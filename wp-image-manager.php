<?php
/**
 * Plugin Name: WP Image Manager Pro
 * Description: Detect & delete unattached images, auto-convert uploads to WebP, backup & restore.
 * Version: 1.16.4
 * Author: Ringomedia
 * Text Domain: wp-image-manager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPIM_VERSION', '1.16.4' );
define( 'WPIM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPIM_URL', plugin_dir_url( __FILE__ ) );
define( 'WPIM_GITHUB_REPO', 'ammar458/wp-image-manager' );
define( 'WPIM_BACKUP_DIR', ABSPATH . 'wp-image-manager-backup' );
define( 'WPIM_BACKUP_DELETED', WPIM_BACKUP_DIR . '/deleted' );
define( 'WPIM_BACKUP_CONVERTED', WPIM_BACKUP_DIR . '/converted' );

require_once WPIM_DIR . 'includes/class-scanner.php';
require_once WPIM_DIR . 'includes/class-deep-scanner.php';
require_once WPIM_DIR . 'includes/class-deleter.php';
require_once WPIM_DIR . 'includes/class-converter.php';
require_once WPIM_DIR . 'includes/class-restorer.php';
require_once WPIM_DIR . 'includes/class-google-drive.php';
require_once WPIM_DIR . 'includes/class-settings.php';
require_once WPIM_DIR . 'includes/class-ajax.php';
require_once WPIM_DIR . 'includes/class-recovery.php';
require_once WPIM_DIR . 'includes/class-rest.php';
require_once WPIM_DIR . 'includes/class-updater.php';

register_activation_hook( __FILE__, 'wpim_activate' );
register_deactivation_hook( __FILE__, 'wpim_deactivate' );

if ( is_admin() ) {
    new WPIM_Updater( __FILE__, WPIM_GITHUB_REPO, WPIM_VERSION );
}

function wpim_activate() {
    $dirs = [ WPIM_BACKUP_DIR, WPIM_BACKUP_DELETED, WPIM_BACKUP_CONVERTED ];
    foreach ( $dirs as $dir ) {
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
    }
    $htaccess = WPIM_BACKUP_DIR . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
    }
}

function wpim_deactivate() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS _wpim_attached_tmp");
    wp_clear_scheduled_hook( 'wpim_gdrive_queue_sweep' );
    wp_clear_scheduled_hook( 'wpim_gdrive_queue_tick' );
}

add_filter( 'cron_schedules', 'wpim_cron_schedules' );
function wpim_cron_schedules( $schedules ) {
    $schedules['wpim_five_minutes'] = [ 'interval' => 300, 'display' => 'Every 5 Minutes (WP Image Manager)' ];
    return $schedules;
}

// Checked on every load rather than only wpim_activate() — an in-place plugin
// update (auto-updater or re-uploading the zip over an already-active install)
// never re-fires the activation hook, so this is what actually guarantees the
// safety-net sweep exists after upgrading from a version that predates it.
add_action( 'init', 'wpim_ensure_gdrive_sweep_scheduled' );
function wpim_ensure_gdrive_sweep_scheduled() {
    // Safety-net sweep: catches any 'gdrive_pending' backup left behind if the
    // immediate post-delete kick (WPIM_Google_Drive::kick_off_queue()) never
    // ran to completion, e.g. the server restarted mid-upload.
    if ( ! wp_next_scheduled( 'wpim_gdrive_queue_sweep' ) ) {
        wp_schedule_event( time(), 'wpim_five_minutes', 'wpim_gdrive_queue_sweep' );
    }
}

// Both the immediate post-delete kick and the recurring safety-net sweep
// drain the same pending-upload queue.
add_action( 'wpim_gdrive_queue_tick', 'wpim_process_gdrive_queue' );
add_action( 'wpim_gdrive_queue_sweep', 'wpim_process_gdrive_queue' );
function wpim_process_gdrive_queue() {
    ( new WPIM_Deleter() )->process_gdrive_queue();
}

/**
 * Fallback that drains the Google Drive upload queue directly, independent
 * of WP-Cron. WP-Cron's own trigger is an HTTP loopback request to itself
 * (spawn_cron()) — on hosts that disable WP-Cron, block self-requests, or
 * just get very little traffic, that loopback can silently never fire,
 * leaving deletes stuck as "gdrive_pending" forever with no visible error.
 * This runs on every request instead (throttled), after the response has
 * already gone out, so it doesn't cost the page that triggered it anything
 * and doesn't depend on the site being able to call itself over HTTP.
 */
add_action( 'shutdown', 'wpim_maybe_process_gdrive_queue_directly' );
function wpim_maybe_process_gdrive_queue_directly() {
    if ( get_option( 'wpim_backup_destination', 'local' ) !== 'gdrive' ) return;
    if ( ! get_option( 'wpim_gdrive_refresh_token' ) ) return;

    // Throttle how often we even bother checking — process_gdrive_queue()
    // itself has its own lock against concurrent runs; this just avoids a
    // filesystem scan on every single page load.
    if ( get_transient( 'wpim_gdrive_direct_check' ) ) return;
    set_transient( 'wpim_gdrive_direct_check', 1, 2 * MINUTE_IN_SECONDS );

    if ( ! glob( WPIM_BACKUP_DELETED . '/*/meta.json' ) ) return;

    if ( function_exists( 'fastcgi_finish_request' ) ) fastcgi_finish_request();
    ( new WPIM_Deleter() )->process_gdrive_queue();
}

