<?php

namespace Automattic\SafePublishMirror;

/**
 * Shared HMAC-SHA256 signing primitives.
 *
 * Both sides of a sync pair build the exact same signed string here, so the
 * outbound signer (destination) and the inbound verifier (source) can never
 * drift out of agreement. This is the sole authentication mechanism between
 * the two sites — there is no Basic Auth fallback.
 *
 * Signed string:
 *
 *   METHOD|REST_ROUTE|TIMESTAMP|CONTENT_HASH|ORIGIN_SITE_URL|ACTION
 *
 * REST_ROUTE is the path after `/wp-json` (no query string, no host), matching
 * what WP_REST_Request::get_route() returns on the receiver. ORIGIN_SITE_URL
 * is the *sender's* home URL — the receiver checks it equals the connected
 * site URL it is configured to trust.
 */
final class HMAC {
	/** Outbound HTTP header names. */
	public const HEADER_TIMESTAMP    = 'X-Safe-Publish-Timestamp';
	public const HEADER_CONTENT_HASH = 'X-Safe-Publish-Content-Hash';
	public const HEADER_SIGNATURE    = 'X-Safe-Publish-Signature';
	public const HEADER_SITE_URL     = 'X-Safe-Publish-Site-URL';
	public const HEADER_ACTION       = 'X-Safe-Publish-Action';

	/**
	 * Inbound header keys as WordPress normalizes them in
	 * WP_REST_Request::get_headers() (lowercased, dashes to underscores).
	 */
	public const KEY_TIMESTAMP    = 'x_safe_publish_timestamp';
	public const KEY_CONTENT_HASH = 'x_safe_publish_content_hash';
	public const KEY_SIGNATURE    = 'x_safe_publish_signature';
	public const KEY_SITE_URL     = 'x_safe_publish_site_url';
	public const KEY_ACTION       = 'x_safe_publish_action';

	/**
	 * SHA-256 hash of a request body, used for tamper detection.
	 */
	public static function content_hash( string $body ): string {
		return hash( 'sha256', $body );
	}

	/**
	 * The canonical string that gets signed. Kept in one place so signer and
	 * verifier can't disagree on field order or separators.
	 */
	public static function signature_string(
		string $method,
		string $rest_route,
		int $timestamp,
		string $content_hash,
		string $origin_site_url,
		string $action
	): string {
		return strtoupper( $method )
			. '|' . $rest_route
			. '|' . $timestamp
			. '|' . $content_hash
			. '|' . untrailingslashit( $origin_site_url )
			. '|' . $action;
	}

	/**
	 * Compute the HMAC-SHA256 signature of a canonical string.
	 */
	public static function sign( string $signature_string, string $shared_secret ): string {
		return hash_hmac( 'sha256', $signature_string, $shared_secret );
	}

	/**
	 * Build the signed request headers for an outbound cross-site call.
	 *
	 * @param string $method          HTTP method.
	 * @param string $rest_route      REST route path (e.g. '/wp/v2/posts/12'), no host or query.
	 * @param string $body            Request body (empty for GET).
	 * @param string $action          Declared Request_Actions intent.
	 * @param string $shared_secret   The shared HMAC secret.
	 * @param string $origin_site_url This site's home URL.
	 * @return array<string, string> Headers keyed by HTTP header name.
	 */
	public static function build_request_headers(
		string $method,
		string $rest_route,
		string $body,
		string $action,
		string $shared_secret,
		string $origin_site_url
	): array {
		$timestamp    = time();
		$content_hash = self::content_hash( $body );
		$origin       = untrailingslashit( $origin_site_url );
		$signature    = self::sign(
			self::signature_string( $method, $rest_route, $timestamp, $content_hash, $origin, $action ),
			$shared_secret
		);

		return [
			self::HEADER_TIMESTAMP    => (string) $timestamp,
			self::HEADER_CONTENT_HASH => $content_hash,
			self::HEADER_SIGNATURE    => $signature,
			self::HEADER_SITE_URL     => $origin,
			self::HEADER_ACTION       => $action,
		];
	}
}
