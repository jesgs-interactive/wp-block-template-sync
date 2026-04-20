<?php
/**
 * Global Styles Sync class.
 *
 * Listens for wp_global_styles post saves (Site Editor) and option updates,
 * then writes theme.json and style.css into the active theme directory.
 *
 * @package WpBlockTemplateSync
 */

namespace WpBlockTemplateSync;

/**
 * Handles syncing of global styles from the database to theme files.
 */
class GlobalStylesSync {

	/**
	 * The option name used as a fallback hook for global styles updates.
	 *
	 * @var string
	 */
	protected string $option_name;

	/**
	 * Register WordPress action hooks.
	 */
	public function __construct() {
		$this->option_name = (string) apply_filters( 'wbts_global_styles_option_name', 'wp_global_styles' );

		// Primary: fire when the Site Editor saves a wp_global_styles post.
		add_action( 'save_post_wp_global_styles', array( $this, 'on_save_global_styles_post' ), 10, 3 );

		// Fallback: fire when the legacy option is updated.
		add_action( 'update_option_' . $this->option_name, array( $this, 'on_update_option' ), 10, 3 );

		// Also sync when the active theme changes.
		add_action( 'after_switch_theme', array( $this, 'on_after_switch_theme' ) );
	}

	/**
	 * Handle a wp_global_styles post save triggered by the Site Editor.
	 *
	 * Ignores revisions and autosaves. Only syncs when the post_name matches
	 * `wp-global-styles-{active-theme-stylesheet}`.
	 *
	 * @param int      $post_id The saved post ID.
	 * @param \WP_Post $post    The saved post object.
	 * @param bool     $update  True when updating an existing post.
	 * @return void
	 */
	public function on_save_global_styles_post( int $post_id, \WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! apply_filters( 'wbts_auto_sync_global_styles_enabled', true ) ) {
			return;
		}

		$expected_post_name = 'wp-global-styles-' . get_stylesheet();

		if ( $post->post_name !== $expected_post_name ) {
			return;
		}

