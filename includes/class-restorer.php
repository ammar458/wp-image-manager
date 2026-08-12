<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIM_Restorer {

    /**
     * Get list of deleted image backups.
     */
    public function get_deleted_backups( $page = 1, $per_page = 50 ) {
        $backup_dir = WPIM_BACKUP_DELETED;
        if ( ! is_dir( $backup_dir ) ) return [ 'items' => [], 'total' => 0 ];

        $items = [];
        $dirs  = glob( $backup_dir . '/*/meta.json' );
        if ( ! $dirs ) return [ 'items' => [], 'total' => 0 ];

        rsort( $dirs ); // Newest first
        $total  = count( $dirs );
        $offset = ( $page - 1 ) * $per_page;
        $slice  = array_slice( $dirs, $offset, $per_page );

        foreach ( $slice as $meta_file ) {
            $meta = json_decode( file_get_contents( $meta_file ), true );
            if ( ! $meta ) continue;
            $items[] = [
                'id'         => $meta['id'],
                'title'      => $meta['post']['post_title'] ?? 'Unknown',
                'filename'   => basename( $meta['file'] ?? '' ),
                'deleted_at' => $meta['deleted_at'] ?? '',
                'backup_dir' => dirname( $meta_file ),
                'storage'    => $meta['storage'] ?? 'local',
            ];
        }

        return [ 'items' => $items, 'total' => $total, 'pages' => ceil($total/$per_page) ];
    }

    /**
     * Restore a deleted attachment by its original ID.
     */
    public function restore_deleted( $id ) {
        $id         = intval( $id );
        $meta_file  = WPIM_BACKUP_DELETED . '/' . $id . '/meta.json';
        if ( ! file_exists( $meta_file ) ) return [ 'success' => false, 'message' => 'Backup metadata not found.' ];

        $meta = json_decode( file_get_contents( $meta_file ), true );
        if ( ! $meta ) return [ 'success' => false, 'message' => 'Could not read backup metadata.' ];

        $upload_dir = wp_upload_dir();
        $backup_id_dir = WPIM_BACKUP_DELETED . '/' . $id;
        $drive_files   = $meta['drive_files'] ?? [];

        // Move files back (from local backup, or download from Google Drive)
        $files  = $meta['files'] ?? [];
        $failed = [];
        foreach ( $files as $original_path ) {
            $rel  = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $original_path );
            $src  = $backup_id_dir . '/' . $rel;

            if ( file_exists( $src ) ) {
                wp_mkdir_p( dirname( $original_path ) );
                rename( $src, $original_path );
            } elseif ( ! empty( $drive_files[ $rel ] ) ) {
                wp_mkdir_p( dirname( $original_path ) );
                if ( WPIM_Google_Drive::download_file( $drive_files[ $rel ], $original_path ) ) {
                    WPIM_Google_Drive::delete_file( $drive_files[ $rel ] );
                } else {
                    $failed[] = basename( $rel );
                }
            } else {
                $failed[] = basename( $rel );
            }
        }

        // Don't restore a half-populated attachment: if any file couldn't be
        // brought back (e.g. Drive download failed), leave the backup in place
        // so the user can retry, instead of re-inserting a post with a missing file.
        if ( ! empty( $failed ) ) {
            return [
                'success' => false,
                'message' => 'Could not restore ' . count( $failed ) . ' file(s): ' . implode( ', ', $failed ) . '. The backup was left in place — please try again.',
            ];
        }

        // Re-insert post
        global $wpdb;
        $post_data = $meta['post'];
        unset( $post_data['ID'] );
        $post_data['ID'] = $id;

        $existing = get_post( $id );
        if ( $existing ) {
            wp_update_post( $post_data );
        } else {
            $wpdb->insert( $wpdb->posts, $post_data );
        }

        // Restore postmeta
        $postmeta = $meta['postmeta'] ?? [];
        foreach ( $postmeta as $row ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s",
                $id, $row['meta_key']
            ));
            if ( $exists ) {
                update_post_meta( $id, $row['meta_key'], maybe_unserialize( $row['meta_value'] ) );
            } else {
                add_post_meta( $id, $row['meta_key'], maybe_unserialize( $row['meta_value'] ) );
            }
        }

        // Clean up backup dir
        $this->rrmdir( $backup_id_dir );

        return [ 'success' => true, 'message' => "Attachment #{$id} restored successfully." ];
    }

    /**
     * Restore a converted image (WebP → original JPEG/PNG).
     */
    public function restore_converted( $id ) {
        $id   = intval($id);
        $info = get_post_meta( $id, '_wpim_converted_to_webp', true );
        if ( ! $info ) return [ 'success' => false, 'message' => 'No conversion record found.' ];

        $backup   = $info['backup'];
        $original = $info['original'];
        $webp     = $info['webp'];
        $storage  = $info['storage'] ?? 'local';

        wp_mkdir_p( dirname( $original ) );

        if ( $storage === 'gdrive' && ! empty( $info['drive_file_id'] ) ) {
            if ( ! WPIM_Google_Drive::download_file( $info['drive_file_id'], $original ) ) {
                return [ 'success' => false, 'message' => 'Could not download backup from Google Drive.' ];
            }
        } else {
            if ( ! file_exists( $backup ) ) return [ 'success' => false, 'message' => 'Backup file not found: ' . $backup ];
            if ( ! copy( $backup, $original ) ) {
                return [ 'success' => false, 'message' => 'Could not restore file.' ];
            }
        }

        // Remove WebP
        if ( file_exists( $webp ) ) @unlink( $webp );

        // Determine mime type
        $ext  = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
        $mime = ( $ext === 'png' ) ? 'image/png' : 'image/jpeg';

        // Update WP record
        update_attached_file( $id, $original );
        wp_update_post( [ 'ID' => $id, 'post_mime_type' => $mime ] );

        $meta = wp_generate_attachment_metadata( $id, $original );
        wp_update_attachment_metadata( $id, $meta );

        delete_post_meta( $id, '_wpim_converted_to_webp' );

        // Remove backup file
        if ( $storage === 'gdrive' && ! empty( $info['drive_file_id'] ) ) {
            WPIM_Google_Drive::delete_file( $info['drive_file_id'] );
        } else {
            @unlink( $backup );
        }

        return [ 'success' => true, 'message' => "Attachment #{$id} reverted to original." ];
    }

    /**
     * Get list of converted image backups.
     */
    public function get_converted_backups( $page = 1, $per_page = 50 ) {
        global $wpdb;
        $offset = ( $page - 1 ) * $per_page;
        $rows = $wpdb->get_results( $wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE pm.meta_key = '_wpim_converted_to_webp'
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset ), ARRAY_A );

        $total = intval( $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpim_converted_to_webp'
        ") );

        $items = [];
        foreach ( $rows as $row ) {
            $info    = maybe_unserialize( $row['meta_value'] );
            $storage = $info['storage'] ?? 'local';
            $backup_exists = ( $storage === 'gdrive' )
                ? ! empty( $info['drive_file_id'] )
                : file_exists( $info['backup'] ?? '' );
            $items[] = [
                'id'           => $row['ID'],
                'title'        => $row['post_title'],
                'original'     => basename( $info['original'] ?? '' ),
                'webp'         => basename( $info['webp'] ?? '' ),
                'converted_at' => $info['converted_at'] ?? '',
                'storage'      => $storage,
                'backup_exists'=> $backup_exists,
            ];
        }

        return [ 'items' => $items, 'total' => $total, 'pages' => ceil($total/$per_page) ];
    }

    private function rrmdir( $dir ) {
        if ( ! is_dir($dir) ) return;
        foreach ( glob( $dir . '/*' ) as $f ) {
            is_dir($f) ? $this->rrmdir($f) : unlink($f);
        }
        rmdir($dir);
    }
}
