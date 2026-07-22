<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_UnitTestCase;

/**
 * End-to-end import on the destination with the source served over a mocked
 * HTTP layer — no network, no media.
 *
 * @covers \Automattic\SafePublishMirror\Import_Runner
 */
class ImportRunnerTest extends WP_UnitTestCase {
	private const SOURCE = 'https://source.example';
	private const ORIGIN = 'https://destination.example';
	private const SECRET = 'a-shared-secret-value-1234567890';

	/** @var array<string, mixed> */
	private array $responses = [];

	private Import_Runner $runner;

	public function setUp(): void {
		parent::setUp();

		$this->responses = [];
		$this->runner    = new Import_Runner(
			new Source_Client( self::SOURCE, self::SECRET, self::ORIGIN ),
			new Post_Importer( new Media_Importer(), self::SOURCE )
		);

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
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => (string) wp_json_encode( $this->responses[ $path ] ?? [] ),
			'headers'  => [],
		];
	}

	public function test_run_imports_catalog_posts_as_drafts(): void {
		self::factory()->user->create( [
			'role'       => 'author',
			'user_email' => 'writer@source.example',
		] );

		$this->responses['/wp-json/safe-publish-mirror/v1/catalog/posts'] = [
			'items'    => [
				[
					'id'        => 55,
					'post_type' => 'post',
				],
			],
			'has_more' => false,
		];
		$this->responses['/wp-json/wp/v2/posts/55']                       = [
			'id'                  => 55,
			'type'                => 'post',
			'slug'                => 'source-post',
			'title'               => [ 'raw' => 'Source post' ],
			'content'             => [ 'raw' => '<p>Content</p>' ],
			'excerpt'             => [ 'raw' => '' ],
			'meta'                => [],
			'safe_publish_author' => [ 'email' => 'writer@source.example' ],
			'safe_publish_media'  => [],
			'_embedded'           => [],
		];

		$summary = $this->runner->run( 10 );

		static::assertTrue( $summary['ok'] );
		static::assertSame( 1, $summary['created'] );
		static::assertSame( 0, $summary['failed'] );

		$imported = $summary['results'][0];
		$post     = get_post( (int) $imported['post_id'] );
		static::assertSame( 'draft', $post->post_status );
		static::assertSame( 'Source post', $post->post_title );
		static::assertSame( '55', get_post_meta( $post->ID, Post_Importer::META_SOURCE_POST_ID, true ) );
	}

	public function test_import_single_imports_one_post_by_id(): void {
		self::factory()->user->create( [
			'role'       => 'author',
			'user_email' => 'writer@source.example',
		] );

		$this->responses['/wp-json/wp/v2/posts/77'] = [
			'id'                  => 77,
			'type'                => 'post',
			'title'               => [ 'raw' => 'Just one' ],
			'content'             => [ 'raw' => '<p>One</p>' ],
			'excerpt'             => [ 'raw' => '' ],
			'safe_publish_author' => [ 'email' => 'writer@source.example' ],
			'_embedded'           => [],
		];

		$result = $this->runner->import_single( 77, 'post' );

		static::assertTrue( $result['success'] );
		static::assertSame( 'draft', get_post( (int) $result['post_id'] )->post_status );
	}

	public function test_run_reports_a_catalog_fetch_failure(): void {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 10 );
		add_filter(
			'pre_http_request',
			static fn(): array => [
				'response' => [
					'code'    => 500,
					'message' => 'Error',
				],
				'body'     => '',
				'headers'  => [],
			],
			10
		);

		$summary = $this->runner->run();

		static::assertFalse( $summary['ok'] );
		static::assertNotNull( $summary['error'] );
	}

	public function test_run_records_author_failures_without_aborting(): void {
		$this->responses['/wp-json/safe-publish-mirror/v1/catalog/posts'] = [
			'items'    => [
				[
					'id'        => 99,
					'post_type' => 'post',
				],
			],
			'has_more' => false,
		];
		$this->responses['/wp-json/wp/v2/posts/99']                       = [
			'id'                  => 99,
			'type'                => 'post',
			'title'               => [ 'raw' => 'Orphan' ],
			'content'             => [ 'raw' => '' ],
			'excerpt'             => [ 'raw' => '' ],
			'safe_publish_author' => [ 'email' => 'ghost@source.example' ],
			'_embedded'           => [],
		];

		$summary = $this->runner->run();

		static::assertTrue( $summary['ok'] );
		static::assertSame( 1, $summary['failed'] );
		static::assertSame( 'safe_publish_mirror_author_not_found', $summary['results'][0]['error'] );
	}
}
