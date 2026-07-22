<?php

defined( 'ABSPATH' ) || die();

if ( ! defined( 'WP_TESTS_DOMAIN' ) && function_exists( 'wpcom_vip_load_plugin' ) ) {
	if ( ! defined( 'VIP_SAFE_PUBLISH_MIRROR_CONFIG' ) ) {
		// Mirror the VIP platform: runtime config is defined before the plugin loads.
		// A git-ignored fixtures/config-local.php overrides the committed fixture —
		// handy for local secrets and experiments (see fixtures/README.md).
		$vip_safe_publish_mirror_fixtures = WP_CONTENT_DIR . '/plugins/safe-publish-mirror/fixtures';
		define(
			'VIP_SAFE_PUBLISH_MIRROR_CONFIG',
			file_exists( $vip_safe_publish_mirror_fixtures . '/config-local.php' )
				? require $vip_safe_publish_mirror_fixtures . '/config-local.php'
				: require $vip_safe_publish_mirror_fixtures . '/config-valid.php'
		);
		unset( $vip_safe_publish_mirror_fixtures );
	}

	wpcom_vip_load_plugin( 'safe-publish-mirror/safe-publish-mirror.php' );
}
