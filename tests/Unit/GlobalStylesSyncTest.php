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
use WpBlockTemplateSync\GlobalStylesSync;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for \WpBlockTemplateSync\GlobalStylesSync.
 */
class GlobalStylesSyncTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Constructor / hook registration
	// -------------------------------------------------------------------------

	#[Test]
	public function constructor_registers_expected_hooks(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		Actions\expectAdded( 'save_post_wp_global_styles' )->once();
		Actions\expectAdded( 'update_option_wp_global_styles' )->once();
		Actions\expectAdded( 'after_switch_theme' )->once();

		new GlobalStylesSync();
	}

	// -------------------------------------------------------------------------
	// on_save_global_styles_post()
	// -------------------------------------------------------------------------

	#[Test]
	public function on_save_global_styles_post_ignores_revisions(): void {
		Functions\expect( 'wp_is_post_revision' )
			->once()
			->with( 99 )
			->andReturn( true );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldNotReceive( 'sync_to_theme' );

		$post = $this->make_post( 99, 'wp-global-styles-my-theme', '{}' );
		$mock->on_save_global_styles_post( 99, $post, true );
	}

	#[Test]
	public function on_save_global_styles_post_ignores_autosaves(): void {
		Functions\expect( 'wp_is_post_revision' )
			->once()
			->with( 5 )
			->andReturn( false );

		Functions\expect( 'wp_is_post_autosave' )
			->once()
			->with( 5 )
			->andReturn( true );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldNotReceive( 'sync_to_theme' );

		$post = $this->make_post( 5, 'wp-global-styles-my-theme', '{}' );
		$mock->on_save_global_styles_post( 5, $post, false );
	}

	#[Test]
	public function on_save_global_styles_post_ignores_mismatched_post_name(): void {
		Functions\expect( 'wp_is_post_revision' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_is_post_autosave' )
			->once()
			->andReturn( false );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( true );

		Functions\expect( 'get_stylesheet' )
			->once()
			->andReturn( 'active-theme' );

		// post_name is for a different theme.
		$post = $this->make_post( 10, 'wp-global-styles-other-theme', '{}' );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldNotReceive( 'sync_to_theme' );

		$mock->on_save_global_styles_post( 10, $post, true );
	}

	#[Test]
	public function on_save_global_styles_post_calls_sync_to_theme_for_active_theme(): void {
		Functions\expect( 'wp_is_post_revision' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_is_post_autosave' )
			->once()
			->andReturn( false );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( true );

		Functions\expect( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		$content = '{"version":2,"settings":{},"styles":{}}';
		$post    = $this->make_post( 20, 'wp-global-styles-my-theme', $content );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldReceive( 'sync_to_theme' )
			->once()
			->with( $content );

		$mock->on_save_global_styles_post( 20, $post, true );
	}

	#[Test]
	public function on_save_global_styles_post_skips_when_filter_disabled(): void {
		Functions\expect( 'wp_is_post_revision' )
			->once()
			->andReturn( false );

		Functions\expect( 'wp_is_post_autosave' )
			->once()
			->andReturn( false );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( false );

		$post = $this->make_post( 21, 'wp-global-styles-my-theme', '{}' );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldNotReceive( 'sync_to_theme' );

		$mock->on_save_global_styles_post( 21, $post, true );
	}

	// -------------------------------------------------------------------------
	// on_update_option()
	// -------------------------------------------------------------------------

	#[Test]
	public function on_update_option_calls_sync_to_theme_with_string_value(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( true );

		$content = '{"version":2}';

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldReceive( 'sync_to_theme' )
			->once()
			->with( $content );

		$mock->on_update_option( '', $content, 'wp_global_styles' );
	}

	#[Test]
	public function on_update_option_skips_when_filter_disabled(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( false );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldNotReceive( 'sync_to_theme' );

		$mock->on_update_option( '', '{}', 'wp_global_styles' );
	}

	// -------------------------------------------------------------------------
	// on_after_switch_theme()
	// -------------------------------------------------------------------------

	#[Test]
	public function on_after_switch_theme_syncs_when_post_found(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( true );

		$content = '{"version":2,"styles":{}}';
		$post    = $this->make_post( 30, 'wp-global-styles-my-theme', $content );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldReceive( 'find_global_styles_post_for_current_theme' )
			->once()
			->andReturn( $post );
		$mock->shouldReceive( 'sync_to_theme' )
			->once()
			->with( $content );

		$mock->on_after_switch_theme();
	}

	#[Test]
	public function on_after_switch_theme_does_nothing_when_no_post(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_auto_sync_global_styles_enabled', true )
			->andReturn( true );

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldReceive( 'find_global_styles_post_for_current_theme' )
			->once()
			->andReturn( null );
		$mock->shouldNotReceive( 'sync_to_theme' );

		$mock->on_after_switch_theme();
	}

	// -------------------------------------------------------------------------
	// find_global_styles_post_for_current_theme()
	// -------------------------------------------------------------------------

	#[Test]
	public function find_returns_post_from_get_page_by_path(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		$post = $this->make_post( 40, 'wp-global-styles-my-theme', '{}' );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'wp-global-styles-my-theme', OBJECT, 'wp_global_styles' )
			->andReturn( $post );

		$result = $sync->find_global_styles_post_for_current_theme();

		$this->assertSame( $post, $result );
	}

	#[Test]
	public function find_falls_back_to_get_posts_when_page_by_path_returns_null(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );

		$post = $this->make_post( 41, 'wp-global-styles-my-theme', '{}' );

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( $post ) );

		$result = $sync->find_global_styles_post_for_current_theme();

		$this->assertSame( $post, $result );
	}

	#[Test]
	public function find_returns_null_when_no_post_exists(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wbts_global_styles_option_name', 'wp_global_styles' )
			->andReturn( 'wp_global_styles' );

		$sync = new GlobalStylesSync();

		Functions\expect( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );

		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array() );

		$result = $sync->find_global_styles_post_for_current_theme();

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// sync_to_theme()
	// -------------------------------------------------------------------------

	#[Test]
	public function sync_to_theme_writes_theme_json_and_returns_written_path(): void {
		// Create a real temp directory so is_dir / file_exists work naturally.
		$theme_dir = sys_get_temp_dir() . '/wbts-test-' . uniqid();
		mkdir( $theme_dir, 0755, true );

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $theme_dir );

		Functions\expect( 'wp_mkdir_p' )
			->once()
			->andReturn( true );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing(
				static function ( $data, $flags ) {
					return (string) json_encode( $data, $flags );
				}
			);

		$fs_mock = \Mockery::mock( 'WP_Filesystem_Base' );
		$fs_mock->shouldReceive( 'put_contents' )
			->once()
			->with( $theme_dir . '/theme.json', \Mockery::type( 'string' ), \Mockery::any() )
			->andReturn( true );

		global $wp_filesystem;
		$wp_filesystem = $fs_mock;

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldReceive( 'get_global_stylesheet' )->once()->andReturn( '' );

		$content = '{"version":2,"settings":{"color":{"palette":[]}},"styles":{"color":{"background":"#fff"}}}';
		$result  = $mock->sync_to_theme( $content );

		$this->assertContains( $theme_dir . '/theme.json', $result['written'] );
		$this->assertEmpty( $result['backup'] );

		$wp_filesystem = null;
		rmdir( $theme_dir );
	}

	#[Test]
	public function sync_to_theme_backs_up_existing_theme_json(): void {
		// Create a real temp directory with an existing theme.json.
		$theme_dir = sys_get_temp_dir() . '/wbts-test-' . uniqid();
		mkdir( $theme_dir, 0755, true );
		file_put_contents( $theme_dir . '/theme.json', '{"version":2}' );

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $theme_dir );

		Functions\expect( 'wp_mkdir_p' )
			->once()
			->andReturnUsing(
				static function ( string $dir ): bool {
					return mkdir( $dir, 0755, true );
				}
			);

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing(
				static function ( $data, $flags ) {
					return (string) json_encode( $data, $flags );
				}
			);

		$fs_mock = \Mockery::mock( 'WP_Filesystem_Base' );
		$fs_mock->shouldReceive( 'put_contents' )
			->once()
			->andReturn( true );

		global $wp_filesystem;
		$wp_filesystem = $fs_mock;

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldReceive( 'get_global_stylesheet' )->once()->andReturn( '' );

		$result = $mock->sync_to_theme( '{}' );

		$this->assertCount( 1, $result['backup'] );
		$this->assertStringContainsString( 'theme-', $result['backup'][0] );
		$this->assertStringEndsWith( '.json', $result['backup'][0] );

		$wp_filesystem = null;

		// Clean up.
		array_map( 'unlink', glob( $theme_dir . '/.global-styles-backups/*' ) ?: array() );
		@rmdir( $theme_dir . '/.global-styles-backups' );
		@unlink( $theme_dir . '/theme.json' );
		rmdir( $theme_dir );
	}

	#[Test]
	public function sync_to_theme_writes_style_css_when_function_available(): void {
		$theme_dir = sys_get_temp_dir() . '/wbts-test-' . uniqid();
		mkdir( $theme_dir, 0755, true );

		Functions\expect( 'get_stylesheet_directory' )
			->once()
			->andReturn( $theme_dir );

		Functions\expect( 'wp_mkdir_p' )
			->once()
			->andReturn( true );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing(
				static function ( $data, $flags ) {
					return (string) json_encode( $data, $flags );
				}
			);

		$fs_mock = \Mockery::mock( 'WP_Filesystem_Base' );
		$fs_mock->shouldReceive( 'put_contents' )
			->twice()
			->andReturn( true );

		global $wp_filesystem;
		$wp_filesystem = $fs_mock;

		/** @var GlobalStylesSync|\Mockery\MockInterface $mock */
		$mock = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$mock->shouldReceive( 'get_global_stylesheet' )->once()->andReturn( 'body { color: red; }' );

		$result = $mock->sync_to_theme( '{}' );

		$this->assertContains( $theme_dir . '/style.css', $result['written'] );

		$wp_filesystem = null;
		rmdir( $theme_dir );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal WP_Post stub.
	 *
	 * @param int    $id           Post ID.
	 * @param string $post_name    Post slug.
	 * @param string $post_content Post content (JSON).
	 * @return \WP_Post
	 */
	private function make_post( int $id, string $post_name, string $post_content ): \WP_Post {
		$post               = \Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_name    = $post_name;
		$post->post_content = $post_content;

		return $post;
	}
}
