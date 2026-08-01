<?php
/**
 * Nexora Engine — Scorer
 *
 * Calculates module scores and overall page scores from open issue severities.
 * Exposes public helper functions nexeng_get_page_score(), nexeng_get_site_score(),
 * and nexeng_get_score_label().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Scorer {

	// Deductions per open issue by severity.
	private const DEDUCTIONS = [
		'critical' => 25,
		'high'     => 15,
		'medium'   => 8,
		'low'      => 3,
	];

	// Weight of each module in the overall page score (must sum to 100).
	private const MODULE_WEIGHTS = [
		'seo'         => 35,
		'performance' => 25,
		'security'    => 20,
		'indexing'    => 20,
	];

	// ─── Module Score ─────────────────────────────────────────────────────────

	/**
	 * Calculates a module score (0-100) from a set of detected issue keys.
	 *
	 * Used by each analyser after running its checks so the score reflects
	 * exactly the issues just detected (including previously-open ones).
	 *
	 * @param string[] $detected_keys Issue keys detected in the current scan.
	 */
	public static function calculate_module_score(
		int $blog_id,
		?int $post_id,
		array $detected_keys
	): int {
		if ( empty( $detected_keys ) ) {
			return 100;
		}

		$db      = NEXENG_Database::get_instance();
		$issues  = $db->get_issues( $blog_id, $post_id, [ 'status' => 'open' ] );

		// Index open issues by key for quick lookup.
		$open_by_key = [];
		foreach ( $issues as $issue ) {
			$open_by_key[ $issue['issue_key'] ] = $issue['severity'];
		}

		$score = 100;

		foreach ( $detected_keys as $key ) {
			$severity  = $open_by_key[ $key ] ?? null;
			if ( null !== $severity && isset( self::DEDUCTIONS[ $severity ] ) ) {
				$score -= self::DEDUCTIONS[ $severity ];
			}
		}

		return max( 0, $score );
	}

	// ─── Overall Page Score ───────────────────────────────────────────────────

	/**
	 * Calculates and persists the overall page score from the four module scores.
	 * Fetches module scores from the scan_results table (most recent per module).
	 */
	public static function calculate_page_score( int $post_id ): int {
		$blog_id = get_current_blog_id();
		$db      = NEXENG_Database::get_instance();

		$module_scores = [];

		foreach ( array_keys( self::MODULE_WEIGHTS ) as $module ) {
			$result = $db->get_scan_result( $blog_id, $post_id, $module );
			$module_scores[ $module ] = $result ? (int) $result['score'] : 0;
		}

		$overall = self::compute_weighted( $module_scores );

		// Persist to nexeng_page_scores.
		$db->upsert_page_score( $blog_id, $post_id, [
			'overall_score'     => $overall,
			'seo_score'         => $module_scores['seo'],
			'performance_score' => $module_scores['performance'],
			'security_score'    => $module_scores['security'],
			'indexing_score'    => $module_scores['indexing'],
		] );

		return $overall;
	}

	/**
	 * Runs all four analysers for a post and returns the final overall score.
	 * Convenience method used by the "Run Full Scan" action.
	 */
	public static function run_full_scan( int $post_id, bool $force = true ): int {
		// NEXENG_SEO used to be listed here, but it is the SEO *output* engine
		// (sitemap, schema, OG) — a singleton with a private constructor and no
		// analyse() method. `new NEXENG_SEO()` therefore fataled on every call,
		// which broke "Run Full Scan", the CLI scan and the change tracker.
		//
		// Each analyser is also guarded: the set that ships can differ between
		// builds, and a missing one must lower the score, not fatal the request.
		foreach ( [ 'NEXENG_Performance', 'NEXENG_Security', 'NEXENG_Indexing' ] as $analyser ) {
			if ( class_exists( $analyser ) && method_exists( $analyser, 'analyse' ) ) {
				( new $analyser() )->analyse( $post_id, $force );
			}
		}

		return self::calculate_page_score( $post_id );
	}

	// ─── Site Score ───────────────────────────────────────────────────────────

	/**
	 * Returns the average overall score across all published pages for a blog.
	 */
	public static function get_site_score( int $blog_id ): int {
		return NEXENG_Database::get_instance()->get_site_average_score( $blog_id );
	}

	// ─── Score Label ──────────────────────────────────────────────────────────

	/**
	 * Returns a human-readable label for a score.
	 *
	 * @return 'excellent'|'good'|'needs work'|'critical'
	 */
	public static function get_score_label( int $score ): string {
		if ( $score >= 85 ) {
			return 'excellent';
		}
		if ( $score >= 65 ) {
			return 'good';
		}
		if ( $score >= 40 ) {
			return 'needs work';
		}
		return 'critical';
	}

	/**
	 * Returns a translated display label for a score.
	 */
	public static function get_score_label_i18n( int $score ): string {
		$map = [
			'excellent'  => __( 'Excellent', 'nexora-engine' ),
			'good'       => __( 'Good', 'nexora-engine' ),
			'needs work' => __( 'Needs Work', 'nexora-engine' ),
			'critical'   => __( 'Critical', 'nexora-engine' ),
		];

		return $map[ self::get_score_label( $score ) ];
	}

	/**
	 * Returns a CSS class suffix for a score label (for badge colouring).
	 */
	public static function get_score_class( int $score ): string {
		$map = [
			'excellent'  => 'green',
			'good'       => 'blue',
			'needs work' => 'yellow',
			'critical'   => 'red',
		];

		return $map[ self::get_score_label( $score ) ] ?? 'grey';
	}

	// ─── Internal Helpers ─────────────────────────────────────────────────────

	/**
	 * @param array<string, int> $module_scores Map of module => score.
	 */
	private static function compute_weighted( array $module_scores ): int {
		$total  = 0;
		$weight = 0;

		foreach ( self::MODULE_WEIGHTS as $module => $w ) {
			if ( isset( $module_scores[ $module ] ) ) {
				$total  += $module_scores[ $module ] * $w;
				$weight += $w;
			}
		}

		if ( 0 === $weight ) {
			return 0;
		}

		return (int) round( $total / $weight );
	}
}

// ─── Global Helper Functions ──────────────────────────────────────────────────

/**
 * Returns the overall score for a post (0-100).
 */
function nexeng_get_page_score( int $post_id ): int {
	$row = NEXENG_Database::get_instance()->get_page_score( get_current_blog_id(), $post_id );
	return $row ? (int) $row['overall_score'] : 0;
}

/**
 * Returns the average site score across all published pages (0-100).
 */
function nexeng_get_site_score(): int {
	return NEXENG_Scorer::get_site_score( get_current_blog_id() );
}

/**
 * Returns a human-readable label for a score.
 *
 * @return 'excellent'|'good'|'needs work'|'critical'
 */
function nexeng_get_score_label( int $score ): string {
	return NEXENG_Scorer::get_score_label( $score );
}
