<?php
/**
 * Nexora Engine — Init (Pre-Render Edition)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_Init {

    private static ?NEXENG_Init $instance = null;

    public static function get_instance(): NEXENG_Init {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the URL prefix to use for stealth proxy paths.
     *
     * Returns '' for hosts that route /_ncx_v12/... to PHP (Apache with WP
     * default .htaccess), or '/index.php' for hosts that intercept asset URLs
     * before reaching PHP (Nginx, LiteSpeed, IIS without rewrite, etc.).
     *
     * The /index.php prefix uses PATH_INFO routing — every WP-compatible host
     * is already configured to send /index.php/anything to PHP, regardless of
     * web server or .htaccess support. This is the universal fallback.
     *
     * Detection happens on activation + Headless toggle via probe_proxy_mode().
     * Mode is cached in the nexeng_proxy_mode option so we don't re-probe.
     */
    public static function proxy_prefix(): string {
        $mode = get_option( 'nexeng_proxy_mode', 'compat' );
        return $mode === 'clean' ? '' : '/index.php';
    }

    /**
     * Returns the asset delivery mode.
     *
     *   'direct' (default) — Asset URLs in HTML reference real /wp-content/
     *     paths. Browsers fetch directly via the web server (no PHP per
     *     asset). Fast on first visit and every visit. Wappalyzer can
     *     detect WordPress from the visible paths, but every other
     *     stealth signal (generator, REST link, body classes, headers,
     *     window.wp namespace, admin-ajax/wp-json proxying) stays intact.
     *
     *   'proxy' — Asset URLs go through /_ncx_v12/... → PHP → file. Hides
     *     /wp-content/ entirely but every asset bootstraps WordPress.
     *     Slow on first visit, fine on repeat (browser cache). Use when
     *     full URL stealth matters more than first-visit speed.
     *
     * Default 'direct' because page speed matters more for SaaS customers
     * than the last 5% of URL cloaking. Customers who want full cloak can
     * flip the toggle in admin → Headless → Asset delivery mode.
     */
    /**
     * Which asset-delivery mode is in force.
     *
     * 'proxy' (Stealth Proxy) is implemented entirely by NEXENG_Ghost_Pro, which
     * lives in a __premium_only file and is not part of the WordPress.org
     * package. When that class is absent there is nothing to rewrite asset URLs,
     * so proxy mode cannot function and this reports 'direct'.
     *
     * This is deliberately a capability test, not a licence test. Asking
     * is_pro() here would be a licence check inside a shipped file deciding what
     * a built-in feature may do — the pattern Guideline 5 prohibits. Asking
     * whether the implementing class exists is just a statement of fact about
     * this build.
     *
     * @return 'proxy'|'direct'
     */
    public static function asset_mode(): string {
        if ( 'proxy' !== get_option( 'nexeng_asset_mode', 'direct' ) ) {
            return 'direct';
        }
        return class_exists( 'NEXENG_Ghost_Pro' ) ? 'proxy' : 'direct';
    }

    private function __construct() {
        // Immediate Boot
        $this->handle_atomic_requests();

        // Phase 2 (SSG): detect HMAC-authenticated capture requests early so
        // the shell renderer bails and WP outputs raw HTML for snapshot.
        if ( class_exists( 'NEXENG_SSG' ) ) {
            $ssg = NEXENG_SSG::get_instance();
            $ssg->maybe_mark_capture_request();
            $ssg->register_hooks();
        }

        add_action( 'init', [ $this, 'init_admin' ] );
        add_action( 'init', [ $this, 'init_ghost_protocol' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render_shell' ], 1 );
        add_action( 'shutdown', [ $this, 'log_miss' ] );

        // Re-probe proxy mode when Headless Mode is flipped on (covers initial
        // setup + host migrations). Cheap if mode is already correct.
        add_action( 'update_option_nexeng_headless_mode', [ $this, 'on_headless_toggle' ], 10, 2 );
        add_action( 'add_option_nexeng_headless_mode',    [ $this, 'on_headless_added' ], 10, 2 );
    }

    public function on_headless_toggle( $old, $new ): void {
        if ( $new === 'on' && $old !== 'on' ) {
            $this->probe_proxy_mode();
        }
    }
    public function on_headless_added( $option, $value ): void {
        if ( $value === 'on' ) {
            $this->probe_proxy_mode();
        }
    }

    public function log_miss(): void {
        // Legacy path kept as a no-op. Miss logging is owned by
        // NEXENG_Analytics::track_php_render_miss(), which skips admin/logged-in
        // traffic, bots, REST/AJAX, and non-page requests before writing.
        return;
    }

    /**
     * Detects whether the host routes /_ncx_v12/* paths to PHP, and stores
     * the right URL prefix in the nexeng_proxy_mode option.
     *
     *   'clean'  → /_ncx_v12/assets/...           (Apache, .htaccess works)
     *   'compat' → /index.php/_ncx_v12/assets/... (Nginx, LiteSpeed, IIS)
     *
     * Self-loopback request to /_ncx_v12/__probe. If response body is
     * "ncx-probe-ok", the host routes the URL to PHP correctly.
     *
     * Defaults to 'compat' on any failure — universally safe.
     */
    public function probe_proxy_mode(): string {
        // Probe URL ends in .css to test whether the host's static-file
        // handler intercepts asset extensions before PHP gets a chance.
        // Nginx's typical asset location regex matches .css/.js/.png/etc.
        // and returns 404 from the filesystem — we want to detect that case.
        $probe_url = home_url( '/_ncx_v12/__probe.css' );
        $response  = wp_remote_get( $probe_url, [
            'timeout'    => 8,
            'sslverify'  => false,
            'redirection'=> 0,
            'user-agent' => 'NexoraProbe/1.0',
            'headers'    => [ 'X-Nexora-Probe' => '1' ],
        ] );

        $body = is_wp_error( $response ) ? '' : trim( wp_remote_retrieve_body( $response ) );
        $code = is_wp_error( $response ) ? 0  : (int) wp_remote_retrieve_response_code( $response );

        // Match our exact CSS-comment marker. Anything else (404, empty,
        // a CDN error page, etc.) means clean URLs won't work for assets.
        $mode = ( $code === 200 && strpos( $body, 'ncx-probe-ok' ) !== false ) ? 'clean' : 'compat';

        update_option( 'nexeng_proxy_mode', $mode );
        update_option( 'nexeng_proxy_mode_detected_at', time() );
        return $mode;
    }

    public function init_ghost_protocol(): void {
        if ( get_option( 'nexeng_headless_mode', 'off' ) !== 'on' ) {
            return;
        }

        if ( is_admin() ) return;

        // Logged-in users always see the original WP — they're editors,
        // previewers, or admins and they need fresh nonces, admin bar,
        // and unmodified body classes for builders to function.
        if ( $this->is_logged_in_visitor() ) return;

        // Defensive: also skip explicit builder/preview contexts even for
        // logged-out edge cases (some hosts cookie-strip in iframes).
        if ( $this->is_builder_context() ) return;

        // Purge WP Fingerprints from <head>
        remove_action( 'wp_head', 'feed_links', 2 );
        remove_action( 'wp_head', 'feed_links_extra', 3 );
        remove_action( 'wp_head', 'rsd_link' );
        remove_action( 'wp_head', 'wlwmanifest_link' );
        remove_action( 'wp_head', 'wp_generator' );
        remove_action( 'wp_head', 'wp_shortlink_wp_head' );
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
        remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );

        // V1.8.1: Remove REST API discovery link (exposes wp-json endpoint)
        remove_action( 'wp_head', 'rest_output_link_wp_head' );
        remove_action( 'wp_head', 'wp_resource_hints', 2 );

        add_filter( 'wp_img_tag_add_auto_sizes', '__return_false' );
        add_filter( 'show_admin_bar', '__return_false' );

        // Strip WordPress / theme fingerprints from body class.
        //
        // KEEP intact (functional, dynamic per site):
        //   elementor-kit-NN  → CSS variables hook for global colors/fonts/spacing
        //   elementor-page-NN → per-page CSS hook
        //   elementor-default → Elementor's theme reset
        //   elementor-page    → general Elementor pages
        //   e-*               → Elementor 3+ container/flex classes
        //
        // STRIP (pure WP fingerprints, no functional impact):
        //   wp-*, page-template-*, page-id-*, wp-theme-*, wp-child-theme-*
        //   plus the active theme's own slug class (resolved at runtime).
        $theme_slugs   = array_unique( array_filter( [ get_stylesheet(), get_template() ] ) );
        $strip_pattern = '/^(wp-|page-template|page-id-|wp-theme-|wp-child-theme)/i';

        add_filter( 'body_class', function( $classes ) use ( $strip_pattern, $theme_slugs ) {
            $allowed = [];
            foreach ( $classes as $class ) {
                if ( preg_match( $strip_pattern, $class ) ) continue;
                if ( in_array( $class, $theme_slugs, true ) ) continue;
                $allowed[] = $class;
            }
            return $allowed;
        }, 9999 );  // run last so we filter classes added by Elementor / theme
    }

    /**
     * Cheap logged-in detection that doesn't need pluggable.php to be loaded.
     * Looks for the WordPress auth cookie directly so it works at the
     * earliest hook (init) without bootstrapping the full auth stack.
     */
    private function is_logged_in_visitor(): bool {
        if ( function_exists( 'is_user_logged_in' ) && did_action( 'set_current_user' ) ) {
            if ( is_user_logged_in() ) {
                return true;
            }
        }
        // Cookie sniff fallback (works before set_current_user fires).
        foreach ( (array) ( $_COOKIE ?? [] ) as $name => $_ ) {
            if ( strpos( $name, 'wordpress_logged_in_' ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the current request is a page-builder edit/preview iframe
     * (Elementor, Beaver, Brizy, etc.). In those contexts the visual builder
     * relies on raw WP/Elementor markup, classes, and head scripts — our
     * Ghost Protocol and shell would break editing.
     */
    private function is_builder_context(): bool {
        // Elementor editor frame.
        if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
            return true;
        }
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'elementor' ) {
            return true;
        }
        // Elementor's own preview-mode helper (loaded after init).
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $ep = \Elementor\Plugin::$instance ?? null;
            if ( $ep && isset( $ep->preview ) && method_exists( $ep->preview, 'is_preview_mode' ) && $ep->preview->is_preview_mode() ) {
                return true;
            }
        }
        // Block editor preview, Customizer, generic preview.
        if ( isset( $_GET['preview'] ) || isset( $_GET['customize_changeset_uuid'] ) ) {
            return true;
        }
        // Other builders (defensive).
        if ( isset( $_GET['fl_builder'] ) || isset( $_GET['brizy-edit'] ) || isset( $_GET['brizy-edit-iframe'] ) ) {
            return true;
        }
        return false;
    }

    public function init_admin(): void {
        if ( is_admin() ) {
            NEXENG_Admin::get_instance();
        }

        // REST controller — always registered, gated by per-route capability checks.
        // Used by the React admin SPA for migrated pages. Legacy AJAX handlers
        // remain registered separately for non-migrated views.
        if ( class_exists( 'NEXENG_REST' ) ) {
            NEXENG_REST::get_instance();
        }
    }

    public function handle_atomic_requests(): void {
        $uri = NEXENG_Request::uri( '' );

        // Probe endpoint — used by probe_proxy_mode() to detect whether the
        // host routes asset-extension URLs under /_ncx_v12/ to PHP.
        //
        // The probe URL ends in ".css" intentionally: Nginx hosts typically
        // intercept .css/.js/.png/etc. via static-file location regex and
        // return 404 before reaching PHP. An extension-less probe URL would
        // pass through on those hosts, giving a false-positive "clean mode."
        if ( strpos( $uri, '_ncx_v12/__probe.css' ) !== false ) {
            if ( ob_get_level() ) ob_end_clean();
            header( 'Content-Type: text/css' );
            header( 'Cache-Control: no-store' );
            echo '/* ncx-probe-ok */';
            exit;
        }

        if ( strpos( $uri, '_ncx_v12/' ) !== false ) {
            // Advanced Ghost Protocol (stealth-proxy asset masking) is a Pro
            // feature. The companion class ships only in the Pro build; in the
            // free build it's absent, and free installs can never enter proxy
            // mode (NEXENG_REST::ssg_set_asset_mode enforces Pro server-side), so
            // no /_ncx_v12/ URLs are ever generated for them.
            if ( class_exists( 'NEXENG_Ghost_Pro' ) ) {
                NEXENG_Ghost_Pro::serve_stealth_asset( $uri );
            }
        }

        // Asset stealth — safe to serve immediately (no WP hooks needed).
        // Note: '_ncx_v12/' caught above; nothing more to do here.

        // ─── EARLY context flags ──────────────────────────────────────────
        // Plugins like Elementor Pro register their AJAX/REST hooks only when
        // DOING_AJAX / REST_REQUEST are true at init time. If we wait until
        // our deferred handler to set them, init has already fired and the
        // hooks were skipped → admin-ajax dispatches to nothing → HTTP 400.
        if ( strpos( $uri, '/_ncx/aj' ) !== false ) {
            if ( ! defined( 'DOING_AJAX' ) ) define( 'DOING_AJAX', true );
            if ( ! defined( 'WP_ADMIN' ) )    define( 'WP_ADMIN',   true );
        }
        if ( strpos( $uri, '/_ncx/api/' ) !== false ) {
            if ( ! defined( 'REST_REQUEST' ) ) define( 'REST_REQUEST', true );
        }

        // The actual dispatch must happen AFTER all plugin hooks register —
        // wp_loaded fires after init + plugins_loaded.
        $needs_defer =
            strpos( $uri, '/_ncx/aj' )         !== false ||
            strpos( $uri, '/_ncx/api/' )       !== false ||
            strpos( $uri, '/_ncx/nonce' )      !== false ||
            strpos( $uri, 'nexeng/v1/public/page' ) !== false;

        if ( $needs_defer ) {
            add_action( 'wp_loaded', [ $this, 'dispatch_deferred_proxies' ], 1 );
        }
    }

    /**
     * Runs at wp_loaded (after init, plugins_loaded). All AJAX handlers and
     * REST routes are registered by now, so dispatch is safe.
     */
    public function dispatch_deferred_proxies(): void {
        $uri = NEXENG_Request::uri( '' );

        if ( strpos( $uri, '/_ncx/nonce' ) !== false && class_exists( 'NEXENG_SSG' ) ) {
            NEXENG_SSG::get_instance()->handle_nonce_request();
        }
        if ( strpos( $uri, '/_ncx/aj' ) !== false ) {
            $this->handle_stealth_ajax();
        }
        if ( strpos( $uri, '/_ncx/api/' ) !== false ) {
            $this->handle_stealth_rest( $uri );
        }
        if ( strpos( $uri, 'nexeng/v1/public/page' ) !== false ) {
            $this->handle_rest_proxy( $uri );
        }
    }

    /**
     * Stealth proxy for admin-ajax.php.
     *
     * This used to `require ABSPATH . 'wp-admin/admin-ajax.php'`. Loading a core
     * file directly is not permitted: the path is not guaranteed on every
     * install (WP_ADMIN_DIR can move it), and pulling core in mid-request from
     * a front-end context is exactly the pattern that lets WordPress be tricked
     * into running admin code unauthenticated.
     *
     * The dispatch itself is small, so it is done here instead — the same
     * approach handle_stealth_rest() already takes for REST. Every wp_ajax_*
     * and wp_ajax_nopriv_* handler that is registered fires normally, and the
     * logged-in/logged-out split is preserved, which is the part that carries
     * the authorisation.
     */
    private function handle_stealth_ajax(): void {
        if ( ! defined( 'DOING_AJAX' ) ) define( 'DOING_AJAX', true );
        if ( ! defined( 'WP_ADMIN' ) )    define( 'WP_ADMIN', true );

        // Mirrors admin-ajax.php: no caching, and a default deny response.
        send_origin_headers();
        header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
        header( 'X-Robots-Tag: noindex' );
        nocache_headers();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The action's own handler is responsible for its nonce, exactly as it is under admin-ajax.php; this only routes.
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( '' === $action ) {
            wp_die( '0', '', [ 'response' => 400 ] );
        }

        do_action( 'admin_init' );

        if ( is_user_logged_in() ) {
            do_action( 'wp_ajax_' . $action );
        } else {
            do_action( 'wp_ajax_nopriv_' . $action );
        }

        // Reached only when no handler claimed the action — same as core.
        wp_die( '0' );
    }

    /**
     * Stealth proxy for /wp-json/. Internally dispatches the REST request via
     * WP_REST_Server — no HTTP round-trip, full Elementor compatibility.
     */
    private function handle_stealth_rest( string $uri ): void {
        if ( ! preg_match( '#/_ncx/api/(.*?)(?:\?.*)?$#', $uri, $m ) ) return;
        $route = '/' . ltrim( $m[1], '/' );

        // Ensure REST routes are registered.
        if ( ! did_action( 'rest_api_init' ) ) {
            rest_get_server();
        }

        $method = NEXENG_Request::method();
        $body   = file_get_contents( 'php://input' ) ?: '';

        $request = new WP_REST_Request( $method, $route );
        $request->set_query_params( wp_unslash( $_GET ) );
        $request->set_body_params( wp_unslash( $_POST ) );
        $request->set_body( $body );

        $headers = function_exists( 'getallheaders' ) ? getallheaders() : [];
        foreach ( (array) $headers as $name => $val ) {
            $request->set_header( $name, $val );
        }
        $content_type = (string) $request->get_header( 'content-type' );
        if ( $body !== '' && stripos( $content_type, 'application/json' ) !== false ) {
            $json = json_decode( $body, true );
            if ( is_array( $json ) ) {
                $request->set_body_params( $json );
            }
        }
        // Default content-type when missing for compatibility with Elementor's posts.
        if ( ! $request->get_header( 'content-type' ) && ! empty( $_POST ) ) {
            $request->set_header( 'content-type', 'application/x-www-form-urlencoded' );
        }

        $server   = rest_get_server();
        $response = $server->dispatch( $request );
        $data     = $server->response_to_data( $response, false );

        if ( ob_get_level() ) ob_end_clean();
        status_header( $response->get_status() );
        header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
        header( 'Cache-Control: no-store, private' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        if ( $route === '/nexeng/v1/vitals' ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type' );
        }
        echo wp_json_encode( $data );
        exit;
    }

    private function handle_rest_proxy( string $uri ): void {
        if ( preg_match( '/public\/page\/?(.*)$/i', $uri, $matches ) ) {
            $path = explode('?', $matches[1])[0];
            $path = trim($path, '/');

            error_reporting(0);
            @ini_set('display_errors', 0);

            if ( ! defined( 'NEXORA_MIRRORING' ) ) define( 'NEXORA_MIRRORING', true );

            $headless = NEXENG_Headless::get_instance();
            $data = $headless->get_page_data_directly($path);
            
            if ( ob_get_level() ) ob_end_clean();

            header('Content-Type: application/json; charset=UTF-8');
            header('Access-Control-Allow-Origin: *');
            echo json_encode($data);
            exit;
        }
    }

    public function maybe_render_shell(): void {
        // SAFETY: If we are in Mirror mode OR Render Master mode OR SSG capture, ABORT to get raw HTML
        if ( defined( 'NEXORA_MIRRORING' ) || defined( 'NEXORA_CAPTURE' ) || isset( $_GET['nexeng_render_master'] ) ) {
            return;
        }

        if ( get_option( 'nexeng_headless_mode', 'off' ) !== 'on' ) {
            return;
        }

        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || strpos( NEXENG_Request::uri( '' ), 'wp-login' ) !== false ) {
            return;
        }

        if ( isset( $_GET['nexeng_raw'] ) || isset( $_GET['classic'] ) ) {
            return;
        }

        // Logged-in users always see live WP (editors, admins, previewers).
        if ( $this->is_logged_in_visitor() ) {
            return;
        }

        // Defensive: builder contexts even for the logged-out edge case.
        if ( $this->is_builder_context() ) {
            return;
        }

        // Query-driven pages (search results, archives, 404) have no single
        // post to fetch — the shell wrapper would render empty. Let WP's
        // normal template chain handle these so Elementor Theme Builder
        // templates (Search Results, Archive, 404) display correctly.
        if ( is_search() || is_404() || is_archive() ) {
            return;
        }

        // ── PREFLIGHT: only commit to the headless shell if it can actually
        //    produce a styled page. ───────────────────────────────────────────
        // The shell renders the page body from a loopback capture of the live
        // page. If that capture isn't available (mirror just purged, loopback
        // blocked, host can't reach itself, render error) the shell would emit
        // an empty #nexora-root — a content-less, CSS-less page that looks
        // broken. We resolve the render HERE, BEFORE touching wp_head() or
        // dequeuing Elementor, so a failure cleanly falls through to normal
        // WordPress rendering and the visitor always sees a correct page.
        $render = $this->resolve_shell_render( (int) get_the_ID() );
        if ( empty( $render['html'] ) ) {
            return; // Graceful fallback → WordPress renders the page normally.
        }

        // Hand the pre-resolved render to the shell so it doesn't fetch twice.
        $GLOBALS['nexeng_shell_render'] = $render;
        include NEXENG_PLUGIN_DIR . 'includes/shell-template.php';
        exit;
    }

    /**
     * Resolve the page-body render for the headless shell.
     *
     * Returns a cached render when available, otherwise performs ONE loopback
     * capture of the live page and caches it (only on success). On any failure
     * it returns an empty 'html' so the caller can fall back to normal WP
     * rendering instead of emitting a broken shell.
     *
     * @param int $post_id Current post ID.
     * @return array{html:string,body_class:string,lcp:string}
     */
    private function resolve_shell_render( int $post_id ): array {
        $empty = [ 'html' => '', 'body_class' => '', 'lcp' => '' ];
        if ( $post_id <= 0 ) {
            return $empty;
        }

        $cache_key = 'nexeng_shell_body_' . get_current_blog_id() . '_' . $post_id;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached['html'] ) ) {
            return [
                'html'       => $cached['html'],
                'body_class' => $cached['body_class'] ?? '',
                'lcp'        => $cached['lcp'] ?? '',
            ];
        }

        $url = add_query_arg( 'nexeng_render_master', '1', get_permalink( $post_id ) );
        $res = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => false ] );
        if ( is_wp_error( $res ) ) {
            return $empty;
        }

        $body_full  = (string) wp_remote_retrieve_body( $res );
        $html       = '';
        $body_class = '';
        $lcp        = '';
        if ( preg_match( '/<body[^>]*class=["\']([^"\']+)["\'][^>]*>/is', $body_full, $m ) ) {
            $body_class = $m[1];
        }
        if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $body_full, $m ) ) {
            $html = $m[1];
        }

        // No usable body → signal failure so the caller falls back to WP.
        if ( '' === $html ) {
            return $empty;
        }

        // Detect the LCP candidate for <head> preload.
        if ( preg_match( '/<img[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $im ) ) {
            $lcp = $im[1];
        } elseif ( preg_match( '/background-image\s*:\s*url\(["\']?([^"\')]+)/i', $html, $bg ) ) {
            $lcp = $bg[1];
        }

        // Cache ONLY a successful render (24h TTL, invalidated on save_post).
        set_transient( $cache_key, [
            'html'       => $html,
            'body_class' => $body_class,
            'lcp'        => $lcp,
        ], DAY_IN_SECONDS );

        return [ 'html' => $html, 'body_class' => $body_class, 'lcp' => $lcp ];
    }
}
