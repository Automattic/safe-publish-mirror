<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \Automattic\SafePublishMirror\Post_Importer
 */
class PostImporterTest extends WP_UnitTestCase {
	private const SOURCE = 'https://source.example';

	private Post_Importer $importer;

	public function setUp(): void {
		parent::setUp();
		$this->importer = new Post_Importer( new Media_Importer(), self::SOURCE );
	}

	private function make_author( string $email = 'writer@source.example' ): void {
		self::factory()->user->create( [
			'role'       => 'author',
			'user_email' => $email,
			'user_login' => 'writer',
		] );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function payload( array $overrides = [] ): array {
		return array_merge(
			[
				'source_post_id' => 4321,
				'title'          => 'Imported headline',
				'content'        => '<p>Body</p>',
				'excerpt'        => 'Summary',
				'slug'           => 'imported-headline',
				'post_type'      => 'post',
				'link'           => self::SOURCE . '/?p=4321',
				'author'         => [ 'email' => 'writer@source.example' ],
				'terms'          => [],
				'meta'           => [],
				'media_library'  => [],
			],
			$overrides
		);
	}

	public function test_creates_a_draft_with_provenance(): void {
		$this->make_author();

		$result = $this->importer->import_post( $this->payload() );

		static::assertIsArray( $result );
		static::assertTrue( $result['success'] );
		static::assertFalse( $result['existing'] );

		$post = get_post( (int) $result['post_id'] );
		static::assertSame( 'draft', $post->post_status );
		static::assertSame( 'Imported headline', $post->post_title );
		static::assertSame( '4321', get_post_meta( $post->ID, Post_Importer::META_SOURCE_POST_ID, true ) );
		static::assertSame( self::SOURCE, get_post_meta( $post->ID, Post_Importer::META_SOURCE_SITE_URL, true ) );
	}

	public function test_reimport_updates_the_same_draft(): void {
		$this->make_author();

		$first  = $this->importer->import_post( $this->payload() );
		$second = $this->importer->import_post( $this->payload( [ 'title' => 'Edited headline' ] ) );

		static::assertSame( $first['post_id'], $second['post_id'] );
		static::assertTrue( $second['existing'] );
		static::assertSame( 'Edited headline', get_post( (int) $second['post_id'] )->post_title );

		$matches = get_posts( [
			'post_type'   => 'any',
			'post_status' => 'any',
			'fields'      => 'ids',
			'meta_key'    => Post_Importer::META_SOURCE_POST_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => '4321', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		static::assertCount( 1, $matches );
	}

	public function test_unknown_author_is_a_hard_error(): void {
		$result = $this->importer->import_post( $this->payload( [ 'author' => [ 'email' => 'nobody@source.example' ] ] ) );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'safe_publish_mirror_author_not_found', $result->get_error_code() );
	}

	public function test_missing_author_is_an_error(): void {
		$result = $this->importer->import_post( $this->payload( [ 'author' => null ] ) );

		static::assertInstanceOf( WP_Error::class, $result );
		static::assertSame( 'safe_publish_mirror_author_missing', $result->get_error_code() );
	}

	public function test_terms_are_imported(): void {
		$this->make_author();

		$result = $this->importer->import_post( $this->payload( [
			'terms' => [
				'category' => [
					[
						'name' => 'Bulletins',
						'slug' => 'bulletins',
					],
				],
			],
		] ) );

		$terms = wp_get_post_terms( (int) $result['post_id'], 'category', [ 'fields' => 'names' ] );
		static::assertContains( 'Bulletins', $terms );
	}

	public function test_rest_exposed_meta_is_imported(): void {
		$this->make_author();

		$result = $this->importer->import_post( $this->payload( [
			'meta' => [ 'spm_reading_time' => '5' ],
		] ) );

		static::assertSame( '5', get_post_meta( (int) $result['post_id'], 'spm_reading_time', true ) );
	}
}
