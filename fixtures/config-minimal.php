<?php
/**
 * A valid config for a site acting as the content *destination* (import role).
 * The schema has no optional fields, so this is the same three required keys
 * as config-valid.php in the other role — it exists to exercise the import
 * side and Config::get()'s fallback for keys outside the schema.
 */

return [
	'connected_site_url' => 'https://source.example',
	'sync_mode'          => 'import',
	'shared_secret'      => 'mock-shared-secret-abc123456789',
];
