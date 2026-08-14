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

        // Primary path: find any postmeta row, for a post of this type, whose
        // value is a comma-separated list of 2+ numbers — the common Jet
        // Engine gallery-field storage (one row, IDs joined with commas).
        // This is a direct SQL scan rather than relying on the
        // attached-images temp table, which only indexes meta values that
        // are a single bare number and would miss this format entirely.
        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT pm.post_id, pm.meta_key, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s
            WHERE pm.meta_value REGEXP '^[0-9]+(,[0-9]+){1,}$'
            ORDER BY pm.post_id DESC
            LIMIT 300
        ", $post_type ) );

        foreach ( $rows as $row ) {
            if ( strpos( $row->meta_key, '_' ) === 0 ) continue; // skip protected/system meta

            $ids = array_values( array_filter( array_map( 'intval', explode( ',', $row->meta_value ) ) ) );
            if ( count( $ids ) < 2 ) continue;

            if ( $this->ids_are_all_attachments( $ids ) ) {
                return [
                    'key'            => $row->meta_key,
                    'format'         => 'csv',
                    'sample_post_id' => (int) $row->post_id,
                    'sample_count'   => count( $ids ),
                ];
            }
        }

        // Fallback: multi-row (same key repeated once per image) or a
        // serialized-array value, discovered via a post the attached-images
        // temp table already knows has several images.
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
            if ( $key === '_thumbnail_id' || strpos( $key, '_' ) === 0 ) continue;

            $ids    = [];
            $format = 'csv';

            if ( count( $values ) > 1 ) {
                foreach ( $values as $v ) {
                    if ( is_numeric( $v ) ) $ids[] = (int) $v;
                }
                $format = 'multi-row';
            } else {
                $ids    = $this->extract_id_list( $values[0] ?? '' );
                $format = is_array( maybe_unserialize( $values[0] ?? '' ) ) ? 'array' : 'csv';
            }

            if ( count( $ids ) < 2 ) continue;
            if ( ! $this->ids_are_all_attachments( $ids ) ) continue;

            return [
                'key'            => $key,
                'format'         => $format,
                'sample_post_id' => (int) $candidate_id,
                'sample_count'   => count( $ids ),
            ];
        }

        return null;
    }

    private function ids_are_all_attachments( array $ids ) {
        foreach ( $ids as $id ) {
            if ( get_post_type( $id ) !== 'attachment' ) return false;
        }
        return true;
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

    /**
     * Fixes an Elementor-authored page/post whose _elementor_data still
     * references an attachment ID that no longer exists on this site (e.g.
     * a background-image widget pointing at a deleted attachment — Elementor
     * silently omits the CSS rule for it rather than erroring, so the page
     * just renders with a blank box and no obvious clue why). Sideloads a
     * replacement from source_image_url, rewrites every {id,url} image
     * reference matching the old ID anywhere in the page's widget tree, and
     * clears Elementor's cached CSS for the page so the fix actually shows
     * up on next load instead of continuing to serve the stale cached rule.
     */
    public function fix_elementor_image( $post_id, $old_attachment_id, $source_image_url ) {
        $new_id = $this->sideload_image( $source_image_url );
        if ( is_wp_error( $new_id ) ) {
            return [ 'success' => false, 'error' => $new_id->get_error_message() ];
        }
        $new_url = wp_get_attachment_url( $new_id );

        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! $raw ) {
            return [ 'success' => false, 'error' => 'No _elementor_data found for this post.', 'new_attachment_id' => $new_id ];
        }

        $data = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [ 'success' => false, 'error' => 'Could not parse _elementor_data JSON.', 'new_attachment_id' => $new_id ];
        }

        $replacements = 0;
        $walk = function ( &$node ) use ( &$walk, &$replacements, $old_attachment_id, $new_id, $new_url ) {
            if ( ! is_array( $node ) ) return;
            if ( isset( $node['id'], $node['url'] ) && (int) $node['id'] === (int) $old_attachment_id ) {
                $node['id']  = $new_id;
                $node['url'] = $new_url;
                $replacements++;
            }
            foreach ( $node as &$value ) {
                if ( is_array( $value ) ) $walk( $value );
            }
        };
        $walk( $data );

        if ( $replacements === 0 ) {
            return [ 'success' => false, 'error' => 'No matching image reference found in _elementor_data.', 'new_attachment_id' => $new_id ];
        }

        $this->write_elementor_data_raw( $post_id, wp_json_encode( $data ) );

        $css_regenerated = $this->regenerate_elementor_css( $post_id );

        return [
            'success'           => true,
            'new_attachment_id' => $new_id,
            'new_url'           => $new_url,
            'replacements'      => $replacements,
            'css_regenerated'   => $css_regenerated,
        ];
    }

    /**
     * Writes _elementor_data via a direct $wpdb query instead of
     * update_post_meta(). Something in this site's save path — a filter on
     * update_post_metadata, possibly Elementor's own sanitizer applying
     * stricter rules outside its normal editor-save context — silently
     * unescaped a quote inside a dynamic-tag placeholder
     * (["field id=\"topic\"]" lost its backslashes) the one time this went
     * through update_post_meta(), corrupting otherwise-valid JSON. Writing
     * the exact bytes straight to postmeta sidesteps whatever that filter
     * is; the object cache still needs clearing so subsequent reads in the
     * same and later requests see the new value instead of a stale one.
     */
    public function write_elementor_data_raw( $post_id, $json_string ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->postmeta,
            [ 'meta_value' => $json_string ],
            [ 'post_id' => $post_id, 'meta_key' => '_elementor_data' ],
            [ '%s' ],
            [ '%d', '%s' ]
        );
        wp_cache_delete( $post_id, 'post_meta' );
    }

    /**
     * Rebuilds Elementor's cached CSS for a post right now, in this request,
     * rather than deleting the cache and hoping a later front-end visit
     * regenerates it — that visit may never reach PHP at all if a page
     * cache or CDN sits in front, which is exactly what left a data-correct
     * fix invisible on the front end (regen ran, but from what looked like
     * stale/empty data, producing an empty cached file).
     */
    public function regenerate_elementor_css( $post_id ) {
        delete_post_meta( $post_id, '_elementor_css' );
        $upload_dir = wp_upload_dir();
        $css_file   = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
        if ( file_exists( $css_file ) ) @unlink( $css_file );

        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            $css_file_obj = new \Elementor\Core\Files\CSS\Post( $post_id );
            $css_file_obj->update();
            return true;
        }

        return false;
    }
}
