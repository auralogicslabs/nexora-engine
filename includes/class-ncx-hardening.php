<?php
/**
 * Nexora Engine — Security Hardening (free guards)
 *
 * All protection is PHP-only — works identically on Apache, Nginx, LiteSpeed,
 * and any other server without requiring .htaccess changes.
 *
 * This file holds the guards every install gets. There is no licence check
 * anywhere in it: everything here runs for everyone, gated only by its own
 * on/off option.
 *
 * Guard map:
 *   nexeng_secure_users_api         — Block /wp-json/wp/v2/users for guests
 *   nexeng_secure_author_enum       — Block ?author=N enumeration
 *   nexeng_secure_xmlrpc            — Disable XML-RPC
 *   nexeng_secure_login_errors      — Generic login error messages
 *   nexeng_secure_remove_version    — Strip WP version from <head> / feeds
 *
 * The Pro guards (security headers, REST tightening, login rate limiting,
 * strong passwords, login rename, file-editor lockout) live in
 * class-ncx-hardening-pro__premium_only.php, which Freemius removes from the
 * WordPress.org build. They are absent there rather than present and disabled —
 * shipping them behind a licence check is what Guideline 5 forbids.
 *
 * Removed in audit:
 *   nexeng_secure_files — could not be enforced server-side (Apache/Nginx serve
 *                      readme.html directly before PHP loads). Removed rather
 *                      than ship a false-security promise.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NEXENG_Hardening {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {

		// ── REST API — block unauthenticated user enumeration ────────────────
		add_filter( 'rest_endpoints', [ $this, 'secure_rest_endpoints' ] );

		// ── XML-RPC ──────────────────────────────────────────────────────────
		add_filter( 'xmlrpc_enabled', [ $this, 'disable_xmlrpc' ] );

		// ── Author enumeration ───────────────────────────────────────────────
		add_action( 'template_redirect', [ $this, 'block_author_enumeration' ] );

		// ── Login error masking ──────────────────────────────────────────────
		add_filter( 'login_errors', [ $this, 'mask_login_errors' ] );

		// ── WP version exposure ──────────────────────────────────────────────
		add_action( 'init', [ $this, 'remove_wp_version' ] );
	}

	// ═════════════════════════════════════════════════════════════════════════
	// REST API — block unauthenticated user enumeration
	// ═════════════════════════════════════════════════════════════════════════

	public function secure_rest_endpoints( $endpoints ) {
		if ( get_option( 'nexeng_secure_users_api' ) !== 'on' ) {
			return $endpoints;
		}
		foreach ( [ '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ] as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				foreach ( $endpoints[ $route ] as $i => $handler ) {
					// PHP 8 guard: some WordPress/plugin route entries store string
					// metadata (namespace, schema callbacks) alongside handler arrays.
					// Array-offset assignment on a string throws a TypeError that kills
					// the entire REST request → every endpoint 500s.
					if ( ! is_array( $handler ) ) {
						continue;
					}
					$endpoints[ $route ][ $i ]['permission_callback'] = static function () {
						return current_user_can( 'list_users' );
					};
				}
			}
		}
		return $endpoints;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Block author enumeration via ?author=N
	// ═════════════════════════════════════════════════════════════════════════

	public function block_author_enumeration() {
		if ( get_option( 'nexeng_secure_author_enum' ) !== 'on' ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection of a public query var; nothing is changed.
		$author = isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : '';
		if ( '' !== $author && is_numeric( $author ) ) {
			$this->trigger_404();
		}
	}

	// ═════════════════════════════════════════════════════════════════════════
	// XML-RPC disable
	// ═════════════════════════════════════════════════════════════════════════

	public function disable_xmlrpc() {
		return ( get_option( 'nexeng_secure_xmlrpc' ) === 'on' ) ? false : true;
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Generic login error messages
	// ═════════════════════════════════════════════════════════════════════════

	public function mask_login_errors( $error ) {
		if ( get_option( 'nexeng_secure_login_errors' ) !== 'on' ) {
			return $error;
		}
		return '<strong>' . __( 'Error', 'nexora-engine' ) . '</strong>: '
			. __( 'Incorrect username or password.', 'nexora-engine' );
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Remove WordPress version
	// ═════════════════════════════════════════════════════════════════════════

	public function remove_wp_version() {
		if ( get_option( 'nexeng_secure_remove_version' ) !== 'on' ) {
			return;
		}
		// Remove generator meta tag from <head>.
		remove_action( 'wp_head', 'wp_generator' );
		// Remove from RSS / Atom feeds.
		add_filter( 'the_generator', '__return_empty_string' );

		// NOTE: ?ver= is intentionally left on script/style URLs. It is the
		// cache-buster — removing it means visitors keep stale CSS/JS after every
		// plugin or theme update until they hard-refresh. Frontend correctness
		// beats a minor information-disclosure mitigation.
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Helpers
	// ═════════════════════════════════════════════════════════════════════════

	/**
	 * Return 404 and stop execution.
	 */
	private function trigger_404() {
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
		get_template_part( 404 );
		exit;
	}
}
