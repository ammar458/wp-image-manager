<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Minimal Google Drive API v3 client using a user-supplied OAuth app
 * (Client ID/Secret). Stores tokens in wp_options and uploads/downloads
 * backup files via wp_remote_* — no external SDK required.
 */
class WPIM_Google_Drive {

    const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const API_BASE    = 'https://www.googleapis.com/drive/v3';
    const UPLOAD_URL  = 'https://www.googleapis.com/upload/drive/v3/files';
    const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    const SCOPE       = 'https://www.googleapis.com/auth/drive.file';

    public static function get_redirect_uri() {
        return admin_url( 'admin-post.php?action=wpim_gdrive_oauth_callback' );
    }

    /**
     * Nudge the pending-upload queue to run as soon as possible without
     * making the current request wait: schedule it, then trigger WP-Cron's
     * own non-blocking loopback request immediately (the same mechanism core
     * uses) instead of waiting for the next real pageview to tick cron.
     */
    public static function kick_off_queue() {
        if ( ! wp_next_scheduled( 'wpim_gdrive_queue_tick' ) ) {
            wp_schedule_single_event( time(), 'wpim_gdrive_queue_tick' );
        }
        if ( function_exists( 'spawn_cron' ) ) spawn_cron();
    }

    public static function is_configured() {
        return (bool) get_option( 'wpim_gdrive_client_id' ) && (bool) get_option( 'wpim_gdrive_client_secret' );
    }

    public static function is_connected() {
        return (bool) get_option( 'wpim_gdrive_refresh_token' );
    }

    public static function get_account_email() {
        return get_option( 'wpim_gdrive_account_email', '' );
    }

    public static function get_auth_url() {
        $params = [
            'client_id'     => get_option( 'wpim_gdrive_client_id' ),
            'redirect_uri'  => self::get_redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce( 'wpim_gdrive_oauth' ),
        ];
        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * Exchange an OAuth authorization code for access + refresh tokens.
     */
    public static function handle_callback_code( $code ) {
        $response = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => get_option( 'wpim_gdrive_client_id' ),
                'client_secret' => get_option( 'wpim_gdrive_client_secret' ),
                'redirect_uri'  => self::get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if ( is_wp_error( $response ) ) return false;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) return false;

        update_option( 'wpim_gdrive_access_token', $body['access_token'] );
        update_option( 'wpim_gdrive_token_expires', time() + intval( $body['expires_in'] ?? 3600 ) - 60 );
        if ( ! empty( $body['refresh_token'] ) ) {
            update_option( 'wpim_gdrive_refresh_token', $body['refresh_token'] );
        }

        $email = self::fetch_account_email( $body['access_token'] );
        if ( $email ) update_option( 'wpim_gdrive_account_email', $email );

        return true;
    }

    private static function fetch_account_email( $access_token ) {
        $resp = wp_remote_get( self::USERINFO_URL, [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ]);
        if ( is_wp_error( $resp ) ) return '';
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return $data['email'] ?? '';
    }

    public static function disconnect() {
        delete_option( 'wpim_gdrive_access_token' );
        delete_option( 'wpim_gdrive_refresh_token' );
        delete_option( 'wpim_gdrive_token_expires' );
        delete_option( 'wpim_gdrive_account_email' );
        delete_option( 'wpim_gdrive_folder_id' );
        delete_option( 'wpim_gdrive_deleted_folder_id' );
        delete_option( 'wpim_gdrive_converted_folder_id' );
    }

    /**
     * Returns a valid access token, refreshing it first if expired.
     */
    public static function get_access_token() {
        $expires = intval( get_option( 'wpim_gdrive_token_expires', 0 ) );
        $token   = get_option( 'wpim_gdrive_access_token' );
        if ( $token && time() < $expires ) return $token;
        return self::refresh_access_token();
    }

    private static function refresh_access_token() {
        $refresh_token = get_option( 'wpim_gdrive_refresh_token' );
        if ( ! $refresh_token ) return false;

        $response = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id'     => get_option( 'wpim_gdrive_client_id' ),
                'client_secret' => get_option( 'wpim_gdrive_client_secret' ),
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if ( is_wp_error( $response ) ) return false;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) return false;

        update_option( 'wpim_gdrive_access_token', $body['access_token'] );
        update_option( 'wpim_gdrive_token_expires', time() + intval( $body['expires_in'] ?? 3600 ) - 60 );

