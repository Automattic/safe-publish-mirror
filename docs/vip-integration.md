# VIP Integration

Safe Publish Mirror is a partner-built reconstruction of Automattic's Safe
Publish plugin, built from the [VIP Integrations Starter Kit](https://github.com/Automattic/vip-integrations-starter-kit)
and against the VIP Integration Center SDK. It mirrors published content from
one WordPress site to another as **drafts**, over an HMAC-authenticated
channel. It demonstrates the patterns WordPress VIP requires of partner
integrations: a single runtime config constant read through a central `Config`
class, graceful degradation when required config is missing, and Tracks-only
telemetry through the VIP Telemetry API.

## Running and testing locally

1. `composer install && npm ci`
2. `vip dev-env create && vip dev-env start`
3. `composer test`

> **Prerequisite:** `composer test` runs PHPUnit **and** the Playwright e2e
> suite. The e2e half needs the `vip dev-env` from step 2 up and reachable —
> without it, `composer test` fails on the e2e stage. That is an environment
> gap, not a broken kit; run `composer test:unit` for PHPUnit alone.

PHPUnit needs the WordPress test library (`WP_TESTS_DIR`); inside the dev-env
container (`vip dev-env shell`) it is preconfigured. To run PHPUnit from the
host against the dev-env database, point `WP_TESTS_DIR` at the bundled
`wp-phpunit` library and `WP_PHPUNIT__TESTS_CONFIG` at a local test config that
targets the dev-env MySQL.

## Build, Test, And Validate Commands

| Purpose | Command |
| --- | --- |
| Install PHP dependencies | `composer install` |
| Install Node dependencies | `npm ci` |
| Build release assets | not applicable — this integration ships no compiled JS/CSS assets (`npm run build` documents this) |
| Tests | `composer test` (PHPUnit + Playwright e2e) |
| Unit tests only | `composer test:unit` |
| Integration validation | `composer run validate-integration` (placeholder until VIP publishes the validator) |

## Roles and how it works

Every runtime setting comes from one VIP-injected constant. The site's **role**
is set by `sync_mode`:

- **`export` (source).** Registers an HMAC-gated catalog endpoint
  (`GET /wp-json/safe-publish-mirror/v1/catalog/posts`) and exposes author and
  media metadata on `wp/v2` posts, but only to the authenticated peer. The
  inbound authenticator also lets the peer read `context=edit` content.
- **`import` (destination).** Adds a top-level **Safe Publish Mirror** admin
  screen that lists the source's posts and imports them **as drafts**. The same
  run is available from WP-CLI:

  ```sh
  wp safe-publish-mirror import --limit=5 [--post-type=post]
  ```

  Either way it lists the source catalog, fetches each post over `wp/v2`
  (`context=edit&_embed`), sideloads referenced media, resolves the author by
  email (never auto-creating a user), and inserts a draft. Re-importing the
  same post updates the existing draft rather than duplicating it.

Authentication is HMAC-SHA256 only (timestamp + content-hash + signed origin +
action, with a replay window) — there is no Basic Auth path.

## Runtime Config

Config constant: `VIP_SAFE_PUBLISH_MIRROR_CONFIG`

The VIP platform defines the constant (a plain PHP associative array) before
the plugin loads. All reads go through `Automattic\SafePublishMirror\Config`.

Required values:

- `connected_site_url`: The connected peer site. On `import` it is the source
  to pull from; on `export` it is the allowed destination origin.
- `sync_mode`: This site's role — `export` (serves content) or `import` (pulls
  content).
- `shared_secret`: HMAC-SHA256 shared secret. **Secret** — masked in the admin
  UI and logs; VIP secret-sync populates it.

There are no optional fields.

Example valid local mock config (destination / import role):

```php
define( 'VIP_SAFE_PUBLISH_MIRROR_CONFIG', [
	'connected_site_url' => 'https://source.example',
	'sync_mode'          => 'import',
	'shared_secret'      => 'mock-shared-secret-abc123456789',
] );
```

Example incomplete config (setup in progress — the required secret is missing):

```php
define( 'VIP_SAFE_PUBLISH_MIRROR_CONFIG', [
	'connected_site_url' => 'https://source.example',
	'sync_mode'          => 'import',
] );
```

With incomplete config the plugin **must not fatal**: it disables the catalog
endpoint / import behavior and shows an admin notice naming the missing fields.
See [`fixtures/`](../fixtures/README.md) for all mocked states and where they
are wired in.

## Telemetry

Telemetry uses the helper in `inc/class-telemetry.php`, which wraps the VIP
Telemetry API (Tracks events only, no Stats) behind a `class_exists` guard so
environments without VIP MU plugins no-op. Event names are prefixed with
`safe_publish_mirror_`, and every event carries `plugin_version` as a default
property. Never include secrets, raw content, URLs, email addresses, or
customer credentials in event properties.

| Name | Type | Trigger | Properties |
| --- | --- | --- | --- |
| `safe_publish_mirror_catalog_listed` | Tracks | The source catalog endpoint serves a page of posts to the authenticated peer. | `post_type`, `count` |
| `safe_publish_mirror_import_completed` | Tracks | A destination import run finishes. | `created`, `updated`, `failed` |

## WordPress surfaces

The manifest ([`vip-handoff.yaml`](../vip-handoff.yaml)) is the authoritative
list. In summary, the integration introduces:

- **REST route:** `GET /wp-json/safe-publish-mirror/v1/catalog/posts` (export
  role, HMAC-gated). It also registers `author_email` and embedded-media
  fields on the existing `wp/v2` post routes for the authenticated peer.
- **WP-CLI command:** `wp safe-publish-mirror import` (import role).
- **Admin page:** a top-level **Safe Publish Mirror** menu (import role,
  `manage_options`).
- **Post meta** on imported drafts: `safe_publish_mirror_source_post_id`,
  `safe_publish_mirror_source_site_url`, `safe_publish_mirror_source_link`.
- No cron events, options, custom tables, or frontend scripts.

## Troubleshooting

| Symptom | Likely cause | What to check |
| --- | --- | --- |
| Admin notice "Safe Publish Mirror: missing required fields" | Required config not yet saved | Confirm `connected_site_url`, `sync_mode`, and `shared_secret` are all set in the VIP Dashboard; the plugin disables sync until they are. |
| No **Safe Publish Mirror** admin menu / no WP-CLI command | Site is not in the import role, or config is incomplete | Both the admin screen and the WP-CLI command register only when `sync_mode = import` **and** config is ready. |
| Catalog endpoint returns `401`/`403` | HMAC handshake failed | Confirm both sites share the same `shared_secret`, that each site's `connected_site_url` points at the other, and that clocks are within the replay window. |
| Import shows posts as "Available" but import fails | Author or media resolution failed | The author is resolved by email via `get_user_by`; a missing user yields a `WP_Error` (no auto-create). Ensure the author exists on the destination and that source media URLs are reachable. |
| Imported content is published, not draft | Not possible by design | Every imported post is created with `post_status = draft`; there is no direct-publish path. |
| Telemetry events not appearing | VIP Telemetry API absent | Tracks events no-op without the VIP MU-plugins Telemetry API (e.g. bare PHPUnit). This is expected off-platform. |

## Support

- Customer / administrator documentation: see the `documentation.public_url`
  in [`vip-handoff.yaml`](../vip-handoff.yaml).
- Config schema and WordPress surfaces: this document and the manifest.
- Conformance status: run `a8c-integration validate` (or
  `composer run validate-integration`).
