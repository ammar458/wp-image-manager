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
    public function get_unattached_page( $page = 1, $per_page = 100 ) {
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
        if ( $exists && ! $force ) {
            $this->ensure_columns();
            return;
        }

        $wpdb->query("DROP TABLE IF EXISTS _wpim_attached_tmp");
        $wpdb->query("
            CREATE TABLE _wpim_attached_tmp (
                aid BIGINT UNSIGNED NOT NULL,
                source VARCHAR(30) NOT NULL DEFAULT 'core',
                post_type VARCHAR(32) NOT NULL DEFAULT '',
                owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY(aid)
            ) ENGINE=InnoDB
        ");

        // ── 1. Standard parent attachment ──────────────────────────────
        // post_type here is the PARENT post's type (e.g. 'boats'), not the
        // attachment's own type, which is always 'attachment'. owner_id is
        // the parent post — where the image is actually attached.
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid, source, post_type, owner_id)
            SELECT p.ID, 'core', COALESCE(parent.post_type, ''), p.post_parent
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
            WHERE p.post_type = 'attachment'
            AND p.post_parent > 0
            AND p.post_mime_type LIKE 'image/%'
            AND p.post_status != 'trash'
        ");

        // ── 2. _thumbnail_id (featured images) ─────────────────────────
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid, source, post_type, owner_id)
            SELECT CAST(pm.meta_value AS UNSIGNED), 'thumbnail', COALESCE(owner.post_type, ''), pm.post_id
            FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} owner ON owner.ID = pm.post_id
            WHERE pm.meta_key = '_thumbnail_id'
            AND pm.meta_value REGEXP '^[0-9]+$'
            AND CAST(pm.meta_value AS UNSIGNED) > 0
        ");

        // ── 3. Numeric-only postmeta values that look like attachment IDs ──
        // Covers JetEngine image fields, ACF image fields (ID mode), etc.
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid, source, post_type, owner_id)
            SELECT CAST(pm.meta_value AS UNSIGNED), 'postmeta', COALESCE(owner.post_type, ''), pm.post_id
            FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} owner ON owner.ID = pm.post_id
            WHERE pm.meta_value REGEXP '^[0-9]{1,10}$'
            AND CAST(pm.meta_value AS UNSIGNED) > 0
            AND pm.meta_key NOT IN (
                '_edit_lock','_edit_last','_wp_trash_meta_time','comment_count',
                '_wp_page_template','menu_order','post_parent'
            )
        ");

        // ── 4. Numeric-only usermeta (user profile images) ─────────────
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid, source, owner_id)
            SELECT DISTINCT CAST(meta_value AS UNSIGNED), 'usermeta', user_id
            FROM {$wpdb->usermeta}
            WHERE meta_value REGEXP '^[0-9]{1,10}$'
            AND CAST(meta_value AS UNSIGNED) > 0
        ");

        // ── 5. Numeric-only termmeta (category/tag images) ─────────────
        if ( $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->termmeta}'") ) {
            $wpdb->query("
                INSERT IGNORE INTO _wpim_attached_tmp (aid, source, owner_id)
                SELECT DISTINCT CAST(meta_value AS UNSIGNED), 'termmeta', term_id
                FROM {$wpdb->termmeta}
                WHERE meta_value REGEXP '^[0-9]{1,10}$'
                AND CAST(meta_value AS UNSIGNED) > 0
            ");
        }

        // ── 6. Options: site logo, custom logo, site_icon ──────────────
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid, source)
            SELECT CAST(option_value AS UNSIGNED), 'options'
            FROM {$wpdb->options}
            WHERE option_name IN ('site_logo','site_icon','custom_logo')
            AND option_value REGEXP '^[0-9]+$'
            AND CAST(option_value AS UNSIGNED) > 0
        ");

        // ── 7. wp-image-{ID} class in post content ─────────────────────
        // Use MySQL REGEXP to find IDs without loading content into PHP.
        // owner_id is the containing post (where the <img> tag lives).
        $wpdb->query("
            INSERT IGNORE INTO _wpim_attached_tmp (aid, source, post_type, owner_id)
            SELECT DISTINCT
                CAST(REGEXP_SUBSTR(post_content, 'wp-image-([0-9]+)') AS UNSIGNED),
                'content',
                post_type,
                ID
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
     * Adds source/post_type columns to a temp table left over from a version
     * of this plugin that predates attachment categorization, so "Browse
     * Attached" doesn't need a fresh scan just to see them appear.
     */
    private function ensure_columns() {
        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM _wpim_attached_tmp", 0 );
        if ( ! in_array( 'source', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE _wpim_attached_tmp ADD COLUMN source VARCHAR(30) NOT NULL DEFAULT 'core'" );
        }
        if ( ! in_array( 'post_type', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE _wpim_attached_tmp ADD COLUMN post_type VARCHAR(32) NOT NULL DEFAULT ''" );
        }
        if ( ! in_array( 'owner_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE _wpim_attached_tmp ADD COLUMN owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0" );
        }
    }

    /**
     * Force a fresh scan (rebuilds temp table).
     */
    public function refresh() {
        $this->maybe_build_attached_temp_table( true );
        return $this->get_summary();
    }

    /**
     * List of "what is this image attached to" categories for the Browse
     * Attached tab, auto-derived from what the scans actually found —
     * custom post types (e.g. 'boats', 'dlr_boats') group by post_type,
     * while sources that aren't tied to one post (Elementor, AdRotate,
     * user/term meta, site options) get their own bucket. See
     * category_where_clause() for the matching per-category filter.
     */
    public function get_attached_categories() {
        global $wpdb;
        $this->maybe_build_attached_temp_table();

        $rows = $wpdb->get_results("
            SELECT
                CASE
                    WHEN source = 'elementor' THEN 'source:elementor'
                    WHEN source = 'adrotate' THEN 'source:adrotate'
                    WHEN post_type <> '' THEN CONCAT('posttype:', post_type)
                    WHEN source = 'usermeta' THEN 'source:usermeta'
                    WHEN source = 'termmeta' THEN 'source:termmeta'
                    WHEN source IN ('options','options-deep') THEN 'source:options'
                    ELSE 'source:other'
                END AS category,
                COUNT(*) AS cnt
            FROM _wpim_attached_tmp
            GROUP BY category
            ORDER BY cnt DESC
        ");

        $labels = [
            'source:elementor' => 'Elementor',
            'source:adrotate'  => 'AdRotate',
            'source:usermeta'  => 'User Profile Images',
            'source:termmeta'  => 'Category/Tag Images',
            'source:options'   => 'Site Options & Widgets',
            'source:other'     => 'Other / Unrecognized',
        ];

        $categories = [];
        foreach ( $rows as $row ) {
            $key = $row->category;
            if ( strpos( $key, 'posttype:' ) === 0 ) {
                $slug  = substr( $key, 9 );
                $obj   = get_post_type_object( $slug );
                $label = $obj ? $obj->labels->name : ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
            } else {
                $label = $labels[ $key ] ?? ucwords( str_replace( [ '-', '_', ':' ], ' ', $key ) );
            }
            $categories[] = [
                'key'   => $key,
                'label' => $label,
                'count' => (int) $row->cnt,
            ];
        }
        return $categories;
    }

    /**
     * Paginated list of attached images belonging to one category from
     * get_attached_categories(). Same image shape as get_unattached_page().
     */
    public function get_attached_page( $category, $page = 1, $per_page = 100 ) {
        global $wpdb;
        $this->maybe_build_attached_temp_table();

        $where = $this->category_where_clause( $category );
        if ( $where === null ) {
            return [ 'images' => [], 'total' => 0, 'pages' => 1, 'current' => 1 ];
        }

        $offset = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM _wpim_attached_tmp t WHERE {$where}" );

        $rows = $wpdb->get_results( $wpdb->prepare("
            SELECT t.aid, t.source, t.post_type, t.owner_id FROM _wpim_attached_tmp t
            WHERE {$where}
            ORDER BY t.aid DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset ) );

        $images = [];
        foreach ( $rows as $row ) {
            $id   = (int) $row->aid;
            $path = get_attached_file( $id );
            $size = ( $path && file_exists( $path ) ) ? filesize( $path ) : 0;
            $images[] = [
                'id'          => $id,
                'title'       => get_the_title( $id ),
                'url'         => wp_get_attachment_url( $id ),
                'thumb'       => wp_get_attachment_image_url( $id, 'thumbnail' ),
                'filename'    => $path ? basename( $path ) : '',
                'size'        => $size ? size_format( $size ) : 'N/A',
                'date'        => get_the_date( 'Y-m-d', $id ),
                'mime'        => get_post_mime_type( $id ),
                'attached_to' => $this->resolve_attachment_location( $row->source, (int) $row->owner_id ),
            ];
        }

        return [
            'images'  => $images,
            'total'   => $total,
            'pages'   => max( 1, ceil( $total / $per_page ) ),
            'current' => (int) $page,
        ];
    }

    /**
     * Boat listings (post types 'boats' and 'dlr_boats') that are missing a
     * featured image and/or any gallery/content image. Reuses owner_id from
     * _wpim_attached_tmp — the same table that powers Browse Attached — so
     * "image_count" here is every image this scanner has ever found linked
     * to that post (thumbnail, gallery/postmeta fields, or images referenced
     * in the post content), not just the featured image.
     */
    public function get_posts_missing_images( $post_types = [ 'boats', 'dlr_boats' ] ) {
        global $wpdb;
        $this->maybe_build_attached_temp_table();

        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT p.ID, p.post_title, p.post_type,
                ( SELECT COUNT(*) FROM _wpim_attached_tmp t WHERE t.owner_id = p.ID ) AS image_count,
                ( SELECT CAST(pm.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} pm
                  WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' LIMIT 1 ) AS thumbnail_id
            FROM {$wpdb->posts} p
            WHERE p.post_type IN ({$placeholders})
            AND p.post_status NOT IN ('trash','auto-draft')
        ", $post_types ) );

        $items = [];
        foreach ( $rows as $row ) {
            $has_featured = ! empty( $row->thumbnail_id ) && get_post( (int) $row->thumbnail_id );
            $image_count  = (int) $row->image_count;

            // Only surface listings actually missing something — a featured
            // image plus 2+ other images (real gallery) isn't worth listing.
            if ( $has_featured && $image_count > 1 ) continue;

            $items[] = [
                'id'           => (int) $row->ID,
                'title'        => $row->post_title !== '' ? $row->post_title : '(no title)',
                'post_type'    => $row->post_type,
                'edit_link'    => get_edit_post_link( (int) $row->ID, '' ),
                'has_featured' => $has_featured,
                'image_count'  => $image_count,
            ];
        }

        usort( $items, function( $a, $b ) {
            return strcasecmp( $a['title'], $b['title'] );
        } );

        return $items;
    }

    /**
     * Turn a temp-table row (source + owner_id) into a human-readable "where
     * is this attached" label plus an edit link, for the Browse Attached tab.
     * owner_id means different things per source (post ID, user ID, term ID)
     * since it's populated differently per UNION branch in
     * maybe_build_attached_temp_table() / WPIM_Deep_Scanner::insert_id().
     */
    private function resolve_attachment_location( $source, $owner_id ) {
        switch ( $source ) {
            case 'usermeta':
                if ( $owner_id > 0 ) {
                    $user = get_userdata( $owner_id );
                    if ( $user ) {
                        return [ 'label' => 'User: ' . $user->display_name, 'link' => get_edit_user_link( $owner_id ) ];
                    }
                }
                return [ 'label' => 'User profile image', 'link' => '' ];

            case 'termmeta':
                if ( $owner_id > 0 ) {
                    $term = get_term( $owner_id );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $link = get_edit_term_link( $term );
                        return [ 'label' => 'Term: ' . $term->name, 'link' => $link && ! is_wp_error( $link ) ? $link : '' ];
                    }
                }
                return [ 'label' => 'Category/tag image', 'link' => '' ];

            case 'options':
            case 'options-deep':
                return [ 'label' => 'Site option / widget', 'link' => admin_url( 'options-general.php' ) ];

            case 'adrotate':
                return [ 'label' => 'AdRotate advertisement', 'link' => '' ];
        }

        // Remaining sources (core, thumbnail, postmeta, postmeta-deep, elementor,
        // content, content-url) attach owner_id to a post.
        if ( $owner_id > 0 ) {
            $post = get_post( $owner_id );
            if ( $post ) {
                $title      = get_the_title( $post );
                $obj        = get_post_type_object( $post->post_type );
                $type_label = $obj ? $obj->labels->singular_name : $post->post_type;
                $link       = get_edit_post_link( $owner_id, '' );
                return [ 'label' => ( $title !== '' ? $title : '(no title)' ) . ' (' . $type_label . ')', 'link' => $link ?: '' ];
            }
        }

        return [ 'label' => 'Unknown location', 'link' => '' ];
    }

    /**
     * Translate a category key from get_attached_categories() into a SQL
     * WHERE fragment. Mirrors that method's CASE precedence exactly (source
     * 'elementor'/'adrotate' win over post_type) so counts and listings agree.
     * Returns null for an unrecognized key.
     */
    private function category_where_clause( $category ) {
        global $wpdb;

        if ( strpos( $category, 'posttype:' ) === 0 ) {
            $slug = substr( $category, 9 );
            return $wpdb->prepare( "t.post_type = %s AND t.source NOT IN ('elementor','adrotate')", $slug );
        }

        switch ( $category ) {
            case 'source:elementor': return "t.source = 'elementor'";
            case 'source:adrotate':  return "t.source = 'adrotate'";
            case 'source:usermeta':  return "t.source = 'usermeta' AND t.post_type = ''";
            case 'source:termmeta':  return "t.source = 'termmeta' AND t.post_type = ''";
            case 'source:options':   return "t.source IN ('options','options-deep') AND t.post_type = ''";
            case 'source:other':     return "t.post_type = '' AND t.source NOT IN ('elementor','adrotate','usermeta','termmeta','options','options-deep')";
        }
        return null;
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
