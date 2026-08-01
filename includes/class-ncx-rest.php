<?php
/**
 * Nexora Engine — REST Controller
 *
 * Exposes Engine's existing AJAX surface as proper REST endpoints for the
 * React admin SPA. Wraps the existing classes (NEXENG_Dashboard, NEXENG_SSG,
 * NEXENG_Settings, etc.) without duplicating business logic.
 *
 * Migrated React pages talk to /wp-json/nexora-engine/v1/*. Legacy PHP views
 * keep using wp_ajax_nexeng_* — both can coexist while we migrate page-by-page.
 *
 * License gating: Pro endpoints check FeatureGate::is_plan_or_above('pro')
 * inside each handler so the React UI can render upgrade CTAs honestly.
 *
 * @package NexoraEngine
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXENG_REST {

	private const NS = 'nexora-engine/v1';

	private static ?NEXENG_REST $instance = null;

	public static function get_instance(): NEXENG_REST {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		// Ensure our REST responses are NEVER cached by a page/object cache or a
		// proxy. The admin SPA polls /ssg/state + /ssg/batch-tick for live build
		// progress; if a cache (LiteSpeed, Cloudflare, Varnish, nginx fastcgi)
		// serves a stale snapshot, the wizard freezes on an old count like
		// "0 / 0 · 0%" while the build actually advances on disk — and only
		// "unfreezes" when the user purges the cache. Sending explicit no-store +
		// LiteSpeed-specific no-cache headers for our namespace kills that.
		add_filter( 'rest_pre_serve_request', [ $this, 'send_nocache_headers' ], 10, 4 );
	}

	/**
	 * Emit no-cache headers for every Nexora Engine REST response so live build
	 * progress is never served stale from a cache layer. Scoped to our namespace
	 * only — we don't touch other plugins' or core REST routes.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool Unmodified $served.
	 */
	public function send_nocache_headers( $served, $result, $request, $server ) {
		$route = is_object( $request ) && method_exists( $request, 'get_route' )
			? (string) $request->get_route()
			: '';
		if ( strpos( $route, '/' . self::NS . '/' ) === 0 || strpos( $route, self::NS ) !== false ) {
			if ( ! headers_sent() ) {
				// Standard HTTP no-cache.
				header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0', true );
				header( 'Pragma: no-cache', true );
				header( 'Expires: 0', true );
				// LiteSpeed-specific — tells LSCache not to cache this response
				// even when the LiteSpeed Cache plugin would otherwise.
				header( 'X-LiteSpeed-Cache-Control: no-cache', true );
				// Cloudflare/edge hint.
				header( 'CDN-Cache-Control: no-store', true );
			}
			// Canonical LiteSpeed Cache plugin signal — fires its own
			// do-not-cache logic regardless of header timing.
			do_action( 'litespeed_control_set_nocache', 'nexora engine live build progress' );
		}
		return $served;
	}

	public function register_routes(): void {
		$caps = static function () {
			return current_user_can( 'manage_options' );
		};

		// ── Summary + license + admin context ──────────────────────────────
		register_rest_route( self::NS, '/summary', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_summary' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/dashboard/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_dashboard_stats' ],
			'permission_callback' => $caps,
		] );

		// ── Stealth audit — measurable Ghost Protocol score ────────────────
		register_rest_route( self::NS, '/stealth-audit', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stealth_audit' ],
			'permission_callback' => $caps,
		] );

		// ── Settings ───────────────────────────────────────────────────────
		register_rest_route( self::NS, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => $caps,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => $caps,
			],
		] );

		// ── Static Delivery (SSG) ──────────────────────────────────────────
		register_rest_route( self::NS, '/ssg/state', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_ssg_state' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/toggle', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_toggle' ],
			'permission_callback' => $caps,
			'args'                => [
				'enabled' => [ 'type' => 'boolean', 'required' => true ],
			],
		] );

		// Build control actions — these mirror the existing AJAX handlers so
		// the React Mirror Build Control panel can drive the same behavior.
		register_rest_route( self::NS, '/ssg/regen-all', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_regen_all' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/regen-pending', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_regen_pending' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/pause', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_pause' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/resume', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_resume' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/stop', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_stop' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/purge', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_purge' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/retry-errors', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_retry_errors' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/pending', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'ssg_pending_list' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/clear-pending', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_clear_pending' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/regen-one', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_regen_one' ],
			'permission_callback' => $caps,
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// ── SSG Builder — bulk batch driver, archives, preflight, nginx, mirror ──
		register_rest_route( self::NS, '/ssg/batch-tick', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_batch_tick' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/regen-archives', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_regen_archives' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/preflight', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_preflight' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/nginx-config', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'ssg_nginx_config' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/mirror', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'ssg_mirror_list' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/mirror/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'ssg_mirror_delete' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/exclude-post', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_exclude_post' ],
			'permission_callback' => $caps,
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( self::NS, '/ssg/exclusions', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'ssg_exclusions_get' ],
				'permission_callback' => $caps,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'ssg_exclusions_save' ],
				'permission_callback' => $caps,
			],
		] );

		register_rest_route( self::NS, '/ssg/asset-mode', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'ssg_set_asset_mode' ],
			'permission_callback' => $caps,
			'args'                => [
				'mode'              => [ 'type' => 'string', 'required' => true ],
				'purge_confirmed'   => [ 'type' => 'boolean', 'required' => true ],
			],
		] );

		// ── Setup Wizard ────────────────────────────────────────────────
		register_rest_route( self::NS, '/wizard/state', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'wizard_state' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/wizard/activate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'wizard_activate' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/wizard/disable-conflict', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'wizard_disable_conflict' ],
			'permission_callback' => $caps,
			'args'                => [
				'slug' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NS, '/wizard/check-diag', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'wizard_check_diag' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/wizard/diag-json', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'wizard_diag_json' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/wizard/finish', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'wizard_finish' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/wizard/reset', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'wizard_reset' ],
			'permission_callback' => $caps,
		] );

		// ── Onboarding (for React shell) ──────────────────────────────────
		register_rest_route( self::NS, '/onboarding/complete', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'complete_onboarding' ],
			'permission_callback' => $caps,
		] );

		// ── Redirects (PRO) ───────────────────────────────────────────────
		register_rest_route( self::NS, '/redirects', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_redirects' ],
				'permission_callback' => $caps,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_redirect' ],
				'permission_callback' => $caps,
			],
		] );

		register_rest_route( self::NS, '/redirects/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_redirect' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/redirects/(?P<id>\d+)/toggle', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'toggle_redirect' ],
			'permission_callback' => $caps,
			'args'                => [
				'is_active' => [ 'type' => 'boolean', 'required' => true ],
			],
		] );

		register_rest_route( self::NS, '/redirects/export', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'export_redirects' ],
			'permission_callback' => $caps,
		] );

		// ── Tools / Maintenance ──────────────────────────────────────────
		register_rest_route( self::NS, '/tools/status', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'tools_status' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/tools/flush-permalinks', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tools_flush_permalinks' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/tools/purge-analytics', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tools_purge_analytics' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/tools/export-settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'tools_export_settings' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/tools/license-clear-cache', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tools_license_clear_cache' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/tools/license-reset-sandbox', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tools_license_reset_sandbox' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/tools/factory-reset', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tools_factory_reset' ],
			'permission_callback' => $caps,
			'args'                => [
				'confirm' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// Live runtime introspection — used by the rail's "kick queue" debug
		// affordance to figure out why auto-rebuild may be stalled. Returns
		// the actual values of every gate (license, option, scheduled events,
		// transient locks) so we can see exactly where the pipeline is stuck.
		register_rest_route( self::NS, '/tools/ssg-debug', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'tools_ssg_debug' ],
			'permission_callback' => $caps,
		] );

		// Forcefully run wp_cron() in-process now. Bypasses every loopback
		// path so it works on LocalWP / low-FPM hosts where wp_remote_post
		// to wp-cron.php would normally time out.
		register_rest_route( self::NS, '/tools/run-cron-now', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'tools_run_cron_now' ],
			'permission_callback' => $caps,
		] );

		// ── Addons registry ─────────────────────────────────────────────
		register_rest_route( self::NS, '/addons', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_addons' ],
			'permission_callback' => $caps,
		] );

		// ── License info ────────────────────────────────────────────────
		register_rest_route( self::NS, '/license', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_license' ],
			'permission_callback' => $caps,
		] );

		// ── Validate group ──────────────────────────────────────────────
		register_rest_route( self::NS, '/seo-report', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_seo_report' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/ssg/pages', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_ssg_pages' ],
			'permission_callback' => $caps,
		] );

		// ── Auralogics Portal ───────────────────────────────────────────
		register_rest_route( self::NS, '/portal', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_portal' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/portal/connect', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'portal_connect' ],
			'permission_callback' => $caps,
			'args'                => [
				'key' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( self::NS, '/portal/disconnect', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'portal_disconnect' ],
			'permission_callback' => $caps,
		] );

		register_rest_route( self::NS, '/portal/regenerate-token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'portal_regenerate_token' ],
			'permission_callback' => $caps,
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Handler implementations — thin wrappers around existing classes
	// ──────────────────────────────────────────────────────────────────────

	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		$plan      = $this->resolve_plan();
		$is_pro    = $this->is_pro();
		$ssg_state = $this->ssg_state_payload();

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'plan'             => $plan,
				'is_pro'           => $is_pro,
				'install_id'       => (string) get_option( 'nexeng_install_id', '' ),
				'onboarding_complete' => $this->wizard_complete(),
				'engine_version'   => defined( 'NEXORA_ENGINE_VERSION' ) ? NEXORA_ENGINE_VERSION : '',
				'ssg'              => $ssg_state,
				'wizard_complete'  => $this->wizard_complete(),
				'upgrade_url'      => $this->upgrade_url(),
			],
		] );
	}

	/**
	 * Stealth audit — probes the public site and scores how well WordPress is
	 * hidden (the measurable side of Ghost Protocol). Pass ?fresh=1 to bypass
	 * the short-lived cache (e.g. right after toggling a setting).
	 */
	public function get_stealth_audit( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_Stealth_Audit' ) ) {
			return rest_ensure_response( [ 'success' => false, 'message' => 'Stealth audit unavailable.' ] );
		}
		$fresh = (bool) $request->get_param( 'fresh' );
		return rest_ensure_response( [
			'success' => true,
			'data'    => \NEXENG_Stealth_Audit::run( $fresh ),
		] );
	}

	public function get_dashboard_stats( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_Dashboard' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [] ] );
		}

		// Dashboard stats are derived from up to 7 days of hit logs and
		// involve a full table scan + several percentile computations. The
		// React dashboard polls every 12 s when idle and 2 s during a build;
		// regenerating fresh stats on every poll is wasted PHP work and the
		// numbers don't move that fast. A 15 s transient gives us a near-live
		// feel without hammering the DB.
		//
		// When a build is running we want a fresher signal — so callers can
		// opt out of the cache by passing ?fresh=1, which the React layer
		// sets during the build window.
		$fresh   = (bool) $request->get_param( 'fresh' );
		$cache_k = 'nexeng_dashboard_stats_v1';
		if ( ! $fresh ) {
			$cached = get_transient( $cache_k );
			if ( is_array( $cached ) ) {
				$cached['_cached'] = true;
				return rest_ensure_response( [ 'success' => true, 'data' => $cached ] );
			}
		}

		$stats = NEXENG_Dashboard::get_instance()->get_stats();
		set_transient( $cache_k, $stats, 15 );
		return rest_ensure_response( [ 'success' => true, 'data' => $stats ] );
	}

	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$keys = $this->settings_key_map();
		$out  = [];
		foreach ( $keys as $key => $default ) {
			$value = get_option( $key, $default );
			$out[ $key ] = is_bool( $default ) ? ( 'on' === $value || true === $value || '1' === (string) $value ) : $value;
		}
		return rest_ensure_response( [ 'success' => true, 'data' => $out ] );
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}

		// Settings that, when changed, require the static mirror to be
		// invalidated so the new policy actually shows up to visitors. Without
		// this, toggling WP Masking would have no visible effect until the
		// next manual rebuild — which would look broken to the user.
		$invalidates_mirror = [ 'nexeng_headless_mode' ];

		// Snapshot the existing values for keys that trigger a silent rebuild,
		// so we can compare before/after and only invalidate when an actual
		// change happened.
		$pre = [];
		foreach ( $invalidates_mirror as $k ) {
			$pre[ $k ] = (string) get_option( $k, '' );
		}

		$keys = $this->settings_key_map();
		foreach ( $body as $key => $value ) {
			if ( ! array_key_exists( $key, $keys ) ) {
				continue;
			}
			$default = $keys[ $key ];
			if ( is_bool( $default ) ) {
				update_option( $key, $value ? 'on' : 'off' );
			} elseif ( is_int( $default ) ) {
				update_option( $key, (int) $value );
			} else {
				update_option( $key, sanitize_text_field( (string) $value ) );
			}
		}

		// Silent mirror refresh when a delivery-shaping setting changed.
		// invalidate_site_wide() marks every captured post as pending without
		// purging — visitors keep getting the existing (slightly stale)
		// mirror while the queue rebuilds. No flash of PHP fallback for
		// high-traffic pages. Pro auto-rebuild drains the queue
		// automatically; on Free, the queue waits for the user's next
		// manual rebuild (the queue stays visible in the rail so they know
		// what changed and why).
		foreach ( $invalidates_mirror as $k ) {
			$post_val = (string) get_option( $k, '' );
			if ( $pre[ $k ] !== $post_val && class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled() ) {
				$ssg = NEXENG_SSG::get_instance();
				if ( method_exists( $ssg, 'invalidate_site_wide' ) ) {
					$ssg->invalidate_site_wide(
						'headless_mode_change',
						$k === 'nexeng_headless_mode'
							? ( $post_val === 'on' ? 'WP Masking enabled' : 'WP Masking disabled' )
							: 'Delivery settings changed'
					);
				}
			}
		}

		return $this->get_settings( $request );
	}

	public function get_ssg_state( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [ 'success' => true, 'data' => $this->ssg_state_payload() ] );
	}

	public function ssg_toggle( WP_REST_Request $request ): WP_REST_Response {
		$enabled = (bool) $request->get_param( 'enabled' );
		update_option( 'nexeng_ssg_enabled', $enabled ? 'on' : 'off' );

		// Mirror admin.php behavior — install/remove dropin if present.
		if ( $enabled && class_exists( 'NEXENG_Dropin' ) && method_exists( 'NEXENG_Dropin', 'install' ) ) {
			NEXENG_Dropin::install();
		}

		// The drop-in's kill switch is not set here: NEXENG_SSG::on_toggle() is
		// hooked to update_option_nexeng_ssg_enabled and handles it, so every
		// path that changes the option (REST, AJAX, WP-CLI, network admin) stays
		// consistent rather than only the two that remembered to call it.

		return rest_ensure_response( [ 'success' => true, 'data' => $this->ssg_state_payload() ] );
	}

	public function ssg_regen_all( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) || ! NEXENG_SSG::is_enabled() ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Static Delivery is disabled — enable it before starting a build.',
				'code'    => 'ssg_disabled',
			], 400 );
		}

		$ssg = NEXENG_SSG::get_instance();
		$include_archives = $request->get_param( 'include_archives' );
		$include_archives = $include_archives === null ? true : (bool) $include_archives;

		$count = $ssg->bulk_start( $include_archives );
		if ( is_wp_error( $count ) ) {
			$bulk = $ssg->bulk_status();
			return new WP_REST_Response( [
				'success' => false,
				'message' => $count->get_error_message(),
				'busy'    => ! empty( $bulk['running'] ) && empty( $bulk['done'] ),
				'data'    => $this->ssg_state_payload(),
			], 409 );
		}

		// Cron fallback — only fires 5 min later if the browser disconnects.
		// The browser-active transient keeps cron from double-processing while
		// React is actively driving the batch loop.
		if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
		}

		$status = $ssg->bulk_status();
		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge( $this->ssg_state_payload(), [
				'total'         => (int) $count,
				'breakdown'     => $status['breakdown'] ?? [],
				'build_session' => $status['build_session'] ?? '',
			] ),
		] );
	}

	public function ssg_regen_pending( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) || ! NEXENG_SSG::is_enabled() ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Static Delivery is disabled — enable it before refreshing pages.',
				'code'    => 'ssg_disabled',
			], 400 );
		}

		$ssg  = NEXENG_SSG::get_instance();
		$bulk = $ssg->bulk_status();
		if ( ! empty( $bulk['running'] ) && empty( $bulk['done'] ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'busy'    => true,
				'message' => 'A build is already running. Check Build Control for progress.',
			], 409 );
		}

		$count = (int) $ssg->bulk_start_pending();
		if ( $count === 0 ) {
			return rest_ensure_response( [
				'success' => true,
				'data'    => array_merge( $this->ssg_state_payload(), [
					'total'   => 0,
					'message' => 'No changed pages to refresh.',
				] ),
			] );
		}

		if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge( $this->ssg_state_payload(), [ 'total' => $count ] ),
		] );
	}

	public function ssg_pause( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( 'NEXENG_SSG' ) ) {
			NEXENG_SSG::get_instance()->bulk_pause();
		}
		return rest_ensure_response( [ 'success' => true, 'data' => $this->ssg_state_payload() ] );
	}

	public function ssg_resume( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( 'NEXENG_SSG' ) ) {
			NEXENG_SSG::get_instance()->bulk_resume();
		}
		return rest_ensure_response( [ 'success' => true, 'data' => $this->ssg_state_payload() ] );
	}

	public function ssg_stop( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( 'NEXENG_SSG' ) ) {
			NEXENG_SSG::get_instance()->bulk_stop();
		}
		return rest_ensure_response( [ 'success' => true, 'data' => $this->ssg_state_payload() ] );
	}

	public function ssg_purge( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( 'NEXENG_SSG' ) ) {
			NEXENG_SSG::get_instance()->purge_all();
		}
		return rest_ensure_response( [ 'success' => true, 'data' => $this->ssg_state_payload() ] );
	}

	public function ssg_retry_errors( WP_REST_Request $request ): WP_REST_Response {
		// SSG has no first-class retry method — the equivalent is "clear the
		// recent errors log and start a pending-only build", which is what the
		// legacy admin UI did. Mirror that here so the React button stops being
		// a no-op.
		delete_option( 'nexeng_ssg_errors' );

		if ( class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled() ) {
			$ssg = NEXENG_SSG::get_instance();
			$bulk = $ssg->bulk_status();
			if ( empty( $bulk['running'] ) || ! empty( $bulk['done'] ) ) {
				$ssg->bulk_start_pending();
				if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
					wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
				}
			}
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $this->ssg_state_payload() ] );
	}

	public function ssg_pending_list( WP_REST_Request $request ): WP_REST_Response {
		$ssg     = class_exists( 'NEXENG_SSG' ) ? NEXENG_SSG::get_instance() : null;
		$pending = ( $ssg && method_exists( $ssg, 'pending_posts' ) ) ? (array) $ssg->pending_posts() : [];
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'pending' => $pending ] ] );
	}

	public function ssg_clear_pending( WP_REST_Request $request ): WP_REST_Response {
		// Mirror handle_ssg_clear_all_pending — the SSG class has no public
		// "wipe the whole queue" method because clearing is a multi-transient
		// halt sequence, not a single op.
		$pending = (array) get_option( 'nexeng_ssg_pending_posts', [] );
		$count   = count( $pending );

		update_option( 'nexeng_ssg_pending_posts', [], false );

		// Cancel any cron that would refill the queue.
		wp_clear_scheduled_hook( 'nexeng_ssg_global_invalidate' );
		wp_clear_scheduled_hook( 'nexeng_ssg_bulk_tick' );
		wp_clear_scheduled_hook( 'nexeng_ssg_bulk_watchdog' );
		wp_clear_scheduled_hook( 'nexeng_ssg_regen' );

		// Wipe every bulk-run transient so an in-progress build halts cleanly.
		foreach ( [
			'nexeng_ssg_bulk_queue', 'nexeng_ssg_bulk_total', 'nexeng_ssg_bulk_done',
			'nexeng_ssg_bulk_errors', 'nexeng_ssg_bulk_running', 'nexeng_ssg_bulk_mode',
			'nexeng_ssg_bulk_breakdown', 'nexeng_ssg_bulk_attempts', 'nexeng_ssg_bulk_last_url',
			'nexeng_ssg_bulk_paused', 'nexeng_ssg_browser_active',
		] as $key ) {
			delete_transient( $key );
		}

		// Suppress the virtual archive item for 24h — see the legacy handler
		// for the rationale (user consciously dismissed the queue).
		delete_option( 'nexeng_ssg_archives_dirty' );
		set_transient( 'nexeng_ssg_archives_dismissed', 1, DAY_IN_SECONDS );

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge( $this->ssg_state_payload(), [
				'cleared' => $count,
				'message' => $count > 0
					? sprintf( '%d pending page%s cleared.', $count, $count === 1 ? '' : 's' )
					: 'Queue was already empty.',
			] ),
		] );
	}

	public function ssg_regen_one( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) || ! NEXENG_SSG::is_enabled() ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Static Delivery is disabled — enable it before regenerating pages.',
				'code'    => 'ssg_disabled',
			], 400 );
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing post_id' ], 400 );
		}

		$ssg = NEXENG_SSG::get_instance();

		// Explicit retry — clear any stored fatal FIRST, before the busy/bulk
		// guards below. Those guards queue the page for the regen cron, and
		// cron_regen() silently drops fatal-flagged pages — so clearing only on
		// the direct-capture path (as before) meant a blocked page's ↻ click
		// did nothing whenever the system happened to be busy: "queued" toast,
		// then the cron discarded it. The user clicked regen on purpose; the
		// fatal flag must not survive that intent on ANY path.
		if ( method_exists( $ssg, 'clear_fatal' ) ) {
			$ssg->clear_fatal( $post_id );
		}

		// Cron-dispatch busy guard — queue instead of trying a live loopback
		// while a recent save is still being processed. Avoids worker
		// starvation on low-FPM hosts (LocalWP / shared hosting).
		if ( get_transient( 'nexeng_ssg_cron_busy' ) ) {
			if ( method_exists( $ssg, 'mark_pending' ) ) $ssg->mark_pending( $post_id, 'manual' );
			if ( method_exists( $ssg, 'schedule_regen' ) ) $ssg->schedule_regen( $post_id );
			return rest_ensure_response( [
				'success' => true,
				'data'    => [
					'message' => 'A recent save is being processed — page queued and will refresh in a few seconds.',
					'queued'  => true,
					'url'     => get_permalink( $post_id ),
				],
			] );
		}

		// If a bulk run is active, prepend this post to the front of the queue
		// rather than fighting it for workers — same trick as the legacy handler.
		if ( get_transient( 'nexeng_ssg_bulk_running' ) ) {
			$queue = (array) get_transient( 'nexeng_ssg_bulk_queue' );
			$queue = array_values( array_filter( $queue, static function ( $item ) use ( $post_id ) {
				return is_array( $item ) || (int) $item !== $post_id;
			} ) );
			array_unshift( $queue, $post_id );
			set_transient( 'nexeng_ssg_bulk_queue', $queue, 4 * HOUR_IN_SECONDS );
			if ( method_exists( $ssg, 'mark_pending' ) ) $ssg->mark_pending( $post_id, 'priority' );
			return rest_ensure_response( [
				'success' => true,
				'data'    => [
					'message' => 'Build is running — page moved to the front of the queue. It will be captured in the next batch (usually within seconds).',
					'queued'  => true,
					'url'     => get_permalink( $post_id ),
				],
			] );
		}

		if ( ! $ssg->acquire_capture_lock() ) {
			if ( method_exists( $ssg, 'mark_pending' ) )   $ssg->mark_pending( $post_id, 'manual' );
			if ( method_exists( $ssg, 'schedule_regen' ) ) $ssg->schedule_regen( $post_id );
			return rest_ensure_response( [
				'success' => true,
				'data'    => [
					'message' => 'A build is already running. This page is queued and will refresh shortly.',
					'queued'  => true,
					'url'     => get_permalink( $post_id ),
				],
			] );
		}

		try {
			$result = $ssg->capture( $post_id );
			if ( is_wp_error( $result ) ) {
				$err_code = $result->get_error_code();
				$err_msg  = $result->get_error_message();
				if ( $err_code === 'nexeng_ssg_source_fatal' && stripos( $err_msg, 'PHP memory' ) !== false ) {
					return new WP_REST_Response( [
						'success' => false,
						'code'    => 'memory_limit',
						'message' => $err_msg . " To fix: add define('WP_MEMORY_LIMIT', '512M'); to wp-config.php, or ask your host to raise the PHP memory limit above 256MB.",
					], 500 );
				}
				return new WP_REST_Response( [ 'success' => false, 'code' => $err_code, 'message' => $err_msg ], 500 );
			}
		} finally {
			$ssg->release_capture_lock();
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'message' => 'Page regenerated. Static mirror is now up to date.',
				'queued'  => false,
				'entry'   => method_exists( $ssg, 'manifest_entry' ) ? $ssg->manifest_entry( $post_id ) : null,
				'url'     => get_permalink( $post_id ),
			],
		] );
	}

	public function complete_onboarding( WP_REST_Request $request ): WP_REST_Response {
		update_user_meta( get_current_user_id(), 'nexeng_onboarding_complete', 1 );
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'completed' => true ] ] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Setup Wizard — REST surface around NEXENG_Wizard. The React wizard reads
	// /wizard/state on mount and calls /wizard/activate when the user clicks
	// the "Start" button on Step 2. Each step is purely client-side; only
	// activate/finish actually mutate WordPress state.
	// ──────────────────────────────────────────────────────────────────────

	public function wizard_state( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_Wizard' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'available' => false ] ] );
		}
		$wizard      = NEXENG_Wizard::get_instance();
		$completed   = (bool) $wizard->is_completed();
		$preflight   = $completed ? [] : (array) $wizard->get_preflight_data();
		$server      = (array) $wizard->get_server_info();
		$conflicts   = $completed ? [] : (array) $wizard->get_active_conflicts();
		$is_pro      = $this->is_pro();
		$upgrade_url = $this->upgrade_url();

		$has_blocking = false;
		foreach ( $conflicts as $c ) {
			if ( ( $c['slug'] ?? '' ) === 'foreign-dropin' ) {
				$has_blocking = true;
				break;
			}
		}

		// Decorate conflicts with auto-fix capability so the UI knows when to
		// offer the "Resolve" button.
		foreach ( $conflicts as &$c ) {
			$c['auto_fix'] = (bool) $wizard->conflict_can_auto_fix( $c );
		}
		unset( $c );

		// Snapshot the current engine state so the completed-wizard screen
		// can show "what's on" without a second round-trip.
		$ssg_on       = class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled();
		$ghost_on     = get_option( 'nexeng_headless_mode', 'off' ) === 'on';
		// Report the EFFECTIVE auto-rebuild state, which depends on whether the
		// module is installed rather than on the licence. class-ncx-ssg-auto is
		// stripped from the WordPress.org build, so there the filter has no
		// subscriber and stays false no matter what the option says. Asking the
		// filter means this answer cannot drift from the code that acts on it.
		$auto_rebuild = class_exists( 'NEXENG_SSG_Auto' )
			&& (bool) apply_filters( 'nexeng_auto_rebuild_active', false );
		$ssg_stats    = ( class_exists( 'NEXENG_SSG' ) ) ? (array) NEXENG_SSG::get_instance()->stats() : [];
		$archive_st   = ( class_exists( 'NEXENG_SSG' ) && method_exists( NEXENG_SSG::get_instance(), 'archive_manifest_status' ) )
			? (array) NEXENG_SSG::get_instance()->archive_manifest_status()
			: [];

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'available'         => true,
				'completed'         => $completed,
				'is_pro'            => $is_pro,
				'is_network'        => is_multisite(),
				'upgrade_url'       => $upgrade_url,
				'preflight'         => $preflight,
				'server'            => $server,
				'conflicts'         => $conflicts,
				'has_blocking'      => $has_blocking,
				'engine'            => [
					'ssg_on'              => $ssg_on,
					'ghost_on'            => $ghost_on,
					'auto_rebuild'        => $auto_rebuild,
					'static_files'        => (int) ( $ssg_stats['total_files'] ?? 0 ),
					'archives_captured'   => (int) ( $archive_st['captured'] ?? 0 ),
					'archives_eligible'   => (int) ( $archive_st['eligible'] ?? 0 ),
				],
				'dashboard_url'     => admin_url( 'admin.php?page=nexora' ),
				'headless_url'      => admin_url( 'admin.php?page=ncx-headless' ),
				'settings_url'      => admin_url( 'admin.php?page=ncx-settings' ),
			],
		] );
	}

	public function wizard_activate( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) || ! class_exists( 'NEXENG_Dropin' ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Engine classes not available.' ], 500 );
		}

		// 1. Enable SSG for every plan.
		update_option( 'nexeng_ssg_enabled', 'on' );

		// 2. Ghost Protocol — Pro only.
		if ( $this->is_pro() ) {
			update_option( 'nexeng_headless_mode', 'on' );
		}

		// 3. Reset asset mode to 'direct' unless the user has explicitly chosen
		// proxy (stealth) before. Same logic as the legacy handler.
		$current_mode = (string) get_option( 'nexeng_asset_mode', 'direct' );
		if ( $current_mode !== 'proxy' ) {
			update_option( 'nexeng_asset_mode', 'direct' );
		}

		// 4. Install drop-in.
		$dropin_ok     = false;
		$dropin_result = NEXENG_Dropin::install();
		if ( ! is_wp_error( $dropin_result ) ) {
			$dropin_ok = NEXENG_Dropin::status() === 'ours';
		}

		// 5. Ensure root + serve rule (Apache/LiteSpeed only).
		$ssg = NEXENG_SSG::get_instance();
		if ( method_exists( $ssg, 'ensure_root' ) ) $ssg->ensure_root();
		$serve_result = method_exists( $ssg, 'install_serve_rule' ) ? $ssg->install_serve_rule() : false;
		$serve_ok     = ( true === $serve_result );

		if ( is_multisite() && class_exists( 'NEXENG_Multisite' ) ) {
			NEXENG_Multisite::rebuild_network_map();
		}

		// 5a. Re-install: purge any stale captures so the new build is clean.
		$stats = (array) $ssg->stats();
		if ( ! empty( $stats['total_files'] ) && (int) $stats['total_files'] > 0 ) {
			$ssg->purge_all();
		}

		// 6. Tier resolution — match the legacy handler exactly.
		$server_sw = strtolower( NEXENG_Request::server( 'SERVER_SOFTWARE' ) );
		$is_apache = str_contains( $server_sw, 'apache' );
		$is_ls     = str_contains( $server_sw, 'litespeed' );
		$is_nginx  = str_contains( $server_sw, 'nginx' );
		$is_iis    = str_contains( $server_sw, 'microsoft-iis' ) || str_contains( $server_sw, 'iis' );
		$is_caddy  = str_contains( $server_sw, 'caddy' );

		if ( $serve_ok && ( $is_apache || $is_ls ) ) {
			// Apache / LiteSpeed have native .htaccess serve — drop-in plus
			// rewrite rule means the static file is served before PHP boots
			// at all on cache hits.
			$tier = 1; $tier_label = 'Full Speed'; $tier_ttfb = '~15ms';
		} elseif ( $dropin_ok ) {
			// Nginx / IIS / Caddy / managed hosts — drop-in still serves
			// static HTML via the early PHP intercept, just one extra
			// PHP boot away from Tier 1.
			$tier = 2; $tier_label = 'Speed Active'; $tier_ttfb = '~45ms';
		} else {
			$tier = 3; $tier_label = 'Pages Built'; $tier_ttfb = '~80ms';
		}

		// 7. Kick off a full build so the wizard can show capture progress.
		$total = 0;
		$count = $ssg->bulk_start( true );
		if ( ! is_wp_error( $count ) ) {
			$total = (int) $count;
			if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
			}
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'tier'        => $tier,
				'tier_label'  => $tier_label,
				'tier_ttfb'   => $tier_ttfb,
				'dropin_ok'   => $dropin_ok,
				'serve_ok'    => $serve_ok,
				'is_nginx'    => $is_nginx,
				'is_apache'   => $is_apache,
				'is_ls'       => $is_ls,
				'is_iis'      => $is_iis,
				'is_caddy'    => $is_caddy,
				'total'       => $total,
				'message'     => 'Engine activated — build started.',
			],
		] );
	}

	public function wizard_disable_conflict( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_Wizard' ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Wizard unavailable.' ], 500 );
		}
		$slug = sanitize_text_field( (string) $request->get_param( 'slug' ) );
		if ( $slug === '' ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing conflict slug.' ], 400 );
		}
		$result = NEXENG_Wizard::get_instance()->disable_conflict_plugin( $slug );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 500 );
		}
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'message' => 'Conflict resolution applied. Refreshing status…' ],
		] );
	}

	public function wizard_check_diag( WP_REST_Request $request ): WP_REST_Response {
		// Delegate to the same private helper the legacy admin handler uses.
		// We re-use the cache/lock transients so a wizard re-run doesn't run
		// the diagnostic twice in the same 20-second window.
		$cache_key = 'nexeng_diag_html_' . get_current_blog_id();
		$lock_key  = 'nexeng_diag_lock_' . get_current_blog_id();
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'html' => $cached, 'cached' => true ] ] );
		}
		if ( get_transient( $lock_key ) ) {
			$busy_html = '<div class="ncx-diag-verdict-block is-warn"><span class="dashicons dashicons-update ncx-diag-verdict-icon"></span><div class="ncx-diag-verdict-text"><h4>Diagnostic already running</h4><p>A system check is already in progress. Wait a few seconds and try again.</p></div></div>';
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'html' => $busy_html, 'busy' => true ] ] );
		}

		set_transient( $lock_key, 1, 20 );
		try {
			// The diagnostic builder is a private NEXENG_Admin method. We invoke it
			// via the existing admin singleton so we don't duplicate the 200+
			// lines of HTML emission.
			$admin = class_exists( 'NEXENG_Admin' ) ? NEXENG_Admin::get_instance() : null;
			if ( $admin && method_exists( $admin, 'run_diagnostic_check' ) ) {
				// run_diagnostic_check is private — wrap via reflection so we
				// don't have to widen its visibility.
				$ref = new \ReflectionMethod( $admin, 'run_diagnostic_check' );
				$ref->setAccessible( true );
				$diag = (string) $ref->invoke( $admin );
			} else {
				$diag = '<p>Diagnostic unavailable.</p>';
			}
			set_transient( $cache_key, $diag, 20 );
		} finally {
			delete_transient( $lock_key );
		}

		return rest_ensure_response( [ 'success' => true, 'data' => [ 'html' => $diag ] ] );
	}

	/**
	 * Structured diagnostic — same probes as the legacy HTML report but
	 * returned as a JSON tree so the React DiagnosticReport component can
	 * render every section natively in the console aesthetic.
	 *
	 * Each section is a { label, status, value, hint, code } row; React maps
	 * status → neon color (ok=lime, warn=amber, err=red, info=cyan).
	 */
	public function wizard_diag_json( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) || ! class_exists( 'NEXENG_Dropin' ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Engine unavailable.' ], 500 );
		}

		$ssg            = NEXENG_SSG::get_instance();
		$home_url       = home_url( '/' );
		$upload         = wp_get_upload_dir();
		$static_root    = trailingslashit( $upload['basedir'] ) . 'nexora-static';
		$static_index   = $static_root . '/index.html';
		$abspath        = ABSPATH;
		$htaccess_path  = trailingslashit( $abspath ) . '.htaccess';

		$ssg_enabled     = NEXENG_SSG::is_enabled();
		$headless_on     = get_option( 'nexeng_headless_mode', 'off' ) === 'on';
		$rule_installed  = method_exists( $ssg, 'serve_rule_installed' ) ? $ssg->serve_rule_installed() : false;
		$dropin_status   = NEXENG_Dropin::status();
		$wp_cache_on     = method_exists( 'NEXENG_Dropin', 'wp_cache_active' ) ? NEXENG_Dropin::wp_cache_active() : false;
		$dropin_conflict = method_exists( 'NEXENG_Dropin', 'detect_conflict' ) ? NEXENG_Dropin::detect_conflict() : '';
		$server_software = NEXENG_Request::server( 'SERVER_SOFTWARE', '(unknown)' );
		$is_nginx        = stripos( $server_software, 'nginx' ) !== false;
		$is_apache       = stripos( $server_software, 'apache' ) !== false;
		$is_litespeed    = stripos( $server_software, 'litespeed' ) !== false;

		// Anonymous loopback probe — checks that visitors actually hit fast path.
		//
		// Why 6s timeout: LocalWP defaults PHP-FPM to 2 workers. When the user
		// triggers this diagnostic from wp-admin, one worker is already held
		// by the admin request itself. The loopback needs a second worker —
		// and on a busy or low-worker host (LocalWP, shared hosting) the
		// second worker can take a few seconds to free up. 3s wasn't enough.
		// IMPORTANT: must use GET, not HEAD.
		// The dropin only serves cached files for GET requests — it
		// returns early on any other method (HEAD, POST, etc). If we
		// send HEAD the probe falls through to full WordPress PHP,
		// which takes 2-3 s and never shows X-Nexora-Cache: HIT.
		// GET exercises the exact same code path real visitors take.
		// We discard the body immediately so bandwidth cost is minimal.
		$t0    = microtime( true );
		$probe = wp_remote_get( $home_url, [
			'timeout'    => 6,
			'sslverify'  => false,
			'redirection'=> 1,
			'cookies'    => [],
		] );
		$probe_ms       = (int) ( ( microtime( true ) - $t0 ) * 1000 );
		$probe_status   = 0;
		$probe_error    = '';
		$probe_timed_out = false;
		$headers_flat   = [];

		if ( is_wp_error( $probe ) ) {
			$probe_error = (string) $probe->get_error_message();
			// cURL error 28 = operation timed out. Treat as the LocalWP /
			// low-worker host case rather than a real failure — actual
			// visitor traffic doesn't share the admin's worker pool.
			$probe_timed_out = stripos( $probe_error, 'timed out' ) !== false
				|| stripos( $probe_error, 'cURL error 28' ) !== false
				|| $probe_ms >= 5500;
		} else {
			$probe_status = (int) wp_remote_retrieve_response_code( $probe );
			$raw_hdrs     = wp_remote_retrieve_headers( $probe );

			$iter = [];
			if ( is_object( $raw_hdrs ) && method_exists( $raw_hdrs, 'getAll' ) ) {
				$iter = $raw_hdrs->getAll();
			} elseif ( is_array( $raw_hdrs ) ) {
				$iter = $raw_hdrs;
			} else {
				$iter = (array) $raw_hdrs;
			}
			foreach ( $iter as $k => $v ) {
				$key = strtolower( ltrim( (string) $k, "\0* " ) );
				$headers_flat[ $key ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		}

		$hdr_nexeng_cache    = $headers_flat['x-nexora-cache'] ?? '';
		$hdr_nextjs_cache = $headers_flat['x-nextjs-cache'] ?? '';
		$hdr_xpb          = $headers_flat['x-powered-by']   ?? '';

		$served_by_dropin =
			( $hdr_nexeng_cache !== ''    && stripos( $hdr_nexeng_cache,    'HIT'    ) !== false ) ||
			( $hdr_nextjs_cache !== '' && stripos( $hdr_nextjs_cache, 'HIT'    ) !== false ) ||
			( stripos( $hdr_xpb, 'next.js' ) !== false );

		$php_in_xpb = stripos( $hdr_xpb, 'php' ) !== false && stripos( $hdr_xpb, 'next' ) === false;
		$served_by_php = ! $served_by_dropin && $php_in_xpb;

		// Verdict — three levels of signal quality.
		if ( $probe_timed_out ) {
			// LocalWP / low-worker-host signature.
			$verdict_status = 'warn';
			$verdict_label  = 'LOOPBACK TIMEOUT';
			$dropin_ready   = $dropin_status === 'ours' && $wp_cache_on;
			$verdict_msg    = $dropin_ready
				? 'The loopback probe timed out — this usually means a low PHP-FPM worker pool (LocalWP defaults to 2 workers). The cache drop-in is installed and WP_CACHE is true, so real visitors will hit the fast path — the diagnostic just can\'t prove it from inside the admin while a worker is held by this request.'
				: 'The loopback probe timed out and the drop-in is not fully installed. Raise PHP-FPM workers or test from a real visitor URL.';
		} elseif ( $served_by_dropin ) {
			if ( $probe_ms > 1000 ) {
				// Drop-in served (headers confirm it) but TTFB was slow.
				// Classic LocalWP 2-worker pattern: dropin ran on the second
				// worker but had to wait for it to free up. Real visitors
				// never share the admin's worker — their TTFB will be ≈20ms.
				$verdict_status = 'ok';
				$verdict_label  = 'FAST PATH (slow probe)';
				$verdict_msg    = sprintf(
					'Static cache headers confirm the drop-in served this page (X-Nexora-Cache: HIT / X-Powered-By: Next.js). The %d ms probe TTFB is a LocalWP artefact — the admin request and this loopback share the same 2-worker PHP-FPM pool, so the second worker had to wait. Real visitor TTFB will be under 50 ms because they never share the pool with your admin session.',
					$probe_ms
				);
			} else {
				$verdict_status = 'ok';
				$verdict_label  = 'FAST PATH';
				$verdict_msg    = sprintf(
					'Page served from static cache in %d ms — zero PHP, zero database queries. Drop-in is healthy.',
					$probe_ms
				);
			}
		} elseif ( ! $served_by_php ) {
			$verdict_status = 'ok';
			$verdict_label  = 'FAST PATH';
			$verdict_msg    = 'Static file served by the web server before PHP ran. Zero database queries.';
		} else {
			$verdict_status = 'err';
			$verdict_label  = 'SLOW PATH';
			$verdict_msg    = 'PHP rendered every request. Neither the cache layer nor a web-server rule is active. Check that WP_CACHE is true and advanced-cache.php is installed.';
		}

		// Build section: SSG state
		$ssg_section = [
			'label' => 'Static delivery',
			'rows'  => [
				[ 'label' => 'Engine',         'status' => $ssg_enabled ? 'ok' : 'warn', 'value' => $ssg_enabled ? 'enabled' : 'disabled' ],
				[ 'label' => 'WP masking',     'status' => $headless_on ? 'ok' : 'off',  'value' => $headless_on ? 'enabled' : 'disabled' ],
				[ 'label' => 'Index captured', 'status' => file_exists( $static_index ) ? 'ok' : 'warn', 'value' => file_exists( $static_index ) ? size_format( filesize( $static_index ) ) : 'missing',
					'hint' => file_exists( $static_index ) ? gmdate( 'Y-m-d H:i:s', filemtime( $static_index ) ) . ' UTC' : null,
				],
			],
		];

		$stats = (array) $ssg->stats();
		$ssg_section['rows'][] = [
			'label'  => 'Files in mirror',
			'status' => ( $stats['total_files'] ?? 0 ) > 0 ? 'ok' : 'warn',
			'value'  => (string) ( $stats['total_files'] ?? 0 ),
			'hint'   => size_format( (int) ( $stats['total_bytes'] ?? 0 ) ) . ' total',
		];

		// Surface the most recent build error so a "0 files" diagnostic explains
		// WHY the build captured nothing instead of leaving the user (and us)
		// guessing. Reads the engine's own error log (nexeng_ssg_errors).
		$build_errors = (array) get_option( 'nexeng_ssg_errors', [] );
		if ( ! empty( $build_errors ) ) {
			$last = $build_errors[0];
			$code = (string) ( $last['code'] ?? '' );
			$msg  = (string) ( $last['message'] ?? '' );
			$ssg_section['rows'][] = [
				'label'  => 'Last build error',
				'status' => 'err',
				'value'  => $code ?: 'error',
				'hint'   => trim( $msg . ( isset( $last['url'] ) ? '  (' . $last['url'] . ')' : '' ) ) ?: null,
			];
		} elseif ( ( $stats['total_files'] ?? 0 ) === 0 ) {
			// Zero files AND zero logged errors = everything was silently skipped
			// or the queue never ran. Flag it explicitly.
			$ssg_section['rows'][] = [
				'label'  => 'Last build error',
				'status' => 'warn',
				'value'  => 'none logged',
				'hint'   => '0 files but no capture errors recorded — queue may have drained without capturing (eligibility) or never ran.',
			];
		}

		// Build section: drop-in
		$dropin_section = [
			'label' => 'Cache drop-in',
			'rows'  => [
				[
					'label'  => 'advanced-cache.php',
					'status' => $dropin_status === 'ours' ? 'ok' : ( $dropin_status === 'foreign' ? 'err' : 'warn' ),
					'value'  => $dropin_status === 'ours' ? 'installed' : ( $dropin_status === 'foreign' ? 'owned by ' . ( $dropin_conflict ?: 'another plugin' ) : 'missing' ),
				],
				[
					'label'  => 'WP_CACHE constant',
					'status' => $wp_cache_on ? 'ok' : 'warn',
					'value'  => $wp_cache_on ? 'true' : 'false',
					'hint'   => $wp_cache_on ? null : 'Add define(\'WP_CACHE\', true) to wp-config.php',
				],
			],
		];

		// Build section: server
		// The label for the serve-rule row depends on the server software —
		// "Apache rule" is misleading on nginx, where the .htaccess file is
		// silently ignored. On nginx we point users at the snippet generator.
		$serve_rule_label = $is_nginx ? 'Nginx rule' : 'Server rule';
		$serve_rule_value = $rule_installed
			? '.htaccess installed'
			: ( $is_nginx
				? 'served via drop-in (Tier 2)'
				: 'not installed' );
		$serve_rule_hint = $is_nginx
			? 'For Tier-1 serving open Tools → "Nginx config" and paste the snippet into your server block.'
			: null;
		$server_section = [
			'label' => 'Server',
			'rows'  => [
				[ 'label' => 'Software',         'status' => 'info', 'value' => $server_software, 'code' => true ],
				[
					'label'  => $serve_rule_label,
					'status' => $rule_installed ? 'ok' : ( $is_apache || $is_litespeed ? 'warn' : 'info' ),
					'value'  => $serve_rule_value,
					'hint'   => $serve_rule_hint,
				],
				[ 'label' => 'DOCUMENT_ROOT',    'status' => 'info', 'value' => NEXENG_Request::server( 'DOCUMENT_ROOT', '(unknown)' ), 'code' => true ],
			],
		];

		// Build section: probe
		if ( $probe_timed_out ) {
			// When the loopback couldn't complete, the rest of the headers
			// are meaningless. Show the timeout explicitly with an
			// actionable hint rather than emit "0 / (empty) / (absent)" rows
			// that look like the engine is broken when it isn't.
			$probe_section = [
				'label' => 'Live loopback probe',
				'rows'  => [
					[
						'label'  => 'Probe result',
						'status' => 'warn',
						'value'  => 'Loopback timeout',
						'hint'   => 'PHP-FPM didn\'t free a worker for the test request in time. Common on LocalWP (2 workers by default).',
					],
					[
						'label' => 'TTFB',
						'status' => 'warn',
						'value' => $probe_ms . ' ms',
						'hint'  => 'This is the probe timeout limit, not real visitor TTFB.',
					],
					[
						'label'  => 'What to do',
						'status' => 'info',
						'value'  => 'Test from a real browser',
						'hint'   => 'Open the site in an incognito tab — the response headers there will show X-Nexora-Cache and X-Powered-By: Next.js if the drop-in is serving.',
					],
				],
			];
		} else {
			// TTFB status: if the dropin clearly served (HIT headers) but TTFB
			// was high, mark it as "info" (not "err") and add an explanatory
			// hint. Otherwise rate it normally.
			$dropin_hit     = $served_by_dropin;
			$ttfb_status    = $dropin_hit && $probe_ms > 1000
				? 'info'
				: ( $probe_ms < 100 ? 'ok' : ( $probe_ms < 500 ? 'warn' : 'err' ) );
			$ttfb_hint      = $dropin_hit && $probe_ms > 1000
				? 'Dropin served (see HIT headers) — high TTFB is the LocalWP shared-worker artefact. Real visitors: ≈20 ms.'
				: null;

			$probe_section = [
				'label' => 'Live loopback probe',
				'rows'  => [
					[ 'label' => 'HTTP status',    'status' => $probe_status === 200 ? 'ok' : 'err', 'value' => (string) $probe_status ],
					[ 'label' => 'TTFB',           'status' => $ttfb_status, 'value' => $probe_ms . ' ms', 'hint' => $ttfb_hint ],
					[ 'label' => 'X-Powered-By',   'status' => stripos( $hdr_xpb, 'next' ) !== false ? 'ok' : ( $php_in_xpb ? 'warn' : 'info' ), 'value' => $hdr_xpb !== '' ? $hdr_xpb : '(empty)', 'code' => true ],
					[ 'label' => 'X-Nexora-Cache', 'status' => stripos( $hdr_nexeng_cache, 'HIT' ) !== false ? 'ok' : 'info', 'value' => $hdr_nexeng_cache !== '' ? $hdr_nexeng_cache : '(absent)', 'code' => true ],
					[ 'label' => 'X-Nextjs-Cache', 'status' => stripos( $hdr_nextjs_cache, 'HIT' ) !== false ? 'ok' : 'info', 'value' => $hdr_nextjs_cache !== '' ? $hdr_nextjs_cache : '(absent)', 'code' => true ],
				],
			];
		}

		// Pages with warnings — surface up to 20 rows
		$warning_pages = [];
		if ( method_exists( $ssg, 'list_status' ) ) {
			foreach ( $ssg->list_status( 50 ) as $row ) {
				if ( ! empty( $row['warnings'] ) ) {
					$warning_pages[] = [
						'id'        => (int) $row['id'],
						'title'     => (string) $row['title'],
						'permalink' => (string) ( $row['permalink'] ?? '' ),
						'warnings'  => array_values( (array) $row['warnings'] ),
					];
				}
				if ( count( $warning_pages ) >= 20 ) break;
			}
		}

		// .htaccess rule position
		$rule_position = 'not present';
		if ( file_exists( $htaccess_path ) && is_readable( $htaccess_path ) ) {
			$contents = (string) file_get_contents( $htaccess_path );
			$nexeng_pos = strpos( $contents, '# BEGIN Nexora SSG' );
			$wp_pos  = strpos( $contents, '# BEGIN WordPress' );
			if ( $nexeng_pos === false ) {
				$rule_position = 'absent';
			} elseif ( $wp_pos === false ) {
				$rule_position = 'present';
			} elseif ( $nexeng_pos < $wp_pos ) {
				$rule_position = 'before WP block';
			} else {
				$rule_position = 'after WP block';
			}
		}

		$server_section['rows'][] = [
			'label'  => '.htaccess position',
			'status' => $rule_position === 'before WP block' ? 'ok' : ( $rule_position === 'after WP block' ? 'err' : 'warn' ),
			'value'  => $rule_position,
		];

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'verdict'  => [
					'status' => $verdict_status,
					'label'  => $verdict_label,
					'msg'    => $verdict_msg,
				],
				'sections' => [
					$probe_section,
					$ssg_section,
					$dropin_section,
					$server_section,
				],
				'warning_pages' => $warning_pages,
				'generated_at'  => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			],
		] );
	}

	public function wizard_finish( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( 'NEXENG_Wizard' ) ) {
			NEXENG_Wizard::get_instance()->complete_wizard();
		}
		// Clear bulk-running flag so a stale build can't re-trigger the wizard
		// guard on the next page load.
		delete_transient( 'nexeng_ssg_bulk_running' );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'url'     => admin_url( 'admin.php?page=nexora' ),
				'message' => 'Setup complete.',
			],
		] );
	}

	public function wizard_reset( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( 'NEXENG_Wizard' ) ) {
			NEXENG_Wizard::get_instance()->reset_completion();
		}
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'message' => 'Wizard completion reset. You can now run the wizard again.' ],
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// SSG Builder — browser-driven batch tick, archives, preflight, nginx,
	// mirror inspector, exclusions, asset mode.
	//
	// Browser-driven design: React calls /ssg/batch-tick repeatedly while a
	// bulk run is active. Each tick captures ONE page (paced naturally by
	// HTTP round-trip latency), updates the browser-active heartbeat, and
	// returns status. Cron fallback only kicks in if the browser disconnects.
	// ──────────────────────────────────────────────────────────────────────

	public function ssg_batch_tick( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'done' => true, 'reason' => 'ssg_unavailable' ] ] );
		}
		if ( ! NEXENG_SSG::is_enabled() ) {
			// Mid-flight disable — tell the UI to stop polling cleanly.
			return rest_ensure_response( [
				'success' => true,
				'data'    => [ 'done' => true, 'reason' => 'ssg_disabled', 'remaining' => 0 ],
			] );
		}

		$ssg = NEXENG_SSG::get_instance();

		// Heartbeat: tell cron the browser session is alive. Cron's bulk tick
		// checks this transient and skips processing while React is driving.
		set_transient( 'nexeng_ssg_browser_active', 1, 2 * MINUTE_IN_SECONDS );

		// ── Server-protection guards ───────────────────────────────────
		// These are belt-and-suspenders to the NEXENG_SSG core (which already
		// enforces MIN_CAPTURE_GAP = 4 s and an atomic capture mutex). They
		// add HIGHER-LEVEL caps so a chatty admin tab / a runaway queue /
		// a degraded host can never amplify server load past safe limits.
		$throttle_reason = '';

		// 1) Hard per-minute capture rate ceiling. Default 12 captures/min
		//    (one every 5 s). Adjustable via filter for production hosts
		//    that want more or less aggressive throughput.
		$rate_limit  = (int) apply_filters( 'nexeng_ssg_captures_per_minute', 12 );
		$rate_window = 60;
		$rate_log    = (array) get_transient( 'nexeng_ssg_capture_rate_log' );
		$rate_now    = time();
		$rate_log    = array_values( array_filter(
			$rate_log,
			static fn ( $ts ) => is_int( $ts ) && $ts > $rate_now - $rate_window
		) );
		if ( count( $rate_log ) >= $rate_limit ) {
			$throttle_reason = 'rate_limit';
		}

		// 2) Adaptive back-off — each tick now captures a time-budgeted BATCH
		//    (~6 s of work), so the recorded duration is per-batch, not per-page.
		//    A batch that consistently overruns its budget by a wide margin
		//    means the host is genuinely struggling (slow loopback, high load) —
		//    skip the next tick to give the FPM pool a break. Threshold is the
		//    browser drive budget + generous headroom; filterable for tuning.
		$stress_ms = (int) apply_filters( 'nexeng_ssg_tick_stress_ms', 18000 );
		$ttfb_log  = (array) get_transient( 'nexeng_ssg_capture_ttfb_log' );
		if ( ! $throttle_reason && count( $ttfb_log ) >= 3 ) {
			$recent_avg = array_sum( array_slice( $ttfb_log, -3 ) ) / 3;
			if ( $recent_avg > $stress_ms ) {
				$throttle_reason = 'host_stressed';
			}
		}

		$captured_this_tick = false;
		// DEFER to the server-side driver ONLY while it's genuinely advancing the
		// queue. capture_in_progress() is heartbeat-gated: it's true only if a
		// capture actually moved the queue in the last ~10s. This is the single
		// reliable "one driver at a time" signal —
		//   • If the server loopback is alive and working (live LiteSpeed): the
		//     heartbeat is fresh → browser defers → server drives alone → the
		//     counts move monotonically (no bounce from two concurrent drivers).
		//   • If the loopback is blocked/dropped or its process died: the
		//     heartbeat goes stale within ~10s → capture_in_progress() returns
		//     false → the BROWSER takes over and drives → the build never freezes.
		// We deliberately do NOT also gate on the nexeng_ssg_drive_inflight hint:
		// that flag can stay set for up to 30s after a loopback was dropped, which
		// would wrongly make the browser defer to a dead driver and freeze the
		// build ("nothing progresses"). The heartbeat is the source of truth.
		$server_driver_active = method_exists( $ssg, 'capture_in_progress' )
			&& $ssg->capture_in_progress();
		if (
			get_transient( 'nexeng_ssg_bulk_running' )
			&& ! get_transient( 'nexeng_ssg_bulk_paused' )
			&& ! $throttle_reason
			&& ! $server_driver_active
		) {
			// Capture a TIME-BUDGETED BATCH in this poll, not a single page.
			// This is the reliable cross-host driver: ssg_batch_tick runs inside
			// an authenticated admin REST request, which caching/proxy layers
			// (LiteSpeed, Cloudflare, Varnish) never serve from cache — so the
			// build always advances with the tab open even when the server-side
			// loopback driver is blocked by the host (the live LiteSpeed case
			// where 0 pages captured). drive_batch() acquires the capture mutex
			// itself and returns the progress; if another driver (loopback/cron)
			// holds the lock it returns reason=lock_held and we just skip.
			$tick_t0  = microtime( true );
			$progress = $ssg->drive_batch();
			$tick_ttfb = (int) ( ( microtime( true ) - $tick_t0 ) * 1000 );

			if ( ( $progress['reason'] ?? '' ) !== 'lock_held' ) {
				$captured_this_tick = true;

				// Record into the rate ring.
				$rate_log[] = $rate_now;
				set_transient( 'nexeng_ssg_capture_rate_log', $rate_log, $rate_window + 5 );

				// Record into the TTFB ring (keep last 10).
				$ttfb_log[] = $tick_ttfb;
				$ttfb_log = array_slice( $ttfb_log, -10 );
				set_transient( 'nexeng_ssg_capture_ttfb_log', $ttfb_log, 15 * MINUTE_IN_SECONDS );
			}
			// If the lock was held, another driver was mid-capture — skip.
		}

		$status = $ssg->bulk_status();

		// Surface the throttle reason to the rail so we can show "Pacing
		// captures to protect the server" if relevant.
		if ( $throttle_reason ) {
			$status['throttled'] = $throttle_reason;
		}
		if ( ! $captured_this_tick && ! $throttle_reason ) {
			// We tried but the mutex was held — surface so the rail can
			// say "another capture is in flight, waiting".
			$status['skipped'] = 'capture_lock_held';
		}

		// On completion, fire CDN zone-wide purge so the edge serves the fresh
		// mirror immediately — same hook as the legacy batch handler.
		if ( ! empty( $status['done'] ) && class_exists( 'NEXENG_CDN' ) && NEXENG_CDN::is_configured() ) {
			$cdn = NEXENG_CDN::purge_all();
			if ( is_wp_error( $cdn ) ) {
				$status['cdn_purge_error'] = $cdn->get_error_message();
			} else {
				$status['cdn_purged'] = true;
			}
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $status ] );
	}

	public function ssg_regen_archives( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) || ! NEXENG_SSG::is_enabled() ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Static delivery is disabled.',
				'code'    => 'ssg_disabled',
			], 400 );
		}

		$ssg   = NEXENG_SSG::get_instance();
		$count = $ssg->bulk_start_archives_only();
		if ( is_wp_error( $count ) ) {
			$bulk = $ssg->bulk_status();
			return new WP_REST_Response( [
				'success' => false,
				'message' => $count->get_error_message(),
				'busy'    => ! empty( $bulk['running'] ) && empty( $bulk['done'] ),
				'data'    => $this->ssg_state_payload(),
			], 409 );
		}

		if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
		}

		$status = $ssg->bulk_status();
		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge( $this->ssg_state_payload(), [
				'total'         => (int) $count,
				'breakdown'     => $status['breakdown'] ?? [],
				'build_session' => $status['build_session'] ?? '',
				'message'       => $count > 0
					? sprintf( '%d archive page%s queued.', $count, $count === 1 ? '' : 's' )
					: 'No archive pages to capture.',
			] ),
		] );
	}

	public function ssg_preflight( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'ok' => true, 'ttfb' => 0 ] ] );
		}
		$t0     = microtime( true );
		$result = NEXENG_SSG::get_instance()->capture_preflight();
		$ttfb   = (int) ( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success' => true,
				'data'    => [
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'ttfb'    => $ttfb,
				],
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'ok' => true, 'ttfb' => $ttfb ],
		] );
	}

	public function ssg_nginx_config( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'config' => '' ] ] );
		}
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'config' => (string) NEXENG_SSG::get_instance()->nginx_serve_config() ],
		] );
	}

	public function ssg_mirror_list( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'rows' => [] ] ] );
		}
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) $limit = 200;
		if ( $limit > 500 ) $limit = 500;

		$rows = (array) NEXENG_SSG::get_instance()->list_status( $limit );
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'rows' => $rows ] ] );
	}

	public function ssg_mirror_delete( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing post_id' ], 400 );
		}
		if ( class_exists( 'NEXENG_SSG' ) ) {
			NEXENG_SSG::get_instance()->delete_post( $post_id );
		}
		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'message' => 'Static file deleted. Page now serves dynamically.' ],
		] );
	}

	public function ssg_exclude_post( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Missing post_id' ], 400 );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Post not found.' ], 404 );
		}
		$title = get_the_title( $post_id );

		// 1. Per-post exclude flag — mirrors the editor metabox setting.
		update_post_meta( $post_id, '_nexeng_exclude', '1' );

		// 2. Drop any cached static file so visitors get dynamic immediately.
		if ( class_exists( 'NEXENG_SSG' ) ) {
			$ssg = NEXENG_SSG::get_instance();
			if ( method_exists( $ssg, 'delete_static_file' ) ) {
				$ssg->delete_static_file( $post_id );
			} elseif ( method_exists( $ssg, 'schedule_delete' ) ) {
				$ssg->schedule_delete( $post_id );
			}
			if ( method_exists( $ssg, 'clear_pending' ) ) $ssg->clear_pending( $post_id );
			if ( method_exists( $ssg, 'clear_fatal' ) )   $ssg->clear_fatal( $post_id );
		}

		// 3. Remove this post from the recent-errors log.
		$errors = (array) get_option( 'nexeng_ssg_errors', [] );
		$errors = array_values( array_filter( $errors, static function ( $e ) use ( $post_id ) {
			return ! is_array( $e ) || (int) ( $e['post_id'] ?? 0 ) !== $post_id;
		} ) );
		update_option( 'nexeng_ssg_errors', $errors, false );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'post_id' => $post_id,
				'message' => sprintf(
					'"%s" excluded — it will now serve dynamically and won\'t appear in future builds.',
					$title
				),
			],
		] );
	}

	public function ssg_exclusions_get( WP_REST_Request $request ): WP_REST_Response {
		$types = (array) get_option( 'nexeng_ssg_excluded_types', [] );
		$hosts_raw = (string) get_option( 'nexeng_ssg_script_hosts', '' );

		// Build a list of available public CPTs so the React editor can
		// render checkboxes without a second round-trip.
		$available_types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $slug => $pto ) {
			if ( in_array( $slug, [ 'attachment' ], true ) ) continue;
			$available_types[] = [
				'slug'  => $slug,
				'label' => (string) ( $pto->labels->name ?? ucfirst( $slug ) ),
			];
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'excluded_types' => array_values( array_filter( array_map( 'strval', $types ) ) ),
				'script_hosts'   => $hosts_raw,
				'available_types' => $available_types,
			],
		] );
	}

	public function ssg_exclusions_save( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();

		$types_raw = isset( $body['types'] ) ? (array) $body['types'] : [];
		$types     = array_values( array_filter( array_map( 'sanitize_key', $types_raw ) ) );
		update_option( 'nexeng_ssg_excluded_types', $types );

		$hosts_raw = isset( $body['hosts'] ) ? (string) $body['hosts'] : '';
		$clean = [];
		foreach ( preg_split( '/\R+/', $hosts_raw ) as $line ) {
			$line = trim( (string) $line );
			if ( $line === '' ) continue;
			if ( preg_match( '/^[a-z0-9.\-]+$/i', $line ) ) {
				$clean[] = strtolower( $line );
			}
		}
		$hosts_clean = implode( "\n", $clean );
		update_option( 'nexeng_ssg_script_hosts', $hosts_clean );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'message'        => 'Exclusions saved.',
				'excluded_types' => $types,
				'script_hosts'   => $hosts_clean,
			],
		] );
	}

	public function ssg_set_asset_mode( WP_REST_Request $request ): WP_REST_Response {
		// The legacy handler required explicit confirmation because switching
		// mode purges and rebuilds the entire mirror. Keep that gate.
		if ( ! $request->get_param( 'purge_confirmed' ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Action requires explicit confirmation.',
			], 403 );
		}

		$mode = (string) $request->get_param( 'mode' );
		$mode = $mode === 'proxy' ? 'proxy' : 'direct';

		// Stealth Proxy is implemented by NEXENG_Ghost_Pro, which is not part of
		// this build when the class is absent. Refuse to store a mode nothing can
		// carry out, rather than accept it and silently serve direct. This asks
		// what the build can do, not what licence is active.
		if ( $mode === 'proxy' && ! class_exists( 'NEXENG_Ghost_Pro' ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Stealth Proxy is not available in this version of the plugin.',
			], 400 );
		}

		$old_mode = (string) get_option( 'nexeng_asset_mode', 'direct' );
		update_option( 'nexeng_asset_mode', $mode );

		// Refresh drop-in so it bakes the new mode into advanced-cache.php.
		if ( class_exists( 'NEXENG_Dropin' ) && method_exists( 'NEXENG_Dropin', 'install' ) ) {
			NEXENG_Dropin::install();
		}

		// Install / remove the Apache stealth asset rule.
		$ssg = class_exists( 'NEXENG_SSG' ) ? NEXENG_SSG::get_instance() : null;
		if ( $ssg ) {
			if ( $mode === 'proxy' && NEXENG_SSG::is_enabled() ) {
				if ( method_exists( $ssg, 'install_stealth_asset_rule' ) ) $ssg->install_stealth_asset_rule();
			} else {
				if ( method_exists( $ssg, 'uninstall_stealth_asset_rule' ) ) $ssg->uninstall_stealth_asset_rule();
			}
		}

		// On actual mode change, purge + restart full build.
		$rebuilding = false;
		$total      = 0;
		if ( $ssg && $mode !== $old_mode ) {
			$ssg->purge_all();
			if ( NEXENG_SSG::is_enabled() ) {
				$count = $ssg->bulk_start();
				if ( ! is_wp_error( $count ) && $count > 0 ) {
					$total = (int) $count;
					if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
						wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
					}
					$rebuilding = true;
				}
			}
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge( $this->ssg_state_payload(), [
				'mode'       => $mode,
				'rebuilding' => $rebuilding,
				'total'      => $total,
				'message'    => $rebuilding
					? "Switched to {$mode} mode. Purged cache and started rebuild of {$total} pages."
					: "Asset mode set to {$mode}.",
			] ),
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Redirects (PRO) — thin wrappers around NEXENG_Database, mirroring the
	// existing AJAX handlers' validation rules so React and PHP call sites
	// behave identically.
	// ──────────────────────────────────────────────────────────────────────

	public function get_redirects( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_Database' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'rows' => [], 'stats' => [], 'chain_ids' => [], 'is_pro' => $this->is_pro() ] ] );
		}
		$db       = NEXENG_Database::get_instance();
		$blog_id  = get_current_blog_id();
		$per_page = max( 1, min( 200, (int) $request->get_param( 'per_page' ) ?: 50 ) );
		$paged    = max( 1, (int) $request->get_param( 'paged' ) ?: 1 );
		$rows     = $db->get_redirects( $blog_id, $per_page, ( $paged - 1 ) * $per_page );
		$stats    = $db->get_redirect_stats( $blog_id );

		// Chain detection — a row whose source_url matches another row's target_url
		// is in a redirect chain. Mirrors the legacy view's chain_ids array.
		$all_targets = array_map(
			static function ( $r ) { return rtrim( (string) ( $r['target_url'] ?? '' ), '/' ); },
			$db->get_redirects( $blog_id, 9999, 0 )
		);
		$chain_ids = [];
		foreach ( $rows as $r ) {
			$full = rtrim( home_url( (string) ( $r['source_url'] ?? '' ) ), '/' );
			if ( in_array( $full, $all_targets, true ) ) {
				$chain_ids[] = (int) $r['id'];
			}
		}

		// Redirect-manager conflict detection — surface any other active
		// redirect plugins so the user knows to consolidate into one source
		// of truth or at least be aware both are running.
		$redirect_conflicts = [];
		$known_redirect_plugins = [
			'redirection/redirection.php'                       => 'Redirection',
			'safe-redirect-manager/safe-redirect-manager.php'  => 'Safe Redirect Manager',
			'301-redirects/301-redirects.php'                   => 'SEO Redirection',
			'simple-301-redirects/wp-simple-301-redirects.php' => 'Simple 301 Redirects',
			'rank-math/rank-math.php'                           => 'Rank Math (built-in redirects)',
			'seo-by-rank-math/seo-by-rank-math.php'            => 'Rank Math SEO',
			'wordpress-seo/wp-seo.php'                          => 'Yoast SEO (premium redirects)',
			'wordpress-seo-premium/wp-seo-premium.php'          => 'Yoast SEO Premium',
			'nexora-pulse/nexora-pulse.php'                     => 'Nexora Pulse',
		];
		if ( function_exists( 'is_plugin_active' ) ) {
			foreach ( $known_redirect_plugins as $file => $label ) {
				if ( is_plugin_active( $file ) ) {
					$redirect_conflicts[] = $label;
				}
			}
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'rows'               => $rows,
				'stats'              => $stats,
				'chain_ids'          => $chain_ids,
				'paged'              => $paged,
				'per_page'           => $per_page,
				'is_pro'             => $this->is_pro(),
				// Other active redirect plugins — React renders a notice if non-empty.
				'redirect_conflicts' => $redirect_conflicts,
			],
		] );
	}

	public function add_redirect( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_pro() ) {
			return rest_ensure_response( [ 'success' => false, 'message' => 'Pro license required.' ] );
		}
		$body      = (array) $request->get_json_params();
		$source    = trim( sanitize_text_field( (string) ( $body['source'] ?? '' ) ) );
		$target    = esc_url_raw( (string) ( $body['target'] ?? '' ) );
		$type      = (int) ( $body['type'] ?? 301 );
		$is_active = (int) ( ! empty( $body['is_active'] ) ? 1 : 0 );
		$notes     = sanitize_text_field( (string) ( $body['notes'] ?? '' ) );

		if ( $source === '' || $target === '' ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Source path and target URL are required.' ], 400 );
		}
		if ( '/' !== substr( $source, 0, 1 ) ) {
			$source = '/' . $source;
		}

		$db      = NEXENG_Database::get_instance();
		$blog_id = get_current_blog_id();
		$ok      = $db->insert_redirect( $blog_id, $source, $target, $type, $is_active, $notes );

		if ( ! $ok ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Database error — redirect not saved.' ], 500 );
		}

		delete_transient( 'nexeng_redirects_' . $blog_id );
		if ( class_exists( 'NEXENG_Redirect_Manager' ) && method_exists( 'NEXENG_Redirect_Manager', 'sync_htaccess' ) ) {
			NEXENG_Redirect_Manager::sync_htaccess( $blog_id );
		}
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'message' => 'Redirect saved.' ] ] );
	}

	public function delete_redirect( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_pro() ) {
			return rest_ensure_response( [ 'success' => false, 'message' => 'Pro license required.' ] );
		}
		$id      = (int) $request->get_param( 'id' );
		$db      = NEXENG_Database::get_instance();
		$blog_id = get_current_blog_id();
		$ok      = $db->delete_redirect( $id, $blog_id );

		if ( ! $ok ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Redirect not found or already deleted.' ], 404 );
		}

		delete_transient( 'nexeng_redirects_' . $blog_id );
		if ( class_exists( 'NEXENG_Redirect_Manager' ) && method_exists( 'NEXENG_Redirect_Manager', 'sync_htaccess' ) ) {
			NEXENG_Redirect_Manager::sync_htaccess( $blog_id );
		}
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'message' => 'Redirect deleted.' ] ] );
	}

	public function toggle_redirect( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_pro() ) {
			return rest_ensure_response( [ 'success' => false, 'message' => 'Pro license required.' ] );
		}
		$id        = (int) $request->get_param( 'id' );
		$is_active = (bool) $request->get_param( 'is_active' );
		$db        = NEXENG_Database::get_instance();
		$blog_id   = get_current_blog_id();
		$ok        = $db->toggle_redirect( $id, $blog_id, $is_active );

		if ( ! $ok ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Failed to update redirect status.' ], 500 );
		}

		delete_transient( 'nexeng_redirects_' . $blog_id );
		if ( class_exists( 'NEXENG_Redirect_Manager' ) && method_exists( 'NEXENG_Redirect_Manager', 'sync_htaccess' ) ) {
			NEXENG_Redirect_Manager::sync_htaccess( $blog_id );
		}
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'message' => 'Status updated.', 'is_active' => $is_active ] ] );
	}

	public function export_redirects( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_pro() ) {
			return rest_ensure_response( [ 'success' => false, 'message' => 'Pro license required.' ] );
		}
		$db      = NEXENG_Database::get_instance();
		$blog_id = get_current_blog_id();
		$rows    = $db->get_redirects( $blog_id, 9999, 0 );

		$lines = [ 'Source URL,Target URL,Type,Status,Hits,Notes,Created At' ];
		foreach ( $rows as $r ) {
			$lines[] = sprintf(
				'"%s","%s","%d","%s","%d","%s","%s"',
				str_replace( '"', '""', (string) $r['source_url'] ),
				str_replace( '"', '""', (string) $r['target_url'] ),
				(int) $r['redirect_type'],
				empty( $r['is_active'] ) ? 'Inactive' : 'Active',
				(int) ( $r['hit_count'] ?? 0 ),
				str_replace( '"', '""', (string) ( $r['notes'] ?? '' ) ),
				(string) ( $r['created_at'] ?? '' )
			);
		}
		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'filename' => 'nexora-redirects-' . gmdate( 'Y-m-d' ) . '.csv',
				'csv'      => implode( "\n", $lines ),
			],
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Tools / Maintenance — thin wrappers over the existing AJAX handlers
	// so the React Tools page shares behaviour with the legacy PHP view.
	// ──────────────────────────────────────────────────────────────────────

	public function tools_status( WP_REST_Request $request ): WP_REST_Response {
		$ssg_stats = class_exists( 'NEXENG_SSG' ) ? (array) NEXENG_SSG::get_instance()->stats() : [];
		$ssg_on    = class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled();

		// License recovery context — only populated for Pro users since the
		// legacy view hides the whole panel for free.
		$license = [];
		if ( $this->is_pro() && class_exists( '\\NexoraEngine\\Licensing\\Environment' ) ) {
			$env        = \NexoraEngine\Licensing\Environment::current();
			$plan       = \NexoraEngine\Licensing\FeatureGate::get_plan();
			$cache      = class_exists( '\\NexoraEngine\\Licensing\\EntitlementCache' )
				? \NexoraEngine\Licensing\EntitlementCache::get()
				: null;
			$grace_on   = class_exists( '\\NexoraEngine\\Licensing\\GracePeriod' )
				&& \NexoraEngine\Licensing\GracePeriod::is_active();
			$dev_on     = class_exists( '\\NexoraEngine\\Licensing\\DevOverrides' )
				&& \NexoraEngine\Licensing\DevOverrides::is_active();
			$fs_ok      = class_exists( '\\NexoraEngine\\Licensing\\FreemiusAdapter' )
				&& \NexoraEngine\Licensing\FreemiusAdapter::instance()->is_available();
			$allow_dev  = class_exists( '\\NexoraEngine\\Licensing\\Environment' )
				&& \NexoraEngine\Licensing\Environment::allows_dev_tools();

			$cache_age  = $cache && class_exists( '\\NexoraEngine\\Licensing\\EntitlementCache' )
				? (int) \NexoraEngine\Licensing\EntitlementCache::age_seconds()
				: -1;
			$grace_secs = $grace_on && class_exists( '\\NexoraEngine\\Licensing\\GracePeriod' )
				? (int) \NexoraEngine\Licensing\GracePeriod::seconds_remaining()
				: 0;

			if ( $dev_on && \NexoraEngine\Licensing\DevOverrides::get_plan() !== null ) {
				$source = 'Dev mode (simulated)';
			} elseif ( $fs_ok ) {
				$source = 'Live verification';
			} elseif ( $cache ) {
				$source = 'Cached locally';
			} elseif ( $grace_on ) {
				$source = 'Grace period';
			} else {
				$source = 'Default free';
			}

			$license = [
				'plan'        => $plan,
				'source'      => $source,
				'environment' => $env,
				'env_label'   => method_exists( '\\NexoraEngine\\Licensing\\Environment', 'label' )
					? \NexoraEngine\Licensing\Environment::label()
					: ucfirst( (string) $env ),
				'cache_age_minutes'  => $cache_age >= 0 ? round( $cache_age / 60, 1 ) : null,
				'grace_active'       => $grace_on,
				'grace_hours_left'   => $grace_on ? round( $grace_secs / 3600, 1 ) : 0,
				'server_reachable'   => $fs_ok,
				'dev_mode_active'    => $dev_on,
				'allow_dev_tools'    => $allow_dev,
				'sync_url'           => wp_nonce_url(
					add_query_arg( 'nexeng_sync', '1', admin_url( 'admin.php?page=ncx-updates' ) ),
					'nexeng_sync_license'
				),
			];
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'system' => [
					'php'              => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
					'wordpress'        => get_bloginfo( 'version' ),
					'engine_version'   => defined( 'NEXORA_ENGINE_VERSION' ) ? NEXORA_ENGINE_VERSION : '',
					'static_delivery'  => $ssg_on,
					'static_pages'     => (int) ( $ssg_stats['total_files'] ?? 0 ),
					'mirror_bytes'     => (int) ( $ssg_stats['total_bytes'] ?? 0 ),
				],
				'license' => $license,
				'is_pro'  => $this->is_pro(),
			],
		] );
	}

	public function tools_flush_permalinks( WP_REST_Request $request ): WP_REST_Response {
		flush_rewrite_rules();
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'message' => 'Permalink cache flushed. Sitemap and paths rebuilt.' ] ] );
	}

	public function tools_purge_analytics( WP_REST_Request $request ): WP_REST_Response {
		if ( class_exists( 'NEXENG_Analytics' ) ) {
			NEXENG_Analytics::get_instance()->purge_logs();
		}
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'message' => 'Analytics logs purged successfully.' ] ] );
	}

	public function tools_export_settings( WP_REST_Request $request ): WP_REST_Response {
		$settings = [];
		// Mirrors the legacy export — same keys, same wire format.
		$options = [ 'headless_mode', 'debug_mode', 'analytics_enabled', 'anonymize_ips', 'sitemap_enabled', 'schema_enabled', 'asset_mode' ];
		foreach ( $options as $opt ) {
			$settings[ $opt ] = get_option( "nexeng_{$opt}" );
		}

		$payload = [
			'plugin'      => 'nexora-engine',
			'version'     => defined( 'NEXORA_ENGINE_VERSION' ) ? NEXORA_ENGINE_VERSION : '',
			'exported_at' => gmdate( 'c' ),
			'settings'    => $settings,
		];

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'filename' => 'nexora-engine-config-' . gmdate( 'Y-m-d' ) . '.json',
				'json'     => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			],
		] );
	}

	public function tools_license_clear_cache( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_pro() ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Pro license required.' ], 403 );
		}
		if ( class_exists( '\\NexoraEngine\\Licensing\\EntitlementCache' ) ) {
			\NexoraEngine\Licensing\EntitlementCache::bust();
		}
		delete_transient( 'nexeng_license_status' );
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'message' => 'Local licence cache cleared. The next page load will re-verify.' ] ] );
	}

	public function tools_license_reset_sandbox( WP_REST_Request $request ): WP_REST_Response {
		// Dev-only — gated server-side as well to make the React UI's gating non-authoritative.
		if ( ! class_exists( '\\NexoraEngine\\Licensing\\Environment' )
			|| ! \NexoraEngine\Licensing\Environment::allows_dev_tools() ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Dev tools are not enabled on this install.' ], 403 );
		}
		if ( class_exists( '\\NexoraEngine\\Licensing\\EntitlementCache' ) ) {
			\NexoraEngine\Licensing\EntitlementCache::bust();
		}
		if ( class_exists( '\\NexoraEngine\\Licensing\\GracePeriod' ) && method_exists( '\\NexoraEngine\\Licensing\\GracePeriod', 'clear' ) ) {
			\NexoraEngine\Licensing\GracePeriod::clear();
		}
		return rest_ensure_response( [ 'success' => true, 'data' => [ 'message' => 'Sandbox state cleared.' ] ] );
	}

	/**
	 * Factory reset — wipe every Nexora Engine touch point so the next admin
	 * page load behaves like a brand-new install: redirect to the wizard,
	 * empty mirror, no drop-in, no pending queue.
	 *
	 * Requires the client to send `confirm: "FACTORY_RESET"` as proof of
	 * intent (double-confirm in the UI). The license activation is NOT touched
	 * — Freemius owns that lifecycle and resetting it here would orphan the
	 * user's purchase. Use the dedicated "Clear local licence cache" tool
	 * instead if licensing state needs refreshing.
	 *
	 * Steps:
	 *   1. Disable SSG (this fires the update_option hook that uninstalls the drop-in).
	 *   2. Purge the static mirror.
	 *   3. Reset wizard completion + install id.
	 *   4. Clear pending posts and all bulk-state transients.
	 *   5. Clear cached stats and fatal-pages list.
	 *   6. Reset asset mode to 'direct' and headless mode to off.
	 *   7. Cancel scheduled cron events.
	 */

	/**
	 * Read-only introspection of every gate that controls SSG auto-rebuild.
	 * Surfaces the actual values so we can see exactly where a stalled
	 * queue is stuck — license, option, scheduled events, transient locks.
	 */
	public function tools_ssg_debug( WP_REST_Request $request ): WP_REST_Response {
		$now = time();

		// License — both code paths.
		$license = [
			'rest_is_pro'     => $this->is_pro(),
			'plan'            => $this->resolve_plan(),
			'feature_gate'    => class_exists( 'NexoraEngine\\Licensing\\FeatureGate' )
				? \NexoraEngine\Licensing\FeatureGate::get_plan()
				: 'unknown',
			'nexeng_licence'     => class_exists( 'NEXENG_Licence' ) ? NEXENG_Licence::is_pro() : null,
		];

		// Auto-rebuild diagnostic. Reads through NEXENG_SSG so it reports the
		// engine's real condition rather than a second copy of it.
		$auto_rebuild = [
			'option_raw'        => get_option( 'nexeng_auto_rebuild', null ),
			'effective_default' => $this->is_pro() ? 'on' : 'off',
			'opt_on'            => 'on' === get_option( 'nexeng_auto_rebuild', $this->is_pro() ? 'on' : 'off' ),
			'effective'         => class_exists( 'NEXENG_SSG' )
				&& method_exists( 'NEXENG_SSG', 'auto_rebuild_active' )
				&& NEXENG_SSG::auto_rebuild_active(),
		];

		// Pending queue raw.
		$pending_raw = (array) get_option( 'nexeng_ssg_pending_posts', [] );
		$pending = [
			'count' => count( $pending_raw ),
			'items' => array_map(
				static function ( $key, $entry ) {
					return [
						'id'        => (int) $key,
						'reason'    => is_array( $entry ) ? (string) ( $entry['reason'] ?? '' ) : '',
						'queued_at' => is_array( $entry ) ? (int) ( $entry['ts'] ?? 0 ) : 0,
					];
				},
				array_keys( $pending_raw ),
				array_values( $pending_raw )
			),
		];

		// Per-pending-post: is there actually a scheduled cron event?
		foreach ( $pending['items'] as &$item ) {
			$next = wp_next_scheduled( 'nexeng_ssg_regen', [ $item['id'] ] );
			$item['scheduled_at']   = $next ? (int) $next : 0;
			$item['scheduled_in_s'] = $next ? ( (int) $next - $now ) : null;
		}
		unset( $item );

		// Full cron table — only NEXENG_SSG-related events.
		$crons = (array) get_option( 'cron', [] );
		$nexeng_events = [];
		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_int( $ts ) || ! is_array( $hooks ) ) continue;
			foreach ( $hooks as $hook => $entries ) {
				if ( strpos( (string) $hook, 'nexeng_' ) !== 0 ) continue;
				foreach ( (array) $entries as $sig => $details ) {
					$nexeng_events[] = [
						'hook'      => $hook,
						'timestamp' => $ts,
						'in_s'      => $ts - $now,
						'args'      => isset( $details['args'] ) ? array_values( (array) $details['args'] ) : [],
					];
				}
			}
		}
		usort( $nexeng_events, static fn ( $a, $b ) => $a['timestamp'] <=> $b['timestamp'] );

		// Transient locks that can block cron_regen.
		$transients = [
			'nexeng_ssg_last_capture_at' => (string) get_transient( 'nexeng_ssg_last_capture_at' ),
			'nexeng_ssg_cron_busy'       => (bool) get_transient( 'nexeng_ssg_cron_busy' ),
			'nexeng_ssg_bulk_running'    => (bool) get_transient( 'nexeng_ssg_bulk_running' ),
			'nexeng_ssg_bulk_paused'     => (bool) get_transient( 'nexeng_ssg_bulk_paused' ),
			'nexeng_ssg_browser_active'  => (bool) get_transient( 'nexeng_ssg_browser_active' ),
			'nexeng_state_cron_kick'     => (bool) get_transient( 'nexeng_state_cron_kick' ),
			'doing_cron'              => (string) get_transient( 'doing_cron' ),
		];

		// Constants / environment.
		$env = [
			'disable_wp_cron'       => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_wp_cron'     => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'wp_cache'              => defined( 'WP_CACHE' ) && WP_CACHE,
			'has_fastcgi_finish'    => function_exists( 'fastcgi_finish_request' ),
			'has_spawn_cron'        => function_exists( 'spawn_cron' ),
			'has_wp_cron'           => function_exists( 'wp_cron' ),
			'ssg_enabled'           => class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled(),
		];

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'now'          => gmdate( 'Y-m-d H:i:s', $now ) . ' UTC',
				'license'      => $license,
				'auto_rebuild' => $auto_rebuild,
				'pending'      => $pending,
				'cron_events'  => $nexeng_events,
				'transients'   => $transients,
				'env'          => $env,
			],
		] );
	}

	/**
	 * Forcefully run wp_cron() in-process right now. Returns the list of
	 * events that fired so the caller can see exactly what was dispatched.
	 */
	public function tools_run_cron_now( WP_REST_Request $request ): WP_REST_Response {
		$now = time();
		$crons = (array) get_option( 'cron', [] );
		$due_before = [];
		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_int( $ts ) || ! is_array( $hooks ) ) continue;
			if ( $ts > $now ) continue;
			foreach ( $hooks as $hook => $entries ) {
				foreach ( (array) $entries as $sig => $details ) {
					$due_before[] = [
						'hook'  => (string) $hook,
						'when'  => $ts,
						'args'  => isset( $details['args'] ) ? array_values( (array) $details['args'] ) : [],
					];
				}
			}
		}

		// Clear any stuck core-cron lock so we don't get a silent skip,
		// and our own rail-poll throttle so the next state poll can rearm.
		delete_transient( 'doing_cron' );
		delete_transient( 'nexeng_state_cron_kick' );

		// Run all due NEXENG_SSG events in-process via the same dispatch path
		// the rail-poll shutdown handler uses. This bypasses wp_cron()'s
		// lock semantics so a stale doing_cron can't silently skip events.
		$this->dispatch_due_nexeng_cron();

		// Re-read the cron option to see what's still pending.
		$crons_after = (array) get_option( 'cron', [] );
		$still_due = [];
		foreach ( $crons_after as $ts => $hooks ) {
			if ( ! is_int( $ts ) || ! is_array( $hooks ) ) continue;
			if ( $ts > $now ) continue;
			foreach ( $hooks as $hook => $entries ) {
				foreach ( (array) $entries as $sig => $details ) {
					$still_due[] = [
						'hook' => (string) $hook,
						'when' => $ts,
					];
				}
			}
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'due_before_count' => count( $due_before ),
				'due_before'       => $due_before,
				'still_due_count'  => count( $still_due ),
				'still_due'        => $still_due,
				'message'          => count( $still_due ) === 0
					? 'wp_cron() ran. All due events processed.'
					: count( $still_due ) . ' event(s) still due — capture may have failed silently.',
			],
		] );
	}

	public function tools_factory_reset( WP_REST_Request $request ): WP_REST_Response {
		$confirm = (string) $request->get_param( 'confirm' );
		if ( $confirm !== 'FACTORY_RESET' ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Confirmation token missing or invalid.',
			], 400 );
		}

		$summary = [];

		// 1. Disable SSG — the option-change hook in nexora-engine.php uninstalls
		// advanced-cache.php for us; we don't need to call NEXENG_Dropin::uninstall().
		update_option( 'nexeng_ssg_enabled', 'off' );
		$summary[] = 'SSG disabled';

		// 2. Purge the static mirror.
		if ( class_exists( 'NEXENG_SSG' ) ) {
			NEXENG_SSG::get_instance()->purge_all();
			$summary[] = 'Mirror purged';
		}

		// 3. Wizard + install identity.
		delete_option( 'nexeng_wizard_completed' );
		delete_option( 'nexeng_install_id' );
		// User-meta onboarding flag — cleared for the user running the reset.
		delete_user_meta( get_current_user_id(), 'nexeng_onboarding_complete' );
		$summary[] = 'Wizard reset';

		// 4. Pending queue + bulk transients (mirrors handle_ssg_clear_all_pending).
		update_option( 'nexeng_ssg_pending_posts', [], false );
		foreach ( [
			'nexeng_ssg_bulk_queue', 'nexeng_ssg_bulk_total', 'nexeng_ssg_bulk_done',
			'nexeng_ssg_bulk_errors', 'nexeng_ssg_bulk_running', 'nexeng_ssg_bulk_mode',
			'nexeng_ssg_bulk_breakdown', 'nexeng_ssg_bulk_attempts', 'nexeng_ssg_bulk_last_url',
			'nexeng_ssg_bulk_paused', 'nexeng_ssg_browser_active', 'nexeng_ssg_build_session',
			'nexeng_ssg_cron_busy', 'nexeng_ssg_archives_dismissed',
		] as $key ) {
			delete_transient( $key );
		}
		delete_option( 'nexeng_ssg_errors' );
		delete_option( 'nexeng_ssg_archives_dirty' );
		delete_option( 'nexeng_ssg_fatal_pages' );
		$summary[] = 'Queue cleared';

		// 5. Stats / runtime cache.
		delete_transient( 'nexeng_ssg_stats_' . get_current_blog_id() );
		delete_transient( 'nexeng_diag_html_' . get_current_blog_id() );

		// 6. Mode flags.
		update_option( 'nexeng_asset_mode', 'direct' );
		update_option( 'nexeng_headless_mode', 'off' );
		$summary[] = 'Modes reset';

		// 7. Cron.
		foreach ( [
			'nexeng_ssg_bulk_tick', 'nexeng_ssg_bulk_watchdog', 'nexeng_ssg_regen',
			'nexeng_ssg_global_invalidate',
		] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		$summary[] = 'Cron cleared';

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'message'      => 'Factory reset complete. Next page load redirects to the wizard.',
				'steps'        => $summary,
				'redirect_url' => admin_url( 'admin.php?page=ncx-wizard' ),
			],
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Addons registry
	// ──────────────────────────────────────────────────────────────────────

	public function get_addons( WP_REST_Request $request ): WP_REST_Response {
		$addons = [];
		if ( class_exists( 'NEXENG_Admin' ) && method_exists( 'NEXENG_Admin', 'get_addon_registry' ) ) {
			$addons = (array) NEXENG_Admin::get_instance()->get_addon_registry();
		}

		// Decorate each row with the action URLs the legacy view computes inline.
		$decorated = [];
		foreach ( $addons as $addon ) {
			$file = (string) ( $addon['file'] ?? '' );
			$decorated[] = array_merge( $addon, [
				'activate_url' => $file
					? wp_nonce_url(
						admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ),
						'activate-plugin_' . $file
					)
					: '',
				'install_url' => ! empty( $addon['wp_org_slug'] )
					? admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( (string) $addon['wp_org_slug'] ) )
					: '',
				'settings_url' => ! empty( $addon['settings_slug'] )
					? admin_url( 'admin.php?page=' . rawurlencode( (string) $addon['settings_slug'] ) )
					: '',
			] );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => [ 'addons' => $decorated ],
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// License info
	// ──────────────────────────────────────────────────────────────────────

	public function get_license( WP_REST_Request $request ): WP_REST_Response {
		$info = [];
		if ( class_exists( '\\NexoraEngine\\Licensing\\LicenseManager' ) ) {
			$info = (array) \NexoraEngine\Licensing\LicenseManager::instance()->get_info();
		}

		// Account / upgrade URLs — same resolution as the legacy view.
		$account_url = '';
		if ( class_exists( '\\NexoraEngine\\Licensing\\FreemiusAdapter' ) ) {
			$adapter = \NexoraEngine\Licensing\FreemiusAdapter::instance();
			if ( $adapter->is_available() ) {
				$account_url = (string) $adapter->get_account_url();
			}
		}
		if ( ! $account_url ) {
			$account_url = admin_url( 'admin.php?page=nexora-account' );
		}

		$upgrade_url = $this->upgrade_url();

		// Days remaining
		$expiry_ts  = (int) ( $info['expiry_ts'] ?? 0 );
		$is_lifetime = ( ( $info['expiry'] ?? '' ) === 'Lifetime' );
		$days_left  = ( $expiry_ts > 0 ) ? (int) ceil( ( $expiry_ts - time() ) / DAY_IN_SECONDS ) : null;
		$tier       = strtolower( (string) ( $info['tier'] ?? 'free' ) );
		$is_paid    = in_array( $tier, [ 'pro', 'agency', 'enterprise', 'cloud' ], true );
		$validity   = 'none';
		if ( $is_lifetime ) {
			$validity = 'lifetime';
		} elseif ( null !== $days_left ) {
			if ( $days_left <= 0 )       $validity = 'expired';
			elseif ( $days_left <= 30 )  $validity = 'warning';
			else                          $validity = 'valid';
		} elseif ( ! $is_paid ) {
			// Free tier holds no license, so "No expiry" reads like a perpetual
			// entitlement. Say plainly that no license applies.
			$validity = 'free';
		}

		// Just-activated flash (matches legacy transient)
		$just_activated = (bool) get_transient( 'nexeng_just_activated' );
		if ( $just_activated ) {
			delete_transient( 'nexeng_just_activated' );
		}

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge( $info, [
				'account_url'    => $account_url,
				'upgrade_url'    => $upgrade_url,
				'days_left'      => $days_left,
				'is_lifetime'    => $is_lifetime,
				'validity'       => $validity,
				'just_activated' => $just_activated,
			] ),
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Validate group — SEO Report + Pages & Posts grid
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Build the same "relative URL" key the analytics layer uses so we can
	 * cheaply join SSG pages against the top-pages traffic table. The legacy
	 * PHP views computed this inline in two places — keep it in one helper
	 * to avoid drift.
	 */
	private function relative_url_for_post( int $post_id ): string {
		$permalink = (string) get_permalink( $post_id );
		$rel       = (string) ( wp_parse_url( $permalink, PHP_URL_PATH ) ?: '/' );
		$home_path = rtrim( (string) ( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '' ), '/' );
		if ( $home_path && strpos( $rel, $home_path ) === 0 ) {
			$rel = substr( $rel, strlen( $home_path ) );
		}
		$rel = '/' . trim( $rel, '/' );
		if ( $rel !== '/' ) {
			$rel .= '/';
		}
		return $rel;
	}

	private function eligible_seo_types(): array {
		$types = get_post_types( [ 'public' => true ], 'names' );
		// Mirrors NEXENG_SSG::is_eligible() exclusions so we don't promise SEO
		// coverage for post types the static engine wouldn't capture.
		foreach ( [ 'attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ] as $internal ) {
			unset( $types[ $internal ] );
		}
		return array_values( $types );
	}

	public function get_seo_report( WP_REST_Request $request ): WP_REST_Response {
		$types = $this->eligible_seo_types();
		$posts = get_posts( [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 500,
		] );

		// Traffic map keyed by relative URL.
		$traffic_map = [];
		$traffic_total_hits = 0;
		$traffic_tracked = 0;
		if ( class_exists( 'NEXENG_Analytics' ) ) {
			$rows = (array) NEXENG_Analytics::get_instance()->get_top_pages( 200 );
			$traffic_tracked = count( $rows );
			foreach ( $rows as $r ) {
				$url = (string) ( $r['url'] ?? '' );
				$hit = (int) ( $r['hits'] ?? 0 );
				if ( $url !== '' ) {
					$traffic_map[ $url ] = $hit;
				}
				$traffic_total_hits += $hit;
			}
		}

		$missing_meta = 0;
		$missing_og   = 0;
		$schema_types = [];
		$rows = [];

		foreach ( $posts as $p ) {
			$seo_data = (array) ( get_post_meta( $p->ID, '_nexeng_seo_data', true ) ?: [] );
			$has_desc = ! empty( $seo_data['og_desc'] );
			$has_og   = ! empty( $seo_data['og_image'] ) || has_post_thumbnail( $p->ID );
			$schema   = (string) ( $seo_data['schema_type'] ?? 'Article' );

			if ( ! $has_desc ) {
				$missing_meta++;
			}
			if ( empty( $seo_data['og_image'] ) ) {
				$missing_og++;
			}
			if ( ! isset( $schema_types[ $schema ] ) ) {
				$schema_types[ $schema ] = 0;
			}
			$schema_types[ $schema ]++;

			$rel_url = $this->relative_url_for_post( (int) $p->ID );

			$rows[] = [
				'id'          => (int) $p->ID,
				'title'       => (string) $p->post_title,
				'post_type'   => (string) $p->post_type,
				'permalink'   => (string) get_permalink( $p->ID ),
				'edit_url'    => (string) get_edit_post_link( $p->ID, '' ),
				'relative'    => $rel_url,
				'has_desc'    => $has_desc,
				'has_og'      => $has_og,
				'schema_type' => $schema,
				'hits'        => (int) ( $traffic_map[ $rel_url ] ?? 0 ),
			];
		}

		ksort( $schema_types );

		return rest_ensure_response( [
			'success' => true,
			'data'    => [
				'sitemap_url' => home_url( '/sitemap.xml' ),
				'totals'      => [
					'urls'             => count( $posts ),
					'missing_meta'     => $missing_meta,
					'missing_og'       => $missing_og,
					'social_ready_pct' => count( $posts ) > 0
						? (int) round( ( count( $posts ) - $missing_og ) / count( $posts ) * 100 )
						: 0,
					'schema_types_count' => count( $schema_types ),
					'traffic_total_hits' => $traffic_total_hits,
					'traffic_tracked'    => $traffic_tracked,
				],
				'schema_types' => $schema_types,
				'rows'         => $rows,
			],
		] );
	}

	/**
	 * Returns every public post with its capture status — what the legacy
	 * Pages & Posts page rendered. The React Headless page uses this to
	 * display the full content grid (capped at 500 rows; matches legacy 200).
	 */
	public function get_ssg_pages( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_SSG' ) ) {
			return rest_ensure_response( [ 'success' => true, 'data' => [ 'rows' => [], 'manifest_count' => 0, 'fatal_count' => 0 ] ] );
		}

		// Lightweight 30s response cache — this endpoint loops every public
		// post calling get_permalink() / get_edit_post_link() / the relative
		// URL helper. On a site with a few hundred posts that's ~1000
		// permalink generations per call.
		//
		// Cache key signature mixes pending_count + manifest file mtime, so
		// writes (new post saved → pending_count flips, or a capture
		// completes → manifest mtime advances) invalidate naturally without
		// us having to wire delete_transient() into every save_post path.
		$ssg = NEXENG_SSG::get_instance();
		// Bump the cache key any time the payload shape changes so old
		// entries from previous plugin versions are ignored, not
		// returned-as-stale. Increment the suffix when the response
		// shape changes (added _debug + bytes / warnings fields).
		$cache_key = 'nexeng_rest_ssg_pages_v3_' . get_current_blog_id();
		$pending_n = method_exists( $ssg, 'pending_count' ) ? (int) $ssg->pending_count() : 0;

		// is_callable() respects visibility — method_exists() returns true for
		// private methods too, which previously caused a fatal when this
		// path was called. Guard with is_callable so future regressions
		// (a maintainer making a public method private) degrade silently.
		$manifest_path = is_callable( [ $ssg, 'manifest_path' ] ) ? (string) $ssg->manifest_path() : '';
		$manifest_mtime = ( $manifest_path !== '' && file_exists( $manifest_path ) )
			? (int) filemtime( $manifest_path )
			: 0;

		// Mix the published-post count into the cache signature so newly
		// published or unpublished content immediately busts the cache.
		// Without this, a fresh-install user would see "No published content
		// found." for up to 30 s after their first publish because pending
		// + manifest mtime are still 0 → cache returns the old empty
		// payload (the original bug). count() over a small option is
		// cheap; this keeps the endpoint responsive without staleness.
		$post_count_sig = (int) wp_count_posts( 'post' )->publish
			+ (int) wp_count_posts( 'page' )->publish;
		$cache_sig = $pending_n . '|' . $manifest_mtime . '|' . $post_count_sig;

		// `?fresh=1` lets the React layer bypass the transient when the
		// user explicitly hits Retry. Useful when something has gone wrong
		// upstream and the cached empty payload would otherwise persist
		// for 30 s past the fix.
		$fresh  = (bool) $request->get_param( 'fresh' );
		$cached = $fresh ? null : get_transient( $cache_key );
		if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $cache_sig ) {
			return rest_ensure_response( [ 'success' => true, 'data' => $cached['data'] ] );
		}

		$manifest     = method_exists( $ssg, 'get_manifest' ) ? (array) $ssg->get_manifest() : [];
		$fatal_pages  = method_exists( $ssg, 'get_fatal_pages' ) ? (array) $ssg->get_fatal_pages() : [];
		$pending_count = method_exists( $ssg, 'pending_count' ) ? (int) $ssg->pending_count() : 0;

		// Match the legacy pages-report.php query exactly — same post-type
		// resolution, same get_posts() call shape. The previous version
		// shipped this on production and worked across every site we
		// support; the only safe change is to bump posts_per_page from
		// 200 → 500 so larger sites still see everything.
		$types = get_post_types( [ 'public' => true ], 'names' );
		unset( $types['attachment'] );
		// Defensive: some headless themes register `post` / `page` with
		// public => false. Add them back so we never end up with an
		// empty post-type list.
		if ( ! isset( $types['post'] ) ) { $types['post'] = 'post'; }
		if ( ! isset( $types['page'] ) ) { $types['page'] = 'page'; }

		$posts = get_posts( [
			'post_type'      => array_values( $types ),
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'type',
			'order'          => 'ASC',
		] );

		// Show ONLY pages the build will actually capture. Without this the table
		// listed types the build queue correctly skips (Elementor library
		// templates, builder CPTs like nca_page, etc.), which then sat on
		// "Pending" forever — inflating the count and making it look like the
		// build never finishes. Filtering through the same is_eligible() gate the
		// build uses keeps the table and the build queue in agreement.
		if ( is_callable( [ $ssg, 'is_eligible' ] ) ) {
			$posts = array_values( array_filter(
				$posts,
				static fn ( $p ) => $ssg->is_eligible( (int) $p->ID )
			) );
		}

		// Diagnostic counts — we still want these to show in the
		// empty-state debugger when something has gone wrong upstream.
		$found_posts = count( $posts );
		$count_post  = (int) wp_count_posts( 'post' )->publish;
		$count_page  = (int) wp_count_posts( 'page' )->publish;

		// Traffic map (relative URL → hits) — same shape as SEO report.
		$traffic_map = [];
		if ( class_exists( 'NEXENG_Analytics' ) ) {
			foreach ( (array) NEXENG_Analytics::get_instance()->get_top_pages( 200 ) as $r ) {
				$traffic_map[ (string) ( $r['url'] ?? '' ) ] = (int) ( $r['hits'] ?? 0 );
			}
		}

		$rows = [];
		foreach ( $posts as $p ) {
			$id          = (int) $p->ID;
			$rel_url     = $this->relative_url_for_post( $id );
			$is_fatal    = isset( $fatal_pages[ $id ] );
			$is_captured = isset( $manifest[ $id ] );
			$is_stale    = $is_captured && method_exists( $ssg, 'is_post_stale' )
				? (bool) $ssg->is_post_stale( $id, $manifest[ $id ] )
				: false;

			if ( $is_fatal )            $state = 'fatal';
			elseif ( $is_stale )        $state = 'stale';
			elseif ( $is_captured )     $state = 'captured';
			else                         $state = 'pending';

			$generated_at = $is_captured ? (int) ( $manifest[ $id ]['generated_at'] ?? 0 ) : 0;
			$bytes        = $is_captured ? (int) ( $manifest[ $id ]['bytes'] ?? 0 ) : 0;
			$warnings     = $is_captured ? (array) ( $manifest[ $id ]['warnings'] ?? [] ) : [];

			$rows[] = [
				'id'           => $id,
				'title'        => (string) $p->post_title,
				'post_type'    => (string) $p->post_type,
				'post_type_label' => ucfirst( (string) $p->post_type ),
				'permalink'    => (string) get_permalink( $id ),
				'relative'     => $rel_url,
				'edit_url'     => (string) get_edit_post_link( $id, '' ),
				'state'        => $state,
				'is_captured'  => $is_captured,
				'is_stale'     => $is_stale,
				'is_fatal'     => $is_fatal,
				'fatal_message' => $is_fatal ? (string) ( $fatal_pages[ $id ]['message'] ?? '' ) : '',
				'fatal_ts'      => $is_fatal ? (int) ( $fatal_pages[ $id ]['ts'] ?? 0 ) : 0,
				'generated_at' => $generated_at,
				'generated_iso' => $generated_at > 0 ? gmdate( 'c', $generated_at ) : null,
				'hits'         => (int) ( $traffic_map[ $rel_url ] ?? 0 ),
				// Mirror-side data joined in so the React table doesn't need a
				// second roundtrip for size / warning info per row.
				'bytes'        => $bytes,
				'warnings'     => $warnings,
			];
		}

		$payload = [
			'rows'           => $rows,
			'manifest_count' => count( $manifest ),
			'fatal_count'    => count( $fatal_pages ),
			'pending_count'  => $pending_count,
			// Diagnostic block — only useful when rows is empty. Lets the
			// React layer surface "why is this empty?" to the user instead
			// of just a generic empty-state. eligible_types = post-type
			// list we asked for; wp_count_* = ground-truth from DB; if
			// the ground truth is > 0 but rows is empty, something
			// upstream is intercepting.
			'_debug' => [
				'wp_query_total'  => $found_posts,
				'wp_query_count'  => $found_posts,
				'eligible_types'  => array_values( $types ),
				'wp_count_post'   => $count_post,
				'wp_count_page'   => $count_page,
			],
		];

		// Cache for 30s. The signature key bumps automatically when pending
		// count changes (write side flips it) so stale data never sticks
		// around longer than a single user interaction.
		set_transient( $cache_key, [ 'sig' => $cache_sig, 'data' => $payload ], 30 );

		return rest_ensure_response( [ 'success' => true, 'data' => $payload ] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Auralogics Portal — connection state + key/token operations.
	// Mirrors the legacy AJAX handlers in NEXENG_Admin so React drives the
	// same flow as the PHP page.
	// ──────────────────────────────────────────────────────────────────────

	private function portal_state_payload(): array {
		$portal_key          = (string) get_option( 'nexeng_portal_key', '' );
		$portal_site         = (string) get_option( 'nexeng_portal_site_id', '' );
		$portal_connected_at = (int) get_option( 'nexeng_portal_connected', 0 );
		$connected = $portal_connected_at > 0 || ( $portal_key !== '' && $portal_site !== '' );

		$portal_url = defined( 'NEXORA_PORTAL_BASE' )
			? rtrim( (string) NEXORA_PORTAL_BASE, '/' ) . '/portal'
			: 'https://auralogicslabs.com/portal';

		$token        = '';
		$connect_url  = '';
		if ( class_exists( 'NEXENG_Portal_API' ) ) {
			$token = (string) NEXENG_Portal_API::get_token();
			// Only fetch a fresh connect URL when we're NOT connected — the
			// legacy view did the same, since calling get_connect_url() rolls
			// a one-time handshake token and would invalidate any active
			// portal telemetry session if we're already linked.
			if ( ! $connected && method_exists( 'NEXENG_Portal_API', 'get_connect_url' ) ) {
				$connect_url = (string) NEXENG_Portal_API::get_connect_url();
			}
		}

		$token_masked = $token !== '' ? substr( $token, 0, 6 ) . str_repeat( '•', 26 ) : '';
		$key_masked   = $portal_key !== '' ? str_repeat( '•', 12 ) . substr( $portal_key, -6 ) : '';

		return [
			'connected'         => $connected,
			'connected_at'      => $portal_connected_at > 0
				? gmdate( 'c', $portal_connected_at )
				: null,
			'connected_human'   => $portal_connected_at > 0
				? date_i18n(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					$portal_connected_at
				)
				: '',
			'site_id'           => $portal_site,
			'has_key'           => $portal_key !== '',
			'key_masked'        => $key_masked,
			'has_token'         => $token !== '',
			'token_masked'      => $token_masked,
			'portal_url'        => $portal_url,
			'connect_url'       => $connect_url,
			'is_pro'            => $this->is_pro(),
			'upgrade_url'       => $this->upgrade_url(),
		];
	}

	public function get_portal( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [ 'success' => true, 'data' => $this->portal_state_payload() ] );
	}

	public function portal_connect( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->is_pro() ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Portal connectivity requires Nexora Engine Pro.' ], 403 );
		}

		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );
		if ( $key === '' || strpos( $key, 'prtl_' ) !== 0 ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid key format. Portal keys begin with prtl_' ], 400 );
		}

		// Same flow as the legacy AJAX handler — generate a site ID on first
		// connect so the portal can address this install uniquely.
		$site_id = (string) get_option( 'nexeng_portal_site_id', '' );
		if ( $site_id === '' ) {
			$site_id = 'site_' . substr( md5( home_url() . wp_generate_password( 8, false ) ), 0, 16 );
			update_option( 'nexeng_portal_site_id', $site_id );
		}

		update_option( 'nexeng_portal_key', $key );
		update_option( 'nexeng_portal_connected', time(), false );

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge(
				$this->portal_state_payload(),
				[ 'message' => 'Site connected to Auralogics Portal.' ]
			),
		] );
	}

	public function portal_disconnect( WP_REST_Request $request ): WP_REST_Response {
		delete_option( 'nexeng_portal_key' );
		delete_option( 'nexeng_portal_site_id' );
		delete_option( 'nexeng_portal_connected' );
		delete_option( 'nexeng_portal_token' );

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge(
				$this->portal_state_payload(),
				[ 'message' => 'Site disconnected from portal.' ]
			),
		] );
	}

	public function portal_regenerate_token( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'NEXENG_Portal_API' ) || ! method_exists( 'NEXENG_Portal_API', 'regenerate_token' ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Portal API not available.' ], 500 );
		}
		$token = (string) NEXENG_Portal_API::regenerate_token();

		return rest_ensure_response( [
			'success' => true,
			'data'    => array_merge(
				$this->portal_state_payload(),
				[
					'message' => 'Site token regenerated. Reconnect this site via the portal.',
					'masked'  => substr( $token, 0, 6 ) . str_repeat( '•', 26 ),
				]
			),
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Shape the SSG state in one consistent payload for both /summary and
	 * /ssg/state so the React Mirror Build Control panel always has the same
	 * keys regardless of which endpoint produced them.
	 */
	private function ssg_state_payload(): array {
		if ( ! class_exists( 'NEXENG_SSG' ) ) {
			return [
				'enabled'         => false,
				'pending_count'   => 0,
				'running'         => false,
				'paused'          => false,
				'processed'       => 0,
				'total'           => 0,
				'percent'         => 0,
				'last_write'      => null,
				'static_files'    => 0,
				'static_bytes'    => 0,
				'auto_rebuild'    => false,
				'archives_missing' => false,
			];
		}
		$ssg     = NEXENG_SSG::get_instance();
		$enabled = method_exists( 'NEXENG_SSG', 'is_enabled' ) && NEXENG_SSG::is_enabled();
		$bulk    = method_exists( $ssg, 'bulk_status' ) ? (array) $ssg->bulk_status() : [];
		$stats   = method_exists( $ssg, 'stats' ) ? (array) $ssg->stats() : [];
		$pending = method_exists( $ssg, 'pending_count' ) ? (int) $ssg->pending_count() : 0;
		$archive = ( $enabled && method_exists( $ssg, 'archive_manifest_status' ) ) ? (array) $ssg->archive_manifest_status() : [];

		$total     = (int) ( $bulk['total'] ?? 0 );
		$processed = (int) ( $bulk['processed'] ?? 0 );
		$percent   = $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 0;

		// Auto-rebuild is Pro (smart_automation). "opt" is what the user asked
		// for, "effective" is what the engine will actually do — they differ on
		// the free tier, where the rail shows the option as set but the chip
		// still reports manual rebuilds. Effective must come from
		// NEXENG_SSG::auto_rebuild_active() so the chip cannot claim behaviour
		// the engine does not have.
		$auto_rebuild_opt = 'on' === get_option( 'nexeng_auto_rebuild', $this->is_pro() ? 'on' : 'off' );
		$auto_rebuild_effective = class_exists( 'NEXENG_SSG' )
			&& method_exists( 'NEXENG_SSG', 'auto_rebuild_active' )
			&& NEXENG_SSG::auto_rebuild_active();

		// Pending queue preview — first 5 entries with title / reason / when.
		// We deliberately do NOT call get_post()/get_permalink() in a loop here
		// because /ssg/state polls every 1.5–8 seconds. On a site with several
		// pending posts that adds up to a noticeable admin slowdown. The raw
		// pending option already has reason + ts; we resolve title + URL only
		// for the first 5 IDs we'll actually display, and skip permalink (which
		// loads rewrite rules) in favour of edit_url which is a cheap string.
		$pending_preview = [];
		if ( $pending > 0 ) {
			$pending_raw = (array) get_option( 'nexeng_ssg_pending_posts', [] );

			// Merge in published pages that were never captured (same set the
			// count now includes), so the preview list is never empty while the
			// counter says > 0 — that mismatch left the rail stuck on
			// "Loading queue…". Edit-queue entries keep their reason/timestamp;
			// never-captured pages get a "not captured yet" reason.
			$missing_ids = ( $ssg && method_exists( $ssg, 'missing_post_ids' ) )
				? (array) $ssg->missing_post_ids()
				: [];
			foreach ( $missing_ids as $mid ) {
				$mid = (int) $mid;
				if ( $mid > 0 && ! isset( $pending_raw[ $mid ] ) ) {
					$pending_raw[ $mid ] = [ 'reason' => 'never_captured', 'ts' => 0 ];
				}
			}

			$ids_shown = 0;
			foreach ( $pending_raw as $post_id => $entry ) {
				if ( $ids_shown >= 5 ) break;
				$post_id = (int) $post_id;
				if ( $post_id <= 0 ) continue;

				$post = get_post( $post_id );
				if ( ! $post || $post->post_status !== 'publish' ) {
					continue;
				}

				$reason    = '';
				$queued_at = 0;
				if ( is_array( $entry ) ) {
					$reason    = (string) ( $entry['reason'] ?? '' );
					$queued_at = (int) ( $entry['ts'] ?? 0 );
				}

				$pending_preview[] = [
					'id'         => $post_id,
					'title'      => (string) ( $post->post_title ?: '(no title)' ),
					'post_type'  => (string) $post->post_type,
					'permalink'  => '',                                   // resolved lazily on click
					'edit_url'   => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
					'reason'     => $reason,
					'queued_iso' => $queued_at > 0 ? gmdate( 'c', $queued_at ) : null,
				];
				$ids_shown++;
			}
		}

		// Activity feed removed from the hot path — list_status() walks the
		// manifest + calls get_post()/get_permalink() per entry, which is
		// expensive enough to dominate a 1.5s state poll. The rail now reads
		// "last_write" + "static_files" + "pending_preview" only; the dedicated
		// /ssg/pages endpoint still serves the full inspector when the user
		// opens the Static Delivery page.
		$activity = [];

		// ── Engine health — circuit-breaker detection ──────────────────
		// On shared hosting (low FPM workers, rate-limited loopback, throttled
		// PHP), the capture loop can hammer the same wall every retry: 45-s
		// cURL timeout × 3 retries = ~2 min per page with no forward progress.
		// We watch for the symptom (a streak of transient HTTP failures in
		// the recent errors log) and surface it via the rail UI instead of
		// letting the engine silently grind. The "Resume" button in the rail
		// gives the user a manual recovery path — we never auto-resume so we
		// don't silently re-stress a struggling host.
		//
		// Must run BEFORE the self-healing cron driver below so it can read
		// $health['degraded'] and decline to dispatch when we're degraded.
		$health = $this->engine_health_report();
		$recent_errors   = $health['recent_errors'];
		$degraded        = $health['degraded'];
		$degraded_reason = $health['reason'];

		// ── Auto-rebuild driver ────────────────────────────────────────
		// The previous design tried to dispatch WP-cron events in a shutdown
		// handler. That approach had a fundamental flaw: it relied on the
		// engine's own cron events (nexeng_ssg_regen / nexeng_ssg_global_invalidate
		// / nexeng_ssg_bulk_tick) actually being scheduled AND being due — but on
		// LocalWP, cron events scheduled for 5 minutes out never run because
		// no front-end traffic triggers WP-cron. The queue would sit forever.
		//
		// New approach (simpler, no cron games): when auto-rebuild is
		// effective and there are pending posts but NO bulk run is active,
		// just start a pending build directly. That's the same call the
		// "Build pending" button makes. Once bulk_running is set, the
		// browser-side BuildDriver picks it up and drives /ssg/batch-tick
		// itself — which works reliably even on LocalWP because each tick
		// is a single foreground HTTP request from the open admin tab.
		//
		// Guards keep this from misbehaving:
		//   • bulk_running   — a build is already in progress, leave it alone
		//   • browser_active — the React driver is processing the queue
		//   • degraded       — engine just paused due to host stress; respect
		//   • kick throttle  — we already started one within the last 30s
		$bulk_running   = (bool) get_transient( 'nexeng_ssg_bulk_running' );
		$browser_active = (bool) get_transient( 'nexeng_ssg_browser_active' );
		$auto_started   = false;

		// Wedged-state recovery — when bulk_running is set but NOTHING has
		// captured in the last ~2 minutes AND no browser is driving, the
		// previous bulk session likely crashed (PHP timeout, browser closed
		// mid-loopback, etc.). Clear the wedge so a fresh bulk_start_pending
		// can take over. Without this, the queue sits forever because every
		// other code path defers to "another build is running".
		if ( $bulk_running && ! $browser_active ) {
			$last_capture_at = (float) get_transient( 'nexeng_ssg_last_capture_at' );
			$wedged_for = $last_capture_at > 0
				? microtime( true ) - $last_capture_at
				: 999.0; // transient expired → at least 60s since last capture
			if ( $wedged_for > 120 ) {
				// Stale running flag. Clear it so the auto-start below can
				// re-arm the queue cleanly.
				delete_transient( 'nexeng_ssg_bulk_running' );
				delete_transient( 'nexeng_ssg_bulk_paused' );
				$bulk_running = false;
			}
		}

		// Auto-rebuild bulk-size cap. Site-wide invalidations (theme save,
		// menu edit, plugin update) can mark hundreds of pages pending in
		// one shot. Auto-starting a 500-page bulk silently is exactly the
		// "plugin is a bad neighbor" pattern we want to avoid. If pending
		// exceeds the cap, we surface a notice instead of auto-firing —
		// the user clicks "Build pending" to opt in to the heavy work.
		$auto_cap = (int) apply_filters( 'nexeng_ssg_auto_rebuild_cap', 100 );
		$auto_cap_exceeded = $pending > $auto_cap;

		if ( $auto_rebuild_effective
			&& $pending > 0
			&& ! $auto_cap_exceeded
			&& ! $bulk_running
			&& ! $browser_active
			&& ! $degraded
			&& ! get_transient( 'nexeng_state_autostart_throttle' )
			&& class_exists( 'NEXENG_SSG' )
		) {
			// 30s throttle prevents the rail (which polls every 2 s while
			// pending > 0) from re-trying the auto-start on every poll.
			set_transient( 'nexeng_state_autostart_throttle', 1, 30 );

			$ssg   = NEXENG_SSG::get_instance();
			$count = method_exists( $ssg, 'bulk_start_pending' )
				? (int) $ssg->bulk_start_pending()
				: 0;
			if ( $count > 0 ) {
				$auto_started = true;
				// Re-read bulk state so the response we send reflects the
				// just-started build instead of the stale snapshot above.
				$bulk     = (array) $ssg->bulk_status();
				$total    = (int) ( $bulk['total'] ?? $total );
				$processed = (int) ( $bulk['processed'] ?? $processed );
				$percent  = $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 0;
			}
		}

		// If we just auto-started, re-evaluate running from the live transient.
		$is_running = $auto_started
			? true
			: ( ! empty( $bulk['running'] ) && empty( $bulk['done'] ) );

		// Self-heal: if a build is running but the server-side drive loop isn't
		// in flight (e.g. a loopback pass died, or the queue was started by a
		// path that didn't kick it), nudge it here. kick_bulk_drive() is
		// self-throttled so this is safe to call on every state poll. This is the
		// belt-and-suspenders that guarantees a queued build always makes
		// progress even if the primary kick was missed.
		if ( $is_running && method_exists( $ssg, 'kick_bulk_drive' ) ) {
			$ssg->kick_bulk_drive();
		}

		return [
			'enabled'          => $enabled,
			'pending_count'    => $pending,
			'running'          => $is_running,
			'paused'           => ! empty( $bulk['paused'] ),
			'processed'        => $processed,
			'total'            => $total,
			'percent'          => $percent,
			'last_write'       => isset( $stats['last_write'] ) && (int) $stats['last_write'] > 0
				? gmdate( 'c', (int) $stats['last_write'] )
				: null,
			// NEXENG_SSG::stats() emits `total_files` / `total_bytes` — the
			// `static_*_count` keys never existed. Keys returned to JS stay
			// `static_files` / `static_bytes` since every UI component reads
			// those.
			'static_files'     => (int) ( $stats['total_files'] ?? 0 ),
			'static_bytes'     => (int) ( $stats['total_bytes'] ?? 0 ),
			'auto_rebuild'           => $auto_rebuild_opt,
			'auto_rebuild_effective' => $auto_rebuild_effective,
			'is_pro'                 => $this->is_pro(),
			'archives_missing'       => ! empty( $archive['needs_build'] ),
			'archives_missing_count' => (int) ( $archive['missing'] ?? 0 ),
			'pending_preview'        => $pending_preview,
			'activity'               => $activity,
			// Engine health — drives the "server slow" notice in the rail.
			'recent_errors'   => $recent_errors,
			'failed_count'    => $health['failed_count'],
			'degraded'        => $degraded,
			'degraded_reason' => $degraded_reason,
			'curl28_count'    => (int) ( $health['curl28_count'] ?? 0 ),
			// Auto-rebuild cap — surface so the rail can show "X pages need
			// review, click Build pending" instead of silently auto-firing.
			'auto_cap'          => $auto_cap,
			'auto_cap_exceeded' => $auto_cap_exceeded,
		];
	}

	/**
	 * Build the rail's engine-health snapshot. Inspects the recent-errors log
	 * for a streak of HTTP timeouts (the classic shared-host / low-FPM
	 * symptom) and short-circuits the bulk loop when detected.
	 *
	 * Auto-pause behaviour: when the streak reaches 3 consecutive
	 * transient HTTP failures in the last 5 minutes, the bulk run is
	 * paused (nexeng_ssg_bulk_paused transient set) so the engine doesn't
	 * keep hammering a struggling server. The rail's "Resume" button
	 * clears the lock so the user can retry manually after the server
	 * recovers.
	 *
	 * The pause is intentionally NOT cleared automatically — if the server
	 * is degraded for hours, we don't want the engine to silently retry
	 * every 30s and add to the load. The user (or a Pro auto-rebuild
	 * window) is responsible for resuming. This is the difference between
	 * "polite plugin" and "DOS-tier autocron".
	 */
	private function engine_health_report(): array {
		$errors_raw = (array) get_option( 'nexeng_ssg_errors', [] );
		$now = time();

		// Map to a compact shape the rail consumes.
		$recent = [];
		$curl28_count = 0;
		foreach ( array_slice( $errors_raw, 0, 5 ) as $e ) {
			$msg = (string) ( $e['message'] ?? '' );
			if ( stripos( $msg, 'cURL error 28' ) !== false
				|| stripos( $msg, 'Operation timed out after 45' ) !== false
			) {
				$curl28_count++;
			}
			$recent[] = [
				'post_id' => (int) ( $e['post_id'] ?? 0 ),
				'title'   => (string) ( $e['title'] ?? '' ),
				'url'     => (string) ( $e['url'] ?? '' ),
				'code'    => (string) ( $e['code'] ?? '' ),
				'message' => $msg,
				'stage'   => (string) ( $e['stage'] ?? '' ),
				'ts_iso'  => isset( $e['ts'] ) ? gmdate( 'c', (int) $e['ts'] ) : null,
			];
		}

		// Detect a streak of recent transient HTTP failures. These are the
		// codes the engine's own is_retryable_error() treats as "try again
		// later" — they're the signature of FPM worker starvation, network
		// throttling, or a 502/504 upstream. A real "page exists but is
		// broken" error (nexeng_ssg_source_fatal, nexeng_ssg_redirect_offsite,
		// etc.) is NOT counted here — those are post-specific, not
		// server-degradation symptoms.
		$transient_codes = [
			'nexeng_ssg_http_error',     // wp_remote_get returned WP_Error (timeout, DNS, refused)
			'nexeng_ssg_http_5xx',       // upstream 5xx
			'nexeng_ssg_http_408',       // request timeout
			'nexeng_ssg_http_429',       // rate limited
		];
		$streak         = 0;
		$curl28_streak  = 0;
		$five_min_ago   = $now - 5 * MINUTE_IN_SECONDS;
		foreach ( $errors_raw as $e ) {
			$ts = (int) ( $e['ts'] ?? 0 );
			if ( $ts < $five_min_ago ) {
				break; // older entries don't count toward the streak
			}
			$code = (string) ( $e['code'] ?? '' );
			$msg  = (string) ( $e['message'] ?? '' );
			$is_curl28 = stripos( $msg, 'cURL error 28' ) !== false
				|| stripos( $msg, 'Operation timed out after 45' ) !== false;
			if ( $is_curl28 ) {
				$curl28_streak++;
			}
			if ( in_array( $code, $transient_codes, true )
				|| stripos( $msg, 'timed out' ) !== false
				|| stripos( $msg, 'curl error 28' ) !== false
			) {
				$streak++;
			} else {
				break; // an unrelated error breaks the streak
			}
		}

		$degraded = false;
		$reason   = '';

		if ( $streak >= 3 ) {
			$degraded = true;
			// Distinguish cURL 28 (worker starvation on low-FPM hosts like LocalWP)
			// from generic HTTP failures so the rail can show a more helpful message.
			$reason = $curl28_streak >= 2
				? 'fpm_worker_exhausted'   // cURL 28 = PHP-FPM pool exhausted — LocalWP classic
				: 'transient_http_streak'; // generic connection / 5xx pattern
			// Auto-pause the bulk run so we don't keep hammering.
			if ( ! get_transient( 'nexeng_ssg_bulk_paused' )
				&& get_transient( 'nexeng_ssg_bulk_running' )
			) {
				set_transient( 'nexeng_ssg_bulk_paused', 1, 4 * HOUR_IN_SECONDS );
				set_transient( 'nexeng_engine_auto_paused_at', $now, 4 * HOUR_IN_SECONDS );
			}
		} elseif ( get_transient( 'nexeng_engine_auto_paused_at' ) && ! get_transient( 'nexeng_ssg_bulk_paused' ) ) {
			$reason = 'recovered';
		}

		return [
			'recent_errors' => $recent,
			'failed_count'  => count( $errors_raw ),
			'degraded'      => $degraded,
			'reason'        => $reason,
			'streak'        => $streak,
			'curl28_count'  => $curl28_count,
		];
	}

	/**
	 * Settings key map — the source of truth for which options the React
	 * settings page may read/write, plus their defaults for type coercion.
	 */
	/**
	 * Settings option name => default-value (the default also encodes the
	 * type: bool defaults are stored as 'on'/'off', int defaults are cast,
	 * everything else is a sanitized string).
	 *
	 * Keep this aligned with the allowlist in NEXENG_Admin::handle_save_settings —
	 * adding a key here without updating the AJAX allowlist (or vice versa)
	 * creates a divergent surface between the React UI and legacy forms.
	 */
	private function settings_key_map(): array {
		return [
			// General
			'nexeng_headless_mode'    => false,
			'nexeng_admin_bar_badge'  => true,
			'nexeng_auto_rebuild'     => true,
			'nexeng_analytics_enabled' => true,
			'nexeng_anonymize_ips'    => true,
			'nexeng_sitemap_enabled'  => true,
			'nexeng_schema_enabled'   => false,

			// SSG
			'nexeng_ssg_enabled'      => false,
			'nexeng_ssg_excluded_types' => '',
			'nexeng_ssg_script_hosts' => '',
			'nexeng_asset_mode'       => 'direct',

			// Staging auth
			'nexeng_http_auth_user'   => '',
			'nexeng_http_auth_pass'   => '',

			// CDN
			'nexeng_cdn_auto_purge'   => true,
			'nexeng_cdn_cf_zone_id'   => '',
			'nexeng_cdn_cf_api_token' => '',
			'nexeng_cdn_bunny_zone_id' => '',
			'nexeng_cdn_bunny_api_key' => '',
			'nexeng_cdn_url'          => '',
			'nexeng_max_cache_size_mb' => 500,

			// Security
			'nexeng_secure_users_api' => false,
			'nexeng_secure_author_enum' => false,
			'nexeng_secure_xmlrpc'    => false,
			'nexeng_secure_rest_tighten' => false,
			'nexeng_secure_rate_limit' => false,
			'nexeng_secure_strong_pass' => false,
			'nexeng_secure_login_rename' => false,
			'nexeng_secure_login_errors' => false,
			'nexeng_secure_remove_version' => false,
			'nexeng_secure_disable_file_edit' => false,
			'nexeng_secure_headers'   => false,
			'nexeng_secure_login_slug' => '',

			// Legacy (kept for backwards compat with older code paths)
			'nexeng_secure_files'     => false,
			'nexeng_ghost_protocol'   => false,
		];
	}

	/**
	 * Scans the WP cron option for any due NEXENG_SSG event. Avoids spending
	 * cycles dispatching cron when nothing's ripe yet. Returns true even
	 * for events overdue by 30 minutes (LocalWP no-traffic case) — they
	 * still need to fire.
	 */
	private function has_due_nexeng_cron(): bool {
		$crons = (array) get_option( 'cron', [] );
		$now   = time();
		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_int( $ts ) || $ts > $now + 5 ) {
				continue;
			}
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( [
				'nexeng_ssg_regen',
				'nexeng_ssg_delete',
				'nexeng_ssg_bulk_tick',
				'nexeng_ssg_global_invalidate',
			] as $hook ) {
				if ( isset( $hooks[ $hook ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Shutdown handler: closes the HTTP response, then dispatches every
	 * due NEXENG_SSG cron event directly via do_action() / unschedule pair.
	 * Bypasses wp_cron()'s `doing_cron` transient lock semantics so a
	 * stale lock from a previously-timed-out loopback can't silently skip
	 * the dispatch.
	 *
	 * Only handles events whose hook name starts with `nexeng_` so we don't
	 * accidentally trigger third-party cron handlers off a rail poll.
	 */
	public function dispatch_due_nexeng_cron(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$crons = (array) get_option( 'cron', [] );
		$now   = time();
		// Build the list of events to fire — DO NOT iterate the cron array
		// while modifying it; collect first, dispatch after.
		$to_fire = [];
		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_int( $ts ) || $ts > $now ) {
				continue;
			}
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $entries ) {
				if ( strpos( (string) $hook, 'nexeng_' ) !== 0 ) {
					continue;
				}
				foreach ( (array) $entries as $sig => $details ) {
					$to_fire[] = [
						'hook' => (string) $hook,
						'ts'   => (int) $ts,
						'args' => isset( $details['args'] ) ? array_values( (array) $details['args'] ) : [],
					];
				}
			}
		}

		// Cap at 10 events per dispatch run so a runaway queue can't keep
		// a PHP worker pinned indefinitely. The next rail poll will pick up
		// any remaining due events on the next 10-second cycle.
		$to_fire = array_slice( $to_fire, 0, 10 );

		foreach ( $to_fire as $event ) {
			// Unschedule first so do_action() can re-schedule itself
			// (delayed retries) without double-queueing.
			wp_unschedule_event( $event['ts'], $event['hook'], $event['args'] );
			do_action_ref_array( $event['hook'], $event['args'] );
		}
	}

	private function is_pro(): bool {
		if ( class_exists( 'NexoraEngine\\Licensing\\FeatureGate' ) ) {
			return \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' );
		}
		if ( class_exists( 'NEXENG_Licence' ) && method_exists( 'NEXENG_Licence', 'is_pro' ) ) {
			return (bool) NEXENG_Licence::is_pro();
		}
		return false;
	}

	private function resolve_plan(): string {
		if ( class_exists( 'NexoraEngine\\Licensing\\FeatureGate' ) ) {
			return (string) \NexoraEngine\Licensing\FeatureGate::get_plan();
		}
		return $this->is_pro() ? 'pro' : 'free';
	}

	private function upgrade_url(): string {
		if ( function_exists( 'NexoraEngine\\get_upgrade_url' ) ) {
			return (string) \NexoraEngine\get_upgrade_url( 'pro' );
		}
		if ( class_exists( 'NexoraEngine\\Licensing\\FeatureGate' ) && method_exists( 'NexoraEngine\\Licensing\\FeatureGate', 'get_upgrade_url' ) ) {
			return (string) \NexoraEngine\Licensing\FeatureGate::get_upgrade_url( 'pro' );
		}
		return 'https://auralogicslabs.com/products/nexora-engine/#pricing';
	}

	private function wizard_complete(): bool {
		if ( class_exists( 'NEXENG_Wizard' ) ) {
			return (bool) NEXENG_Wizard::get_instance()->is_completed();
		}
		return true;
	}
}
