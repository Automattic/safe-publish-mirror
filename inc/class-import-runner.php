<?php

namespace Automattic\SafePublishMirror;

use WP_Error;

/**
 * Orchestrates an import run on the destination: list the source catalog, then
 * fetch and import each post as a draft. Returns a plain summary so any
 * trigger (WP-CLI, a future admin action) can report it.
 */
final class Import_Runner {
	private Source_Client $client;
	private Post_Importer $importer;

	public function __construct( Source_Client $client, Post_Importer $importer ) {
		$this->client   = $client;
		$this->importer = $importer;
	}

	public static function from_config( Config $config ): self {
		$client   = Source_Client::from_config( $config );
		$importer = new Post_Importer( new Media_Importer(), $config->connected_site_url() );

		return new self( $client, $importer );
	}

	/**
	 * @param int    $limit     Maximum posts to import.
	 * @param string $post_type Source post type to pull.
	 * @return array<string, mixed> Summary: created/updated/failed counts + per-item results.
	 */
	public function run( int $limit = 10, string $post_type = 'post' ): array {
		$limit   = max( 1, $limit );
		$catalog = $this->client->fetch_catalog(
			[
				'post_type' => $post_type,
				'per_page'  => $limit,
			]
		);

		if ( $catalog instanceof WP_Error ) {
			return $this->summary( [], $catalog );
		}

		$results = [];
		foreach ( array_slice( $catalog['items'], 0, $limit ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$results[] = $this->import_one( (int) $item['id'], (string) ( $item['post_type'] ?? $post_type ) );
		}

		$summary = $this->summary( $results );

		Telemetry::get_instance()->record_event(
			Telemetry::EVENT_IMPORT_COMPLETED,
			[
				'created' => $summary['created'],
				'updated' => $summary['updated'],
				'failed'  => $summary['failed'],
			]
		);

		return $summary;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function import_one( int $source_post_id, string $post_type ): array {
		$payload = $this->client->fetch_post( $source_post_id, $post_type );
		if ( $payload instanceof WP_Error ) {
			return $this->failure( $source_post_id, $payload );
		}

		$result = $this->importer->import_post( $payload );
		if ( $result instanceof WP_Error ) {
			return $this->failure( $source_post_id, $result );
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function failure( int $source_post_id, WP_Error $error ): array {
		return [
			'success'        => false,
			'source_post_id' => $source_post_id,
			'error'          => $error->get_error_code(),
			'message'        => $error->get_error_message(),
		];
	}

	/**
	 * @param list<array<string, mixed>> $results
	 * @return array<string, mixed>
	 */
	private function summary( array $results, ?WP_Error $error = null ): array {
		$created = 0;
		$updated = 0;
		$failed  = 0;

		foreach ( $results as $result ) {
			if ( empty( $result['success'] ) ) {
				++$failed;
			} elseif ( ! empty( $result['existing'] ) ) {
				++$updated;
			} else {
				++$created;
			}
		}

		return [
			'ok'      => ! $error instanceof WP_Error,
			'error'   => $error instanceof WP_Error ? $error->get_error_message() : null,
			'fetched' => count( $results ),
			'created' => $created,
			'updated' => $updated,
			'failed'  => $failed,
			'results' => $results,
		];
	}
}
