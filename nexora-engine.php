<?php
/**
 * Nexora Engine
 *
 * A modern WordPress Infrastructure Intelligence Platform.
 * Enterprise-grade static delivery, Ghost Protocol security, and SaaS-ready architecture.
 *
 * @package           NexoraEngine
 * @author            Auralogics Labs
 * @license           GPL-2.0-or-later
 * @link              https://auralogicslabs.com
 *
 * Plugin Name:       Nexora Engine
 * Plugin URI:        https://auralogicslabs.com/products/nexora-engine
 * Description:       Enterprise WordPress infrastructure platform — static delivery, Ghost Protocol fingerprint cloaking, and intelligent automation.
 * Version:           1.0.0
 * Author:            Auralogics Labs
 * Author URI:        https://auralogicslabs.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nexora-engine
 * Domain Path:       /languages
 * Requires at least: 6.1
 * Requires PHP:      8.0
 * Tested up to:      7.0
 */

namespace NexoraEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// PLUGIN CONSTANTS
// ──────────────────────────────────────────────────────────────────────────────

define( 'NEXORA_ENGINE_FILE',      __FILE__ );
define( 'NEXORA_ENGINE_DIR',       plugin_dir_path( __FILE__ ) );
define( 'NEXORA_ENGINE_URL',       plugin_dir_url( __FILE__ ) );
define( 'NEXORA_ENGINE_VERSION',   '1.0.0' );
define( 'NEXORA_ENGINE_NAMESPACE', 'NexoraEngine' );
define( 'NEXORA_ENGINE_SLUG',      'nexora-engine' );

if ( ! defined( 'NEXORA_ENGINE_START_TIME' ) ) {
	define( 'NEXORA_ENGINE_START_TIME', microtime( true ) );
}

// ──────────────────────────────────────────────────────────────────────────────
// COMPATIBILITY & SAFETY CHECKS
// ──────────────────────────────────────────────────────────────────────────────

if ( version_compare( $GLOBALS['wp_version'], '6.1', '<' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Nexora Engine requires WordPress 6.1 or higher.', 'nexora-engine' );
		echo '</p></div>';
	} );
	return;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Nexora Engine requires PHP 7.4 or higher.', 'nexora-engine' );
		echo '</p></div>';
	} );
	return;
}

// ──────────────────────────────────────────────────────────────────────────────
// SHORTHAND CONSTANTS
// The includes/ class files use the short NEXENG_ constant names; map them to the
// canonical NEXORA_ENGINE_ constants here. Both prefixes are 4+ chars (wp.org
// compliant); NEXENG_ is simply the concise form used across the class layer.
// ──────────────────────────────────────────────────────────────────────────────

if ( ! defined( 'NEXENG_VERSION' ) ) {
	define( 'NEXENG_VERSION', NEXORA_ENGINE_VERSION );
}
if ( ! defined( 'NEXENG_PLUGIN_DIR' ) ) {
	define( 'NEXENG_PLUGIN_DIR', NEXORA_ENGINE_DIR );
}
if ( ! defined( 'NEXENG_PLUGIN_URL' ) ) {
	define( 'NEXENG_PLUGIN_URL', NEXORA_ENGINE_URL );
}

// ──────────────────────────────────────────────────────────────────────────────
// FREEMIUS SDK BOOTSTRAP
// Must run before the autoloader so ne_fs() exists in global scope before any
// Licensing class references it.  SDK path: vendor/freemius/start.php
// Keys (NEXORA_FS_PUBLIC_KEY, NEXORA_FS_SECRET_KEY) live ONLY in wp-config.php.
// ──────────────────────────────────────────────────────────────────────────────

require_once NEXORA_ENGINE_DIR . 'app/Licensing/freemius-bootstrap.php';

// ──────────────────────────────────────────────────────────────────────────────
// UNINSTALL CLEANUP (via Freemius)
// Freemius manages uninstall and requires the teardown to run through its
// `after_uninstall` action rather than a standalone uninstall.php. Load the
// cleanup function and register it with the SDK.
// ──────────────────────────────────────────────────────────────────────────────
require_once NEXORA_ENGINE_DIR . 'includes/uninstall-cleanup.php';
if ( function_exists( 'ne_fs' ) ) {
	ne_fs()->add_action( 'after_uninstall', 'nexeng_run_uninstall_cleanup' );
}

// ──────────────────────────────────────────────────────────────────────────────
// PSR-4 AUTOLOADER & ENTERPRISE BOOTSTRAP
// ──────────────────────────────────────────────────────────────────────────────

require_once NEXORA_ENGINE_DIR . 'app/Autoloader.php';
Autoloader::init( NEXORA_ENGINE_DIR . 'app' );

// Enterprise bootstrap (registers plugins_loaded, activation/deactivation hooks,
// loads all legacy includes in the correct dependency order, inits NEXENG_Admin, etc.)
Core\PluginBootstrap::instance();