		$this->sync_to_theme( $post->post_content );
	}

	/**
	 * Handle a legacy option update (fallback for older WP installations).
	 *
	 * @param mixed  $old_value  Previous option value.
	 * @param mixed  $new_value  New option value.
	 * @param string $option     Option name.
	 * @return void
	 */
	public function on_update_option( $old_value, $new_value, string $option ): void {
		if ( ! apply_filters( 'wbts_auto_sync_global_styles_enabled', true ) ) {
			return;
		}

		if ( is_string( $new_value ) ) {
			$content = $new_value;
		} else {
			$encoded = wp_json_encode( $new_value );

			if ( false === $encoded ) {
				return;
			}

			$content = $encoded;
		}

		$this->sync_to_theme( $content );
	}

	/**
	 * Handle theme switches by syncing the global styles for the newly activated theme.
	 *
	 * @return void
	 */
	public function on_after_switch_theme(): void {
		if ( ! apply_filters( 'wbts_auto_sync_global_styles_enabled', true ) ) {
			return;
		}

		$post = $this->find_global_styles_post_for_current_theme();

		if ( ! $post ) {
			return;
		}

		$this->sync_to_theme( $post->post_content );
	}

	/**
	 * Look up the wp_global_styles post for the currently active theme.
	 *
	 * First tries get_page_by_path(), then falls back to a get_posts() query
	 * across all relevant post statuses.
	 *
	 * @return \WP_Post|null The post object, or null if not found.
	 */
	public function find_global_styles_post_for_current_theme(): ?\WP_Post {
		$stylesheet = get_stylesheet();
		$post_name  = 'wp-global-styles-' . $stylesheet;

		$post = get_page_by_path( $post_name, OBJECT, 'wp_global_styles' );

		if ( $post instanceof \WP_Post ) {
			return $post;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wp_global_styles',
				'name'           => $post_name,
				'post_status'    => array( 'publish', 'draft', 'auto-draft', 'private' ),
				'numberposts'    => 1,
				'no_found_rows'  => true,
			)
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Write the global styles from the given JSON content into theme.json and style.css.
	 *
	 * Backs up existing theme.json and style.css into `.global-styles-backups/`
	 * before overwriting. Deep-merges the `settings` and `styles` keys from the
	 * database into the existing theme.json so that all other keys (templateParts,
	 * customTemplates, patterns, title, etc.) are preserved. Writes style.css via
	 * wp_get_global_stylesheet() when available.
	 *
	 * @param string $content JSON-encoded global styles (post_content or option value).
	 * @return array{backup: string[], written: string[]} Paths that were backed up and written.
	 */
	public function sync_to_theme( string $content ): array {
		$global_styles = json_decode( $content, true );

		if ( ! is_array( $global_styles ) ) {
			$global_styles = array();
		}

		$theme_dir       = get_stylesheet_directory();
		$theme_json_path = $theme_dir . '/theme.json';
//		$style_css_path  = $theme_dir . '/style.css';

		$result = array(
			'backup'  => array(),
			'written' => array(),
		);

		// Read existing theme.json so we can preserve keys we are not syncing.
		$existing_theme_json = array();

		if ( file_exists( $theme_json_path ) ) {
			$raw = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! empty( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$existing_theme_json = $decoded;
				}
			}
		}

		// Normalise the DB content before merging: WordPress stores palette,
		// gradients, duotone, fontSizes and fontFamilies grouped by origin
		// ({"theme":[…],"default":[…],"custom":[…]}), while theme.json expects
		// flat arrays. Extract the "theme" origin so the shapes match.
		$global_styles = $this->normalize_global_styles_for_theme_json( $global_styles );

		// Deep-merge: database `settings` and `styles` win; everything else is kept.
		// Start from the existing array so that every key retains its original
		// position (important for clean diffs). Only `settings` and `styles` are
		// updated in-place; all other top-level keys are left untouched.
		$theme_json = $existing_theme_json;

		if ( ! isset( $theme_json['version'] ) ) {
			$theme_json['version'] = 2;
		}

		$theme_json['settings'] = $this->deep_merge(
			$existing_theme_json['settings'] ?? array(),
			$global_styles['settings'] ?? array()
		);

		$theme_json['styles'] = $this->deep_merge(
			$existing_theme_json['styles'] ?? array(),
			$global_styles['styles'] ?? array()
		);

		// Ensure the backup directory exists.
		$backup_dir = $theme_dir . '/.global-styles-backups';

		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
		}

		$timestamp = gmdate( 'Y-m-d-His' );

		// Encode the merged array directly to preserve the original key order.
		// WP_Theme_JSON::get_data() re-serialises according to WordPress's internal
		// schema order, which would break diff comparisons against the original file.
		$theme_json_content = wp_json_encode( $theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $theme_json_content || '' === $theme_json_content ) {
			return $result;
		}

		// Backup existing theme.json before overwriting.
		if ( file_exists( $theme_json_path ) ) {
			$backup_path = $backup_dir . '/theme-' . $timestamp . '.json';

			if ( copy( $theme_json_path, $backup_path ) ) {
				$result['backup'][] = $backup_path;
			}
		}

		if ( $this->write_file( $theme_json_path, $theme_json_content ) ) {
			$result['written'][] = $theme_json_path;
		}

		// Backup and overwrite style.css only when CSS content is available.
