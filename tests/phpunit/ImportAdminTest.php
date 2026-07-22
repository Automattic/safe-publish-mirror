<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use WP_UnitTestCase;

/**
 * @covers \Automattic\SafePublishMirror\Import_Admin
 */
class ImportAdminTest extends WP_UnitTestCase {
	private const SOURCE = 'https://source.example';

	private Import_Admin $admin;

	public function setUp(): void {
		parent::setUp();
		$this->admin = new Import_Admin();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_register_wires_menu_and_action_hooks(): void {
		$this->admin->register();

		static::assertSame( 10, has_action( 'admin_menu', [ $this->admin, 'add_menu' ] ) );
		static::assertSame( 10, has_action( 'admin_post_' . Import_Admin::IMPORT_ACTION, [ $this->admin, 'handle_import' ] ) );
	}

	/**
	 * @global array<string, string> $admin_page_hooks
	 */
	public function test_add_menu_registers_the_top_level_page(): void {
		/** @var array<string, string> $admin_page_hooks */
		global $admin_page_hooks;

		$this->admin->add_menu();

		static::assertArrayHasKey( Import_Admin::MENU_SLUG, $admin_page_hooks );
	}

	public function test_build_rows_distinguishes_imported_from_available(): void {
		// Seed a local draft that stands in for an already-imported source post 55.
		$local_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		update_post_meta( $local_id, Post_Importer::META_SOURCE_POST_ID, '55' );
		update_post_meta( $local_id, Post_Importer::META_SOURCE_SITE_URL, self::SOURCE );

		$rows = Import_Admin::build_rows(
			[
				[
					'id'        => 55,
					'post_type' => 'post',
					'title'     => 'Already here',
					'status'    => 'publish',
					'date_gmt'  => '2026-07-20T10:00:00',
				],
				[
					'id'        => 56,
					'post_type' => 'post',
					'title'     => 'Not yet',
					'status'    => 'publish',
					'date_gmt'  => '2026-07-21T10:00:00',
				],
			],
			self::SOURCE
		);

		static::assertCount( 2, $rows );

		static::assertSame( $local_id, $rows[0]['local_post_id'] );
		static::assertSame( 'Up to date', $rows[0]['local_state'] );
		static::assertSame( 'draft', $rows[0]['local_status'] );
		static::assertNotSame( '', $rows[0]['date'] );

		static::assertSame( 0, $rows[1]['local_post_id'] );
		static::assertSame( 'Available', $rows[1]['local_state'] );
		static::assertSame( '', $rows[1]['local_status'] );
	}

	public function test_build_rows_skips_malformed_items(): void {
		$rows = Import_Admin::build_rows( [ 'not-an-array', [ 'title' => 'no id' ], [ 'id' => 0 ] ], self::SOURCE );

		static::assertSame( [], $rows );
	}
}