// ──────────────────────────────────────────────────────────────────────────────
// ONE-TIME PREFIX MIGRATION  (ncx_ → nexeng_)
// The plugin's data prefix changed from the too-short "ncx" (3 chars) to the
// wp.org-compliant "nexeng". This copies any surviving old keys to the new names
// so an existing install keeps its settings, then removes the old keys. Runs once,
// gated by the nexeng_prefix_migrated flag. Reserved wire-format tokens (the
// _ncx_v12 static-URL path) are NOT data keys and are untouched.
// ──────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'nexeng_legacy_option_names' ) ) {
	/**
	 * Option name suffixes this plugin has used.
	 *
	 * The migration below walks this list rather than running a "ncx_%" LIKE
	 * query, so it can only touch options this plugin created. A four-letter
	 * prefix is not ours exclusively, and the old query deleted every match
	 * after copying it — including another plugin's settings.
	 *
	 * Derived from the option call sites in this plugin.
	 *
	 * @return string[]
	 */
	function nexeng_legacy_option_names(): array {
		return [
		'admin_bar_badge', 'analytics_enabled', 'anonymize_ips',
		'api_key', 'asset_base', 'asset_mode',
		'auto_rebuild', 'cdn_auto_purge', 'cdn_bunny_api_key',
		'cdn_bunny_zone_id', 'cdn_cf_api_token', 'cdn_cf_zone_id',
		'cdn_generic_purge', 'db_version', 'dropin_last_error',
		'dropin_runtime_rev', 'dropin_wpcache_writable', 'headless_mode',
		'http_auth_pass', 'http_auth_user', 'install_id',
		'portal_connected', 'portal_key', 'portal_site_id',
		'portal_token', 'prefix_migrated', 'pro_regen_needed',
		'proxy_mode', 'proxy_mode_detected_at', 'revalidate_secret',
		'revalidate_url', 'schema_enabled', 'secure_author_enum',
		'secure_disable_file_edit', 'secure_headers', 'secure_login_errors',
		'secure_login_rename', 'secure_login_slug', 'secure_rate_limit',
		'secure_remove_version', 'secure_rest_tighten', 'secure_strong_pass',
		'secure_users_api', 'secure_xmlrpc', 'sitemap_enabled',
		'ssg_archives_dirty', 'ssg_auto_rollback_20260519', 'ssg_blueprint_hash',
		'ssg_capture_lock', 'ssg_drive_secret', 'ssg_enabled',
		'ssg_errors', 'ssg_excluded_types', 'ssg_fatal_pages',
		'ssg_last_build_at', 'ssg_last_bulk_at', 'ssg_last_purge_at',
		'ssg_pending_posts', 'ssg_script_hosts', 'version',
		'wizard_completed',
		];
	}
}

if ( ! function_exists( 'nexeng_migrate_prefix' ) ) {
	/**
	 * Migrate ncx_ / _ncx_ prefixed options, transients, post-meta and user-meta
	 * to the nexeng_ / _nexeng_ prefix. Idempotent; safe to call on every load.
	 */
	function nexeng_migrate_prefix() {
		if ( get_option( 'nexeng_prefix_migrated' ) ) {
			return;
		}

		global $wpdb;

		// ── Options: rename ncx_* → nexeng_* ───────────────────────────────────
		// This used to select every option matching 'ncx\_%' and delete each one
		// after copying it. "ncx" is four characters and not ours exclusively, so
		// any other plugin using the same prefix had its settings renamed out
		// from under it and the originals removed. Destroying another plugin's
		// data to tidy up our own is never an acceptable trade.
		//
		// The migration now walks only names this plugin is known to have used,
		// so an option we never created is never touched.
		foreach ( nexeng_legacy_option_names() as $suffix ) {
			$old_name = 'ncx_' . $suffix;
			$new_name = 'nexeng_' . $suffix;

			// get_option() cannot distinguish "absent" from "stored false", so
			// ask the options table directly before deleting anything.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$old_name
			) );
			if ( null === $exists ) {
				continue;
			}

			if ( null === get_option( $new_name, null ) ) {
				add_option( $new_name, get_option( $old_name ), '', 'no' );
			}
			delete_option( $old_name );
		}

		// ── Transients (option-backed): _transient_ncx_* and timeouts. ─────────
		$wpdb->query(
			"UPDATE {$wpdb->options}
			 SET option_name = REPLACE( option_name, '_transient_ncx_', '_transient_nexeng_' )
			 WHERE option_name LIKE '\\_transient\\_ncx\\_%'"
		);
		$wpdb->query(
			"UPDATE {$wpdb->options}
			 SET option_name = REPLACE( option_name, '_transient_timeout_ncx_', '_transient_timeout_nexeng_' )
			 WHERE option_name LIKE '\\_transient\\_timeout\\_ncx\\_%'"
		);

		// ── Post meta: _ncx_* → _nexeng_* and ncx_* → nexeng_*. ────────────────
		$wpdb->query(
			"UPDATE {$wpdb->postmeta}
			 SET meta_key = CONCAT( '_nexeng_', SUBSTRING( meta_key, LENGTH('_ncx_') + 1 ) )
			 WHERE meta_key LIKE '\\_ncx\\_%'"
		);
		$wpdb->query(
			"UPDATE {$wpdb->postmeta}
			 SET meta_key = CONCAT( 'nexeng_', SUBSTRING( meta_key, LENGTH('ncx_') + 1 ) )
			 WHERE meta_key LIKE 'ncx\\_%'"
		);

		// ── User meta: same two forms. ─────────────────────────────────────────
		$wpdb->query(
			"UPDATE {$wpdb->usermeta}
			 SET meta_key = CONCAT( '_nexeng_', SUBSTRING( meta_key, LENGTH('_ncx_') + 1 ) )
			 WHERE meta_key LIKE '\\_ncx\\_%'"
		);
		$wpdb->query(
			"UPDATE {$wpdb->usermeta}
			 SET meta_key = CONCAT( 'nexeng_', SUBSTRING( meta_key, LENGTH('ncx_') + 1 ) )
			 WHERE meta_key LIKE 'ncx\\_%'"
		);

		update_option( 'nexeng_prefix_migrated', 1, 'no' );
	}
}

