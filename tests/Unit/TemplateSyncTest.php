<?php
/**
 * Unit tests for TemplateSync.
 *
 * @package WpBlockTemplateSync\Tests\Unit
 */

namespace WpBlockTemplateSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WpBlockTemplateSync\TemplateSync;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for \WpBlockTemplateSync\TemplateSync.
 */
class TemplateSyncTest extends TestCase {

use MockeryPHPUnitIntegration;

/**
 * @var TemplateSync
 */
private TemplateSync $sync;

/**
 * Set up test fixtures.
 */
protected function setUp(): void {
parent::setUp();
Monkey\setUp();
$this->sync = new TemplateSync();
}

/**
 * Tear down after each test.
 */
protected function tearDown(): void {
Monkey\tearDown();
parent::tearDown();
}

// -------------------------------------------------------------------------
// init()
// -------------------------------------------------------------------------

#[Test]
	public function init_registers_hooks(): void {
Actions\expectAdded( 'rest_after_insert_wp_template' )
->once()
->with( array( $this->sync, 'sync_template' ), 10, 3 );

Actions\expectAdded( 'rest_after_insert_wp_template_part' )
->once()
->with( array( $this->sync, 'sync_template_part' ), 10, 3 );

$this->sync->init();
}

// -------------------------------------------------------------------------
// sync_post() - capability check
// -------------------------------------------------------------------------

#[Test]
	public function sync_post_returns_false_when_user_lacks_capability(): void {
Functions\expect( 'current_user_can' )
->once()
->with( 'edit_theme_options' )
->andReturn( false );

$post   = $this->make_post( 123, 'index', '<!-- wp:paragraph /-->' );
$result = $this->sync->sync_post( $post, 'templates' );

$this->assertFalse( $result );
}

// -------------------------------------------------------------------------
// sync_post() - theme ownership check
// -------------------------------------------------------------------------

#[Test]
	public function sync_post_returns_false_when_template_belongs_to_different_theme(): void {
Functions\expect( 'current_user_can' )
->once()
->with( 'edit_theme_options' )
->andReturn( true );

$term = $this->make_term( 'other-theme' );
Functions\expect( 'get_the_terms' )
->once()
->with( 42, 'wp_theme' )
->andReturn( array( $term ) );

Functions\expect( 'is_wp_error' )
->once()
->andReturn( false );

Functions\expect( 'get_stylesheet' )
->once()
->andReturn( 'active-theme' );

$post   = $this->make_post( 42, 'index', '<!-- wp:paragraph /-->' );
$result = $this->sync->sync_post( $post, 'templates' );

$this->assertFalse( $result );
}

#[Test]
	public function sync_post_returns_false_when_no_theme_term_found(): void {
Functions\expect( 'current_user_can' )
->once()
->with( 'edit_theme_options' )
->andReturn( true );

Functions\expect( 'get_the_terms' )
->once()
->with( 1, 'wp_theme' )
->andReturn( array() );

Functions\expect( 'is_wp_error' )
->once()
->andReturn( false );

$post   = $this->make_post( 1, 'index', '<!-- wp:paragraph /-->' );
$result = $this->sync->sync_post( $post, 'templates' );

$this->assertFalse( $result );
}

// -------------------------------------------------------------------------
// sync_post() - path traversal guard
// -------------------------------------------------------------------------

#[Test]
	public function sync_post_returns_false_when_slug_contains_traversal_sequence(): void {
Functions\expect( 'current_user_can' )
->once()
->with( 'edit_theme_options' )
->andReturn( true );

$term = $this->make_term( 'my-theme' );
Functions\expect( 'get_the_terms' )
->once()
->with( 5, 'wp_theme' )
->andReturn( array( $term ) );

Functions\expect( 'is_wp_error' )
->once()
->andReturn( false );

Functions\expect( 'get_stylesheet' )
->once()
->andReturn( 'my-theme' );

$post   = $this->make_post( 5, '../../../wp-config', '' );
$result = $this->sync->sync_post( $post, 'templates' );

$this->assertFalse( $result );
}

#[Test]
	public function sync_post_returns_false_when_slug_contains_forward_slash(): void {
Functions\expect( 'current_user_can' )
->once()
->with( 'edit_theme_options' )
->andReturn( true );

$term = $this->make_term( 'my-theme' );
Functions\expect( 'get_the_terms' )
->once()
->with( 6, 'wp_theme' )
->andReturn( array( $term ) );

Functions\expect( 'is_wp_error' )
->once()
->andReturn( false );

Functions\expect( 'get_stylesheet' )
->once()
->andReturn( 'my-theme' );

$post   = $this->make_post( 6, 'sub/template', '' );
$result = $this->sync->sync_post( $post, 'templates' );

$this->assertFalse( $result );
}

// -------------------------------------------------------------------------
// sync_post() - successful write
// -------------------------------------------------------------------------

#[Test]
	public function sync_post_writes_template_file_and_returns_true(): void {
Functions\expect( 'current_user_can' )
->once()
->with( 'edit_theme_options' )
->andReturn( true );

$term = $this->make_term( 'my-theme' );
Functions\expect( 'get_the_terms' )
->once()
->with( 10, 'wp_theme' )
->andReturn( array( $term ) );

Functions\expect( 'is_wp_error' )
->once()
->andReturn( false );

Functions\expect( 'get_stylesheet' )
->once()
->andReturn( 'my-theme' );

$theme_dir = sys_get_temp_dir() . '/wp-bts-test-' . uniqid();
Functions\expect( 'get_stylesheet_directory' )
->once()
->andReturn( $theme_dir );

Functions\expect( 'wp_mkdir_p' )
->once()
->with( $theme_dir . DIRECTORY_SEPARATOR . 'templates' )
->andReturn( true );

$content   = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
$file_path = $theme_dir . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'index.html';

$fs_mock = \Mockery::mock( 'WP_Filesystem_Base' );
$fs_mock->shouldReceive( 'put_contents' )
->once()
->with( $file_path, $content, \Mockery::any() )
->andReturn( true );

global $wp_filesystem;
$wp_filesystem = $fs_mock;

$post   = $this->make_post( 10, 'index', $content );
$result = $this->sync->sync_post( $post, 'templates' );

$this->assertTrue( $result );

$wp_filesystem = null;
}

#[Test]
	public function sync_post_writes_template_part_file_and_returns_true(): void {
Functions\expect( 'current_user_can' )
->once()
->with( 'edit_theme_options' )
->andReturn( true );

$term = $this->make_term( 'my-theme' );
Functions\expect( 'get_the_terms' )
->once()
->with( 20, 'wp_theme' )
->andReturn( array( $term ) );

Functions\expect( 'is_wp_error' )
->once()
->andReturn( false );

Functions\expect( 'get_stylesheet' )
->once()
->andReturn( 'my-theme' );

$theme_dir = sys_get_temp_dir() . '/wp-bts-test-' . uniqid();
Functions\expect( 'get_stylesheet_directory' )
->once()
->andReturn( $theme_dir );

Functions\expect( 'wp_mkdir_p' )
->once()
->with( $theme_dir . DIRECTORY_SEPARATOR . 'parts' )
->andReturn( true );

$content   = '<!-- wp:site-title /-->';
$file_path = $theme_dir . DIRECTORY_SEPARATOR . 'parts' . DIRECTORY_SEPARATOR . 'header.html';

$fs_mock = \Mockery::mock( 'WP_Filesystem_Base' );
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

#[Test]
	public function sync_template_delegates_to_sync_post_with_templates_type(): void {
$post    = $this->make_post( 30, 'single', '' );
$request = \Mockery::mock( 'WP_REST_Request' );

/** @var TemplateSync|\Mockery\MockInterface $sync */
$sync = \Mockery::mock( TemplateSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
$sync->shouldReceive( 'sync_post' )
->once()
->with( $post, 'templates' );

$sync->sync_template( $post, $request, false );
}

#[Test]
	public function sync_template_part_delegates_to_sync_post_with_parts_type(): void {
$post    = $this->make_post( 31, 'footer', '' );
$request = \Mockery::mock( 'WP_REST_Request' );

/** @var TemplateSync|\Mockery\MockInterface $sync */
$sync = \Mockery::mock( TemplateSync::class )->makePartial()->shouldAllowMockingProtectedMethods();
$sync->shouldReceive( 'sync_post' )
->once()
->with( $post, 'parts' );

$sync->sync_template_part( $post, $request, false );
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
$post               = \Mockery::mock( 'WP_Post' );
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
$term       = \Mockery::mock( 'WP_Term' );
$term->name = $name;

return $term;
}
}
