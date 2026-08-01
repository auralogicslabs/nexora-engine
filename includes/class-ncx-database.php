<?php
/**
 * Nexora Engine — Database
 *
 * Central data-access layer. All DB operations go through this class.
 * No other class may write $wpdb queries directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This file is the plugin's dedicated data-access layer for its OWN custom tables
// (nexeng_issues, nexeng_scan_results, nexeng_redirects, …). Table names are always built
// from the prefix-safe map in NEXENG_Database::__get() ( $wpdb->prefix . <constant> ),
// never from user input, so they cannot be passed as a %s placeholder. Every
// user-supplied value IS passed through $wpdb->prepare(). Custom plugin tables are
// not in the WP object cache and legitimately require direct queries. The following
// sniffs are therefore disabled file-wide with that justification:
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

class NEXENG_Database {

	private static ?NEXENG_Database $instance = null;

	/** @var wpdb */
	private $wpdb;

	private function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public static function get_instance(): NEXENG_Database {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─── Dynamic Table Names ──────────────────────────────────────────────────
	// Always read $wpdb->prefix at call time so multisite blog-switching works.

	public function __get( string $name ): string {
		$map = [
			'scan_results' => 'nexeng_scan_results',
			'issues'       => 'nexeng_issues',
			'page_scores'  => 'nexeng_page_scores',
			'settings'     => 'nexeng_settings',
			'redirects'    => 'nexeng_redirects',
			'scan_history' => 'nexeng_scan_history',
			'hits'         => 'nexeng_hits',
			'hits_daily'   => 'nexeng_hits_daily',
			'vitals'       => 'nexeng_vitals',
		];

		if ( isset( $map[ $name ] ) ) {
			return $this->wpdb->prefix . $map[ $name ];
		}

		return '';
	}

	// ─── Scan Results ─────────────────────────────────────────────────────────

	public function insert_scan_result(
		int $blog_id,
		int $post_id,
		string $scan_type,
		array $result_data,
		int $score
	): bool {
		$result = $this->wpdb->replace(
			$this->scan_results,
			[
				'blog_id'     => $blog_id,
				'post_id'     => $post_id,
				'scan_type'   => sanitize_key( $scan_type ),
				'result_data' => wp_json_encode( $result_data ),
				'score'       => min( 100, max( 0, $score ) ),
				'scanned_at'  => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s' ]
		);

		return false !== $result;
	}

	public function get_scan_result( int $blog_id, int $post_id, string $scan_type ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scan_results} WHERE blog_id = %d AND post_id = %d AND scan_type = %s ORDER BY scanned_at DESC LIMIT 1",
				$blog_id,
				$post_id,
				$scan_type
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['result_data'] = json_decode( $row['result_data'], true ) ?? [];

		return $row;
	}

	// ─── Issues ───────────────────────────────────────────────────────────────

	/**
	 * Upsert an issue record. Deduplicates on (blog_id, post_id, issue_key).
	 *
	 * @param array{title: string, severity: string, explanation: string, fix: string} $data
	 */
	public function insert_issue( int $blog_id, ?int $post_id, string $issue_key, array $data ): bool {
		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->issues} WHERE blog_id = %d AND post_id <=> %s AND issue_key = %s",
				$blog_id,
				$post_id,
				$issue_key
			)
		);

		if ( $existing ) {
			$result = $this->wpdb->update(
				$this->issues,
				[
					'title'       => sanitize_text_field( $data['title'] ?? '' ),
					'severity'    => $this->sanitize_severity( $data['severity'] ?? 'low' ),
					'explanation' => wp_kses_post( $data['explanation'] ?? '' ),
					'fix'         => wp_kses_post( $data['fix'] ?? '' ),
					'status'      => 'open',
					'resolved_at' => null,
				],
				[
					'blog_id'   => $blog_id,
					'post_id'   => $post_id,
					'issue_key' => $issue_key,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%s' ],
				[ '%d', '%s', '%s' ]
			);
		} else {
			$result = $this->wpdb->insert(
				$this->issues,
				[
					'blog_id'     => $blog_id,
					'post_id'     => $post_id,
					'issue_key'   => sanitize_key( $issue_key ),
					'title'       => sanitize_text_field( $data['title'] ?? '' ),
					'severity'    => $this->sanitize_severity( $data['severity'] ?? 'low' ),
					'explanation' => wp_kses_post( $data['explanation'] ?? '' ),
					'fix'         => wp_kses_post( $data['fix'] ?? '' ),
					'status'      => 'open',
					'detected_at' => current_time( 'mysql' ),
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		return false !== $result;
	}

	public function resolve_issue( int $blog_id, ?int $post_id, string $issue_key ): bool {
		$result = $this->wpdb->update(
			$this->issues,
			[
				'status'      => 'resolved',
				'resolved_at' => current_time( 'mysql' ),
			],
			[
				'blog_id'   => $blog_id,
				'post_id'   => $post_id,
				'issue_key' => $issue_key,
			],
			[ '%s', '%s' ],
			[ '%d', '%s', '%s' ]
		);

		return false !== $result;
	}

	public function ignore_issue( int $blog_id, ?int $post_id, string $issue_key ): bool {
		$result = $this->wpdb->update(
			$this->issues,
			[ 'status' => 'ignored' ],
			[
				'blog_id'   => $blog_id,
				'post_id'   => $post_id,
				'issue_key' => $issue_key,
			],
			[ '%s' ],
			[ '%d', '%s', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Returns issues for a post. Optional filters: severity, status.
	 *
	 * @param array{severity?: string, status?: string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function get_issues( int $blog_id, ?int $post_id, array $filters = [] ): array {
		$where  = 'WHERE blog_id = %d AND post_id <=> %s';
		$params = [ $blog_id, $post_id ];

		if ( ! empty( $filters['severity'] ) ) {
			$where   .= ' AND severity = %s';
			$params[] = $this->sanitize_severity( $filters['severity'] );
		}

		if ( ! empty( $filters['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key( $filters['status'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->issues} {$where} ORDER BY FIELD(severity,'critical','high','medium','low'), detected_at DESC",
				...$params
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Returns all open issues for a blog (site-level).
	 *
	 * @param array{severity?: string, status?: string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function get_site_issues( int $blog_id, array $filters = [] ): array {
		$where  = 'WHERE blog_id = %d';
		$params = [ $blog_id ];

		if ( ! empty( $filters['severity'] ) ) {
			$where   .= ' AND severity = %s';
			$params[] = $this->sanitize_severity( $filters['severity'] );
		}

		$status = $filters['status'] ?? 'open';
		$where   .= ' AND status = %s';
		$params[] = sanitize_key( $status );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->issues} {$where} ORDER BY FIELD(severity,'critical','high','medium','low'), detected_at DESC",
				...$params
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Returns issue count grouped by severity for a post.
	 *
	 * @return array{critical: int, high: int, medium: int, low: int}
	 */
	public function count_by_severity( int $blog_id, ?int $post_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT severity, COUNT(*) AS cnt FROM {$this->issues} WHERE blog_id = %d AND post_id <=> %s AND status = 'open' GROUP BY severity",
				$blog_id,
				$post_id
			),
			ARRAY_A
		);

		$counts = [ 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 ];
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['severity'] ] ) ) {
				$counts[ $row['severity'] ] = (int) $row['cnt'];
			}
		}

		return $counts;
	}

	// ─── Page Scores ──────────────────────────────────────────────────────────

	/**
	 * Upsert page score record.
	 *
	 * @param array{overall_score?: int, seo_score?: int, performance_score?: int, security_score?: int, indexing_score?: int, headless_ready?: int, hybrid_required?: int} $scores
	 */
	public function upsert_page_score( int $blog_id, int $post_id, array $scores ): bool {
		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->page_scores} WHERE blog_id = %d AND post_id = %d",
				$blog_id,
				$post_id
			)
		);

		$sanitized = [
			'overall_score'     => min( 100, max( 0, (int) ( $scores['overall_score'] ?? 0 ) ) ),
			'seo_score'         => min( 100, max( 0, (int) ( $scores['seo_score'] ?? 0 ) ) ),
			'performance_score' => min( 100, max( 0, (int) ( $scores['performance_score'] ?? 0 ) ) ),
			'security_score'    => min( 100, max( 0, (int) ( $scores['security_score'] ?? 0 ) ) ),
			'indexing_score'    => min( 100, max( 0, (int) ( $scores['indexing_score'] ?? 0 ) ) ),
			'headless_ready'    => (int) ! empty( $scores['headless_ready'] ),
			'hybrid_required'   => (int) ! empty( $scores['hybrid_required'] ),
		];

		if ( $existing ) {
			$result = $this->wpdb->update(
				$this->page_scores,
				$sanitized,
				[ 'blog_id' => $blog_id, 'post_id' => $post_id ],
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%d' ],
				[ '%d', '%d' ]
			);
		} else {
			$result = $this->wpdb->insert(
				$this->page_scores,
				array_merge( $sanitized, [ 'blog_id' => $blog_id, 'post_id' => $post_id ] ),
				[ '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' ]
			);
		}

		return false !== $result;
	}

	public function get_page_score( int $blog_id, int $post_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->page_scores} WHERE blog_id = %d AND post_id = %d",
				$blog_id,
				$post_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function get_site_average_score( int $blog_id ): int {
		$avg = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT ROUND(AVG(overall_score)) FROM {$this->page_scores} WHERE blog_id = %d",
				$blog_id
			)
		);

		return (int) ( $avg ?? 0 );
	}

	// ─── Settings ─────────────────────────────────────────────────────────────

	public function get_setting( int $blog_id, string $key, mixed $default = null ): mixed {
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT setting_value FROM {$this->settings} WHERE blog_id = %d AND setting_key = %s",
				$blog_id,
				$key
			)
		);

		if ( null === $value ) {
			return $default;
		}

		// Attempt JSON decode for complex values; return scalar otherwise.
		$decoded = json_decode( $value, true );

		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) )
			? $decoded
			: $value;
	}

	public function update_setting( int $blog_id, string $key, mixed $value ): bool {
		$serialized = is_array( $value ) || is_object( $value )
			? wp_json_encode( $value )
			: (string) $value;

		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->settings} WHERE blog_id = %d AND setting_key = %s",
				$blog_id,
				$key
			)
		);

		if ( $existing ) {
			$result = $this->wpdb->update(
				$this->settings,
				[ 'setting_value' => $serialized ],
				[ 'blog_id' => $blog_id, 'setting_key' => $key ],
				[ '%s' ],
				[ '%d', '%s' ]
			);
		} else {
			$result = $this->wpdb->insert(
				$this->settings,
				[
					'blog_id'       => $blog_id,
					'setting_key'   => sanitize_key( $key ),
					'setting_value' => $serialized,
				],
				[ '%d', '%s', '%s' ]
			);
		}

		return false !== $result;
	}

	// ─── Posts ────────────────────────────────────────────────────────────────

	/**
	 * Returns all published post IDs for the given post types.
	 *
	 * @param string[] $post_types
	 * @return int[]
	 */
	public function get_all_published_post_ids( int $blog_id, array $post_types = [ 'post', 'page' ] ): array {
		// Build placeholders: one %s per post type.
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		$blog_prefix = $this->wpdb->get_blog_prefix( $blog_id );
		$posts_table = $blog_prefix . 'posts';

		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT ID FROM {$posts_table} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
				...$post_types
			)
		);

		return array_map( 'intval', $ids ?: [] );
	}

	// ─── Redirects (PRO) ─────────────────────────────────────────────────────

	/**
	 * Ensures the redirects table exists and has the current schema.
	 * Uses dbDelta so it also adds missing columns on existing tables (e.g. after
	 * a schema update). A static flag prevents repeat calls within the same request.
	 */
	private function ensure_redirect_table(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		// Fast path: schema already confirmed current — skip the expensive SHOW CREATE TABLE.
		// NEXENG_Activator::maybe_create_tables (admin_init) and activate_single_site both
		// write nexeng_db_version after a successful dbDelta run.
		$db_version = class_exists( 'NEXENG_Activator' ) ? NEXENG_Activator::DB_VERSION : null;
		if ( $db_version && get_option( 'nexeng_db_version' ) === $db_version ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $this->wpdb->get_charset_collate();
		$table   = $this->redirects;
		dbDelta( "CREATE TABLE {$table} (
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
		) {$charset};" );
	}

	public function get_redirect( string $source_url, int $blog_id ): ?array {
		$this->ensure_redirect_table();
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->redirects} WHERE blog_id = %d AND source_url = %s AND is_active = 1 LIMIT 1",
				$blog_id,
				$source_url
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function insert_redirect( int $blog_id, string $source, string $target, int $type = 301, int $is_active = 1, string $notes = '' ): bool {
		$this->ensure_redirect_table();
		$result = $this->wpdb->replace(
			$this->redirects,
			[
				'blog_id'       => $blog_id,
				'source_url'    => esc_url_raw( $source ),
				'target_url'    => esc_url_raw( $target ),
				'redirect_type' => in_array( $type, [ 301, 302 ], true ) ? $type : 301,
				'is_active'     => $is_active ? 1 : 0,
				'notes'         => sanitize_text_field( $notes ),
				'hit_count'     => 0,
				'created_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ]
		);
		return false !== $result;
	}

	public function toggle_redirect( int $id, int $blog_id, bool $is_active ): bool {
		$this->ensure_redirect_table();
		$result = $this->wpdb->update(
			$this->redirects,
			[ 'is_active' => $is_active ? 1 : 0 ],
			[ 'id' => $id, 'blog_id' => $blog_id ],
			[ '%d' ],
			[ '%d', '%d' ]
		);
		return false !== $result;
	}

	public function delete_redirect( int $id, int $blog_id ): bool {
		$this->ensure_redirect_table();
		$result = $this->wpdb->delete(
			$this->redirects,
			[ 'id' => $id, 'blog_id' => $blog_id ],
			[ '%d', '%d' ]
		);
		return false !== $result;
	}

	public function increment_redirect_hit( int $id ): void {
		$this->ensure_redirect_table();
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->redirects} SET hit_count = hit_count + 1 WHERE id = %d",
				$id
			)
		);
	}

	/**
	 * Returns stats for the redirect dashboard: total, active, total hits, top rule.
	 */
	public function get_redirect_stats( int $blog_id ): array {
		$this->ensure_redirect_table();
		$total  = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->redirects} WHERE blog_id = %d", $blog_id ) );
		$active = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->redirects} WHERE blog_id = %d AND is_active = 1", $blog_id ) );
		$hits   = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COALESCE(SUM(hit_count),0) FROM {$this->redirects} WHERE blog_id = %d", $blog_id ) );
		$top    = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT source_url, hit_count FROM {$this->redirects} WHERE blog_id = %d ORDER BY hit_count DESC LIMIT 1",
			$blog_id
		), ARRAY_A );
		return [ 'total' => $total, 'active' => $active, 'hits' => $hits, 'top' => $top ];
	}

	/**
	 * Returns all redirects for a blog, paginated.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_redirects( int $blog_id, int $per_page = 20, int $offset = 0 ): array {
		$this->ensure_redirect_table();
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->redirects} WHERE blog_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$blog_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		return $rows ?: [];
	}

	// ─── Scan History (PRO) ───────────────────────────────────────────────────

	public function save_snapshot( int $blog_id, int $post_id, array $snapshot_data, int $site_score ): bool {
		$result = $this->wpdb->insert(
			$this->scan_history,
			[
				'blog_id'       => $blog_id,
				'post_id'       => $post_id,
				'snapshot_data' => wp_json_encode( $snapshot_data ),
				'site_score'    => min( 100, max( 0, $site_score ) ),
				'created_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%d', '%s' ]
		);

		return false !== $result;
	}

	public function get_scan_history( int $blog_id, int $post_id, int $limit = 10 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, blog_id, post_id, site_score, created_at FROM {$this->scan_history} WHERE blog_id = %d AND post_id = %d ORDER BY created_at DESC LIMIT %d",
				$blog_id,
				$post_id,
				$limit
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	public function get_snapshot( int $snapshot_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->scan_history} WHERE id = %d",
				$snapshot_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['snapshot_data'] = json_decode( $row['snapshot_data'], true ) ?? [];

		return $row;
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	private function sanitize_severity( string $severity ): string {
		$allowed = [ 'low', 'medium', 'high', 'critical' ];
		return in_array( $severity, $allowed, true ) ? $severity : 'low';
	}
}