// This file is under the NexoraEngine namespace, so the function is actually
// NexoraEngine\nexeng_migrate_prefix(). WordPress resolves string callbacks in the
// GLOBAL namespace, so we must pass the fully-qualified name (or it silently fails
// to find a global function of the same name and fatals).
add_action( 'plugins_loaded', __NAMESPACE__ . '\\nexeng_migrate_prefix', 1 );

// ──────────────────────────────────────────────────────────────────────────────
// FREEMIUS LICENSE-EVENT HOOKS
// Bust all licensing caches whenever the user activates, deactivates, or changes
// their plan so the next page load fetches a fresh result from Freemius.
// Runs at plugins_loaded @15, after Freemius finishes its own init (@10).
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', static function() {
	if ( ! function_exists( 'ne_fs' ) ) {
		return;
	}
	$fs = ne_fs();
	if ( ! ( $fs instanceof \Freemius ) ) {
		return;
	}

	// ── Cache-bust callback ───────────────────────────────────────────────────
	$bust = static function() {
		\NexoraEngine\Licensing\FeatureGate::bust_all_caches();
	};

	// ── License lifecycle ─────────────────────────────────────────────────────
	// after_license_activation   — redirect-return request after successful checkout
	// after_license_deactivation — user deactivates via Account page
	// after_license_change       — upgrade / downgrade / expiry change
	// after_license_expiration   — license expires (where supported by SDK version)
	//   NOTE: 'after_plan_change' does NOT exist in the Freemius SDK.
	//         The real hook is 'after_license_change'.
	$fs->add_action( 'after_license_activation',   $bust );
	$fs->add_action( 'after_license_deactivation', $bust );
	$fs->add_action( 'after_license_change',       $bust );
	$fs->add_action( 'after_license_expiration',   $bust ); // SDK ≥ 2.3

	// ── Plan-sync events ──────────────────────────────────────────────────────
	// after_account_plan_sync   — fires after Freemius syncs plan state from API
	// after_plans_sync          — fires when the plan list is refreshed from API
	// after_account_connection  — fires the first time a user connects their account
	$fs->add_action( 'after_account_plan_sync',  $bust );
	$fs->add_action( 'after_plans_sync',         $bust );
	$fs->add_action( 'after_account_connection', $bust );

	// ── Mark just-activated + auto-enable Ghost Protocol ─────────────────────
	$fs->add_action( 'after_license_activation', static function() {
		set_transient( 'nexeng_just_activated', 1, 5 * MINUTE_IN_SECONDS );
		// Ghost Protocol is safe to auto-enable — pure header/meta stripping,
		// no server config required. Stealth Proxy stays OFF; user enables it
		// deliberately from the Headless page when their server is ready.
		if ( get_option( 'nexeng_headless_mode' ) !== 'on' ) {
			update_option( 'nexeng_headless_mode', 'on' );
			set_transient( 'nexeng_ghost_auto_enabled', 1, HOUR_IN_SECONDS );
		}

		// ── Persistent "Pro regen needed" flag ──────────────────────────────
		// If the user already had static pages captured under the Free tier,
		// those files don't reflect Pro-only optimisations (advanced SEO
		// capture, Stealth-Proxy-ready URLs, header hardening parity, etc.).
		// Set a sticky option (not a transient) so the banner shown on every
		// Nexora admin page won't disappear until the user actually runs a
		// regen OR explicitly dismisses it. The flag clears automatically
		// the moment bulk_start() fires (see NEXENG_SSG::bulk_start()).
		if ( class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled() ) {
			$_ssg_stats = NEXENG_SSG::get_instance()->stats();
			if ( (int) ( $_ssg_stats['total_files'] ?? 0 ) > 0 ) {
				update_option( 'nexeng_pro_regen_needed', 1, false );
			}
		}
	} );
}, 15 );

