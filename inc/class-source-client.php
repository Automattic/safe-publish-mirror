<?php

namespace Automattic\SafePublishMirror;

use WP_Error;

/**
 * Outbound HTTP client used by the destination (import) site to read the
 * source. Every request is HMAC-signed with the shared secret and the site's
 * own home URL as the origin, so the source can verify it and grant edit
 * context.
 *
 * Two calls matter:
 *   - fetch_catalog(): the source's catalog endpoint, to discover post IDs.
 *   - fetch_post():    a single post over wp/v2 with context=edit and _embed,
 *                      flattened into the payload the importer consumes.
 */
final class Source_Client {
	private const MAX_RESPONSE_BYTES = 25 * 1024 * 1024;
	private const TIMEOUT            = 10;

	private string $source_site_url;
	private string $shared_secret;
	private string $origin_site_url;

	public function __construct( string $source_site_url, string $shared_secret, string $origin_site_url ) {
		$this->source_site_url = untrailingslashit( $source_site_url );
		$this->shared_secret   = $shared_secret;
		$this->origin_site_url = untrailingslashit( $origin_site_url );
	}

	public static function from_config( Config $config ): self {
		return new self( $config->connected_site_url(), $config->shared_secret(), untrailingslashit( home_url() ) );
	}

	/**
	 * List available posts on the source.
	 *
	 * @param array<string, scalar> $args Query args (post_type, per_page, page, status).
	 * @return array{items: list<mixed>, has_more: bool}|WP_Error
	 */
	public function fetch_catalog( array $args = [] ) {
		$route    = '/' . REST_Controller::NAMESPACE . '/catalog/posts';
		$response = $this->get( $route, $args, Request_Actions::LIST_ITEMS );

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		return [
			'items'    => isset( $response['items'] ) && is_array( $response['items'] ) ? array_values( $response['items'] ) : [],
			'has_more' => ! empty( $response['has_more'] ),
		];
	}

	/**
	 * Fetch a single post in edit context and flatten it for the importer.
	 *
	 * @param int    $source_post_id Post ID on the source.
	 * @param string $post_type      Post type slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public function fetch_post( int $source_post_id, string $post_type = 'post' ) {
		$route    = '/wp/v2/' . $this->rest_base( $post_type ) . '/' . $source_post_id;
		$response = $this->get(
			$route,
			[
				'context' => 'edit',
				'_embed'  => '1',
			],
			Request_Actions::IMPORT
		);

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		return $this->flatten_post( $response );
	}

	/**
	 * @param array<string, mixed> $data Raw wp/v2 post response.
	 * @return array<string, mixed>
	 */
	private function flatten_post( array $data ): array {
		return [
			'source_post_id'     => (int) ( $data['id'] ?? 0 ),
			'title'              => $this->raw_or_rendered( $data['title'] ?? null ),
			'content'            => $this->raw_or_rendered( $data['content'] ?? null ),
			'excerpt'            => $this->raw_or_rendered( $data['excerpt'] ?? null ),
			'slug'               => (string) ( $data['slug'] ?? '' ),
			'post_type'          => (string) ( $data['type'] ?? 'post' ),
			'link'               => (string) ( $data['link'] ?? '' ),
			'date_gmt'           => (string) ( $data['date_gmt'] ?? '' ),
			'author'             => $this->author( $data ),
			'featured_media_url' => $this->featured_media_url( $data ),
			'terms'              => $this->embedded_terms( $data ),
			'meta'               => isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : [],
			'media_library'      => isset( $data['safe_publish_media'] ) && is_array( $data['safe_publish_media'] ) ? $data['safe_publish_media'] : [],
		];
	}

	/**
	 * @param mixed $field A wp/v2 rendered/raw field (array with 'raw'/'rendered', or string).
	 */
	private function raw_or_rendered( $field ): string {
		if ( is_array( $field ) ) {
			if ( isset( $field['raw'] ) && is_string( $field['raw'] ) ) {
				return $field['raw'];
			}
			if ( isset( $field['rendered'] ) && is_string( $field['rendered'] ) ) {
				return $field['rendered'];
			}
			return '';
		}

		return is_string( $field ) ? $field : '';
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, string>|null
	 */
	private function author( array $data ): ?array {
		$author = $data['safe_publish_author'] ?? null;
		if ( ! is_array( $author ) || ! isset( $author['email'] ) ) {
			return null;
		}

		return [
			'email'        => (string) $author['email'],
			'login'        => (string) ( $author['login'] ?? '' ),
			'display_name' => (string) ( $author['display_name'] ?? '' ),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function featured_media_url( array $data ): string {
		/** @var mixed $featured */
		$featured = $data['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '';

		return is_string( $featured ) ? $featured : '';
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, list<array{name: string, slug: string}>>
	 */
	private function embedded_terms( array $data ): array {
		$groups = $data['_embedded']['wp:term'] ?? [];
		if ( ! is_array( $groups ) ) {
			return [];
		}

		$terms = [];
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			/** @var mixed $term */
			foreach ( $group as $term ) {
				if ( ! is_array( $term ) || ! isset( $term['taxonomy'], $term['name'] ) ) {
					continue;
				}
				$taxonomy             = (string) $term['taxonomy'];
				$terms[ $taxonomy ][] = [
					'name' => (string) $term['name'],
					'slug' => (string) ( $term['slug'] ?? '' ),
				];
			}
		}

		return $terms;
	}

	/**
	 * Perform a signed GET and decode the JSON body.
	 *
	 * @param string                $rest_route REST route path used for signing (no host/query).
	 * @param array<string, scalar> $query      Query args appended to the URL.
	 * @param string                $action     Declared Request_Actions intent.
	 * @return array<string, mixed>|WP_Error
	 */
	private function get( string $rest_route, array $query, string $action ) {
		if ( '' === $this->source_site_url ) {
			return new WP_Error( 'safe_publish_mirror_no_source', 'No connected source site configured' );
		}

		$url     = $this->source_site_url . '/wp-json' . $rest_route;
		$url     = empty( $query ) ? $url : add_query_arg( $query, $url );
		$headers = HMAC::build_request_headers( 'GET', $rest_route, '', $action, $this->shared_secret, $this->origin_site_url );

		$request_args = [
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'headers'             => $headers,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
		];

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $url, '', 3, self::TIMEOUT, 20, $request_args );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Off-platform fallback; VIP helper used when available.
			$response = wp_remote_get( $url, $request_args );
		}

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'safe_publish_mirror_http_' . $code,
				sprintf( 'Source responded with HTTP %d', $code ),
				[ 'status' => $code ]
			);
		}

		/** @var mixed $decoded */
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'safe_publish_mirror_bad_json', 'Source returned a malformed response' );
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	private function rest_base( string $post_type ): string {
		$object = get_post_type_object( $post_type );
		if ( $object && is_string( $object->rest_base ) && '' !== $object->rest_base ) {
			return $object->rest_base;
		}

		// Sensible defaults for the built-in types when the destination hasn't
		// registered the type: post -> posts, page -> pages.
		return 'page' === $post_type ? 'pages' : ( 'post' === $post_type ? 'posts' : $post_type );
	}
}
