<?php
/**
 * Unit tests for TemplateSync.
 *
 * @package WpBlockTemplateSync\Tests\Unit
 */

namespace WpBlockTemplateSync\Tests\Unit;

use WP_Mock;
use WP_Mock\Tools\TestCase;
use WpBlockTemplateSync\TemplateSync;

/**
 * Tests for \WpBlockTemplateSync\TemplateSync.
 */
class TemplateSyncTest extends TestCase {

	/**
	 * @var TemplateSync
	 */
	private TemplateSync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
		$this->sync = new TemplateSync();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// -------------------------------------------------------------------------
	// init()
	// -------------------------------------------------------------------------

	/**
	 * @test
	 */
	public function init_registers_hooks(): void {
		WP_Mock::expectActionAdded( 'rest_after_insert_wp_template', array( $this->sync, 'sync_template' ), 10, 3 );
		WP_Mock::expectActionAdded( 'rest_after_insert_wp_template_part', array( $this->sync, 'sync_template_part' ), 10, 3 );

		$this->sync->init();

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// sync_post() – capability check
	// -------------------------------------------------------------------------

	/**
	 * @test
	 */
	public function sync_post_returns_false_when_user_lacks_capability(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_theme_options' )
			->andReturn( false );

		$post = $this->make_post( 123, 'index', '<!-- wp:paragraph /-->' );
		$result = $this->sync->sync_post( $post, 'templates' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// sync_post() – theme ownership check
	// -------------------------------------------------------------------------

	/**
	 * @test
	 */
	public function sync_post_returns_false_when_template_belongs_to_different_theme(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_theme_options' )
			->andReturn( true );

		$term = $this->make_term( 'other-theme' );
		WP_Mock::userFunction( 'get_the_terms' )
			->once()
			->with( 42, 'wp_theme' )
			->andReturn( array( $term ) );

		WP_Mock::userFunction( 'is_wp_error' )
			->once()
			->andReturn( false );

		WP_Mock::userFunction( 'get_stylesheet' )
			->once()
			->andReturn( 'active-theme' );

		$post   = $this->make_post( 42, 'index', '<!-- wp:paragraph /-->' );
		$result = $this->sync->sync_post( $post, 'templates' );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function sync_post_returns_false_when_no_theme_term_found(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_theme_options' )
			->andReturn( true );

		WP_Mock::userFunction( 'get_the_terms' )
			->once()
			->with( 1, 'wp_theme' )
			->andReturn( array() );

		WP_Mock::userFunction( 'is_wp_error' )
			->once()
			->andReturn( false );

		$post   = $this->make_post( 1, 'index', '<!-- wp:paragraph /-->' );
		$result = $this->sync->sync_post( $post, 'templates' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// sync_post() – path traversal guard
	// -------------------------------------------------------------------------

	/**
	 * @test
	 */
	public function sync_post_returns_false_when_slug_contains_traversal_sequence(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_theme_options' )
			->andReturn( true );

		$term = $this->make_term( 'my-theme' );
		WP_Mock::userFunction( 'get_the_terms' )
			->once()
			->with( 5, 'wp_theme' )
			->andReturn( array( $term ) );

		WP_Mock::userFunction( 'is_wp_error' )
			->once()
			->andReturn( false );

		WP_Mock::userFunction( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		$post   = $this->make_post( 5, '../../../wp-config', '' );
		$result = $this->sync->sync_post( $post, 'templates' );

		$this->assertFalse( $result );
	}

	/**
	 * @test
	 */
	public function sync_post_returns_false_when_slug_contains_forward_slash(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_theme_options' )
			->andReturn( true );

		$term = $this->make_term( 'my-theme' );
		WP_Mock::userFunction( 'get_the_terms' )
			->once()
			->with( 6, 'wp_theme' )
			->andReturn( array( $term ) );

		WP_Mock::userFunction( 'is_wp_error' )
			->once()
			->andReturn( false );

		WP_Mock::userFunction( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		$post   = $this->make_post( 6, 'sub/template', '' );
		$result = $this->sync->sync_post( $post, 'templates' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// sync_post() – successful write
	// -------------------------------------------------------------------------

	/**
	 * @test
	 */
	public function sync_post_writes_template_file_and_returns_true(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_theme_options' )
			->andReturn( true );

		$term = $this->make_term( 'my-theme' );
		WP_Mock::userFunction( 'get_the_terms' )
			->once()
			->with( 10, 'wp_theme' )
			->andReturn( array( $term ) );

		WP_Mock::userFunction( 'is_wp_error' )
			->once()
			->andReturn( false );

		WP_Mock::userFunction( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		$theme_dir = sys_get_temp_dir() . '/wp-block-template-sync-test-' . uniqid();
		WP_Mock::userFunction( 'get_stylesheet_directory' )
			->once()
			->andReturn( $theme_dir );

		WP_Mock::userFunction( 'wp_mkdir_p' )
			->once()
			->with( $theme_dir . DIRECTORY_SEPARATOR . 'templates' )
			->andReturn( true );

		// Stub the wp_filesystem write so we can assert its arguments.
		$content   = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$file_path = $theme_dir . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'index.html';

		$fs_mock = \Mockery::mock( '\WP_Filesystem_Base' );
		$fs_mock->shouldReceive( 'put_contents' )
			->once()
			->with( $file_path, $content, \Mockery::any() )
			->andReturn( true );

		// Inject the filesystem mock into the global.
		global $wp_filesystem;
		$wp_filesystem = $fs_mock;

		$post   = $this->make_post( 10, 'index', $content );
		$result = $this->sync->sync_post( $post, 'templates' );

		$this->assertTrue( $result );

		// Restore.
		$wp_filesystem = null;
	}

	/**
	 * @test
	 */
	public function sync_post_writes_template_part_file_and_returns_true(): void {
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_theme_options' )
			->andReturn( true );

		$term = $this->make_term( 'my-theme' );
		WP_Mock::userFunction( 'get_the_terms' )
			->once()
			->with( 20, 'wp_theme' )
			->andReturn( array( $term ) );

		WP_Mock::userFunction( 'is_wp_error' )
			->once()
			->andReturn( false );

		WP_Mock::userFunction( 'get_stylesheet' )
			->once()
			->andReturn( 'my-theme' );

		$theme_dir = sys_get_temp_dir() . '/wp-block-template-sync-test-' . uniqid();
		WP_Mock::userFunction( 'get_stylesheet_directory' )
			->once()
			->andReturn( $theme_dir );

		WP_Mock::userFunction( 'wp_mkdir_p' )
			->once()
			->with( $theme_dir . DIRECTORY_SEPARATOR . 'parts' )
			->andReturn( true );

		$content   = '<!-- wp:site-title /-->';
		$file_path = $theme_dir . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . 'header.html';

		$fs_mock = \Mockery::mock( '\WP_Filesystem_Base' );
		$fs_mock->shouldReceive( 'put_contents' )
			->once()
			->with( $file_path, $content, \Mockery::any() )
			->andReturn( true );

		global $wp_filesystem;
		$wp_filesystem = $fs_mock;

		$post   = $this->make_post( 20, 'header', $content );
		$result = $this->sync->sync_post( $post, 'parts' );

		$this->assertTrue( $result );

		$wp_filesystem = null;
	}

	// -------------------------------------------------------------------------
	// sync_template() / sync_template_part() delegates
	// -------------------------------------------------------------------------

	/**
	 * @test
	 */
	public function sync_template_delegates_to_sync_post_with_templates_type(): void {
		$post    = $this->make_post( 30, 'single', '' );
		$request = \Mockery::mock( '\WP_REST_Request' );

		/** @var TemplateSync|\Mockery\MockInterface $sync */
		$sync = \Mockery::mock( TemplateSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$sync->shouldReceive( 'sync_post' )
			->once()
			->with( $post, 'templates' );

		$sync->sync_template( $post, $request, false );

		$this->assertConditionsMet();
	}

	/**
	 * @test
	 */
	public function sync_template_part_delegates_to_sync_post_with_parts_type(): void {
		$post    = $this->make_post( 31, 'footer', '' );
		$request = \Mockery::mock( '\WP_REST_Request' );

		/** @var TemplateSync|\Mockery\MockInterface $sync */
		$sync = \Mockery::mock( TemplateSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$sync->shouldReceive( 'sync_post' )
			->once()
			->with( $post, 'parts' );

		$sync->sync_template_part( $post, $request, false );

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal WP_Post stub.
	 *
	 * @param int    $id           Post ID.
	 * @param string $post_name    Template slug.
	 * @param string $post_content Block markup.
	 * @return \WP_Post
	 */
	private function make_post( int $id, string $post_name, string $post_content ): \WP_Post {
		$post               = \Mockery::mock( '\WP_Post' );
		$post->ID           = $id;
		$post->post_name    = $post_name;
		$post->post_content = $post_content;

		return $post;
	}

	/**
	 * Build a minimal WP_Term stub.
	 *
	 * @param string $name Term name (theme slug).
	 * @return \WP_Term
	 */
	private function make_term( string $name ): \WP_Term {
		$term       = \Mockery::mock( '\WP_Term' );
		$term->name = $name;

		return $term;
	}
}
