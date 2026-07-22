<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_UnitTestCase;

/**
 * @covers \Automattic\SafePublishMirror\Source_Client
 */
class SourceClientTest extends WP_UnitTestCase {
	private const SOURCE = 'https://source.example';
	private const ORIGIN = 'https://destination.example';
	private const SECRET = 'a-shared-secret-value-1234567890';

	/** @var list<array{url: string, args: array<string, mixed>}> */
	private array $requests = [];

	/** @var array<string, mixed> */
	private array $responses = [];

	private Source_Client $client;

	public function setUp(): void {
		parent::setUp();

		$this->requests  = [];
		$this->responses = [];
		$this->client    = new Source_Client( self::SOURCE, self::SECRET, self::ORIGIN );

		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		parent::tearDown();
	}

	/**
	 * @param false|array<string, mixed> $preempt
	 * @param array<string, mixed>       $args
	 * @return array<string, mixed>
	 */
	public function mock_http( $preempt, array $args, string $url ): array {
		$this->requests[] = [
			'url'  => $url,
			'args' => $args,
		];

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body = $this->responses[ $path ] ?? [
			'items'    => [],
			'has_more' => false,
		];

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => (string) wp_json_encode( $body ),
			'headers'  => [],
		];
	}

	public function test_catalog_request_is_signed(): void {
		$this->responses['/wp-json/safe-publish-mirror/v1/catalog/posts'] = [
			'items'    => [
				[
					'id'        => 7,
					'post_type' => 'post',
				],
			],
			'has_more' => false,
		];

		$result = $this->client->fetch_catalog( [ 'per_page' => 5 ] );

		static::assertSame( 7, $result['items'][0]['id'] );

		$headers = $this->requests[0]['args']['headers'];
		static::assertSame( self::ORIGIN, $headers[ HMAC::HEADER_SITE_URL ] );
		static::assertSame( Request_Actions::LIST_ITEMS, $headers[ HMAC::HEADER_ACTION ] );

		$expected = HMAC::sign(
			HMAC::signature_string(
				'GET',
				'/safe-publish-mirror/v1/catalog/posts',
				(int) $headers[ HMAC::HEADER_TIMESTAMP ],
				HMAC::content_hash( '' ),
				self::ORIGIN,
				Request_Actions::LIST_ITEMS
			),
			self::SECRET
		);
		static::assertSame( $expected, $headers[ HMAC::HEADER_SIGNATURE ] );
	}

	public function test_fetch_post_flattens_the_response(): void {
		$this->responses['/wp-json/wp/v2/posts/7'] = [
			'id'                  => 7,
			'type'                => 'post',
			'slug'                => 'from-source',
			'link'                => self::SOURCE . '/from-source',
			'title'               => [ 'raw' => 'Raw title' ],
			'content'             => [ 'raw' => '<p>Raw body</p>' ],
			'excerpt'             => [ 'raw' => 'Raw excerpt' ],
			'meta'                => [ 'spm_key' => 'value' ],
			'safe_publish_author' => [
				'email'        => 'writer@source.example',
				'login'        => 'writer',
				'display_name' => 'Writer',
			],
			'safe_publish_media'  => [ self::SOURCE . '/wp-content/uploads/a.jpg' => [ 'alt' => 'A' ] ],
			'_embedded'           => [
				'wp:featuredmedia' => [ [ 'source_url' => self::SOURCE . '/wp-content/uploads/hero.jpg' ] ],
				'wp:term'          => [
					[
						[
							'taxonomy' => 'category',
							'name'     => 'News',
							'slug'     => 'news',
						],
					],
				],
			],
		];

		$payload = $this->client->fetch_post( 7, 'post' );

		static::assertSame( 'Raw title', $payload['title'] );
		static::assertSame( '<p>Raw body</p>', $payload['content'] );
		static::assertSame( 'writer@source.example', $payload['author']['email'] );
		static::assertSame( self::SOURCE . '/wp-content/uploads/hero.jpg', $payload['featured_media_url'] );
		static::assertSame( 'News', $payload['terms']['category'][0]['name'] );
		static::assertArrayHasKey( self::SOURCE . '/wp-content/uploads/a.jpg', $payload['media_library'] );

		// The single-post read asks for edit context and embeds.
		$fetch_url = $this->requests[0]['url'];
		static::assertStringContainsString( 'context=edit', $fetch_url );
		static::assertStringContainsString( '_embed=1', $fetch_url );
	}

	public function test_non_200_response_is_an_error(): void {
		add_filter(
			'pre_http_request',
			static fn(): array => [
				'response' => [
					'code'    => 403,
					'message' => 'Forbidden',
				],
				'body'     => '',
				'headers'  => [],
			],
			20
		);

		$result = $this->client->fetch_catalog();
		static::assertInstanceOf( \WP_Error::class, $result );
	}
}
