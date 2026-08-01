<?php
/**
 * Nexora Engine — Activator
 *
 * Runs on plugin activation. Creates / upgrades all DB tables via dbDelta(),
 * inserts default settings, registers cron schedules (PRO), and flushes rewrites.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This activator only issues CREATE TABLE statements through dbDelta() for the
// plugin's OWN tables. Table names are built from $wpdb->prefix (the $p variable),
// never user input, and dbDelta is the WordPress-mandated table-creation API, which
// cannot use %s placeholders for identifiers. Disabling the prepared-SQL sniffs
// file-wide on that basis:
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

class NEXENG_Activator {

	/**
	 * Entry point — called by register_activation_hook.
	 * Handles both single-site and network-wide activation.
	 */
	public static function run(): void {
		if ( is_multisite() && isset( $_GET['networkwide'] ) && '1' === $_GET['networkwide'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Network activation: activate for every existing blog.
			$blog_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $blog_ids as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::activate_single_site();
				restore_current_blog();
			}
		} else {
			self::activate_single_site();
		}
	}

	/**
	 * Activates the plugin for the current site.
	 */
	public static function activate_single_site(): void {
		self::create_tables();
		self::insert_defaults();
		self::register_cron_schedules();

		update_option( 'nexeng_version', NEXENG_VERSION );
		update_option( 'nexeng_db_version', self::DB_VERSION );

		flush_rewrite_rules();

		NEXENG_Cache::flush_all();
	}

	/**
	 * Schema version — bump this (not NEXENG_VERSION) whenever tables are added or altered.
	 * Stored in nexeng_db_version option; mismatch triggers a dbDelta run on admin_init.
	 */
	const DB_VERSION = '2.0.2';

	/**
	 * Ensures tables exist for the current blog.
	 * Hooked to admin_init so any missed activation or schema change self-heals.
	 */
	public static function maybe_create_tables(): void {
		if ( get_option( 'nexeng_db_version' ) === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		update_option( 'nexeng_db_version', self::DB_VERSION );
	}

	// ─── Table Creation ───────────────────────────────────────────────────────

	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p        = $wpdb->prefix;

		$sql = "
		CREATE TABLE {$p}nexeng_scan_results (
		  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id      INT NOT NULL DEFAULT 1,
		  post_id      BIGINT UNSIGNED NOT NULL,
		  scan_type    VARCHAR(50) NOT NULL,
		  result_data  LONGTEXT,
		  score        TINYINT UNSIGNED,
		  scanned_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
		  PRIMARY KEY (id),
		  KEY idx_blog_post (blog_id, post_id),
		  KEY idx_scan_type (scan_type)
		) {$charset};

		CREATE TABLE {$p}nexeng_issues (
		  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id      INT NOT NULL DEFAULT 1,
		  post_id      BIGINT UNSIGNED DEFAULT NULL,
		  issue_key    VARCHAR(100) NOT NULL,
		  title        VARCHAR(255) NOT NULL,
		  severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
		  explanation  TEXT NOT NULL,
		  fix          TEXT NOT NULL,
		  status       ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
		  detected_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
		  resolved_at  DATETIME DEFAULT NULL,
		  PRIMARY KEY (id),
		  UNIQUE KEY unique_issue (blog_id, post_id, issue_key),
		  KEY idx_status (status),
		  KEY idx_severity (severity)
		) {$charset};

		CREATE TABLE {$p}nexeng_page_scores (
		  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id           INT NOT NULL DEFAULT 1,
		  post_id           BIGINT UNSIGNED NOT NULL,
		  overall_score     TINYINT UNSIGNED NOT NULL DEFAULT 0,
		  seo_score         TINYINT UNSIGNED NOT NULL DEFAULT 0,
		  performance_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
		  security_score    TINYINT UNSIGNED NOT NULL DEFAULT 0,
		  indexing_score    TINYINT UNSIGNED NOT NULL DEFAULT 0,
		  headless_ready    TINYINT(1) NOT NULL DEFAULT 0,
		  hybrid_required   TINYINT(1) NOT NULL DEFAULT 0,
		  updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		  PRIMARY KEY (id),
		  UNIQUE KEY unique_page (blog_id, post_id)
		) {$charset};

		CREATE TABLE {$p}nexeng_settings (
		  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id       INT NOT NULL DEFAULT 1,
		  setting_key   VARCHAR(100) NOT NULL,
		  setting_value LONGTEXT,
		  PRIMARY KEY (id),
		  UNIQUE KEY unique_setting (blog_id, setting_key)
		) {$charset};

		CREATE TABLE {$p}nexeng_redirects (
		  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id       INT NOT NULL DEFAULT 1,
		  source_url    VARCHAR(500) NOT NULL,
		  target_url    VARCHAR(500) NOT NULL,
		  redirect_type SMALLINT NOT NULL DEFAULT 301,
		  is_active     TINYINT(1) NOT NULL DEFAULT 1,
		  notes         VARCHAR(255) DEFAULT NULL,
		  hit_count     INT NOT NULL DEFAULT 0,
		  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
		  PRIMARY KEY (id),
		  KEY idx_blog_source (blog_id, source_url(191))
		) {$charset};

		CREATE TABLE {$p}nexeng_scan_history (
		  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id       INT NOT NULL DEFAULT 1,
		  post_id       BIGINT UNSIGNED NOT NULL,
		  snapshot_data LONGTEXT,
		  site_score    TINYINT UNSIGNED,
		  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
		  PRIMARY KEY (id),
		  KEY idx_blog_post (blog_id, post_id),
		  KEY idx_created (created_at)
		) {$charset};

		CREATE TABLE {$p}nexeng_hits (
		  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id       INT NOT NULL DEFAULT 1,
		  post_id       BIGINT UNSIGNED DEFAULT NULL,
		  url           VARCHAR(1000) NOT NULL,
		  hit_type      ENUM('hit','miss') NOT NULL DEFAULT 'hit',
		  response_time INT DEFAULT NULL,
		  ip_hash       VARCHAR(64) NOT NULL,
		  ua_class      ENUM('desktop','mobile','tablet','bot') NOT NULL DEFAULT 'desktop',
		  ref_class     ENUM('direct','search','social','internal','other') NOT NULL DEFAULT 'direct',
		  country       CHAR(2) DEFAULT NULL,
		  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
		  PRIMARY KEY (id),
		  KEY idx_blog_url (blog_id, url(191)),
		  KEY idx_created (created_at),
		  KEY idx_post_id (post_id)
		) {$charset};

		CREATE TABLE {$p}nexeng_hits_daily (
		  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id       INT NOT NULL DEFAULT 1,
		  post_id       BIGINT UNSIGNED DEFAULT NULL,
		  day           DATE NOT NULL,
		  hits          INT UNSIGNED NOT NULL DEFAULT 0,
		  misses        INT UNSIGNED NOT NULL DEFAULT 0,
		  avg_ttfb      INT UNSIGNED DEFAULT NULL,
		  PRIMARY KEY (id),
		  UNIQUE KEY unique_daily (blog_id, post_id, day),
		  KEY idx_day (day)
		) {$charset};

		CREATE TABLE {$p}nexeng_vitals (
		  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		  blog_id       INT NOT NULL DEFAULT 1,
		  post_id       BIGINT UNSIGNED NOT NULL,
		  metric_name   ENUM('LCP','INP','CLS') NOT NULL,
		  metric_value  FLOAT NOT NULL,
		  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
		  PRIMARY KEY (id),
		  KEY idx_blog_post (blog_id, post_id),
		  KEY idx_created (created_at)
		) {$charset};
		";

		dbDelta( $sql );
	}

	// ─── Default Settings ─────────────────────────────────────────────────────

	private static function insert_defaults(): void {
		$db      = NEXENG_Database::get_instance();
		$blog_id = get_current_blog_id();

		$defaults = [
			'nexeng_api_key'                  => self::generate_api_key(),
			'nexeng_alerts_enabled'           => '0',
			'nexeng_alert_email'              => get_option( 'admin_email' ),
			'nexeng_alert_severity_threshold' => 'critical',
			'nexeng_alert_frequency'          => 'daily',
			'nexeng_webhook_url'              => '',
			'nexeng_webhook_secret'           => wp_generate_password( 32, false ),
			'nexeng_wl_enabled'               => '0',
			'nexeng_wl_plugin_name'           => 'Nexora Engine',
			'nexeng_wl_logo_url'              => '',
			'nexeng_wl_support_url'           => '',
			'nexeng_wl_hide_nexora_branding'  => '0',
			'nexeng_ga_client_id'             => '',
			'nexeng_ga_client_secret'         => '',
			'nexeng_ga_property_id'           => '',
			'nexeng_gsc_site_url'             => '',
			'nexeng_licence_key'              => '',
		];

		foreach ( $defaults as $key => $value ) {
			// Only insert if no existing value; don't overwrite on re-activation.
			if ( null === $db->get_setting( $blog_id, $key ) ) {
				$db->update_setting( $blog_id, $key, $value );
			}
		}
	}

	// ─── Cron Schedules (PRO) ─────────────────────────────────────────────────

	private static function register_cron_schedules(): void {
		if ( ! NEXENG_Licence::is_pro() ) {
			return;
		}

		$jobs = [
			'nexeng_scan_all_pages' => 'weekly',
			'nexeng_check_links'    => 'weekly',
			'nexeng_refresh_ga'     => 'hourly',
			'nexeng_refresh_gsc'    => 'hourly',
			'nexeng_send_digest'    => 'daily',
		];

		foreach ( $jobs as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	private static function generate_api_key(): string {
		return 'nexeng_' . bin2hex( random_bytes( 24 ) );
	}
}
