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

        $deleted = 0;
        $errors  = [];

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
                // Remove from WP database
                wp_delete_attachment( $id, true );
                $deleted++;
            }
        }

        return [ 'deleted' => $deleted, 'errors' => $errors ];
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
