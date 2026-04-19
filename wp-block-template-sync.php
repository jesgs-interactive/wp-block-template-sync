<?php
/**
 * Plugin Name: WP Block Template Sync
 * Plugin URI:  https://github.com/jesgs-interactive/wp-block-template-sync
 * Description: Syncs changes to theme templates in the Site Editor with the files in the theme.
 * Version:     1.0.0
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

define( 'WP_BLOCK_TEMPLATE_SYNC_VERSION', '1.0.0' );
define( 'WP_BLOCK_TEMPLATE_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WP_BLOCK_TEMPLATE_SYNC_PLUGIN_DIR . 'includes/class-template-sync.php';

add_action(
	'plugins_loaded',
	function () {
		$sync = new WpBlockTemplateSync\TemplateSync();
		$sync->init();
	}
);
