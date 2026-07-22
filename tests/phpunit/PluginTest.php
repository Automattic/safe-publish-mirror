<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use ReflectionProperty;
use WP_UnitTestCase;

/**
 * @covers \Automattic\SafePublishMirror\Plugin
*/
class PluginTest extends WP_UnitTestCase {
	public function tear_down(): void {
		// Drop any Config singleton swapped in for a test so the next test
		// re-reads the constant bootstrap.php defines, and unhook anything init()
		// registered so it doesn't leak into other test classes.
		$this->set_config_singleton( null );
		remove_action( 'rest_api_init', [ Plugin::get_instance(), 'register_rest_api' ] );
		remove_action( 'admin_notices', [ Plugin::get_instance(), 'render_config_notice' ] );
		parent::tear_down();
	}

	public function test_construct(): void {
		$plugin = Plugin::get_instance();

		static::assertEquals( 10, has_action( 'init', [ $plugin, 'init' ] ) );
	}

	public function test_ready_config_wires_the_rest_api(): void {
		$this->set_config_singleton( new Config( [
			'connected_site_url' => 'https://peer.example',
			'sync_mode'          => 'export',
			'shared_secret'      => 'a-shared-secret-value-1234567890',
		] ) );

		Plugin::get_instance()->init();

		static::assertSame( 10, has_action( 'rest_api_init', [ Plugin::get_instance(), 'register_rest_api' ] ) );
		static::assertFalse( has_action( 'admin_notices', [ Plugin::get_instance(), 'render_config_notice' ] ) );
	}

	public function test_incomplete_config_registers_only_the_notice(): void {
		$this->set_config_singleton( new Config( [
			'connected_site_url' => 'https://peer.example',
			'sync_mode'          => 'import',
		] ) );

		Plugin::get_instance()->init();

		static::assertSame( 10, has_action( 'admin_notices', [ Plugin::get_instance(), 'render_config_notice' ] ) );
		static::assertFalse( has_action( 'rest_api_init', [ Plugin::get_instance(), 'register_rest_api' ] ) );
	}

	public function test_config_notice_lists_missing_fields(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_config_singleton( new Config( [
			'connected_site_url' => 'https://source.example',
			'sync_mode'          => 'import',
		] ) );

		$actual = $this->render_config_notice();

		static::assertStringContainsString( 'notice notice-warning', $actual );
		static::assertStringContainsString( 'missing required fields: shared_secret', $actual );
	}

	public function test_config_notice_reports_undefined_constant(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->set_config_singleton( new Config( null ) );

		$actual = $this->render_config_notice();

		static::assertStringContainsString( 'the ' . Config::CONSTANT_NAME . ' constant is not defined', $actual );
	}

	public function test_config_notice_hidden_from_non_admins(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->set_config_singleton( new Config( null ) );

		static::assertSame( '', $this->render_config_notice() );
	}

	private function render_config_notice(): string {
		ob_start();
		Plugin::get_instance()->render_config_notice();
		return (string) ob_get_clean();
	}

	/**
	 * @param Config|null $config
	 */
	private function set_config_singleton( ?Config $config ): void {
		$property = new ReflectionProperty( Config::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $config );
	}
}
