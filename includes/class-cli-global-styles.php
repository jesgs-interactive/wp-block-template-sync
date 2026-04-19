<?php
/**
 * WP-CLI command: block-template-sync sync-global-styles
 *
 * Manually triggers the global-styles → theme.json / style.css sync.
 * Useful for CI pipelines and local development workflows.
 *
 * @package WpBlockTemplateSync
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Syncs global styles from the database into the active theme's theme.json and style.css.
 */
class WBTS_CLI_Sync_Global_Styles {

	/**
	 * Sync global styles from the database to theme.json and style.css.
	 *
	 * ## OPTIONS
	 *
	 * [--option=<option>]
	 * : WordPress option name that holds the global styles.
	 * ---
	 * default: wp_global_styles
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp block-template-sync sync-global-styles
	 *     wp block-template-sync sync-global-styles --option=wp_global_styles
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$option  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'option', apply_filters( 'wbts_global_styles_option_name', 'wp_global_styles' ) );
		$opt_val = get_option( $option );

		if ( false === $opt_val ) {
			WP_CLI::error( sprintf( 'Option "%s" not found in the database.', $option ) );
			return;
		}

		$sync = new \WpBlockTemplateSync\GlobalStylesSync( $option );
		$res  = $sync->sync_to_theme( $opt_val );

		if ( isset( $res['error'] ) ) {
			WP_CLI::error( $res['error'] );
			return;
		}

		WP_CLI::success( 'Global styles synced.' );

		if ( ! empty( $res['backups'] ) ) {
			WP_CLI::log( 'Backups: ' . implode( ', ', $res['backups'] ) );
		}

		if ( ! empty( $res['theme_json'] ) ) {
			WP_CLI::log( 'Wrote theme.json: ' . $res['theme_json'] );
		}

		if ( ! empty( $res['style_css'] ) ) {
			WP_CLI::log( 'Wrote style.css: ' . $res['style_css'] );
		}
	}
}

WP_CLI::add_command( 'block-template-sync sync-global-styles', 'WBTS_CLI_Sync_Global_Styles' );
