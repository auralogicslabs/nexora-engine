<?php
/**
 * Nexora Engine — Entitlement Cache
 *
 * 24-hour transient cache of the last plan result returned by Freemius.
 *
 * Purpose:
 *   – Prevents a Freemius API call on every WordPress page load.
 *   – Protects against brief Freemius network timeouts (≤ 24 h).
 *
 * The cache is refreshed every time FreemiusAdapter::get_plan() succeeds,
 * and is busted by FeatureGate::bust_all_caches() on license events
 * (activation, deactivation, plan upgrade/downgrade).
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EntitlementCache — 24-hour short-term cache of the last known Freemius plan.
 */
class EntitlementCache {

	/** WordPress transient key. Versioned so a plugin update auto-clears stale data. */
	const KEY = 'nexeng_entitlement_v1';

	/**
	 * Fallback TTL used only when the Environment class is unavailable.
	 * Normal path: Environment::cache_ttl() returns the environment-aware value.
	 *
	 *   Local   →   300 s (  5 min) — fast QA iteration
	 *   Staging →  1800 s ( 30 min) — balanced responsiveness
	 *   Prod    → 14400 s (  4 h)   — reduce API pressure
	 */
	const TTL_FALLBACK = 14400; // 4 hours

	/**
	 * Persist the current plan and license status.
	 *
	 * TTL is chosen by Environment::cache_ttl() so that:
	 *   – Local dev:  5 min  (stale states noticed immediately after sync)
	 *   – Staging:   30 min  (balance between QA responsiveness and API load)
	 *   – Production: 4 h    (reduce Freemius API call frequency)
	 *
	 * @param string $plan   Plan slug: 'free' | 'pro' | 'agency'.
	 * @param string $status License status: 'active' | 'expired' | 'unverified'.
	 */
	public static function set( $plan, $status = 'active' ) {
		$ttl = class_exists( Environment::class )
			? Environment::cache_ttl()
			: self::TTL_FALLBACK;

		set_transient(
			self::KEY,
			array(
				'plan'      => (string) $plan,
				'status'    => (string) $status,
				'cached_at' => time(),
				'ttl'       => $ttl,
			),
			$ttl
		);
	}

	/**
	 * Returns the TTL that was used when the current cache entry was written,
	 * or the current environment TTL when no cache exists.
	 *
	 * Used by the recovery panel to display meaningful expiry information.
	 *
	 * @return int Seconds.
	 */
	public static function active_ttl() {
		$data = self::get();
		if ( $data && ! empty( $data['ttl'] ) ) {
			return (int) $data['ttl'];
		}
		return class_exists( Environment::class )
			? Environment::cache_ttl()
			: self::TTL_FALLBACK;
	}

	/**
	 * Returns the full cached data array, or null when cache is empty or expired.
	 *
	 * @return array{plan:string,status:string,cached_at:int}|null
	 */
	public static function get() {
		$data = get_transient( self::KEY );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Returns the cached plan slug, or null when cache is absent.
	 *
	 * @return string|null
	 */
	public static function get_plan() {
		$data = self::get();
		return ( $data && isset( $data['plan'] ) ) ? (string) $data['plan'] : null;
	}

	/**
	 * Returns the cached license status, or null when cache is absent.
	 *
	 * @return string|null
	 */
	public static function get_status() {
		$data = self::get();
		return ( $data && isset( $data['status'] ) ) ? (string) $data['status'] : null;
	}

	/**
	 * Deletes the cache transient immediately.
	 * Call after any license change so the next page load gets a fresh result.
	 */
	public static function bust() {
		delete_transient( self::KEY );
	}

	/**
	 * Returns how many seconds ago the cache was set, or -1 when absent.
	 *
	 * @return int
	 */
	public static function age_seconds() {
		$data = self::get();
		if ( ! $data || empty( $data['cached_at'] ) ) {
			return -1;
		}
		return max( 0, time() - (int) $data['cached_at'] );
	}
}
