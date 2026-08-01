<?php
/**
 * Nexora Engine — Centralized Feature Gate System
 *
 * The single entry point for all feature-access checks throughout the plugin.
 * Replaces scattered "if ( is_pro() )" checks with a consistent, auditable API.
 *
 * ──────────────────────────────────────────────────────────────────────────────
 * USAGE:
 *   if ( Features::can( 'advanced_ghost_protocol' ) ) { ... }
 *   Features::check_or_notice( 'seo_intelligence', 'SEO Intelligence' );
 *   $url = Features::get_upgrade_url();
 * ──────────────────────────────────────────────────────────────────────────────
 *
 * @package NexoraEngine\Core
 */

namespace NexoraEngine\Core;

use NexoraEngine\Licensing\FeatureGate;
use NexoraEngine\Licensing\LicenseManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Features — Static façade over FeatureGate for the rest of the plugin.
 */
class Features {

	// ── Core checks ───────────────────────────────────────────────────────────

	/**
	 * Returns true when the current plan grants access to the given feature.
	 *
	 * @param string $feature Feature key (see Entitlements::$map).
	 * @return bool
	 */
	public static function can( $feature ) {
		return FeatureGate::can( $feature );
	}

	/**
	 * Alias for can() — matches the Nexora_Features::enabled() API
	 * specified in the commercialization master prompt.
	 *
	 * Usage: Nexora_Features::enabled( 'seo_intelligence' )
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function enabled( $feature ) {
		return self::can( $feature );
	}

	/**
	 * Returns true when ALL features in the list are available.
	 *
	 * @param string[] $features Feature keys.
	 * @return bool
	 */
	public static function can_all( array $features ) {
		foreach ( $features as $feature ) {
			if ( ! FeatureGate::can( $feature ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns true when ANY feature in the list is available.
	 *
	 * @param string[] $features Feature keys.
	 * @return bool
	 */
	public static function can_any( array $features ) {
		foreach ( $features as $feature ) {
			if ( FeatureGate::can( $feature ) ) {
				return true;
			}
		}
		return false;
	}

	// ── Plan checks ───────────────────────────────────────────────────────────

	/**
	 * Returns the active plan slug: 'free' | 'pro'.
	 *
	 * @return string
	 */
	public static function get_tier() {
		return FeatureGate::get_plan();
	}

	/**
	 * Returns true when the current plan exactly matches the given slug.
	 *
	 * @param string $tier Plan slug.
	 * @return bool
	 */
	public static function is_tier( $tier ) {
		return self::get_tier() === $tier;
	}

	/**
	 * Returns true when the current plan is at or above the required level.
	 *
	 * Plan hierarchy: free(0) < pro(1)
	 *
	 * Legacy aliases 'agency', 'enterprise', and 'cloud' map to Pro (level 1).
	 *
	 * @param string $tier Minimum tier required.
	 * @return bool
	 */
	public static function is_tier_or_above( $tier ) {
		$hierarchy = array(
			'free'       => 0,
			'pro'        => 1,
			'agency'     => 1,
			'enterprise' => 1,
			'cloud'      => 1,
		);

		$current  = isset( $hierarchy[ self::get_tier() ] ) ? $hierarchy[ self::get_tier() ] : 0;
		$required = isset( $hierarchy[ $tier ] )            ? $hierarchy[ $tier ]            : 0;

		return $current >= $required;
	}

	// ── UX helpers ────────────────────────────────────────────────────────────

	/**
	 * Check feature availability; show an admin notice if the feature is locked.
	 * Returns true when access is granted.
	 *
	 * @param string $feature Feature key.
	 * @param string $context Human-readable feature name for the notice text.
	 * @return bool
	 */
	public static function check_or_notice( $feature, $context = '' ) {
		if ( self::can( $feature ) ) {
			return true;
		}

		$label   = $context ?: ucwords( str_replace( '_', ' ', $feature ) );
		$message = sprintf(
			/* translators: %s: Feature name */
			esc_html__( '%s requires Nexora Engine Pro or above.', 'nexora-engine' ),
			$label
		);
		$message .= ' <a href="' . esc_url( self::get_upgrade_url() ) . '" target="_blank">'
			. esc_html__( 'Upgrade →', 'nexora-engine' )
			. '</a>';

		self::show_admin_notice( $message, 'warning' );

		return false;
	}

	/**
	 * Set a transient flag for a deferred upgrade notice.
	 * Call when you want to remind the user later (e.g., after a save action).
	 *
	 * @param string $feature Feature key.
	 */
	public static function redirect_to_upgrade( $feature ) {
		if ( self::can( $feature ) ) {
			return;
		}
		set_transient( 'nexora_upgrade_notice_' . $feature, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Returns the Freemius upgrade checkout URL.
	 *
	 * @param string $plan Target plan ('pro').
	 * @return string
	 */
	public static function get_upgrade_url( $plan = 'pro' ) {
		return FeatureGate::get_upgrade_url( $plan );
	}

	// ── License info ──────────────────────────────────────────────────────────

	/**
	 * Returns a summary array of the current license state.
	 * Useful for admin pages and debug output.
	 *
	 * @return array
	 */
	public static function get_license_info() {
		return LicenseManager::instance()->get_info();
	}

	/**
	 * Returns all feature keys available for the current plan.
	 *
	 * @return string[]
	 */
	public static function get_available_features() {
		return LicenseManager::instance()->get_tier_features( self::get_tier() );
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	/**
	 * Queue an admin notice (admin context only).
	 *
	 * @param string $message Escaped / wp_kses_post safe HTML.
	 * @param string $type    Notice type: 'error' | 'warning' | 'success' | 'info'.
	 */
	private static function show_admin_notice( $message, $type = 'info' ) {
		if ( ! is_admin() ) {
			return;
		}
		add_action(
			'admin_notices',
			static function() use ( $message, $type ) {
				?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Nexora Engine:', 'nexora-engine' ); ?></strong>
						<?php echo wp_kses_post( $message ); ?>
					</p>
				</div>
				<?php
			}
		);
	}
}