// ──────────────────────────────────────────────────────────────────────────────
// ADMIN_INIT — MANUAL SYNC + ENTITLEMENT RECONCILIATION
//
// Runs before any HTML output so wp_safe_redirect() can still fire.
//
// Two responsibilities:
//   A) Manual sync  — user clicked "Sync license state".
//      Triggers a real Freemius API round-trip (sync_install) to refresh the
//      cached license state from Freemius servers, then busts our transients
//      and redirects back cleanly (no headers-already-sent).
//
//   B) Reconciliation — on every admin page load, compare what we cached vs
//      what Freemius reports from its local WP-options store.  If they differ
//      the cache is stale and we bust it so the page render reads fresh state.
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'admin_init', static function() {
	if ( ! function_exists( 'ne_fs' ) ) {
		return;
	}
	$fs = ne_fs();
	if ( ! ( $fs instanceof \Freemius ) ) {
		return;
	}

	// ── A) Manual sync ────────────────────────────────────────────────────────
	// The "Sync license state" link in the License page adds ?nexeng_sync=1.
	// We handle it here (admin_init, before any output) so the redirect works.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['nexeng_sync'] ) ) {
		// phpcs:enable
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'nexeng_sync_license' );

		// Force Freemius to re-fetch the install/license from its own API.
		// After this call Freemius's $this->_license is current-from-server.
		$adapter = \NexoraEngine\Licensing\FreemiusAdapter::instance();
		$adapter->force_sync();

		// Bust our transient cache so the next get_plan() reads fresh state.
		\NexoraEngine\Licensing\FeatureGate::bust_all_caches();

		// Redirect back without the query params so the page renders cleanly.
		wp_safe_redirect( remove_query_arg( array( 'nexeng_sync', '_wpnonce' ) ) );
		exit;
	}

	// ── B) Reconciliation ─────────────────────────────────────────────────────
	// Only compare when we have a cached value — no cache means the next
	// get_plan() call will go straight to Freemius anyway.
	$cached = \NexoraEngine\Licensing\EntitlementCache::get_plan();
	if ( null === $cached ) {
		return;
	}

	// Read directly from Freemius's in-memory state (loaded from WP options
	// at boot).  No API call; O(1) cost.
	$live = \NexoraEngine\Licensing\FreemiusAdapter::instance()->get_plan();

	if ( $live !== $cached ) {
		// Cache is stale — bust so the current page render reads fresh state.
		\NexoraEngine\Licensing\FeatureGate::bust_all_caches();
	}
} );

// ──────────────────────────────────────────────────────────────────────────────
// SHELL BODY CACHE INVALIDATION
// The shell-template caches the loopback render per post in a transient
// (key: nexeng_shell_body_{blog}_{post_id}) for 24h. We flush whenever a post is
// edited or its status changes so editors see updates without waiting for TTL.
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'save_post',      'NexoraEngine\nexeng_flush_shell_body_cache' );
add_action( 'deleted_post',   'NexoraEngine\nexeng_flush_shell_body_cache' );
add_action( 'trashed_post',   'NexoraEngine\nexeng_flush_shell_body_cache' );
add_action( 'untrashed_post', 'NexoraEngine\nexeng_flush_shell_body_cache' );

/**
 * Flush the cached shell body and REST normalizer transient for a post.
 *
 * @param int $post_id Post ID.
 */
function nexeng_flush_shell_body_cache( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	$blog = get_current_blog_id();
	$pid  = (int) $post_id;
	delete_transient( 'nexeng_shell_body_' . $blog . '_' . $pid );
	delete_transient( 'nexeng_norm_'       . $blog . '_' . $pid );
}

