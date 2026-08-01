<?php
/**
 * Nexora Engine — Grace Period
 *
 * 72-hour offline safety net for paid-plan users when Freemius is unreachable.
 *
 * How it differs from EntitlementCache:
 *   EntitlementCache (24h)  — reduces API calls; replaced on every Freemius success.
 *   GracePeriod     (72h)  — deep fallback; only ever records PAID plans; serves
 *                             the last known good tier when BOTH Freemius and the
 *                             EntitlementCache have failed.
 *
 * Real-world scenario:
 *   Day 0  — Freemius says Pro.  Cache + grace period both record Pro.
 *   Day 1  — Cache expired.  Freemius API unreachable.  Grace period still returns Pro.
 *   Day 3+ — Grace period also expires.  Plugin degrades to free tier.
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GracePeriod — 72-hour offline safety net for paid-plan users.
 */
class GracePeriod {

	/** WordPress transient key. */
	const KEY = 'nexeng_license_grace_v1';

	/** Grace window — 72 hours. */
	const TTL = 3 * DAY_IN_SECONDS;

	/**
	 * Record the last successfully verified paid plan.
	 *
	 * Call this every time Freemius returns pro or agency successfully.
	 * Free-plan responses are intentionally NOT recorded here — a free user
	 * who can't reach Freemius should remain free, not get a 72h upgrade.
	 *
	 * @param string $plan Plan slug: 'pro' | 'agency'.
	 */
	public static function record( $plan ) {
		if ( 'free' === $plan ) {
			return; // Only store paid tiers.
		}
		set_transient(
			self::KEY,
			array(
				'plan'        => (string) $plan,
				'recorded_at' => time(),
			),
			self::TTL
		);
	}

	/**
	 * Returns true when we are within the 72-hour grace window.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return false !== get_transient( self::KEY );
	}

	/**
	 * Returns the plan preserved during the grace window, or 'free' if not active.
	 *
	 * @return string
	 */
	public static function get_plan() {
		$data = get_transient( self::KEY );
		return ( is_array( $data ) && ! empty( $data['plan'] ) )
			? (string) $data['plan']
			: 'free';
	}

	/**
	 * Clears the grace-period transient immediately.
	 * Call after an explicit license deactivation or plan cancellation.
	 */
	public static function clear() {
		delete_transient( self::KEY );
	}

	/**
	 * Returns the number of seconds remaining in the grace window, or 0.
	 *
	 * @return int
	 */
	public static function seconds_remaining() {
		$data = get_transient( self::KEY );
		if ( ! is_array( $data ) || empty( $data['recorded_at'] ) ) {
			return 0;
		}
		$elapsed   = time() - (int) $data['recorded_at'];
		$remaining = self::TTL - $elapsed;
		return max( 0, $remaining );
	}
}
