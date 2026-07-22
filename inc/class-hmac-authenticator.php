<?php

namespace Automattic\SafePublishMirror;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Inbound HMAC verifier for the source (export) site.
 *
 * Hooks `rest_pre_dispatch` and validates the signed Safe Publish headers on:
 *
 *   - GET/HEAD requests to `/wp/v2/`      — so the destination can read
 *     `context=edit` content; on success the request is granted the edit
 *     capabilities WordPress needs to return raw fields.
 *   - any request to `/safe-publish-mirror/v1/` — the catalog endpoint, which
 *     checks is_authenticated() in its own permission callback.
 *
 * Requests without Safe Publish headers pass straight through to WordPress'
 * normal handling. Requests that carry the headers but fail validation are
 * rejected with a WP_Error. This is the sole auth path — no Basic Auth.
 */
final class HMAC_Authenticator {
	private const REPLAY_WINDOW_DEFAULT = 300;
	private const REPLAY_WINDOW_MIN     = 30;
	private const REPLAY_WINDOW_MAX     = 900;

	/**
	 * Primitive capabilities granted to an authenticated request so the wp/v2
	 * controllers return editable content.
	 *
	 * @var list<string>
	 */
	private const GRANTED_CAPS = [
		'read',
		'edit_posts',
		'edit_others_posts',
		'edit_private_posts',
		'edit_published_posts',
		'read_private_posts',
		'edit_pages',
		'edit_others_pages',
		'edit_private_pages',
		'edit_published_pages',
		'read_private_pages',
		'manage_categories',
		'upload_files',
	];

	/**
	 * Object-level meta capabilities short-circuited to "granted" while a
	 * request is authenticated.
	 *
	 * @var list<string>
	 */
	private const META_CAPS = [
		'edit_post',
		'read_post',
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'edit_private_posts',
		'read_private_posts',
	];

	private string $shared_secret;
	private string $connected_site_url;
	private bool $authenticated = false;

	public function __construct( string $shared_secret, string $connected_site_url ) {
		$this->shared_secret      = $shared_secret;
		$this->connected_site_url = untrailingslashit( $connected_site_url );
	}

	public static function from_config( Config $config ): self {
		return new self( $config->shared_secret(), $config->connected_site_url() );
	}

	/**
	 * Register the inbound auth filter. Called on rest_api_init.
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'authenticate_request' ], 10, 3 );
	}

	public function is_authenticated(): bool {
		return $this->authenticated;
	}

	/**
	 * @param WP_REST_Response|WP_Error|null $result  Short-circuit response, if any.
	 * @param WP_REST_Server|null            $_server Server instance (unused).
	 * @param mixed                          $request Inbound request (a WP_REST_Request in practice).
	 * @return WP_REST_Response|WP_Error|null Original result on pass-through/success, WP_Error on failure.
	 */
	public function authenticate_request( $result, $_server, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$route = (string) $request->get_route();

		$is_wp_route      = 0 === strpos( $route, '/wp/v2/' );
		$is_catalog_route = 0 === strpos( $route, '/' . REST_Controller::NAMESPACE . '/' );

		if ( ! $is_wp_route && ! $is_catalog_route ) {
			return $result;
		}

		if ( $is_wp_route ) {
			$method = strtoupper( (string) $request->get_method() );
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return $result;
			}
		}

		$headers = $request->get_headers();

		// No Safe Publish headers: leave the request for WordPress to handle.
		if ( empty( $headers[ HMAC::KEY_TIMESTAMP ] ) || empty( $headers[ HMAC::KEY_SIGNATURE ] ) ) {
			return $result;
		}

		$error = $this->validate( $request );
		if ( $error instanceof WP_Error ) {
			return $error;
		}

		$this->authenticated = true;

		if ( $is_wp_route ) {
			$this->grant_authenticated_context();
		}

