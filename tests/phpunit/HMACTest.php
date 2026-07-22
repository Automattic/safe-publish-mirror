<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_UnitTestCase;

/**
 * @covers \Automattic\SafePublishMirror\HMAC
 */
class HMACTest extends WP_UnitTestCase {
	private const SECRET = 'a-shared-secret-value-1234567890';

	public function test_content_hash_matches_native_sha256(): void {
		static::assertSame( hash( 'sha256', 'payload' ), HMAC::content_hash( 'payload' ) );
	}

	public function test_signature_string_is_canonical(): void {
		$string = HMAC::signature_string( 'get', '/wp/v2/posts/9', 1000, 'abc', 'https://origin.example/', 'import' );

		static::assertSame( 'GET|/wp/v2/posts/9|1000|abc|https://origin.example|import', $string );
	}

	public function test_sign_is_deterministic(): void {
		$a = HMAC::sign( 'canonical', self::SECRET );
		$b = HMAC::sign( 'canonical', self::SECRET );

		static::assertSame( $a, $b );
		static::assertNotSame( $a, HMAC::sign( 'canonical', 'different-secret' ) );
	}

	public function test_build_request_headers_round_trips_to_the_verifier(): void {
		$headers = HMAC::build_request_headers(
			'GET',
			'/wp/v2/posts/12',
			'',
			Request_Actions::IMPORT,
			self::SECRET,
			'https://origin.example'
		);

		$expected = HMAC::sign(
			HMAC::signature_string(
				'GET',
				'/wp/v2/posts/12',
				(int) $headers[ HMAC::HEADER_TIMESTAMP ],
				$headers[ HMAC::HEADER_CONTENT_HASH ],
				'https://origin.example',
				Request_Actions::IMPORT
			),
			self::SECRET
		);

		static::assertSame( $expected, $headers[ HMAC::HEADER_SIGNATURE ] );
		static::assertSame( 'https://origin.example', $headers[ HMAC::HEADER_SITE_URL ] );
		static::assertSame( HMAC::content_hash( '' ), $headers[ HMAC::HEADER_CONTENT_HASH ] );
	}
}
