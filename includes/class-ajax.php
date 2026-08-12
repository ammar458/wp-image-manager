<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPIM_Ajax {
    public function __construct() {
        $actions = [
            'wpim_scan',
            'wpim_deep_scan',
            'wpim_get_page',
            'wpim_delete_batch',
            'wpim_bulk_convert',
            'wpim_get_converted',
            'wpim_get_deleted',
            'wpim_restore_deleted',
            'wpim_restore_converted',
            'wpim_toggle_auto_webp',
            'wpim_conversion_stats',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, str_replace('wpim_', 'handle_', $action) ] );
        }
    }

    private function verify() {
        if ( ! check_ajax_referer( 'wpim_nonce', 'nonce', false ) ) wp_send_json_error( 'Invalid nonce.', 403 );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized.', 403 );
    }

    private function set_limits() {
        // Give long-running scans more time/memory
        if ( ! ini_get('safe_mode') ) {
            @set_time_limit( 120 );
            @ini_set( 'memory_limit', '256M' );
        }
    }

    public function handle_scan() {
        $this->verify();
        $this->set_limits();
        $scanner = new WPIM_Scanner();
        try {
            $summary = $scanner->refresh();
            wp_send_json_success( $summary );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Scan error: ' . $e->getMessage() );
        }
    }

    public function handle_deep_scan() {
        $this->verify();
        $this->set_limits();
        // Deep scan can touch a lot of rows — allow more time.
        if ( ! ini_get('safe_mode') ) {
            @set_time_limit( 240 );
        }
        try {
            $scanner = new WPIM_Scanner();
            $scanner->maybe_build_attached_temp_table(); // ensure base table exists first

            $deep = new WPIM_Deep_Scanner();
            $report = $deep->run();

            // Recompute totals now that deep-scan matches are in the temp table.
            $summary = $scanner->get_summary();
            $summary['deep'] = $report;

            wp_send_json_success( $summary );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Deep scan error: ' . $e->getMessage() );
        }
    }

    public function handle_get_page() {
        $this->verify();
        $this->set_limits();
        $page    = intval( $_POST['page'] ?? 1 );
        $scanner = new WPIM_Scanner();
        try {
            // Build temp table if not already built this request
            $scanner->maybe_build_attached_temp_table();
            $data = $scanner->get_unattached_page( $page, 100 );
            wp_send_json_success( $data );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Page load error: ' . $e->getMessage() );
        }
    }

    public function handle_delete_batch() {
        $this->verify();
        $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
        if ( empty($ids) ) wp_send_json_error( 'No IDs provided.' );
        $deleter = new WPIM_Deleter();
        $result  = $deleter->delete_batch( $ids );
        wp_send_json_success( $result );
    }

    public function handle_bulk_convert() {
        $this->verify();
        $this->set_limits();
        $offset    = intval( $_POST['offset'] ?? 0 );
        $converter = new WPIM_Converter();
        $result    = $converter->bulk_convert( 100, $offset );
        wp_send_json_success( $result );
    }

    public function handle_get_converted() {
        $this->verify();
        $page     = intval( $_POST['page'] ?? 1 );
        $restorer = new WPIM_Restorer();
        $data     = $restorer->get_converted_backups( $page, 100 );
        wp_send_json_success( $data );
    }

    public function handle_get_deleted() {
        $this->verify();
        $page     = intval( $_POST['page'] ?? 1 );
        $restorer = new WPIM_Restorer();
        $data     = $restorer->get_deleted_backups( $page, 100 );
        wp_send_json_success( $data );
    }

    public function handle_restore_deleted() {
        $this->verify();
        $id       = intval( $_POST['attachment_id'] ?? 0 );
        $restorer = new WPIM_Restorer();
        $result   = $restorer->restore_deleted( $id );
        if ( $result['success'] ) wp_send_json_success( $result );
        else wp_send_json_error( $result['message'] );
    }

    public function handle_restore_converted() {
        $this->verify();
        $id       = intval( $_POST['attachment_id'] ?? 0 );
        $restorer = new WPIM_Restorer();
        $result   = $restorer->restore_converted( $id );
        if ( $result['success'] ) wp_send_json_success( $result );
        else wp_send_json_error( $result['message'] );
    }

    public function handle_toggle_auto_webp() {
        $this->verify();
        $val = intval( $_POST['enabled'] ?? 0 );
        update_option( 'wpim_auto_webp', $val ? 1 : 0 );
        wp_send_json_success( [ 'enabled' => $val ] );
    }

    public function handle_conversion_stats() {
        $this->verify();
        $converter = new WPIM_Converter();
        wp_send_json_success( $converter->get_conversion_stats() );
    }
}

new WPIM_Ajax();
