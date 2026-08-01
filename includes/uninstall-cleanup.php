<?php
/**
 * Nexora Engine — Uninstall Cleanup
 *
 * The cleanup that used to live in the top-level uninstall.php. Freemius manages
 * uninstall itself and requires plugins to run their teardown through the SDK's
 * `after_uninstall` action instead of a standalone uninstall.php, so this logic
 * is registered via ne_fs()->add_action( 'after_uninstall', ... ) in the main
 * plugin file.
 *
 * Runs only when the user deletes the plugin (not on deactivation).
 *
 * Cleans up:
 *  - The advanced-cache.php drop-in (only if it's ours) + the WP_CACHE define we added
 *  - All nexeng_* / nexora_engine_* options
 *  - Transients (shell body cache, REST normalizer cache) + legacy ncx_ rows
 *  - Scheduled cron events
 *  - User meta
 *  - Plugin database tables
 *
 * NOTE: Static files in /wp-content/uploads/nexora-static/ are intentionally
 * preserved — they are content-related and the user may want to keep them.
 * A separate "Delete Static Files" button in the Tools view handles that.
 *
 * @package NexoraEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nexeng_run_uninstall_cleanup' ) ) {
	/**
	 * Full teardown of everything this plugin created.
	 *
	 * @return void
	 */
	function nexeng_run_uninstall_cleanup() {
		// ──────────────────────────────────────────────────────────────────────
		// 1. Remove the advanced-cache.php drop-in (only if it's ours)
		// ──────────────────────────────────────────────────────────────────────
		$dropin_path = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( file_exists( $dropin_path ) ) {
			$content = @file_get_contents( $dropin_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $content && strpos( $content, 'NEXORA_ADVANCED_CACHE' ) !== false ) {
				@unlink( $dropin_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		// Disable the WP_CACHE constant we added in wp-config.php so the next
		// install doesn't inherit our cache flag. Best-effort — file may be read-only.
		$wp_config = ABSPATH . 'wp-config.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- One-shot uninstall cleanup of the WP_CACHE define this plugin added to wp-config.php; the file legitimately lives at ABSPATH and a read-only check avoids a fatal.
		if ( is_writable( $wp_config ) ) {
			$cfg = @file_get_contents( $wp_config ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $cfg && false !== strpos( $cfg, '/* Added by Nexora Engine */' ) ) {
				$cfg = preg_replace(
					'#\s*/\* Added by Nexora Engine \*/\s*define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\);\s*#',
					"\n",
					$cfg
				);
				@file_put_contents( $wp_config, $cfg ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		// ──────────────────────────────────────────────────────────────────────
		// 2. Clear all plugin options
		// ──────────────────────────────────────────────────────────────────────
		$options_to_delete = array(
			// Core settings
			'nexeng_headless_mode',
			'nexeng_ssg_enabled',
			'nexeng_debug_mode',
			'nexeng_proxy_mode',
			'nexeng_asset_mode',
			'nexeng_asset_base',

			// API & Keys
			'nexeng_api_key',
			'nexeng_license_key',
			'nexeng_licence_key',

			// Analytics
			'nexeng_analytics_enabled',
			'nexeng_anonymize_ips',

			// SEO
			'nexeng_sitemap_enabled',
			'nexeng_schema_enabled',

			// White label
			'nexeng_white_label_name',
			'nexeng_support_email',

			// Security
			'nexeng_secure_users_api',
			'nexeng_secure_author_enum',
			'nexeng_secure_xmlrpc',
			'nexeng_secure_rest_tighten',
			'nexeng_secure_files',
			'nexeng_secure_rate_limit',
			'nexeng_secure_strong_pass',

			// SSG internals
			'nexeng_ssg_excluded_types',
			'nexeng_ssg_script_hosts',
			'nexeng_ssg_last_bulk_at',
			'nexeng_ssg_last_purge_at',
			'nexeng_ssg_errors',
			'nexeng_ssg_bulk_queue',
			'nexeng_ssg_bulk_cursor',
			'nexeng_ssg_manifest',
			'nexeng_ssg_pending',
			'nexeng_ssg_archives_dirty',
			'nexeng_ssg_fatal_pages',
			'nexeng_pro_regen_needed',
			'nexeng_auto_rebuild',

			// Drop-in
			'nexeng_dropin_last_error',
			'nexeng_wp_config_writable',

			// Wizard
			'nexeng_wizard_completed',
			'nexeng_setup_wizard_completed',

			// Portal
			'nexeng_portal_key',
			'nexeng_portal_site_id',

			// Dashboard
			'nexeng_ttfb_data',
			'nexeng_vitals_data',

			// New engine options
			'nexora_engine_activated_at',
			'nexora_engine_settings',
			'nexora_engine_license_cache',

			// Versioning & install identity (post-2.2 additions)
			'nexeng_version',
			'nexeng_db_version',
			'nexeng_install_id',
			'nexeng_engine_auto_paused_at',
			'nexeng_admin_bar_badge',
			'nexeng_ssg_bulk_paused',

			// Whitelabel + Alerts + Webhook + GA/GSC keys
			'nexeng_alerts_enabled',
			'nexeng_alert_email',
			'nexeng_alert_severity_threshold',
			'nexeng_alert_frequency',
			'nexeng_webhook_url',
			'nexeng_webhook_secret',
			'nexeng_wl_enabled',
			'nexeng_wl_plugin_name',
			'nexeng_wl_logo_url',
			'nexeng_wl_support_url',
			'nexeng_wl_hide_nexora_branding',
			'nexeng_ga_client_id',
			'nexeng_ga_client_secret',
			'nexeng_ga_property_id',
			'nexeng_gsc_site_url',

			// CDN integration
			'nexeng_cdn_auto_purge',
			'nexeng_cdn_cf_zone_id',
			'nexeng_cdn_cf_api_token',
			'nexeng_cdn_bunny_zone_id',
			'nexeng_cdn_bunny_api_key',

			// Staging HTTP auth
			'nexeng_http_auth_user',
			'nexeng_http_auth_pass',

			// Security extras
			'nexeng_secure_login_rename',
			'nexeng_secure_login_errors',
			'nexeng_secure_login_slug',
			'nexeng_secure_remove_version',
			'nexeng_secure_disable_file_edit',
			'nexeng_secure_headers',
		);

		foreach ( $options_to_delete as $option ) {
			delete_option( $option );
		}

		// ──────────────────────────────────────────────────────────────────────
		// 3. Remove all transients (shell body cache + REST normalizer cache)
		// ──────────────────────────────────────────────────────────────────────
		global $wpdb;

		// Removes our own transients from the core {$wpdb->options} table on
		// uninstall. No user input — hardcoded LIKE patterns. Direct query / no
		// cache are inherent.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_nexeng_%'
			    OR option_name LIKE '_transient_timeout_nexeng_%'
			    OR option_name LIKE '_transient_nexora_%'
			    OR option_name LIKE '_transient_timeout_nexora_%'"
		);

		// Defensive sweep of any legacy "ncx_" prefixed rows left over from
		// installs that predate the ncx_ → nexeng_ prefix migration.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE 'ncx\\_%'
			    OR option_name LIKE '_transient_ncx\\_%'
			    OR option_name LIKE '_transient_timeout_ncx\\_%'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// ──────────────────────────────────────────────────────────────────────
		// 4. Clear scheduled cron events
		// ──────────────────────────────────────────────────────────────────────
		$cron_hooks = array(
			'nexeng_hourly_aggregate',
			'nexeng_probe_proxy_mode',
			'nexeng_scheduled_scan',
			'nexora_engine_scheduled_regen',
			// SSG events
			'nexeng_ssg_regen',
			'nexeng_ssg_bulk_tick',
			'nexeng_ssg_global_invalidate',
			'nexeng_ssg_delete',
			// Pro maintenance jobs registered by NEXENG_Activator
			'nexeng_scan_all_pages',
			'nexeng_check_links',
			'nexeng_refresh_ga',
			'nexeng_refresh_gsc',
			'nexeng_send_digest',
		);

		foreach ( $cron_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
			wp_clear_scheduled_hook( $hook );
		}

		// ──────────────────────────────────────────────────────────────────────
		// 5. Remove user meta
		// ──────────────────────────────────────────────────────────────────────
		delete_metadata( 'user', 0, 'nexeng_wizard_dismissed', '', true );
		delete_metadata( 'user', 0, 'nexora_engine_wizard_completed', '', true );

		// ──────────────────────────────────────────────────────────────────────
		// 6. Drop plugin database tables — every table NEXENG_Activator creates
		// ──────────────────────────────────────────────────────────────────────
		$tables_to_drop = array(
			'nexeng_analytics',     // legacy
			'nexeng_scan_results',
			'nexeng_issues',
			'nexeng_page_scores',
			'nexeng_settings',
			'nexeng_redirects',
			'nexeng_scan_history',
			'nexeng_hits',
			'nexeng_hits_daily',
			'nexeng_vitals',
		);
		foreach ( $tables_to_drop as $tbl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$tbl}" );
		}

		// Flush rewrite rules so our custom endpoints are removed.
		flush_rewrite_rules();
	}
}
