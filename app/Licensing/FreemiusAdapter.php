<?php
/**
 * Nexora Engine — Freemius Adapter
 *
 * The ONLY class in the entire codebase that directly calls ne_fs().
 * All other Licensing classes interact with Freemius exclusively through
 * this adapter, making it trivial to swap the back-end in the future.
 *
 * Every public method:
 *   – Returns a safe default when the SDK is unavailable.
 *   – Wraps Freemius calls in try/catch to prevent fatals on SDK changes.
 *   – Never throws — always degrades to a sensible value.
 *
 * @package NexoraEngine\Licensing
 */

namespace NexoraEngine\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FreemiusAdapter — Isolation layer between Freemius SDK and plugin internals.
 */
class FreemiusAdapter {

	/** @var self|null */
	private static $instance = null;

	/** @var \Freemius|null */
	private $fs = null;

	/** @var bool SDK loaded and object is a valid \Freemius instance */
	private $available = false;

	// ── Singleton ─────────────────────────────────────────────────────────────

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->boot();
	}

	// ── Boot ──────────────────────────────────────────────────────────────────

	private function boot() {
		// ne_fs() is defined in freemius-bootstrap.php (global scope, no namespace).
		// It returns null when the vendor/freemius/ SDK directory is not installed yet.
		if ( ! function_exists( 'ne_fs' ) ) {
			return;
		}
		try {
			$fs = ne_fs();
			if ( $fs instanceof \Freemius ) {
				$this->fs        = $fs;
				$this->available = true;
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Nexora Engine] FreemiusAdapter::boot() — ' . $e->getMessage() );
		}
	}

	// ── Availability ──────────────────────────────────────────────────────────

	/**
	 * Returns true when the SDK is installed and the Freemius object is ready.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->available;
	}

	// ── Plan ──────────────────────────────────────────────────────────────────

	/**
	 * Returns the active plan slug: 'free' | 'pro'.
	 *
	 * Resolution order (most-to-least stable):
	 *
	 *   1. has_active_valid_license() / is_paying()
	 *      Confirms a paid license exists before checking tier.
	 *
	 *   2. Plan ID map  (most stable — immune to dashboard renames)
	 *      Optional. Populated via:
	 *        define( 'NEXORA_FS_PRO_PLAN_ID',    12345 );  // in wp-config.php
	 *        define( 'NEXENG_FS_LEGACY_AGENCY_PLAN_ID', 12346 ); // legacy — maps to pro
	 *      Or via WordPress filter:
	 *        add_filter( 'nexora_fs_plan_id_map', fn($m) => $m + [12345=>'pro'] );
	 *      When no IDs are configured this step is skipped gracefully.
	 *
	 *   3. Plan name/slug matching  (fallback)
	 *      Uses $plan->name (the internal SDK slug, NOT the marketing title).
	 *      Case-insensitive partial match. Legacy Agency / Enterprise plans map to Pro.
	 *
	 * Why we do NOT use is_plan():
	 *   Freemius's is_plan() is case-sensitive against $plan->name.  Any casing
	 *   mismatch returns false → plugin silently falls back to 'free' after
	 *   a successful checkout.
	 *
	 * @return string  'free' | 'pro'
	 */
	public function get_plan() {
		if ( ! $this->available ) {
			return 'free';
		}
		try {
			// ── 1. Valid paid license? ────────────────────────────────────────────
			$has_license = $this->fs->has_active_valid_license();
			if ( ! $has_license ) {
				// is_paying() catches grandfathered / lifetime paid installs.
				if ( ! $this->fs->is_paying() ) {
					return 'free';
				}
			}

			// ── 2. Plan ID mapping (most stable) ──────────────────────────────────
			// Plan IDs are numeric and never change even if the plan is renamed
			// in the Freemius dashboard.  Skip gracefully when no IDs are configured.
			$id_map = $this->get_plan_id_map();
			if ( ! empty( $id_map ) ) {
				$plan_obj = $this->fs->get_plan();
				if ( $plan_obj && isset( $plan_obj->id ) ) {
					$plan_id = (int) $plan_obj->id;
					if ( isset( $id_map[ $plan_id ] ) ) {
						return (string) $id_map[ $plan_id ];
					}
				}
			}

			// ── 3. Plan name/slug matching (fallback) ─────────────────────────────
			// get_plan_name() returns $plan->name — the internal slug set by the
			// developer in the Freemius dashboard, NOT the public marketing title.
			// We lowercase and partial-match to handle any casing variant.
			$plan_name = strtolower( (string) $this->fs->get_plan_name() );

			// Any active paid plan (including legacy Agency / Enterprise names) → Pro.
			return 'pro';

		} catch ( \Throwable $e ) {
			// Fall through to free on any SDK error.
		}
		return 'free';
	}

	/**
	 * Returns the plan-ID → tier map for use in get_plan().
	 *
	 * IDs come from NEXENG_FS_PRO_PLAN_ID / NEXENG_FS_LEGACY_AGENCY_PLAN_ID defined in
	 * freemius-bootstrap.php — edit those constants after creating plans in
	 * the Freemius dashboard.  Returns an empty array when IDs are still 0,
	 * causing get_plan() to fall through to name-based matching.
	 *
	 * @return array<int,string>  plan_id → 'pro'
	 */
	private function get_plan_id_map() {
		$map = array();

		if ( defined( 'NEXENG_FS_PRO_PLAN_ID' ) && NEXENG_FS_PRO_PLAN_ID > 0 ) {
			$map[ (int) NEXENG_FS_PRO_PLAN_ID ] = 'pro';
		}
		// Legacy Agency plan IDs — grandfathered customers receive Pro entitlements.
		$legacy_agency_id = 0;
		if ( defined( 'NEXENG_FS_LEGACY_AGENCY_PLAN_ID' ) && NEXENG_FS_LEGACY_AGENCY_PLAN_ID > 0 ) {
			$legacy_agency_id = (int) NEXENG_FS_LEGACY_AGENCY_PLAN_ID;
		} elseif ( defined( 'NEXENG_FS_AGENCY_PLAN_ID' ) && NEXENG_FS_AGENCY_PLAN_ID > 0 ) {
			$legacy_agency_id = (int) NEXENG_FS_AGENCY_PLAN_ID;
		}
		if ( $legacy_agency_id > 0 ) {
			$map[ $legacy_agency_id ] = 'pro';
		}

		return $map;
	}

	/**
	 * Returns true when the site has a paid, non-expired license.
	 *
	 * Uses Freemius's has_active_valid_license() which is the canonical
	 * method for checking whether a license is present AND not expired.
	 * (Freemius does not expose a public is_plan_active() method.)
	 *
	 * @return bool
	 */
	public function is_plan_active() {
		if ( ! $this->available ) {
			return false;
		}
		try {
			return (bool) $this->fs->has_active_valid_license();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Returns true when the site has a license that is present but expired.
	 *
	 * Checks the cached license object directly — Freemius does not expose
	 * a public is_plan_expired() method on the Freemius class itself.
	 *
	 * @return bool
	 */
	public function is_plan_expired() {
		if ( ! $this->available ) {
			return false;
		}
		try {
			$license = $this->fs->_get_license();
			if ( ! $license || ! is_object( $license ) ) {
				return false;
			}
			return (bool) $license->is_expired();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	// ── Force sync ───────────────────────────────────────────────────────────

	/**
	 * Tell Freemius to re-fetch the current install state from its API.
	 *
	 * Calling sync_install( [], true ) forces a fresh round-trip to Freemius
	 * servers, updating the local $this->_site, $this->_license, and plan
	 * objects.  After this call, has_active_valid_license() returns the real
	 * current state rather than what was last cached in WP options.
	 *
	 * This is intentionally synchronous — it is only called when the admin
	 * explicitly clicks "Sync license state", not on every page load.
	 *
	 * @return bool  True if sync was attempted; false if SDK unavailable.
	 */
	public function force_sync() {
		if ( ! $this->available ) {
			return false;
		}
		try {
			$this->fs->sync_install( array(), true );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	// ── Upgrade / Account URLs ────────────────────────────────────────────────

	/**
	 * Returns the upgrade destination for the "Go Pro" CTA.
	 *
	 * IMPORTANT: We intentionally do NOT use Freemius's own get_upgrade_url()
	 * here. That method points at the in-plugin Freemius pricing page
	 * (admin.php?page=<slug>-pricing), but we deliberately disable that page in
	 * freemius-bootstrap.php ('pricing' => false + is_pricing_page_visible =>
	 * false) because the Freemius pricing overlay JS crashes until plans are
	 * configured. Returning the Freemius URL would therefore send users to a
	 * "you are not allowed to access this page" screen.
	 *
	 * Instead we send users to our own public pricing/checkout page. When the
	 * in-plugin pricing page is re-enabled in the future, switch this back to
	 * $this->fs->get_upgrade_url().
	 *
	 * @param string $plan Target plan slug ('pro').
	 * @return string
	 */
	public function get_upgrade_url( $plan = 'pro' ) {
		$fallback = 'https://auralogicslabs.com/products/nexora-engine/#pricing';

		if ( ! $this->available ) {
			return $fallback;
		}

		// Allow re-enabling the native Freemius pricing page later via filter.
		$use_native = (bool) apply_filters( 'nexeng_use_freemius_pricing_page', false );
		if ( ! $use_native ) {
			return $fallback;
		}

		try {
			$url = (string) $this->fs->get_upgrade_url();
			return $url !== '' ? esc_url( $url ) : $fallback;
		} catch ( \Throwable $e ) {
			return $fallback;
		}
	}

	/**
	 * Returns the Freemius account/billing dashboard URL.
	 *
	 * @return string
	 */
	public function get_account_url() {
		if ( ! $this->available ) {
			return '';
		}
		try {
			return esc_url( $this->fs->get_account_url() );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	// ── User & License Info ───────────────────────────────────────────────────

	/**
	 * Returns the license-holder's display name.
	 *
	 * @return string
	 */
	public function get_user_name() {
		if ( ! $this->available ) {
			return '';
		}
		try {
			$user = $this->fs->get_user();
			return $user ? esc_html( $user->get_name() ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Returns the license-holder's email address.
	 *
	 * @return string
	 */
	public function get_user_email() {
		if ( ! $this->available ) {
			return '';
		}
		try {
			$user = $this->fs->get_user();
			return $user ? esc_html( $user->email ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Returns the number of sites activated on the current license.
	 *
	 * @return int
	 */
	public function get_site_count() {
		if ( ! $this->available ) {
			return 0;
		}
		try {
			$license = $this->fs->_get_license();
			return $license ? (int) $license->activated : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Returns a formatted license expiry date, or 'Lifetime' for perpetual licenses.
	 *
	 * Returns '' when there is no license at all. This distinction matters: a free
	 * install has no license object, and treating that the same as "a license with
	 * no expiration date" told free users they held a lifetime license. Only an
	 * actual license record with an empty expiration is perpetual.
	 *
	 * @return string
	 */
	public function get_license_expiry() {
		if ( ! $this->available ) {
			return '';
		}
		try {
			$license = $this->fs->_get_license();
			if ( ! $license ) {
				return '';
			}
			if ( empty( $license->expiration ) ) {
				return 'Lifetime';
			}
			return esc_html(
				date_i18n( get_option( 'date_format' ), strtotime( $license->expiration ) )
			);
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Returns the license expiry as a Unix timestamp, or 0 for lifetime/unavailable.
	 *
	 * @return int
	 */
	public function get_license_expiry_timestamp() {
		if ( ! $this->available ) {
			return 0;
		}
		try {
			$license = $this->fs->_get_license();
			if ( ! $license || empty( $license->expiration ) ) {
				return 0;
			}
			return (int) strtotime( $license->expiration );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Returns the maximum number of sites the license allows, or 0 if unlimited/unavailable.
	 *
	 * @return int
	 */
	public function get_license_quota() {
		if ( ! $this->available ) {
			return 0;
		}
		try {
			$license = $this->fs->_get_license();
			if ( ! $license ) {
				return 0;
			}
			$quota = isset( $license->quota ) ? (int) $license->quota : 0;
			return $quota > 0 ? $quota : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Returns the human-readable plan label from Freemius.
	 *
	 * @return string e.g. 'Pro', 'Free'
	 */
	public function get_plan_title() {
		if ( ! $this->available ) {
			return 'Free';
		}
		try {
			if ( 'pro' === $this->get_plan() ) {
				return 'Pro';
			}
			$plan = $this->fs->get_plan();
			return $plan ? esc_html( $plan->title ) : 'Free';
		} catch ( \Throwable $e ) {
			return 'Free';
		}
	}
}
