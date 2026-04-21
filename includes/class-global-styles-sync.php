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
	 * Last warnings produced by the normalizer (for diagnostics).
	 *
	 * @var string[]
	 */
	protected array $last_normalize_warnings = array();

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

		// Admin: register settings & admin page for toggling pruning.
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_admin_settings' ) );

		// Admin action: manual sync theme.json -> database
		add_action( 'admin_post_wbts_sync_theme_to_db', array( $this, 'handle_admin_sync' ) );

		// AJAX endpoints and assets for the admin sync UI.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_wbts_sync_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_wbts_sync_commit', array( $this, 'ajax_commit' ) );
	}

	/**
	 * Enqueue admin JS for the sync UI.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets(): void {
		// Only enqueue on our admin page. Use get_current_screen() when
		// available (more reliable than checking $_GET['page']).
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = \get_current_screen();
			if ( ! $screen || 'appearance_page_wbts-template-sync' !== $screen->id ) {
				return;
			}
		} else {
			if ( ! isset( $_GET['page'] ) || 'wbts-template-sync' !== $_GET['page'] ) {
				return;
			}
		}

		// Build the script URL relative to this file (includes/ -> ../admin/js/...)
		$script_url = \plugin_dir_url( __FILE__ ) . '../admin/js/wbts-sync.js';
		\wp_register_script( 'wbts-sync', $script_url, array(), false, true );
		\wp_localize_script( 'wbts-sync', 'wbtsSync', array(
			'ajax_url' => \admin_url( 'admin-ajax.php' ),
			'nonce'    => \wp_create_nonce( 'wbts_sync_theme_to_db' ),
		) );
		\wp_enqueue_script( 'wbts-sync' );
	}


	/**
	 * Prune generated CSS so it only contains preset variables/classes present in
	 * the merged theme.json. Useful when you want the generated stylesheet to
	 * reflect only the presets the theme actually defines (optional; off by
	 * default).
	 *
	 * @param string $css The formatted/generated CSS.
	 * @param array  $theme_json The merged theme.json array.
	 * @return string Pruned CSS.
	 */
	protected function prune_css_to_theme_presets( string $css, array $theme_json ): string {
		$allowed_color_slugs = array();
		$allowed_gradient_slugs = array();
		$allowed_fontsize_slugs = array();
		$allowed_fontfamily_slugs = array();
		$allowed_duotone_slugs = array();

		$settings = $theme_json['settings'] ?? array();

		// Palette
		$palette = $settings['color']['palette'] ?? array();
		if ( is_array( $palette ) ) {
			foreach ( $palette as $item ) {
				if ( isset( $item['slug'] ) ) {
					$allowed_color_slugs[] = $item['slug'];
				}
			}
		}

		// Gradients
		$gradients = $settings['color']['gradients'] ?? array();
		if ( is_array( $gradients ) ) {
			foreach ( $gradients as $g ) {
				if ( isset( $g['slug'] ) ) {
					$allowed_gradient_slugs[] = $g['slug'];
				}
			}
		}

		// Font sizes
		$fontSizes = $settings['typography']['fontSizes'] ?? array();
		if ( is_array( $fontSizes ) ) {
			foreach ( $fontSizes as $fs ) {
				if ( isset( $fs['slug'] ) ) {
					$allowed_fontsize_slugs[] = $fs['slug'];
				}
			}
		}

		// Font families
		$fontFamilies = $settings['typography']['fontFamilies'] ?? array();
		if ( is_array( $fontFamilies ) ) {
			foreach ( $fontFamilies as $ff ) {
				if ( isset( $ff['slug'] ) ) {
					$allowed_fontfamily_slugs[] = $ff['slug'];
				}
			}
		}

		// Duotone
		$duotone = $settings['color']['duotone'] ?? array();
		if ( is_array( $duotone ) ) {
			foreach ( $duotone as $d ) {
				if ( isset( $d['slug'] ) ) {
					$allowed_duotone_slugs[] = $d['slug'];
				}
			}
		}

		// Helper closures
		$is_allowed_color = function( $slug ) use ( $allowed_color_slugs ) {
			return in_array( $slug, $allowed_color_slugs, true );
		};
		$is_allowed_gradient = function( $slug ) use ( $allowed_gradient_slugs ) {
			return in_array( $slug, $allowed_gradient_slugs, true );
		};
		$is_allowed_fontsize = function( $slug ) use ( $allowed_fontsize_slugs ) {
			return in_array( $slug, $allowed_fontsize_slugs, true );
		};
		$is_allowed_fontfamily = function( $slug ) use ( $allowed_fontfamily_slugs ) {
			return in_array( $slug, $allowed_fontfamily_slugs, true );
		};
		$is_allowed_duotone = function( $slug ) use ( $allowed_duotone_slugs ) {
			return in_array( $slug, $allowed_duotone_slugs, true );
		};

		// Remove CSS custom properties for color presets not in the whitelist.
		$css = preg_replace_callback(
			'/--wp--preset--color--([a-z0-9_-]+)\s*:\s*[^;]+;/i',
			function( $m ) use ( $is_allowed_color ) {
				$slug = $m[1];
				return $is_allowed_color( $slug ) ? $m[0] : '';
			},
			$css
		);

		// Gradients
		$css = preg_replace_callback(
			'/--wp--preset--gradient--([a-z0-9_-]+)\s*:\s*[^;]+;/i',
			function( $m ) use ( $is_allowed_gradient ) {
				$slug = $m[1];
				return $is_allowed_gradient( $slug ) ? $m[0] : '';
			},
			$css
		);

		// Font sizes
		$css = preg_replace_callback(
			'/--wp--preset--font-size--([a-z0-9_-]+)\s*:\s*[^;]+;/i',
			function( $m ) use ( $is_allowed_fontsize ) {
				$slug = $m[1];
				return $is_allowed_fontsize( $slug ) ? $m[0] : '';
			},
			$css
		);

		// Font families
		$css = preg_replace_callback(
			'/--wp--preset--font-family--([a-z0-9_-]+)\s*:\s*[^;]+;/i',
			function( $m ) use ( $is_allowed_fontfamily ) {
				$slug = $m[1];
				return $is_allowed_fontfamily( $slug ) ? $m[0] : '';
			},
			$css
		);

		// Duotone
		$css = preg_replace_callback(
			'/--wp--preset--duotone--([a-z0-9_-]+)\s*:\s*[^;]+;/i',
			function( $m ) use ( $is_allowed_duotone ) {
				$slug = $m[1];
				return $is_allowed_duotone( $slug ) ? $m[0] : '';
			},
			$css
		);

		// Remove helper classes for presets that are not allowed. Detect all
		// color-like slugs present in the CSS and remove corresponding helper
		// class rules for disallowed slugs.
		$all_color_slugs = array();
		if ( preg_match_all( '/--wp--preset--color--([a-z0-9_-]+)/i', $css, $m ) ) {
			$all_color_slugs = array_unique( $m[1] );
		}

		$disallowed_colors = array_diff( $all_color_slugs, $allowed_color_slugs );

		foreach ( $disallowed_colors as $slug ) {
			$slug_q = preg_quote( $slug, '/' );
			$patterns = array(
				'/\.has-' . $slug_q . '-color\s*\{[^}]*\}/i',
				'/\.has-' . $slug_q . '-background-color\s*\{[^}]*\}/i',
				'/\.has-' . $slug_q . '-border-color\s*\{[^}]*\}/i',
				'/\.has-' . $slug_q . '-gradient-background\s*\{[^}]*\}/i',
				'/\.has-' . $slug_q . '-font-size\s*\{[^}]*\}/i',
				'/\.has-' . $slug_q . '-font-family\s*\{[^}]*\}/i',
			);

			foreach ( $patterns as $pat ) {
				$css = preg_replace( $pat, '', $css );
			}
		}

		// Remove any lines that contain only whitespace (left behind when rules
		// or declarations were stripped), then collapse long runs of newlines to
		// a maximum of two so the stylesheet remains readable.
		$css = preg_replace( '/^[ \t]+\r?\n/m', "\n", $css );
		$css = preg_replace( '/\r?\n{3,}/', "\n\n", $css );

		// Also remove completely empty lines (lines with no spaces) that may
		// remain — this ensures the file doesn't contain large gaps after
		// pruning.
		$css = preg_replace( '/^\s*\r?\n/m', "", $css );

		return trim( $css ) . "\n";
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
		// Prepare the default result structure early so we can return on decode failure
		// without depending on later initialisation.
		$result = array(
			'backup' => array(),
			'written' => array(),
		);

		$global_styles = $this->safe_json_decode( $content );

		if ( ! is_array( $global_styles ) ) {
			// If we couldn't decode the provided DB content, abort sync to avoid
			// overwriting the theme.json with empty values. Record a warning for diagnostics.
			$this->last_normalize_warnings[] = 'sync_to_theme aborted: failed to decode database global styles content.';
			return $result;
		}

		$theme_dir       = get_stylesheet_directory();
		$theme_json_path = $theme_dir . '/theme.json';
		$style_css_path  = $theme_dir . '/style.css';

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
		$css = $this->get_global_stylesheet();

		if ( ! empty( $css ) ) {
			// Always preserve the theme-header comment that WordPress requires for
			// a theme to be recognised as valid. Extract it from the existing file
			// before we overwrite anything.
			$theme_header = '';

			$existing_style = '';
			if ( file_exists( $style_css_path ) ) {
				$existing_style = file_get_contents( $style_css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$theme_header    = $this->extract_style_header( $existing_style );
			}

			// Format the minified CSS output so the file stays human-readable.
			$formatted_css = $this->format_css( $css );

			// Combine: header (with trailing newline) + blank separator + formatted CSS.
			$style_content = rtrim( $theme_header ) . "\n\n" . $formatted_css;

			// Optional pruning: remove generated preset variables/classes not present
			// in the merged theme.json. Off by default; can be enabled via filter.
			$prune_default = (bool) get_option( 'wbts_prune_generated_css', false );
			if ( apply_filters( 'wbts_prune_generated_css', $prune_default, $theme_json ) ) {
				$style_content = $this->prune_css_to_theme_presets( $style_content, $theme_json );
			}

			// Allow consumers to disable writing the stylesheet entirely.
			if ( ! apply_filters( 'wbts_write_style_css', true, $style_content, $theme_json ) ) {
				// Skip writing CSS but still return the result for theme.json.
				$noop = true; // explicit no-op to satisfy linters
			} else {
				// If content hasn't changed, skip backup and write to avoid touching timestamps.
				if ( $existing_style === $style_content ) {
					// No change — do nothing.
					$noop = true; // explicit no-op to satisfy linters
				} else {
					if ( file_exists( $style_css_path ) ) {
						$backup_path = $backup_dir . '/style-' . $timestamp . '.css';

						if ( copy( $style_css_path, $backup_path ) ) {
							$result['backup'][] = $backup_path;
						}
					}

					if ( $this->write_file( $style_css_path, $style_content ) ) {
						$result['written'][] = $style_css_path;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Extract the opening block comment from a style.css file.
	 *
	 * WordPress requires the theme-header comment (Theme Name, Version, etc.)
	 * at the top of style.css to recognise the theme as valid. This method
	 * returns everything up to and including the closing `*\/` so it can be
	 * prepended to any regenerated stylesheet content.
	 *
	 * Returns an empty string when no opening comment is found.
	 *
	 * @param string $css Raw content of the existing style.css.
	 * @return string The header comment block, or an empty string.
	 */
	protected function extract_style_header( string $css ): string {
		$start = strpos( $css, '/*' );

		if ( false === $start ) {
			return '';
		}

		$end = strpos( $css, '*/', $start );

		if ( false === $end ) {
			return '';
		}

		return substr( $css, $start, $end - $start + 2 ) . "\n";
	}

	/**
	 * Format minified CSS into readable, indented multi-line output.
	 *
	 * wp_get_global_stylesheet() returns minified CSS. This method inserts
	 * newlines and indentation so the written file is easy to diff and read.
	 *
	 * @param string $css Minified CSS string.
	 * @return string Formatted CSS string.
	 */
	protected function format_css( string $css ): string {
		$output     = '';
		$indent     = 0;
		$tab        = "\t";
		$length     = strlen( $css );
		$in_string  = false;
		$string_char = '';
		$in_comment = false;
		$i          = 0;

		while ( $i < $length ) {
			$char = $css[ $i ];
			$next = $i + 1 < $length ? $css[ $i + 1 ] : '';

			// Track block comments so we don't mangle their content.
			if ( ! $in_string && ! $in_comment && '/' === $char && '*' === $next ) {
				$in_comment = true;
				$output    .= $char;
				$i++;
				continue;
			}

			if ( $in_comment ) {
				$output .= $char;

				if ( '*' === $char && '/' === $next ) {
					$output    .= '/';
					$i         += 2;
					$in_comment = false;
					$output    .= "\n" . str_repeat( $tab, $indent );
					continue;
				}

				$i++;
				continue;
			}

			// Track quoted strings so braces inside them are not treated as rules.
			if ( ! $in_string && ( '"' === $char || "'" === $char ) ) {
				$in_string   = true;
				$string_char = $char;
				$output     .= $char;
				$i++;
				continue;
			}

			if ( $in_string ) {
				$output .= $char;

				if ( $char === $string_char && ( $i === 0 || '\\' !== $css[ $i - 1 ] ) ) {
					$in_string = false;
				}

				$i++;
				continue;
			}

			if ( '{' === $char ) {
				$output .= " {\n";
				$indent++;
				$output .= str_repeat( $tab, $indent );
			} elseif ( '}' === $char ) {
				$indent  = max( 0, $indent - 1 );
				// Remove trailing whitespace/tab before the closing brace.
				$output  = rtrim( $output ) . "\n";
				$output .= str_repeat( $tab, $indent ) . "}\n";

				if ( $indent > 0 ) {
					$output .= str_repeat( $tab, $indent );
				} else {
					$output .= "\n";
				}
			} elseif ( ';' === $char ) {
				$output .= ";\n" . str_repeat( $tab, $indent );
			} elseif ( ',' === $char && preg_match( '/[}\s]/', $next ) ) {
				// Selector list — break after comma when followed by whitespace or `}`.
				$output .= ",\n" . str_repeat( $tab, $indent );
			} elseif ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char ) {
				// Collapse runs of whitespace to a single space (except after `;` / `{`
				// where we have already emitted a newline).
				if ( ! in_array( substr( $output, -1 ), array( "\n", "\t", ' ', '{' ), true ) ) {
					$output .= ' ';
				}
			} else {
				$output .= $char;
			}

			$i++;
		}

		return trim( $output ) . "\n";
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
		// WordPress may store these presets keyed by origin (theme/default/custom/blocks)
		// in the database, whereas theme.json expects flat sequential arrays. Normalize
		// by extracting the "theme" (or custom/first) origin so shapes match before merging.
		$grouped_fields = array(
			'color'      => array( 'palette', 'gradients', 'duotone' ),
			'typography' => array( 'fontSizes', 'fontFamilies' ),
			// Shadow presets were introduced in core (settings.shadow.presets).
			// The DB may store them keyed by origin; treat them like other presets.
			// Only normalize the editable `presets` array. `defaultPresets` is a
			// boolean flag (enable/disable) per core and should not be coerced
			// into an array or otherwise modified by the normalizer.
			'shadow'     => array( 'presets' ),
			// Additional preset-like fields that may be keyed by origin in the DB.
			'spacing'    => array( 'spacingSizes', 'spacingScale' ),
			'border'     => array( 'radiusSizes' ),
			'dimensions' => array( 'aspectRatios' ),
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
					$v = $value['theme'];
				} elseif ( isset( $value['custom'] ) && is_array( $value['custom'] ) ) {
					$v = $value['custom'];
				} else {
					$v = reset( $value );
				}

				// If the extracted value is an associative map keyed by slug, convert
				// it to a sequential array of its values so theme.json receives the
				// expected array shape (presets are arrays of objects).
				if ( is_array( $v ) && ! $this->is_sequential_array( $v ) ) {
					// If every child is an array, keep their values.
					$all_children_arrays = true;
					foreach ( $v as $child ) {
						if ( ! is_array( $child ) ) {
							$all_children_arrays = false;
							break;
						}
					}

					if ( $all_children_arrays ) {
						$v = array_values( $v );
					} else {
						// Special-case: shadow presets may be stored as slug => shadow-string.
						if ( 'shadow' === $section && 'presets' === $field ) {
							$converted = array();
							foreach ( $v as $slug => $child ) {
								if ( is_array( $child ) ) {
									$item = $child;
									if ( ! isset( $item['slug'] ) ) {
										$item['slug'] = $slug;
									}
								} else {
									$item = array( 'slug' => $slug, 'shadow' => (string) $child );
								}
								$converted[] = $item;
							}
							$v = $converted;
						} else {
							// Not an array-of-arrays; coerce to empty to avoid schema errors.
							$v = array();
						}
					}
				}

				$global_styles['settings'][ $section ][ $field ] = is_array( $v ) ? $v : array();
			}
		}

		// Post-normalization verification: ensure each listed field is a sequential
		// array (theme.json expects arrays for presets). If something remains
		// associative, attempt to extract an origin again; otherwise coerce to an
		// empty array and record a warning so callers can surface diagnostics.
		$warnings = array();
		foreach ( $grouped_fields as $section => $fields ) {
			foreach ( $fields as $field ) {
				if ( ! isset( $global_styles['settings'][ $section ][ $field ] ) ) {
					continue;
				}

				$val = $global_styles['settings'][ $section ][ $field ];

				if ( is_array( $val ) && ! $this->is_sequential_array( $val ) ) {
					// Try to extract origins again.
					$extracted = null;
					if ( isset( $val['theme'] ) && is_array( $val['theme'] ) ) {
						$extracted = $val['theme'];
					} elseif ( isset( $val['custom'] ) && is_array( $val['custom'] ) ) {
						$extracted = $val['custom'];
					} else {
						$extracted = reset( $val );
					}

					if ( is_array( $extracted ) ) {
						if ( $this->is_sequential_array( $extracted ) ) {
							$global_styles['settings'][ $section ][ $field ] = $extracted;
							continue;
						}

						// If associative but contains array children, convert to sequential.
						$all_children_arrays = true;
						foreach ( $extracted as $child ) {
							if ( ! is_array( $child ) ) {
								$all_children_arrays = false;
								break;
							}
						}

						if ( $all_children_arrays ) {
							$global_styles['settings'][ $section ][ $field ] = array_values( $extracted );
							continue;
						}

						// Special-case: shadow presets may be stored as slug => shadow-string.
						if ( 'shadow' === $section && 'presets' === $field ) {
							$converted = array();
							foreach ( $extracted as $slug => $child ) {
								if ( is_array( $child ) ) {
									$item = $child;
									if ( ! isset( $item['slug'] ) ) {
										$item['slug'] = $slug;
									}
								} else {
									$item = array( 'slug' => $slug, 'shadow' => (string) $child );
								}
								$converted[] = $item;
							}
							$global_styles['settings'][ $section ][ $field ] = $converted;
							continue;
						}
					}

					// Couldn't coerce to a sequential array — clear it to avoid schema errors.
					$global_styles['settings'][ $section ][ $field ] = array();
					$warnings[] = sprintf( "Normalized settings.%s.%s: coerced non-sequential value to empty array.", $section, $field );
				}
			}
		}

		$this->last_normalize_warnings = $warnings;

		return $global_styles;
	}

	/**
	 * Register the admin submenu page under Appearance for plugin settings.
	 *
	 * @return void
	 */
	public function add_admin_page(): void {
		add_submenu_page(
			'themes.php',
			'WP Block Template Sync',
			'Template Sync',
			'manage_options',
			'wbts-template-sync',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register admin settings and fields.
	 *
	 * @return void
	 */
	public function register_admin_settings(): void {
		register_setting( 'wbts_template_sync', 'wbts_prune_generated_css', array(
			'type' => 'boolean',
			'sanitize_callback' => function ( $v ) {
				return (bool) $v;
			},
		) );

		add_settings_section( 'wbts_main', 'WP Block Template Sync', function () {
			echo '<p>Settings for WP Block Template Sync.</p>';
		}, 'wbts_template_sync' );

		add_settings_field( 'wbts_prune_generated_css', 'Prune generated CSS', array( $this, 'render_prune_field' ), 'wbts_template_sync', 'wbts_main' );
	}

	/**
	 * Render the prune checkbox field.
	 *
	 * @return void
	 */
	public function render_prune_field(): void {
		$val = (bool) get_option( 'wbts_prune_generated_css', false );
		echo '<label><input type="checkbox" name="wbts_prune_generated_css" value="1"' . ( $val ? ' checked' : '' ) . '> Prune generated CSS to theme presets only</label>';
	}

	/**
	 * Render the admin settings page.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			echo '<p>Insufficient permissions.</p>';
			return;
		}

		// Show result notice if present.
		if ( isset( $_GET['wbts_sync_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'success' === $_GET['wbts_sync_result'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>Theme files synced to the database successfully.</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>Failed to sync theme files to the database. Check server logs for details.</p></div>';
			}
		}

		echo '<div class="wrap"><h1>WP Block Template Sync</h1>';
		// Settings form
		echo '<form method="post" action="options.php">';
		settings_fields( 'wbts_template_sync' );
		do_settings_sections( 'wbts_template_sync' );
		submit_button();
		echo '</form>';

		// Manual sync UI: preview via AJAX then commit via AJAX (fallback to form available).
		echo '<h2>Manual sync</h2>';
		echo '<p>If you edit <code>theme.json</code> directly and want to copy its <code>settings</code> and <code>styles</code> into the database (Site Editor global styles), preview the changes and then commit.</p>';
		echo '<p><button id="wbts-sync-preview" class="button">Preview changes</button> ';
		echo '<button id="wbts-sync-commit" class="button button-primary" style="display:none">Sync theme.json → Database</button></p>';
		echo '<div id="wbts-sync-diff" style="margin-top:1rem"></div>';
		// Non-JS fallback: simple form that posts to admin-post.php
		echo '<noscript><form method="post" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
		\wp_nonce_field( 'wbts_sync_theme_to_db', 'wbts_sync_nonce' );
		echo '<input type="hidden" name="action" value="wbts_sync_theme_to_db">';
		echo '<p><button type="submit" class="button button-primary">Sync theme.json → Database</button></p>';
		echo '</form></noscript>';

		echo '</div>';
	}

	/**
	 * Handle the admin POST that syncs theme.json into the wp_global_styles post
	 * (and legacy option) for the current theme.
	 *
	 * This action is hooked to `admin_post_wbts_sync_theme_to_db` and expects a
	 * valid nonce and the current user to have the `edit_theme_options` cap.
	 *
	 * @return void
	 */
	public function handle_admin_sync(): void {
		if ( ! \current_user_can( 'edit_theme_options' ) ) {
			\wp_die( 'Insufficient permissions' );
		}

		if ( ! isset( $_POST['wbts_sync_nonce'] ) || ! \wp_verify_nonce( \wp_unslash( $_POST['wbts_sync_nonce'] ), 'wbts_sync_theme_to_db' ) ) {
			\wp_die( 'Invalid request' );
		}

		$stylesheet = \get_stylesheet();
		$theme_dir  = \get_stylesheet_directory();
		$theme_json_path = $theme_dir . '/theme.json';

		$success = false;

		if ( file_exists( $theme_json_path ) ) {
			$raw = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				$post_content = \wp_json_encode(
					array(
						'version'  => $decoded['version'] ?? 2,
						'settings' => $decoded['settings'] ?? array(),
						'styles'   => $decoded['styles'] ?? array(),
					),
					JSON_UNESCAPED_SLASHES
				);

				// Try to find an existing wp_global_styles post for this theme.
				$post = $this->find_global_styles_post_for_current_theme();

				if ( $post instanceof \WP_Post ) {
					\wp_update_post( array(
						'ID' => $post->ID,
						'post_content' => $post_content,
					) );
					$success = true;
				} else {
					// Insert as a new wp_global_styles post.
					$new = \wp_insert_post( array(
						'post_type' => 'wp_global_styles',
						'post_status' => 'publish',
						'post_name' => 'wp-global-styles-' . $stylesheet,
						'post_title' => 'Global Styles — ' . $stylesheet,
						'post_content' => $post_content,
					) );

					if ( $new ) {
						$success = true;
					}
				}

				// Also update legacy option for older WP versions.
				if ( function_exists( '\update_option' ) ) {
					\update_option( $this->option_name, $post_content );
				}
			}
		}

		$redirect = \admin_url( 'themes.php?page=wbts-template-sync' );
		$redirect = \add_query_arg( 'wbts_sync_result', $success ? 'success' : 'fail', $redirect );
		\wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * AJAX: return a diff preview between theme.json and the DB/global styles.
	 *
	 * Expects: nonce (wbts_sync_theme_to_db)
	 * Returns JSON { success: bool, diff: string, theme: string, db: string }
	 */
	public function ajax_preview(): void {
		if ( ! \current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( 'insufficient_permissions', 403 );
		}

		$nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
		if ( ! \wp_verify_nonce( $nonce, 'wbts_sync_theme_to_db' ) ) {
			wp_send_json_error( 'invalid_nonce', 400 );
		}

		$stylesheet = \get_stylesheet();
		$theme_dir  = \get_stylesheet_directory();
		$theme_json_path = $theme_dir . '/theme.json';

		$theme_pretty = '';
		$db_pretty = '';
		$diff_html = '';

		if ( file_exists( $theme_json_path ) ) {
			$raw = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$theme_pretty = \wp_json_encode( array(
					'version' => $decoded['version'] ?? 2,
					'settings' => $decoded['settings'] ?? array(),
					'styles' => $decoded['styles'] ?? array(),
				), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			}
		}

		$normalization_warnings = array();
		$post = $this->find_global_styles_post_for_current_theme();
		if ( $post instanceof \WP_Post ) {
			$db_arr = $this->safe_json_decode( $post->post_content );
			if ( is_array( $db_arr ) ) {
				// Normalize DB-shaped global styles so origin-keyed presets become arrays.
				$db_arr = $this->normalize_global_styles_for_theme_json( $db_arr );
				$normalization_warnings = $this->last_normalize_warnings;
				$db_pretty = \wp_json_encode( array(
					'version'  => $db_arr['version'] ?? 2,
					'settings' => $db_arr['settings'] ?? array(),
					'styles'   => $db_arr['styles'] ?? array(),
				), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			} else {
				// If decoding failed, emit a warning so the UI can surface the issue.
				$normalization_warnings[] = 'Failed to decode wp_global_styles post_content; it may contain invalid JSON.';
			}
		} else {
			// legacy option
			if ( function_exists( '\\get_option' ) ) {
				$opt = \get_option( $this->option_name, '' );
				if ( is_string( $opt ) ) {
					$opt_arr = json_decode( $opt, true );
					if ( is_array( $opt_arr ) ) {
						$opt_arr = $this->normalize_global_styles_for_theme_json( $opt_arr );
						$normalization_warnings = $this->last_normalize_warnings;
						$db_pretty = \wp_json_encode( array(
							'version'  => $opt_arr['version'] ?? 2,
							'settings' => $opt_arr['settings'] ?? array(),
							'styles'   => $opt_arr['styles'] ?? array(),
						), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
					}
				}
			}
		}

		$has_changes = false;
		$diff_html = '';

		if ( $theme_pretty === $db_pretty ) {
			$has_changes = false;
			$diff_html = '<pre>No differences</pre>';
		} else {
			$has_changes = true;
			$diff_html = $this->generate_diff_html( $db_pretty, $theme_pretty );
		}

		$response = array(
			'diff' => $diff_html,
			'theme' => $theme_pretty,
			'db' => $db_pretty,
			'has_changes' => $has_changes,
		);

		if ( ! empty( $normalization_warnings ) ) {
			$response['normalization_warnings'] = $normalization_warnings;
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: commit theme.json into the DB (same behaviour as handle_admin_sync),
	 * but return JSON instead of redirecting.
	 */
	public function ajax_commit(): void {
		if ( ! \current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( 'insufficient_permissions', 403 );
		}

		$nonce = $_POST['nonce'] ?? '';
		if ( ! \wp_verify_nonce( $nonce, 'wbts_sync_theme_to_db' ) ) {
			wp_send_json_error( 'invalid_nonce', 400 );
		}

		$stylesheet = \get_stylesheet();
		$theme_dir  = \get_stylesheet_directory();
		$theme_json_path = $theme_dir . '/theme.json';

		$success = false;

		if ( file_exists( $theme_json_path ) ) {
			$raw = file_get_contents( $theme_json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) ) {
				$post_content = \wp_json_encode(
					array(
						'version'  => $decoded['version'] ?? 2,
						'settings' => $decoded['settings'] ?? array(),
						'styles'   => $decoded['styles'] ?? array(),
					),
					JSON_UNESCAPED_SLASHES
				);

				$post = $this->find_global_styles_post_for_current_theme();

				if ( $post instanceof \WP_Post ) {
					\wp_update_post( array(
						'ID' => $post->ID,
						'post_content' => $post_content,
					) );
					$success = true;
				} else {
					$new = \wp_insert_post( array(
						'post_type' => 'wp_global_styles',
						'post_status' => 'publish',
						'post_name' => 'wp-global-styles-' . $stylesheet,
						'post_title' => 'Global Styles — ' . $stylesheet,
						'post_content' => $post_content,
					) );

					if ( $new ) {
						$success = true;
					}
				}

				if ( function_exists( '\update_option' ) ) {
					\update_option( $this->option_name, $post_content );
				}
			}
		}

		if ( $success ) {
			wp_send_json_success( 'synced' );
		} else {
			wp_send_json_error( 'failed', 500 );
		}
	}

	/**
	 * Generate a simple HTML diff between two pretty-printed JSON strings.
	 * Uses an LCS algorithm to mark removed and added lines.
	 *
	 * @param string $old
	 * @param string $new
	 * @return string HTML fragment with <del> for removals and <ins> for additions.
	 */
	protected function generate_diff_html( string $old, string $new ): string {
		$old_lines = $old === '' ? array() : preg_split("/\r?\n/", $old);
		$new_lines = $new === '' ? array() : preg_split("/\r?\n/", $new);

		$rows = array();
		$m = count( $old_lines );
		$n = count( $new_lines );

		// Build LCS table
		$dp = array_fill( 0, $m + 1, array_fill( 0, $n + 1, 0 ) );
		for ( $i = $m - 1; $i >= 0; $i-- ) {
			for ( $j = $n - 1; $j >= 0; $j-- ) {
				if ( $old_lines[ $i ] === $new_lines[ $j ] ) {
					$dp[ $i ][ $j ] = $dp[ $i + 1 ][ $j + 1 ] + 1;
				} else {
					$dp[ $i ][ $j ] = max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
				}
			}
		}

		// Backtrack
		$i = 0; $j = 0;
		$html = '<pre class="wbts-diff" style="white-space:pre-wrap;">';
		while ( $i < $m && $j < $n ) {
			if ( $old_lines[ $i ] === $new_lines[ $j ] ) {
				$html .= htmlspecialchars( $old_lines[ $i ] ) . "\n";
				$i++; $j++;
			} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				// old line removed
				$html .= '<del style="background:#fdd;display:block;">' . htmlspecialchars( $old_lines[ $i ] ) . '</del>' . "\n";
				$i++;
			} else {
				// new line added
				$html .= '<ins style="background:#dfd;display:block;">' . htmlspecialchars( $new_lines[ $j ] ) . '</ins>' . "\n";
				$j++;
			}
		}

		while ( $i < $m ) {
			$html .= '<del style="background:#fdd;display:block;">' . htmlspecialchars( $old_lines[ $i ] ) . '</del>' . "\n";
			$i++;
		}

		while ( $j < $n ) {
			$html .= '<ins style="background:#dfd;display:block;">' . htmlspecialchars( $new_lines[ $j ] ) . '</ins>' . "\n";
			$j++;
		}

		$html .= '</pre>';
		return $html;
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

	/**
	 * Try to decode JSON robustly from a string that may contain HTML or stray
	 * characters (for example if a DB post_content was accidentally polluted
	 * with diff HTML). Attempts several strategies: direct decode, balanced
	 * braces extraction, and stripped-tags extraction.
	 *
	 * @param string $raw Raw content to decode.
	 * @return array|null Decoded array on success, or null on failure.
	 */
	protected function safe_json_decode( string $raw ): ?array {
		if ( '' === $raw ) {
			return null;
		}

		// Direct attempt first.
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		// Try to extract a balanced JSON object from the string (first { .. matching }).
		$start = strpos( $raw, '{' );
		if ( false !== $start ) {
			$depth = 0;
			$len = strlen( $raw );
			for ( $i = $start; $i < $len; $i++ ) {
				$ch = $raw[ $i ];
				if ( '{' === $ch ) {
					$depth++;
				} elseif ( '}' === $ch ) {
					$depth--;
					if ( 0 === $depth ) {
						$substr = substr( $raw, $start, $i - $start + 1 );
						$decoded = json_decode( $substr, true );
						if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
							$this->last_normalize_warnings[] = 'Recovered JSON by extracting balanced braces from content.';
							return $decoded;
						}
						break;
					}
				}
			}
		}

		// As a last resort, strip HTML tags and try again.
		$stripped = strip_tags( $raw );
		$decoded = json_decode( $stripped, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			$this->last_normalize_warnings[] = 'Recovered JSON after stripping HTML tags from content.';
			return $decoded;
		}

		// Nothing worked.
		return null;
	}
}
