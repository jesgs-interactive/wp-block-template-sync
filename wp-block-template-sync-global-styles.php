<?php
/**
 * Plugin Name: WP Block Template Sync — Global Styles Auto Sync
 * Plugin URI:  https://github.com/jesgs-interactive/wp-block-template-sync
 * Description: Auto-sync global styles from DB to theme.json and style.css for local theme development. Overwrites theme.json (no merge) and writes computed global stylesheet to style.css. Backups are stored in the active theme under .global-styles-backups/.
 * Version:     0.1.0
 * Author:      Jess Green
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-block-template-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/inc/GlobalStylesSync.php';

if ( file_exists( __DIR__ . '/inc/cli-global-styles.php' ) ) {
	require_once __DIR__ . '/inc/cli-global-styles.php';
}

add_action(
	'plugins_loaded',
	function () {
		// Allow override of the option name via filter.
		$option = apply_filters( 'wbts_global_styles_option_name', 'wp_global_styles' );

		// Instantiate the sync class (keeps hooks alive for the request lifetime).
		new \Wbts\GlobalStylesSync\GlobalStylesSync( $option );
	}
);
