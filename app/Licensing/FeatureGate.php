<?php
/**
 * Nexora Engine — Feature Gate
 *
 * Orchestrates the full license-resolution chain and exposes the two public
 * APIs used everywhere in the plugin:
 *
 *   FeatureGate::can( 'feature_key' )          — bool
 *   FeatureGate::get_plan()                    — 'free' | 'pro'
 *
 * Resolution order (first match wins):
 *   1. DevOverrides  — local/staging simulation (blocked on production)
 *   2. FreemiusAdapter — live plan from Freemius API
 *   3. EntitlementCache — 24h transient (Freemius temporarily unavailable)
 *   4. GracePeriod   — 72h offline safety net (cache also gone)
 *   5. Free fallback — safe default
 *
 * The resolved plan is memoized per-request.  Call reset() in unit tests or
 * after programmatically changing license state in the same request.
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FeatureGate — Runtime plan resolution and feature checking.
 */
class FeatureGate {

	/**
	 * Per-request memoized plan (null = not yet resolved this request).
	 *
	 * @var string|null
	 */
	private static $plan = null;

	// ── Plan resolution ───────────────────────────────────────────────────────

	/**
	 * Returns the resolved plan for the current HTTP request.
	 * Result is memoized; subsequent calls in the same request are free.
	 *
	 * @return string  'free' | 'pro'
	 */
	public static function get_plan() {
		if ( null !== self::$plan ) {
			return self::$plan;
		}

		// ── 1. Dev overrides (local / staging only) ───────────────────────
		$dev = DevOverrides::get_plan();
		if ( null !== $dev ) {
			self::$plan = Entitlements::normalize_plan( $dev );
			return self::$plan;
		}

		// ── 2. Live Freemius ──────────────────────────────────────────────
		$adapter = FreemiusAdapter::instance();
		if ( $adapter->is_available() ) {
			try {
				$live_plan = $adapter->get_plan();

				// Free-plan users have no paid subscription so is_plan_active()
				// returns false — but 'free' is never "expired", it is always active.
				if ( 'free' === $live_plan ) {
					$status = 'active';
				} else {
					$status = $adapter->is_plan_active() ? 'active' : 'expired';
				}

				// Refresh short-term cache on every successful Freemius call.
				EntitlementCache::set( $live_plan, $status );

				// Update grace period only for paid plans.
				// When Freemius confirms the plan is now free (cancelled/downgraded),
				// clear the grace period immediately so the 72h window can't promote
				// a cancelled Pro account back to pro while Freemius is unreachable.
				if ( 'free' !== $live_plan ) {
					GracePeriod::record( $live_plan );
				} else {
					GracePeriod::clear();
				}

				self::$plan = Entitlements::normalize_plan( $live_plan );
				return self::$plan;

			} catch ( \Throwable $e ) {
				// Freemius threw — fall through to cache.
			}
		}

		// ── 3. Entitlement cache (24 h) ───────────────────────────────────
		$cached = EntitlementCache::get_plan();
		if ( null !== $cached ) {
			self::$plan = Entitlements::normalize_plan( $cached );
			return self::$plan;
		}

		// ── 4. Grace period (72 h) ────────────────────────────────────────
		if ( GracePeriod::is_active() ) {
			self::$plan = Entitlements::normalize_plan( GracePeriod::get_plan() );
			return self::$plan;
		}

		// ── 5. Safe fallback ──────────────────────────────────────────────
		self::$plan = Entitlements::PLAN_FREE;
		return self::$plan;
	}

	// ── Feature checking ──────────────────────────────────────────────────────

	/**
	 * Returns true when the current plan has access to the given feature.
	 *
	 * @param string $feature Feature key (see Entitlements::$map).
	 * @return bool
	 */
	public static function can( $feature ) {
		return Entitlements::plan_has_feature( self::get_plan(), $feature );
	}

	/**
	 * Returns true when the current plan is at or above the required level.
	 *
	 * @param string $min_plan Minimum plan slug required: 'free' | 'pro'.
	 * @return bool
	 */
	public static function is_plan_or_above( $min_plan ) {
		return Entitlements::plan_level( self::get_plan() ) >= Entitlements::plan_level( $min_plan );
	}

	// ── Cache management ──────────────────────────────────────────────────────

	/**
	 * Reset the per-request memoized plan.
	 * Call in unit tests or after programmatically changing license state.
	 */
	public static function reset() {
		self::$plan = null;
	}

	/**
	 * Bust ALL persistent licensing caches (transients) and reset the memo.
	 *
	 * Attached to Freemius license-event hooks in nexora-engine.php:
	 *   after_license_activation, after_license_deactivation, after_plan_change.
	 */
	public static function bust_all_caches() {
		EntitlementCache::bust();
		GracePeriod::clear();
		self::reset();
	}

	// ── Upgrade helpers ───────────────────────────────────────────────────────

	/**
	 * Returns the Freemius checkout upgrade URL.
	 *
	 * @param string $plan Target plan ('pro').
	 * @return string
	 */
	public static function get_upgrade_url( $plan = 'pro' ) {
		return FreemiusAdapter::instance()->get_upgrade_url( $plan );
	}
}
