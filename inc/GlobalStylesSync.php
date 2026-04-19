<?php
/**
 * Global Styles Sync class.
 *
 * Listens for updates to the global-styles DB option and writes the values
 * into the active theme's theme.json and style.css for local theme development.
 *
 * @package Wbts\GlobalStylesSync
 */

namespace Wbts\GlobalStylesSync;

/**
 * Handles syncing of DB global-styles into the active theme's theme.json and style.css.
 */
class GlobalStylesSync {

	/**
	 * The DB option name used to store global styles.
	 *
	 * @var string
	 */
	protected $option_name;

	/**
	 * Constructor. Registers WordPress action hooks.
	 *
	 * @param string|null $option_name Optional. Override the option name. Defaults to the filtered value.
	 */
	public function __construct( $option_name = null ) {
		$this->option_name = $option_name ?: apply_filters( 'wbts_global_styles_option_name', 'wp_global_styles' );

		// Hook when the option is updated.
		add_action( 'update_option_' . $this->option_name, array( $this, 'on_update_option' ), 10, 3 );

		// Hook on theme switch.
		add_action( 'after_switch_theme', array( $this, 'on_after_switch_theme' ) );
	}

	/**
	 * Triggered when the global-styles option is updated in the DB.
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $value     New option value.
	 * @param string $option    Option name.
	 * @return void
	 */
	public function on_update_option( $old_value, $value, $option ) {
		if ( ! apply_filters( 'wbts_auto_sync_global_styles_enabled', true ) ) {
			return;
		}

		$this->sync_to_theme( $value );
	}

	/**
	 * Triggered after a theme is switched.
	 *
	 * @return void
	 */
	public function on_after_switch_theme() {
		if ( ! apply_filters( 'wbts_auto_sync_global_styles_enabled', true ) ) {
			return;
		}

		$opt = get_option( $this->option_name );
		if ( $opt ) {
			$this->sync_to_theme( $opt );
		}
	}

	/**
	 * Sync global styles JSON (from DB) into the active theme's theme.json and style.css.
	 *
	 * Overwrites theme.json entirely using a simple mapping: version=2 + settings/styles
	 * from the DB payload. Creates timestamped backups before overwriting.
	 *
	 * @param mixed $global_styles Raw value from the DB option (string, array, or object).
	 * @param array $args          Optional extra arguments (reserved for future use).
	 * @return array Results array containing keys: 'backups', 'theme_json', 'style_css',
	 *               and on failure 'error' / 'error_theme_json' / 'error_style_css'.
	 */
	public function sync_to_theme( $global_styles, $args = array() ) {
		$theme_dir = get_stylesheet_directory();
		if ( ! $theme_dir || ! is_dir( $theme_dir ) ) {
			return array(
				'error'   => 'theme_not_found',
				'message' => 'Active theme directory not found or not accessible.',
			);
		}

		$results    = array();
		$backup_dir = $theme_dir . '/.global-styles-backups';
		if ( ! is_dir( $backup_dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @mkdir( $backup_dir, 0755, true ) && ! is_dir( $backup_dir ) ) {
				$results['backup_dir_error'] = 'failed_create_backup_dir';
			}
		}

		// Normalize incoming value: could be JSON string, serialized, or array/object.
		$data = $this->parse_global_styles( $global_styles );

		if ( null === $data ) {
			return array(
				'error'   => 'invalid_global_styles',
				'message' => 'Could not parse global styles payload.',
			);
		}

		// Build theme.json structure (overwrite behavior, version 2).
		$theme_json = array( 'version' => 2 );
		if ( isset( $data['settings'] ) ) {
			$theme_json['settings'] = $data['settings'];
		}
		if ( isset( $data['styles'] ) ) {
			$theme_json['styles'] = $data['styles'];
		}
		// Fallback keys that some WP versions use.
		if ( empty( $theme_json['settings'] ) && isset( $data['globalStyles']['settings'] ) ) {
			$theme_json['settings'] = $data['globalStyles']['settings'];
		}
		if ( empty( $theme_json['styles'] ) && isset( $data['globalStyles']['styles'] ) ) {
			$theme_json['styles'] = $data['globalStyles']['styles'];
		}

		$timestamp = gmdate( 'YmdHis' );

		// Paths.
		$theme_json_path = $theme_dir . '/theme.json';
		$style_css_path  = $theme_dir . '/style.css';

		// Backup existing files.
		foreach ( array( $theme_json_path, $style_css_path ) as $path ) {
			if ( file_exists( $path ) ) {
				$bak = $backup_dir . '/' . basename( $path ) . '.' . $timestamp . '.bak';
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( @copy( $path, $bak ) ) {
					$results['backups'][] = $bak;
				} else {
					$results['backup_errors'][] = $path;
				}
			}
		}

		// Write theme.json atomically.
		$encoded  = wp_json_encode( $theme_json );
		$tmp_theme = $theme_dir . '/.theme.json.tmp';
		if ( false === file_put_contents( $tmp_theme, $encoded ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$results['error_theme_json'] = 'failed_write_tmp';
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @rename( $tmp_theme, $theme_json_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@copy( $tmp_theme, $theme_json_path );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $tmp_theme );
			}
			$results['theme_json'] = $theme_json_path;
		}

		// Generate computed CSS.
		$css = $this->get_global_stylesheet( $encoded );

		// Write style.css atomically.
		$tmp_style = $theme_dir . '/.style.css.tmp';
		if ( false === file_put_contents( $tmp_style, $css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$results['error_style_css'] = 'failed_write_tmp';
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @rename( $tmp_style, $style_css_path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@copy( $tmp_style, $style_css_path );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $tmp_style );
			}
			$results['style_css'] = $style_css_path;
		}

		return $results;
	}

	/**
	 * Parse the raw global styles value from the DB into a PHP array.
	 *
	 * @param mixed $global_styles Raw value (JSON string, serialized string, array, or object).
	 * @return array|null Parsed array, or null on failure.
	 */
	protected function parse_global_styles( $global_styles ) {
		if ( is_string( $global_styles ) ) {
			$decoded = json_decode( $global_styles, true );
			if ( null !== $decoded ) {
				return $decoded;
			}
			// Try unserialize as fallback.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$maybe = @unserialize( $global_styles );
			if ( false !== $maybe && null !== $maybe ) {
				return (array) $maybe;
			}
			return null;
		}

		if ( is_array( $global_styles ) || is_object( $global_styles ) ) {
			return (array) $global_styles;
		}

		return null;
	}

	/**
	 * Get the global stylesheet CSS.
	 *
	 * Uses wp_get_global_stylesheet() when available, then falls back to
	 * WP_Theme_JSON_Resolver, and finally includes a JSON comment as a last resort.
	 *
	 * @param string $encoded_theme_json JSON-encoded theme.json content (used for fallback comment).
	 * @return string CSS string.
	 */
	protected function get_global_stylesheet( $encoded_theme_json ) {
		$css = '';

		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$css = wp_get_global_stylesheet();
		}

		if ( empty( $css ) && class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'get_instance' ) ) {
			try {
				$resolver = \WP_Theme_JSON_Resolver::get_instance();
				if ( method_exists( $resolver, 'get_global_stylesheet' ) ) {
					$css = $resolver->get_global_stylesheet();
				}
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Resolver unavailable; fall through to the comment fallback.
			}
		}

		if ( empty( $css ) ) {
			// Fallback: include theme.json JSON as a comment to preserve content.
			$css = "/* Generated by wp-block-template-sync: no renderer available. Dumping theme.json below. */\n\n/* " . $encoded_theme_json . " */\n";
		}

		return $css;
	}
}
