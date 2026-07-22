<?php

namespace Automattic\SafePublishMirror;

/**
 * The declared intent of a cross-site request. The value travels in the
 * X-Safe-Publish-Action header and is folded into the signed HMAC payload, so
 * any in-flight tampering of the label is detectable by the receiver.
 */
final class Request_Actions {
	/** List the source catalog. */
	public const LIST_ITEMS = 'list';

	/** Fetch a single post's editable content for import. */
	public const IMPORT = 'import';

	/** Connectivity/credential probe. */
	public const PROBE = 'probe';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return [ self::LIST_ITEMS, self::IMPORT, self::PROBE ];
	}

	public static function is_valid( string $action ): bool {
		return in_array( $action, self::all(), true );
	}
}
