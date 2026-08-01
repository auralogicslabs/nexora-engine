<?php
/**
 * Nexora Engine — Safe request-superglobal accessors.
 *
 * $_SERVER is attacker-controlled. Not just the obvious HTTP_* entries, which
 * are request headers verbatim, but REQUEST_URI and QUERY_STRING too. Reading
 * them raw is the same mistake as reading $_GET raw, and the plugin was doing
 * it in 86 places.
 *
 * Every accessor here unslashes and sanitizes, and each one narrows to the
 * shape its key is actually allowed to have — an IP that is not an IP, or a
 * method that is not a method, comes back empty rather than being passed on.
 *
 * Note for the cache drop-ins: templates/advanced-cache*.php run before
 * WordPress loads and cannot call any of this. They carry their own plain-PHP
 * equivalents.
 *
 * @package NexoraEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEXENG_Request — read request data without trusting it.
 */
class NEXENG_Request {

	/**
	 * Generic $_SERVER read: unslashed, sanitized, never null.
	 *
	 * @param string $key     Server key.
	 * @param string $default Returned when absent.
	 * @return string
	 */
	public static function server( string $key, string $default = '' ): string {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return $default;
		}
		$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		return '' === $value ? $default : $value;
	}

	/**
	 * Request path + query, as WordPress itself would treat it.
	 *
	 * esc_url_raw would strip the leading slash-relative form, so this keeps
	 * the raw path but removes control characters and anything that could
	 * break out of an attribute or a header.
	 *
	 * @param string $default Returned when absent.
	 * @return string
	 */
	public static function uri( string $default = '/' ): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $default;
		}
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		// Strip anything a URI may not contain: control chars, spaces, quotes,
		// angle brackets, backslashes.
		$uri = preg_replace( '/[\x00-\x20\x7F"\'<>\\\\]/', '', (string) $uri );
		return '' === $uri ? $default : $uri;
	}

	/**
	 * Query string only, control characters removed.
	 *
	 * @return string
	 */
	public static function query_string(): string {
		if ( ! isset( $_SERVER['QUERY_STRING'] ) ) {
			return '';
		}
		$qs = wp_unslash( $_SERVER['QUERY_STRING'] );
		return (string) preg_replace( '/[\x00-\x20\x7F"\'<>\\\\]/', '', (string) $qs );
	}

	/**
	 * HTTP method, upper-case letters only.
	 *
	 * @return string
	 */
	public static function method(): string {
		$method = strtoupper( self::server( 'REQUEST_METHOD', 'GET' ) );
		return preg_match( '/^[A-Z]{3,10}$/', $method ) ? $method : 'GET';
	}

	/**
	 * The connecting IP, validated as an IP or empty.
	 *
	 * Proxy headers are deliberately NOT consulted: X-Forwarded-For and
	 * friends are set by the client on a direct connection, so trusting them
	 * lets anyone forge their address and defeat rate limiting. Only
	 * REMOTE_ADDR, which the web server sets, is used.
	 *
	 * @return string
	 */
	public static function ip(): string {
		$ip = self::server( 'REMOTE_ADDR' );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * User agent, clipped — it is echoed into logs and admin tables.
	 *
	 * @param int $max Maximum length kept.
	 * @return string
	 */
	public static function user_agent( int $max = 255 ): string {
		return substr( self::server( 'HTTP_USER_AGENT' ), 0, $max );
	}

	/**
	 * Referer, validated as a URL or empty.
	 *
	 * @return string
	 */
	public static function referer(): string {
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}
		$ref = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		return $ref ? $ref : '';
	}

	/**
	 * Host, restricted to the characters a hostname may contain.
	 *
	 * @return string
	 */
	public static function host(): string {
		$host = strtolower( self::server( 'HTTP_HOST' ) );
		return preg_match( '/^[a-z0-9.\-]+(:[0-9]{1,5})?$/', $host ) ? $host : '';
	}
}
