<?php
/**
 * Nexora Engine — Stealth Audit
 *
 * Probes the public site the way a fingerprinting tool (Wappalyzer, BuiltWith,
 * WhatCMS) or a corporate vulnerability scanner does, and reports exactly which
 * WordPress signals are EXPOSED vs HIDDEN. Produces a 0–100 "Stealth Score" plus
 * a per-check breakdown.
 *
 * This is the measurable, demoable proof behind Ghost Protocol — it turns
 * "we hide WordPress" into a number the user can screenshot, and powers the
 * before/after trophy report.
 *
 * Read-only: every check is a GET/HEAD against the site's own public URLs.
 * Nothing is written. Results are cached briefly so repeated dashboard polls
 * don't hammer the server.
 *
 * @package NexoraEngine
 */

// Global namespace — matches the other includes/class-ncx-*.php legacy classes
// (NEXENG_SSG, NEXENG_CDN, …) which the bootstrap requires explicitly.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Stealth_Audit {

	/** Cache key for the last full audit. */
	const CACHE_KEY = 'nexeng_stealth_audit';

	/** How long a full audit is cached (seconds). */
	const CACHE_TTL = 300;

	/**
	 * Run (or return cached) the full stealth audit.
	 *
	 * @param bool $fresh Force a fresh probe, bypassing the cache.
	 * @return array {
	 *   score:int, grade:string, exposed:int, hidden:int, total:int,
	 *   checks: array<int,array{id:string,label:string,hidden:bool,detail:string,weight:int,severity:string}>,
	 *   verdict:string, generated:int
	 * }
	 */
	public static function run( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['score'] ) ) {
				return $cached;
			}
		}

		$home = home_url( '/' );
		$page = self::fetch( $home );
		$body = $page['body'];
		$hdrs = $page['headers'];

		$checks = array();

		// ── 1. X-Powered-By header doesn't reveal PHP ──────────────────────────
		$xpb = strtolower( (string) ( $hdrs['x-powered-by'] ?? '' ) );
		$checks[] = self::check(
			'header_xpoweredby',
			__( 'PHP version hidden from headers', 'nexora-engine' ),
			'' === $xpb || false === strpos( $xpb, 'php' ),
			$xpb ? sprintf( 'X-Powered-By: %s', esc_html( $hdrs['x-powered-by'] ) ) : __( 'No X-Powered-By header', 'nexora-engine' ),
			15, 'high'
		);

		// ── 2. X-Pingback header absent (a dead giveaway of WordPress) ─────────
		$checks[] = self::check(
			'header_pingback',
			__( 'X-Pingback header removed', 'nexora-engine' ),
			empty( $hdrs['x-pingback'] ),
			empty( $hdrs['x-pingback'] ) ? __( 'Not present', 'nexora-engine' ) : __( 'X-Pingback exposes xmlrpc.php', 'nexora-engine' ),
			10, 'high'
		);

		// ── 3. Generator meta tag stripped ────────────────────────────────────
		$has_generator = (bool) preg_match( '/<meta[^>]+name=["\']generator["\'][^>]*wordpress/i', $body );
		$checks[] = self::check(
			'meta_generator',
			__( 'Generator meta tag stripped', 'nexora-engine' ),
			! $has_generator,
			$has_generator ? __( '<meta name="generator" content="WordPress …"> present', 'nexora-engine' ) : __( 'No WordPress generator tag', 'nexora-engine' ),
			15, 'high'
		);

		// ── 4. No /wp-content/ paths in the HTML source ───────────────────────
		$has_wpcontent = false !== stripos( $body, '/wp-content/' );
		$checks[] = self::check(
			'paths_wpcontent',
			__( 'wp-content paths masked', 'nexora-engine' ),
			! $has_wpcontent,
			$has_wpcontent ? __( '/wp-content/ visible in asset URLs', 'nexora-engine' ) : __( 'No /wp-content/ in source', 'nexora-engine' ),
			15, 'high', true
		);

		// ── 5. No /wp-includes/ paths in the HTML source ──────────────────────
		$has_wpincludes = false !== stripos( $body, '/wp-includes/' );
		$checks[] = self::check(
			'paths_wpincludes',
			__( 'wp-includes paths masked', 'nexora-engine' ),
			! $has_wpincludes,
			$has_wpincludes ? __( '/wp-includes/ visible in source', 'nexora-engine' ) : __( 'No /wp-includes/ in source', 'nexora-engine' ),
			10, 'medium', true
		);

		// ── 6. REST API discovery link removed from <head> ────────────────────
		$has_restlink = (bool) preg_match( '/<link[^>]+rel=["\']https:\/\/api\.w\.org\//i', $body )
			|| false !== stripos( $body, '/wp-json/' );
		$checks[] = self::check(
			'rest_discovery',
			__( 'REST API (wp-json) discovery hidden', 'nexora-engine' ),
			! $has_restlink,
			$has_restlink ? __( 'wp-json discovery link/path exposed', 'nexora-engine' ) : __( 'No REST discovery in source', 'nexora-engine' ),
			10, 'medium', true
		);

		// ── 7. RSD / wlwmanifest links removed ────────────────────────────────
		$has_rsd = (bool) preg_match( '/<link[^>]+rel=["\'](EditURI|wlwmanifest)["\']/i', $body );
		$checks[] = self::check(
			'meta_rsd',
			__( 'RSD / wlwmanifest links removed', 'nexora-engine' ),
			! $has_rsd,
			$has_rsd ? __( 'XML-RPC editor discovery links present', 'nexora-engine' ) : __( 'No RSD/wlwmanifest links', 'nexora-engine' ),
			5, 'low'
		);

		// ── 8. No ?ver= WordPress/asset version strings ───────────────────────
		$has_ver = (bool) preg_match( '/\?ver=\d/i', $body );
		$checks[] = self::check(
			'asset_ver',
			__( 'Asset ?ver= version strings stripped', 'nexora-engine' ),
			! $has_ver,
			$has_ver ? __( '?ver= query strings leak plugin/WP versions', 'nexora-engine' ) : __( 'No ?ver= version strings', 'nexora-engine' ),
			5, 'low', true
		);

		// ── 9. xmlrpc.php not openly accepting requests ───────────────────────
		$xmlrpc = self::fetch( home_url( '/xmlrpc.php' ), 'GET' );
		$xmlrpc_open = false !== stripos( $xmlrpc['body'], 'XML-RPC server accepts POST requests only' );
		$checks[] = self::check(
			'xmlrpc',
			__( 'XML-RPC endpoint not openly advertised', 'nexora-engine' ),
			! $xmlrpc_open,
			$xmlrpc_open ? __( 'xmlrpc.php responds with the standard WP banner', 'nexora-engine' ) : __( 'xmlrpc.php disabled or not advertising', 'nexora-engine' ),
			5, 'medium'
		);

		// ── 10. /?author=1 doesn't redirect to /author/<username>/ ────────────
		$author = self::fetch( home_url( '/?author=1' ), 'GET', false );
		$author_leaks = ! empty( $author['location'] ) && (bool) preg_match( '#/author/[^/]+#i', $author['location'] );
		$checks[] = self::check(
			'author_enum',
			__( 'Author enumeration (?author=N) blocked', 'nexora-engine' ),
			! $author_leaks,
			/* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
			$author_leaks ? sprintf( __( 'Leaks username via redirect: %s', 'nexora-engine' ), esc_html( $author['location'] ) ) : __( 'No username leak', 'nexora-engine' ),
			5, 'medium'
		);

		// ── Scoring ───────────────────────────────────────────────────────────
		$total_weight = 0;
		$earned       = 0;
		$exposed      = 0;
		foreach ( $checks as $c ) {
			$total_weight += $c['weight'];
			if ( $c['hidden'] ) {
				$earned += $c['weight'];
			} else {
				$exposed++;
			}
		}
		$score = $total_weight > 0 ? (int) round( ( $earned / $total_weight ) * 100 ) : 0;
		$hidden = count( $checks ) - $exposed;

		$result = array(
			'score'     => $score,
			'grade'     => self::grade( $score ),
			'exposed'   => $exposed,
			'hidden'    => $hidden,
			'total'     => count( $checks ),
			'checks'    => $checks,
			'verdict'   => self::verdict( $score, $exposed ),
			'generated' => time(),
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Build one check row.
	 *
	 * @param bool $pro_only When true, this signal can ONLY be masked by Pro's
	 *                       Advanced Ghost Protocol (deep source rewriting). On
	 *                       the free tier such a signal shows as exposed with a
	 *                       "Pro unlocks this" hint rather than a plain failure.
	 */
	private static function check( string $id, string $label, bool $hidden, string $detail, int $weight, string $severity, bool $pro_only = false ): array {
		return array(
			'id'       => $id,
			'label'    => $label,
			'hidden'   => $hidden,
			'detail'   => $detail,
			'weight'   => $weight,
			'severity' => $severity,
			'pro_only' => $pro_only,
		);
	}

	/**
	 * Fetch a URL via loopback and return body + lowercased headers.
	 *
	 * @param string $url            URL to fetch.
	 * @param string $method         GET or HEAD.
	 * @param bool   $follow_redirect Whether to follow redirects (for author-enum we must NOT).
	 * @return array{body:string,headers:array<string,string>,location:string,status:int}
	 */
	private static function fetch( string $url, string $method = 'GET', bool $follow_redirect = true ): array {
		$args = array(
			'timeout'     => 8,
			'sslverify'   => false,
			'redirection' => $follow_redirect ? 3 : 0,
			'method'      => $method,
			// Probe as an anonymous visitor — no admin cookies — so we see what
			// a fingerprinting bot sees, not the logged-in (live WP) view.
			'cookies'     => array(),
			'user-agent'  => 'NexoraEngine-StealthAudit/1.0',
		);
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'body' => '', 'headers' => array(), 'location' => '', 'status' => 0 );
		}
		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $res ) as $k => $v ) {
			// A repeated header arrives as an array, and Requests can nest that
			// one level deeper still. implode() only flattens the outer level, so
			// the inner array reached string conversion and emitted a PHP warning
			// on every audit run. Walk the whole structure instead.
			if ( is_array( $v ) ) {
				$parts = array();
				array_walk_recursive(
					$v,
					static function ( $item ) use ( &$parts ) {
						if ( is_scalar( $item ) ) {
							$parts[] = (string) $item;
						}
					}
				);
				$value = implode( ', ', $parts );
			} else {
				$value = is_scalar( $v ) ? (string) $v : '';
			}
			$headers[ strtolower( (string) $k ) ] = $value;
		}
		return array(
			'body'     => (string) wp_remote_retrieve_body( $res ),
			'headers'  => $headers,
			'location' => (string) wp_remote_retrieve_header( $res, 'location' ),
			'status'   => (int) wp_remote_retrieve_response_code( $res ),
		);
	}

	/** Map score → letter grade. */
	private static function grade( int $score ): string {
		if ( $score >= 95 ) return 'A+';
		if ( $score >= 85 ) return 'A';
		if ( $score >= 70 ) return 'B';
		if ( $score >= 50 ) return 'C';
		if ( $score >= 30 ) return 'D';
		return 'F';
	}

	/** One-line human verdict. */
	private static function verdict( int $score, int $exposed ): string {
		if ( $score >= 95 ) {
			return __( 'Your site is effectively invisible — fingerprinting tools cannot identify WordPress.', 'nexora-engine' );
		}
		if ( $score >= 70 ) {
			return sprintf(
				/* translators: %d = number of exposed signals */
				_n( 'Strong stealth — %d WordPress signal is still exposed.', 'Strong stealth — %d WordPress signals are still exposed.', $exposed, 'nexora-engine' ),
				$exposed
			);
		}
		if ( $score >= 30 ) {
			return __( 'WordPress is partially exposed. Enable Ghost Protocol and the hardening rules below to close the gaps.', 'nexora-engine' );
		}
		return __( 'WordPress is clearly detectable. Turn on Static Delivery + Ghost Protocol to start cloaking.', 'nexora-engine' );
	}
}
