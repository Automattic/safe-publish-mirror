<?php
/**
 * Incomplete setup state: the integration is enabled but the customer has not
 * saved all required fields in the VIP Dashboard yet — here the secret that
 * VIP secret-sync populates is still missing. The plugin must not fatal — it
 * disables the affected behavior and surfaces a diagnostic.
 */

return [
	'connected_site_url' => 'https://source.example',
	'sync_mode'          => 'import',
];
