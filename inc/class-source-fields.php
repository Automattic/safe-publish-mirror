<?php

namespace Automattic\SafePublishMirror;

use WP_REST_Request;

/**
 * Extra `wp/v2` REST fields the source exposes so the destination can resolve
 * things the standard payload omits:
 *
 *   - safe_publish_author: the post author's email/login/display name, so the
 *     destination can match the author by email (it never auto-creates users).
 *   - safe_publish_media: a map of uploaded media URLs found in the content to
 *     their library metadata (alt/title/caption/description), so sideloaded
 *     attachments keep their metadata.
 *
 * Both fields return null unless the request is HMAC-authenticated. Author
 * email is PII and must never appear in a public/unauthenticated response.
 */
final class Source_Fields {
	private HMAC_Authenticator $authenticator;

	public function __construct( HMAC_Authenticator $authenticator ) {
		$this->authenticator = $authenticator;
	}

	public function register(): void {
		foreach ( $this->syncable_post_types() as $post_type ) {
			register_rest_field(
				$post_type,
				'safe_publish_author',
				[
					'get_callback' => [ $this, 'get_author' ],
					'schema'       => null,
				]
			);
			register_rest_field(
				$post_type,
				'safe_publish_media',
				[
					'get_callback' => [ $this, 'get_media' ],
					'schema'       => null,
				]
			);
		}
	}

	/**
	 * @param array<string, mixed> $post Prepared post response data.
	 * @return array<string, string>|null
	 */
	public function get_author( array $post ): ?array {
		if ( ! $this->authenticator->is_authenticated() ) {
			return null;
		}

		$user = get_userdata( (int) ( $post['author'] ?? 0 ) );
		if ( ! $user ) {
			return null;
		}

		return [
			'email'        => $user->user_email,
			'login'        => $user->user_login,
			'display_name' => $user->display_name,
		];
	}

	/**
	 * @param array<string, mixed> $post Prepared post response data.
	 * @return array<string, array<string, string>>|null
	 */
	public function get_media( array $post ): ?array {
		if ( ! $this->authenticator->is_authenticated() ) {
			return null;
		}

		$content = self::post_field_string( 'post_content', (int) ( $post['id'] ?? 0 ) );
		if ( '' === $content ) {
			return [];
		}

		$library = [];
		foreach ( $this->upload_urls_in( $content ) as $url ) {
			$attachment_id = $this->resolve_attachment_id( $url );
			if ( ! $attachment_id ) {
				continue;
			}

			$library[ $url ] = [
				'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'title'       => self::post_field_string( 'post_title', $attachment_id ),
				'caption'     => self::post_field_string( 'post_excerpt', $attachment_id ),
				'description' => self::post_field_string( 'post_content', $attachment_id ),
			];
		}

		return $library;
	}

	/**
	 * Uploads-directory URLs referenced in the content.
	 *
	 * @return list<string>
	 */
	private function upload_urls_in( string $content ): array {
		$baseurl = (string) ( wp_get_upload_dir()['baseurl'] ?? '' );
		if ( '' === $baseurl ) {
			return [];
		}

		preg_match_all( '#' . preg_quote( $baseurl, '#' ) . '[^\s"\'<>()]+#', $content, $matches );
		if ( ! isset( $matches[0] ) || [] === $matches[0] ) {
			return [];
		}

		$urls = [];
		foreach ( $matches[0] as $url ) {
			$urls[ (string) strtok( $url, '?' ) ] = true;
		}

		return array_keys( $urls );
	}

	/**
	 * get_post_field can return non-string values for some fields; coerce to a
	 * plain string so callers never get an array.
	 */
	private static function post_field_string( string $field, int $post_id ): string {
		$value = get_post_field( $field, $post_id );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Resolve a URL to an attachment ID, retrying without a `-WxH` size suffix
	 * so a resized image still maps back to its source attachment.
	 */
	private function resolve_attachment_id( string $url ): int {
		$id = $this->url_to_attachment_id( $url );
		if ( $id ) {
			return $id;
		}

		$full = (string) preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );
		if ( $full !== $url ) {
			$id = $this->url_to_attachment_id( $full );
		}

		return $id;
	}

	/**
	 * Prefer the VIP cached resolver; fall back to core off-platform.
	 */
	private function url_to_attachment_id( string $url ): int {
		if ( function_exists( 'wpcom_vip_attachment_url_to_postid' ) ) {
			return (int) wpcom_vip_attachment_url_to_postid( $url );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid -- VIP cached wrapper used when available; this is the off-platform fallback.
		return (int) attachment_url_to_postid( $url );
	}

	/**
	 * @return list<string>
	 */
	private function syncable_post_types(): array {
		$types = get_post_types( [ 'public' => true ] );
		unset( $types['attachment'] );

		return array_values( $types );
	}
}
