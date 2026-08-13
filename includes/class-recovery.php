<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Recovers featured/gallery images for a post from an external URL — built
 * for cloning images back from a source site (e.g. production) onto listings
 * that lost theirs. The gallery meta key is site-specific (Jet Engine names
 * it however the site builder set it up), so this auto-detects it from a
 * post of the same type that still has an intact gallery, rather than
 * hard-coding a key that would break the moment it's wrong.
 */
class WPIM_Recovery {

    /**
     * Finds the postmeta key that holds gallery attachment IDs for a post
     * type, by sampling a post that _wpim_attached_tmp already knows has
     * several images attached and checking which of its meta keys is a list
     * of IDs that all resolve to real attachments. Multi-row postmeta (same
     * key repeated once per image — the common Jet Engine gallery pattern)
     * and single-value array/CSV storage are both handled.
     */
    public function discover_gallery_field( $post_type ) {
        global $wpdb;

        $scanner = new WPIM_Scanner();
        $scanner->maybe_build_attached_temp_table();

        $candidate_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT t.owner_id
            FROM _wpim_attached_tmp t
            INNER JOIN {$wpdb->posts} p ON p.ID = t.owner_id AND p.post_type = %s
            GROUP BY t.owner_id
            HAVING COUNT(*) >= 3
            ORDER BY MAX(t.aid) DESC
            LIMIT 1
        ", $post_type ) );

        if ( ! $candidate_id ) return null;

        $all_meta = get_post_meta( (int) $candidate_id );
        foreach ( $all_meta as $key => $values ) {
            if ( $key === '_thumbnail_id' || strpos( $key, '_edit_' ) === 0 || strpos( $key, '_wp_' ) === 0 ) continue;

            $ids    = [];
            $format = 'csv';

            if ( count( $values ) > 1 ) {
                foreach ( $values as $v ) {
                    if ( is_numeric( $v ) ) $ids[] = (int) $v;
                }
                $format = 'multi-row';
            } else {
                $ids = $this->extract_id_list( $values[0] ?? '' );
                $format = is_array( maybe_unserialize( $values[0] ?? '' ) ) ? 'array' : 'csv';
            }

            if ( count( $ids ) < 2 ) continue;

            $valid = true;
            foreach ( $ids as $id ) {
                if ( get_post_type( $id ) !== 'attachment' ) { $valid = false; break; }
            }
            if ( ! $valid ) continue;

            return [
                'key'            => $key,
                'format'         => $format,
                'sample_post_id' => (int) $candidate_id,
                'sample_count'   => count( $ids ),
            ];
        }

        return null;
    }

    private function extract_id_list( $raw ) {
        $val = maybe_unserialize( $raw );
        if ( is_array( $val ) ) {
            return array_values( array_filter( array_map( 'intval', $val ) ) );
        }
        if ( is_string( $val ) && $val !== '' && preg_match( '/^[\d,\s|]+$/', $val ) ) {
            return array_values( array_filter( array_map( 'intval', preg_split( '/[,|]+/', $val ) ) ) );
        }
        return [];
    }

    /**
     * Downloads a remote image and adds it to this site's media library.
     * Returns the new attachment ID, or a WP_Error.
     */
    public function sideload_image( $url ) {
        if ( ! function_exists( 'download_url' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) require_once ABSPATH . 'wp-admin/includes/image.php';
        if ( ! function_exists( 'media_handle_sideload' ) ) require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) return $tmp;

        $filename   = wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'image.jpg' );
        $file_array = [ 'name' => $filename, 'tmp_name' => $tmp ];

        $attachment_id = media_handle_sideload( $file_array, 0 );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return $attachment_id;
        }
        return $attachment_id;
    }

    /**
     * Recovers one post's featured image and/or gallery from external URLs.
     * dry_run reports what would happen (including the detected gallery
     * field) without downloading or writing anything.
     */
    public function recover_post( $post_id, $post_type, $featured_url, array $gallery_urls, $dry_run = true ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== $post_type ) {
            return [ 'post_id' => $post_id, 'success' => false, 'error' => 'Post not found or type mismatch.' ];
        }

        $field = $this->discover_gallery_field( $post_type );

        if ( $dry_run ) {
            return [
                'post_id'                 => $post_id,
                'title'                   => get_the_title( $post_id ),
                'dry_run'                 => true,
                'would_set_featured'      => (bool) $featured_url,
                'would_set_gallery_count' => count( $gallery_urls ),
                'gallery_field'           => $field,
            ];
        }

        $result = [ 'post_id' => $post_id, 'title' => get_the_title( $post_id ), 'success' => true ];

        if ( $featured_url ) {
            $fid = $this->sideload_image( $featured_url );
            if ( is_wp_error( $fid ) ) {
                $result['featured_error'] = $fid->get_error_message();
            } else {
                set_post_thumbnail( $post_id, $fid );
                $result['featured_id'] = $fid;
            }
        }

        if ( ! empty( $gallery_urls ) ) {
            if ( ! $field ) {
                $result['gallery_error'] = 'Could not auto-detect a gallery meta field for this post type.';
            } else {
                $new_ids = [];
                foreach ( $gallery_urls as $url ) {
                    $gid = $this->sideload_image( $url );
                    if ( is_wp_error( $gid ) ) {
                        $result['gallery_errors'][] = $gid->get_error_message();
                        continue;
                    }
                    $new_ids[] = $gid;
                }
                if ( $new_ids ) {
                    $this->write_gallery_field( $post_id, $field, $new_ids );
                    $result['gallery_ids']    = $new_ids;
                    $result['gallery_field']  = $field['key'];
                }
            }
        }

        return $result;
    }

    private function write_gallery_field( $post_id, $field, $new_ids ) {
        $key = $field['key'];
        switch ( $field['format'] ) {
            case 'multi-row':
                delete_post_meta( $post_id, $key );
                foreach ( $new_ids as $id ) add_post_meta( $post_id, $key, $id );
                break;
            case 'array':
                update_post_meta( $post_id, $key, $new_ids );
                break;
            default: // csv
                update_post_meta( $post_id, $key, implode( ',', $new_ids ) );
        }
    }
}
