<?php
/**
 * Plugin Name: Safe Publish Mirror
 * Description: Reference implementation of a WordPress VIP partner integration, built from the VIP Integrations Starter Kit.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author: Stark Industries
 * License: MIT
 * Text Domain: safe-publish-mirror
 */

use Automattic\SafePublishMirror\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'VIP_SAFE_PUBLISH_MIRROR_LOADED' ) ) {
	return;
}

define( 'VIP_SAFE_PUBLISH_MIRROR_LOADED', true );
define( 'VIP_SAFE_PUBLISH_MIRROR_VERSION', '1.0.0' );
define( 'VIP_SAFE_PUBLISH_MIRROR_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

Plugin::get_instance();
