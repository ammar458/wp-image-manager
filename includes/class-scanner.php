<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fast scanner using pure SQL set operations.
 * Avoids loading all IDs into PHP memory.
 * Works correctly with 100k+ image libraries.
 */
class WPIM_Scanner {

    /**
     * Get a fast summary using SQL only — no PHP loops over full ID sets.
     */
    public function get_summary() {
        global $wpdb;

        $total = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'
            AND post_status != 'trash'
        ");

        // Build the attached IDs using SQL UNION of all sources
        // This runs entirely in MySQL — no PHP loops
        $this->maybe_build_attached_temp_table();

        $attached = (int) $wpdb->get_var("SELECT COUNT(DISTINCT aid) FROM _wpim_attached_tmp");
        $unattached = $total - $attached;

        return [
            'total'      => $total,
            'attached'   => $attached,
            'unattached' => max(0, $unattached),
        ];
    }

    /**
     * Get a paginated list of unattached image IDs with metadata.
     */
    public function get_unattached_page( $page = 1, $per_page = 50 ) {
        global $wpdb;

        $this->maybe_build_attached_temp_table();

        $offset = ( $page - 1 ) * $per_page;

        // Total unattached
        $total = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND p.post_status != 'trash'
            AND NOT EXISTS (SELECT 1 FROM _wpim_attached_tmp t WHERE t.aid = p.ID)
        ");

        $ids = $wpdb->get_col( $wpdb->prepare("
            SELECT p.ID FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            AND p.post_status != 'trash'
            AND NOT EXISTS (SELECT 1 FROM _wpim_attached_tmp t WHERE t.aid = p.ID)
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset ) );

        $total_all = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'
            AND post_status != 'trash'
        ");
        $attached_count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT aid) FROM _wpim_attached_tmp");

        $images = [];
        foreach ( $ids as $id ) {
            $path = get_attached_file( $id );
            $size = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;
            $images[] = [
                'id'       => (int) $id,
                'title'    => get_the_title( $id ),
                'url'      => wp_get_attachment_url( $id ),
                'thumb'    => wp_get_attachment_image_url( $id, 'thumbnail' ),
                'filename' => $path ? basename( $path ) : '',
                'size'     => $size ? size_format( $size ) : 'N/A',
                'date'     => get_the_date( 'Y-m-d', $id ),
                'mime'     => get_post_mime_type( $id ),
            ];
        }

        return [
            'images'     => $images,
            'total'      => $total,
            'pages'      => max( 1, ceil( $total / $per_page ) ),
            'current'    => (int) $page,
            'attached'   => $attached_count,
            'unattached' => $total,
            'total_all'  => $total_all,
        ];
    }

    /**
     * Build (or refresh) a temporary table of all referenced/attached image IDs.
     * Uses pure SQL UNION — no PHP loops, no memory issues.
     */
    public function maybe_build_attached_temp_table( $force = false ) {
        global $wpdb;

        // Use a real table (not TEMPORARY) so it survives across multiple queries
        // in the same request and can be reused within the same page load.
        $exists = $wpdb->get_var("SHOW TABLES LIKE '_wpim_attached_tmp'");
        if ( $exists && ! $force ) return;

        $wpdb->query("DROP TABLE IF EXISTS _wpim_attached_tmp");
        $wpdb->query("CREATE TABLE _wpim_attached_tmp (aid BIGINT UNSIGNED NOT NULL, PRIMARY KEY(aid)) ENGINE=InnoDB");

        // ── 1. Standard parent attachment ──────────────────────────────
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid)
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_parent > 0
            AND post_mime_type LIKE 'image/%'
            AND post_status != 'trash'
        ");

        // ── 2. _thumbnail_id (featured images) ─────────────────────────
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid)
            SELECT CAST(meta_value AS UNSIGNED) FROM {$wpdb->postmeta}
            WHERE meta_key = '_thumbnail_id'
            AND meta_value REGEXP '^[0-9]+$'
            AND CAST(meta_value AS UNSIGNED) > 0
        ");

        // ── 3. Numeric-only postmeta values that look like attachment IDs ──
        // Covers JetEngine image fields, ACF image fields (ID mode), etc.
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid)
            SELECT DISTINCT CAST(meta_value AS UNSIGNED)
            FROM {$wpdb->postmeta}
            WHERE meta_value REGEXP '^[0-9]{1,10}$'
            AND CAST(meta_value AS UNSIGNED) > 0
            AND meta_key NOT IN (
                '_edit_lock','_edit_last','_wp_trash_meta_time','comment_count',
                '_wp_page_template','menu_order','post_parent'
            )
        ");

        // ── 4. Numeric-only usermeta (user profile images) ─────────────
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid)
            SELECT DISTINCT CAST(meta_value AS UNSIGNED)
            FROM {$wpdb->usermeta}
            WHERE meta_value REGEXP '^[0-9]{1,10}$'
            AND CAST(meta_value AS UNSIGNED) > 0
        ");

        // ── 5. Numeric-only termmeta (category/tag images) ─────────────
        if ( $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->termmeta}'") ) {
            $wpdb->query("
                INSERT IGNORE INTO _wpim_attached_tmp (aid)
                SELECT DISTINCT CAST(meta_value AS UNSIGNED)
                FROM {$wpdb->termmeta}
                WHERE meta_value REGEXP '^[0-9]{1,10}$'
                AND CAST(meta_value AS UNSIGNED) > 0
            ");
        }

        // ── 6. Options: site logo, custom logo, site_icon ──────────────
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid)
            SELECT CAST(option_value AS UNSIGNED)
            FROM {$wpdb->options}
            WHERE option_name IN ('site_logo','site_icon','custom_logo')
            AND option_value REGEXP '^[0-9]+$'
            AND CAST(option_value AS UNSIGNED) > 0
        ");

        // ── 7. wp-image-{ID} class in post content ─────────────────────
        // Use MySQL REGEXP to find IDs without loading content into PHP
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid)
            SELECT DISTINCT CAST(
                REGEXP_SUBSTR(post_content, 'wp-image-([0-9]+)') AS UNSIGNED
            )
            FROM {$wpdb->posts}
            WHERE post_content LIKE '%wp-image-%'
            AND post_type NOT IN ('attachment','revision')
            AND post_status NOT IN ('trash','auto-draft')
            HAVING CAST(
                REGEXP_SUBSTR(post_content, 'wp-image-([0-9]+)') AS UNSIGNED
            ) > 0
        ");

        // Remove any IDs that don't actually exist as image attachments
        $wpdb->query("
            DELETE t FROM _wpim_attached_tmp t
            LEFT JOIN {$wpdb->posts} p ON p.ID = t.aid
                AND p.post_type = 'attachment'
                AND p.post_mime_type LIKE 'image/%'
            WHERE p.ID IS NULL
        ");
    }

    /**
     * Force a fresh scan (rebuilds temp table).
     */
    public function refresh() {
        $this->maybe_build_attached_temp_table( true );
        return $this->get_summary();
    }
}

// Standalone helper used during temp table creation to detect engine support
function wpim_get_table_engine() {
    global $wpdb;
    // Test if MEMORY engine is available
    $test = @$wpdb->query("CREATE TEMPORARY TABLE _wpim_engine_test (id INT) ENGINE=InnoDB");
    if ( $test !== false ) {
        $wpdb->query("DROP TEMPORARY TABLE IF EXISTS _wpim_engine_test");
        return 'MEMORY';
    }
    return 'InnoDB';
}
