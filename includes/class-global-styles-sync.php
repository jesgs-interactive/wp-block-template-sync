<?php
/**
 * Global Styles Sync class.
 *
 * Listens for updates to the global-styles option in the database and writes
 * the resulting theme.json and style.css files into the active theme directory.
 * Intended for local theme development only.
 *
 * @package WpBlockTemplateSync
 */

namespace WpBlockTemplateSync;

/**
 * Handles automatic syncing of global styles from the database to theme files.
 */
class GlobalStylesSync {

	/**
	 * WordPress option name that stores the global styles.
	 *
	 * @var string
	 */
	protected string $option_name;

	/**
	 * Register hooks.
	 *
	 * @param string|null $option_name Option name to watch. Defaults to the filtered value of
	 *                                 `wbts_global_styles_option_name` (normally `wp_global_styles`).
	 */
	public function __construct( ?string $option_name = null ) {
		$this->option_name = $option_name ?? (string) apply_filters( 'wbts_global_styles_option_name', 'wp_global_styles' );

		add_action( 'update_option_' . $this->option_name, array( $this, 'on_update_option' ), 10, 3 );
		add_action( 'after_switch_theme', array( $this, 'on_after_switch_theme' ) );
	}

	/**
	 * Callback for the `update_option_{$option_name}` action.
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $value     New option value.
	 * @param string $option    Option name.
	 * @return void
	 */
	public function on_update_option( $old_value, $value, string $option ): void {
		if ( ! apply_filters( 'wbts_auto_sync_global_styles_enabled', true ) ) {
			return;
		}

		$this->sync_to_theme( $value );
	}

	/**
	 * Callback for the `after_switch_theme` action.
	 *
	 * @return void
	 */
	public function on_after_switch_theme(): void {
		if ( ! apply_filters( 'wbts_auto_sync_global_styles_enabled', true ) ) {
			return;
		}

		$opt = get_option( $this->option_name );
		if ( $opt ) {
			$this->sync_to_theme( $opt );
		}
	}

	/**
	 * Write global styles to theme.json and style.css in the active theme directory.
	 *
	 * @param mixed $global_styles Raw global-styles value from the database (JSON string or array).
	 * @param array $args          Optional overrides (reserved for future use).
	 * @return array Result array with keys `theme_json`, `style_css`, `backups`, and any error keys.
	 */
	public function sync_to_theme( $global_styles, array $args = [] ): array {
		$theme_dir = get_stylesheet_directory();

		if ( ! $theme_dir || ! is_dir( $theme_dir ) || ! is_writable( $theme_dir ) ) {
			return array( 'error' => 'theme_dir_not_writable' );
		}

		$backup_dir = $theme_dir . '/.global-styles-backups';
		if ( ! is_dir( $backup_dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @mkdir( $backup_dir, 0755, true ) && ! is_dir( $backup_dir ) ) {
				// Backup directory could not be created; continue without backups.
				$backup_dir = '';
			}
		}

		// Normalise the incoming value to an array.
		$data = $this->parse_global_styles( $global_styles );

		// Build a minimal theme.json payload.
		$theme_json = array( 'version' => 2 );

		if ( isset( $data['settings'] ) ) {
			$theme_json['settings'] = $data['settings'];
		} elseif ( isset( $data['globalStyles']['settings'] ) ) {
			$theme_json['settings'] = $data['globalStyles']['settings'];
		}

		if ( isset( $data['styles'] ) ) {
			$theme_json['styles'] = $data['styles'];
		} elseif ( isset( $data['globalStyles']['styles'] ) ) {
			$theme_json['styles'] = $data['globalStyles']['styles'];
		}

		// Backup existing theme files before overwriting.
		$timestamp = gmdate( 'YmdHis' );
		$results   = array( 'backups' => array() );
		$paths     = array(
			'theme_json' => $theme_dir . '/theme.json',
			'style_css'  => $theme_dir . '/style.css',
		);

		foreach ( $paths as $key => $path ) {
			if ( '' !== $backup_dir && file_exists( $path ) && is_dir( $backup_dir ) ) {
				$backup_path = $backup_dir . '/' . basename( $path ) . '.' . $timestamp . '.bak';
				copy( $path, $backup_path );
				$results['backups'][ $key ] = $backup_path;
			}
		}

		// Write theme.json atomically (tmp file then rename).
		$theme_json_tmp = $theme_dir . '/.theme.json.tmp';
		$encoded        = wp_json_encode( $theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false !== file_put_contents( $theme_json_tmp, $encoded ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			rename( $theme_json_tmp, $paths['theme_json'] );
			$results['theme_json'] = $paths['theme_json'];
		} else {
			$results['error_theme_json'] = true;
		}

		// Generate computed CSS.
		$css = $this->get_global_stylesheet( $theme_json );

		// Write style.css atomically.
		$style_css_tmp = $theme_dir . '/.style.css.tmp';

		if ( false !== file_put_contents( $style_css_tmp, $css ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			rename( $style_css_tmp, $paths['style_css'] );
			$results['style_css'] = $paths['style_css'];
		} else {
			$results['error_style_css'] = true;
		}

		return $results;
	}

	/**
	 * Normalize a raw database value into a plain PHP array.
	 *
	 * The option may be stored as a JSON string, a serialized string, or already
	 * decoded to an array.
	 *
	 * Note: `unserialize()` is only used as a last-resort fallback for options
	 * that were stored by older WordPress versions. The value originates from the
	 * WordPress database (written by WordPress core) and is not treated as
	 * arbitrary untrusted user input. Always ensure your database is secured and
	 * never pass externally-supplied strings to this method.
	 *
	 * @param mixed $raw Raw database value.
	 * @return array Decoded data, or an empty array on failure.
	 */
	protected function parse_global_styles( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( is_object( $raw ) ) {
			return (array) $raw;
		}

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( null !== $decoded && is_array( $decoded ) ) {
				return $decoded;
			}

			// Fallback: attempt to unserialise (older WP versions stored options serialised).
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$maybe = @unserialize( $raw );
			if ( false !== $maybe && is_array( $maybe ) ) {
				return $maybe;
			}
		}

		return array();
	}

	/**
	 * Retrieve the computed global stylesheet string.
	 *
	 * Tries the WP 5.9+ `wp_get_global_stylesheet()` function first, then falls
	 * back to the `WP_Theme_JSON_Resolver` class method, and finally outputs the
	 * theme.json payload as a CSS comment so the file is never left empty.
	 *
	 * @param array $theme_json The built theme.json payload (used for the fallback comment only).
	 * @return string CSS string.
	 */
	protected function get_global_stylesheet( array $theme_json ): string {
		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			return wp_get_global_stylesheet();
		}

		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$resolver = \WP_Theme_JSON_Resolver::get_instance();
			if ( method_exists( $resolver, 'get_global_stylesheet' ) ) {
				return $resolver->get_global_stylesheet();
			}
		}

		// No renderer available — embed the JSON as a comment so the file is populated.
		return "/* Generated by wp-block-template-sync: no CSS renderer available. Dumping JSON below. */\n\n"
			. '/* ' . wp_json_encode( $theme_json ) . ' */';
	}
}
