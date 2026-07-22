<?php

namespace Automattic\SafePublishMirror;

use WP_Error;

/**
 * Imports a fetched source post onto the destination as a draft.
 *
 * Everything lands as post_status = draft — there is no direct-publish path.
 * The author is resolved by email and never auto-created: an unknown author is
 * a hard error. Re-importing the same source post updates the existing draft
 * rather than creating a duplicate (matched on source ID + source site URL).
 */
final class Post_Importer {
	public const META_SOURCE_POST_ID  = 'safe_publish_mirror_source_post_id';
	public const META_SOURCE_SITE_URL = 'safe_publish_mirror_source_site_url';
	public const META_SOURCE_LINK     = 'safe_publish_mirror_source_link';

	private Media_Importer $media;
	private string $source_site_url;

	public function __construct( Media_Importer $media, string $source_site_url ) {
		$this->media           = $media;
		$this->source_site_url = untrailingslashit( $source_site_url );
	}

	/**
	 * @param array<string, mixed> $payload Flattened source post (see Source_Client::fetch_post()).
	 * @return array<string, mixed>|WP_Error
	 */
	public function import_post( array $payload ) {
		$source_post_id = (int) ( $payload['source_post_id'] ?? 0 );
		if ( ! $source_post_id ) {
			return new WP_Error( 'safe_publish_mirror_invalid_payload', 'Missing source post ID' );
		}

		$author_id = $this->resolve_author( $payload['author'] ?? null );
		if ( $author_id instanceof WP_Error ) {
			return $author_id;
		}

		$library = isset( $payload['media_library'] ) && is_array( $payload['media_library'] ) ? $payload['media_library'] : [];
		$content = $this->media->rewrite_content( (string) ( $payload['content'] ?? '' ), $library );

		$existing_id = $this->find_existing( $source_post_id );

		$postarr = [
			'post_title'   => wp_slash( (string) ( $payload['title'] ?? '' ) ),
			'post_content' => wp_slash( $content ),
			'post_excerpt' => wp_slash( (string) ( $payload['excerpt'] ?? '' ) ),
			'post_name'    => (string) ( $payload['slug'] ?? '' ),
			'post_status'  => 'draft',
			'post_type'    => $this->resolve_post_type( (string) ( $payload['post_type'] ?? 'post' ) ),
			'post_author'  => $author_id,
		];

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		$this->store_provenance( $post_id, $source_post_id, (string) ( $payload['link'] ?? '' ) );
		$this->import_featured_image( $post_id, (string) ( $payload['featured_media_url'] ?? '' ) );
		$this->import_terms( $post_id, isset( $payload['terms'] ) && is_array( $payload['terms'] ) ? $payload['terms'] : [] );
		$this->import_meta( $post_id, isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : [], $payload );

		return [
			'success'        => true,
			'post_id'        => $post_id,
			'existing'       => (bool) $existing_id,
			'source_post_id' => $source_post_id,
			'title'          => (string) ( $payload['title'] ?? '' ),
			'edit_url'       => (string) get_edit_post_link( $post_id, 'raw' ),
		];
	}

	/**
	 * Resolve the destination author by email. Never auto-creates a user.
	 *
	 * @param mixed $author Source author payload (email/login/display_name).
	 * @return int|WP_Error
	 */
	private function resolve_author( $author ) {
		if ( ! is_array( $author ) || empty( $author['email'] ) ) {
			return new WP_Error( 'safe_publish_mirror_author_missing', 'Source post is missing author information' );
		}

		$user = get_user_by( 'email', (string) $author['email'] );
		if ( ! $user ) {
			return new WP_Error(
				'safe_publish_mirror_author_not_found',
				'No destination user matches the source author email',
				[ 'email' => (string) $author['email'] ]
			);
		}

		return (int) $user->ID;
	}

	private function resolve_post_type( string $post_type ): string {
		return post_type_exists( $post_type ) ? $post_type : 'post';
	}

	private function find_existing( int $source_post_id ): int {
		$found = get_posts(
			[
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded dedupe lookup keyed on source id + site.
					'relation' => 'AND',
					[
						'key'   => self::META_SOURCE_POST_ID,
						'value' => (string) $source_post_id,
					],
					[
						'key'   => self::META_SOURCE_SITE_URL,
						'value' => $this->source_site_url,
					],
				],
			]
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	private function store_provenance( int $post_id, int $source_post_id, string $source_link ): void {
		update_post_meta( $post_id, self::META_SOURCE_POST_ID, (string) $source_post_id );
		update_post_meta( $post_id, self::META_SOURCE_SITE_URL, $this->source_site_url );
		update_post_meta( $post_id, self::META_SOURCE_LINK, esc_url_raw( $source_link ) );
	}

	private function import_featured_image( int $post_id, string $featured_media_url ): void {
		if ( '' === $featured_media_url ) {
			return;
		}

		$attachment_id = $this->media->sideload( $featured_media_url );
		if ( $attachment_id ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	/**
	 * @param array<array-key, mixed> $terms taxonomy => list of {name, slug}.
	 */
	private function import_terms( int $post_id, array $terms ): void {
		/** @var mixed $group */
		foreach ( $terms as $taxonomy => $group ) {
			if ( ! is_string( $taxonomy ) ) {
				continue;
			}
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $group ) ) {
				continue;
			}

			$names = [];
			/** @var mixed $term */
			foreach ( $group as $term ) {
				if ( is_array( $term ) && isset( $term['name'] ) && '' !== $term['name'] ) {
					$names[] = (string) $term['name'];
				}
			}

			if ( ! empty( $names ) ) {
				wp_set_object_terms( $post_id, $names, $taxonomy, false );
			}
		}
	}

	/**
	 * @param array<array-key, mixed> $meta    REST-exposed source meta.
	 * @param array<string, mixed>    $payload Full payload, for filter context.
	 */
	private function import_meta( int $post_id, array $meta, array $payload ): void {
		$filtered = (array) apply_filters( 'safe_publish_mirror_import_post_meta', $meta, $payload );

		/** @var mixed $value */
		foreach ( $filtered as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
	}
}