//		$css = $this->get_global_stylesheet();
//
//		if ( ! empty( $css ) ) {
//			if ( file_exists( $style_css_path ) ) {
//				$backup_path = $backup_dir . '/style-' . $timestamp . '.css';
//
//				if ( copy( $style_css_path, $backup_path ) ) {
//					$result['backup'][] = $backup_path;
//				}
//			}
//
//			if ( $this->write_file( $style_css_path, $css ) ) {
//				$result['written'][] = $style_css_path;
//			}
//		}

		return $result;
	}

	/**
	 * Return the computed global stylesheet, or an empty string when the
	 * wp_get_global_stylesheet() function is not available.
	 *
	 * Extracted into its own method so that tests can override it via a
	 * partial mock without needing to stub PHP built-ins.
	 *
	 * @return string CSS output, or an empty string.
	 */
	protected function get_global_stylesheet(): string {
		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			return (string) wp_get_global_stylesheet();
		}

		return '';
	}

	/**
	 * Recursively merge two arrays, with values in $override taking precedence.
	 *
	 * Unlike array_merge_recursive(), scalar values in $override replace those
	 * in $base instead of being collected into an array. Keys that already exist
	 * in $base are updated in-place so their position in the array is retained,
	 * which keeps diffs against the original theme.json clean. New keys from
	 * $override are appended at the end.
	 *
	 * Sequential (indexed) arrays are always replaced wholesale — merging them
	 * by index makes no semantic sense for things like palette entries.
	 *
	 * @param array $base     The base array (e.g. existing theme.json section).
	 * @param array $override The override array (e.g. values from the database).
	 * @return array The merged result.
	 */
	protected function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if (
				is_array( $value ) &&
				isset( $base[ $key ] ) &&
				is_array( $base[ $key ] ) &&
				! $this->is_sequential_array( $value ) &&
				! $this->is_sequential_array( $base[ $key ] )
			) {
				// Both sides are associative: recurse so existing keys keep their position.
				$base[ $key ] = $this->deep_merge( $base[ $key ], $value );
			} else {
				// Scalar, or at least one side is a sequential array: replace entirely.
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Return true when $arr is a sequential (0-indexed) array.
	 *
	 * Used by deep_merge() to decide whether to recurse or replace.
	 *
	 * @param array $arr The array to test.
	 * @return bool
	 */
	protected function is_sequential_array( array $arr ): bool {
		if ( empty( $arr ) ) {
			return true;
		}

		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Normalise the decoded global-styles array from the database before merging
	 * it into theme.json.
	 *
	 * WordPress groups palette, gradients, duotone, fontSizes and fontFamilies by
	 * origin inside the wp_global_styles post_content:
	 *
	 *   { "theme": [...], "default": [...], "custom": [...] }
	 *
	 * theme.json expects flat arrays for these fields. When the DB value is in the
	 * grouped shape this method extracts the "theme" origin so the shapes match.
	 *
	 * @param array $global_styles Decoded global styles from the database.
	 * @return array Normalised global styles ready to merge into theme.json.
	 */
	protected function normalize_global_styles_for_theme_json( array $global_styles ): array {
		// Fields inside settings that WordPress may group by origin.
		$grouped_fields = array(
			'color'      => array( 'palette', 'gradients', 'duotone' ),
			'typography' => array( 'fontSizes', 'fontFamilies' ),
		);

		foreach ( $grouped_fields as $section => $fields ) {
			foreach ( $fields as $field ) {
				$value = $global_styles['settings'][ $section ][ $field ] ?? null;

				if ( ! is_array( $value ) ) {
					continue;
				}

				// If it's already a sequential array it is already in theme.json shape.
				if ( $this->is_sequential_array( $value ) ) {
					continue;
				}

				// It is an associative (origin-grouped) array. Extract "theme" origin
				// if present; otherwise try "custom", then fall back to the first value.
				if ( isset( $value['theme'] ) && is_array( $value['theme'] ) ) {
					$global_styles['settings'][ $section ][ $field ] = $value['theme'];
				} elseif ( isset( $value['custom'] ) && is_array( $value['custom'] ) ) {
					$global_styles['settings'][ $section ][ $field ] = $value['custom'];
				} else {
					$first = reset( $value );
					$global_styles['settings'][ $section ][ $field ] = is_array( $first ) ? $first : array();
				}
			}
		}

		return $global_styles;
	}

	/**
	 * Write content to a file using the WordPress Filesystem API.
	 *
	 * @param string $file_path Absolute path to the destination file.
	 * @param string $content   Content to write.
	 * @return bool True on success, false on failure.
	 */
	protected function write_file( string $file_path, string $content ): bool {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return (bool) $wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE );
	}
}
