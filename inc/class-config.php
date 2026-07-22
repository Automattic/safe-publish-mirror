<?php

namespace Automattic\SafePublishMirror;

/**
 * Centralized reader for the runtime configuration the VIP platform injects
 * as the VIP_SAFE_PUBLISH_MIRROR_CONFIG PHP constant (a plain associative
 * array, defined before the plugin is loaded).
 *
 * Missing or invalid required fields must never cause a fatal error: callers
 * check is_ready() and disable the affected behavior instead.
 *
 * Schema (locked in Chunk 0):
 *
 *   connected_site_url  string  The connected peer site. On `import` it is the
 *                               source to pull from; on `export` it is the
 *                               allowed destination origin.
 *   sync_mode           enum    Role of *this* site: 'export' | 'import'.
 *   shared_secret       string  HMAC-SHA256 shared secret. SECRET.
 */
final class Config {
	public const CONSTANT_NAME = 'VIP_SAFE_PUBLISH_MIRROR_CONFIG';

	public const SYNC_MODE_EXPORT = 'export';
	public const SYNC_MODE_IMPORT = 'import';

	public const REQUIRED_FIELDS = [ 'connected_site_url', 'sync_mode', 'shared_secret' ];

	/**
	 * Keys whose values are secrets and must never be rendered in the admin UI
	 * (or logs). Surface that a value is set without exposing it.
	 */
	public const SENSITIVE_FIELDS = [ 'shared_secret' ];

	/** @var self|null */
	private static $instance;

	/** @var array<string, mixed> */
	private array $config = [];

	private bool $available = false;

	public static function get_instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self( defined( self::CONSTANT_NAME ) ? constant( self::CONSTANT_NAME ) : null );
		}

		return self::$instance;
	}

	/**
	 * @param mixed $raw Raw value of the config constant. Anything other than
	 *                   an array is treated as "no usable config".
	 */
	public function __construct( $raw ) {
		if ( is_array( $raw ) ) {
			/** @psalm-var array<string, mixed> $raw */
			$this->config    = $raw;
			$this->available = true;
		}
	}

	/**
	 * Whether the config constant was defined and contained an array.
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Required fields that are missing or unusable. An incomplete setup is a
	 * normal state: an integration can be enabled before the customer has
	 * saved all required values in the VIP Dashboard.
	 *
	 * "Required" means present and usable: a field must be a non-empty string.
	 * A missing key, a non-string value (false, 0, [] from a misparsed config),
	 * or a blank/whitespace-only string all count as missing rather than
	 * silently enabling a half-configured integration. `sync_mode` must also be
	 * one of the two supported roles, or it is treated as missing.
	 *
	 * @return list<string>
	 */
	public function missing_fields(): array {
		$missing = [];

		foreach ( self::REQUIRED_FIELDS as $field ) {
			/** @var mixed $value */
			$value = $this->config[ $field ] ?? null;

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				$missing[] = $field;
				continue;
			}

			if ( 'sync_mode' === $field && ! in_array( $value, self::sync_modes(), true ) ) {
				$missing[] = $field;
			}
		}

		return $missing;
	}

	/**
	 * Whether the config is usable: constant present and all required fields set.
	 */
	public function is_ready(): bool {
		return $this->available && [] === $this->missing_fields();
	}

	/**
	 * The connected peer site URL, without a trailing slash.
	 */
	public function connected_site_url(): string {
		return untrailingslashit( (string) $this->get( 'connected_site_url', '' ) );
	}

	/**
	 * The role of this site: 'export' or 'import' (empty string if unset).
	 */
	public function sync_mode(): string {
		return (string) $this->get( 'sync_mode', '' );
	}

	/**
	 * The HMAC shared secret. Never render this in UI or logs.
	 */
	public function shared_secret(): string {
		return (string) $this->get( 'shared_secret', '' );
	}

	/**
	 * Whether this site serves content (the source in a sync pair).
	 */
	public function is_export(): bool {
		return self::SYNC_MODE_EXPORT === $this->sync_mode();
	}

	/**
	 * Whether this site pulls content (the destination in a sync pair).
	 */
	public function is_import(): bool {
		return self::SYNC_MODE_IMPORT === $this->sync_mode();
	}

	/**
	 * @param mixed $default_value
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		return $this->config[ $key ] ?? $default_value;
	}

	/**
	 * The injected config as key => display string, with sensitive values
	 * masked. Lets an integration surface where its runtime settings come from
	 * (the VIP-injected constant) without leaking secrets or reinventing the
	 * wheel per-site.
	 *
	 * @return array<string, string>
	 */
	public function for_display(): array {
		$display = [];

		foreach ( array_keys( $this->config ) as $key ) {
			$display[ $key ] = in_array( $key, self::SENSITIVE_FIELDS, true )
				? '••••••••'
				: self::stringify( $this->config[ $key ] );
		}

		return $display;
	}

	/**
	 * @return list<string>
	 */
	private static function sync_modes(): array {
		return [ self::SYNC_MODE_EXPORT, self::SYNC_MODE_IMPORT ];
	}

	/**
	 * @param mixed $value
	 */
	private static function stringify( $value ): string {
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}
}
