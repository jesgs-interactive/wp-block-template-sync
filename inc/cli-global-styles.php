<?php
/**
 * WP-CLI command for manually syncing global styles.
 *
 * @package Wbts\GlobalStylesSync
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Syncs global styles from the database into the active theme's theme.json and style.css.
	 */
	class WBTS_CLI_Sync_Global_Styles {

		/**
		 * Sync global styles from the DB into the active theme.
		 *
		 * ## EXAMPLES
		 *
		 *     wp block-template-sync sync-global-styles
		 *
		 * @param array $args       Positional arguments (unused).
		 * @param array $assoc_args Associative arguments (unused).
		 * @return void
		 */
		public function __invoke( $args, $assoc_args ) {
			$option  = apply_filters( 'wbts_global_styles_option_name', 'wp_global_styles' );
			$opt_val = get_option( $option );

			if ( false === $opt_val || empty( $opt_val ) ) {
				WP_CLI::error( sprintf( 'Option "%s" not found or empty.', $option ) );
				return;
			}

			$sync = new \Wbts\GlobalStylesSync\GlobalStylesSync( $option );
			$res  = $sync->sync_to_theme( $opt_val );

			if ( isset( $res['error'] ) ) {
				WP_CLI::error( $res['error'] );
				return;
			}

			WP_CLI::success( 'Global styles synced.' );

			if ( ! empty( $res['backups'] ) ) {
				foreach ( $res['backups'] as $b ) {
					WP_CLI::log( 'Backup: ' . $b );
				}
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
}
