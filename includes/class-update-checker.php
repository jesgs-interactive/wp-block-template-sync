<?php
/**
 * Minimal GitHub Releases update checker.
 *
 * Queries the repository's latest release and integrates with WordPress plugin
 * update hooks to advertise an update when the GitHub release tag is newer than
 * the installed plugin version.
 *
 * This is a small, dependency-free implementation intended for public repos.
 *
 * @package WpBlockTemplateSync
 */

namespace WpBlockTemplateSync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UpdateChecker {
    /** @var string Plugin basename (e.g. folder/plugin-file.php) */
    protected string $plugin_basename;

    /** @var string Current installed version */
    protected string $current_version;

    // No persistent property for transient; key is computed per-repo.

    public function __construct( string $plugin_basename, string $current_version ) {
        $this->plugin_basename = $plugin_basename;
        $this->current_version = $current_version;
    }

    public function init(): void {
        add_filter( 'site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
    }

    /**
     * Hook: site_transient_update_plugins
     *
     * @param object $transient
     * @return object
     */
    public function check_update( $transient ) {
        // Repo is hard-coded in the plugin constant.
        $repo = defined( 'WP_BLOCK_TEMPLATE_SYNC_GITHUB_REPO' ) ? (string) WP_BLOCK_TEMPLATE_SYNC_GITHUB_REPO : '';

        if ( '' === $repo ) {
            return $transient;
        }

        // Avoid running when no version is set / placeholder present.
        if ( '' === $this->current_version || false !== strpos( $this->current_version, '{{' ) ) {
            return $transient;
        }

        $release = $this->get_latest_release( $repo );

        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            return $transient;
        }

        $remote_version = ltrim( (string) $release['tag_name'], "vV" );

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            $package = $release['zipball_url'] ?? '';

            $update = new \stdClass();
            $update->slug = dirname( $this->plugin_basename );
            $update->new_version = $remote_version;
            $update->url = $release['html_url'] ?? 'https://github.com';
            $update->package = $package;

            if ( ! is_object( $transient ) ) {
                return $transient;
            }

            $transient->response[ $this->plugin_basename ] = $update;
        }

        return $transient;
    }

    /**
     * Provide plugin information to the details modal (Plugins > Details).
     *
     * @param false|object|array $res
     * @param string $action
     * @param object $args
     * @return object|false
     */
    public function plugins_api( $res, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $res;
        }

        $slug = dirname( $this->plugin_basename );

        if ( empty( $args->slug ) || $args->slug !== $slug ) {
            return $res;
        }

        $repo = defined( 'WP_BLOCK_TEMPLATE_SYNC_GITHUB_REPO' ) ? (string) WP_BLOCK_TEMPLATE_SYNC_GITHUB_REPO : '';
        if ( '' === $repo ) {
            return $res;
        }

        $release = $this->get_latest_release( $repo );
        if ( ! is_array( $release ) ) {
            return $res;
        }

        $remote_version = ltrim( (string) ( $release['tag_name'] ?? '' ), "vV" );

        $info = new \stdClass();
        $info->name = 'WP Block Template Sync';
        $info->slug = $slug;
        $info->version = $remote_version ?: $this->current_version;
        $info->author = 'Jess Green';
        $info->homepage = $release['html_url'] ?? 'https://github.com';
        $info->download_link = $release['zipball_url'] ?? '';

        $sections = array();
        $sections['description'] = 'Syncs Site Editor templates and global styles into theme files.';
        if ( ! empty( $release['body'] ) ) {
            $sections['changelog'] = nl2br( $this->escape_html( $release['body'] ) );
        }

        $info->sections = $sections;

        return $info;
    }

    /**
     * Fetch the latest GitHub release for a repo (owner/repo). Uses a transient
     * to cache results for 5 minutes.
     *
     * @param string $repo
     * @return array|null
     */
    protected function get_latest_release( string $repo ): ?array {
        $transient_key = 'wbts_github_release_' . md5( $repo );
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        // Validate repo format "owner/repo".
        if ( false === strpos( $repo, '/' ) ) {
            return null;
        }

        $url = 'https://api.github.com/repos/' . rawurlencode( $repo ) . '/releases/latest';

        $args = array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WP-Block-Template-Sync-Updater',
            ),
        );

        $resp = wp_remote_get( $url, $args );
        if ( is_wp_error( $resp ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );

        if ( 200 !== (int) $code || '' === $body ) {
            return null;
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        // Cache for 6 hours to avoid rate limits.
        set_transient( $transient_key, $decoded, MINUTE_IN_SECONDS * 360 );

        return $decoded;
    }

    /**
     * Escape a string for HTML output. Prefer WP's esc_html when available,
     * otherwise fall back to PHP's htmlspecialchars.
     *
     * @param string $text
     * @return string
     */
    protected function escape_html( string $text ): string {
        if ( function_exists( 'esc_html' ) ) {
            return esc_html( $text );
        }

        return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}


