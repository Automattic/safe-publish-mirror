<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_UnitTestCase;

/**
 * Network-free coverage: the dedupe and content-rewrite paths that reuse an
 * already-imported attachment without downloading anything. The download +
 * sideload path is exercised end-to-end in the dev-env run.
 *
 * @covers \Automattic\SafePublishMirror\Media_Importer
 */
class MediaImporterTest extends WP_UnitTestCase {
	private const SOURCE_URL = 'https://source.example/wp-content/uploads/photo.jpg';

	private Media_Importer $media;

	public function setUp(): void {
		parent::setUp();
		$this->media = new Media_Importer();
	}

	private function seed_attachment( string $local_guid ): int {
		$attachment_id = self::factory()->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'guid'           => $local_guid,
		] );
		update_post_meta( $attachment_id, Media_Importer::META_SOURCE_URL, self::SOURCE_URL );

		return $attachment_id;
	}

	public function test_sideload_reuses_an_existing_attachment(): void {
		$attachment_id = $this->seed_attachment( 'http://example.org/wp-content/uploads/photo.jpg' );

		// A query string on the source URL must not defeat the dedupe.
		static::assertSame( $attachment_id, $this->media->sideload( self::SOURCE_URL . '?ver=2' ) );
	}

	public function test_rewrite_content_swaps_known_urls_for_local_ones(): void {
		$local = 'http://example.org/wp-content/uploads/photo.jpg';
		$this->seed_attachment( $local );

		$content   = '<figure><img src="' . self::SOURCE_URL . '" alt="" /></figure>';
		$rewritten = $this->media->rewrite_content( $content, [ self::SOURCE_URL => [ 'alt' => 'Photo' ] ] );

		static::assertStringContainsString( $local, $rewritten );
		static::assertStringNotContainsString( self::SOURCE_URL, $rewritten );
	}

	public function test_rewrite_content_leaves_unknown_urls_untouched(): void {
		// Block outbound HTTP so an un-seeded URL fails to sideload cleanly
		// instead of hitting the network.
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'No network in tests' ), 10 );

		$content   = '<img src="https://elsewhere.example/x.jpg" />';
		$rewritten = $this->media->rewrite_content( $content, [ 'https://elsewhere.example/x.jpg' => [] ] );

		static::assertSame( $content, $rewritten );
	}
}
