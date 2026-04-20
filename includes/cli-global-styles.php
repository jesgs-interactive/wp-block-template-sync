<?php
/**
 * WP-CLI command for manually triggering a global styles sync.
 *
 * Registers the command:
 *   wp block-template-sync sync-global-styles
 *
 * @package WpBlockTemplateSync
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_command(
	'block-template-sync sync-global-styles',
	static function (): void {
		$sync = new \WpBlockTemplateSync\includes\GlobalStylesSync();
		$post = $sync->find_global_styles_post_for_current_theme();

		if ( ! $post ) {
			WP_CLI::error( 'No wp_global_styles post found for the current theme. Nothing to sync.' );
			return;
		}

		$result = $sync->sync_to_theme( $post->post_content );

		foreach ( $result['backup'] as $file ) {
			WP_CLI::log( 'Backed up: ' . $file );
		}

		foreach ( $result['written'] as $file ) {
			WP_CLI::log( 'Written:   ' . $file );
		}

		WP_CLI::success( 'Global styles synced to theme files.' );
	},
	array(
		'shortdesc' => 'Sync global styles from the database to theme.json and style.css.',
		'longdesc'  => <<<'EOT'
Reads the wp_global_styles post for the currently active theme and writes the
settings and styles into theme.json (version 2) and, when WordPress provides
the wp_get_global_stylesheet() function, into style.css.

Existing copies of theme.json and style.css are backed up into the
.global-styles-backups/ directory inside the theme folder before being
overwritten.

## EXAMPLES

    wp block-template-sync sync-global-styles
EOT
		,
	)
);
