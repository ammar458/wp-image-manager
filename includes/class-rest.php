<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST routes for the image-recovery feature. Separate from class-ajax.php
 * on purpose: admin-ajax's nonce is tied to a logged-in browser session, but
 * recovery is meant to be driven from outside the browser (e.g. scripted
 * against a WordPress Application Password), which only REST's
 * current_user_can()-based auth supports.
 */
class WPIM_Rest {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'wpim/v1', '/inspect/(?P<id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'callback'            => [ $this, 'handle_inspect' ],
        ] );

        register_rest_route( 'wpim/v1', '/gallery-field/(?P<post_type>[a-z_\-]+)', [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'callback'            => [ $this, 'handle_gallery_field' ],
        ] );

        register_rest_route( 'wpim/v1', '/recover', [
            'methods'             => 'POST',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'callback'            => [ $this, 'handle_recover' ],
        ] );
    }

    public function handle_inspect( $req ) {
        $id = (int) $req['id'];
        return [
            'post_id'   => $id,
            'post_type' => get_post_type( $id ),
            'title'     => get_the_title( $id ),
            'meta'      => get_post_meta( $id ),
        ];
    }

    public function handle_gallery_field( $req ) {
        $recovery = new WPIM_Recovery();
        $field    = $recovery->discover_gallery_field( sanitize_key( $req['post_type'] ) );
        return [ 'field' => $field ];
    }

    public function handle_recover( WP_REST_Request $req ) {
        if ( ! ini_get( 'safe_mode' ) ) {
            @set_time_limit( 120 );
        }

        $post_id      = (int) $req->get_param( 'post_id' );
        $post_type    = sanitize_key( $req->get_param( 'post_type' ) ?: 'boats' );
        $featured_url = $req->get_param( 'featured_image_url' );
        $gallery_urls = (array) $req->get_param( 'gallery_image_urls' );
        $dry_run      = (bool) $req->get_param( 'dry_run' );

        if ( ! $post_id ) {
            return new WP_Error( 'wpim_bad_request', 'post_id is required.', [ 'status' => 400 ] );
        }

        // Only allow https image URLs — this endpoint is admin-only, but
        // there's no reason to let it fetch arbitrary schemes/hosts anyway.
        $is_https = function ( $u ) { return is_string( $u ) && wp_http_validate_url( $u ) && strpos( $u, 'https://' ) === 0; };

        $gallery_urls = array_values( array_filter( $gallery_urls, $is_https ) );
        if ( $featured_url && ! $is_https( $featured_url ) ) $featured_url = '';

        $recovery = new WPIM_Recovery();
        $result   = $recovery->recover_post( $post_id, $post_type, $featured_url, $gallery_urls, $dry_run );

        return rest_ensure_response( $result );
    }
}

new WPIM_Rest();
