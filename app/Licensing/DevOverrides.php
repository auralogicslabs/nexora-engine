<?php
/**
 * Nexora Engine — Developer Mode Overrides
 *
 * Allows local and staging environments to simulate paid tiers without a real
 * Freemius license.  Production sites are ALWAYS blocked from using overrides,
 * regardless of what is defined in wp-config.php.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * USAGE — add to wp-config.php on dev / staging ONLY:
 *
 *   define( 'NEXORA_DEV_MODE',       true );
 *   define( 'NEXORA_PRO_ENABLED',    true );   // simulate Pro tier
 *   // Legacy aliases (also unlock Pro):
 *   define( 'NEXORA_AGENCY_ENABLED', true );
 *   define( 'NEXORA_ENTERPRISE_ENABLED', true );
 *
 * Do NOT commit these defines to version control or deploy to production.
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DevOverrides — Non-production tier simulation.
 *
 * Checked FIRST in the FeatureGate resolution chain.
 * Is a complete no-op in production.
 */
class DevOverrides {

	/**
	 * Returns true when dev overrides are active.
	 *
	 * THREE layers must all pass:
	 *  1. NEXORA_DEV_MODE must be explicitly defined and truthy.
	 *  2. WP_ENVIRONMENT_TYPE must NOT be 'production'.
	 *  3. The server must be a recognised dev host (localhost, RFC-1918 IP,
	 *     *.local / *.test / *.localhost TLD) OR WP_ENVIRONMENT_TYPE must be
	 *     explicitly set to 'local' | 'development' | 'staging'.
	 *
	 * Layer 3 is the critical production safeguard: even if someone copies the
	 * wp-config.php defines to a live server, the public hostname fails the
	 * dev-host check and the override silently returns null.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( ! defined( 'NEXORA_DEV_MODE' ) || ! NEXORA_DEV_MODE ) {
			return false;
		}

		// Layer 2: hard block when WP explicitly says production.
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'production' === WP_ENVIRONMENT_TYPE ) {
			return false;
		}

		// Layer 3: require either a recognised dev host OR an explicit dev
		// WP_ENVIRONMENT_TYPE.  This stops overrides working on live servers
		// whose owners simply copied the dev constants.
		$env_ok = defined( 'WP_ENVIRONMENT_TYPE' )
			&& in_array( WP_ENVIRONMENT_TYPE, [ 'local', 'development', 'staging' ], true );

		if ( ! $env_ok && ! self::is_dev_host() ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns true when the current HTTP host looks like a local / dev machine.
	 *
	 * Recognises:
	 *  • localhost, 127.0.0.1, ::1
	 *  • RFC-1918 private IP ranges (10.x, 172.16-31.x, 192.168.x)
	 *  • Dev TLDs: .local  .test  .localhost  .internal  .example  .invalid
	 *  • LocalWP's default *.local and *.localhost domains
	 *
	 * @return bool
	 */
	private static function is_dev_host(): bool {
		$host = \class_exists( '\NEXENG_Request' )
			? \NEXENG_Request::host()
			: \strtolower( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) );
		if ( '' === $host ) {
			$host = \strtolower( (string) \php_uname( 'n' ) );
		}
		$host = (string) preg_replace( '/:\d+$/', '', $host ); // strip port

		// Exact localhost / loopback matches.
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1', '0.0.0.0' ], true ) ) {
			return true;
		}

		// RFC-1918 private IP ranges.
		if ( preg_match( '/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $host ) ) {
			return true;
		}

		// Known dev TLD suffixes.
		foreach ( [ '.local', '.test', '.localhost', '.internal', '.example', '.invalid' ] as $tld ) {
			if ( str_ends_with( $host, $tld ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the overridden plan slug, or null when overrides are not active.
	 *
	 * Logs a PHP warning so developers know overrides are running.
	 *
	 * @return string|null  'pro' | null
	 */
	public static function get_plan() {
		if ( ! self::is_active() ) {
			return null;
		}

		if ( defined( 'NEXORA_AGENCY_ENABLED' ) && NEXORA_AGENCY_ENABLED ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Nexora Engine] ⚠️  DEV OVERRIDE: Pro tier active (legacy NEXORA_AGENCY_ENABLED). Non-production only.' );
			return Entitlements::PLAN_PRO;
		}

		if ( defined( 'NEXORA_ENTERPRISE_ENABLED' ) && NEXORA_ENTERPRISE_ENABLED ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Nexora Engine] ⚠️  DEV OVERRIDE: Pro tier active (legacy NEXORA_ENTERPRISE_ENABLED). Non-production only.' );
			return Entitlements::PLAN_PRO;
		}

		if ( defined( 'NEXORA_PRO_ENABLED' ) && NEXORA_PRO_ENABLED ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Nexora Engine] ⚠️  DEV OVERRIDE: Pro tier active. Non-production only.' );
			return Entitlements::PLAN_PRO;
		}

		// NEXORA_DEV_MODE is on but neither plan override is defined — still free.
		return null;
	}
}
