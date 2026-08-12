<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIM_Deleter {

    /**
     * Delete (move to backup) a batch of attachment IDs.
     *
     * @param int[] $ids
     * @return array { deleted: int, errors: string[], queued_for_upload: int }
     */
    public function delete_batch( $ids ) {
        wpim_activate(); // Ensure backup dirs exist

        $deleted    = 0;
        $errors     = [];
        $queued     = 0;
        $use_gdrive = get_option( 'wpim_backup_destination', 'local' ) === 'gdrive' && WPIM_Google_Drive::is_connected();

        foreach ( $ids as $id ) {
            $id = intval( $id );
            if ( ! $id ) continue;

            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                // Still remove from DB
                wp_delete_attachment( $id, true );
                $deleted++;
                continue;
            }

            $upload_dir = wp_upload_dir();
            $meta       = wp_get_attachment_metadata( $id );
            // Only the main file is ever backed up — thumbnail sizes are
            // regenerated from it on restore (like the WebP-revert path
            // already does), so they don't cost a network round trip each
            // when the destination is Google Drive.
            $size_files = $this->get_size_files( $file, $meta );

            $meta_data = [
                'id'         => $id,
                'file'       => $file,
                'post'       => get_post( $id, ARRAY_A ),
                'postmeta'   => $this->get_postmeta( $id ),
                'upload_dir' => $upload_dir,
                'files'      => [ $file ],
                'deleted_at' => current_time( 'mysql' ),
                'thumb'      => wpim_generate_thumb_data_uri( $file ),
            ];

            // The main file always lands in the local backup folder first,
            // whichever destination is chosen. This is what keeps the delete
            // request itself fast: WordPress never blocks on a Google Drive
            // upload. When Drive is the destination the file is left here as
            // 'gdrive_pending' and a background queue (see process_gdrive_queue())
            // uploads it and removes the local copy once Drive confirms it —
            // so the file is never gone from both places at once.
            $rel_path    = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $file );
            $backup_dest = WPIM_BACKUP_DELETED . '/' . $id . '/' . $rel_path;

            if ( ! wp_mkdir_p( dirname( $backup_dest ) ) ) {
                $errors[] = "Could not create backup dir for attachment #{$id}";
                continue;
            }

            $meta_data['storage'] = $use_gdrive ? 'gdrive_pending' : 'local';
            file_put_contents( WPIM_BACKUP_DELETED . '/' . $id . '/meta.json', json_encode( $meta_data, JSON_PRETTY_PRINT ) );

            $move_ok = true;
            if ( ! rename( $file, $backup_dest ) ) {
                // Try copy+delete
                if ( copy( $file, $backup_dest ) ) {
                    unlink( $file );
                } else {
                    $errors[] = "Could not move file: " . basename( $file );
                    $move_ok  = false;
                }
            }

            if ( $move_ok ) {
                $this->delete_files( $size_files );
                wp_delete_attachment( $id, true );
                $deleted++;
                if ( $use_gdrive ) $queued++;
            }
        }

        if ( $queued > 0 ) {
            WPIM_Google_Drive::kick_off_queue();
        }

        return [ 'deleted' => $deleted, 'errors' => $errors, 'queued_for_upload' => $queued ];
    }

    /**
     * Upload every 'gdrive_pending' file left in the local backup folder to
     * Google Drive, mirroring the original upload folder structure under a
     * per-attachment folder (WP Image Manager Backups/deleted/<id>/...). Runs
     * off the request thread via WP-Cron, so deletes never wait on this.
     */
    public function process_gdrive_queue( $limit = 25 ) {
        if ( ! WPIM_Google_Drive::is_connected() ) return;

        $meta_files = glob( WPIM_BACKUP_DELETED . '/*/meta.json' );
        if ( ! $meta_files ) return;

        $upload_dir = wp_upload_dir();
        $processed  = 0;

        foreach ( $meta_files as $meta_file ) {
            if ( $processed >= $limit ) {
                // More pending than fit in one tick — drain the rest right away
                // instead of waiting for the next safety-net sweep.
                WPIM_Google_Drive::kick_off_queue();
                return;
            }

            $meta = json_decode( file_get_contents( $meta_file ), true );
            if ( ! $meta || ( $meta['storage'] ?? '' ) !== 'gdrive_pending' ) continue;

            $id     = $meta['id'];
            $rel    = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $meta['file'] );
            $staged = dirname( $meta_file ) . '/' . $rel;

            if ( ! file_exists( $staged ) ) continue; // Restored (or otherwise gone) since it was queued.

            $processed++;
            $result = $this->backup_staged_file_to_gdrive( $id, $staged, $rel );

            if ( ! $result['success'] ) {
                // Left as 'gdrive_pending' — retried on the next tick.
                $meta['last_upload_error'] = $result['message'];
                file_put_contents( $meta_file, json_encode( $meta, JSON_PRETTY_PRINT ) );
                continue;
            }

            $meta['storage']     = 'gdrive';
            $meta['drive_files'] = $result['drive_files'];
            unset( $meta['last_upload_error'] );
            file_put_contents( $meta_file, json_encode( $meta, JSON_PRETTY_PRINT ) );

            @unlink( $staged );
        }
    }

    /**
     * @return array { success: bool, message?: string, drive_files?: array<string,string> }
     */
    private function backup_staged_file_to_gdrive( $id, $staged_file, $rel ) {
        $base_folder = WPIM_Google_Drive::get_subfolder_id( 'deleted' );
        if ( ! $base_folder ) {
            return [ 'success' => false, 'message' => 'Could not access the Google Drive backup folder.' ];
        }

        $id_folder = WPIM_Google_Drive::get_nested_folder_id( $base_folder, (string) $id );
        if ( ! $id_folder ) {
            return [ 'success' => false, 'message' => 'Could not create the Google Drive folder for this attachment.' ];
        }

        $dir = dirname( $rel );
        $target_folder = ( $dir === '.' || $dir === '' ) ? $id_folder : WPIM_Google_Drive::get_nested_folder_id( $id_folder, $dir );
        if ( ! $target_folder ) {
            return [ 'success' => false, 'message' => 'Could not create a Google Drive subfolder.' ];
        }

        $file_id = WPIM_Google_Drive::upload_file( $staged_file, basename( $rel ), $target_folder );
        if ( ! $file_id ) {
            return [ 'success' => false, 'message' => 'Upload failed for ' . basename( $rel ) . '.' ];
        }

        return [ 'success' => true, 'drive_files' => [ $rel => $file_id ] ];
    }

    private function delete_files( $files ) {
        foreach ( $files as $f ) {
            if ( file_exists( $f ) ) @unlink( $f );
        }
    }

    /**
     * Get every registered thumbnail-size file for an attachment (excluding
     * the main file itself). These are never backed up — only deleted —
     * since they're cheaply regenerated from the main file on restore.
     */
    private function get_size_files( $main_file, $meta ) {
        $files = [];
        $dir   = dirname( $main_file );

        if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size ) {
                if ( empty( $size['file'] ) ) continue;
                $candidate = $dir . '/' . $size['file'];
                if ( $candidate !== $main_file ) $files[] = $candidate;
            }
        }
        return array_unique( $files );
    }

    /**
     * Get all postmeta for an attachment.
     */
    private function get_postmeta( $id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
            $id
        ), ARRAY_A );
    }
}
