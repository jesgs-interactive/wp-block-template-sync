<?php
/**
 * Unit tests for GlobalStylesSync helpers.
 *
 * @package WpBlockTemplateSync\Tests\Unit
 */

namespace WpBlockTemplateSync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WpBlockTemplateSync\GlobalStylesSync;

class GlobalStylesHelpersTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	#[Test]
	public function extract_style_header_returns_header_block(): void {
		$content = "/*\nTheme Name: Foo\n*/\n:root{--a:1;}";

		$sync = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();

		$ref = new \ReflectionMethod( $sync, 'extract_style_header' );
		$ref->setAccessible( true );

		$result = $ref->invoke( $sync, $content );

		$this->assertStringContainsString( 'Theme Name: Foo', $result );
		$this->assertStringEndsWith( "*/\n", $result );
	}

	#[Test]
	public function format_css_expands_minified_css(): void {
		$min = 'body{color:red;}h1{font-size:2rem;}';

		$sync = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();

		$ref = new \ReflectionMethod( $sync, 'format_css' );
		$ref->setAccessible( true );

		$out = $ref->invoke( $sync, $min );

		$this->assertStringContainsString( "{\n", $out );
		$this->assertStringContainsString( ";\n", $out );
	}

	#[Test]
	public function prune_css_removes_unallowed_presets_and_helpers(): void {
		$css = "--wp--preset--color--base-0: #000; --wp--preset--color--vivid-cyan-blue: #0693e3; .has-base-0-color{color:var(--wp--preset--color--base-0);} .has-vivid-cyan-blue-color{color:var(--wp--preset--color--vivid-cyan-blue);} .has-vivid-cyan-blue-background-color{background:var(--wp--preset--color--vivid-cyan-blue);} ";

		$theme_json = array(
			'settings' => array(
				'color' => array(
					'palette' => array(
						array( 'slug' => 'base-0', 'color' => '#000' ),
					),
				),
			),
		);

		$sync = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();

		$ref = new \ReflectionMethod( $sync, 'prune_css_to_theme_presets' );
		$ref->setAccessible( true );

		$out = $ref->invoke( $sync, $css, $theme_json );

		$this->assertStringContainsString( '--wp--preset--color--base-0', $out );
		$this->assertStringNotContainsString( '--wp--preset--color--vivid-cyan-blue', $out );
		$this->assertStringContainsString( '.has-base-0-color', $out );
		$this->assertStringNotContainsString( '.has-vivid-cyan-blue-color', $out );
	}

	#[Test]
	public function prune_css_removes_empty_lines_after_pruning(): void {
		$css = "/* header */\n\n:root {\n--wp--preset--color--vivid-cyan-blue: #0693e3;\n--wp--preset--color--base-0: #000;\n}\n\n.has-vivid-cyan-blue-color{color:var(--wp--preset--color--vivid-cyan-blue);}\n\n.has-base-0-color{color:var(--wp--preset--color--base-0);}\n";

		$theme_json = array(
			'settings' => array(
				'color' => array(
					'palette' => array(
						array( 'slug' => 'base-0', 'color' => '#000' ),
					),
				),
			),
		);

		$sync = \Mockery::mock( GlobalStylesSync::class )->makePartial()->shouldAllowMockingProtectedMethods();

		$ref = new \ReflectionMethod( $sync, 'prune_css_to_theme_presets' );
		$ref->setAccessible( true );

		$out = $ref->invoke( $sync, $css, $theme_json );

		// No triple-newline runs
		$this->assertStringNotContainsString( "\n\n\n", $out );

		// No lines that are only whitespace should remain
		$this->assertDoesNotMatchRegularExpression( '/^\s*$/m', $out );
	}
}
