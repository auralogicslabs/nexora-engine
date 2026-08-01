<?php
/**
 * Nexora Engine — Deactivator
 *
 * Runs on plugin deactivation. Clears all scheduled cron jobs and flushes
 * rewrite rules. Does NOT delete any data — the user may reactivate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Deactivator {

	/**
	 * Entry point — called by register_deactivation_hook.
	 */
	public static function run(): void {
		self::clear_cron_jobs();

		flush_rewrite_rules();

		NEXENG_Cache::flush_all();
	}

	/**
	 * Removes all WP-Cron events registered by Nexora Engine.
	 *
	 * Includes both the Pro maintenance jobs (scheduled in NEXENG_Activator) and
	 * the SSG build pipeline events (scheduled dynamically as posts are
	 * published). Anything left scheduled after deactivation would fire on
	 * the next request and hit a missing class — keep this list current.
	 */
	private static function clear_cron_jobs(): void {
		$hooks = [
			// Pro maintenance jobs
			'nexeng_scan_all_pages',
			'nexeng_check_links',
			'nexeng_refresh_ga',
			'nexeng_refresh_gsc',
			'nexeng_send_digest',
			'nexeng_webhook_retry',
			// SSG build pipeline
			'nexeng_ssg_regen',
			'nexeng_ssg_bulk_tick',
			'nexeng_ssg_global_invalidate',
			'nexeng_ssg_delete',
			// Legacy
			'nexeng_hourly_aggregate',
			'nexeng_probe_proxy_mode',
			'nexeng_scheduled_scan',
			'nexora_engine_scheduled_regen',
		];

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
			// Also clear any remaining scheduled instances.
			wp_clear_scheduled_hook( $hook );
		}
	}
}
