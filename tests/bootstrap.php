<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package WpBlockTemplateSync\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants used by the plugin that are not provided in tests.
if ( ! defined( 'FS_CHMOD_FILE' ) ) {
	define( 'FS_CHMOD_FILE', 0644 );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// Provide a lightweight stub for `get_option()` when running unit tests
// without bootstrapping WordPress. Tests can still override this via
// Brain Monkey expectations when specific values are required.
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal fallback for get_option() used in unit tests when WP core is
	 * not available. Returns the supplied default value.
	 *
	 * Tests may override behavior using Brain Monkey (Functions::expect/when)
	 * after Monkey\setUp() if a different return value is required.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $name, $default = null ) {
		return $default;
	}
}

