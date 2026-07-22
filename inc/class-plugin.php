<?php

namespace Automattic\SafePublishMirror;

final class Plugin {
	/** @var self|null */
	private static $instance;

	// @codeCoverageIgnoreStart
	// This code is executed in bootstrap.php, before PHPUnit initializes test coverage
	public static function get_instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'init' ] );
	}

	public function init(): void {
		$config = Config::get_instance();

		if ( ! $config->is_ready() ) {
			// Missing or incomplete runtime config must never fatal: disable the
			// config-dependent behavior and surface a diagnostic instead.
			add_action( 'admin_notices', [ $this, 'render_config_notice' ] );
			return;
		}

		// The source (export) role serves content over the REST API; the
		// destination (import) role pulls it via the admin screen and WP-CLI.
		add_action( 'rest_api_init', [ $this, 'register_rest_api' ] );

		if ( $config->is_import() ) {
			( new Import_Admin() )->register();

			if ( self::wp_cli_available() ) {
				\WP_CLI::add_command( 'safe-publish-mirror', new CLI_Command( $config ) );
			}
		}
	}
	// @codeCoverageIgnoreEnd

	/**
	 * Register the inbound authenticator and, on an export-role site, the
	 * catalog endpoint and the source REST fields.
	 */
	public function register_rest_api(): void {
		$config        = Config::get_instance();
		$authenticator = HMAC_Authenticator::from_config( $config );
		$authenticator->register();

		if ( $config->is_export() ) {
			( new REST_Controller( $authenticator ) )->register_routes();
			( new Source_Fields( $authenticator ) )->register();
		}
	}

	private static function wp_cli_available(): bool {
		return defined( 'WP_CLI' ) && true === constant( 'WP_CLI' );
	}

	public function render_config_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config  = Config::get_instance();
		$details = $config->is_available()
			? sprintf(
				/* translators: %s: comma-separated list of missing config fields */
				__( 'missing required fields: %s', 'safe-publish-mirror' ),
				implode( ', ', $config->missing_fields() )
			)
			: sprintf(
				/* translators: %s: name of the runtime config constant */
				__( 'the %s constant is not defined', 'safe-publish-mirror' ),
				Config::CONSTANT_NAME
			);

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: reason the configuration is incomplete */
					__( 'Safe Publish Mirror setup is incomplete (%s). Its REST API endpoints are disabled until the configuration is completed in the VIP Dashboard.', 'safe-publish-mirror' ),
					$details
				)
			)
		);
	}
}
