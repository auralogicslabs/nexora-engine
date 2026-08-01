<?php
/**
 * Nexora Engine — Conflict Detector (PRO)
 *
 * Detects known plugin and theme conflicts that affect headless/hybrid
 * rendering, REST API compatibility, and general site stability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Conflict_Detector {

	private NEXENG_Cache $cache;

	// Known conflicts: [slug => [name, category, severity, reason, fix]]
	private const KNOWN_CONFLICTS = [
		'wp-super-cache/wp-cache.php' => [
			'name'     => 'WP Super Cache',
			'category' => 'caching',
			'severity' => 'medium',
			'reason'   => 'Full-page caching may serve stale HTML to REST API crawlers or break dynamic headless routes.',
			'fix'      => 'Exclude REST API paths (/wp-json/*) from cache rules. Set Cache-Control headers appropriately.',
		],
		'w3-total-cache/w3-total-cache.php' => [
			'name'     => 'W3 Total Cache',
			'category' => 'caching',
			'severity' => 'medium',
			'reason'   => 'Page and object caching can interfere with REST API responses and personalised content.',
			'fix'      => 'Disable page caching for REST API routes and logged-in users.',
		],
		'wp-rocket/wp-rocket.php' => [
			'name'     => 'WP Rocket',
			'category' => 'caching',
			'severity' => 'low',
			'reason'   => 'WP Rocket\'s cache exclusion rules are configurable; default config may cache REST API requests.',
			'fix'      => 'Add /wp-json/ to WP Rocket\'s "Never Cache URL(s)" list.',
		],
		'really-simple-ssl/rlrsssl-really-simple-ssl.php' => [
			'name'     => 'Really Simple SSL',
			'category' => 'security',
			'severity' => 'low',
			'reason'   => 'HSTS and mixed-content fixes may affect cross-origin requests from headless frontends.',
			'fix'      => 'Ensure CORS headers are correctly set for your headless domain after enabling HSTS.',
		],
		'all-in-one-wp-security-and-firewall/wp-security.php' => [
			'name'     => 'All In One WP Security',
			'category' => 'security',
			'severity' => 'medium',
			'reason'   => 'Firewall rules may block REST API requests originating from headless frontend servers.',
			'fix'      => 'Whitelist headless server IPs in the firewall settings and ensure REST API access is not blocked.',
		],
		'wordfence/wordfence.php' => [
			'name'     => 'Wordfence',
			'category' => 'security',
			'severity' => 'low',
			'reason'   => 'Rate limiting and bot blocking may affect headless build processes and CI/CD pipelines.',
			'fix'      => 'Whitelist build server IPs and ensure Wordfence rate limits do not throttle REST API consumers.',
		],
		'autoptimize/autoptimize.php' => [
			'name'     => 'Autoptimize',
			'category' => 'performance',
			'severity' => 'medium',
			'reason'   => 'Script/style aggregation may break Gutenberg block editor and conflict with headless JS bundles.',
			'fix'      => 'Disable script aggregation if using the block editor. Use selective exclusions for editor scripts.',
		],
		'jetpack/jetpack.php' => [
			'name'     => 'Jetpack',
			'category' => 'multi',
			'severity' => 'low',
			'reason'   => 'Jetpack\'s CDN, image optimization, and REST API modules can conflict with headless image pipelines.',
			'fix'      => 'Disable Jetpack CDN if using an external image CDN. Audit enabled Jetpack modules.',
		],
		'broken-link-checker/broken-link-checker.php' => [
			'name'     => 'Broken Link Checker',
			'category' => 'performance',
			'severity' => 'high',
			'reason'   => 'Continuously crawls all content and hammers the database; known to cause severe slow-downs on larger sites.',
			'fix'      => 'Replace with Nexora Engine\'s built-in broken link checker (PRO), which runs on a controlled schedule.',
		],
		'google-sitemap-generator/sitemap.php' => [
			'name'     => 'Google XML Sitemaps',
			'category' => 'seo',
			'severity' => 'medium',
			'reason'   => 'May conflict with other sitemap plugins (Yoast, RankMath) and Nexora Engine\'s sitemap module.',
			'fix'      => 'Use only one sitemap plugin. Nexora Engine PRO includes a built-in sitemap — disable others.',
		],
		'hummingbird-performance/wp-hummingbird.php' => [
			'name'     => 'Hummingbird',
			'category' => 'caching',
			'severity' => 'high',
			'reason'   => 'Hummingbird\'s page caching competes for advanced-cache.php and can serve non-headless HTML.',
			'fix'      => 'Disable Page Caching in Hummingbird settings to allow Nexora Engine to handle delivery.',
		],
	];

	public function __construct() {
		$this->cache = NEXENG_Cache::get_instance();
	}

	// ─── Public API ───────────────────────────────────────────────────────────

	/**
	 * Returns detected conflicts from active plugins.
	 *
	 * @return array<int, array{slug: string, name: string, category: string, severity: string, reason: string, fix: string}>
	 */
	public function get_conflicts( bool $force = false ): array {
		$blog_id   = get_current_blog_id();
		$cache_key = NEXENG_Cache::make_key( 'conflicts', $blog_id, 0 );

		if ( ! $force ) {
			$cached = $this->cache->get( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$conflicts = $this->scan_active_plugins();

		$this->cache->set( $cache_key, $conflicts, 3600 );

		return $conflicts;
	}

	// ─── Scan ─────────────────────────────────────────────────────────────────

	/**
	 * @return array<int, array{slug: string, name: string, category: string, severity: string, reason: string, fix: string}>
	 */
	private function scan_active_plugins(): array {
		$active    = (array) get_option( 'active_plugins', [] );
		$conflicts = [];

		foreach ( self::KNOWN_CONFLICTS as $slug => $info ) {
			if ( in_array( $slug, $active, true ) ) {
				$conflicts[] = array_merge( [ 'slug' => $slug ], $info );
			}
		}

		// Sort by severity: high → medium → low.
		usort( $conflicts, static function ( array $a, array $b ): int {
			$order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
			return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
		} );

		return $conflicts;
	}
}
