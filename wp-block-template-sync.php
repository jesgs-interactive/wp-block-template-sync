<?php
/**
 * Plugin Name: WP Block Template Sync
 * Plugin URI: https://github.com/jesgs-interactive/wp-block-template-sync
 * Description: Syncs changes to theme templates in the Site Editor with the files in the theme.
 * Version: {{VERSION}}
 * Author: Jess Green
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-block-template-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_BLOCK_TEMPLATE_SYNC_VERSION', '{{VERSION}}' );
define( 'WP_BLOCK_TEMPLATE_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WP_BLOCK_TEMPLATE_SYNC_PLUGIN_DIR . 'includes/class-template-sync.php';
require_once WP_BLOCK_TEMPLATE_SYNC_PLUGIN_DIR . 'includes/class-global-styles-sync.php';

add_action(
	'plugins_loaded',
	function () {
		$sync = new WpBlockTemplateSync\TemplateSync();
		$sync->init();

		$global_styles_sync = new \WpBlockTemplateSync\GlobalStylesSync(); // phpcs:ignore -- hooks registered in constructor; variable kept to document intent.
	}
);

require_once WP_BLOCK_TEMPLATE_SYNC_PLUGIN_DIR . 'includes/cli-global-styles.php';
