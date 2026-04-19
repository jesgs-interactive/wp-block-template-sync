<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WpBlockTemplateSync\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants used by the plugin that WP_Mock does not provide.
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}

WP_Mock::bootstrap();
