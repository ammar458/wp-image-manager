<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIM_Deleter {

    /**
     * Delete (move to backup) a batch of attachment IDs.
     *
     * @param int[] $ids
     * @return array { deleted: int, errors: string[] }
     */
    public function delete_batch( $ids ) {
        wpim_activate(); // Ensure backup dirs exist

        $deleted    = 0;
        $errors     = [];
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

            if ( $use_gdrive ) {
                // Google Drive selected: back up straight to Drive with zero local
                // footprint — no staging copy on this server at all. Never deletes
                // anything if the upload fails.
                $result = $this->backup_to_gdrive( $id, $file, $upload_dir );
                if ( ! $result['success'] ) {
                    $errors[] = "Attachment #{$id}: " . $result['message'];
                    continue;
                }

                $meta_data['storage']     = 'gdrive';
                $meta_data['drive_files'] = $result['drive_files'];

                wp_mkdir_p( WPIM_BACKUP_DELETED . '/' . $id );
                file_put_contents( WPIM_BACKUP_DELETED . '/' . $id . '/meta.json', json_encode( $meta_data, JSON_PRETTY_PRINT ) );

                $this->delete_files( $size_files );
                wp_delete_attachment( $id, true );
                $deleted++;
                continue;
            }

            // Local storage selected: move only the main file into the backup folder.
            $rel_path    = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $file );
            $backup_dest = WPIM_BACKUP_DELETED . '/' . $id . '/' . $rel_path;

            if ( ! wp_mkdir_p( dirname( $backup_dest ) ) ) {
                $errors[] = "Could not create backup dir for attachment #{$id}";
                continue;
            }

            $meta_data['storage'] = 'local';
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
            }
        }

        return [ 'deleted' => $deleted, 'errors' => $errors ];
    }

    /**
     * Upload just the main file for this attachment directly to Google Drive —
     * no local staging copy — mirroring the original upload folder structure
     * under a per-attachment folder (WP Image Manager Backups/deleted/<id>/...).
     *
     * @return array { success: bool, message?: string, drive_files?: array<string,string> }
     */
    private function backup_to_gdrive( $id, $file, $upload_dir ) {
        $base_folder = WPIM_Google_Drive::get_subfolder_id( 'deleted' );
        if ( ! $base_folder ) {
            return [ 'success' => false, 'message' => 'Could not access the Google Drive backup folder.' ];
        }

        $id_folder = WPIM_Google_Drive::get_nested_folder_id( $base_folder, (string) $id );
        if ( ! $id_folder ) {
            return [ 'success' => false, 'message' => 'Could not create the Google Drive folder for this attachment.' ];
        }

        $rel = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $file );
        $dir = dirname( $rel );
        $target_folder = ( $dir === '.' ) ? $id_folder : WPIM_Google_Drive::get_nested_folder_id( $id_folder, $dir );
        if ( ! $target_folder ) {
            return [ 'success' => false, 'message' => 'Could not create a Google Drive subfolder.' ];
        }

        $file_id = WPIM_Google_Drive::upload_file( $file, basename( $rel ), $target_folder );
        if ( ! $file_id ) {
            return [ 'success' => false, 'message' => 'Upload failed for ' . basename( $rel ) . '.' ];
        }

        if ( file_exists( $file ) ) @unlink( $file );

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
