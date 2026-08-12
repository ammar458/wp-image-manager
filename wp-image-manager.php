<?php
/**
 * Plugin Name: WP Image Manager Pro
 * Description: Detect & delete unattached images, auto-convert uploads to WebP, backup & restore.
 * Version: 1.3.0
 * Author: Ringomedia
 * Text Domain: wp-image-manager
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPIM_VERSION', '1.3.0' );
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
