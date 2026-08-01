<?php
/**
 * Nexora Engine — Cache
 *
 * Two-layer cache wrapper: tries the WordPress object cache (Redis/Memcached)
 * first, then falls back to transients. All other classes use this — never
 * call set_transient() or wp_cache_set() directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Cache {

	private static ?NEXENG_Cache $instance = null;

	/** Object-cache group used for all Nexora entries. */
	private const CACHE_GROUP = 'nexora_core';

	private function __construct() {}

	public static function get_instance(): NEXENG_Cache {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─── Core Operations ─────────────────────────────────────────────────────

	/**
	 * Retrieves a cached value. Returns false if not found.
	 */
	public function get( string $key ): mixed {
		$safe_key = $this->sanitize_key( $key );

		// Try object cache first (Redis/Memcached if available).
		$found  = false;
		$cached = wp_cache_get( $safe_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		// Fall back to transient.
		$transient = get_transient( $safe_key );

		return $transient;
	}

	/**
	 * Stores a value in both object cache and transients.
	 *
	 * @param int $ttl Time-to-live in seconds.
	 */
	public function set( string $key, mixed $value, int $ttl = 3600 ): void {
		$safe_key = $this->sanitize_key( $key );

		wp_cache_set( $safe_key, $value, self::CACHE_GROUP, $ttl );
		set_transient( $safe_key, $value, $ttl );
	}

	/**
	 * Deletes an entry from both object cache and transients.
	 */
	public function delete( string $key ): void {
		$safe_key = $this->sanitize_key( $key );

		wp_cache_delete( $safe_key, self::CACHE_GROUP );
		delete_transient( $safe_key );
	}

	/**
	 * Deletes all transients whose keys start with a given prefix.
	 *
	 * Object cache entries are invalidated via wp_cache_flush_group() when
	 * the object cache supports it (WP 6.1+), otherwise the group is nuked
	 * by deleting each known transient key.
	 */
	public function flush_group( string $prefix ): void {
		global $wpdb;

		// Flush object cache group if supported.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}

		$safe_prefix = $this->sanitize_key( $prefix );

		// Delete matching transients from the database. Operates on the core
		// {$wpdb->options} table (standard interpolation); all user-derived values
		// use $wpdb->prepare placeholders. Direct query + no-cache are inherent to a
		// bulk transient sweep.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_' . $safe_prefix . '%',
				'_transient_timeout_' . $safe_prefix . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ─── Convenience Key Builders ─────────────────────────────────────────────

	/**
	 * Builds a standardised cache key: nexeng_{type}_{blog_id}_{post_id}
	 */
	public static function make_key( string $type, int $blog_id, int $post_id = 0 ): string {
		return "nexeng_{$type}_{$blog_id}_{$post_id}";
	}

	/**
	 * Builds a site-level cache key: nexeng_{type}_{blog_id}
	 */
	public static function make_site_key( string $type, int $blog_id ): string {
		return "nexeng_{$type}_{$blog_id}";
	}

	// ─── Invalidation Hooks ───────────────────────────────────────────────────

	/**
	 * Invalidates all cache entries for a given post.
	 * Hooked to save_post in NEXENG_Init.
	 */
	public function on_save_post( int $post_id ): void {
		$blog_id   = get_current_blog_id();
		$scan_types = [ 'seo', 'indexing', 'performance', 'security', 'headless', 'linking' ];

		foreach ( $scan_types as $type ) {
			$this->delete( self::make_key( $type, $blog_id, $post_id ) );
		}

		// Also flush normalizer cache.
		$this->delete( self::make_key( 'normalizer', $blog_id, $post_id ) );
		$this->delete( self::make_key( 'content_type', $blog_id, $post_id ) );
	}

	/**
	 * Flushes all site-level caches on plugin activation/deactivation.
	 * Hooked in NEXENG_Activator and NEXENG_Deactivator.
	 */
	public static function flush_all(): void {
		$instance = self::get_instance();
		$instance->flush_group( 'nexeng_' );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Ensures a cache key is safe and not too long for the DB column.
	 * WordPress transient keys are limited to 172 characters.
	 */
	private function sanitize_key( string $key ): string {
		$key = preg_replace( '/[^a-z0-9_\-]/', '_', strtolower( $key ) );

		if ( strlen( $key ) > 172 ) {
			$key = substr( $key, 0, 140 ) . '_' . md5( $key );
		}

		return $key;
	}
}
