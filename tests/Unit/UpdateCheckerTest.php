<?php
/**
 * Unit tests for UpdateChecker.
 *
 * @package WpBlockTemplateSync\Tests\Unit
 */

namespace WpBlockTemplateSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WpBlockTemplateSync\UpdateChecker;
use PHPUnit\Framework\Attributes\Test;

class UpdateCheckerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if ( ! defined( 'WP_BLOCK_TEMPLATE_SYNC_GITHUB_REPO' ) ) {
            define( 'WP_BLOCK_TEMPLATE_SYNC_GITHUB_REPO', 'jesgs-interactive/wp-block-template-sync' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function check_update_adds_update_when_remote_version_is_newer(): void {
        $repo = UpdateChecker::GITHUB_REPO;
        $expected_key = 'wbts_github_release_' . md5( $repo );

        $tag = 'v2.0.0';
        $release = array(
            'tag_name'   => $tag,
            'html_url'   => 'https://github.com/' . UpdateChecker::GITHUB_REPO . '/releases/tag/' . $tag,
            'zipball_url'=> UpdateChecker::GITHUB_REPO_URL . '/zipball/' . $tag,
            'body'       => 'Release notes',
        );

        // No cached transient.
        Functions\expect( 'get_transient' )
            ->once()
            ->with( $expected_key )
            ->andReturn( false );

        // Simulate network call and response extraction.
        Functions\expect( 'wp_remote_get' )
            ->once()
            ->andReturn( array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $release ) ) );

        Functions\expect( 'wp_remote_retrieve_response_code' )
            ->once()
            ->andReturn( 200 );

        Functions\expect( 'wp_remote_retrieve_body' )
            ->once()
            ->andReturn( json_encode( $release ) );

        Functions\expect( 'set_transient' )
            ->once()
            ->with( $expected_key, \Mockery::type('array'), 60 * 360 )
            ->andReturnTrue();

        $checker = new UpdateChecker( 'wp-block-template-sync/wp-block-template-sync.php', '1.0.0' );

        $transient = new \stdClass();
        $transient->response = array();

        $result = $checker->check_update( $transient );

        $this->assertIsObject( $result );
        $this->assertArrayHasKey( 'wp-block-template-sync/wp-block-template-sync.php', $result->response );

        $update = $result->response['wp-block-template-sync/wp-block-template-sync.php'];
        $this->assertEquals( '2.0.0', $update->new_version );
        $this->assertEquals( $release['zipball_url'], $update->package );
    }

    #[Test]
    public function plugins_api_returns_info_from_cached_release(): void {
        $repo = UpdateChecker::GITHUB_REPO;
        $expected_key = 'wbts_github_release_' . md5( $repo );

        $tag = 'v3.1.4';
        $release = array(
            'tag_name'   => $tag,
            'html_url'   => 'https://github.com/' . UpdateChecker::GITHUB_REPO . '/releases/tag/' . $tag,
            'zipball_url'=> UpdateChecker::GITHUB_REPO_URL . '/zipball/' . $tag,
            'body'       => "What's new",
        );

        // Return cached release to avoid network.
        Functions\expect( 'get_transient' )
            ->once()
            ->with( $expected_key )
            ->andReturn( $release );

        $checker = new UpdateChecker( 'wp-block-template-sync/wp-block-template-sync.php', '3.0.0' );

        $args = (object) array( 'slug' => 'wp-block-template-sync' );

        $info = $checker->plugins_api( false, 'plugin_information', $args );

        $this->assertIsObject( $info );
        $this->assertEquals( '3.1.4', $info->version );
        $this->assertEquals( $release['zipball_url'], $info->download_link );
        $this->assertStringContainsString( 'What', $info->sections['changelog'] );
    }

}

