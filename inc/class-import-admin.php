<?php

namespace Automattic\SafePublishMirror;

use WP_Error;

/**
 * Destination-side admin screen: "Safe Publish Mirror" → a list of the source
 * site's posts with a per-row **Import** action. Import is the only action —
 * no edit, delete, or rollback (those live in wp-admin's normal post screens
 * once a draft exists).
 *
 * Registered only on an import-role site with complete config.
 */
final class Import_Admin {
	public const MENU_SLUG     = 'safe-publish-mirror';
	public const IMPORT_ACTION = 'safe_publish_mirror_import';
	public const NONCE         = 'safe_publish_mirror_import';

	private const PER_PAGE = 20;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_' . self::IMPORT_ACTION, [ $this, 'handle_import' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Safe Publish Mirror', 'safe-publish-mirror' ),
			__( 'Safe Publish Mirror', 'safe-publish-mirror' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-download'
		);
	}

	/**
	 * @codeCoverageIgnore -- thin view include; the data it renders is covered via view_model().
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require __DIR__ . '/../views/import-page.php';
	}

	/**
	 * Build the data the import screen renders: the current filter/page, the
	 * source catalog mapped to rows with local-import state, and any post-action
	 * notice. Kept separate from the markup so it is testable.
	 *
	 * @return array{
	 *   source_url: string,
	 *   post_type: string,
	 *   page: int,
	 *   has_more: bool,
	 *   error: string,
	 *   notice: array{type: string, message: string}|null,
	 *   rows: list<array{source_post_id: int, post_type: string, title: string, source_status: string, date: string, local_post_id: int, local_state: string, local_status: string, edit_link: string}>
	 * }
	 */
	public static function view_model(): array {
		$config     = Config::get_instance();
		$source_url = $config->connected_site_url();

		$post_type = 'post';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing filter, no state change.
		if ( isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing filter, no state change.
			$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}

		$page = 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination, no state change.
		if ( isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination, no state change.
			$page = max( 1, (int) $_GET['paged'] );
		}

		$catalog = Source_Client::from_config( $config )->fetch_catalog(
			[
				'post_type' => $post_type,
				'per_page'  => self::PER_PAGE,
				'page'      => $page,
			]
		);

		$model = [
			'source_url' => $source_url,
			'post_type'  => '' === $post_type ? 'post' : $post_type,
			'page'       => $page,
			'has_more'   => false,
			'error'      => '',
			'notice'     => self::notice(),
			'rows'       => [],
		];

		if ( $catalog instanceof WP_Error ) {
			$model['error'] = $catalog->get_error_message();
			return $model;
		}

		$model['has_more'] = $catalog['has_more'];
		$model['rows']     = self::build_rows( $catalog['items'], $source_url );

		return $model;
	}

	/**
	 * @param list<mixed> $items   Catalog items from the source.
	 * @param string      $source_url Connected source site URL.
	 * @return list<array{source_post_id: int, post_type: string, title: string, source_status: string, date: string, local_post_id: int, local_state: string, local_status: string, edit_link: string}>
	 */
	public static function build_rows( array $items, string $source_url ): array {
		$rows = [];

		/** @var mixed $item */
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$source_id = (int) $item['id'];
			$local_id  = Post_Importer::find_local( $source_id, $source_url );

			$rows[] = [
				'source_post_id' => $source_id,
				'post_type'      => (string) ( $item['post_type'] ?? 'post' ),
				'title'          => (string) ( $item['title'] ?? '' ),
				'source_status'  => (string) ( $item['status'] ?? '' ),
				'date'           => self::format_date( (string) ( $item['date_gmt'] ?? '' ) ),
				'local_post_id'  => $local_id,
				'local_state'    => $local_id
					? __( 'Up to date', 'safe-publish-mirror' )
					: __( 'Available', 'safe-publish-mirror' ),
				'local_status'   => $local_id ? (string) get_post_status( $local_id ) : '',
				'edit_link'      => $local_id ? (string) get_edit_post_link( $local_id, 'raw' ) : '',
			];
		}

		return $rows;
	}

	/**
	 * Handle a per-row import submission, then redirect back to the list with a
	 * notice. Import is create-or-update: re-importing refreshes the draft.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import content.', 'safe-publish-mirror' ) );
		}

		check_admin_referer( self::NONCE );

		$source_id = isset( $_POST['source_post_id'] ) && is_scalar( $_POST['source_post_id'] ) ? (int) $_POST['source_post_id'] : 0;
		$post_type = isset( $_POST['post_type'] ) && is_string( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';

		$result = Import_Runner::from_config( Config::get_instance() )->import_single( $source_id, '' === $post_type ? 'post' : $post_type );

		$args = [ 'page' => self::MENU_SLUG ];
		if ( ! empty( $result['success'] ) ) {
			$args['spm_imported'] = (int) $result['post_id'];
		} else {
			$args['spm_error'] = rawurlencode( (string) ( $result['message'] ?? __( 'Import failed.', 'safe-publish-mirror' ) ) );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The one-line notice to show after an import redirect, if any.
	 *
	 * @return array{type: string, message: string}|null
	 */
	private static function notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['spm_imported'] ) && is_scalar( $_GET['spm_imported'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
			$post_id = (int) $_GET['spm_imported'];
			return [
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: link to the imported draft */
					__( 'Imported as a draft: %s', 'safe-publish-mirror' ),
					sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $post_id, 'raw' ) ), esc_html( get_the_title( $post_id ) ) )
				),
			];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['spm_error'] ) && is_string( $_GET['spm_error'] ) ) {
			return [
				'type'    => 'error',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
				'message' => sanitize_text_field( wp_unslash( $_GET['spm_error'] ) ),
			];
		}

		return null;
	}

	private static function format_date( string $date_gmt ): string {
		if ( '' === $date_gmt ) {
			return '';
		}

		$format = (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' );

		return (string) mysql2date( $format, get_date_from_gmt( $date_gmt ) );
	}
}
