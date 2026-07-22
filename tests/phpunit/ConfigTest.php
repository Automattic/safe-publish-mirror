<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_UnitTestCase;

/**
 * @covers \Automattic\SafePublishMirror\Config
 */
class ConfigTest extends WP_UnitTestCase {
	private const FIXTURES_DIR = __DIR__ . '/../../fixtures';

	public function test_export_fixture_is_ready(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-valid.php' );

		static::assertTrue( $config->is_available() );
		static::assertTrue( $config->is_ready() );
		static::assertSame( [], $config->missing_fields() );
		static::assertTrue( $config->is_export() );
		static::assertFalse( $config->is_import() );
		static::assertSame( 'https://destination.example', $config->connected_site_url() );
	}

	public function test_import_fixture_is_ready(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-minimal.php' );

		static::assertTrue( $config->is_ready() );
		static::assertTrue( $config->is_import() );
		static::assertFalse( $config->is_export() );
		// A key outside the schema falls back to the supplied default.
		static::assertSame( 'fallback', $config->get( 'not_a_field', 'fallback' ) );
	}

	public function test_connected_site_url_is_untrailingslashed(): void {
		$config = new Config( [
			'connected_site_url' => 'https://source.example/',
			'sync_mode'          => 'import',
			'shared_secret'      => 'a-shared-secret-value',
		] );

		static::assertSame( 'https://source.example', $config->connected_site_url() );
	}

	public function test_incomplete_fixture_degrades_gracefully(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-incomplete.php' );

		static::assertTrue( $config->is_available() );
		static::assertFalse( $config->is_ready() );
		static::assertSame( [ 'shared_secret' ], $config->missing_fields() );
	}

	public function test_invalid_fixture_is_not_available(): void {
		$config = new Config( require self::FIXTURES_DIR . '/config-invalid.php' );

		static::assertFalse( $config->is_available() );
		static::assertFalse( $config->is_ready() );
		static::assertSame( 'default', $config->get( 'connected_site_url', 'default' ) );
	}

	public function test_empty_required_field_counts_as_missing(): void {
		$config = new Config( [
			'connected_site_url' => '',
			'sync_mode'          => 'import',
			'shared_secret'      => 'a-shared-secret-value',
		] );

		static::assertFalse( $config->is_ready() );
		static::assertSame( [ 'connected_site_url' ], $config->missing_fields() );
	}

	public function test_unrecognized_sync_mode_counts_as_missing(): void {
		$config = new Config( [
			'connected_site_url' => 'https://source.example',
			'sync_mode'          => 'bidirectional',
			'shared_secret'      => 'a-shared-secret-value',
		] );

		static::assertFalse( $config->is_ready() );
		static::assertSame( [ 'sync_mode' ], $config->missing_fields() );
		static::assertFalse( $config->is_export() );
		static::assertFalse( $config->is_import() );
	}

	/**
	 * A required field that is present but not a usable string must degrade,
	 * not enable the integration. Guards against "required means merely set".
	 *
	 * @dataProvider provide_unusable_values
	 * @param mixed $value
	 */
	public function test_unusable_required_field_counts_as_missing( $value ): void {
		$config = new Config( [
			'connected_site_url' => 'https://source.example',
			'sync_mode'          => 'import',
			'shared_secret'      => $value,
		] );

		static::assertFalse( $config->is_ready() );
		static::assertSame( [ 'shared_secret' ], $config->missing_fields() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_unusable_values(): array {
		return [
			'boolean false' => [ false ],
			'integer zero'  => [ 0 ],
			'whitespace'    => [ '   ' ],
			'empty array'   => [ [] ],
		];
	}

	public function test_undefined_constant_is_not_available(): void {
		$config = new Config( null );

		static::assertFalse( $config->is_available() );
		static::assertFalse( $config->is_ready() );
	}

	public function test_singleton_reads_the_constant(): void {
		// bootstrap.php defines the constant from the valid fixture.
		static::assertTrue( Config::get_instance()->is_ready() );
		static::assertSame( Config::get_instance(), Config::get_instance() );
	}

	public function test_for_display_masks_secrets_and_stringifies_values(): void {
		$config = new Config( [
			'connected_site_url' => 'https://source.example',
			'sync_mode'          => 'import',
			'shared_secret'      => 'super-secret-value',
			'retries'            => 3,
		] );

		$display = $config->for_display();

		static::assertSame( 'https://source.example', $display['connected_site_url'] );
		static::assertSame( '3', $display['retries'] );
		static::assertNotSame( 'super-secret-value', $display['shared_secret'] );
		static::assertStringNotContainsString( 'super-secret-value', implode( '', $display ) );
	}
}
