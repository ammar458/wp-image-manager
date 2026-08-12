<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles the backup-destination setting and the Google Drive OAuth
 * connect/callback/disconnect flow (admin-post.php actions).
 */
class WPIM_Settings {

    public function __construct() {
        add_action( 'admin_post_wpim_save_backup_settings', [ $this, 'save_backup_settings' ] );
        add_action( 'admin_post_wpim_gdrive_connect', [ $this, 'connect' ] );
        add_action( 'admin_post_wpim_gdrive_oauth_callback', [ $this, 'oauth_callback' ] );
        add_action( 'admin_post_wpim_gdrive_disconnect', [ $this, 'disconnect' ] );
    }

    private function check_cap() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
    }

    private function redirect_to_settings( $msg ) {
        wp_safe_redirect( add_query_arg(
            [ 'page' => 'wp-image-manager', 'tab' => 'settings', 'wpim_msg' => $msg ],
            admin_url( 'upload.php' )
        ) );
        exit;
    }

    public function save_backup_settings() {
        $this->check_cap();
        check_admin_referer( 'wpim_backup_settings' );

        $destination = ( isset( $_POST['backup_destination'] ) && $_POST['backup_destination'] === 'gdrive' ) ? 'gdrive' : 'local';
        update_option( 'wpim_backup_destination', $destination );

        if ( isset( $_POST['gdrive_client_id'] ) ) {
            update_option( 'wpim_gdrive_client_id', sanitize_text_field( wp_unslash( $_POST['gdrive_client_id'] ) ) );
        }
        if ( isset( $_POST['gdrive_client_secret'] ) && $_POST['gdrive_client_secret'] !== '' ) {
            update_option( 'wpim_gdrive_client_secret', sanitize_text_field( wp_unslash( $_POST['gdrive_client_secret'] ) ) );
        }

        $this->redirect_to_settings( 'settings_saved' );
    }

    public function connect() {
        $this->check_cap();
        check_admin_referer( 'wpim_gdrive_connect' );

        if ( ! WPIM_Google_Drive::is_configured() ) {
            $this->redirect_to_settings( 'gdrive_not_configured' );
        }

        wp_redirect( WPIM_Google_Drive::get_auth_url() );
        exit;
    }

    public function oauth_callback() {
        $this->check_cap();

        if ( empty( $_GET['state'] ) || ! wp_verify_nonce( sanitize_text_field( $_GET['state'] ), 'wpim_gdrive_oauth' ) ) {
            wp_die( 'Invalid or expired authorization request. Please try connecting again.' );
        }

        $msg = 'gdrive_error';
        if ( ! empty( $_GET['code'] ) ) {
            $ok = WPIM_Google_Drive::handle_callback_code( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
            if ( $ok ) {
                update_option( 'wpim_backup_destination', 'gdrive' );
                $msg = 'gdrive_connected';
            }
        }

        $this->redirect_to_settings( $msg );
    }

    public function disconnect() {
        $this->check_cap();
        check_admin_referer( 'wpim_gdrive_disconnect' );

        WPIM_Google_Drive::disconnect();
        update_option( 'wpim_backup_destination', 'local' );

        $this->redirect_to_settings( 'gdrive_disconnected' );
    }
}

new WPIM_Settings();
