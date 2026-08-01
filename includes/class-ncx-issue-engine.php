<?php
/**
 * Nexora Engine — Issue Engine
 *
 * Central issue registry. All analyser modules register issues here.
 * No module writes to the nexeng_issues table directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Issue_Engine {

	private static ?NEXENG_Issue_Engine $instance = null;

	private NEXENG_Database $db;

	private function __construct() {
		$this->db = NEXENG_Database::get_instance();
	}

	public static function get_instance(): NEXENG_Issue_Engine {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ─── Register / Resolve ───────────────────────────────────────────────────

	/**
	 * Registers (upserts) an issue. Deduplicates on blog_id + post_id + issue_key.
	 *
	 * @param array{title: string, severity: string, explanation: string, fix: string} $data
	 */
	public function register_issue(
		int $blog_id,
		?int $post_id,
		string $issue_key,
		array $data
	): void {
		$this->db->insert_issue( $blog_id, $post_id, $issue_key, $data );
	}

	/**
	 * Marks an issue as resolved and records the resolved timestamp.
	 */
	public function resolve_issue( int $blog_id, ?int $post_id, string $issue_key ): void {
		$this->db->resolve_issue( $blog_id, $post_id, $issue_key );
	}

	/**
	 * Marks an issue as ignored so it is excluded from alerts and digests.
	 */
	public function ignore_issue( int $blog_id, ?int $post_id, string $issue_key ): void {
		$this->db->ignore_issue( $blog_id, $post_id, $issue_key );
	}

	// ─── Queries ──────────────────────────────────────────────────────────────

	/**
	 * Returns issues for a specific post.
	 *
	 * @param array{severity?: string, status?: string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function get_issues( int $blog_id, ?int $post_id, array $filters = [] ): array {
		return $this->db->get_issues( $blog_id, $post_id, $filters );
	}

	/**
	 * Returns all open issues across a blog.
	 *
	 * @param array{severity?: string, status?: string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function get_site_issues( int $blog_id, array $filters = [] ): array {
		return $this->db->get_site_issues( $blog_id, $filters );
	}

	/**
	 * Returns open issue counts grouped by severity for a post.
	 *
	 * @return array{critical: int, high: int, medium: int, low: int}
	 */
	public function count_by_severity( int $blog_id, ?int $post_id ): array {
		return $this->db->count_by_severity( $blog_id, $post_id );
	}

	/**
	 * Returns open issue counts grouped by severity across the whole blog.
	 *
	 * @return array{critical: int, high: int, medium: int, low: int}
	 */
	public function count_site_by_severity( int $blog_id ): array {
		return $this->db->count_by_severity( $blog_id, null );
	}

	// ─── Bulk Helpers ─────────────────────────────────────────────────────────

	/**
	 * Resolves all open issues for a post that are no longer detected.
	 * Called after a fresh scan to auto-close fixed issues.
	 *
	 * @param string[] $still_open_keys Issue keys still detected in latest scan.
	 */
	public function auto_resolve_cleared( int $blog_id, int $post_id, array $still_open_keys ): void {
		$open_issues = $this->db->get_issues( $blog_id, $post_id, [ 'status' => 'open' ] );

		foreach ( $open_issues as $issue ) {
			if ( ! in_array( $issue['issue_key'], $still_open_keys, true ) ) {
				$this->db->resolve_issue( $blog_id, $post_id, $issue['issue_key'] );
			}
		}
	}

	// ─── Severity Helpers ─────────────────────────────────────────────────────

	/**
	 * Returns a CSS class for a severity level for use in admin views.
	 */
	public static function severity_class( string $severity ): string {
		$map = [
			'critical' => 'ncx-severity ncx-severity--critical',
			'high'     => 'ncx-severity ncx-severity--high',
			'medium'   => 'ncx-severity ncx-severity--medium',
			'low'      => 'ncx-severity ncx-severity--low',
		];
		return $map[ $severity ] ?? 'ncx-severity ncx-severity--low';
	}

	/**
	 * Returns a translated severity label.
	 */
	public static function severity_label( string $severity ): string {
		$map = [
			'critical' => __( 'Critical', 'nexora-engine' ),
			'high'     => __( 'High', 'nexora-engine' ),
			'medium'   => __( 'Medium', 'nexora-engine' ),
			'low'      => __( 'Low', 'nexora-engine' ),
		];
		return $map[ $severity ] ?? ucfirst( $severity );
	}
}
