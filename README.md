# Safe Publish Mirror

A minimal, partner-built reconstruction of Automattic's Safe Publish plugin,
built against the VIP Integration Center SDK. It mirrors published content from
one WordPress site to another as **drafts**, over an HMAC-authenticated channel.

## How it works

All runtime settings come from one VIP-injected constant,
`VIP_SAFE_PUBLISH_MIRROR_CONFIG`:

| Key | Meaning |
| --- | --- |
| `connected_site_url` | The peer site: the source to pull from (`import`) or the allowed destination origin (`export`). |
| `sync_mode` | This site's role: `export` (serves content) or `import` (pulls content). |
| `shared_secret` | HMAC-SHA256 shared secret (VIP secret-sync populates it). |

Incomplete config never fatals — the endpoints stay disabled and an admin
notice names the missing fields.

**Export role (source).** Registers an HMAC-gated catalog endpoint
(`GET /wp-json/safe-publish-mirror/v1/catalog/posts`) and exposes author +
media metadata on `wp/v2` posts, but only to the authenticated peer. The
inbound authenticator also lets the peer read `context=edit` content.

**Import role (destination).** An import-role site gets a top-level
**Safe Publish Mirror** admin screen that lists the source's posts with their
local state (Available / Up to date) and a per-row **Import** button — import is
the only action. The same run is available from WP-CLI:

```sh
wp safe-publish-mirror import --limit=5 [--post-type=post]
```

Either way it lists the source catalog, fetches each post over `wp/v2`
(`context=edit&_embed`), sideloads referenced media, resolves the author by
email (never auto-creating a user), and inserts a draft. Re-importing the same
post updates the existing draft rather than duplicating it.

Authentication is HMAC-SHA256 only (timestamp + content-hash + signed origin +
action, with a replay window) — there is no Basic Auth path. Telemetry is
Tracks-only and carries bounded metadata: never secrets, content, or emails.

---

This repository was scaffolded from the WordPress VIP Integration Starter Kit;
the starter-kit guidance below still applies.

## Starter Kit

Welcome to WordPress VIP! This repository is a starting point for developing an integration and submitting for the Integrations Center.

It contains an example of fully configured VIP local and cloud development environments along with unit tests, end-to-end tests, static analysis and linting.

Utilizing these tools will allow you to submit the new versions of your integrations and us to deploy the code with confidence.

The kit implements the WordPress VIP integration requirements and doubles as a reference implementation: runtime config via a single VIP-provided constant, config fixtures, Tracks telemetry, and the required command surface (`composer test`, `composer run validate-integration`). See [/docs/vip-integration.md](/docs/vip-integration.md) for the operational details.

## Technology

We used tools that we consider the best technology in the industry with convenience in mind. These are the tools we use on a day-to-day basis to ensure code quality on WordPress VIP platform.

### Unit Tests

We utilize [PHPUnit 9](https://phpunit.de/index.html) for unit tests. For an example of a test suite please refer to [/tests/phpunit](tests/phpunit/) folder.

### End-to-end tests

For end-to-end tests we use [Playwright](https://playwright.dev/). Examples can be found in [/tests/e2e](/tests/e2e).

### Static analysis

[Psalm](https://psalm.dev/) is a free & open-source static analysis tool that helps you identify problems in your code.

Please note, for Psalm to work properly you will need to annotate your PHP code. For examples please refer to [/inc](/inc).

### Linting and coding standards.

Linting and coding standards are powered by [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) (commonly known as PHPCS) along with WordPress VIP and WordPress core rulesets.

For more information please refer to [linting doc](/docs/linting.md).

### GitHub Actions

CI runs on every push and pull request to `main`:

| Workflow | What it does |
| --- | --- |
| `unit-tests.yml` | PHPUnit across the VIP platform baseline (PHP 8.2–8.5 × WordPress 6.9.x/latest, single site and multisite). |
| `e2e.yml` | Playwright end-to-end tests against a real `vip dev-env` (WordPress 6.9 and 7.0). |
| `lint.yml` | PHPCS with the WordPress VIP rulesets. |
| `static-code-analysis.yml` | Psalm static analysis. |
| `codeql.yml` / `dependency-review.yml` | Security scanning of code and dependency changes. |

## Repository structure

⚠️ You may notice the repository contains several folders. These should not be removed as they constitute a complete WordPress VIP application. A brief description is available in [/docs/directories.md](/docs/directories.md)

For more information on how our codebase is structured, see https://docs.wpvip.com/technical-references/vip-codebase/. 

## Local installation and development

To fully leverage the starter kit you will need to have the following tools installed: [Composer](https://getcomposer.org/), [Node.js](https://nodejs.org/en), NPM (installed with Node.js), [Docker](https://www.docker.com/), [VIP-CLI](https://docs.wpvip.com/vip-cli/).

📝 While we usually recommend Docker Desktop we understand that it may be not possible to utilize it for your organization. The Starter Kit is compatible with alternative container runtimes like Colima and Rancher Desktop. For details please refer to [our documentation](https://docs.wpvip.com/vip-local-development-environment/requirements/#Alternatives-to-Docker-Desktop).

Assuming you have prerequisites installed, follow these steps to set up the local environment.

1. Clone the repository and make it your own.
2. Change the working directory to your repository.
3. Install Composer dependencies
```sh
composer install
```
4. Install Node.js dependencies
```sh
npm i
```
5. Rename the example prefix set (slug, namespace, constants) to your integration's names:
```sh
composer setup
```
6. Create and start a WPVIP local development instance:
```sh
vip dev-env create
vip dev-env start
```
7. Write code, write tests. Or the other way around! `composer test` runs both suites (the e2e half needs the dev-env from the previous step running — see [/docs/vip-integration.md](/docs/vip-integration.md)).

📝 For convenience, this repository contains a special configuration file [vip-dev-env.yml](/.wpvip/vip-dev-env.yml), feel free to tweak it to your needs. For more in-depth guide to VIP Local Development Environments please refer to [our documentation site](https://docs.wpvip.com/vip-local-development-environment/create/).

## Cloud-based development

We leverage GitHub Codespaces. There are no set up steps. On the first start of the codespace it will take a few minutes to build. Once the build runs you'll have a working environment. You can use either Web-based editor or local VSCode.

## Submitting your plugin to WordPress VIP Integrations Center

Once you're confident your code is ready for the prime time, please contact your Technology Partner Manager for the next steps.
