<?php
/**
 * Unit tests for GlobalStylesSync.
 *
 * @package WpBlockTemplateSync\Tests\Unit
 */

namespace WpBlockTemplateSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Wbts\GlobalStylesSync\GlobalStylesSync;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for \Wbts\GlobalStylesSync\GlobalStylesSync.
 */
class GlobalStylesSyncTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Temporary theme directory used across tests.
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
		// Clean up temp directory.
		$this->remove_dir( $this->theme_dir );

		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Constructor / hook registration
	// -------------------------------------------------------------------------

	#[Test]
	public function constructor_registers_update_option_hook(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )
			->once()
			->with( \Mockery::type( 'array' ), 10, 3 );

		Actions\expectAdded( 'after_switch_theme' )
			->once()
			->with( \Mockery::type( 'array' ), 10 );

		new GlobalStylesSync();
	}

	#[Test]
	public function constructor_uses_provided_option_name(): void {
		Actions\expectAdded( 'update_option_custom_option' )
			->once();

		Actions\expectAdded( 'after_switch_theme' )
			->once();

		$sync = new GlobalStylesSync( 'custom_option' );
		$this->assertInstanceOf( GlobalStylesSync::class, $sync );
	}

	// -------------------------------------------------------------------------
	// on_update_option() — disabled via filter
	// -------------------------------------------------------------------------

	#[Test]
	public function on_update_option_does_nothing_when_feature_disabled(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( false );

		// Should return early without calling get_stylesheet_directory.
		Functions\expect( 'get_stylesheet_directory' )->never();

		$sync->on_update_option( '', '{}', 'wp_global_styles' );
	}

	// -------------------------------------------------------------------------
	// on_after_switch_theme() — disabled via filter
	// -------------------------------------------------------------------------

	#[Test]
	public function on_after_switch_theme_does_nothing_when_feature_disabled(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( false );

		Functions\expect( 'get_option' )->never();
		Functions\expect( 'get_stylesheet_directory' )->never();

		$sync->on_after_switch_theme();
	}

	#[Test]
	public function on_after_switch_theme_does_nothing_when_option_empty(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( true );

		Functions\expect( 'get_option' )
			->once()
			->with( 'wp_global_styles' )
			->andReturn( false );

		Functions\expect( 'get_stylesheet_directory' )->never();

		$sync->on_after_switch_theme();
	}

	// -------------------------------------------------------------------------
	// sync_to_theme() — error cases
	// -------------------------------------------------------------------------

	#[Test]
	public function sync_to_theme_returns_error_when_theme_dir_not_found(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( '/nonexistent/path/that/does/not/exist' );

		$result = $sync->sync_to_theme( '{}' );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'theme_not_found', $result['error'] );
	}

	#[Test]
	public function sync_to_theme_returns_error_when_global_styles_unparseable(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $this->theme_dir );

		$result = $sync->sync_to_theme( 'not-json-not-serialized!!!' );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'invalid_global_styles', $result['error'] );
	}

	// -------------------------------------------------------------------------
	// sync_to_theme() — successful write with JSON string payload
	// -------------------------------------------------------------------------

	#[Test]
	public function sync_to_theme_writes_theme_json_from_json_string(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->once()
			->andReturn( 'body { color: red; }' );

		$payload = json_encode(
			array(
				'settings' => array( 'color' => array( 'palette' => array() ) ),
				'styles'   => array( 'color' => array( 'text' => '#000' ) ),
			)
		);

		$result = $sync->sync_to_theme( $payload );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertArrayHasKey( 'theme_json', $result );
		$this->assertFileExists( $this->theme_dir . '/theme.json' );

		$written = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true );
		$this->assertSame( 2, $written['version'] );
		$this->assertArrayHasKey( 'settings', $written );
		$this->assertArrayHasKey( 'styles', $written );
	}

	#[Test]
	public function sync_to_theme_writes_style_css(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing( 'json_encode' );

		$expected_css = 'body { margin: 0; }';
		Functions\expect( 'wp_get_global_stylesheet' )
			->once()
			->andReturn( $expected_css );

		$result = $sync->sync_to_theme( array( 'settings' => array(), 'styles' => array() ) );

		$this->assertArrayHasKey( 'style_css', $result );
		$this->assertFileExists( $this->theme_dir . '/style.css' );
		$this->assertSame( $expected_css, file_get_contents( $this->theme_dir . '/style.css' ) );
	}

	#[Test]
	public function sync_to_theme_creates_backups_of_existing_files(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		// Place existing files in the theme dir.
		file_put_contents( $this->theme_dir . '/theme.json', '{"version":1}' );
		file_put_contents( $this->theme_dir . '/style.css', '/* old */' );

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->once()
			->andReturn( '/* new css */' );

		$result = $sync->sync_to_theme( '{"settings":{},"styles":{}}' );

		$this->assertArrayHasKey( 'backups', $result );
		$this->assertCount( 2, $result['backups'] );

		// Backup directory should exist and contain the backup files.
		$backup_dir = $this->theme_dir . '/.global-styles-backups';
		$this->assertDirectoryExists( $backup_dir );
		foreach ( $result['backups'] as $backup_path ) {
			$this->assertFileExists( $backup_path );
		}
	}

	#[Test]
	public function sync_to_theme_accepts_array_payload(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_get_global_stylesheet' )
			->once()
			->andReturn( '' );

		$payload = array(
			'settings' => array( 'typography' => array() ),
			'styles'   => array(),
		);

		$result = $sync->sync_to_theme( $payload );

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertFileExists( $this->theme_dir . '/theme.json' );

		$written = json_decode( file_get_contents( $this->theme_dir . '/theme.json' ), true );
		$this->assertSame( 2, $written['version'] );
	}

	#[Test]
	public function sync_to_theme_uses_fallback_css_when_renderer_returns_empty(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $this->theme_dir );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing( 'json_encode' );

		// Renderer exists but returns empty — the fallback comment should be used.
		Functions\expect( 'wp_get_global_stylesheet' )
			->once()
			->andReturn( '' );

		$result = $sync->sync_to_theme( array( 'settings' => array(), 'styles' => array() ) );

		$this->assertArrayHasKey( 'style_css', $result );
		$css = file_get_contents( $this->theme_dir . '/style.css' );
		$this->assertStringContainsString( 'wp-block-template-sync', $css );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Path to the directory.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
