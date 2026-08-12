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
            $files      = $this->get_all_attachment_files( $file, $meta );

            $meta_data = [
                'id'         => $id,
                'file'       => $file,
                'post'       => get_post( $id, ARRAY_A ),
                'postmeta'   => $this->get_postmeta( $id ),
                'upload_dir' => $upload_dir,
                'files'      => $files,
                'deleted_at' => current_time( 'mysql' ),
                'thumb'      => wpim_generate_thumb_data_uri( $file ),
            ];

            if ( $use_gdrive ) {
                // Google Drive selected: back up straight to Drive with zero local
                // footprint — no staging copy on this server at all. All-or-nothing
                // per attachment, so a failed upload never deletes anything.
                $result = $this->backup_to_gdrive( $id, $files, $upload_dir );
                if ( ! $result['success'] ) {
                    $errors[] = "Attachment #{$id}: " . $result['message'];
                    continue;
                }

                $meta_data['storage']     = 'gdrive';
                $meta_data['drive_files'] = $result['drive_files'];

                wp_mkdir_p( WPIM_BACKUP_DELETED . '/' . $id );
                file_put_contents( WPIM_BACKUP_DELETED . '/' . $id . '/meta.json', json_encode( $meta_data, JSON_PRETTY_PRINT ) );

                wp_delete_attachment( $id, true );
                $deleted++;
                continue;
            }

            // Local storage selected: move the files into the local backup folder.
            $rel_path   = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $file );
            $backup_dir = dirname( WPIM_BACKUP_DELETED . '/' . $id . '/' . $rel_path );

            if ( ! wp_mkdir_p( $backup_dir ) ) {
                $errors[] = "Could not create backup dir for attachment #{$id}";
                continue;
            }

            $meta_data['storage'] = 'local';
            file_put_contents( WPIM_BACKUP_DELETED . '/' . $id . '/meta.json', json_encode( $meta_data, JSON_PRETTY_PRINT ) );

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
                wp_delete_attachment( $id, true );
                $deleted++;
            }
        }

        return [ 'deleted' => $deleted, 'errors' => $errors ];
    }

    /**
     * Upload every file for this attachment directly to Google Drive — no
     * local staging copy — mirroring the original upload folder structure
     * under a per-attachment folder (WP Image Manager Backups/deleted/<id>/...).
     * All-or-nothing: if any file fails partway, everything already uploaded
     * for this attachment is rolled back so nothing is left orphaned.
     *
     * @return array { success: bool, message?: string, drive_files?: array<string,string> }
     */
    private function backup_to_gdrive( $id, $files, $upload_dir ) {
        $base_folder = WPIM_Google_Drive::get_subfolder_id( 'deleted' );
        if ( ! $base_folder ) {
            return [ 'success' => false, 'message' => 'Could not access the Google Drive backup folder.' ];
        }

        $id_folder = WPIM_Google_Drive::get_nested_folder_id( $base_folder, (string) $id );
        if ( ! $id_folder ) {
            return [ 'success' => false, 'message' => 'Could not create the Google Drive folder for this attachment.' ];
        }

        $drive_files = [];
        foreach ( $files as $original_path ) {
            if ( ! file_exists( $original_path ) ) continue;

            $rel = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $original_path );
            $dir = dirname( $rel );
            $target_folder = ( $dir === '.' ) ? $id_folder : WPIM_Google_Drive::get_nested_folder_id( $id_folder, $dir );
            if ( ! $target_folder ) {
                $this->rollback_drive_files( $drive_files );
                return [ 'success' => false, 'message' => 'Could not create a Google Drive subfolder.' ];
            }

            $file_id = WPIM_Google_Drive::upload_file( $original_path, basename( $rel ), $target_folder );
            if ( ! $file_id ) {
                $this->rollback_drive_files( $drive_files );
                return [ 'success' => false, 'message' => 'Upload failed for ' . basename( $rel ) . '.' ];
            }
            $drive_files[ $rel ] = $file_id;
        }

        // Every file uploaded successfully — now it's safe to remove the originals.
        foreach ( $files as $original_path ) {
            if ( file_exists( $original_path ) ) @unlink( $original_path );
        }

        return [ 'success' => true, 'drive_files' => $drive_files ];
    }

    private function rollback_drive_files( $drive_files ) {
        foreach ( $drive_files as $file_id ) {
            WPIM_Google_Drive::delete_file( $file_id );
        }
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
