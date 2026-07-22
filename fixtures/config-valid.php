<?php
/**
 * Fully configured example of the VIP_SAFE_PUBLISH_MIRROR_CONFIG runtime
 * config constant, for a site acting as the content *source* (export role).
 * Mock values only — never put real credentials in fixtures.
 */

return [
	'connected_site_url' => 'https://destination.example',
	'sync_mode'          => 'export',
	'shared_secret'      => 'mock-shared-secret-abc123456789',
];
