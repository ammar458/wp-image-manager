<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Checks GitHub Releases for newer versions and hooks the result into
 * WordPress's native plugin-update UI (Dashboard > Updates, "update now" link).
 */
class WPIM_Updater {

    private $file;
    private $basename;
    private $slug;
    private $version;
    private $github_repo;
    private $cache_key;

    public function __construct( $file, $github_repo, $version ) {
        $this->file        = $file;
        $this->basename    = plugin_basename( $file );
        $this->slug        = dirname( $this->basename );
        $this->version     = $version;
        $this->github_repo = $github_repo;
        $this->cache_key   = 'wpim_github_release_' . md5( $github_repo );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );
        // WP's own "Check Again" button on Dashboard > Updates only clears WP's
        // transient, not ours — without this, a forced recheck would still just
        // hand back our stale 6-hour-old cached release info.
        add_action( 'delete_site_transient_update_plugins', [ $this, 'clear_cache' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_error_notice' ] );

        add_filter( "plugin_action_links_{$this->basename}", [ $this, 'add_check_update_link' ] );
        add_action( 'admin_init', [ $this, 'maybe_handle_check_update' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_check_result_notice' ] );
    }

    /**
     * Adds a "Check for updates" link next to Deactivate/Activate on the
     * Plugins list table, mirroring the row-action pattern WP core plugins use.
     */
    public function add_check_update_link( $links ) {
        $url = wp_nonce_url(
            add_query_arg( [
                'wpim_check_update' => 1,
                'plugin'            => $this->basename,
            ], admin_url( 'plugins.php' ) ),
            'wpim_check_update_' . $this->basename
        );

        $links['wpim_check_update'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'wp-image-manager' ) . '</a>';

        return $links;
    }

    /**
     * Runs on admin_init (rather than a dedicated admin_action_ hook) so the
     * redirect happens before the Plugins list table renders, without needing
     * a separate no-UI admin page to host the handler.
     */
    public function maybe_handle_check_update() {
        if ( empty( $_GET['wpim_check_update'] ) || ( $_GET['plugin'] ?? '' ) !== $this->basename ) return;
        if ( ! current_user_can( 'update_plugins' ) ) return;
        check_admin_referer( 'wpim_check_update_' . $this->basename );

        delete_transient( $this->cache_key );
        $release = $this->get_release();

        if ( $release ) {
            $remote_version = ltrim( $release->tag_name, 'v' );
            $status = version_compare( $remote_version, $this->version, '>' ) ? 'available' : 'latest';
        } else {
            $status = 'error';
        }
        set_transient( 'wpim_check_update_result', $status, MINUTE_IN_SECONDS );

        // Also clear WP's own update transient so "Update Now" appears right
        // away instead of waiting for WP's normal 12-hour recheck cycle.
        delete_site_transient( 'update_plugins' );

        wp_safe_redirect( remove_query_arg( [ 'wpim_check_update', 'plugin', '_wpnonce' ] ) );
        exit;
    }

    public function maybe_show_check_result_notice() {
        if ( ! current_user_can( 'update_plugins' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'plugins' ) return;

        $status = get_transient( 'wpim_check_update_result' );
        if ( ! $status ) return;
        delete_transient( 'wpim_check_update_result' );

        if ( $status === 'available' ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>WP Image Manager Pro:</strong> a new version is available below.</p></div>';
        } elseif ( $status === 'latest' ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>WP Image Manager Pro:</strong> you already have the latest version (' . esc_html( $this->version ) . ').</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p><strong>WP Image Manager Pro:</strong> the update check failed. See the notice below for details.</p></div>';
        }
    }

    private function get_release() {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->github_repo}/releases/latest",
            [ 'headers' => [ 'Accept' => 'application/vnd.github+json' ] ]
        );

        if ( is_wp_error( $response ) ) {
            $this->record_error( 'Could not reach GitHub: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $this->record_error( "GitHub returned HTTP {$code} while checking for updates." );
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $release->tag_name ) ) {
            $this->record_error( 'GitHub release response was missing version info.' );
            return false;
        }

        delete_option( 'wpim_updater_last_error' );
        set_transient( $this->cache_key, $release, 6 * HOUR_IN_SECONDS );
        return $release;
    }

    private function record_error( $message ) {
        update_option( 'wpim_updater_last_error', [
            'message' => $message,
            'time'    => current_time( 'mysql' ),
        ] );
    }

    /**
     * Surface silent update-check failures (network/DNS/firewall issues
     * reaching GitHub) instead of just showing no "Update Now" link with no
     * explanation.
     */
    public function maybe_show_error_notice() {
        if ( ! current_user_can( 'update_plugins' ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'update-core' ], true ) ) return;

        $error = get_option( 'wpim_updater_last_error' );
        if ( ! $error ) return;

        // Only surface it while it's still relevant — a stale error from days
        // ago (since resolved and simply never re-checked) shouldn't nag forever.
        if ( strtotime( $error['time'] ) < time() - DAY_IN_SECONDS ) return;

        printf(
            '<div class="notice notice-warning"><p><strong>WP Image Manager Pro:</strong> couldn\'t check for updates (last attempt %1$s) — %2$s This is why no update may be showing; it usually means the server can\'t reach GitHub. You can always <a href="%3$s" target="_blank" rel="noopener noreferrer">download the latest release directly</a> and install it via Plugins &rsaquo; Add New &rsaquo; Upload Plugin.</p></div>',
            esc_html( $error['time'] ),
            esc_html( $error['message'] ),
            esc_url( "https://github.com/{$this->github_repo}/releases/latest" )
        );
    }

    private function get_download_url( $release ) {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( $asset->name === $this->slug . '.zip' ) {
                    return $asset->browser_download_url;
                }
            }
        }
        return $release->zipball_url;
    }

    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_release();
        if ( ! $release ) return $transient;

        $remote_version = ltrim( $release->tag_name, 'v' );

        if ( version_compare( $remote_version, $this->version, '>' ) ) {
            $transient->response[ $this->basename ] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->basename,
                'new_version' => $remote_version,
                'url'         => "https://github.com/{$this->github_repo}",
                'package'     => $this->get_download_url( $release ),
                'tested'      => get_bloginfo( 'version' ),
            ];
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $release = $this->get_release();
        if ( ! $release ) return $result;

        return (object) [
            'name'          => 'WP Image Manager Pro',
            'slug'          => $this->slug,
            'version'       => ltrim( $release->tag_name, 'v' ),
            'author'        => 'Ringomedia',
            'homepage'      => "https://github.com/{$this->github_repo}",
            'sections'      => [ 'changelog' => wpautop( wp_kses_post( $release->body ?? '' ) ) ],
            'download_link' => $this->get_download_url( $release ),
        ];
    }

    /**
     * GitHub zipballs extract to "{repo}-{sha}"; rename to the plugin slug so
     * WordPress overwrites the existing plugin directory instead of creating a new one.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $args = [] ) {
        global $wp_filesystem;

        if ( empty( $args['plugin'] ) || $args['plugin'] !== $this->basename ) return $source;

        $expected = trailingslashit( $remote_source ) . $this->slug;

        if ( trailingslashit( $source ) === trailingslashit( $expected ) ) return $source;
        if ( ! $wp_filesystem->is_dir( $source ) ) return $source;

        if ( $wp_filesystem->move( $source, $expected ) ) {
            return trailingslashit( $expected );
        }

        return $source;
    }

    /**
     * Hooked both to 'upgrader_process_complete' (2 args: $upgrader, $data
     * array) and 'delete_site_transient_update_plugins' (1 arg: the transient
     * name string). $data staying null distinguishes the latter — it always
     * means "force a fresh check", so the cache is cleared unconditionally.
     */
    public function clear_cache( $upgrader = null, $data = null ) {
        if ( $data === null ) {
            delete_transient( $this->cache_key );
            return;
        }
        if ( ( $data['action'] ?? '' ) === 'update' && ( $data['type'] ?? '' ) === 'plugin' ) {
            delete_transient( $this->cache_key );
        }
    }
}