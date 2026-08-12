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
    }

    private function get_release() {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->github_repo}/releases/latest",
            [ 'headers' => [ 'Accept' => 'application/vnd.github+json' ] ]
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $release->tag_name ) ) return false;

        set_transient( $this->cache_key, $release, 6 * HOUR_IN_SECONDS );
        return $release;
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

    public function clear_cache( $upgrader, $data ) {
        if ( ( $data['action'] ?? '' ) === 'update' && ( $data['type'] ?? '' ) === 'plugin' ) {
            delete_transient( $this->cache_key );
        }
    }
}