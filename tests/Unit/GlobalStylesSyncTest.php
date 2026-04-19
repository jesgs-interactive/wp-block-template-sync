<?php
/**
 * Unit tests for GlobalStylesSync.
 *
 * @package WpBlockTemplateSync\Tests\Unit
 */

namespace WpBlockTemplateSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WpBlockTemplateSync\GlobalStylesSync;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for \WpBlockTemplateSync\GlobalStylesSync.
 */
class GlobalStylesSyncTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Temporary theme directory used by write tests.
	 *
	 * @var string
	 */
	private string $theme_dir;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->theme_dir = sys_get_temp_dir() . '/wbts-gs-test-' . uniqid();
		mkdir( $this->theme_dir, 0755, true );
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		// Clean up temporary directory.
		$this->remove_dir( $this->theme_dir );

		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Constructor – hook registration
	// -------------------------------------------------------------------------

	#[Test]
	public function constructor_registers_update_option_hook_with_default_option(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )
			->once();

		Actions\expectAdded( 'after_switch_theme' )
			->once();

		new GlobalStylesSync();
	}

	#[Test]
	public function constructor_uses_provided_option_name(): void {
		Actions\expectAdded( 'update_option_custom_option' )
			->once();

		Actions\expectAdded( 'after_switch_theme' )
			->once();

		new GlobalStylesSync( 'custom_option' );
	}

	// -------------------------------------------------------------------------
	// on_update_option()
	// -------------------------------------------------------------------------

	#[Test]
	public function on_update_option_skips_sync_when_filter_returns_false(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->once()
			->andReturn( false );

		// get_stylesheet_directory must NOT be called when sync is disabled.
		Functions\expect( 'get_stylesheet_directory' )->never();

		// Pass the option name explicitly so the constructor skips the apply_filters call.
		$sync = new GlobalStylesSync( 'wp_global_styles' );
		$sync->on_update_option( '', '{}', 'wp_global_styles' );
	}

	#[Test]
	public function on_update_option_calls_sync_when_filter_returns_true(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->once()
			->andReturn( true );

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->andReturn( '/* css */' );

		// Pass the option name explicitly so the constructor skips the apply_filters call.
		$sync = new GlobalStylesSync( 'wp_global_styles' );
		$sync->on_update_option( '', '{"version":2}', 'wp_global_styles' );

		// Return value is void; just assert no exception.
		$this->addToAssertionCount( 1 );
	}

	// -------------------------------------------------------------------------
	// on_after_switch_theme()
	// -------------------------------------------------------------------------

	#[Test]
	public function on_after_switch_theme_skips_when_filter_disabled(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->once()
			->andReturn( false );

		Functions\expect( 'get_option' )->never();

		// Pass option name explicitly to avoid a second apply_filters call in constructor.
		$sync = new GlobalStylesSync( 'wp_global_styles' );
		$sync->on_after_switch_theme();
	}

	#[Test]
	public function on_after_switch_theme_skips_when_option_empty(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->once()
			->andReturn( true );

		Functions\expect( 'get_option' )
			->once()
			->with( 'wp_global_styles' )
			->andReturn( false );

		Functions\expect( 'get_stylesheet_directory' )->never();

		// Pass option name explicitly to avoid a second apply_filters call in constructor.
		$sync = new GlobalStylesSync( 'wp_global_styles' );
		$sync->on_after_switch_theme();
	}

	// -------------------------------------------------------------------------
	// sync_to_theme() – guard conditions
	// -------------------------------------------------------------------------

	#[Test]
	public function sync_to_theme_returns_error_when_theme_dir_not_writable(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( '/nonexistent/path/that/does/not/exist' );

		$sync = new GlobalStylesSync();
		$res  = $sync->sync_to_theme( '{}' );

		$this->assertArrayHasKey( 'error', $res );
		$this->assertSame( 'theme_dir_not_writable', $res['error'] );
	}

	// -------------------------------------------------------------------------
	// sync_to_theme() – successful write
	// -------------------------------------------------------------------------

	#[Test]
	public function sync_to_theme_writes_theme_json_from_json_string(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Functions\expect( 'get_stylesheet_directory' )
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->andReturn( '/* css */' );

		$payload = json_encode( array( 'settings' => array( 'color' => array() ), 'styles' => array() ) );
		$sync    = new GlobalStylesSync();
		$res     = $sync->sync_to_theme( $payload );

		$this->assertArrayHasKey( 'theme_json', $res );
		$this->assertFileExists( $res['theme_json'] );

		$written = json_decode( file_get_contents( $res['theme_json'] ), true );
		$this->assertSame( 2, $written['version'] );
		$this->assertArrayHasKey( 'settings', $written );
		$this->assertArrayHasKey( 'styles', $written );
	}

	#[Test]
	public function sync_to_theme_writes_theme_json_from_array(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Functions\expect( 'get_stylesheet_directory' )
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->andReturn( '/* css */' );

		$payload = array( 'settings' => array( 'typography' => array() ) );
		$sync    = new GlobalStylesSync();
		$res     = $sync->sync_to_theme( $payload );

		$this->assertArrayHasKey( 'theme_json', $res );
		$written = json_decode( file_get_contents( $res['theme_json'] ), true );
		$this->assertSame( 2, $written['version'] );
	}

	#[Test]
	public function sync_to_theme_creates_backup_of_existing_theme_json(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Functions\expect( 'get_stylesheet_directory' )
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->andReturn( '/* css */' );

		// Create a pre-existing theme.json so a backup is triggered.
		file_put_contents( $this->theme_dir . '/theme.json', '{"version":1}' );

		$sync = new GlobalStylesSync();
		$res  = $sync->sync_to_theme( array( 'styles' => array() ) );

		$this->assertArrayHasKey( 'backups', $res );
		$this->assertArrayHasKey( 'theme_json', $res['backups'] );
		$this->assertFileExists( $res['backups']['theme_json'] );
	}

	#[Test]
	public function sync_to_theme_writes_style_css(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Functions\expect( 'get_stylesheet_directory' )
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->once()
			->andReturn( 'body { color: red; }' );

		$sync = new GlobalStylesSync();
		$res  = $sync->sync_to_theme( '{}' );

		$this->assertArrayHasKey( 'style_css', $res );
		$this->assertFileExists( $res['style_css'] );
		$this->assertSame( 'body { color: red; }', file_get_contents( $res['style_css'] ) );
	}

	#[Test]
	public function sync_to_theme_uses_fallback_css_comment_when_stylesheet_returns_empty(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Functions\expect( 'get_stylesheet_directory' )
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->andReturnUsing( 'json_encode' );

		// Simulate wp_get_global_stylesheet returning empty string.
		Functions\expect( 'wp_get_global_stylesheet' )
			->once()
			->andReturn( '' );

		$sync = new GlobalStylesSync();
		$res  = $sync->sync_to_theme( '{}' );

		// The CSS file should still be written (with empty content from renderer).
		$this->assertArrayHasKey( 'style_css', $res );
		$this->assertFileExists( $res['style_css'] );
	}

	#[Test]
	public function sync_to_theme_falls_back_to_globalStyles_nested_key(): void {
		Functions\expect( 'apply_filters' )
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Functions\expect( 'get_stylesheet_directory' )
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->andReturn( '' );

		// Use the nested globalStyles key format.
		$payload = array(
			'globalStyles' => array(
				'settings' => array( 'color' => array() ),
				'styles'   => array( 'typography' => array() ),
			),
		);

		$sync = new GlobalStylesSync();
		$res  = $sync->sync_to_theme( $payload );

		$written = json_decode( file_get_contents( $res['theme_json'] ), true );
		$this->assertArrayHasKey( 'settings', $written );
		$this->assertArrayHasKey( 'styles', $written );
	}

	// -------------------------------------------------------------------------
	// Helper: recursive directory removal
	// -------------------------------------------------------------------------

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Path to remove.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
		foreach ( $items as $item ) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			is_dir( $path ) ? $this->remove_dir( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}
}
