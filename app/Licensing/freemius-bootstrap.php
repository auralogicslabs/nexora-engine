<?php

/**
 * Nexora Engine — Freemius SDK Bootstrap
 *
 * ⚠️  This file MUST NOT declare a PHP namespace.
 *     ne_fs() must live in global scope so the Freemius SDK can locate it
 *     from any context (WP-CLI, REST API, cron, admin, front-end).
 *
 * SDK path (already installed):
 *   plugins/nexora-engine/vendor/freemius/
 *
 * ── LIVE / PRODUCTION (default) ────────────────────────────────────────────
 * Client sites need NOTHING in wp-config.php.  The public_key below is the
 * only credential Freemius requires for a standard install.  Everything else
 * (opt-in, licence activation, upgrade flow) is handled by the SDK automatically.
 *
 * ── SANDBOX / LOCAL TESTING (developer only) ─────────────────────────────────
 * Add to YOUR local wp-config.php only — never ship these to clients:
 *   define( 'WP_FS__DEV_MODE',              true );  // use Freemius sandbox API
 *   define( 'WP_FS__SKIP_EMAIL_ACTIVATION', true );  // skip opt-in email
 *   define( 'WP_FS__nexora-engine_SECRET_KEY', 'sk_...' ); // sandbox secret key
 *   define( 'NEXORA_DEV_MODE',              true );  // DevOverrides (fake licence)
 *   define( 'NEXORA_PRO_ENABLED',           true );  // simulate Pro tier
 *
 * The secret key is for developer admin API calls only and must never be
 * distributed inside the plugin ZIP or placed on client sites.
 *
 * @package NexoraEngine
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// ── Plan IDs ──────────────────────────────────────────────────────────────────
// After creating plans in the Freemius dashboard, paste the numeric IDs here.
// Find them in the dashboard URL when editing each plan: .../plan/XXXXX/edit
// Leave as 0 until plans exist — plan detection falls back to name matching and
// the pricing overlay is suppressed to prevent a JS crash.
if ( !defined( 'NEXENG_FS_PRO_PLAN_ID' ) ) {
    define( 'NEXENG_FS_PRO_PLAN_ID', 48706 );
    // Production Pro plan ID (Freemius dashboard)
}
if ( !defined( 'NEXENG_FS_LEGACY_AGENCY_PLAN_ID' ) ) {
    define( 'NEXENG_FS_LEGACY_AGENCY_PLAN_ID', 48707 );
    // Legacy Agency plan ID — maps to Pro
}
if ( !function_exists( 'ne_fs' ) ) {
    /**
     * Returns the Freemius singleton for Nexora Engine.
     * Returns null (graceful degradation) when the SDK is not yet installed.
     *
     * @return \Freemius|null
     */
    function ne_fs() {
        global $ne_fs;
        if ( isset( $ne_fs ) ) {
            return $ne_fs;
        }
        // ── Multisite network integration ─────────────────────────────────────
        if ( !defined( 'WP_FS__PRODUCT_29612_MULTISITE' ) ) {
            define( 'WP_FS__PRODUCT_29612_MULTISITE', true );
        }
        // ── Graceful degradation: SDK not installed ────────────────────────────
        // We use NEXORA_ENGINE_DIR (defined in nexora-engine.php before this file
        // is required) rather than dirname(__FILE__) because this file lives in
        // app/Licensing/ — not the plugin root where vendor/ resides.
        $sdk_path = NEXORA_ENGINE_DIR . 'vendor/freemius/start.php';
        if ( !file_exists( $sdk_path ) ) {
            return null;
        }
        // Suppress Freemius debug output unconditionally — SDK traces must never
        // appear in browser consoles for site visitors.
        //
        // NOTE ON THE WP_FS__ PREFIX: these constant names are defined by the
        // Freemius SDK (vendor/freemius/config.php), not by this plugin. They
        // are its documented configuration interface, so they are set here under
        // the names the SDK reads. Renaming them to a nexeng_ prefix would mean
        // the SDK never sees them and debug output would not be suppressed.
        // Every constant this plugin actually owns uses NEXORA_ENGINE_ or NEXENG_.
        // The WP_FS__* debug constants are not defined here. They belong to the
        // Freemius SDK, and defining them from this plugin means declaring
        // constants prefixed "WP_" — a prefix this plugin does not own. The SDK
        // already defaults both to false (vendor/freemius/config.php derives them
        // from WP_FS__DEV_MODE), so setting them changed nothing except to claim
        // a reserved-looking name.
        require_once $sdk_path;
        $ne_fs = fs_dynamic_init( array(
            'id'               => '29612',
            'slug'             => 'nexora-engine',
            'premium_slug'     => 'nexora-engine-license',
            'type'             => 'plugin',
            'public_key'       => 'pk_54593fb3dcb570c39ec0d83ab1cc7',
            'is_premium'       => false,
            'anonymous_mode'   => true,
            'has_addons'       => false,
            'has_paid_plans'   => true,
            'is_org_compliant' => true,
            'trial'            => array(
                'days'               => 14,
                'is_require_payment' => false,
            ),
            'menu'             => array(
                'slug'       => 'nexora',
                'first-path' => 'admin.php?page=nexora',
                'contact'    => false,
                'support'    => false,
                'network'    => true,
                'pricing'    => false,
            ),
            'is_live'          => true,
        ) );
        // ── UX: suppress Freemius-injected promotional UI ─────────────────────
        // We handle ALL upgrade flows through our own admin pages (License page,
        // feature-level CTAs).  The Freemius generic trial banner and its auto-
        // injected "Start Trial" submenu conflict with our UX and branding, so
        // we suppress them unconditionally.  Real free users see our own CTAs;
        // paid users see nothing promotional at all.
        $ne_fs->add_filter( 'show_trial', '__return_false' );
        $ne_fs->add_filter( 'show_admin_notification', '__return_false' );
        // ── UX: skip the deactivation feedback survey ─────────────────────────
        $ne_fs->add_filter( 'show_deactivation_feedback_form', '__return_false' );
        // ── Guard: suppress pricing overlay until plans exist in dashboard ─────
        // freemius-pricing.js crashes with Object.keys(null) when the Freemius
        // dashboard has no paid plans yet.  NEXENG_FS_PRO_PLAN_ID stays 0 until the
        // developer fills it in above, so the filter auto-lifts on the next deploy.
        if ( NEXENG_FS_PRO_PLAN_ID === 0 ) {
            $ne_fs->add_filter( 'is_pricing_page_visible', '__return_false' );
        }
        // ── Branded opt-in connect message ─────────────────────────────────────
        $ne_fs->add_filter(
            'connect_message',
            function (
                $message,
                $first_name,
                $plugin_title,
                $user_login,
                $site_link,
                $freemius_link
            ) {
                return sprintf( 
                    /* translators: 1: User's first name. 2: Plugin title (may contain bold HTML). */
                    __( 'Hi %1$s — to activate %2$s and receive automatic security &amp; feature updates, we need permission to collect anonymous usage diagnostics (no content, no personal data is ever transmitted). You can opt out at any time from the Account page.', 'nexora-engine' ),
                    esc_html( $first_name ),
                    $plugin_title
                 );
            },
            10,
            6
        );
        return $ne_fs;
    }

    // Kick off Freemius initialisation immediately so it registers its own
    // plugins_loaded / admin_init / admin_menu hooks at the correct time.
    ne_fs();
    // Signal that the SDK has been bootstrapped.
    do_action( 'ne_fs_loaded' );
}