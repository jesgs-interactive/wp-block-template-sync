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

  public const GITHUB_API_URL = 'https://api.github.com';
  public const GITHUB_REPO = 'jesgs-interactive/wp-block-template-sync';
  public const GITHUB_REPO_URL = 'https://api.github.com/repos/jesgs-interactive/wp-block-template-sync';
  public const GITHUB_RELEASES_URL = 'https://api.github.com/repos/jesgs-interactive/wp-block-template-sync/releases/latest';

  // Plugin metadata constants.
  public const PLUGIN_NAME = 'WP Block Template Sync';
  public const PLUGIN_AUTHOR = 'Jess Green';


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
        // Allow the check to run during development when WP_DEBUG=true even if
        // the version placeholder is present.
        if ( ('' === $this->current_version || false !== strpos( $this->current_version, '{{' )) && ! WP_DEBUG ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
            return $transient;
        }

        $remote_version = ltrim( (string) $release['tag_name'], "vV" );

        if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
            // Prefer a release asset that matches the plugin slug (e.g. "slug.zip")
            // so the extracted folder name matches the plugin directory. Fall
            // back to GitHub's zipball URL if no suitable asset exists.
            $slug = dirname( $this->plugin_basename );
            $package = '';
            if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
                foreach ( $release['assets'] as $asset ) {
                    $name = $asset['name'] ?? '';
                    $url = $asset['browser_download_url'] ?? '';
                    if ( '' === $name || '' === $url ) {
                        continue;
                    }

                    // Exact match: "slug.zip"
                    if ( 0 === strcasecmp( $name, $slug . '.zip' ) ) {
                        $package = $url;
                        break;
                    }

                    // Partial match: contains slug and is a zip (e.g. "plugin-v1.2.3.zip").
                    if ( false !== stripos( $name, $slug ) && strtolower( substr( $name, -4 ) ) === '.zip' ) {
                        $package = $url;
                        break;
                    }
                }
            }

            if ( '' === $package ) {
                // No suitable release asset found for the plugin; do not offer an
                // update that uses the repo zipball. The release should include
                // a distributable asset (e.g. "slug.zip"). Hard-fail by
                // returning the transient unchanged.
                return $transient;
            }

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

        $repo = self::GITHUB_REPO;
        if ( '' === $repo ) {
            return $res;
        }

        $release = $this->get_latest_release( $repo );
        if ( ! is_array( $release ) ) {
            return $res;
        }

        $remote_version = ltrim( (string) ( $release['tag_name'] ?? '' ), "vV" );

        $info = new \stdClass();
        $info->name = self::PLUGIN_NAME;
        $info->slug = $slug;
        $info->version = $remote_version ?: $this->current_version;
        $info->author = self::PLUGIN_AUTHOR;
        $info->homepage = $release['html_url'] ?? 'https://github.com';

        // Prefer a release asset zip matching the plugin slug for proper
        // installation folder naming. Fall back to the repo zipball URL.
        $slug = dirname( $this->plugin_basename );
        $download_link = '';
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                $name = $asset['name'] ?? '';
                $url = $asset['browser_download_url'] ?? '';
                if ( '' === $name || '' === $url ) {
                    continue;
                }

                if ( 0 === strcasecmp( $name, $slug . '.zip' ) ) {
                    $download_link = $url;
                    break;
                }

                if ( false !== stripos( $name, $slug ) && strtolower( substr( $name, -4 ) ) === '.zip' ) {
                    $download_link = $url;
                    break;
                }
            }
        }

        if ( '' === $download_link ) {
            // No distributable asset present; do not provide plugin download
            // information. Require a release asset rather than falling back to
            // the repo zipball.
            return $res;
        }

        $info->download_link = $download_link;

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
     * to cache results for 6 hours.
     *
     * @param string|null $repo Owner/repo string (optional). Defaults to class constant.
     * @return array|null
     */
    protected function get_latest_release( ?string $repo = null ): ?array {
        $repo = $repo ?: self::GITHUB_REPO;
        $transient_key = 'wbts_github_release_' . md5( $repo );
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $args = array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WP-Block-Template-Sync-Updater',
            ),
        );

        $resp = wp_remote_get( self::GITHUB_RELEASES_URL, $args );
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