        return $body['access_token'];
    }

    /** @var array<string,string> "{parent}/{name}" => folder ID, cached for the lifetime of the request */
    private static $folder_cache = [];

    private static function find_or_create_folder( $name, $parent_id = null ) {
        $cache_key = ( $parent_id ?: 'root' ) . '/' . $name;
        if ( isset( self::$folder_cache[ $cache_key ] ) ) return self::$folder_cache[ $cache_key ];

        $token = self::get_access_token();
        if ( ! $token ) return false;

        $q = "mimeType='application/vnd.google-apps.folder' and name='" . str_replace( "'", "\\'", $name ) . "' and trashed=false";
        if ( $parent_id ) $q .= " and '" . str_replace( "'", "\\'", $parent_id ) . "' in parents";

        $url  = self::API_BASE . '/files?' . http_build_query( [ 'q' => $q, 'fields' => 'files(id,name)' ] );
        $resp = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ] );
        if ( ! is_wp_error( $resp ) ) {
            $data = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $data['files'][0]['id'] ) ) {
                return self::$folder_cache[ $cache_key ] = $data['files'][0]['id'];
            }
        }

        $meta = [ 'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder' ];
        if ( $parent_id ) $meta['parents'] = [ $parent_id ];

        $resp = wp_remote_post( self::API_BASE . '/files', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            'body'    => json_encode( $meta ),
        ]);
        if ( is_wp_error( $resp ) ) return false;

        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $data['id'] ) ) return false;

        return self::$folder_cache[ $cache_key ] = $data['id'];
    }

    /**
     * Walk (creating as needed) a nested folder path such as "2026/07" under
     * a parent folder, returning the final folder's ID. Segments are cached
     * per-request so many files sharing the same folder don't re-hit the API.
     */
    public static function get_nested_folder_id( $parent_id, $relative_dir ) {
        $relative_dir = trim( str_replace( '\\', '/', $relative_dir ), '/' );
        if ( $relative_dir === '' || $relative_dir === '.' ) return $parent_id;

        $current = $parent_id;
        foreach ( explode( '/', $relative_dir ) as $segment ) {
            if ( $segment === '' ) continue;
            $current = self::find_or_create_folder( $segment, $current );
            if ( ! $current ) return false;
        }
        return $current;
    }

    /**
     * Get (creating if needed) the Drive folder ID for 'deleted' or 'converted' backups.
     */
    public static function get_subfolder_id( $which ) {
        $option_key = 'wpim_gdrive_' . $which . '_folder_id';
        $cached     = get_option( $option_key );
        if ( $cached ) return $cached;

        $root = get_option( 'wpim_gdrive_folder_id' );
        if ( ! $root ) {
            $root = self::find_or_create_folder( 'WP Image Manager Backups' );
            if ( ! $root ) return false;
            update_option( 'wpim_gdrive_folder_id', $root );
        }

        $sub = self::find_or_create_folder( $which, $root );
        if ( $sub ) update_option( $option_key, $sub );
        return $sub;
    }

    /**
     * Upload a local file to a Drive folder. Returns the Drive file ID or false.
     */
    public static function upload_file( $local_path, $remote_name, $parent_id ) {
        $token = self::get_access_token();
        if ( ! $token || ! file_exists( $local_path ) ) return false;

        $boundary = wp_generate_password( 24, false );
        $mime     = function_exists( 'mime_content_type' ) ? ( mime_content_type( $local_path ) ?: 'application/octet-stream' ) : 'application/octet-stream';
        $metadata = json_encode( [ 'name' => $remote_name, 'parents' => [ $parent_id ] ] );
        $data     = file_get_contents( $local_path );
        if ( $data === false ) return false;

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $data . "\r\n";
        $body .= "--{$boundary}--";

        $resp = wp_remote_post( self::UPLOAD_URL . '?uploadType=multipart', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'multipart/related; boundary=' . $boundary,
            ],
            'body'    => $body,
            'timeout' => 60,
        ]);

        if ( is_wp_error( $resp ) ) return false;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) return false;

        $result = json_decode( wp_remote_retrieve_body( $resp ), true );
        return $result['id'] ?? false;
    }

    /**
     * Download a Drive file to a local path. Returns true/false.
     */
    public static function download_file( $file_id, $dest_path ) {
        $token = self::get_access_token();
        if ( ! $token ) return false;

        $resp = wp_remote_get( self::API_BASE . "/files/{$file_id}?alt=media", [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 60,
        ]);

        if ( is_wp_error( $resp ) ) return false;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) return false;

        wp_mkdir_p( dirname( $dest_path ) );
        return (bool) file_put_contents( $dest_path, wp_remote_retrieve_body( $resp ) );
    }

    public static function delete_file( $file_id ) {
        $token = self::get_access_token();
        if ( ! $token ) return false;

        $resp = wp_remote_request( self::API_BASE . "/files/{$file_id}", [
            'method'  => 'DELETE',
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ]);

        return ! is_wp_error( $resp );
    }
}
