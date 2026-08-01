<?php
/**
 * Nexora Engine — Server-Side Analytics
 *
 * Handles hit logging, data aggregation, and Core Web Vitals beaconing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Analytics works exclusively on the plugin's OWN custom tables (nexeng_hits,
// nexeng_hits_daily, nexeng_vitals). Every table name comes from the prefix-safe
// NEXENG_Database map or directly from $wpdb->prefix — never user input — so it
// cannot be a %s placeholder. User-supplied values (where any exist) are passed
// through $wpdb->prepare(). These plugin tables are not in the object cache and
// require direct queries. Disabling the matching sniffs file-wide on that basis:
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

class NEXENG_Analytics {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->ensure_tables();

		// Register aggregation cron
		add_action( 'nexeng_analytics_aggregate', [ $this, 'aggregate_hits' ] );
		add_action( 'nexeng_analytics_aggregate', [ $this, 'ingest_logs' ] );
		if ( ! wp_next_scheduled( 'nexeng_analytics_aggregate' ) ) {
			wp_schedule_event( time(), 'hourly', 'nexeng_analytics_aggregate' );
		}

		// REST API for Vitals
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Add ACAO: * to the vitals POST response so any-origin static pages
		// can send beacons without CORS errors (same-origin, CDN, or file://).
		add_filter( 'rest_pre_serve_request', [ $this, 'add_vitals_cors_headers' ], 10, 3 );

		// Inject vitals script into frontend
		add_action( 'wp_footer', [ $this, 'inject_vitals_script' ] );

		// Track cache misses — only fires when PHP actually rendered the
		// frontend page (drop-in serves cached pages without running PHP).
		// This is the single source of 'miss' records in the hits table.
		add_action( 'template_redirect', [ $this, 'track_php_render_miss' ], 999 );
	}

	private function ensure_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = "
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

	/**
	 * Log a hit from the application layer.
	 * Note: Drop-in hits are handled separately in advanced-cache.php for performance.
	 */
	public function log_hit( $post_id, $type = 'miss', $ttfb = 0 ) {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		$url = $this->normalize_frontend_url( home_url( NEXENG_Request::uri() ) );
		if ( false === $url ) {
			return;
		}

		$ip = $this->get_ip();
		$ua = NEXENG_Request::user_agent();
		$ref = NEXENG_Request::referer();

		$wpdb->insert(
			$db->hits,
			[
				'blog_id'       => get_current_blog_id(),
				'post_id'       => $post_id,
				'url'           => $url,
				'hit_type'      => $type,
				'response_time' => $ttfb,
				'ip_hash'       => hash( 'sha256', $ip . 'nexeng_salt' ), // Privacy first
				'ua_class'      => $this->classify_ua( $ua ),
				'ref_class'     => $this->classify_referrer( $ref ),
				'country'       => NEXENG_Request::server( 'HTTP_CF_IPCOUNTRY' ) ?: null, // Cloudflare support
			]
		);
	}

	/**
	 * Get stats for dashboard
	 */
	public function get_stats() {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		$this->ingest_logs();
		$recent_hits = $this->get_recent_frontend_hits();

		// 1. Hit Ratio (24h)
		$hits = 0;
		$misses = 0;
		foreach ( $recent_hits as $row ) {
			if ( strtotime( $row['created_at'] ) < strtotime( '-24 hours' ) ) {
				continue;
			}
			if ( $row['hit_type'] === 'hit' ) {
				$hits++;
			} elseif ( $row['hit_type'] === 'miss' ) {
				$misses++;
			}
		}
		$total = $hits + $misses;
		$hit_ratio = $total > 0 ? round( ($hits / $total) * 100, 1 ) : 0;

		// Last data point timestamp — most recent row within the 7-day window
		// (used by the dashboard to show "last recorded X ago" freshness label).
		$last_hit_at = null;
		foreach ( $recent_hits as $row ) {
			$ts = strtotime( $row['created_at'] );
			if ( $last_hit_at === null || $ts > $last_hit_at ) {
				$last_hit_at = $ts;
			}
		}

		// 2. TTFB Percentiles
		// Use max(1,...) so Windows 0ms readings (microtime resolution) still
		// count as valid data rather than disappearing from percentile arrays.
		$ttfbs = [];
		foreach ( $recent_hits as $row ) {
			if ( $row['hit_type'] === 'hit' && $row['response_time'] !== null && strtotime( $row['created_at'] ) >= strtotime( '-24 hours' ) ) {
				$ttfbs[] = max( 1, (int) $row['response_time'] );
			}
		}
		sort( $ttfbs );
		$p50 = 0; $p95 = 0;
		if ( ! empty( $ttfbs ) ) {
			$count = count( $ttfbs );
			$p50 = $ttfbs[min( $count - 1, (int) floor( $count * 0.5 ) )];
			$p95 = $ttfbs[min( $count - 1, (int) floor( $count * 0.95 ) )];
		}

		// 3. Top Pages (7d)
		$top_pages = $this->aggregate_top_pages( $recent_hits, 10 );

		// 4. Traffic Summary Data (Last 7 days).
		$chart_data = $this->aggregate_daily_rows( $recent_hits );

		// 5. Core Web Vitals (p75 Last 7 days).
		$vitals_summary = $this->get_vitals_summary();

		return [
			'hit_ratio'          => $hit_ratio,
			'traffic_total_24h'  => $total,
			'last_hit_at'        => $last_hit_at,
			'ttfb_p50'           => $p50,
			'ttfb_p95'           => $p95,
			'ttfb_samples'       => count( $ttfbs ),
			'top_pages'          => $top_pages,
			'chart'              => $chart_data,
			'vitals'             => $vitals_summary['values'],
			'vitals_samples'     => $vitals_summary['samples'],
			'vitals_method'      => 'p75',
		];
	}

	/**
	 * Get top pages by traffic.
	 */
	public function get_top_pages( $limit = 10 ) {
		return $this->aggregate_top_pages( $this->get_recent_frontend_hits(), (int) $limit );
	}

	/**
	 * Get latest vitals (p75 Last 7 days).
	 */
	public function get_latest_vitals() {
		$summary = $this->get_vitals_summary();
		return $summary['values'];
	}

	/**
	 * Get latest activity for the Neural Pulse feed
	 */
	public function get_latest_activity( $limit = 10 ) {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, hit_type, response_time, country, created_at 
			 FROM {$db->hits} 
			 ORDER BY created_at DESC 
			 LIMIT %d",
			$limit * 4
		), ARRAY_A );

		// Format for UI
		$results = [];
		foreach ( $rows as $r ) {
			$url = $this->normalize_frontend_url( $r['url'] );
			if ( false === $url ) {
				continue;
			}
			$r['url'] = $url;
			$r['time_ago'] = human_time_diff( strtotime( $r['created_at'] ), current_time( 'timestamp' ) ) . ' ago';
			$r['type_label'] = $r['hit_type'] === 'hit' ? 'Cache Hit' : ( $r['hit_type'] === 'miss' ? 'Cache Miss' : 'Security Block' );
			$results[] = $r;
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Ingest hits from the high-speed file log written by the drop-in.
	 *
	 * Drop-in v2+ writes to nexora-private/nexeng_hits.log (protected directory).
	 * Older installs used the root uploads path — we fall back to that for
	 * a seamless transition if the private file doesn't exist yet.
	 */
	public function ingest_logs() {
		// One-time repair: fix any existing 0-ms records caused by Windows
		// microtime() resolution — set them to 1 ms so they appear in TTFB stats.
		// Runs unconditionally (not gated by log file presence) so it fires even
		// when there are no new log entries to process.
		$repair_flag = 'nexeng_ttfb_0ms_repaired';
		if ( ! get_option( $repair_flag ) ) {
			global $wpdb;
			$db = NEXENG_Database::get_instance();
			$wpdb->query( "UPDATE {$db->hits} SET response_time = 1 WHERE response_time = 0 AND hit_type = 'hit'" );
			update_option( $repair_flag, 1, false );
		}

		// Resolved at runtime, never assembled from ABSPATH . 'wp-content/uploads'.
		// Neither segment is fixed: WP_CONTENT_DIR can point anywhere, the uploads
		// directory is configurable, and on multisite each site gets its own — so
		// the hardcoded form silently read the wrong file, or none at all.
		$uploads  = wp_upload_dir();
		$basedir  = ! empty( $uploads['basedir'] ) ? trailingslashit( $uploads['basedir'] ) : '';
		if ( '' === $basedir ) {
			return;
		}

		// Primary path: private subdirectory (drop-in v2+, current).
		$log_file = $basedir . 'nexora-private/nexeng_hits.log';

		// Legacy fallback: root uploads path (pre-v2 drop-in or custom installs).
		if ( ! is_file( $log_file ) || ! is_readable( $log_file ) ) {
			$log_file = $basedir . 'nexeng_hits.log';
		}

		if ( ! is_file( $log_file ) || ! is_readable( $log_file ) ) return;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native handle required for flock()/ftruncate() atomic log rotation; WP_Filesystem offers no locking primitive.
		$handle = fopen( $log_file, 'r+' );
		if ( ! $handle ) return;

		// Lock and read
		if ( flock( $handle, LOCK_EX ) ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				$data = json_decode( trim( $line ), true );
				if ( $data ) {
					$this->log_hit_raw( $data );
				}
			}
			// Clear the file
			ftruncate( $handle, 0 );
			flock( $handle, LOCK_UN );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the flock() handle above; WP_Filesystem cannot provide advisory file locking.
		fclose( $handle );
	}

	private function log_hit_raw( $data ) {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		$url = $this->normalize_frontend_url( $data['url'] ?? '' );
		if ( false === $url ) {
			return;
		}

		// Log-file keys: 'ua' (not 'user_agent'), 'ts' (not 'timestamp').
		// Treat 0ms TTFB as 1ms (Windows microtime() resolution artefact).
		$wpdb->insert(
			$db->hits,
			[
				'blog_id'       => get_current_blog_id(),
				'url'           => $url,
				'hit_type'      => $data['hit_type'] ?? 'hit',
				'response_time' => max( 1, (int) ( $data['ttfb'] ?? 0 ) ),
				'ip_hash'       => hash( 'sha256', ( $data['ip'] ?? '' ) . 'nexeng_salt' ),
				'ua_class'      => $this->classify_ua( $data['ua'] ?? $data['user_agent'] ?? '' ),
				'ref_class'     => $this->classify_referrer( NEXENG_Request::referer() ),
				'created_at'    => gmdate( 'Y-m-d H:i:s', $data['ts'] ?? $data['timestamp'] ?? time() ),
			]
		);
	}

	/**
	 * Records a 'miss' entry in wp_nexeng_hits whenever WordPress fully renders a
	 * frontend page in PHP. Triggered on `template_redirect` (only fires on
	 * frontend page requests — never on admin, AJAX, REST, feeds, embeds, or
	 * cached pages served by the drop-in).
	 *
	 * The actual DB insert is deferred to `shutdown` so we can record the
	 * full server render time as TTFB. The write does not delay the response
	 * — WP fires `shutdown` after output is flushed.
	 *
	 * Filters in place:
	 *   • Logged-in users   — admins browsing the site shouldn't pollute
	 *                         anonymous-visitor cache stats.
	 *   • Bots              — classify_ua flags them; we skip at insert time.
	 *   • Non-content URLs  — only is_singular / is_home / is_archive / is_search
	 *                         / is_404 (real page renders, not feeds or robots.txt).
	 */
	public function track_php_render_miss(): void {
		// Hard skips — these are never frontend page renders.
		if ( wp_doing_ajax() || wp_doing_cron() || is_admin() ) return;
		if ( is_feed() || is_robots() || is_embed() || is_preview() ) return;
		// Defined in WP 5.4+, helps skip favicon noise.
		if ( function_exists( 'is_favicon' ) && is_favicon() ) return;
		// REST requests don't fire template_redirect anyway, but defense in depth.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

		// Only track real visitor-facing pages. Skip everything else (custom
		// REST-routed views, etc).
		if ( ! ( is_singular() || is_home() || is_front_page() || is_archive() || is_search() || is_404() ) ) {
			return;
		}

		// Skip logged-in users — they intentionally bypass the cache and would
		// otherwise show as 100% misses, skewing the ratio.
		if ( is_user_logged_in() ) return;

		// Quick bot filter — saves a DB insert per crawl.
		$ua = NEXENG_Request::user_agent();
		if ( $this->classify_ua( $ua ) === 'bot' ) return;

		$post_id = is_singular() ? (int) get_the_ID() : 0;
		$start   = isset( $_SERVER['REQUEST_TIME_FLOAT'] )
			? (float) sanitize_text_field( wp_unslash( $_SERVER['REQUEST_TIME_FLOAT'] ) )
			: microtime( true );

		// Defer the DB write to shutdown so we capture the FULL render time
		// (template_redirect fires before any output). The insert happens
		// after the response is sent so it never delays the visitor.
		add_action( 'shutdown', function() use ( $post_id, $start ) {
			$ttfb_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			$this->log_miss_record( $post_id, $ttfb_ms );
		} );
	}

	/**
	 * Inserts a 'miss' record into wp_nexeng_hits. Called from the shutdown
	 * handler registered by track_php_render_miss().
	 */
	private function log_miss_record( int $post_id, int $ttfb_ms ): void {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		$url = $this->normalize_frontend_url( home_url( NEXENG_Request::uri() ) );
		if ( false === $url ) {
			return;
		}

		$wpdb->insert(
			$db->hits,
			[
				'blog_id'       => get_current_blog_id(),
				'post_id'       => $post_id ?: null,
				'url'           => $url,
				'hit_type'      => 'miss',
				'response_time' => max( 0, $ttfb_ms ),
				'ip_hash'       => hash( 'sha256', $this->get_ip() . 'nexeng_salt' ),
				'ua_class'      => $this->classify_ua( NEXENG_Request::user_agent() ),
				'ref_class'     => $this->classify_referrer( NEXENG_Request::referer() ),
				'country'       => NEXENG_Request::server( 'HTTP_CF_IPCOUNTRY' ) ?: null,
			]
		);
	}

	private function log_hit_for_current_request( int $post_id, int $response_time = 0 ): void {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		$referer = NEXENG_Request::referer();
		$url     = '' !== $referer ? $referer : home_url( NEXENG_Request::uri() );
		$url = $this->normalize_frontend_url( $url );
		if ( false === $url ) {
			return;
		}

		// Determine hit_type accurately:
		// • 'hit'  — a cached static file exists for this page (drop-in serves it
		//            for anonymous visitors; browser TTFB should be very low).
		// • 'miss' — no static file → PHP rendered the page → high TTFB expected.
		// This is more accurate than hard-coding 'hit' for every vitals beacon,
		// which would inflate the hit ratio even on pages that were PHP-rendered.
		$hit_type = 'miss';
		if ( class_exists( 'NEXENG_SSG' ) ) {
			$ssg = NEXENG_SSG::get_instance();
			// Posts with a manifest entry have a cached static file. For archive
			// pages (homepage, categories) the manifest key is a string, so we
			// also accept any fast TTFB (< 150 ms) as a proxy for cache-served.
			if ( $post_id > 0 && $ssg->manifest_entry( $post_id ) ) {
				$hit_type = 'hit';
			} elseif ( $response_time > 0 && $response_time < 150 ) {
				// Very fast TTFB from the browser → almost certainly served from
				// cache (static file or CDN). Use as a fallback for archive pages
				// that don't have a numeric post_id in the manifest.
				$hit_type = 'hit';
			}
		}

		$wpdb->insert(
			$db->hits,
			[
				'blog_id'       => get_current_blog_id(),
				'post_id'       => $post_id,
				'url'           => $url,
				'hit_type'      => $hit_type,
				'response_time' => max( 0, $response_time ),
				'ip_hash'       => hash( 'sha256', $this->get_ip() . 'nexeng_salt' ),
				'ua_class'      => $this->classify_ua( NEXENG_Request::user_agent() ),
				'ref_class'     => $this->classify_referrer( NEXENG_Request::referer() ),
				'country'       => NEXENG_Request::server( 'HTTP_CF_IPCOUNTRY' ) ?: null,
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	private function get_recent_frontend_hits(): array {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		// Exclude bot traffic — Googlebot/Bingbot crawls would otherwise inflate
		// the hit ratio and make TTFB stats noisy. The classify_ua() function
		// stamps 'bot' on every insert, so this is a single indexed condition.
		$rows = $wpdb->get_results(
			"SELECT url, hit_type, response_time, created_at
			 FROM {$db->hits}
			 WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
			   AND ua_class != 'bot'
			 ORDER BY created_at DESC",
			ARRAY_A
		);

		$clean = [];
		foreach ( $rows ?: [] as $row ) {
			$url = $this->normalize_frontend_url( $row['url'] ?? '' );
			if ( false === $url ) {
				continue;
			}
			$row['url'] = $url;
			$clean[] = $row;
		}

		return $clean;
	}

	private function aggregate_top_pages( array $rows, int $limit ): array {
		$pages = [];
		foreach ( $rows as $row ) {
			$url = $row['url'];
			if ( ! isset( $pages[ $url ] ) ) {
				$pages[ $url ] = [ 'url' => $url, 'hits' => 0 ];
			}
			$pages[ $url ]['hits']++;
		}

		usort( $pages, fn( $a, $b ) => $b['hits'] <=> $a['hits'] );
		return array_slice( $pages, 0, max( 1, $limit ) );
	}

	private function aggregate_daily_rows( array $rows ): array {
		$days = [];
		for ( $i = 6; $i >= 0; $i-- ) {
			$day = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$days[ $day ] = [ 'day' => $day, 'hits' => 0, 'misses' => 0 ];
		}

		foreach ( $rows as $row ) {
			$day = gmdate( 'Y-m-d', strtotime( $row['created_at'] ) );
			if ( ! isset( $days[ $day ] ) ) {
				continue;
			}
			if ( $row['hit_type'] === 'hit' ) {
				$days[ $day ]['hits']++;
			} elseif ( $row['hit_type'] === 'miss' ) {
				$days[ $day ]['misses']++;
			}
		}

		return array_values( $days );
	}

	private function get_vitals_summary(): array {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT metric_name, metric_value
			 FROM {$db->vitals}
			 WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
			ARRAY_A
		);

		$buckets = [
			'LCP' => [],
			'INP' => [],
			'CLS' => [],
		];

		foreach ( $rows ?: [] as $row ) {
			$metric = strtoupper( (string) ( $row['metric_name'] ?? '' ) );
			if ( ! isset( $buckets[ $metric ] ) || ! is_numeric( $row['metric_value'] ?? null ) ) {
				continue;
			}
			$value = (float) $row['metric_value'];
			if ( $value < 0 ) {
				continue;
			}
			$buckets[ $metric ][] = $value;
		}

		$values  = [];
		$samples = [];

		foreach ( $buckets as $metric => $metric_values ) {
			$samples[ $metric ] = count( $metric_values );
			if ( empty( $metric_values ) ) {
				continue;
			}

			sort( $metric_values, SORT_NUMERIC );
			$precision = $metric === 'CLS' ? 3 : 0;
			$values[ $metric ] = round( $this->percentile( $metric_values, 0.75 ), $precision );
		}

		return [
			'values'  => $values,
			'samples' => $samples,
		];
	}

	private function percentile( array $sorted_values, float $percentile ): float {
		$count = count( $sorted_values );
		if ( $count === 0 ) {
			return 0.0;
		}
		if ( $count === 1 ) {
			return (float) $sorted_values[0];
		}

		$index = (int) ceil( $count * $percentile ) - 1;
		$index = max( 0, min( $count - 1, $index ) );
		return (float) $sorted_values[ $index ];
	}

	private function normalize_frontend_url( string $url ) {
		$url = trim( $url );
		if ( $url === '' ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		$path = $parts['path'] ?? $url;
		$query = $parts['query'] ?? '';
		$home_path = rtrim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );

		if ( $home_path !== '' && strpos( $path, $home_path ) === 0 ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = '/' . trim( $path, '/' );
		if ( $path !== '/' ) {
			$path .= '/';
		}

		if ( $query !== '' || preg_match( '#/(wp-admin|wp-login\.php|wp-json|wp-content|wp-includes|xmlrpc\.php|_ncx)(/|$)#i', $path ) ) {
			return false;
		}

		if ( preg_match( '#\.[a-z0-9]{2,5}/?$#i', $path ) ) {
			return false;
		}

		return $path;
	}

	/**
	 * Aggregate raw hits into daily summaries.
	 */
	public function aggregate_hits() {
		$db = NEXENG_Database::get_instance();
		global $wpdb;

		// Move data older than 24 hours to the daily table
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$query = "
			INSERT INTO {$db->hits_daily} (blog_id, post_id, day, hits, misses, avg_ttfb)
			SELECT 
				blog_id, 
				post_id, 
				DATE(created_at) as day,
				SUM(CASE WHEN hit_type = 'hit' THEN 1 ELSE 0 END) as hits,
				SUM(CASE WHEN hit_type = 'miss' THEN 1 ELSE 0 END) as misses,
				AVG(response_time) as avg_ttfb
			FROM {$db->hits}
			WHERE created_at < %s
			GROUP BY blog_id, post_id, DATE(created_at)
			ON DUPLICATE KEY UPDATE 
				hits = hits + VALUES(hits),
				misses = misses + VALUES(misses),
				avg_ttfb = (avg_ttfb + VALUES(avg_ttfb)) / 2
		";

		$wpdb->query( $wpdb->prepare( $query, current_time( 'mysql' ) ) );

		// Cleanup raw hits older than 7 days to save space
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->hits} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ) ) );
	}

	/**
	 * Append ACAO: * to every /nexeng/v1/vitals REST response.
	 * The vitals endpoint is a public fire-and-forget beacon that must work
	 * from any page origin — same domain, CDN subdomain, or during local
	 * file:// testing.  Only applies to the vitals route; all other nexeng/v1
	 * routes are left unchanged.
	 */
	public function add_vitals_cors_headers( bool $served, $result, $request ): bool {
		if ( '/nexeng/v1/vitals' === $request->get_route() ) {
			header( 'Access-Control-Allow-Origin: *' );
		}
		return $served;
	}

	public function register_rest_routes() {
		register_rest_route( 'nexeng/v1', '/vitals', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_vitals_report' ],
			'permission_callback' => '__return_true', // Public beacon
		] );

		// Vitals is a fire-and-forget beacon posted from static pages.
		// Those pages can be served from any origin (CDN, different subdomain,
		// or — during local testing — file://).  Respond to OPTIONS preflight
		// with ACAO: * so the POST is never blocked by CORS.
		add_action( 'init', function () {
			if ( 'OPTIONS' !== NEXENG_Request::method() ) {
				return;
			}
			if ( false === strpos( NEXENG_Request::uri( '' ), 'nexeng/v1/vitals' ) ) {
				return;
			}
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type' );
			header( 'Access-Control-Max-Age: 86400' );
			header( 'Content-Length: 0' );
			status_header( 204 );
			exit;
		}, 5 );
	}

	public function handle_vitals_report( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || empty( $data ) ) {
			$raw = $request->get_body();
			if ( is_string( $raw ) && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$data = $decoded;
				}
			}
		}
		if ( ! is_array( $data ) || empty( $data['post_id'] ) ) {
			return new WP_Error( 'invalid_data', 'Missing vitals data', [ 'status' => 400 ] );
		}

		$allowed_metrics = [ 'LCP', 'INP', 'CLS' ];

		// Normalise both payload shapes into a uniform { metric => value } map.
		//   • v3 (current beacon): { post_id, metrics: { LCP, INP, CLS }, ttfb }
		//   • v2 (legacy):         { post_id, metric: 'LCP', value: 1234, ttfb }
		// Accepting both keeps in-flight beacons from older cached scripts working
		// during the rollout window — no data loss while browsers refresh.
		$pairs = [];
		if ( isset( $data['metrics'] ) && is_array( $data['metrics'] ) ) {
			foreach ( $data['metrics'] as $metric => $value ) {
				$metric = strtoupper( sanitize_key( $metric ) );
				if ( in_array( $metric, $allowed_metrics, true ) && is_numeric( $value ) ) {
					$pairs[ $metric ] = (float) $value;
				}
			}
		} elseif ( ! empty( $data['metric'] ) && array_key_exists( 'value', $data ) ) {
			$metric = strtoupper( sanitize_key( $data['metric'] ) );
			if ( in_array( $metric, $allowed_metrics, true ) && is_numeric( $data['value'] ) ) {
				$pairs[ $metric ] = (float) $data['value'];
			}
		}

		if ( empty( $pairs ) ) {
			return new WP_Error( 'invalid_metric', 'Invalid vitals data', [ 'status' => 400 ] );
		}

		// Drop bot-originated vitals at the door — they don't represent real
		// user experience and skew the LCP/INP/CLS averages.
		$ua_class = $this->classify_ua( NEXENG_Request::user_agent() );
		if ( $ua_class === 'bot' ) {
			return rest_ensure_response( [ 'success' => true, 'skipped' => 'bot' ] );
		}

		// Skip logged-in admins/editors — they're authoring/preview traffic, not
		// representative real-world users, and would skew per-user-visible metrics.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return rest_ensure_response( [ 'success' => true, 'skipped' => 'admin' ] );
		}

		$db      = NEXENG_Database::get_instance();
		$post_id = (int) $data['post_id'];
		$blog_id = get_current_blog_id();
		global $wpdb;

		foreach ( $pairs as $metric => $value ) {
			$wpdb->insert(
				$db->vitals,
				[
					'blog_id'      => $blog_id,
					'post_id'      => $post_id,
					'metric_name'  => $metric,
					'metric_value' => $value,
				]
			);
		}

		// Legacy compatibility var so downstream code (if any) referring to
		// $metric / $data['value'] still works during the deprecation window.
		// Most installs only see the single-metric beacon during the first
		// hour after this upgrade ships.
		$metric = array_key_first( $pairs );

		// NOTE: Hit-logging is NOT done here anymore.
		// Previously the LCP beacon called log_hit_for_current_request() which
		// double-counted cache hits (drop-in already logs them) and contaminated
		// the response_time column with browser-side TTFB mixed in with the
		// drop-in's server-side measurements.
		// Hit/miss records now have exactly one source per request:
		//   • cache hit  → drop-in writes to nexeng_hits.log (ingest_logs picks up)
		//   • cache miss → PHP template_redirect+shutdown writes a miss record
		// See track_php_render_miss() below.

		return rest_ensure_response( [ 'success' => true ] );
	}

	public function inject_vitals_script() {
		if ( is_admin() ) return;
		if ( get_option( 'nexeng_analytics_enabled', 'on' ) !== 'on' ) return;
		$post_id = get_the_ID();
		if ( ! $post_id ) return;

		?>
		<?php ob_start(); ?>
		(function() {
			if (!('PerformanceObserver' in window)) return;
			const pid      = <?php echo (int) $post_id; ?>;
			<?php // wp_json_encode emits the quotes and escapes for a JS string context.
			// esc_url_raw was wrong here: it sanitizes a URL for storage, it does
			// not escape output. ?>
			const endpoint = <?php echo wp_json_encode( rest_url( 'nexeng/v1/vitals' ) ); ?>;
			const nav      = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
			const ttfb     = nav ? Math.max(0, Math.round(nav.responseStart)) : 0;

			// Collected values — updated continuously, sent ONCE on page exit.
			// Earlier history:
			//   v1: 1 POST per interaction (INP) + 2× LCP/CLS (pagehide AND visibilitychange)
			//   v2: max 3 POSTs total (one per metric, all on page exit)
			//   v3 (current): SINGLE batched POST containing every available metric —
			//                 one PHP hit per page view instead of three. Halves admin-ajax
			//                 pressure on Free Tier sites where /wp-json/ bypasses the
			//                 static cache.
			let lcp = 0, inp = 0, cls = 0, flushed = false;

			function flush() {
				if (flushed) return; // guard against pagehide + visibilitychange both firing
				flushed = true;
				const metrics = {};
				if (lcp > 0) metrics.LCP = lcp;
				if (cls > 0) metrics.CLS = Math.round(cls * 1000) / 1000;
				if (inp > 0) metrics.INP = inp;
				if (!Object.keys(metrics).length) return;
				const payload = JSON.stringify({ post_id: pid, metrics: metrics, ttfb: ttfb });
				if (navigator.sendBeacon) {
					try {
						const blob = new Blob([payload], { type: 'application/json' });
						if (navigator.sendBeacon(endpoint, blob)) return;
					} catch (e) {}
				}
				fetch(endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					keepalive: true,
					body: payload
				}).catch(function(){});
			}

			// LCP — collect the latest candidate; report on flush only.
			try {
				new PerformanceObserver((l) => {
					const e = l.getEntries();
					if (e.length) lcp = Math.round(e[e.length - 1].startTime);
				}).observe({type: 'largest-contentful-paint', buffered: true});
			} catch (e) {}

			// INP — track the WORST interaction seen; report max value on flush.
			// Do NOT call send() here — it was firing on every click/keypress.
			try {
				if (PerformanceObserver.supportedEntryTypes.includes('event')) {
					new PerformanceObserver((l) => {
						for (const e of l.getEntries()) {
							if (e.interactionId && e.duration > inp) inp = Math.round(e.duration);
						}
					}).observe({type: 'event', durationThreshold: 16, buffered: true});
				}
			} catch (e) {}

			// CLS — accumulate layout shift; report on flush only.
			try {
				new PerformanceObserver((l) => {
					for (const e of l.getEntries()) {
						if (!e.hadRecentInput) cls += e.value;
					}
				}).observe({type: 'layout-shift', buffered: true});
			} catch (e) {}

			// Single flush point — fires when user navigates away or hides the tab.
			window.addEventListener('pagehide', flush, {once: true});
			document.addEventListener('visibilitychange', function() {
				if (document.visibilityState === 'hidden') flush();
			}, {once: true});
		})();
		<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
		<?php
	}

	private function classify_ua( $ua ) {
		$ua = strtolower( $ua );
		if ( strpos( $ua, 'bot' ) !== false || strpos( $ua, 'spider' ) !== false ) return 'bot';
		if ( strpos( $ua, 'mobile' ) !== false || strpos( $ua, 'android' ) !== false || strpos( $ua, 'iphone' ) !== false ) return 'mobile';
		if ( strpos( $ua, 'tablet' ) !== false || strpos( $ua, 'ipad' ) !== false ) return 'tablet';
		return 'desktop';
	}

	private function classify_referrer( $ref ) {
		if ( empty( $ref ) ) return 'direct';
		$host = wp_parse_url( $ref, PHP_URL_HOST );
		$my_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host === $my_host ) return 'internal';
		if ( preg_match( '/(google|bing|yahoo|duckduckgo)\./i', $host ) ) return 'search';
		if ( preg_match( '/(facebook|twitter|t\.co|linkedin|instagram|pinterest)\./i', $host ) ) return 'social';
		return 'other';
	}

    /**
     * Purges all logs from the database.
     */
    public function purge_logs() {
        global $wpdb;
		$db = NEXENG_Database::get_instance();
        $wpdb->query( "TRUNCATE TABLE {$db->hits}" );
        $wpdb->query( "TRUNCATE TABLE {$db->hits_daily}" );
        $wpdb->query( "TRUNCATE TABLE {$db->vitals}" );
    }

	/**
	 * The connecting IP, used only as salt for the stored hash.
	 *
	 * This used to prefer HTTP_CLIENT_IP and HTTP_X_FORWARDED_FOR over
	 * REMOTE_ADDR. Both are request headers, so on a direct connection the
	 * client sets them itself: a visitor could send any address they liked and
	 * appear as a different person on every request, which makes the hit table
	 * trivially poisonable. Only REMOTE_ADDR, set by the web server, is used,
	 * and it is validated as an IP before use.
	 */
	private function get_ip() {
		$ip = NEXENG_Request::ip();
		return '' !== $ip ? $ip : '0.0.0.0';
	}
}
