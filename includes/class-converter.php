<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIM_Converter {

    /**
     * Convert a JPEG or PNG file to WebP.
     * Keeps the original in the converted backup folder.
     *
     * @param string $file       Absolute path to source file.
     * @param bool   $is_new     Whether this is a fresh upload.
     * @return array|false       ['webp_path' => ..., 'original_backup' => ...] or false on failure.
     */
    public function convert_file( $file, $is_new = false ) {
        if ( ! file_exists( $file ) ) return false;

        $type = mime_content_type( $file );

        if ( ! in_array( $type, [ 'image/jpeg', 'image/png' ] ) ) return false;

        // Check GD or Imagick support
        if ( ! $this->webp_supported() ) return false;

        $upload_dir = wp_upload_dir();
        $rel        = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $file );

        // Only offer the trackable (bulk-convert) path to Google Drive — new-upload
        // conversions don't yet have a post ID/postmeta record to point at the file.
        $use_gdrive = ! $is_new && get_option( 'wpim_backup_destination', 'local' ) === 'gdrive' && WPIM_Google_Drive::is_connected();

        $storage       = 'local';
        $drive_file_id = '';
        $backup_path   = '';
        $thumb         = wpim_generate_thumb_data_uri( $file );

        // Back up the original BEFORE doing anything destructive to it, so a
        // failed backup simply aborts the conversion instead of risking data loss.
        if ( $use_gdrive ) {
            $result = $this->backup_original_to_gdrive( $file, $rel );
            if ( ! $result['success'] ) return false;
            $storage       = 'gdrive';
            $drive_file_id = $result['drive_file_id'];
        } else {
            $backup_path = $this->backup_original_locally( $file, $rel );
        }

        $webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
        $quality   = apply_filters( 'wpim_webp_quality', 82 );
        $success   = false;

        if ( function_exists( 'imagecreatefromjpeg' ) || function_exists( 'imagecreatefrompng' ) ) {
            $success = $this->convert_gd( $file, $type, $webp_path, $quality );
        }

        if ( ! $success && extension_loaded('imagick') ) {
            $success = $this->convert_imagick( $file, $webp_path, $quality );
        }

        if ( ! $success ) {
            // Conversion failed after backing up — undo the backup so nothing's left dangling.
            if ( $storage === 'gdrive' ) {
                WPIM_Google_Drive::delete_file( $drive_file_id );
            } elseif ( $backup_path ) {
                @unlink( $backup_path );
            }
            return false;
        }

        // If not a new upload, keep original in place (only remove it for new uploads where we replace in-flight)
        if ( $is_new ) {
            @unlink( $file );
        }

        return [
            'webp_path'       => $webp_path,
            'original_backup' => $backup_path,
            'storage'         => $storage,
            'drive_file_id'   => $drive_file_id,
            'thumb'           => $thumb,
        ];
    }

    private function backup_original_locally( $file, $rel ) {
        $backup_path = WPIM_BACKUP_CONVERTED . '/' . $rel;
        wp_mkdir_p( dirname( $backup_path ) );
        copy( $file, $backup_path );
        return $backup_path;
    }

    /**
     * Upload the original straight to Google Drive — no local staging copy —
     * mirroring the original upload folder structure (WP Image Manager
     * Backups/converted/<same relative path>).
     */
    private function backup_original_to_gdrive( $file, $rel ) {
        $base_folder = WPIM_Google_Drive::get_subfolder_id( 'converted' );
        if ( ! $base_folder ) return [ 'success' => false ];

        $dir = dirname( $rel );
        $target_folder = ( $dir === '.' ) ? $base_folder : WPIM_Google_Drive::get_nested_folder_id( $base_folder, $dir );
        if ( ! $target_folder ) return [ 'success' => false ];

        $file_id = WPIM_Google_Drive::upload_file( $file, basename( $rel ), $target_folder );
        if ( ! $file_id ) return [ 'success' => false ];

        return [ 'success' => true, 'drive_file_id' => $file_id ];
    }

    /**
     * Convert existing library images to WebP (bulk).
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function bulk_convert( $limit = 100, $offset = 0 ) {
        global $wpdb;

        if ( ! $this->webp_supported() ) {
            return [ 'converted' => 0, 'skipped' => 0, 'errors' => ['WebP conversion not supported (GD/Imagick missing).'] ];
        }

        $ids = $wpdb->get_col( $wpdb->prepare("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type IN ('image/jpeg','image/png')
            AND post_status != 'trash'
            LIMIT %d OFFSET %d
        ", $limit, $offset ) );

        $converted = 0;
        $skipped   = 0;
        $errors    = [];

        foreach ( $ids as $id ) {
            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) { $skipped++; continue; }

            $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
            if ( $ext === 'webp' ) { $skipped++; continue; }

            // Check if already converted
            $already = get_post_meta( $id, '_wpim_converted_to_webp', true );
            if ( $already ) { $skipped++; continue; }

            $result = $this->convert_file( $file, false );
            if ( $result ) {
                // Update attachment record to point to WebP
                $new_file = $result['webp_path'];
                update_attached_file( $id, $new_file );

                // Update mime type
                wp_update_post( [ 'ID' => $id, 'post_mime_type' => 'image/webp' ] );

                // Regenerate metadata for new file
                $meta = wp_generate_attachment_metadata( $id, $new_file );
                wp_update_attachment_metadata( $id, $meta );

                // Mark as converted
                update_post_meta( $id, '_wpim_converted_to_webp', [
                    'original'   => $file,
                    'backup'     => $result['original_backup'],
                    'webp'       => $new_file,
                    'converted_at' => current_time('mysql'),
                    'storage'       => $result['storage'],
                    'drive_file_id' => $result['drive_file_id'],
                    'thumb'         => $result['thumb'],
                ]);

                $converted++;
            } else {
                $errors[] = "Failed to convert: " . basename( $file );
                $skipped++;
            }
        }

        // Count remaining
        $total_remaining = intval( $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type IN ('image/jpeg','image/png')
            AND post_status != 'trash'
        ") );

        return [
            'converted'  => $converted,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'remaining'  => $total_remaining,
        ];
    }

    public function get_conversion_stats() {
        global $wpdb;
        $jpeg_png = intval( $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type IN ('image/jpeg','image/png')
            AND post_status != 'trash'
        ") );
        $webp = intval( $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type = 'image/webp'
            AND post_status != 'trash'
        ") );
        $converted_count = intval( $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpim_converted_to_webp'
        ") );
        return [
            'jpeg_png'        => $jpeg_png,
            'webp'            => $webp,
            'already_webp'    => $webp - $converted_count,
            'converted_count' => $converted_count,
            'supported'       => $this->webp_supported(),
        ];
    }

    private function webp_supported() {
        if ( function_exists('imagewebp') ) return true;
        if ( extension_loaded('imagick') ) {
            $im = new Imagick();
            return in_array('WEBP', $im->queryFormats());
        }
        return false;
    }

    private function convert_gd( $src, $type, $dest, $quality ) {
        $image = null;
        if ( $type === 'image/jpeg' ) {
            $image = @imagecreatefromjpeg( $src );
        } elseif ( $type === 'image/png' ) {
            $image = @imagecreatefrompng( $src );
            if ( $image ) {
                imagealphablending( $image, true );
                imagesavealpha( $image, true );
            }
        }
        if ( ! $image ) return false;
        $result = imagewebp( $image, $dest, $quality );
        imagedestroy( $image );
        return $result && file_exists( $dest );
    }

    private function convert_imagick( $src, $dest, $quality ) {
        try {
            $im = new Imagick( $src );
            $im->setImageFormat('WEBP');
            $im->setImageCompressionQuality( $quality );
            $im->writeImage( $dest );
            $im->destroy();
            return file_exists( $dest );
        } catch ( Exception $e ) {
            return false;
        }
    }
}