		return $result;
	}

	/**
	 * Validate the signed headers. Returns null on success, WP_Error on failure.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return WP_Error|null
	 */
	private function validate( WP_REST_Request $request ): ?WP_Error {
		/** @var array<string, mixed> $headers */
		$headers = $request->get_headers();

		if ( '' === $this->shared_secret ) {
			return new WP_Error( 'safe_publish_mirror_no_secret', 'Shared secret not configured', [ 'status' => 500 ] );
		}

		if ( '' === $this->connected_site_url ) {
			return new WP_Error( 'safe_publish_mirror_no_connected_site', 'Connected site URL not configured', [ 'status' => 500 ] );
		}

		$timestamp = (int) ( $headers[ HMAC::KEY_TIMESTAMP ][0] ?? 0 );
		if ( abs( time() - $timestamp ) > $this->replay_window() ) {
			return new WP_Error( 'safe_publish_mirror_expired', 'Request timestamp outside the allowed window', [ 'status' => 401 ] );
		}

		$received_hash = (string) ( $headers[ HMAC::KEY_CONTENT_HASH ][0] ?? '' );
		if ( '' === $received_hash || ! hash_equals( HMAC::content_hash( (string) $request->get_body() ), $received_hash ) ) {
			return new WP_Error( 'safe_publish_mirror_content_hash', 'Content hash verification failed', [ 'status' => 401 ] );
		}

		$origin = untrailingslashit( (string) ( $headers[ HMAC::KEY_SITE_URL ][0] ?? '' ) );
		if ( '' === $origin || $origin !== $this->connected_site_url ) {
			return new WP_Error( 'safe_publish_mirror_origin', 'Request origin does not match the connected site URL', [ 'status' => 403 ] );
		}

		$action    = (string) ( $headers[ HMAC::KEY_ACTION ][0] ?? '' );
		$signature = (string) ( $headers[ HMAC::KEY_SIGNATURE ][0] ?? '' );
		$expected  = HMAC::sign(
			HMAC::signature_string(
				(string) $request->get_method(),
				(string) $request->get_route(),
				$timestamp,
				$received_hash,
				$origin,
				$action
			),
			$this->shared_secret
		);

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'safe_publish_mirror_signature', 'Invalid authentication signature', [ 'status' => 401 ] );
		}

		return null;
	}

	/**
	 * Grant the capabilities WordPress needs to serve context=edit for the
	 * duration of this request, then drop them once the dispatch completes so
	 * the elevation cannot leak into a later request in the same PHP process.
	 */
	private function grant_authenticated_context(): void {
		add_filter( 'user_has_cap', [ $this, 'grant_caps' ] );
		add_filter( 'map_meta_cap', [ $this, 'map_meta_caps' ], 10, 2 );
		add_filter( 'rest_post_dispatch', [ $this, 'tear_down' ], PHP_INT_MAX );
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @return array<string, bool>
	 */
	public function grant_caps( array $allcaps ): array {
		if ( ! $this->authenticated ) {
			return $allcaps;
		}

		foreach ( self::GRANTED_CAPS as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * @param list<string> $caps
	 * @return list<string>
	 */
	public function map_meta_caps( array $caps, string $cap ): array {
		if ( $this->authenticated && in_array( $cap, self::META_CAPS, true ) ) {
			return [ 'exist' ];
		}

		return $caps;
	}

	/**
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error $result
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error
	 */
	public function tear_down( $result ) {
		remove_filter( 'user_has_cap', [ $this, 'grant_caps' ] );
		remove_filter( 'map_meta_cap', [ $this, 'map_meta_caps' ], 10 );
		remove_filter( 'rest_post_dispatch', [ $this, 'tear_down' ], PHP_INT_MAX );
		$this->authenticated = false;

		return $result;
	}

	/**
	 * Replay window in seconds, filterable but clamped so protection holds.
	 */
	private function replay_window(): int {
		/** @var mixed $filtered */
		$filtered = apply_filters( 'safe_publish_mirror_auth_max_time_diff', self::REPLAY_WINDOW_DEFAULT );
		return max( self::REPLAY_WINDOW_MIN, min( (int) $filtered, self::REPLAY_WINDOW_MAX ) );
	}
}