/**
 * External-cron endpoint: lets a scheduler OUTSIDE WordPress (host cron,
 * or a free service like cron-job.org) drain the upload queue on a fixed
 * schedule even when the site gets zero visitors — which every other
 * trigger here (WP-Cron's pseudo-cron, the direct fallback above) still
 * fundamentally needs a request to piggyback on. URL + setup instructions
 * are shown on the Backup Settings tab. Runs on 'init' so it doesn't pull in
 * the full admin/theme bootstrap, and stays out of normal page rendering
 * entirely — a real request just returns "Forbidden" if the token doesn't match.
 */
add_action( 'init', 'wpim_maybe_handle_external_cron_request' );
function wpim_maybe_handle_external_cron_request() {
    if ( empty( $_GET['wpim_gdrive_process'] ) ) return;

    $secret = get_option( 'wpim_gdrive_cron_secret' );
    if ( ! $secret || ! hash_equals( $secret, wp_unslash( $_GET['wpim_gdrive_process'] ) ) ) {
        status_header( 403 );
        exit( 'Forbidden' );
    }

    nocache_headers();
    ( new WPIM_Deleter() )->process_gdrive_queue();
    exit( 'WP Image Manager Pro: Google Drive queue processed.' );
}

add_action( 'admin_menu', 'wpim_add_menu' );
function wpim_add_menu() {
    add_media_page(
        'Image Manager Pro',
        'Image Manager Pro',
        'manage_options',
        'wp-image-manager',
        'wpim_render_page'
    );
}

add_action( 'admin_enqueue_scripts', 'wpim_enqueue' );
function wpim_enqueue( $hook ) {
    if ( $hook !== 'media_page_wp-image-manager' ) return;
    wp_enqueue_style( 'wpim-style', WPIM_URL . 'assets/css/style.css', [], WPIM_VERSION );
    wp_enqueue_script( 'wpim-script', WPIM_URL . 'assets/js/app.js', [ 'jquery' ], WPIM_VERSION, true );
    wp_localize_script( 'wpim-script', 'WPIM', [
        'nonce'   => wp_create_nonce( 'wpim_nonce' ),
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
    ]);
}

// WebP conversion on upload
add_filter( 'wp_handle_upload', 'wpim_convert_on_upload', 10, 2 );
function wpim_convert_on_upload( $upload, $context ) {
    if ( get_option( 'wpim_auto_webp', 1 ) != 1 ) return $upload;
    if ( $context !== 'upload' ) return $upload;
    $type = $upload['type'] ?? '';
    if ( ! in_array( $type, [ 'image/jpeg', 'image/png' ] ) ) return $upload;

    $converter = new WPIM_Converter();
    $result = $converter->convert_file( $upload['file'], true );
    if ( $result ) {
        $upload['file'] = $result['webp_path'];
        $upload['url']  = str_replace( basename( $upload['url'] ), basename( $result['webp_path'] ), $upload['url'] );
        $upload['type'] = 'image/webp';
    }
    return $upload;
}

function wpim_render_page() {
    include WPIM_DIR . 'includes/admin-page.php';
}

/**
 * Generate a small preview thumbnail as an inline base64 data URI, so restore
 * lists can show a real image regardless of whether the backup itself lives
 * locally or on Google Drive (neither of which is safe/simple to hotlink —
 * the local folder is .htaccess-protected, and Drive files aren't public).
 */
function wpim_generate_thumb_data_uri( $path, $max = 150 ) {
    if ( ! file_exists( $path ) || ! function_exists( 'imagecreatetruecolor' ) ) return '';

    $info = @getimagesize( $path );
    if ( ! $info ) return '';

    switch ( $info['mime'] ) {
        case 'image/jpeg':
            $src = @imagecreatefromjpeg( $path );
            break;
        case 'image/png':
            $src = @imagecreatefrompng( $path );
            break;
        case 'image/webp':
            $src = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
            break;
        case 'image/gif':
            $src = @imagecreatefromgif( $path );
            break;
        default:
            return '';
    }
    if ( ! $src ) return '';

    $w = imagesx( $src );
    $h = imagesy( $src );
    $ratio = min( $max / $w, $max / $h, 1 );
    $nw = max( 1, (int) round( $w * $ratio ) );
    $nh = max( 1, (int) round( $h * $ratio ) );

    $thumb = imagecreatetruecolor( $nw, $nh );
    imagealphablending( $thumb, false );
    imagesavealpha( $thumb, true );
    imagecopyresampled( $thumb, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );

    ob_start();
    imagejpeg( $thumb, null, 70 );
    $data = ob_get_clean();

    imagedestroy( $src );
    imagedestroy( $thumb );

    if ( ! $data ) return '';
    return 'data:image/jpeg;base64,' . base64_encode( $data );
}
