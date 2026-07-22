<?php
declare(strict_types = 1);

namespace Automattic\SafePublishMirror;

use Spy_REST_Server;
use WP_REST_Request;
use WP_REST_Server;
use WP_Test_REST_TestCase;

/**
 * @covers \Automattic\SafePublishMirror\Source_Fields
 */
class SourceFieldsTest extends WP_Test_REST_TestCase {
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
		( new Source_Fields( $this->authenticator ) )->register();
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

	private function fetch_post( int $post_id, bool $signed ): WP_REST_Request {
		$route   = '/wp/v2/posts/' . $post_id;
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'context', 'edit' );

		if ( $signed ) {
			$headers = HMAC::build_request_headers( 'GET', $route, '', Request_Actions::IMPORT, self::SECRET, untrailingslashit( home_url() ) );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}
		}

		return $request;
	}

	private function author_post(): int {
		$author = self::factory()->user->create( [
			'role'         => 'author',
			'user_email'   => 'writer@source.example',
			'user_login'   => 'writer',
			'display_name' => 'The Writer',
		] );

		return self::factory()->post->create( [
			'post_status' => 'publish',
			'post_author' => $author,
		] );
	}

	public function test_author_field_exposes_email_when_authenticated(): void {
		$post_id  = $this->author_post();
		$response = rest_do_request( $this->fetch_post( $post_id, true ) );

		static::assertSame( 200, $response->get_status() );
		$author = $response->get_data()['safe_publish_author'];
		static::assertSame( 'writer@source.example', $author['email'] );
		static::assertSame( 'writer', $author['login'] );
		static::assertSame( 'The Writer', $author['display_name'] );
	}

	public function test_author_field_is_null_when_unauthenticated(): void {
		$post_id = $this->author_post();

		// An unauthenticated read cannot use edit context; fetch in view context
		// and confirm the PII field is withheld.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$data    = rest_do_request( $request )->get_data();

		static::assertNull( $data['safe_publish_author'] );
	}

	public function test_media_field_maps_content_uploads_to_metadata(): void {
		$upload   = wp_get_upload_dir();
		$relative = '2026/07/example.jpg';
		$url      = $upload['baseurl'] . '/' . $relative;

		$attachment_id = self::factory()->attachment->create( [
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'Example image',
			'post_excerpt'   => 'A caption',
			'post_content'   => 'A description',
		] );
		update_post_meta( $attachment_id, '_wp_attached_file', $relative );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Alt text' );

		$post_id = self::factory()->post->create( [
			'post_status'  => 'publish',
			'post_content' => '<!-- wp:image --><figure><img src="' . $url . '" /></figure><!-- /wp:image -->',
		] );

		$data  = rest_do_request( $this->fetch_post( $post_id, true ) )->get_data();
		$media = $data['safe_publish_media'];

		static::assertArrayHasKey( $url, $media );
		static::assertSame( 'Alt text', $media[ $url ]['alt'] );
		static::assertSame( 'Example image', $media[ $url ]['title'] );
		static::assertSame( 'A caption', $media[ $url ]['caption'] );
	}

	public function test_media_field_is_null_when_unauthenticated(): void {
		$post_id = $this->author_post();
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );

		static::assertNull( rest_do_request( $request )->get_data()['safe_publish_media'] );
	}
}
