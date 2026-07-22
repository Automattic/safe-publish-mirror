<?php

namespace Automattic\SafePublishMirror;

/**
 * Sideloads media referenced by an imported post and rewrites the post content
 * so it points at the local copies.
 *
 * Every sideloaded attachment records the source URL it came from
 * (safe_publish_mirror_source_url), so re-importing the same media reuses the
 * existing attachment instead of downloading it again.
 */
final class Media_Importer {
	public const META_SOURCE_URL = 'safe_publish_mirror_source_url';

	/**
	 * Rewrite the uploads URLs in a post's content to their sideloaded local
	 * equivalents.
	 *
	 * @param string                    $content Raw post content.
	 * @param array<array-key, mixed>   $library Source media URL => metadata.
	 * @return string Rewritten content.
	 */
	public function rewrite_content( string $content, array $library ): string {
		/** @var mixed $metadata */
		foreach ( $library as $source_url => $metadata ) {
			$source_url    = (string) $source_url;
			$attachment_id = $this->sideload( $source_url, is_array( $metadata ) ? $metadata : [] );
			if ( ! $attachment_id ) {
				continue;
			}

			$local_url = (string) wp_get_attachment_url( $attachment_id );
			if ( '' !== $local_url ) {
				$content = str_replace( $source_url, $local_url, $content );
			}
		}

		return $content;
	}

	/**
	 * Sideload a single media URL and return the attachment ID (or 0 on failure).
	 *
	 * @param array<array-key, mixed> $metadata Library metadata (alt/title/caption/description).
	 */
	public function sideload( string $source_url, array $metadata = [] ): int {
		$source_url = (string) strtok( $source_url, '?' );
		if ( '' === $source_url ) {
			return 0;
		}

		$existing = $this->existing_attachment( $source_url );
		if ( $existing ) {
			return $existing;
		}

		$this->load_media_dependencies();

		$tmp = download_url( $source_url, self::download_timeout() );
		if ( $tmp instanceof \WP_Error ) {
			return 0;
		}

		$file_array = [
			'name'     => basename( $source_url ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, 0 );
		if ( $attachment_id instanceof \WP_Error ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return 0;
		}

		update_post_meta( $attachment_id, self::META_SOURCE_URL, $source_url );
		$this->apply_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/**
	 * @param array<array-key, mixed> $metadata
	 */
	private function apply_metadata( int $attachment_id, array $metadata ): void {
		$alt = $this->meta_string( $metadata, 'alt' );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		$post_update = [];
		$title       = $this->meta_string( $metadata, 'title' );
		if ( '' !== $title ) {
			$post_update['post_title'] = sanitize_text_field( $title );
		}
		$caption = $this->meta_string( $metadata, 'caption' );
		if ( '' !== $caption ) {
			$post_update['post_excerpt'] = wp_kses_post( $caption );
		}
		$description = $this->meta_string( $metadata, 'description' );
		if ( '' !== $description ) {
			$post_update['post_content'] = wp_kses_post( $description );
		}

		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $attachment_id;
			wp_update_post( $post_update );
		}
	}

	/**
	 * @param array<array-key, mixed> $metadata
	 */
	private function meta_string( array $metadata, string $key ): string {
		return isset( $metadata[ $key ] ) && is_string( $metadata[ $key ] ) ? $metadata[ $key ] : '';
	}

	private function existing_attachment( string $source_url ): int {
		$found = get_posts(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => self::META_SOURCE_URL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded provenance lookup for import dedupe.
				'meta_value'             => $source_url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded provenance lookup for import dedupe.
			]
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	private function load_media_dependencies(): void {
		/** @psalm-suppress MissingFile -- WordPress admin includes, resolved at runtime. */
		require_once ABSPATH . 'wp-admin/includes/file.php';
		/** @psalm-suppress MissingFile -- WordPress admin includes, resolved at runtime. */
		require_once ABSPATH . 'wp-admin/includes/media.php';
		/** @psalm-suppress MissingFile -- WordPress admin includes, resolved at runtime. */
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	private static function download_timeout(): int {
		return 30;
	}
}
