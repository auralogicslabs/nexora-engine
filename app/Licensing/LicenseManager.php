<?php
/**
 * Nexora Engine — License Manager
 *
 * Thin public-API façade over FeatureGate.  All real resolution logic lives
 * in FeatureGate → FreemiusAdapter / EntitlementCache / GracePeriod / DevOverrides.
 *
 * LicenseManager exists for two reasons:
 *   1. Backward compatibility — legacy includes/class-ncx-*.php files call
 *      LicenseManager::instance()->can() and ->get_tier().
 *   2. Singleton entrypoint — PluginBootstrap::initialize() calls
 *      LicenseManager::instance() to ensure the licensing subsystem is
 *      registered early in the boot sequence.
 *
 * Do NOT add new business logic here.  Put it in FeatureGate or Entitlements.
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LicenseManager — Backward-compatible façade over the FeatureGate subsystem.
 */
class LicenseManager {

	// ── Plan constants ────────────────────────────────────────────────────────

	const TIER_FREE = 'free';
	const TIER_PRO  = 'pro';

	/** @deprecated 2.2.0 Agency tier removed — alias for TIER_PRO. */
	const TIER_AGENCY = 'pro';

	/** @deprecated 2.2.0 Legacy aliases — both map to Pro. */
	const TIER_ENTERPRISE = 'pro';
	const TIER_CLOUD      = 'pro';

	// ── Status constants ──────────────────────────────────────────────────────

	const STATUS_ACTIVE     = 'active';
	const STATUS_EXPIRED    = 'expired';
	const STATUS_SUSPENDED  = 'suspended';
	const STATUS_UNVERIFIED = 'unverified';

	// ── Singleton ─────────────────────────────────────────────────────────────

	/** @var self|null */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Lightweight constructor — no eager license loading.
	 * Plan resolution is deferred to FeatureGate on first ::can() / ::get_tier() call.
	 */
	private function __construct() {}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Returns the current license tier: 'free' | 'pro'.
	 *
	 * @return string
	 */
	public function get_tier() {
		return FeatureGate::get_plan();
	}

	/**
	 * Returns the current license status string.
	 *
	 * @return string  'active' | 'expired' | 'unverified'
	 */
	public function get_status() {
		$adapter = FreemiusAdapter::instance();

		if ( ! $adapter->is_available() ) {
			// If we're serving from cache or grace, mark as active for UX purposes.
			$cached = EntitlementCache::get_status();
			return $cached ?: self::STATUS_UNVERIFIED;
		}

		if ( $adapter->is_plan_expired() ) {
			return self::STATUS_EXPIRED;
		}

		if ( $adapter->is_plan_active() ) {
			return self::STATUS_ACTIVE;
		}

		return self::STATUS_UNVERIFIED;
	}

	/**
	 * Returns true when the license status is 'active'.
	 *
	 * @return bool
	 */
	public function is_active() {
		return self::STATUS_ACTIVE === $this->get_status();
	}

	/**
	 * Returns true when the current tier is Pro or above.
	 *
	 * @return bool
	 */
	public function is_pro() {
		return FeatureGate::is_plan_or_above( 'pro' );
	}

	/**
	 * Returns true when the current tier is Pro.
	 * Legacy alias kept for backward compatibility.
	 *
	 * @return bool
	 */
	public function is_agency() {
		return $this->is_pro();
	}

	/**
	 * Check whether a feature key is available for the current plan.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function can( $feature ) {
		return FeatureGate::can( $feature );
	}

	/**
	 * Alias for can() — matches old has_feature() call sites.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function has_feature( $feature ) {
		return $this->can( $feature );
	}

	/**
	 * Returns all feature keys available for a given tier.
	 * Kept for backward compatibility with legacy includes.
	 *
	 * @param string $tier Tier slug.
	 * @return string[]
	 */
	public function get_tier_features( $tier ) {
		return Entitlements::get_features_for_plan( $tier );
	}

	/**
	 * Returns a summary array for display / debugging.
	 *
	 * @return array
	 */
	public function get_info() {
		$adapter = FreemiusAdapter::instance();

		return array(
			'tier'         => $this->get_tier(),
			'status'       => $this->get_status(),
			'is_active'    => $this->is_active(),
			'dev_override' => DevOverrides::is_active(),
			// User-facing label. We intentionally do not expose the underlying
			// licence-vendor name here — that's an implementation detail. Users
			// see "Auralogics" for a verified paid licence and "Local only" when
			// no remote verification is available.
			'provider'     => $adapter->is_available() ? 'Auralogics' : 'Local only',
			'user_name'    => $adapter->get_user_name(),
			'user_email'   => $adapter->get_user_email(),
			'plan_title'   => $adapter->get_plan_title(),
			'expiry'       => $adapter->get_license_expiry(),
			'expiry_ts'    => $adapter->get_license_expiry_timestamp(),
			'site_count'   => $adapter->get_site_count(),
			'quota'        => $adapter->get_license_quota(),
		);
	}
}
