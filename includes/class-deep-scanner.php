<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Deep Scanner — catches "hidden" image references the fast SQL-only pass
 * (WPIM_Scanner) cannot see, because they aren't stored as a plain numeric
 * attachment ID in a standard WP location.
 *
 * Covers:
 *   1. AdRotate ads          -> {$prefix}adrotate.image column (a file path, not an ID)
 *   2. Serialized PHP arrays -> ACF galleries, WooCommerce meta, widgets, theme mods
 *   3. JSON blobs            -> Elementor _elementor_data, Gutenberg block attrs, etc.
 *   4. Comma-separated IDs   -> _product_image_gallery and similar CSV meta
 *   5. Raw URLs / file paths -> <img src="">, CSS background-image, shortcode attrs,
 *                               anything containing "wp-content/uploads/..."
 *
 * Because this walks every non-trivial postmeta/option value it is heavier
 * than the fast scan, so it's exposed as an explicit "Deep Scan" action the
 * user runs after the fast scan, rather than on every page load.
 */
class WPIM_Deep_Scanner {

    /** @var array<string,int> filename (basename, no size suffix) => attachment ID */
    private $filename_map = [];

    /** @var array<string,int> same as $filename_map but lower-cased, for case-insensitive fallback matches */
    private $filename_map_lower = [];

    /** @var array<string,int> lower-cased path relative to uploads dir (e.g. "2026/07/mobile.jpg") => attachment ID */
    private $path_map = [];

    /** @var array<int,bool> set of valid image attachment IDs, for validating candidate IDs */
    private $valid_ids = [];

    /** @var array<int,string> post_id => post_type, built lazily as postmeta rows are scanned */
    private $post_type_cache = [];

    /** Safety cap so a single request can't run forever on huge tables. */
    private $max_rows_per_table = 20000;
    private $batch_size = 2000;

    private $meta_key_blacklist = [
        '_edit_lock', '_edit_last', '_wp_trash_meta_time', '_wp_trash_meta_status',
        'comment_count', '_wp_page_template', 'menu_order', 'post_parent',
        '_wp_old_slug', '_wp_old_date',
    ];

    /**
     * Run the full deep scan. Returns a report of what it found, and
     * inserts every newly-matched attachment ID into _wpim_attached_tmp
     * (created already by WPIM_Scanner) with a 'source' tag.
     */
    public function run() {
        global $wpdb;

        // Make sure the base temp table + its 'source' column exist.
        $this->ensure_source_column();

        $this->build_lookup_maps();

        $report = [
            'adrotate'   => 0,
            'postmeta'   => 0,
            'options'    => 0,
            'content'    => 0,
            'total_new'  => 0,
        ];

        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM _wpim_attached_tmp" );

        $report['adrotate'] = $this->scan_adrotate();
        $report['postmeta'] = $this->scan_postmeta();
        $report['options']  = $this->scan_options();
        $report['content']  = $this->scan_post_content();

        $after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM _wpim_attached_tmp" );
        $report['total_new'] = max( 0, $after - $before );

        return $report;
    }

