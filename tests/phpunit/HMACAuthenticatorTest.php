<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \Automattic\SafePublishMirror\HMAC_Authenticator
 */
class HMACAuthenticatorTest extends WP_UnitTestCase {
	private const SECRET = 'a-shared-secret-value-1234567890';
	private const PEER   = 'https://source.example';

	private function make_authenticator(): HMAC_Authenticator {
		return new HMAC_Authenticator( self::SECRET, self::PEER );
	}

	/**
	 * Build a request signed as if it came from the connected peer site.
	 */
	private function signed_request(
		string $method,
		string $route,
		string $action = Request_Actions::IMPORT,
		string $origin = self::PEER,
		string $secret = self::SECRET
	): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$headers = HMAC::build_request_headers( $method, $route, '', $action, $secret, $origin );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	public function test_valid_signature_authenticates_and_passes_through(): void {
		$auth   = $this->make_authenticator();
		$result = $auth->authenticate_request( null, null, $this->signed_request( 'GET', '/wp/v2/posts/1' ) );

		static::assertNull( $result );
		static::assertTrue( $auth->is_authenticated() );

		$auth->tear_down( new \WP_REST_Response() );
	}

	public function test_unsigned_request_passes_through_unauthenticated(): void {
		$auth    = $this->make_authenticator();
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/1' );

		static::assertNull( $auth->authenticate_request( null, null, $request ) );
		static::assertFalse( $auth->is_authenticated() );
	}

	public function test_unrelated_route_is_ignored(): void {
		$auth    = $this->make_authenticator();
		$request = $this->signed_request( 'GET', '/oembed/1.0/embed' );

		static::assertNull( $auth->authenticate_request( null, null, $request ) );
		static::assertFalse( $auth->is_authenticated() );
	}

	public function test_write_method_on_wp_route_is_ignored(): void {
		$auth    = $this->make_authenticator();
		$request = $this->signed_request( 'POST', '/wp/v2/posts' );

		static::assertNull( $auth->authenticate_request( null, null, $request ) );
		static::assertFalse( $auth->is_authenticated() );
	}

	public function test_tampered_signature_is_rejected(): void {
		$auth    = $this->make_authenticator();
		$request = $this->signed_request( 'GET', '/wp/v2/posts/1' );
		$request->set_header( HMAC::HEADER_SIGNATURE, 'deadbeef' );

		$result = $auth->authenticate_request( null, null, $request );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'safe_publish_mirror_signature', $result->get_error_code() );
		static::assertFalse( $auth->is_authenticated() );
	}

	public function test_wrong_secret_is_rejected(): void {
		$auth   = $this->make_authenticator();
		$result = $auth->authenticate_request( null, null, $this->signed_request( 'GET', '/wp/v2/posts/1', Request_Actions::IMPORT, self::PEER, 'the-wrong-secret' ) );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'safe_publish_mirror_signature', $result->get_error_code() );
	}

	public function test_foreign_origin_is_rejected(): void {
		$auth   = $this->make_authenticator();
		$result = $auth->authenticate_request( null, null, $this->signed_request( 'GET', '/wp/v2/posts/1', Request_Actions::IMPORT, 'https://attacker.example' ) );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'safe_publish_mirror_origin', $result->get_error_code() );
		static::assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_expired_timestamp_is_rejected(): void {
		$auth    = $this->make_authenticator();
		$request = $this->signed_request( 'GET', '/wp/v2/posts/1' );
		// Re-sign with a stale timestamp so the signature stays valid but the window fails.
		$stale     = time() - 5000;
		$hash      = HMAC::content_hash( '' );
		$signature = HMAC::sign(
			HMAC::signature_string( 'GET', '/wp/v2/posts/1', $stale, $hash, self::PEER, Request_Actions::IMPORT ),
			self::SECRET
		);
		$request->set_header( HMAC::HEADER_TIMESTAMP, (string) $stale );
		$request->set_header( HMAC::HEADER_SIGNATURE, $signature );

		$result = $auth->authenticate_request( null, null, $request );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'safe_publish_mirror_expired', $result->get_error_code() );
	}

	public function test_missing_secret_configuration_returns_500(): void {
		$auth   = new HMAC_Authenticator( '', self::PEER );
		$result = $auth->authenticate_request( null, null, $this->signed_request( 'GET', '/wp/v2/posts/1' ) );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_grant_caps_only_applies_when_authenticated(): void {
		$auth = $this->make_authenticator();

		static::assertArrayNotHasKey( 'edit_posts', $auth->grant_caps( [] ) );

		$auth->authenticate_request( null, null, $this->signed_request( 'GET', '/wp/v2/posts/1' ) );
		$granted = $auth->grant_caps( [] );
		static::assertTrue( $granted['edit_posts'] );
		static::assertTrue( $granted['read_private_posts'] );

		$auth->tear_down( new \WP_REST_Response() );
		static::assertArrayNotHasKey( 'edit_posts', $auth->grant_caps( [] ) );
	}
}
