<?php
/**
 * Nexora Engine — Content Detector
 *
 * Detects the content system used by each post and caches the result.
 * Returns one of: 'elementor' | 'gutenberg' | 'classic' | 'shortcode' | 'mixed'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Content_Detector {

	private NEXENG_Cache $cache;

	public function __construct() {
		$this->cache = NEXENG_Cache::get_instance();
	}

	// ─── Public API ───────────────────────────────────────────────────────────

	/**
	 * Returns the detected content type for a post.
	 * Result is cached and stored in post meta.
	 */
	public function detect( int $post_id ): string {
		$blog_id   = get_current_blog_id();
		$cache_key = NEXENG_Cache::make_key( 'content_type', $blog_id, $post_id );

		$cached = $this->cache->get( $cache_key );
		if ( false !== $cached && is_string( $cached ) ) {
			return $cached;
		}

		$type = $this->run_detection( $post_id );

		$this->cache->set( $cache_key, $type, NEXENG_CACHE_SEO );
		update_post_meta( $post_id, '_nexeng_content_type', $type );

		return $type;
	}

	/**
	 * Detects content type for all published posts in a blog.
	 *
	 * @return array<int, string> Map of post_id => content_type
	 */
	public function detect_all( int $blog_id ): array {
		$db       = NEXENG_Database::get_instance();
		$post_ids = $db->get_all_published_post_ids( $blog_id );
		$results  = [];

		foreach ( $post_ids as $post_id ) {
			$results[ $post_id ] = $this->detect( $post_id );
		}

		return $results;
	}

	// ─── Detection Logic ──────────────────────────────────────────────────────

	private function run_detection( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return 'classic';
		}

		$has_elementor  = $this->is_elementor( $post_id );
		$has_gutenberg  = $this->is_gutenberg( $post );
		$has_shortcodes = $this->has_shortcodes( $post->post_content );

		// Mixed: Elementor + shortcodes, or Gutenberg + shortcodes.
		if ( ( $has_elementor || $has_gutenberg ) && $has_shortcodes ) {
			return 'mixed';
		}

		if ( $has_elementor ) {
			return 'elementor';
		}

		if ( $has_gutenberg ) {
			return 'gutenberg';
		}

		// Classic with shortcodes only — label as shortcode so callers know
		// PHP rendering is required.
		if ( $has_shortcodes ) {
			return 'shortcode';
		}

		return 'classic';
	}

	// ─── Detectors ───────────────────────────────────────────────────────────

	private function is_elementor( int $post_id ): bool {
		$data = get_post_meta( $post_id, '_elementor_data', true );

		if ( empty( $data ) || ! is_string( $data ) ) {
			return false;
		}

		// Must be valid JSON and non-empty array.
		$decoded = json_decode( $data, true );

		return JSON_ERROR_NONE === json_last_error()
			&& is_array( $decoded )
			&& ! empty( $decoded );
	}

	private function is_gutenberg( WP_Post $post ): bool {
		return str_contains( $post->post_content, '<!-- wp:' );
	}

	private function has_shortcodes( string $content ): bool {
		// Match any [shortcode] or [shortcode attr="val"] pattern.
		return (bool) preg_match( '/\[[a-zA-Z_\-][a-zA-Z0-9_\-]*[^\]]*\]/', $content );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Returns the cached/stored content type without triggering fresh detection.
	 * Falls back to live detection if no cached value exists.
	 */
	public function get_stored_type( int $post_id ): string {
		$stored = get_post_meta( $post_id, '_nexeng_content_type', true );

		if ( ! empty( $stored ) ) {
			return (string) $stored;
		}

		return $this->detect( $post_id );
	}

	/**
	 * Returns a human-readable label for a content type.
	 */
	public static function label( string $type ): string {
		$labels = [
			'elementor'  => __( 'Elementor', 'nexora-engine' ),
			'gutenberg'  => __( 'Gutenberg', 'nexora-engine' ),
			'classic'    => __( 'Classic Editor', 'nexora-engine' ),
			'shortcode'  => __( 'Shortcode', 'nexora-engine' ),
			'mixed'      => __( 'Mixed', 'nexora-engine' ),
		];

		return $labels[ $type ] ?? ucfirst( $type );
	}
}