    private function ensure_source_column() {
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

    private function insert_id( $id, $source, $post_type = '', $owner_id = 0 ) {
        global $wpdb;
        $id = (int) $id;
        if ( $id <= 0 || ! isset( $this->valid_ids[ $id ] ) ) return false;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO _wpim_attached_tmp (aid, source, post_type, owner_id) VALUES (%d, %s, %s, %d)
             ON DUPLICATE KEY UPDATE source = source", // keep first source, don't overwrite
            $id, $source, (string) $post_type, (int) $owner_id
        ) );
        return true;
    }

    /**
     * Batch-resolve post_type for a set of post IDs into $post_type_cache,
     * one query per unique batch instead of one query per row — postmeta
     * scans can touch thousands of rows referencing a much smaller set of posts.
     */
    private function warm_post_type_cache( $post_ids ) {
        global $wpdb;
        $missing = array_diff( array_unique( array_map( 'intval', $post_ids ) ), array_keys( $this->post_type_cache ) );
        if ( empty( $missing ) ) return;

        $placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
            $missing
        ) );
        foreach ( $rows as $row ) {
            $this->post_type_cache[ (int) $row->ID ] = $row->post_type;
        }
        foreach ( $missing as $mid ) {
            if ( ! isset( $this->post_type_cache[ $mid ] ) ) $this->post_type_cache[ $mid ] = '';
        }
    }

    /**
     * Build:
     *  - valid_ids: every image attachment ID that exists (for validating candidates)
     *  - filename_map: basename (with size-suffix like -300x300 stripped) => ID
     */
    private function build_lookup_maps() {
        global $wpdb;

        $rows = $wpdb->get_results( "
            SELECT ID, guid FROM {$wpdb->posts}
            WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
        " );
        foreach ( $rows as $row ) {
            $id = (int) $row->ID;
            $this->valid_ids[ $id ] = true;
            $url_path = parse_url( $row->guid, PHP_URL_PATH ) ?: $row->guid;
            $this->add_filename( basename( $url_path ), $id );
            $this->index_path_from_uploads( $url_path, $id );
        }

        // Also index the real relative file (covers cases where guid and
        // actual stored file differ, e.g. after moving uploads). This is the
        // authoritative source for the full relative path, since multiple
        // attachments very commonly share the same basename across different
        // year/month upload folders (e.g. two unrelated "banner.jpg" uploads).
        $attached = $wpdb->get_results( "
            SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'
        " );
        foreach ( $attached as $row ) {
            $id = (int) $row->post_id;
            if ( ! isset( $this->valid_ids[ $id ] ) ) continue;
            $this->add_filename( basename( $row->meta_value ), $id );
            $this->index_path( $row->meta_value, $id );
        }
    }

    private function add_filename( $filename, $id ) {
        if ( ! $filename ) return;
        $this->filename_map[ $filename ] = $id;
        $this->index_lower( $filename, $id );
        // Also index without a WP size suffix, e.g. photo-300x200.jpg -> photo.jpg
        $stripped = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $filename );
        if ( $stripped !== $filename && ! isset( $this->filename_map[ $stripped ] ) ) {
            $this->filename_map[ $stripped ] = $id;
            $this->index_lower( $stripped, $id );
        }
    }

    private function index_lower( $filename, $id ) {
        $lower = strtolower( $filename );
        if ( ! isset( $this->filename_map_lower[ $lower ] ) ) {
            $this->filename_map_lower[ $lower ] = $id;
        }
    }

    /**
     * Index by the path relative to the uploads directory (e.g. "2026/07/mobile.jpg"),
     * which — unlike a bare basename — reliably distinguishes two different
     * attachments that happen to share the same filename in different
     * year/month upload folders.
     */
    private function index_path( $rel_path, $id ) {
        $rel_path = ltrim( str_replace( '\\', '/', $rel_path ), '/' );
        if ( ! $rel_path ) return;
        $key = strtolower( $rel_path );
        if ( ! isset( $this->path_map[ $key ] ) ) {
            $this->path_map[ $key ] = $id;
        }
    }

    private function index_path_from_uploads( $url_path, $id ) {
        if ( preg_match( '#uploads/(.+)$#i', $url_path, $m ) ) {
            $this->index_path( $m[1], $id );
        }
    }

    private function match_filename_in_string( $text ) {
        $matched = [];
        if ( ! is_string( $text ) || $text === '' ) return $matched;

        // Case-insensitive gate — real-world uploads (camera photos, banner
        // exports) very often have upper-case extensions like .JPG/.PNG.
        if ( stripos( $text, 'uploads' ) === false && stripos( $text, '.jpg' ) === false
            && stripos( $text, '.jpeg' ) === false && stripos( $text, '.png' ) === false
            && stripos( $text, '.webp' ) === false && stripos( $text, '.gif' ) === false ) {
            return $matched;
        }
        // Match up to the extension using "not whitespace/quote/bracket" instead of a
        // fixed character whitelist, so percent-encoded, unicode, or spaced-but-quoted
        // filenames aren't silently skipped.
        if ( preg_match_all( '#[^\s"\'<>()]+\.(?:jpe?g|png|gif|webp)#i', $text, $m ) ) {
            foreach ( $m[0] as $path ) {
                // Prefer a full relative-path match (unambiguous) over a bare
                // basename match, since many sites have multiple different
                // attachments sharing the same filename in different folders.
                $id = null;
                if ( preg_match( '#uploads/(.+)$#i', $path, $pm ) ) {
                    $key = strtolower( ltrim( str_replace( '\\', '/', $pm[1] ), '/' ) );
                    if ( isset( $this->path_map[ $key ] ) ) {
                        $id = $this->path_map[ $key ];
                    }
                }

                if ( $id === null ) {
                    $base = basename( $path );
                    if ( isset( $this->filename_map[ $base ] ) ) {
                        $id = $this->filename_map[ $base ];
                    } else {
                        $lower = strtolower( $base );
                        if ( isset( $this->filename_map_lower[ $lower ] ) ) {
                            $id = $this->filename_map_lower[ $lower ];
                        }
                    }
                }

                if ( $id !== null ) $matched[] = $id;
            }
        }
        return $matched;
    }

    /**
     * Recursively walk a decoded value (from unserialize or json_decode)
     * collecting int-like leaves (candidate attachment IDs) and string
     * leaves that look like filenames/URLs.
     */
    private function walk_value( $value, &$found_ids ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            foreach ( (array) $value as $v ) {
                $this->walk_value( $v, $found_ids );
            }
            return;
        }
        if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[0-9]{1,10}$/', $value ) ) ) {
            $found_ids[] = (int) $value;
            return;
        }
        if ( is_string( $value ) ) {
            foreach ( $this->match_filename_in_string( $value ) as $id ) {
                $found_ids[] = $id;
            }
        }
    }

    /**
     * 1. AdRotate: the AdCode field usually just contains a "%asset%" placeholder
     *    tag (not a real path) — the actual image lives in the "Banner asset"
     *    picker, saved to the 'image' column either as a WP media URL/path or,
     *    on some installs, as a bare WP attachment ID. Pro/responsive variants
     *    can also store a serialized array of per-device image paths. Schema
     *    varies across free/Pro versions, so columns are detected rather than
     *    hard-coded.
     */
    private function scan_adrotate() {
        global $wpdb;
        $table = $wpdb->prefix . 'adrotate';
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $exists ) return 0;

        $count = 0;

        $known_columns  = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
        $image_columns  = array_intersect(
            [ 'image', 'bannercode', 'thumbnail', 'desktop_image', 'mobile_image', 'tablet_image', 'mobile_tablet_image' ],
            $known_columns
        );
        if ( ! empty( $image_columns ) ) {
            $select = implode( ', ', array_map( function ( $c ) { return "`{$c}`"; }, $image_columns ) );
            $rows   = $wpdb->get_results( "SELECT {$select} FROM `{$table}`", ARRAY_A );

            foreach ( $rows as $row ) {
                foreach ( $row as $val ) {
                    foreach ( $this->extract_ids_from_value( $val ) as $id ) {
                        if ( $this->insert_id( $id, 'adrotate' ) ) $count++;
                    }
                }
            }
        }

        // AdRotate Pro keeps a separate table for its newer "Creatives" ad type.
        $creatives_table = $wpdb->prefix . 'adrotate_creatives';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$creatives_table}'" ) ) {
            $creative_columns = $wpdb->get_col( "DESCRIBE `{$creatives_table}`", 0 );
            $creative_image_columns = array_intersect( [ 'image', 'bannercode', 'thumbnail' ], $creative_columns );
            if ( ! empty( $creative_image_columns ) ) {
                $c_select = implode( ', ', array_map( function ( $c ) { return "`{$c}`"; }, $creative_image_columns ) );
                $c_rows   = $wpdb->get_results( "SELECT {$c_select} FROM `{$creatives_table}`", ARRAY_A );
                foreach ( $c_rows as $row ) {
                    foreach ( $row as $val ) {
                        foreach ( $this->extract_ids_from_value( $val ) as $id ) {
                            if ( $this->insert_id( $id, 'adrotate' ) ) $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Resolve a single stored value (from a DB column) to candidate attachment
     * IDs, whether it's a bare numeric ID, a serialized array of paths/IDs, or
     * a plain path/URL string.
     */
    private function extract_ids_from_value( $val ) {
        if ( ! is_string( $val ) || $val === '' ) return [];

        // Some installs store the WP attachment ID directly instead of a path.
        if ( preg_match( '/^[0-9]{1,10}$/', trim( $val ) ) ) {
            return [ (int) trim( $val ) ];
        }

        // Pro/responsive versions can store a serialized array of per-size image paths/IDs.
        if ( strpos( $val, 'a:' ) === 0 ) {
            $decoded = @unserialize( $val, [ 'allowed_classes' => false ] );
            if ( $decoded !== false ) {
                $found = [];
                $this->walk_value( $decoded, $found );
                return array_unique( $found );
            }
        }

        return $this->match_filename_in_string( $val );
    }

    /**
     * 2/3/4. postmeta: serialized arrays, JSON blobs, CSV id lists, raw URLs.
     * Only looks at meta_values that are non-trivial (skips plain scalars
     * already handled by the fast SQL scan).
     */
    private function scan_postmeta() {
        global $wpdb;
        $count = 0;
        $processed = 0;
        $last_id = 0;

        $blacklist_sql = "'" . implode( "','", array_map( 'esc_sql', $this->meta_key_blacklist ) ) . "'";

        while ( $processed < $this->max_rows_per_table ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "
                SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                WHERE meta_id > %d
                AND meta_key NOT IN ({$blacklist_sql})
                AND (
                    meta_value LIKE 'a:%%'
                    OR meta_value LIKE '{%%'
                    OR meta_value LIKE '[%%'
                    OR meta_value REGEXP '^[0-9]+(,[0-9]+)+$'
                    OR meta_value LIKE '%%wp-content/uploads%%'
                    OR meta_value LIKE '%%.jpg%%' OR meta_value LIKE '%%.jpeg%%'
                    OR meta_value LIKE '%%.png%%' OR meta_value LIKE '%%.webp%%'
                )
                ORDER BY meta_id ASC
                LIMIT %d
            ", $last_id, $this->batch_size ) );

            if ( empty( $rows ) ) break;

            $this->warm_post_type_cache( wp_list_pluck( $rows, 'post_id' ) );

            foreach ( $rows as $row ) {
                $last_id = (int) $row->meta_id;
                $found = [];
                $val = $row->meta_value;

                if ( strpos( $val, 'a:' ) === 0 ) {
                    $decoded = @unserialize( $val, [ 'allowed_classes' => false ] );
                    if ( $decoded !== false ) {
                        $this->walk_value( $decoded, $found );
                    }
                } elseif ( $val !== '' && ( $val[0] === '{' || $val[0] === '[' ) ) {
                    $decoded = json_decode( $val, true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        $this->walk_value( $decoded, $found );
                    }
                } elseif ( preg_match( '/^[0-9]+(,[0-9]+)+$/', $val ) ) {
                    foreach ( explode( ',', $val ) as $piece ) {
                        $found[] = (int) $piece;
                    }
                } else {
                    $found = array_merge( $found, $this->match_filename_in_string( $val ) );
                }

                $source    = ( $row->meta_key === '_elementor_data' ) ? 'elementor' : 'postmeta-deep';
                $post_type = $this->post_type_cache[ (int) $row->post_id ] ?? '';

                foreach ( array_unique( $found ) as $id ) {
                    if ( $this->insert_id( $id, $source, $post_type, $row->post_id ) ) $count++;
                }
            }

            $processed += count( $rows );
            if ( count( $rows ) < $this->batch_size ) break;
        }

        return $count;
    }

    /**
     * Options table: widgets, theme_mods (custom backgrounds/headers), and
     * common page-builder global settings. Restricted to autoloaded rows
     * plus a few explicit prefixes to keep this bounded on busy sites.
     */
    private function scan_options() {
        global $wpdb;
        $count = 0;

        $rows = $wpdb->get_results( "
            SELECT option_name, option_value FROM {$wpdb->options}
            WHERE (
                option_name LIKE 'widget_%'
                OR option_name LIKE 'theme_mods_%'
                OR option_name LIKE 'elementor_%'
                OR option_name = 'sidebars_widgets'
            )
            AND (
                option_value LIKE 'a:%'
                OR option_value LIKE '{%'
                OR option_value LIKE '%wp-content/uploads%'
            )
            LIMIT {$this->max_rows_per_table}
        " );

        foreach ( $rows as $row ) {
            $found = [];
            $val = $row->option_value;

            if ( strpos( $val, 'a:' ) === 0 ) {
                $decoded = @unserialize( $val, [ 'allowed_classes' => false ] );
                if ( $decoded !== false ) $this->walk_value( $decoded, $found );
            } elseif ( $val !== '' && ( $val[0] === '{' || $val[0] === '[' ) ) {
                $decoded = json_decode( $val, true );
                if ( json_last_error() === JSON_ERROR_NONE ) $this->walk_value( $decoded, $found );
            } else {
                $found = $this->match_filename_in_string( $val );
            }

            $source = ( strpos( $row->option_name, 'elementor_' ) === 0 ) ? 'elementor' : 'options-deep';

            foreach ( array_unique( $found ) as $id ) {
                if ( $this->insert_id( $id, $source ) ) $count++;
            }
        }

        return $count;
    }

    /**
     * Post content: anything the fast scan's "wp-image-{ID} class" check
     * misses — raw <img src="">, CSS background-image, shortcode attributes
     * that reference an uploads path directly instead of an attachment ID.
     */
    private function scan_post_content() {
        global $wpdb;
        $count = 0;

        $rows = $wpdb->get_results( "
            SELECT ID, post_type, post_content FROM {$wpdb->posts}
            WHERE post_type NOT IN ('attachment','revision')
            AND post_status NOT IN ('trash','auto-draft')
            AND (
                post_content LIKE '%wp-content/uploads%'
                OR post_content LIKE '%.jpg%' OR post_content LIKE '%.jpeg%'
                OR post_content LIKE '%.png%' OR post_content LIKE '%.webp%'
            )
            LIMIT {$this->max_rows_per_table}
        " );

        foreach ( $rows as $row ) {
            foreach ( $this->match_filename_in_string( $row->post_content ) as $id ) {
                if ( $this->insert_id( $id, 'content-url', $row->post_type, $row->ID ) ) $count++;
            }
        }

        return $count;
    }
}
