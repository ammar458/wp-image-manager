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

            // Build backup destination
            $upload_dir  = wp_upload_dir();
            $rel_path    = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $file );
            $backup_path = WPIM_BACKUP_DELETED . '/' . $id . '/' . $rel_path;
            $backup_dir  = dirname( $backup_path );

            if ( ! wp_mkdir_p( $backup_dir ) ) {
                $errors[] = "Could not create backup dir for attachment #{$id}";
                continue;
            }

            // Gather all files for this attachment (original + sizes)
            $meta  = wp_get_attachment_metadata( $id );
            $files = $this->get_all_attachment_files( $file, $meta );

            // Save metadata to JSON for restore
            $meta_data = [
                'id'          => $id,
                'file'        => $file,
                'post'        => get_post( $id, ARRAY_A ),
                'postmeta'    => $this->get_postmeta( $id ),
                'upload_dir'  => $upload_dir,
                'files'       => $files,
                'deleted_at'  => current_time( 'mysql' ),
            ];
            file_put_contents( WPIM_BACKUP_DELETED . '/' . $id . '/meta.json', json_encode( $meta_data, JSON_PRETTY_PRINT ) );

            // Move each file
            $move_ok = true;
            foreach ( $files as $src ) {
                if ( ! file_exists( $src ) ) continue;
                $rel  = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $src );
                $dest = WPIM_BACKUP_DELETED . '/' . $id . '/' . $rel;
                wp_mkdir_p( dirname( $dest ) );
                if ( ! rename( $src, $dest ) ) {
                    // Try copy+delete
                    if ( copy( $src, $dest ) ) {
                        unlink( $src );
                    } else {
                        $errors[] = "Could not move file: " . basename( $src );
                        $move_ok  = false;
                    }
                }
            }

            if ( $move_ok ) {
                // Optionally upload the backed-up files to Google Drive, falling back
                // to the local copy for any file that fails to upload.
                if ( $use_gdrive ) {
                    $storage     = $this->upload_backup_to_gdrive( $id, $files, $upload_dir );
                    $meta_data['storage']     = $storage['storage'];
                    $meta_data['drive_files'] = $storage['drive_files'];
                    file_put_contents( WPIM_BACKUP_DELETED . '/' . $id . '/meta.json', json_encode( $meta_data, JSON_PRETTY_PRINT ) );
                }

                // Remove from WP database
                wp_delete_attachment( $id, true );
                $deleted++;
            }
        }

        return [ 'deleted' => $deleted, 'errors' => $errors ];
    }

    /**
     * Upload the locally-backed-up files for an attachment to Google Drive.
     * Files that upload successfully are removed locally; files that fail
     * are left in place as a fallback.
     *
     * @return array { storage: 'gdrive'|'local'|'mixed', drive_files: array<string,string> }
     */
    private function upload_backup_to_gdrive( $id, $files, $upload_dir ) {
        $drive_files = [];
        $any_failed  = false;
        $any_ok      = false;

        $folder_id = WPIM_Google_Drive::get_subfolder_id( 'deleted' );
        if ( ! $folder_id ) {
            return [ 'storage' => 'local', 'drive_files' => [] ];
        }

        foreach ( $files as $original_path ) {
            $rel         = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $original_path );
            $backup_file = WPIM_BACKUP_DELETED . '/' . $id . '/' . $rel;
            if ( ! file_exists( $backup_file ) ) continue;

            $remote_name = $id . '_' . str_replace( [ '/', '\\' ], '_', $rel );
            $file_id     = WPIM_Google_Drive::upload_file( $backup_file, $remote_name, $folder_id );

            if ( $file_id ) {
                $drive_files[ $rel ] = $file_id;
                @unlink( $backup_file );
                $any_ok = true;
            } else {
                $any_failed = true;
            }
        }

        if ( $any_ok && ! $any_failed ) $storage = 'gdrive';
        elseif ( $any_ok && $any_failed ) $storage = 'mixed';
        else $storage = 'local';

        return [ 'storage' => $storage, 'drive_files' => $drive_files ];
    }

    /**
     * Get all physical files associated with an attachment.
     */
    private function get_all_attachment_files( $main_file, $meta ) {
        $files  = [ $main_file ];
        $dir    = dirname( $main_file );

        if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $files[] = $dir . '/' . $size['file'];
                }
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
