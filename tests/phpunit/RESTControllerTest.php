<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use Spy_REST_Server;
use WP_REST_Request;
use WP_REST_Server;
use WP_Test_REST_TestCase;

/**
 * @covers \Automattic\SafePublishMirror\REST_Controller
 */
class RESTControllerTest extends WP_Test_REST_TestCase {
	private const SECRET = 'a-shared-secret-value-1234567890';

	private HMAC_Authenticator $authenticator;

	/**
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	public function setUp(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		parent::setUp();

		$this->authenticator = new HMAC_Authenticator( self::SECRET, untrailingslashit( home_url() ) );
		$this->authenticator->register();

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		( new REST_Controller( $this->authenticator ) )->register_routes();
	}

	/**
	 * @global WP_REST_Server|null $wp_rest_server
	 */
	public function tearDown(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;
		remove_filter( 'rest_pre_dispatch', [ $this->authenticator, 'authenticate_request' ], 10 );
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function catalog_request( bool $signed, array $params = [] ): WP_REST_Request {
		$route   = '/' . REST_Controller::NAMESPACE . '/catalog/posts';
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( $signed ) {
			$headers = HMAC::build_request_headers( 'GET', $route, '', Request_Actions::LIST_ITEMS, self::SECRET, untrailingslashit( home_url() ) );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}
		}

		return $request;
	}

	public function test_route_is_registered(): void {
		/** @var WP_REST_Server $wp_rest_server */
		global $wp_rest_server;

		static::assertArrayHasKey( '/' . REST_Controller::NAMESPACE . '/catalog/posts', $wp_rest_server->get_routes() );
	}

	public function test_unauthenticated_request_is_denied(): void {
		$response = rest_do_request( $this->catalog_request( false ) );

		static::assertSame( 401, $response->get_status() );
	}

	public function test_authenticated_request_lists_posts(): void {
		$post_id = self::factory()->post->create( [
			'post_title'  => 'Hello source',
			'post_status' => 'publish',
		] );

		$response = rest_do_request( $this->catalog_request( true ) );
		$data     = $response->get_data();

		static::assertSame( 200, $response->get_status() );
		static::assertFalse( $data['has_more'] );
		$ids = wp_list_pluck( $data['items'], 'id' );
		static::assertContains( $post_id, $ids );

		$item = $data['items'][ array_search( $post_id, $ids, true ) ];
		static::assertSame( 'Hello source', $item['title'] );
		static::assertSame( 'post', $item['post_type'] );
		static::assertSame( 'publish', $item['status'] );
	}

	public function test_pagination_reports_has_more(): void {
		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$response = rest_do_request( $this->catalog_request( true, [ 'per_page' => 2 ] ) );
		$data     = $response->get_data();

		static::assertTrue( $data['has_more'] );
		static::assertCount( 2, $data['items'] );
	}

	public function test_unknown_post_type_is_rejected(): void {
		$response = rest_do_request( $this->catalog_request( true, [ 'post_type' => 'not_a_type' ] ) );

		static::assertSame( 400, $response->get_status() );
	}

	public function test_empty_title_falls_back(): void {
		self::factory()->post->create( [
			'post_title'  => '',
			'post_status' => 'publish',
		] );

		$response = rest_do_request( $this->catalog_request( true ) );
		$titles   = wp_list_pluck( $response->get_data()['items'], 'title' );

		static::assertContains( '(no title)', $titles );
	}
}
