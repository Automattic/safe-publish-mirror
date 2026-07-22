<?php

namespace Automattic\SafePublishMirror;

use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The source-side catalog endpoint: `GET /safe-publish-mirror/v1/catalog/posts`.
 *
 * Lists the posts a destination site may pull. The heavy content transfer then
 * happens over `wp/v2` with `context=edit` and `_embed`; this endpoint only
 * enumerates what is available. HMAC-authenticated: the permission callback
 * defers to the inbound authenticator, so only the trusted connected peer can
 * read it.
 */
final class REST_Controller {
	public const NAMESPACE = 'safe-publish-mirror/v1';

	private const PER_PAGE_DEFAULT = 20;
	private const PER_PAGE_MAX     = 100;

	private HMAC_Authenticator $authenticator;

	public function __construct( HMAC_Authenticator $authenticator ) {
		$this->authenticator = $authenticator;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/catalog/posts',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_catalog' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'page'      => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page'  => [
						'type'    => 'integer',
						'default' => self::PER_PAGE_DEFAULT,
						'minimum' => 1,
						'maximum' => self::PER_PAGE_MAX,
					],
					'post_type' => [
						'type'    => 'string',
						'default' => 'post',
					],
					'status'    => [
						'type'    => 'string',
						'default' => 'publish',
					],
				],
			]
		);
	}

	/**
	 * Only the HMAC-authenticated connected peer may enumerate the catalog.
	 */
	public function check_permission(): bool {
		return $this->authenticator->is_authenticated();
	}

	public function get_catalog( WP_REST_Request $request ): WP_REST_Response {
		$per_page  = (int) $request->get_param( 'per_page' );
		$page      = (int) $request->get_param( 'page' );
		$post_type = (string) $request->get_param( 'post_type' );
		$status    = (string) $request->get_param( 'status' );

		if ( ! in_array( $post_type, $this->syncable_post_types(), true ) ) {
			return new WP_REST_Response(
				[
					'code'    => 'invalid_post_type',
					'message' => 'Unknown or non-syncable post type',
				],
				400
			);
		}

		$query = new WP_Query(
			[
				'post_type'              => $post_type,
				'post_status'            => $this->normalize_status( $status ),
				'posts_per_page'         => $per_page + 1, // One extra row derives has_more without a COUNT.
				'offset'                 => ( $page - 1 ) * $per_page,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		/** @var list<WP_Post> $posts */
		$posts    = is_array( $query->posts ) ? $query->posts : [];
		$has_more = count( $posts ) > $per_page;
		if ( $has_more ) {
			array_pop( $posts );
		}

		$items = array_map( [ $this, 'to_item' ], $posts );

		Telemetry::get_instance()->record_event(
			'catalog_listed',
			[
				'post_type' => $post_type,
				'count'     => count( $items ),
			]
		);

		return new WP_REST_Response(
			[
				'items'    => $items,
				'has_more' => $has_more,
			],
			200
		);
	}

	/**
	 * @param WP_Post $post
	 * @return array<string, mixed>
	 */
	private function to_item( WP_Post $post ): array {
		$title = get_the_title( $post );

		return [
			'id'           => $post->ID,
			'title'        => '' === $title ? '(no title)' : $title,
			'post_type'    => $post->post_type,
			'status'       => $post->post_status,
			'link'         => (string) get_permalink( $post ),
			'date_gmt'     => $post->post_date_gmt,
			'modified_gmt' => $post->post_modified_gmt,
		];
	}

	/**
	 * Public post types the catalog is willing to enumerate. Attachments are
	 * excluded — media travels with its parent post, not on its own.
	 *
	 * @return list<string>
	 */
	private function syncable_post_types(): array {
		$types = get_post_types( [ 'public' => true ] );
		unset( $types['attachment'] );

		return array_values( $types );
	}

	/**
	 * Restrict the requested status to a known publish-ish set; default to
	 * publish. Draft/pending/private are allowed because the caller is the
	 * authenticated peer reading with edit context.
	 *
	 * @return string|list<string>
	 */
	private function normalize_status( string $status ) {
		$allowed = [ 'publish', 'draft', 'pending', 'private', 'future' ];

		return in_array( $status, $allowed, true ) ? $status : 'publish';
	}
}