// Editor-facing manual flush: append ?nexeng_flush=1 to any front-end URL while
// logged in as admin to force-rebuild the cached shell body for that post.
add_action( 'template_redirect', function() {
	if ( ! isset( $_GET['nexeng_flush'] ) || ! current_user_can( 'edit_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$pid = (int) get_the_ID();
	if ( $pid ) {
		$blog = get_current_blog_id();
		delete_transient( 'nexeng_shell_body_' . $blog . '_' . $pid );
		delete_transient( 'nexeng_norm_'       . $blog . '_' . $pid );
	}
}, 1 );

// ──────────────────────────────────────────────────────────────────────────────
// GLOBAL CACHE FLUSH
// Theme/global changes invalidate every cached page because Elementor Kit
// colours, menu changes, widget edits, etc. all alter the rendered HTML site-wide.
// ──────────────────────────────────────────────────────────────────────────────

$_nexeng_global_flush = function() {
	global $wpdb;
	// Bulk-clears our own shell-body / normalizer transients from the core
	// {$wpdb->options} table. No user input — the LIKE patterns are hardcoded.
	// Direct query + no-cache are inherent to a transient sweep.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_nexeng_shell_body_%'
		    OR option_name LIKE '_transient_timeout_nexeng_shell_body_%'
		    OR option_name LIKE '_transient_nexeng_norm_%'
		    OR option_name LIKE '_transient_timeout_nexeng_norm_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};

add_action( 'customize_save_after',           $_nexeng_global_flush );
add_action( 'wp_update_nav_menu',             $_nexeng_global_flush );
add_action( 'switch_theme',                   $_nexeng_global_flush );
add_action( 'update_option_blogname',         $_nexeng_global_flush );
add_action( 'update_option_blogdescription',  $_nexeng_global_flush );
add_action( 'update_option_sidebars_widgets', $_nexeng_global_flush );
// Elementor Kit edits alter every cached page (typography/colour changes).
add_action( 'elementor/core/files/clear_cache', $_nexeng_global_flush );

unset( $_nexeng_global_flush ); // Clean up global scope.

// ──────────────────────────────────────────────────────────────────────────────
// SSG PURGE ON PLUGIN/THEME UPDATE
// Elementor and other page builders embed content-hashed JS chunk filenames
// directly in the rendered HTML. When a plugin updates its assets, the hashes
// change and any SSG-cached page that still holds the old filenames will throw
// ChunkLoadErrors. We purge all SSG static files so they are rebuilt on the next
// visit with fresh, correct asset references.
// ──────────────────────────────────────────────────────────────────────────────

// Elementor explicitly signals asset regeneration via this action.
add_action( 'elementor/core/files/clear_cache', function() {
	if ( class_exists( 'NEXENG_SSG' ) && \NEXENG_SSG::is_enabled() ) {
		// Elementor can fire this while an editor session or asset refresh is
		// still in progress. Do not delete the live static mirror immediately;
		// mark the site for a controlled rebuild so existing static pages stay
		// available until the next capture replaces them.
		\NEXENG_SSG::get_instance()->schedule_global_invalidate();
	}
} );

// Plugin/theme updates: intentionally NOT auto-purging SSG here.
// On WPMU shared servers, upgrader_process_complete fires for every
// network-wide update and would repeatedly wipe the static cache.
// Users regenerate manually after updates, or rely on the Elementor
// files/clear_cache hook below for page-builder asset changes.

// ──────────────────────────────────────────────────────────────────────────────
// DROP-IN CACHE SYNC
// Installs advanced-cache.php when SSG is enabled; removes it on disable.
// Runs on option change so the drop-in always reflects the live toggle state.
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Sync the advanced-cache.php drop-in with the SSG enable/disable toggle.
 *
 * @param mixed $old_value Previous option value (unused).
 * @param mixed $new_value New option value.
 */
function nexeng_dropin_sync_with_ssg( $old_value, $new_value ) {
	if ( $new_value === 'on' ) {
		$r = \NEXENG_Dropin::install();
		if ( is_wp_error( $r ) ) {
			update_option( 'nexeng_dropin_last_error', $r->get_error_message(), false );
		} else {
			delete_option( 'nexeng_dropin_last_error' );
		}
	} elseif ( $new_value === 'off' ) {
		\NEXENG_Dropin::uninstall();
		delete_option( 'nexeng_dropin_last_error' );
	}

	// On multisite: bust the fleet stats cache so the network dashboard
	// immediately reflects the updated SSG state for this blog.
	if ( is_multisite() && class_exists( 'NEXENG_Multisite' ) ) {
		\NEXENG_Multisite::bust_fleet_cache();
	}

	// Bust the menu badge cache — drop-in state just changed.
	\NEXENG_Admin::bust_issue_count_cache();
}

add_action( 'update_option_nexeng_ssg_enabled', 'NexoraEngine\nexeng_dropin_sync_with_ssg', 10, 2 );
add_action( 'add_option_nexeng_ssg_enabled', function( $name, $value ) {
	nexeng_dropin_sync_with_ssg( null, $value );
}, 10, 2 );

add_action( 'init', function() {
	$dropin_rev = 'html-cache-policy-v1-20260523';
	if ( get_option( 'nexeng_ssg_enabled' ) !== 'on' || ! class_exists( '\NEXENG_Dropin' ) ) {
		return;
	}
	if ( get_option( 'nexeng_dropin_runtime_rev' ) === $dropin_rev && \NEXENG_Dropin::status() === 'ours' ) {
		return;
	}
	$result = \NEXENG_Dropin::install();
	if ( is_wp_error( $result ) ) {
		update_option( 'nexeng_dropin_last_error', $result->get_error_message(), false );
		return;
	}
	update_option( 'nexeng_dropin_runtime_rev', $dropin_rev, false );
	delete_option( 'nexeng_dropin_last_error' );
}, 1 );

// ──────────────────────────────────────────────────────────────────────────────
// MULTISITE LIFECYCLE HOOKS
// Auto-activates Nexora on new network sites (when network-activated) and
// cleans up SSG static files + rebuilds the network map when a site is deleted.
// Requires WP 5.1+ (wp_initialize_site / wp_uninitialize_site) which is
// guaranteed by our WP 5.9 minimum.
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'wp_initialize_site', static function( $new_site ) {
	if ( class_exists( 'NEXENG_Multisite' ) ) {
		\NEXENG_Multisite::on_new_blog( (int) $new_site->blog_id );
	}
} );

add_action( 'wp_uninitialize_site', static function( $old_site ) {
	if ( class_exists( 'NEXENG_Multisite' ) ) {
		\NEXENG_Multisite::on_delete_blog( (int) $old_site->blog_id );
	}
} );

// Remove drop-in on deactivation so we don't leave orphaned PHP running.
register_deactivation_hook( __FILE__, function() {
	if ( class_exists( 'NEXENG_Dropin' ) ) {
		\NEXENG_Dropin::uninstall();
	}
} );

// Re-install drop-in on activation if SSG was already on (re-activation / update).
register_activation_hook( __FILE__, function() {
	if ( get_option( 'nexeng_ssg_enabled' ) === 'on' && class_exists( 'NEXENG_Dropin' ) ) {
		\NEXENG_Dropin::install();
	}
	// Default options for fresh installs.
	if ( ! get_option( 'nexeng_asset_base' ) ) {
		update_option( 'nexeng_asset_base', network_site_url() );
	}
	if ( ! get_option( 'nexeng_proxy_mode' ) ) {
		update_option( 'nexeng_proxy_mode', 'compat' );
	}
	if ( ! get_option( 'nexeng_asset_mode' ) ) {
		update_option( 'nexeng_asset_mode', 'direct' );
	}
	// Schedule the proxy-mode detection probe shortly after activation.
	if ( ! wp_next_scheduled( 'nexeng_probe_proxy_mode' ) ) {
		wp_schedule_single_event( time() + 10, 'nexeng_probe_proxy_mode' );
	}
} );

// Cron handler for the deferred activation probe.
add_action( 'nexeng_probe_proxy_mode', function() {
	if ( class_exists( 'NEXENG_Init' ) ) {
		\NEXENG_Init::get_instance()->probe_proxy_mode();
	}
} );

// ──────────────────────────────────────────────────────────────────────────────
// ADMIN NOTICES — Drop-in status
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	// Surface install failures.
	$err = get_option( 'nexeng_dropin_last_error' );
	if ( $err ) {
		echo '<div class="notice notice-error"><p><strong>Nexora Engine (SSG drop-in):</strong> '
			. esc_html( $err ) . '</p></div>';
	}

	// WP_CACHE not active warning.
	if ( class_exists( 'NEXENG_SSG' ) && class_exists( 'NEXENG_Dropin' )
		&& get_option( 'nexeng_ssg_enabled' ) === 'on'
		&& \NEXENG_Dropin::status() === 'ours'
		&& ! \NEXENG_Dropin::wp_cache_active()
	) {
		echo '<div class="notice notice-warning"><p><strong>Nexora Engine (SSG):</strong> '
			. 'The drop-in cache file is installed, but <code>WP_CACHE</code> is not active. '
			. 'Add <code>define( \'WP_CACHE\', true );</code> to <code>wp-config.php</code> '
			. '(above the "stop editing" line) to activate static delivery.</p></div>';
	}
} );

