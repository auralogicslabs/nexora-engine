<?php
/**
 * Nexora Engine — NEXENG_Licence Bridge
 *
 * Backward-compatible shim that routes all legacy NEXENG_Licence calls to the
 * new enterprise licensing stack (FeatureGate → FreemiusAdapter).
 *
 * ALL 30+ call sites across class-ncx-*.php files use NEXENG_Licence::is_pro().
 * This bridge keeps every call site intact while delegating to Freemius.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * Migration map:
 *   NEXENG_Licence::is_pro()        → NexoraEngine\Core\Features::is_tier_or_above('pro')
 *   NEXENG_Licence::clear_cache()   → NexoraEngine\Licensing\FeatureGate::bust_all_caches()
 *   NEXENG_Licence::get_key()       → Deprecated (Freemius manages keys via its own UI)
 *   NEXENG_Licence::save_key()      → Deprecated (use Freemius account dashboard)
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * @package NexoraEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Licence {

	// Singleton — all methods static; no external instantiation needed.
	private function __construct() {}

	/**
	 * Returns true when the site has an active Pro Freemius licence,
	 * or when a dev override is active (NEXORA_DEV_MODE + NEXORA_PRO_ENABLED).
	 *
	 * This is the ONLY method legacy code needs to call.
	 * Do NOT check ne_fs() or plan names directly outside FreemiusAdapter.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		// Delegate entirely to the new FeatureGate system.
		if ( class_exists( 'NexoraEngine\\Core\\Features', false ) ) {
			return NexoraEngine\Core\Features::is_tier_or_above( 'pro' );
		}

		// Safety fallback during very early boot (before autoloader fires).
		// Should never be hit in normal operation.
		return false;
	}

	/**
	 * Legacy alias — Agency tier was merged into Pro.
	 *
	 * @return bool
	 */
	public static function is_agency() {
		return self::is_pro();
	}

	/**
	 * Clears all licensing caches — call after any manual licence change.
	 * Delegates to FeatureGate which busts both EntitlementCache and GracePeriod.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		if ( class_exists( 'NexoraEngine\\Licensing\\FeatureGate', false ) ) {
			NexoraEngine\Licensing\FeatureGate::bust_all_caches();
		}
	}

	/**
	 * @deprecated Licence keys are now managed by Freemius via its account UI.
	 *             This method is kept only to prevent fatal errors in WP-CLI code
	 *             that still calls it.  It always returns an empty string.
	 *
	 * @return string
	 */
	public static function get_key() {
		return '';
	}

	/**
	 * @deprecated Licence keys are now managed by Freemius.
	 *             If you need to activate a licence, use the Freemius account
	 *             page that appears under the Nexora Engine admin menu.
	 *             This method is a no-op retained only to prevent fatal errors.
	 *
	 * @param string $licence_key Unused.
	 * @return void
	 */
	public static function save_key( $licence_key ) {
		// No-op: Freemius manages licence activation/deactivation via its own UI.
		// Kept for backward compat with WP-CLI commands that still call this.
	}
}
