<?php
/**
 * Nexora Engine — Indexing Analyser
 *
 * Detects indexing problems per post: noindex directives, canonical conflicts,
 * password protection, and sitemap omissions.
 * Accounts for 20% of overall page score.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Indexing {

	private NEXENG_Cache        $cache;
	private NEXENG_Issue_Engine $issues;

	public function __construct() {
		$this->cache  = NEXENG_Cache::get_instance();
		$this->issues = NEXENG_Issue_Engine::get_instance();
	}

	// ─── Public API ───────────────────────────────────────────────────────────

	/**
	 * Runs all indexing checks and returns the indexing score (0-100).
	 */
	public function analyse( int $post_id, bool $force = false ): int {
		$blog_id   = get_current_blog_id();
		$cache_key = NEXENG_Cache::make_key( 'indexing', $blog_id, $post_id );

		if ( ! $force ) {
			$cached = $this->cache->get( $cache_key );
			if ( false !== $cached && is_int( $cached ) ) {
				return $cached;
			}
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$detected_keys = $this->run_checks( $blog_id, $post_id, $post );

		$this->issues->auto_resolve_cleared( $blog_id, $post_id, $detected_keys );

		$score = NEXENG_Scorer::calculate_module_score( $blog_id, $post_id, $detected_keys );

		NEXENG_Database::get_instance()->insert_scan_result(
			$blog_id,
			$post_id,
			'indexing',
			[ 'checked_keys' => $detected_keys ],
			$score
		);

		$this->cache->set( $cache_key, $score, NEXENG_CACHE_INDEXING );

		return $score;
	}

	// ─── Checks ───────────────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function run_checks( int $blog_id, int $post_id, WP_Post $post ): array {
		$detected = [];

		$detected = array_merge( $detected, $this->check_noindex_meta( $blog_id, $post_id, $post ) );
		$detected = array_merge( $detected, $this->check_noindex_header( $blog_id, $post_id, $post ) );
		$detected = array_merge( $detected, $this->check_canonical_conflict( $blog_id, $post_id, $post ) );
		$detected = array_merge( $detected, $this->check_password_protected( $blog_id, $post_id, $post ) );

		return $detected;
	}

	// ─── noindex Meta ─────────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_noindex_meta( int $blog_id, int $post_id, WP_Post $post ): array {
		$detected = [];

		$noindex = false;

		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$robots_noindex = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			if ( '1' === $robots_noindex ) {
				$noindex = true;
			}
		}

		// RankMath.
		if ( ! $noindex && defined( 'RANK_MATH_VERSION' ) ) {
			$robots = (string) get_post_meta( $post_id, 'rank_math_robots', true );
			if ( str_contains( $robots, 'noindex' ) ) {
				$noindex = true;
			}
		}

		// WordPress core reading settings (discourage search engines).
		if ( ! $noindex && '0' === get_option( 'blog_public' ) ) {
			$noindex = true;
		}

		if ( $noindex ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_noindex_meta', [
				'title'       => __( 'Page Set to noindex', 'nexora-engine' ),
				'severity'    => 'critical',
				'explanation' => __( 'This page has a robots meta tag or SEO plugin setting instructing search engines not to index it. The page will not appear in search results.', 'nexora-engine' ),
				'fix'         => __( 'If this page should be indexed, open your SEO plugin settings for this page and change the robots directive from "noindex" to "index". Check WordPress Reading Settings if the whole site is set to discourage crawlers.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_noindex_meta';
		}

		return $detected;
	}

	// ─── noindex Header ───────────────────────────────────────────────────────

	/**
	 * Checks X-Robots-Tag response header via wp_remote_head().
	 *
	 * @return string[]
	 */
	private function check_noindex_header( int $blog_id, int $post_id, WP_Post $post ): array {
		$detected = [];
		$url      = get_permalink( $post_id );

		if ( empty( $url ) ) {
			return $detected;
		}

		$response = wp_remote_head( $url, [ 'timeout' => 10, 'redirection' => 3 ] );

		if ( is_wp_error( $response ) ) {
			return $detected;
		}

		$x_robots = wp_remote_retrieve_header( $response, 'x-robots-tag' );

		if ( ! empty( $x_robots ) && str_contains( strtolower( $x_robots ), 'noindex' ) ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_noindex_header', [
				'title'       => __( 'X-Robots-Tag Header Contains noindex', 'nexora-engine' ),
				'severity'    => 'critical',
				/* translators: %s: X-Robots-Tag header value */
				'explanation' => sprintf( __( 'The server is sending an X-Robots-Tag HTTP header with value "%s", instructing search engines not to index this page.', 'nexora-engine' ), esc_html( $x_robots ) ),
				'fix'         => __( 'Check your server configuration (nginx/Apache), CDN settings, or any plugin that modifies HTTP headers. Remove or change the X-Robots-Tag header to allow indexing.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_noindex_header';
		}

		return $detected;
	}

	// ─── Canonical Conflict ───────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_canonical_conflict( int $blog_id, int $post_id, WP_Post $post ): array {
		$detected = [];

		$canonical = '';

		if ( defined( 'WPSEO_VERSION' ) ) {
			$canonical = (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$canonical = (string) get_post_meta( $post_id, 'rank_math_canonical_url', true );
		}

		if ( empty( $canonical ) ) {
			return $detected;
		}

		$permalink      = (string) get_permalink( $post_id );
		$canonical_norm = rtrim( $canonical, '/' );
		$permalink_norm = rtrim( $permalink, '/' );

		if ( $canonical_norm !== $permalink_norm ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_canonical_conflict', [
				'title'       => __( 'Canonical URL Conflicts With Permalink', 'nexora-engine' ),
				'severity'    => 'high',
				/* translators: 1: canonical URL, 2: permalink */
				'explanation' => sprintf( __( 'The canonical tag points to %1$s but the page permalink is %2$s. Search engines will attribute the page\'s authority to the canonical URL, potentially deindexing this page.', 'nexora-engine' ), esc_url( $canonical ), esc_url( $permalink ) ),
				'fix'         => __( 'Update the canonical URL in your SEO plugin to exactly match the page permalink, unless this is an intentional consolidation of duplicate content.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_canonical_conflict';
		}

		return $detected;
	}

	// ─── Password Protected ───────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_password_protected( int $blog_id, int $post_id, WP_Post $post ): array {
		$detected = [];

		if ( ! empty( $post->post_password ) ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_password_protected', [
				'title'       => __( 'Password-Protected Page', 'nexora-engine' ),
				'severity'    => 'medium',
				'explanation' => __( 'This page is password-protected. Search engine crawlers cannot access or index password-protected content, so the page will not appear in search results.', 'nexora-engine' ),
				'fix'         => __( 'If this page should be publicly indexed, remove the password protection in the WordPress editor under "Visibility". If it must stay private, ensure it is excluded from your sitemap.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_password_protected';
		}

		return $detected;
	}
}