// ──────────────────────────────────────────────────────────────────────────────
// LIVE-SERVER DIAGNOSTIC  (?nexeng_diag=1 on any front-end URL while logged in)
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'init', 'NexoraEngine\nexeng_run_diagnostics' );

/**
 * Render the SSG diagnostic page when ?nexeng_diag=1 is present.
 * Admin-only; exits after output.
 */
function nexeng_run_diagnostics() {
	if ( empty( $_GET['nexeng_diag'] ) || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( ! class_exists( 'NEXENG_SSG' ) || ! class_exists( 'NEXENG_Dropin' ) ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );

	$ssg           = \NEXENG_SSG::get_instance();
	$home_url      = home_url( '/' );
	$home_path     = wp_parse_url( $home_url, PHP_URL_PATH ) ?: '/';
	$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '(unknown)';
	$abspath       = ABSPATH;
	$upload        = wp_get_upload_dir();
	$static_root   = trailingslashit( $upload['basedir'] ) . 'nexora-static';
	$static_index  = $static_root . '/index.html';
	$htaccess_path = trailingslashit( $abspath ) . '.htaccess';
	$server_sw     = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '(unknown)';

	$ssg_enabled     = \NEXENG_SSG::is_enabled();
	$headless_on     = get_option( 'nexeng_headless_mode', 'off' ) === 'on';
	$rule_installed  = $ssg->serve_rule_installed();
	$dropin_status   = \NEXENG_Dropin::status();
	$wp_cache_on     = \NEXENG_Dropin::wp_cache_active();
	$dropin_conflict = \NEXENG_Dropin::detect_conflict();
	$is_nginx        = stripos( $server_sw, 'nginx' ) !== false;
	$is_apache       = stripos( $server_sw, 'apache' ) !== false;
	$is_litespeed    = stripos( $server_sw, 'litespeed' ) !== false;

	$rule_position    = 'unknown';
	$htaccess_excerpt = '';
	if ( file_exists( $htaccess_path ) && is_readable( $htaccess_path ) ) {
		$contents         = file_get_contents( $htaccess_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$htaccess_excerpt = $contents;
		$nexeng_pos = strpos( $contents, '# BEGIN Nexora SSG' );
		$wp_pos  = strpos( $contents, '# BEGIN WordPress' );
		if ( false === $nexeng_pos ) {
			$rule_position = 'NOT PRESENT';
		} elseif ( false === $wp_pos ) {
			$rule_position = 'present (no WP block)';
		} elseif ( $nexeng_pos < $wp_pos ) {
			$rule_position = 'BEFORE WordPress block ✅';
		} else {
			$rule_position = 'AFTER WordPress block ❌ (Apache hits WP rule first)';
		}
	}

	$t0              = microtime( true );
	$probe           = wp_remote_get( $home_url, array( 'timeout' => 15, 'sslverify' => false, 'redirection' => 2 ) );
	$probe_ms        = (int) ( ( microtime( true ) - $t0 ) * 1000 );
	$probe_headers   = array();
	$probe_body_head = '';
	$probe_status    = 0;

	if ( ! is_wp_error( $probe ) ) {
		$probe_status   = wp_remote_retrieve_response_code( $probe );
		$probe_headers  = wp_remote_retrieve_headers( $probe );
		if ( method_exists( $probe_headers, 'getAll' ) ) {
			$probe_headers = $probe_headers->getAll();
		}
		$probe_body_head = substr( wp_remote_retrieve_body( $probe ), 0, 600 );
	} else {
		$probe_status = 'ERROR: ' . $probe->get_error_message();
	}

	$hdr_xpb = $hdr_nexeng_cache = '';
	foreach ( (array) $probe_headers as $k => $v ) {
		$lk = strtolower( $k );
		if ( 'x-powered-by' === $lk ) {
			$hdr_xpb = is_array( $v ) ? implode( ',', $v ) : (string) $v;
		}
		if ( 'x-nexora-cache' === $lk ) {
			$hdr_nexeng_cache = is_array( $v ) ? implode( ',', $v ) : (string) $v;
		}
	}

	$served_by_dropin = '' !== $hdr_nexeng_cache && false !== stripos( $hdr_nexeng_cache, 'HIT' );
	$body_has_loader  = stripos( $probe_body_head, 'ncx-loader' ) !== false
	                 || stripos( $probe_body_head, '__NEXORA_PROPS__' ) !== false;
	$served_by_php    = ! $served_by_dropin && ( $body_has_loader
	                    || false !== stripos( $hdr_xpb, 'php' ) );

	if ( $served_by_dropin ) {
		$verdict = '<span style="color:#0a0;font-weight:bold">✅ FAST PATH (drop-in) — advanced-cache.php served the file before WP booted.</span>';
	} elseif ( ! $served_by_php ) {
		$verdict = '<span style="color:#0a0;font-weight:bold">✅ FAST PATH (web server) — Rewrite rule served the static file. PHP did not run.</span>';
	} else {
		$verdict = '<span style="color:#c00;font-weight:bold">❌ SLOW PATH — PHP rendered this request. Neither the drop-in nor a server rewrite is active.</span>';
	}

	$home_file_exists = file_exists( $static_index );
	$stats            = $ssg->stats();

	$rows_with_warnings = array();
	if ( method_exists( $ssg, 'list_status' ) ) {
		foreach ( $ssg->list_status( 500 ) as $row ) {
			if ( ! empty( $row['warnings'] ) ) {
				$rows_with_warnings[] = $row;
			}
		}
	}
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone developer SSG-diagnostic page (capability-gated). All dynamic values below are individually esc_html()/esc_url()/(int)-cast; the ternaries emit only hardcoded HTML status badges (<span class="good">…</span>) which are intentional trusted markup and cannot be esc_html'd.
	// The <style> below is deliberately raw. This is a standalone document —
	// it emits its own doctype and <head> instead of rendering inside wp-admin —
	// so there is no wp_head or admin_enqueue_scripts queue for an enqueued
	// stylesheet to be printed into. Capability-gated, developer-facing only.
	?><!doctype html><html><head><meta charset="utf-8"><title>Nexora Engine — SSG Diagnostic</title>
	<style>
	.wrap{font:14px/1.5 -apple-system,Segoe UI,sans-serif;max-width:960px;margin:30px auto;padding:0 20px}
	h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:24px 0 8px;border-bottom:1px solid #eee;padding-bottom:4px}
	table{border-collapse:collapse;width:100%}td{padding:6px 10px;border-bottom:1px solid #f0f0f0;vertical-align:top}
	td:first-child{width:35%;color:#666;font-weight:500}td:last-child{font-family:monospace;font-size:13px;word-break:break-all}
	.good{color:#0a0}.bad{color:#c00}.warn{color:#c80}
	pre{background:#f6f6f6;padding:12px;border-radius:4px;overflow:auto;max-height:280px;font-size:12px}
	.verdict{padding:14px;border-radius:6px;background:#f9f9f9;margin:14px 0;font-size:15px}
	</style></head><body><div class="wrap">
	<h1>Nexora Engine — SSG Diagnostic</h1>
	<div class="verdict"><?php echo $verdict; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<h2>Plugin State</h2><table>
	<tr><td>Web server</td><td><?php echo esc_html( $server_sw ); ?></td></tr>
	<tr><td>Headless Mode</td><td><?php echo $headless_on ? '<span class="good">ON</span>' : '<span class="bad">OFF</span>'; ?></td></tr>
	<tr><td>SSG enabled</td><td><?php echo $ssg_enabled ? '<span class="good">ON</span>' : '<span class="bad">OFF</span>'; ?></td></tr>
	<tr><td>advanced-cache.php drop-in</td><td><?php
		if ( 'ours' === $dropin_status ) echo '<span class="good">INSTALLED (Nexora Engine)</span>';
		elseif ( 'foreign' === $dropin_status ) echo '<span class="bad">CONFLICT — owned by ' . esc_html( $dropin_conflict ?: 'another plugin' ) . '</span>';
		else echo '<span class="bad">NOT INSTALLED</span>';
	?></td></tr>
	<tr><td>WP_CACHE constant</td><td><?php echo $wp_cache_on ? '<span class="good">true ✅</span>' : '<span class="bad">false — drop-in will NOT load</span>'; ?></td></tr>
	<tr><td>.htaccess rule position</td><td><?php echo $is_nginx ? '<span class="warn">N/A (Nginx)</span>' : esc_html( $rule_position ); ?></td></tr>
	</table>
	<h2>Filesystem</h2><table>
	<tr><td>Static root</td><td><?php echo esc_html( $static_root ); ?></td></tr>
	<tr><td>Root exists / writable</td><td><?php echo ( $stats['root_exists'] ? '<span class="good">YES</span>' : '<span class="bad">NO</span>' ) . ' / ' . ( $stats['root_writable'] ? '<span class="good">YES</span>' : '<span class="bad">NO</span>' ); ?></td></tr>
	<tr><td>Total static files</td><td><?php echo (int) $stats['total_files']; ?></td></tr>
	<tr><td>Homepage static file</td><td><?php echo $home_file_exists ? '<span class="good">EXISTS</span>' : '<span class="bad">MISSING — regenerate all pages</span>'; ?></td></tr>
	</table>
	<h2>Live HTTP Probe — <?php echo esc_html( $home_url ); ?></h2><table>
	<tr><td>Status</td><td><?php echo esc_html( (string) $probe_status ); ?></td></tr>
	<tr><td>Response time</td><td><?php echo (int) $probe_ms; ?> ms <?php echo $probe_ms < 500 ? '<span class="good">(fast)</span>' : '<span class="bad">(slow — PHP likely ran)</span>'; ?></td></tr>
	<tr><td>X-Nexora-Cache</td><td><?php echo '' !== $hdr_nexeng_cache ? '<span class="good">' . esc_html( $hdr_nexeng_cache ) . ' ✅ (drop-in working)</span>' : '<span class="warn">absent</span>'; ?></td></tr>
	<tr><td>X-Powered-By</td><td><?php echo $hdr_xpb ? esc_html( $hdr_xpb ) : '<span class="warn">absent</span>'; ?></td></tr>
	</table>
	<?php if ( ! empty( $rows_with_warnings ) ): ?>
	<h2>Broken Asset References (<?php echo count( $rows_with_warnings ); ?> page(s))</h2>
	<table><?php foreach ( $rows_with_warnings as $row ): ?>
	<tr><td><a href="<?php echo esc_url( $row['permalink'] ); ?>" target="_blank"><?php echo esc_html( $row['title'] ); ?></a></td>
	<td><?php foreach ( (array) $row['warnings'] as $w ) echo '• ' . esc_html( $w ) . '<br>'; ?></td></tr>
	<?php endforeach; ?></table>
	<?php endif; ?>
	</div></body></html>
	<?php
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

// ──────────────────────────────────────────────────────────────────────────────
// HELPER FUNCTIONS (global namespace aliases for template/external use)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Check if a Nexora Engine feature is available for the active license plan.
 *
 * @param string $feature Feature key (see Entitlements::$map).
 * @return bool
 */
function can_feature( $feature ) {
	return Core\Features::can( $feature );
}

/**
 * Get the active plan slug: 'free' | 'pro'
 *
 * @return string
 */
function get_license_tier() {
	return Licensing\LicenseManager::instance()->get_tier();
}

/**
 * Check if the current plan is Pro.
 *
 * @return bool
 */
function is_pro() {
	return Core\Features::is_tier_or_above( 'pro' );
}

/**
 * Legacy alias — Agency tier was merged into Pro.
 *
 * @return bool
 */
function is_agency() {
	return is_pro();
}

/**
 * Legacy alias — Enterprise tier was merged into Pro.
 *
 * @return bool
 */
function is_enterprise() {
	return is_pro();
}

/**
 * Returns the Freemius checkout upgrade URL.
 * Falls back to the pricing page when the Freemius SDK is not yet installed.
 *
 * @param string $plan Target plan slug: 'pro' (default).
 * @return string
 */
function get_upgrade_url( $plan = 'pro' ) {
	return Core\Features::get_upgrade_url( $plan );
}

// ──────────────────────────────────────────────────────────────────────────────
// GLOBAL CLASS ALIASES
// These non-namespaced aliases allow legacy includes/class-ncx-*.php files and
// third-party add-ons to call the licensing and feature-gate APIs without
// importing the full NexoraEngine\ namespace.
//
// Usage in legacy files:
//   Nexora_Features::enabled( 'seo_intelligence' )
//   Nexora_License_Manager::can( 'advanced_ghost_protocol' )
// ──────────────────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', static function() {

	// Nexora_Features — global alias for NexoraEngine\Core\Features
	if ( ! class_exists( 'Nexora_Features', false ) ) {
		class_alias( 'NexoraEngine\\Core\\Features', 'Nexora_Features' );
	}

	// Nexora_License_Manager — global alias for NexoraEngine\Licensing\LicenseManager
	if ( ! class_exists( 'Nexora_License_Manager', false ) ) {
		class_alias( 'NexoraEngine\\Licensing\\LicenseManager', 'Nexora_License_Manager' );
	}

}, 5 ); // Priority 5: before legacy includes initialize (priority 0).

// ──────────────────────────────────────────────────────────────────────────────
// PLUGIN LOADED HOOK
// ──────────────────────────────────────────────────────────────────────────────

do_action( 'nexora_engine_loaded' );
