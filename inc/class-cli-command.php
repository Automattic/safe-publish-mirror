<?php

namespace Automattic\SafePublishMirror;

use WP_CLI;

/**
 * WP-CLI trigger for a destination-side import run:
 *
 *   wp safe-publish-mirror import [--limit=<n>] [--post-type=<type>]
 *
 * The command is only registered on an import-role site with complete config
 * (see Plugin). It exists so an operator can run an end-to-end pull between two
 * dev sites without any UI.
 */
final class CLI_Command {
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Import posts from the connected source as drafts.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum number of posts to import. Default 10.
	 *
	 * [--post-type=<type>]
	 * : Source post type to pull. Default 'post'.
	 *
	 * @param list<string>          $_args      Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 */
	public function import( array $_args, array $assoc_args ): void {
		if ( ! $this->config->is_import() ) {
			WP_CLI::error( 'This site is not configured with sync_mode = import.' );
		}

		$limit     = (int) ( $assoc_args['limit'] ?? 10 );
		$post_type = (string) ( $assoc_args['post-type'] ?? 'post' );

		$summary = Import_Runner::from_config( $this->config )->run( $limit, $post_type );

		if ( empty( $summary['ok'] ) ) {
			WP_CLI::error( sprintf( 'Import failed: %s', (string) $summary['error'] ) );
		}

		$results = is_array( $summary['results'] ) ? array_values( $summary['results'] ) : [];
		/** @var mixed $result */
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			if ( empty( $result['success'] ) ) {
				WP_CLI::warning( sprintf( 'Source #%d failed: %s', (int) $result['source_post_id'], (string) $result['message'] ) );
			} else {
				WP_CLI::log( sprintf(
					'%s draft #%d from source #%d — %s',
					empty( $result['existing'] ) ? 'Created' : 'Updated',
					(int) $result['post_id'],
					(int) $result['source_post_id'],
					(string) $result['title']
				) );
			}
		}

		WP_CLI::success( sprintf(
			'%d fetched · %d created · %d updated · %d failed',
			(int) $summary['fetched'],
			(int) $summary['created'],
			(int) $summary['updated'],
			(int) $summary['failed']
		) );
	}
}
