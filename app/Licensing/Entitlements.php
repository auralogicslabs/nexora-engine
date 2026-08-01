<?php
/**
 * Nexora Engine — Entitlements Map
 *
 * Single source of truth for the feature → minimum-plan mapping.
 * Plans: free(0) < pro(1)
 *
 * HOW TO ADD A FEATURE:
 *   1. Add one entry here with the minimum plan required.
 *   2. That is all. No other file needs updating.
 *
 * HOW TO CHECK A FEATURE:
 *   Use FeatureGate::can('feature_key') — do NOT call this class directly
 *   from business logic.  Entitlements is consumed only by FeatureGate.
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entitlements — Declares the minimum plan for every feature.
 */
class Entitlements {

	// Plan slug constants.
	const PLAN_FREE = 'free';
	const PLAN_PRO  = 'pro';

	/** @deprecated 2.2.0 Agency tier removed — use PLAN_PRO. Kept for legacy call sites. */
	const PLAN_AGENCY = 'pro';

	/**
	 * Feature → minimum plan map.
	 *
	 * 'free' = available to WP.org free downloads and all paid users.
	 * 'pro'  = requires Pro license.
	 *
	 * @var array<string,string>
	 */
	private static $map = array(

		// ── Free tier ─────────────────────────────────────────────────────────
		'static_delivery'          => 'free',   // Pre-rendered static HTML delivery
		'spa_navigation'           => 'free',   // Client-side SPA routing support
		'regeneration'             => 'free',   // Manual page regeneration
		'compatibility_layer'      => 'free',   // Plugin conflict resolution
		'elementor_compat'         => 'free',   // Elementor builder compatibility
		'gutenberg_compat'         => 'free',   // Block editor compatibility
		'asset_optimization'       => 'free',   // CSS/JS asset optimisation
		'delivery_diagnostics'     => 'free',   // Basic delivery diagnostics
		'cache_handling'           => 'free',   // Core cache purge / management
		'browser_optimization'     => 'free',   // Browser caching headers
		'basic_security'           => 'free',   // Basic WordPress hardening rules
		'health_checks'            => 'free',   // Site health status monitoring
		'delivery_metrics'         => 'free',   // Basic TTFB / hit-miss metrics
		'ghost_lite'               => 'free',   // Ghost Lite: basic WP fingerprint removal

		// Previously listed as Pro, now free. Each of these was implemented by a
		// class that ships in the free build and was never actually gated at
		// runtime, so calling them "Pro" locked nothing and simply misdescribed
		// the plugin. Under WordPress.org Guideline 5 a feature that ships must
		// be fully usable, so they are declared free — which is what they have
		// always been in practice. No behaviour changed when these moved.
		'rest_cloaking'            => 'free',  // class-ncx-init.php — ships and runs for everyone
		'wp_masking'               => 'free',  // class-ncx-init.php — ships and runs for everyone
		// ── Pro tier ──────────────────────────────────────────────────────────
		// POLICY: a key may only be listed here when the file named beside it
		// carries the __premium_only suffix, so Freemius physically removes it
		// from the WordPress.org build. Anything that ships in the free zip must
		// be 'free' — a runtime licence check over shipped code is trialware
		// (Guideline 5: not allowed 'even if the locked feature is present in the
		// code just in case the user upgrades').
		//
		// The filename goes on the SAME line as the key: scripts/verify-free-build.mjs
		// reads it from there to confirm the file exists and is stripped.
		//
		// Every entry must also map to functionality that actually ships in the
		// Pro build: a buyer who upgrades and finds an empty feature churns.
		// Aspirational items belong in the readme roadmap, not here.
		'smart_automation'         => 'pro',   // class-ncx-ssg-auto__premium_only.php
		'seo_intelligence'         => 'pro',   // class-ncx-seo-pro__premium_only.php
		'advanced_security'        => 'pro',   // class-ncx-hardening-pro__premium_only.php
		'advanced_ghost_protocol'  => 'pro',   // class-ncx-ghost-pro__premium_only.php
		'scheduled_regeneration'   => 'pro',   // class-ncx-scheduler__premium_only.php
		'core_web_vitals'          => 'pro',   // class-ncx-gsc__premium_only.php
		'global_cdn'               => 'pro',   // class-ncx-cdn__premium_only.php
		'infrastructure_reports'   => 'pro',   // class-ncx-pdf-report__premium_only.php
		'portal_connectivity'      => 'pro',   // class-ncx-portal-api__premium_only.php
		'white_label_support'      => 'pro',   // class-ncx-white-label__premium_only.php
		'multisite_orchestration'  => 'pro',   // class-ncx-multisite__premium_only.php
		'fleet_dashboard'          => 'pro',   // class-ncx-network-admin__premium_only.php
		'network_controls'         => 'pro',   // class-ncx-network-admin__premium_only.php
	);

	/**
	 * Returns the numeric hierarchy level for a plan slug.
	 * Unknown slugs resolve to 0 (free).
	 *
	 * Legacy slugs agency / enterprise / cloud resolve to Pro (level 1).
	 *
	 * @param string $plan Plan slug.
	 * @return int  0 = free | 1 = pro
	 */
	public static function plan_level( $plan ) {
		$levels = array(
			'free'       => 0,
			'pro'        => 1,
			'agency'     => 1,
			'enterprise' => 1,
			'cloud'      => 1,
		);
		return isset( $levels[ $plan ] ) ? $levels[ $plan ] : 0;
	}

	/**
	 * Normalizes legacy plan slugs to the active two-tier model.
	 *
	 * @param string $plan Raw plan slug.
	 * @return string 'free' | 'pro'
	 */
	public static function normalize_plan( $plan ) {
		if ( self::plan_level( $plan ) >= 1 ) {
			return self::PLAN_PRO;
		}
		return self::PLAN_FREE;
	}

	/**
	 * Check whether a plan has access to a feature.
	 *
	 * @param string $plan    Resolved plan slug.
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function plan_has_feature( $plan, $feature ) {
		$min_plan = isset( self::$map[ $feature ] ) ? self::$map[ $feature ] : 'pro';
		return self::plan_level( $plan ) >= self::plan_level( $min_plan );
	}

	/**
	 * Returns all feature keys available for a given plan (cumulative).
	 *
	 * @param string $plan Plan slug.
	 * @return string[]
	 */
	public static function get_features_for_plan( $plan ) {
		$plan_level = self::plan_level( $plan );
		$available  = array();
		foreach ( self::$map as $feature => $min_plan ) {
			if ( $plan_level >= self::plan_level( $min_plan ) ) {
				$available[] = $feature;
			}
		}
		return $available;
	}

	/**
	 * Returns the minimum plan required for a feature.
	 *
	 * @param string $feature Feature key.
	 * @return string Plan slug ('free' | 'pro').
	 */
	public static function get_required_plan( $feature ) {
		return isset( self::$map[ $feature ] ) ? self::$map[ $feature ] : 'pro';
	}

	/**
	 * Returns the full feature → plan map (useful for admin UI / debugging).
	 *
	 * @return array<string,string>
	 */
	public static function get_map() {
		return self::$map;
	}
}
