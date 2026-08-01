<?php
/**
 * Nexora Engine — Security Checker
 *
 * Detects common WordPress security risks via HTTP HEAD requests
 * and WordPress API checks.
 * Accounts for 20% of overall page score.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Security {

	private NEXENG_Cache        $cache;
	private NEXENG_Issue_Engine $issues;

	// HTTP request timeout for all security probes.
	private const PROBE_TIMEOUT = 10;

	public function __construct() {
		$this->cache  = NEXENG_Cache::get_instance();
		$this->issues = NEXENG_Issue_Engine::get_instance();
	}

	// ─── Public API ───────────────────────────────────────────────────────────

	/**
	 * Runs all security checks for the site (site-level, not per-post).
	 * Returns the security score (0-100).
	 *
	 * Pass $post_id = 0 to run site-level checks only.
	 * Pass a real $post_id to attach issues to that post.
	 */
	public function analyse( int $post_id = 0, bool $force = false ): int {
		$blog_id   = get_current_blog_id();
		$cache_key = NEXENG_Cache::make_key( 'security', $blog_id, $post_id );

		if ( ! $force ) {
			$cached = $this->cache->get( $cache_key );
			if ( false !== $cached && is_int( $cached ) ) {
				return $cached;
			}
		}

		// Security checks are site-level — use NULL post_id for DB storage.
		$db_post_id    = $post_id > 0 ? $post_id : null;
		$detected_keys = $this->run_checks( $blog_id, $db_post_id );

		$this->issues->auto_resolve_cleared( $blog_id, $db_post_id, $detected_keys );

		$score = NEXENG_Scorer::calculate_module_score( $blog_id, $db_post_id, $detected_keys );

		NEXENG_Database::get_instance()->insert_scan_result(
			$blog_id,
			$post_id,
			'security',
			[ 'checked_keys' => $detected_keys ],
			$score
		);

		$this->cache->set( $cache_key, $score, NEXENG_CACHE_SECURITY );

		return $score;
	}

	// ─── Checks ───────────────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function run_checks( int $blog_id, ?int $post_id ): array {
		$detected = [];

		$detected = array_merge( $detected, $this->check_debug_log( $blog_id, $post_id ) );
		$detected = array_merge( $detected, $this->check_xmlrpc( $blog_id, $post_id ) );
		$detected = array_merge( $detected, $this->check_user_enumeration( $blog_id, $post_id ) );
		$detected = array_merge( $detected, $this->check_exposed_files( $blog_id, $post_id ) );
		$detected = array_merge( $detected, $this->check_login_url( $blog_id, $post_id ) );

		return $detected;
	}

	// ─── Debug Log Exposed ────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_debug_log( int $blog_id, ?int $post_id ): array {
		$detected = [];
		$url      = trailingslashit( content_url() ) . 'debug.log';
		$response = $this->probe( $url );

		if ( 200 === $response ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_debug_log_exposed', [
				'title'       => __( 'debug.log File Publicly Accessible', 'nexora-engine' ),
				'severity'    => 'critical',
				'explanation' => __( 'The WordPress debug log (wp-content/debug.log) is publicly accessible. This file can expose database credentials, file paths, plugin names, and error details that attackers use to craft targeted exploits.', 'nexora-engine' ),
				'fix'         => __( 'Block access to debug.log via your .htaccess (Apache) or nginx config. Add: "deny from all" inside a Files directive for debug.log. Alternatively, move the log file outside the web root by setting WP_DEBUG_LOG to an absolute path in wp-config.php.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_debug_log_exposed';
		}

		return $detected;
	}

	// ─── XML-RPC ──────────────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_xmlrpc( int $blog_id, ?int $post_id ): array {
		$detected = [];
		$url      = trailingslashit( get_home_url() ) . 'xmlrpc.php';
		$response = $this->probe( $url );

		// xmlrpc.php returns 405 (Method Not Allowed) for HEAD but is still accessible.
		// A 200 or 405 both indicate it is reachable.
		if ( in_array( $response, [ 200, 405 ], true ) ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_xmlrpc_enabled', [
				'title'       => __( 'XML-RPC Enabled and Accessible', 'nexora-engine' ),
				'severity'    => 'high',
				'explanation' => __( 'The WordPress XML-RPC endpoint (xmlrpc.php) is publicly accessible. XML-RPC is an outdated remote publishing protocol that is commonly exploited for brute-force password attacks and DDoS amplification (pingback abuse).', 'nexora-engine' ),
				'fix'         => __( 'Disable XML-RPC unless you explicitly need it for a remote publishing tool. Use a plugin like "Disable XML-RPC" or add the following to your .htaccess: <Files xmlrpc.php> deny from all </Files>', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_xmlrpc_enabled';
		}

		return $detected;
	}

	// ─── User Enumeration ─────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_user_enumeration( int $blog_id, ?int $post_id ): array {
		$detected = [];
		$url      = trailingslashit( get_home_url() ) . 'wp-json/wp/v2/users';

		$response = wp_remote_get( $url, [
			'timeout'    => self::PROBE_TIMEOUT,
			'user-agent' => 'NexoraEngine/' . NEXENG_VERSION . ' SecurityScanner',
		] );

		if ( is_wp_error( $response ) ) {
			return $detected;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$users = is_array( $body ) ? $body : [];

			// Only flag if actual user data (slugs/names) are returned.
			if ( ! empty( $users ) && isset( $users[0]['slug'] ) ) {
				$this->issues->register_issue( $blog_id, $post_id, 'nexeng_user_enum_exposed', [
					'title'       => __( 'User Enumeration via REST API', 'nexora-engine' ),
					'severity'    => 'high',
					'explanation' => __( 'The WordPress REST API /wp/v2/users endpoint returns user data (usernames, display names) without authentication. Attackers use this to harvest valid usernames for brute-force login attempts.', 'nexora-engine' ),
					'fix'         => __( 'Restrict the users endpoint to authenticated requests only. Add this to functions.php: add_filter("rest_endpoints", function($e){ if(isset($e["/wp/v2/users"])) { $e["/wp/v2/users"][0]["permission_callback"] = function(){ return current_user_can("list_users"); }; } return $e; }); Or use a security plugin like Wordfence to block the endpoint.', 'nexora-engine' ),
				] );
				$detected[] = 'nexeng_user_enum_exposed';
			}
		}

		return $detected;
	}

	// ─── Exposed Files ────────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_exposed_files( int $blog_id, ?int $post_id ): array {
		$detected        = [];
		$home            = trailingslashit( get_home_url() );
		$exposed_targets = [
			'readme.html'   => 'readme.html',
			'license.txt'   => 'license.txt',
		];
		$found_files     = [];

		foreach ( $exposed_targets as $key => $file ) {
			$response = $this->probe( $home . $file );
			if ( 200 === $response ) {
				$found_files[] = $file;
			}
		}

		if ( ! empty( $found_files ) ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_readme_exposed', [
				'title'       => __( 'WordPress Version Files Publicly Accessible', 'nexora-engine' ),
				'severity'    => 'medium',
				/* translators: %s: comma-separated list of exposed filenames */
				'explanation' => sprintf(
					/* translators: %1 / %2 etc.: counts and value(s) inserted into the message. */
					__( 'The following files are publicly accessible: %s. These files reveal the WordPress version number, which attackers use to identify known vulnerabilities for that version.', 'nexora-engine' ),
					implode( ', ', $found_files )
				),
				'fix'         => __( 'Block access to these files via .htaccess or nginx config, or delete them. In .htaccess add: <FilesMatch "^(readme\.html|license\.txt)$"> deny from all </FilesMatch>', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_readme_exposed';
		}

		return $detected;
	}

	// ─── Login URL Default ────────────────────────────────────────────────────

	/**
	 * @return string[]
	 */
	private function check_login_url( int $blog_id, ?int $post_id ): array {
		$detected = [];
		$url      = trailingslashit( get_home_url() ) . 'wp-login.php';
		$response = $this->probe( $url );

		// wp-login.php returns 200 whether the user is logged in or not.
		if ( 200 === $response ) {
			$this->issues->register_issue( $blog_id, $post_id, 'nexeng_login_url_default', [
				'title'       => __( 'Default WordPress Login URL Accessible', 'nexora-engine' ),
				'severity'    => 'medium',
				'explanation' => __( 'The default WordPress login page (wp-login.php) is publicly accessible. Automated bots continually target this URL for credential stuffing and brute-force attacks.', 'nexora-engine' ),
				'fix'         => __( 'Change the login URL to a custom path using a plugin like WPS Hide Login or Perfmatters. Also enable login attempt limiting (e.g. Limit Login Attempts Reloaded) and two-factor authentication to further harden the login page.', 'nexora-engine' ),
			] );
			$detected[] = 'nexeng_login_url_default';
		}

		return $detected;
	}

	// ─── HTTP Probe Helper ────────────────────────────────────────────────────

	/**
	 * Makes a HEAD request and returns the HTTP status code.
	 * Returns 0 on error or timeout.
	 */
	private function probe( string $url ): int {
		$response = wp_remote_head( $url, [
			'timeout'    => self::PROBE_TIMEOUT,
			'redirection' => 0,
			'user-agent' => 'NexoraEngine/' . NEXENG_VERSION . ' SecurityScanner',
		] );

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}
}
