<?php
/**
 * Nexora Engine — Environment Detection
 *
 * Single source of truth for the runtime environment.
 * Drives environment-aware behaviour across the plugin:
 *   – EntitlementCache TTL  (5 min local / 30 min staging / 4 h production)
 *   – Dev-tool visibility   (recovery panel, sandbox reset button)
 *   – Verbose logging gates (sandbox-only debug output)
 *
 * Detection order (first match wins):
 *   1. WP_ENVIRONMENT_TYPE constant  — most authoritative; set by host / wp-config.php
 *   2. Hostname pattern analysis     — localhost / .local / staging.* / etc.
 *   3. WP_DEBUG as a loose hint      — debug on + no resolvable host → assume local
 *   4. Production fallback           — safe default; never assumes dev
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Environment — Detects the current runtime context and exposes environment-aware helpers.
 */
class Environment {

	// ── Canonical environment slugs ────────────────────────────────────────────
	const LOCAL      = 'local';
	const STAGING    = 'staging';
	const PRODUCTION = 'production';

	/**
	 * Memoized result for the current HTTP request.
	 * Reset only in unit tests via Environment::reset().
	 *
	 * @var string|null
	 */
	private static $env = null;

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Returns the current environment slug: 'local' | 'staging' | 'production'.
	 *
	 * @return string
	 */
	public static function current() {
		if ( null === self::$env ) {
			self::$env = self::detect();
		}
		return self::$env;
	}

	/** @return bool */
	public static function is_local() {
		return self::LOCAL === self::current();
	}

	/** @return bool */
	public static function is_staging() {
		return self::STAGING === self::current();
	}

	/** @return bool */
	public static function is_production() {
		return self::PRODUCTION === self::current();
	}

	/**
	 * Returns true when developer / sandbox tooling may be displayed.
	 * Always false on production regardless of any other setting.
	 *
	 * @return bool
	 */
	public static function allows_dev_tools() {
		return ! self::is_production();
	}

	/**
	 * Returns the entitlement cache TTL appropriate for this environment.
	 *
	 * Local   →   300 s  ( 5 min)  — fast iteration; stale states noticed immediately
	 * Staging →  1800 s  (30 min)  — balance between responsiveness and API load
	 * Prod    → 14400 s  ( 4 h)    — reduce Freemius API calls in production
	 *
	 * @return int Seconds.
	 */
	public static function cache_ttl() {
		switch ( self::current() ) {
			case self::LOCAL:
				return 300;    //  5 minutes
			case self::STAGING:
				return 1800;   // 30 minutes
			default:
				return 14400;  //  4 hours
		}
	}

	/**
	 * Human-readable label for the current environment (for admin display).
	 *
	 * @return string
	 */
	public static function label() {
		switch ( self::current() ) {
			case self::LOCAL:
				return 'Local';
			case self::STAGING:
				return 'Staging';
			default:
				return 'Production';
		}
	}

	// ── Detection ──────────────────────────────────────────────────────────────

	/**
	 * Core detection logic — runs once per request, result is memoized.
	 *
	 * @return string Environment slug.
	 */
	private static function detect() {
		// ── 1. WP_ENVIRONMENT_TYPE constant ──────────────────────────────────
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			$wpe = strtolower( (string) WP_ENVIRONMENT_TYPE );
			if ( in_array( $wpe, array( 'local', 'development' ), true ) ) {
				return self::LOCAL;
			}
			if ( in_array( $wpe, array( 'staging', 'stage', 'preprod' ), true ) ) {
				return self::STAGING;
			}
			if ( 'production' === $wpe ) {
				return self::PRODUCTION;
			}
			// Non-standard value falls through to hostname analysis.
		}

		// ── 2. Hostname pattern analysis ──────────────────────────────────────
		$raw_host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: '';
		$host = strtolower( (string) preg_replace( '/:\d+$/', '', $raw_host ) ); // strip port

		if ( self::matches_local_host( $host ) ) {
			return self::LOCAL;
		}
		if ( self::matches_staging_host( $host ) ) {
			return self::STAGING;
		}

		// ── 3. WP_DEBUG loose hint ────────────────────────────────────────────
		// Only treat as local when HTTP_HOST is also absent (e.g. CLI / cron with debug on).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' === $host ) {
			return self::LOCAL;
		}

		// ── 4. Safe production fallback ───────────────────────────────────────
		return self::PRODUCTION;
	}

	/**
	 * Returns true when the hostname resolves to a local development address.
	 *
	 * @param string $host Lowercase hostname (port already stripped).
	 * @return bool
	 */
	private static function matches_local_host( $host ) {
		// Exact-match loopback addresses and bare 'localhost'.
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '' ), true ) ) {
			return true;
		}
		// Common local / dev TLDs used by Valet, Lando, DDEV, etc.
		$local_tlds = array( '.local', '.test', '.dev', '.localhost', '.internal', '.example' );
		foreach ( $local_tlds as $tld ) {
			if ( substr( $host, -strlen( $tld ) ) === $tld ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns true when the hostname contains staging-environment signals.
	 *
	 * @param string $host Lowercase hostname (port already stripped).
	 * @return bool
	 */
	private static function matches_staging_host( $host ) {
		// Full sub-domain prefixes: staging.example.com, stage.example.com, etc.
		$prefixes = array( 'staging.', 'stage.', 'preprod.', 'preview.', 'uat.', 'qa.', 'dev.' );
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $host, $prefix ) ) {
				return true;
			}
		}
		// Segment-match for domains like app.staging.example.com
		$parts = explode( '.', $host );
		$staging_segments = array( 'staging', 'stage', 'preprod', 'uat', 'qa' );
		foreach ( $parts as $segment ) {
			if ( in_array( $segment, $staging_segments, true ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Test helpers ───────────────────────────────────────────────────────────

	/**
	 * Clears the memoized environment result.
	 * Call between unit test cases that mock $_SERVER['HTTP_HOST'].
	 */
	public static function reset() {
		self::$env = null;
	}
}
