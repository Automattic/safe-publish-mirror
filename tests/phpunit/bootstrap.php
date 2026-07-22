<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// The VIP Telemetry API ships with the platform's MU plugins and is absent
// in bare PHPUnit runs; load a test double so the helper can be asserted.
require_once __DIR__ . '/stubs/class-vip-telemetry.php';

$_tests_dir = (string) getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI
	throw new Exception( "Could not find {$_tests_dir}/includes/functions.php" ); // NOSONAR
}

if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', 'empty' );
}

// Give access to tests_add_filter() function.
/** @psalm-suppress UnresolvableInclude */
require_once $_tests_dir . '/includes/functions.php';

if ( ! defined( 'VIP_SAFE_PUBLISH_MIRROR_CONFIG' ) ) {
	// Mirror the VIP platform: runtime config is defined before the plugin loads.
	define( 'VIP_SAFE_PUBLISH_MIRROR_CONFIG', require __DIR__ . '/../../fixtures/config-valid.php' );
}

function _manually_load_plugin(): void {
	require_once __DIR__ . '/../../safe-publish-mirror.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
/** @psalm-suppress UnresolvableInclude */
require_once $_tests_dir . '/includes/bootstrap.php';

// The ready, export-role fixture above makes the plugin wire its source REST API
// globally when WP fires `init` during bootstrap, registering an inbound
// authenticator bound to the fixture's peer on `rest_pre_dispatch`. The REST and
// Source_Fields unit tests construct their own instances with a hermetic
// authenticator, so unhook that global wiring — otherwise the plugin's
// authenticator rejects each test's signed request (origin mismatch) before the
// test's own authenticator can run.
remove_action( 'rest_api_init', [ \Automattic\SafePublishMirror\Plugin::get_instance(), 'register_rest_api' ] );

/**
 * @psalm-suppress InvalidGlobal
 * @var string
 */
global $wp_version;
echo 'WP Version: ', esc_html( $wp_version ), PHP_EOL;
