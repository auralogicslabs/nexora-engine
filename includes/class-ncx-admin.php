<?php
/**
 * Nexora Engine — Admin Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // Brand menu icon — runs on every admin screen (the top-level menu is
        // always visible), so it can't live inside the page-guarded enqueue_assets.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_menu_icon' ] );

        // Register AJAX Handlers
        $ajax_actions = [
            'run_full_scan', 'scan_single_page', 'generate_api_key',
            'test_api_connection', 'resolve_issue', 'ignore_issue',
            'save_settings', 'clear_cache', 'dismiss_banner',
            'add_redirect', 'delete_redirect', 'toggle_redirect', 'export_redirects', 'reset_scan_data',
            // Phase 2 SSG
            'ssg_toggle', 'ssg_regen_one', 'ssg_regen_all_start',
            'ssg_regen_archives_start',
            'ssg_regen_all_batch', 'ssg_purge', 'ssg_stats',
            'ssg_save_exclusions', 'ssg_list', 'ssg_delete_one',
            'ssg_set_asset_mode',
            // Bulk control (stop / pause / resume / pending-only regen / pre-flight / nginx config / retry errors)
            'ssg_bulk_stop', 'ssg_bulk_pause', 'ssg_bulk_resume', 'ssg_regen_pending',
            'ssg_preflight', 'ssg_nginx_config', 'ssg_retry_errors',
            // Maintenance Tools
            'flush_permalinks', 'purge_analytics', 'export_settings',
            // Wizard
            'wizard_activate', 'wizard_disable_conflict', 'wizard_check_diag', 'wizard_finish', 'wizard_reset_completion',
            // Telemetry & Stats
            'ttfb_beacon',
            'get_neural_pulse',
            // Dashboard (handled by class-ncx-dashboard.php separately)
            'regenerate_all', 'purge_cache',
            // Portal connectivity
            'portal_connect', 'portal_disconnect', 'portal_sync', 'regenerate_portal_token',
            // Licensing recovery (Tools page)
            'licensing_clear_cache', 'licensing_get_state', 'licensing_reset_sandbox',
            // Headless page banners
            'dismiss_ghost_banner',
            // Pro-upgrade post-activation regen banner
            'dismiss_pro_regen_banner',
            // Live pending queue (Build Control live-poll)
            'get_pending_list',
            // Remove a single item from the pending queue (dismiss button)
            'ssg_remove_pending',
            // Clear the entire pending queue (escape hatch for stuck queues)
            'ssg_clear_all_pending',
            // One-click exclude a page from future static captures (from error rows)
            'ssg_exclude_page',
            // CDN integration
            'cdn_test_cloudflare', 'cdn_purge_all',
        ];
        // Public nopriv actions — only telemetry beacons fired from the front-end.
        // All management/destructive actions are admin-only (verify_request enforces
        // check_ajax_referer + manage_options, but still best-practice to skip nopriv).
        $nopriv_actions = [ 'ttfb_beacon' ];

        foreach ( $ajax_actions as $action ) {
            add_action( "wp_ajax_nexeng_{$action}", [ $this, "handle_{$action}" ] );
            if ( in_array( $action, $nopriv_actions, true ) ) {
                add_action( "wp_ajax_nopriv_nexeng_{$action}", [ $this, "handle_{$action}" ] );
            }
        }

        add_action( 'admin_init', [ $this, 'maybe_redirect_to_wizard' ] );
        add_action( 'admin_init', [ $this, 'maybe_handle_wizard_reset' ] );
        add_action( 'admin_init', [ $this, 'maybe_handle_portal_callback' ] );
        add_action( 'admin_bar_menu', [ $this, 'render_admin_bar_build_status' ], 80 );
        add_action( 'wp_head', [ $this, 'print_admin_bar_build_styles' ] );
        add_action( 'admin_head', [ $this, 'print_admin_bar_build_styles' ] );

        // Form-based redirect save (admin-post.php, fired before any output).
        add_action( 'admin_post_nexeng_save_redirects', [ $this, 'handle_save_redirects' ] );
        add_action( 'admin_post_nexeng_regen_pending_admin_bar', [ $this, 'handle_admin_bar_regen_pending' ] );
    }

    /**
     * Redirects to the wizard if it's the first run.
     */
    public function maybe_redirect_to_wizard() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        // Never trap a Super Admin inside the per-site wizard when they're
        // in the network admin context — network controls live in the
        // separate "Nexora Fleet" menu and don't depend on the per-site
        // wizard state.
        if ( function_exists( 'is_network_admin' ) && is_network_admin() ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $wizard = NEXENG_Wizard::get_instance();
        if ( $wizard->is_completed() ) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing, no state change
        $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );

        // Only intercept Nexora Engine pages — never block core WP or other plugins.
        // Nexora pages are: 'nexora' (main dashboard) and anything starting with 'ncx-'.
        $is_nexora_page = ( $page === 'nexora' || strpos( $page, 'ncx-' ) === 0 );
        if ( ! $is_nexora_page ) return;

        // Already on the wizard — don't loop.
        if ( $page === 'ncx-wizard' ) return;

        wp_safe_redirect( admin_url( 'admin.php?page=ncx-wizard' ) );
        exit;
    }

    /**
     * Reset wizard completion when the admin explicitly re-runs setup.
     */
    public function maybe_handle_wizard_reset() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below when reset flag is present
        $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
        if ( $page !== 'ncx-wizard' ) {
            return;
        }
        if ( empty( $_GET['nexeng_reset_wizard'] ) ) {
            return;
        }

        check_admin_referer( 'nexeng_reset_wizard' );

        NEXENG_Wizard::get_instance()->reset_completion();
        set_transient( 'nexeng_wizard_just_reset', 1, 30 );

        wp_safe_redirect( admin_url( 'admin.php?page=ncx-wizard' ) );
        exit;
    }

    /**
     * Handles the portal callback redirect: ncx-updates?nexeng_connected=1
     * Validates the handshake completed, sets a display transient, then
     * redirects to the clean URL so a page refresh won't retrigger the notice.
     */
    public function maybe_handle_portal_callback() {
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        // Accept the callback on EITHER the legacy ncx-updates page or the new
        // ncx-portal page — portal-side may send users to either depending on
        // when its rollout is updated to honor the `return_to` hint we send.
        $page = $_GET['page'] ?? '';
        if ( $page !== 'ncx-updates' && $page !== 'ncx-portal' ) return;
        if ( ( $_GET['nexeng_connected'] ?? '' ) !== '1' ) return;

        // Verify the handshake: accept_callback() returns false when no
        // handshake was ever initiated (e.g., crafted URL). Silently abort
        // so a forged ?nexeng_connected=1 cannot mark the site as connected.
        $verified = true; // safe default when Portal API class is absent
        if ( class_exists( 'NEXENG_Portal_API' ) ) {
            $verified = NEXENG_Portal_API::accept_callback();
        }

        if ( ! $verified ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ncx-updates' ) );
            exit;
        }

        update_option( 'nexeng_portal_connected', time(), false );
        set_transient( 'nexeng_portal_just_connected', 1, 60 );

        // After a successful handshake, send the user to the Portal page
        // so they see the connected state on the page they actually launched
        // the flow from — not the License page.
        wp_safe_redirect( admin_url( 'admin.php?page=ncx-portal' ) );
        exit;
    }

    public function render_admin_bar_build_status( WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'NEXENG_SSG' ) || ! NEXENG_SSG::is_enabled() ) {
            return;
        }

        $ssg     = NEXENG_SSG::get_instance();
        $pending = method_exists( $ssg, 'pending_count' ) ? (int) $ssg->pending_count() : 0;
        $bulk    = method_exists( $ssg, 'bulk_status' ) ? $ssg->bulk_status() : [];
        $running = ! empty( $bulk['running'] ) && empty( $bulk['done'] );
        $paused  = ! empty( $bulk['paused'] );

        if ( ! $running && $pending <= 0 && ! is_admin() ) {
            return;
        }

        $monitor_url = admin_url( 'admin.php?page=ncx-dashboard#ncxRegenProgressPanel' );
        $class       = 'ncx-adminbar-build';
        $label       = __( 'Nexora: Static OK', 'nexora-engine' );

        if ( $paused ) {
            $class .= ' is-paused';
            $label = __( 'Nexora: Build Paused', 'nexora-engine' );
        } elseif ( $running ) {
            $processed = (int) ( $bulk['processed'] ?? 0 );
            $total     = (int) ( $bulk['total'] ?? 0 );
            $label     = $total > 0
                /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                ? sprintf( __( 'Nexora: Building %1$d/%2$d', 'nexora-engine' ), $processed, $total )
                : __( 'Nexora: Building', 'nexora-engine' );
            $class .= ' is-running';
        } elseif ( $pending > 0 ) {
            $label = sprintf(
                /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                _n( 'Nexora: %d update ready', 'Nexora: %d updates ready', $pending, 'nexora-engine' ),
                $pending
            );
            $class .= ' has-pending';
        }

        $wp_admin_bar->add_node( [
            'id'    => 'ncx-build-status',
            'title' => '<span class="ab-icon dashicons dashicons-update" aria-hidden="true"></span><span class="ab-label">' . esc_html( $label ) . '</span>',
            'href'  => $pending > 0 && ! $running && ! $paused ? $this->admin_bar_regen_pending_url() : $monitor_url,
            'meta'  => [
                'class' => $class,
                'title' => __( 'Nexora static mirror build status', 'nexora-engine' ),
            ],
        ] );

        if ( $pending > 0 && ! $running && ! $paused ) {
            $wp_admin_bar->add_node( [
                'id'     => 'ncx-build-refresh-pending',
                'parent' => 'ncx-build-status',
                'title'  => sprintf(
                    /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                    _n( 'Refresh %d changed page', 'Refresh %d changed pages', $pending, 'nexora-engine' ),
                    $pending
                ),
                'href'   => $this->admin_bar_regen_pending_url(),
            ] );
        }

        $wp_admin_bar->add_node( [
            'id'     => 'ncx-build-open-control',
            'parent' => 'ncx-build-status',
            'title'  => __( 'Open Build Control', 'nexora-engine' ),
            'href'   => $monitor_url,
        ] );

    }

    private function admin_bar_regen_pending_url(): string {
        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = is_admin() ? admin_url( 'admin.php?page=ncx-dashboard#ncxRegenProgressPanel' ) : $this->current_request_url();
        }

        return wp_nonce_url(
            add_query_arg(
                [
                    'action'      => 'nexeng_regen_pending_admin_bar',
                    'redirect_to' => $redirect,
                ],
                admin_url( 'admin-post.php' )
            ),
            'nexeng_regen_pending_admin_bar'
        );
    }

    private function current_request_url(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = ( NEXENG_Request::host() ?: wp_parse_url( home_url(), PHP_URL_HOST ) );
        $uri    = NEXENG_Request::uri();
        return esc_url_raw( $scheme . '://' . $host . $uri );
    }

    public function print_admin_bar_build_styles(): void {
        static $printed = false;
        if ( $printed || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $printed = true;
        ?>
        <?php ob_start(); ?>
            #wpadminbar #wp-admin-bar-ncx-build-status > .ab-item { display:flex; align-items:center; gap:4px; }
            #wpadminbar #wp-admin-bar-ncx-build-status .ab-icon { top:2px; font-family:dashicons; font-size:16px; margin:0 2px 0 0; }
            #wpadminbar #wp-admin-bar-ncx-build-status.has-pending > .ab-item { background:#f59e0b; color:#111827; }
            #wpadminbar #wp-admin-bar-ncx-build-status.is-running > .ab-item { background:#0252fa; color:#fff; }
            #wpadminbar #wp-admin-bar-ncx-build-status.is-paused > .ab-item { background:#475569; color:#fff; }
            #wpadminbar #wp-admin-bar-ncx-build-status.has-pending .ab-icon,
            #wpadminbar #wp-admin-bar-ncx-build-status.is-running .ab-icon,
            #wpadminbar #wp-admin-bar-ncx-build-status.is-paused .ab-icon { color:inherit; }
        <?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>
        <?php
    }

    public function handle_admin_bar_regen_pending(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'nexora-engine' ) );
        }
        check_admin_referer( 'nexeng_regen_pending_admin_bar' );

        $redirect = isset( $_GET['redirect_to'] ) ? rawurldecode( wp_unslash( $_GET['redirect_to'] ) ) : '';
        if ( ! $redirect ) {
            $redirect = admin_url( 'admin.php?page=ncx-dashboard#ncxRegenProgressPanel' );
        }

        $status = 'empty';
        if ( class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled() ) {
            $ssg  = NEXENG_SSG::get_instance();
            $bulk = $ssg->bulk_status();
            if ( ! empty( $bulk['running'] ) && empty( $bulk['done'] ) ) {
                $status = 'running';
            } elseif ( $ssg->pending_count() > 0 ) {
                $count  = $ssg->bulk_start_pending();
                $status = $count > 0 ? 'queued' : 'empty';
                if ( $count > 0 && ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
                    wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
                }
            }
        }

        wp_safe_redirect( add_query_arg( 'nexeng_build', $status, $redirect ) );
        exit;
    }

    public function register_menu() {
        $count = $this->get_critical_issue_count();
        $badge = $count > 0 ? " <span class='update-plugins count-{$count}'><span class='plugin-count'>{$count}</span></span>" : "";
        $label = class_exists( 'NEXENG_White_Label' ) ? NEXENG_White_Label::brand_name() : 'Nexora Engine';

        // Single top-level menu item — the React SPA owns its own sidebar
        // navigation across every sub-page, so duplicating those routes in the
        // WordPress sidebar would feel like a "normal WP plugin" (10 entries
        // under one parent) and undercut the modern app feel. We register
        // each route as a *hidden* submenu so admin.php?page=ncx-<slug> URLs
        // still resolve, but they never show in wp-admin.
        // Icon is painted by CSS (enqueue_menu_icon) so the Nexora brand mark
        // renders crisply at 20x20 in the admin menu — matching Nexora Pulse.
        add_menu_page(
            $label, $label . $badge, 'manage_options', 'nexora',
            [ $this, 'render_dashboard' ], 'none', 58
        );

        // Register every submenu unconditionally. The React app shows Pro
        // items in its sidebar even on Free (with a lock badge), so the
        // URL must resolve — otherwise free users clicking SEO Report /
        // Security / Redirects would land on a blank wp-admin page. The
        // React page handles the upgrade prompt; we just need the route
        // to be reachable. `manage_options` is still enforced in the
        // render callback below.
        $submenus = [
            'dashboard'    => 'Dashboard',
            'headless'     => 'Static Delivery',
            'seo-report'   => 'SEO Report',
            'security'     => 'Security',
            'redirects'    => 'Redirect Manager',
            // 'portal' hidden for now — separate cloud feature, deferred.
            'tools'        => 'Tools',
            'settings'     => 'Settings',
            'addons'       => 'Addons',
            'updates'      => 'License',
        ];

        foreach ( $submenus as $slug => $label ) {
            // Hidden submenu: parent_slug = null. The page renders normally
            // when requested via admin.php?page=ncx-<slug>, but the entry is
            // suppressed from the WordPress sidebar so users navigate
            // exclusively through the React app's own dark-blue sidebar.
            add_submenu_page(
                null, $label, $label, 'manage_options',
                'ncx-' . $slug, [ $this, 'render_' . str_replace( '-', '_', $slug ) ]
            );
        }

        // Wizard — also hidden (was already null-parented).
        add_submenu_page(
            null, 'Setup Wizard', 'Setup Wizard', 'manage_options',
            'ncx-wizard', [ $this, 'render_wizard' ]
        );

        // The top-level menu's own slug (page=nexora) maps to the Dashboard
        // callback, so the auto-created duplicate submenu entry it generates
        // is removed regardless.
        remove_submenu_page( 'nexora', 'nexora' );
    }

    /**
     * Paint the Nexora brand mark as the top-level admin-menu icon.
     *
     * The menu was registered with icon 'none', so we set the icon here with a
     * CSS background-image (20x20) the same way Nexora Pulse does. This keeps
     * the brand consistent across the Nexora family and renders crisply on
     * every admin theme. Output goes through wp_add_inline_style so WordPress
     * owns it (no echoed <style> tag — wp.org guideline).
     */
    public function enqueue_menu_icon() {
        $icon = esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/nexora-icon.png' );
        $css  = '#adminmenu #toplevel_page_nexora .wp-menu-image{'
              . 'background:url("' . $icon . '") center center no-repeat !important;'
              . 'background-size:20px 20px !important;}'
              . '#adminmenu #toplevel_page_nexora .wp-menu-image img,'
              . '#adminmenu #toplevel_page_nexora .wp-menu-image:before{display:none !important;}';
        wp_register_style( 'nexora-engine-menu-icon', false, [], defined( 'NEXORA_ENGINE_VERSION' ) ? NEXORA_ENGINE_VERSION : '1.0.0' );
        wp_enqueue_style( 'nexora-engine-menu-icon' );
        wp_add_inline_style( 'nexora-engine-menu-icon', $css );
    }

    public function enqueue_assets( $hook ) {
        $is_nexora_page = strpos( $hook, 'nexora' ) !== false || strpos( $hook, 'ncx-' ) !== false;
        $is_editor_page = in_array( $hook, [ 'post.php', 'post-new.php' ], true );
        if ( ! $is_nexora_page && ! $is_editor_page ) return;

        $url = plugin_dir_url( dirname( __FILE__ ) );
        $css_file = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/admin.css';
        $js_file  = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/admin.js';
        $css_version = file_exists( $css_file ) ? filemtime( $css_file ) : '1.0';
        $js_version  = file_exists( $js_file ) ? filemtime( $js_file ) : '1.0';

        // ── Detect which view is being rendered so we can decide React vs PHP ──
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing
        $page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // Top-level menu URL is ?page=nexora — alias it to the Dashboard view
        // so the React bundle still enqueues when the user clicks the
        // primary "Nexora Engine" sidebar entry. Without this alias, the
        // submenus-hidden patch leaves users on a blank admin page.
        $view_slug   = ( $page === 'nexora' ) ? 'dashboard' : preg_replace( '/^ncx-/', '', $page );
        $is_react_page = $is_nexora_page && in_array( $view_slug, $this->react_migrated_views, true );

        if ( $is_react_page ) {
            // ── React SPA bundle ─────────────────────────────────────
            $spa_js  = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/dist/nexora-engine.js';
            $spa_css = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/dist/nexora-engine.css';
            if ( file_exists( $spa_js ) ) {
                $spa_version = (string) filemtime( $spa_js );
                if ( file_exists( $spa_css ) ) {
                    wp_enqueue_style( 'nexora-engine-spa', $url . 'assets/dist/nexora-engine.css', [], $spa_version );
                }
                wp_enqueue_script( 'nexora-engine-spa', $url . 'assets/dist/nexora-engine.js', [], $spa_version, true );

                // Stamp an install ID once so the React app can detect fresh
                // installs and wipe stale localStorage on reinstall.
                if ( ! get_option( 'nexeng_install_id' ) ) {
                    update_option( 'nexeng_install_id', wp_generate_uuid4(), false );
                }

                wp_localize_script( 'nexora-engine-spa', 'NexoraEngine', [
                    'apiUrl'    => rest_url( 'nexora-engine/v1/' ),
                    'nonce'     => wp_create_nonce( 'wp_rest' ),
                    'adminUrl'  => admin_url(),
                    'siteUrl'   => get_site_url(),
                    'pluginUrl' => $url,
                    'version'   => defined( 'NEXORA_ENGINE_VERSION' ) ? NEXORA_ENGINE_VERSION : '',
                    'installId' => (string) get_option( 'nexeng_install_id', '' ),
                    'onboardingComplete' => (bool) get_user_meta( get_current_user_id(), 'nexeng_onboarding_complete', true ),
                    'currentView' => $view_slug,
                    'plan'      => class_exists( '\NexoraEngine\Licensing\FeatureGate' )
                                     ? \NexoraEngine\Licensing\FeatureGate::get_plan()
                                     : 'free',
                    'isPro'     => class_exists( '\NexoraEngine\Licensing\FeatureGate' )
                                     && \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' ),
                    // What this BUILD can do, as opposed to what this licence
                    // permits. Freemius strips the __premium_only files from the
                    // WordPress.org download, so on a free build these classes do
                    // not exist and the corresponding code is simply absent.
                    //
                    // The SPA must key its controls on these flags, never on
                    // isPro: a control the build cannot honour has to be absent
                    // or inert-by-description, not a disabled switch over code
                    // that shipped. isPro remains only for upgrade copy and
                    // pricing links, which are about the plan, not the build.
                    'can'       => [
                        'autoRebuild'  => class_exists( 'NEXENG_SSG_Auto' ),
                        'seoPerPost'   => class_exists( 'NEXENG_SEO_Pro' ),
                        'hardeningPro' => class_exists( 'NEXENG_Hardening_Pro' ),
                        'stealthProxy' => class_exists( 'NEXENG_Ghost_Pro' ),
                        'scheduler'    => class_exists( 'NEXENG_Scheduler' ),
                        'redirects'    => class_exists( 'NEXENG_Redirect_Manager' ),
                        'portal'       => class_exists( 'NEXENG_Portal_API' ),
                        'cdn'          => class_exists( 'NEXENG_CDN' ),
                        'whiteLabel'   => class_exists( 'NEXENG_White_Label' ),
                        'multisite'    => class_exists( 'NEXENG_Multisite' ),
                        'vitals'       => class_exists( 'NEXENG_GSC' ),
                        'pdfReport'    => class_exists( 'NEXENG_PDF_Report' ),
                    ],
                    'upgradeUrl' => function_exists( 'NexoraEngine\\get_upgrade_url' )
                                     ? \NexoraEngine\get_upgrade_url( 'pro' )
                                     : 'https://auralogicslabs.com/products/nexora-engine/#pricing',
                    'siblings'  => [
                        'pulse'  => defined( 'NEXORA_PULSE_VERSION' ) || file_exists( WP_PLUGIN_DIR . '/nexora-pulse/nexora-pulse.php' ),
                        'media'  => defined( 'NXM_VERSION' ) || file_exists( WP_PLUGIN_DIR . '/nexora-media/nexora-media.php' ),
                    ],
                    'user'      => [
                        'id'    => get_current_user_id(),
                        'name'  => wp_get_current_user()->display_name,
                        'email' => wp_get_current_user()->user_email,
                    ],
                    // Admin-bar build-status labels. The node is rendered
                    // server-side once per page load, and the SPA rewrites it as
                    // the build progresses; without these the label would revert
                    // to English on a translated site the moment it updated.
                    // %1$d/%2$d and %d are substituted in JS.
                    'adminBarLabels' => [
                        'ok'          => __( 'Nexora: Static OK', 'nexora-engine' ),
                        'paused'      => __( 'Nexora: Build Paused', 'nexora-engine' ),
                        'building'    => __( 'Nexora: Building', 'nexora-engine' ),
                        /* translators: 1: pages processed, 2: total pages. */
                        'buildingOf'  => __( 'Nexora: Building %1$d/%2$d', 'nexora-engine' ),
                        /* translators: %d: number of pages awaiting rebuild. */
                        'pendingOne'  => __( 'Nexora: %d update ready', 'nexora-engine' ),
                        /* translators: %d: number of pages awaiting rebuild. */
                        'pendingMany' => __( 'Nexora: %d updates ready', 'nexora-engine' ),
                        /* translators: %d: number of changed pages. */
                        'refreshOne'  => __( 'Refresh %d changed page', 'nexora-engine' ),
                        /* translators: %d: number of changed pages. */
                        'refreshMany' => __( 'Refresh %d changed pages', 'nexora-engine' ),
                    ],
                ] );
                return;  // React-only page — don't enqueue legacy admin assets too
            }
        }

        // ── Legacy PHP admin assets (still needed for non-migrated pages) ──
        if ( $is_nexora_page ) {
            wp_enqueue_style( 'ncx-admin', $url . 'assets/css/admin.css', [], $css_version );
        }
        wp_enqueue_script( 'ncx-admin', $url . 'assets/js/admin.js', [ 'jquery' ], $js_version, true );

        wp_localize_script( 'ncx-admin', 'ncxVars', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'nexeng_dashboard' ),
            'settingsUrl' => admin_url( 'admin.php?page=ncx-settings' ),
            'plan'        => class_exists( '\NexoraEngine\Licensing\FeatureGate' )
                                 ? \NexoraEngine\Licensing\FeatureGate::get_plan()
                                 : 'free',
        ] );
    }

    private function get_admin_nav_items() {
        return [
            'dashboard'    => [ 'label' => __( 'Dashboard', 'nexora-engine' ),       'section' => __( 'Operate', 'nexora-engine' ),  'icon' => 'dashicons-dashboard',     'pro' => false ],
            'headless'     => [ 'label' => __( 'Static Delivery', 'nexora-engine' ), 'section' => __( 'Operate', 'nexora-engine' ),  'icon' => 'dashicons-cloud',         'pro' => false ],
            // pages-report removed — embedded inside Static Delivery
            // 'pro' here means "this screen has nothing to show without the Pro
            // module installed", NOT "this screen is for paying customers".
            // Security and SEO Report both own free functionality — the five
            // shipping guards and the XML sitemap — so hiding them behind the
            // licence left free users unable to reach features they have.
            // Redirect Manager is genuinely empty without its stripped file.
            'seo-report'   => [ 'label' => __( 'SEO Report', 'nexora-engine' ),      'section' => __( 'Validate', 'nexora-engine' ), 'icon' => 'dashicons-search',        'pro' => false ],
            'security'     => [ 'label' => __( 'Security', 'nexora-engine' ),        'section' => __( 'Protect', 'nexora-engine' ),  'icon' => 'dashicons-shield-alt',    'pro' => false ],
            'redirects'    => [ 'label' => __( 'Redirect Manager', 'nexora-engine' ), 'section' => __( 'Protect', 'nexora-engine' ),  'icon' => 'dashicons-randomize',     'pro' => class_exists( 'NEXENG_Redirect_Manager' ) ? false : true ],
            'tools'        => [ 'label' => __( 'Tools', 'nexora-engine' ),           'section' => __( 'Manage', 'nexora-engine' ),   'icon' => 'dashicons-admin-tools',   'pro' => false ],
            'settings'     => [ 'label' => __( 'Settings', 'nexora-engine' ),        'section' => __( 'Manage', 'nexora-engine' ),   'icon' => 'dashicons-admin-generic', 'pro' => false ],
            'addons'       => [ 'label' => __( 'Addons', 'nexora-engine' ),          'section' => __( 'Manage', 'nexora-engine' ),   'icon' => 'dashicons-admin-plugins',  'pro' => false ],
            'updates'      => [ 'label' => __( 'License', 'nexora-engine' ),         'section' => __( 'Manage', 'nexora-engine' ),   'icon' => 'dashicons-update',        'pro' => false ],
        ];
    }

    /**
     * Returns the Nexora ecosystem addon registry with live status for each addon.
     * Called from the addons.php view — exposed as public so the view can access it.
     *
     * Status values: 'active' | 'installed' | 'not-installed' | 'coming-soon'
     */
    public function get_addon_registry(): array {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $addons = [
            [
                'slug'          => 'nexora-media',
                'file'          => 'nexora-media/nexora-media.php',
                'name'          => 'Nexora Media',
                'tagline'       => 'Media Delivery Intelligence',
                'description'   => 'AVIF/WebP conversion, background optimization queue, and adaptive responsive scaling. Processes your library asynchronously without slowing down the editor.',
                'benefit'       => 'Pairs with Static Delivery — AVIF/WebP images shrink your mirror size, improve Core Web Vitals, and serve faster from every cached page.',
                'badge'         => 'recommended',
                'icon_dashicon' => 'dashicons-images-alt2',
                'version'       => '1.0.17',
                // Must match the slug Nexora Media registers its settings page
                // under. It was renamed to nxmedia-settings; the old nxm-settings
                // value sent "Open settings" to a page that no longer exists.
                'settings_slug' => 'nxmedia-settings',
                'wp_org_slug'   => '', // empty until published on WP.org
            ],
            [
                // Renamed 2026-05 from "Nexora Insights" to align with the
                // Pulse product brand. We still look for the legacy slug
                // below so existing installs aren't reported as missing.
                'slug'          => 'nexora-pulse',
                'file'          => 'nexora-pulse/nexora-pulse.php',
                'name'          => 'Nexora Pulse',
                'tagline'       => 'AI SEO Operations Platform',
                'description'   => 'AI-driven SEO operations and content intelligence built for static-delivered sites. Auto-audits indexability, schema, internal linking, and Core Web Vitals — then turns each finding into a one-click fix.',
                'benefit'       => 'Pairs with Nexora Engine: every audit runs against the static mirror, so what Pulse sees is what your visitors see — not a logged-in admin view.',
                'badge'         => 'recommended',
                'icon_dashicon' => 'dashicons-chart-area',
                'version'       => null,
                'settings_slug' => 'nexora-pulse',
                // Published on WP.org 2026-07. A non-empty slug is what turns the
                // disabled "Coming to WP.org" button in addons.php into a working
                // Install link — it opens WordPress's own plugin-information modal
                // so the install happens in place rather than off-site.
                'wp_org_slug'   => 'nexora-pulse',
            ],
        ];

        foreach ( $addons as &$addon ) {
            if ( 'coming-soon' === $addon['badge'] ) {
                $addon['status'] = 'coming-soon';
                continue;
            }
            // Primary detection path.
            if ( is_plugin_active( $addon['file'] ) ) {
                $addon['status'] = 'active';
            } elseif ( file_exists( WP_PLUGIN_DIR . '/' . $addon['file'] ) ) {
                $addon['status'] = 'installed';
            } else {
                $addon['status'] = 'not-installed';
            }

            // Runtime constants — same plugin can live under a non-default
            // directory name (symlinks, dev forks) which file_exists()
            // misses. When the constant is defined the plugin is loaded
            // regardless of where the file lives.
            if ( $addon['slug'] === 'nexora-pulse' && $addon['status'] === 'not-installed' ) {
                if ( defined( 'NEXORA_PULSE_VERSION' ) ) {
                    $addon['status']  = 'active';
                    $addon['version'] = NEXORA_PULSE_VERSION;
                }
            }
            if ( $addon['slug'] === 'nexora-media' && $addon['status'] === 'not-installed' ) {
                if ( defined( 'NXM_VERSION' ) ) {
                    $addon['status']  = 'active';
                    $addon['version'] = NXM_VERSION;
                }
            }
        }
        unset( $addon );

        return $addons;
    }

    private function render_admin_frame_open( $view ) {
        $is_pro = class_exists( 'NexoraEngine\\Licensing\\FeatureGate' )
            && \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' );
        $current = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sections = [];

        foreach ( $this->get_admin_nav_items() as $slug => $item ) {
            if ( $item['pro'] && ! $is_pro ) {
                continue;
            }
            $sections[ $item['section'] ][ $slug ] = $item;
        }

        echo '<div class="ncx-product-frame">';
        echo '<aside class="ncx-product-sidebar" aria-label="' . esc_attr__( 'Nexora Engine navigation', 'nexora-engine' ) . '">';
        echo '<div class="ncx-product-brand"><span class="ncx-product-logo" aria-hidden="true">N</span><div><strong>Nexora Engine</strong><span>' . esc_html__( 'Static infrastructure lab', 'nexora-engine' ) . '</span></div></div>';
        echo '<nav class="ncx-product-nav">';
        foreach ( $sections as $section_label => $items ) {
            echo '<div class="ncx-product-nav-section">';
            echo '<span class="ncx-product-nav-label">' . esc_html( $section_label ) . '</span>';
            foreach ( $items as $slug => $item ) {
                $active = ( $view === $slug ) || ( $slug === 'dashboard' && ( $current === 'nexora' || $current === 'ncx-dashboard' ) );
                echo '<a class="ncx-product-nav-item' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=ncx-' . $slug ) ) . '">';
                echo '<span class="dashicons ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span>';
                echo '<span>' . esc_html( $item['label'] ) . '</span>';
                echo '</a>';
            }
            echo '</div>';
        }
        echo '</nav>';
        $this->render_admin_help_sidebar();
        echo '</aside>';

        echo '<main class="ncx-product-main">';
        echo '<div class="ncx-product-topbar">';
        echo '<div><span class="ncx-product-eyebrow">' . esc_html__( 'Nexora Engine', 'nexora-engine' ) . '</span><strong>' . esc_html__( 'Infrastructure Control Center', 'nexora-engine' ) . '</strong></div>';
        echo '<div class="ncx-product-actions">';
        echo '<span class="ncx-product-plan">' . ( $is_pro ? esc_html__( 'Pro', 'nexora-engine' ) : esc_html__( 'Free', 'nexora-engine' ) ) . '</span>';
        // Auto-Build status pill — visible on Pro only, reflects live option state.
        if ( $is_pro ) {
            $auto_rebuild_on = get_option( 'nexeng_auto_rebuild', 'on' ) === 'on';
            $ssg_active      = class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled();
            $ab_label        = ( $auto_rebuild_on && $ssg_active ) ? esc_html__( 'Auto-Build ON', 'nexora-engine' ) : esc_html__( 'Auto-Build OFF', 'nexora-engine' );
            $ab_class        = ( $auto_rebuild_on && $ssg_active ) ? 'ncx-topbar-ab ncx-topbar-ab--on' : 'ncx-topbar-ab ncx-topbar-ab--off';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ab_class is esc_attr()'d above and $ab_label is built from esc_html__() literals; both are already escaped.
            echo '<span class="' . esc_attr( $ab_class ) . '"><span class="ncx-topbar-ab-dot"></span>' . $ab_label . '</span>';
        }
        // ── Setup Wizard — ONLY shown when wizard is incomplete ─────────────
        // The previous design had two side-by-side pill buttons ("Setup Wizard"
        // and "Run Diagnostic") that looked nearly identical. Users
        // consistently mis-clicked Diagnostic thinking it was the wizard.
        // Once the wizard is done, it's effectively a one-time action — we
        // move "Re-run Setup" to the help sidebar where it belongs as an
        // occasional-use link, removing it from the high-attention topbar.
        $wizard_done = class_exists( 'NEXENG_Wizard' ) && NEXENG_Wizard::get_instance()->is_completed();
        if ( ! $wizard_done ) {
            $wizard_url = esc_url( NEXENG_Wizard::get_admin_url() );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $wizard_url is esc_url()'d above; the title attribute uses esc_attr__().
            echo '<a class="ncx-product-action ncx-product-action--wizard" id="ncx-topbar-setup-wizard" href="' . $wizard_url . '" title="' . esc_attr__( 'Open the setup wizard', 'nexora-engine' ) . '">';
            echo '<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>';
            echo '<span class="ncx-product-action-label">' . esc_html__( 'Setup Wizard', 'nexora-engine' ) . '</span>';
            echo '</a>';
        }
        // ── Run Diagnostic button — distinct primary CTA with search icon ──
        echo '<a class="ncx-product-action ncx-product-action--diagnostic is-primary ncx-run-diagnostic-global" id="ncx-topbar-run-diagnostic" href="' . esc_url( admin_url( 'admin.php?page=ncx-tools&nexeng_open_diag=1#run-diagnostic' ) ) . '" title="' . esc_attr__( 'Check server health and SSG capture pipeline', 'nexora-engine' ) . '">';
        echo '<span class="dashicons dashicons-search" aria-hidden="true"></span>';
        echo '<span class="ncx-product-action-label">' . esc_html__( 'Run Diagnostic', 'nexora-engine' ) . '</span>';
        echo '</a>';
        echo '</div>';
        echo '</div>';
        echo '<div class="ncx-product-content">';
    }

    private function render_shared_regen_panel() {
        $stats = class_exists( 'NEXENG_SSG' ) ? NEXENG_SSG::get_instance()->stats() : [];
        $ssg   = class_exists( 'NEXENG_SSG' ) ? NEXENG_SSG::get_instance() : null;
        $bulk  = $ssg ? $ssg->bulk_status() : [];
        $pending_count = $ssg && method_exists( $ssg, 'pending_count' ) ? $ssg->pending_count() : 0;
        $pending_posts = ( $ssg && $pending_count > 0 ) ? $ssg->pending_posts() : [];
        $total = (int) ( $bulk['total'] ?? 0 );
        $done  = (int) ( $bulk['processed'] ?? 0 );
        $pct   = $total > 0 ? min( 100, (int) round( ( $done / $total ) * 100 ) ) : 0;
        $is_running = ! empty( $bulk['running'] ) && empty( $bulk['done'] );
        $is_paused  = ! empty( $bulk['paused'] );
        $cron_events = array_filter( [
            wp_next_scheduled( 'nexeng_ssg_regen' ),
            wp_next_scheduled( 'nexeng_ssg_global_invalidate' ),
            wp_next_scheduled( 'nexeng_ssg_bulk_tick' ),
            wp_next_scheduled( 'nexeng_ssg_bulk_watchdog' ),
        ] );
        $next_cron  = ! empty( $cron_events ) ? min( $cron_events ) : false;
        $status     = $is_paused ? __( 'Paused', 'nexora-engine' ) : ( $is_running ? __( 'Running', 'nexora-engine' ) : __( 'Ready', 'nexora-engine' ) );
        $last_write = ! empty( $stats['last_write'] ) ? human_time_diff( (int) $stats['last_write'] ) . ' ' . __( 'ago', 'nexora-engine' ) : __( 'Not built yet', 'nexora-engine' );

        // Detect whether auto-regen is healthy (WP-Cron active or external cron wired).
        $cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $auto_regen_ok  = ! $cron_disabled;  // on managed/production hosts this is always fine
        // Default the auto-rebuild option based on the current plan tier so new
        // installs — or users who never saved Settings — see the correct badge.
        // Free tier defaults off (manual queue); Pro tier defaults on (auto-deploy).
        $is_pro_plan    = class_exists( 'NEXENG_Licence' ) && NEXENG_Licence::is_pro();
        $auto_rebuild   = get_option( 'nexeng_auto_rebuild', $is_pro_plan ? 'on' : 'off' ) === 'on';
        $ssg_on         = class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled();
        $suppress_archive       = (bool) get_transient( 'nexeng_suppress_archive_notice' );
        $archive_status         = ( $ssg && $ssg_on && ! $suppress_archive ) ? $ssg->archive_manifest_status() : [];
        $archives_missing       = ! empty( $archive_status['needs_build'] );
        $archives_missing_count = (int) ( $archive_status['missing'] ?? 0 );

        // ── Admin-triggered cron kick ─────────────────────────────────────────
        // On zero-traffic dev environments (LocalWP) or low-traffic production
        // sites, WP-Cron never fires because no front-end page loads occur to
        // trigger it.  When a Pro user opens any Nexora admin page and there are
        // pending items / a cron event is overdue, kick wp-cron directly from the
        // admin request so queued rebuilds actually execute.
        // Only fires when: SSG is on, pending items exist, not already running,
        // Pro plan, auto_rebuild is on, and WP-Cron is the default scheduler.
        if (
            $ssg_on && ! $is_running && ! $is_paused && ! $cron_disabled &&
            $is_pro_plan && $auto_rebuild &&
            ! empty( $cron_events ) // There is at least one overdue cron event
        ) {
            spawn_cron();
        }
        ?>
        <aside class="ncx-build-control" aria-label="<?php esc_attr_e( 'Static Site Generation build control', 'nexora-engine' ); ?>">
        <section class="ncx-regen-progress-panel ncx-regen-progress-panel--global <?php echo esc_attr( $is_running ? 'is-running' : 'is-idle' ); ?><?php echo esc_attr( $pending_count > 0 ? ' has-pending' : '' ); ?><?php echo esc_attr( ! $ssg_on ? ' is-ssg-off' : '' ); ?>" id="ncxRegenProgressPanel" data-auto-rebuild="<?php echo esc_attr( $auto_rebuild ? '1' : '0' ); ?>" aria-live="polite">
            <div class="ncx-rp-heading">
                <div class="ncx-rp-heading-left">
                    <span class="ncx-rp-kicker"><?php esc_html_e( 'Static Delivery', 'nexora-engine' ); ?></span>
                    <strong><?php esc_html_e( 'Mirror Build Control', 'nexora-engine' ); ?></strong>
                </div>
                <div class="ncx-rp-heading-badges">
                    <span class="ncx-rp-mode-badge <?php echo esc_attr( $auto_rebuild ? 'ncx-mode-auto' : 'ncx-mode-manual' ); ?>"
                          id="ncxRpModeBadge"
                          title="<?php echo $auto_rebuild ? esc_attr__( 'Pro — changes deploy automatically on the next cron tick', 'nexora-engine' ) : esc_attr__( 'Free — click Refresh Changed Pages to deploy updates manually', 'nexora-engine' ); ?>">
                        <?php echo $auto_rebuild ? esc_html__( 'Auto', 'nexora-engine' ) : esc_html__( 'Manual', 'nexora-engine' ); ?>
                    </span>
                    <span class="ncx-rp-status-pill" id="ncxRpStatus"><?php echo esc_html( $status ); ?></span>
                </div>
            </div>

            <?php /* ── SSG master switch — always visible, gates the panel body ── */ ?>
            <div class="ncx-rp-ssg-switch-row" id="ncxRpSsgSwitchRow">
                <div class="ncx-rp-ssg-switch-info">
                    <span class="ncx-rp-ssg-dot <?php echo esc_attr( $ssg_on ? 'ncx-rp-ssg-dot--on' : 'ncx-rp-ssg-dot--off' ); ?>"></span>
                    <span class="ncx-rp-ssg-switch-label">
                        <?php echo $ssg_on ? esc_html__( 'Static Delivery active', 'nexora-engine' ) : esc_html__( 'Static Delivery disabled', 'nexora-engine' ); ?>
                    </span>
                </div>
                <label class="ncx-switch" title="<?php esc_attr_e( 'Toggle Static Delivery on / off', 'nexora-engine' ); ?>">
                    <input type="checkbox" class="ncx-toggle-auto"
                           data-option="ssg_enabled"
                           data-label="<?php esc_attr_e( 'Static Delivery', 'nexora-engine' ); ?>"
                           <?php checked( $ssg_on ); ?>>
                    <span class="ncx-slider"></span>
                </label>
            </div>

            <?php /* ── Panel body — dimmed when SSG is off ── */ ?>
            <div class="ncx-build-panel-body<?php echo esc_attr( ! $ssg_on ? ' is-ssg-off' : '' ); ?>">
            <?php if ( ! $ssg_on ) : ?>
            <p class="ncx-bc-ssg-off-hint">
                <span class="dashicons dashicons-cloud"></span>
                <?php esc_html_e( 'Enable Static Delivery above to activate the build queue.', 'nexora-engine' ); ?>
            </p>
            <?php endif; ?>

            <?php /* Panel-anchored notice — JS populates this for queue actions (dismiss, regen one) */ ?>
            <div id="ncxPanelNotice" class="ncx-panel-notice" style="display:none" aria-live="polite" aria-atomic="true">
                <span class="ncx-pn-icon dashicons"></span>
                <span class="ncx-pn-text"></span>
            </div>
            <p class="ncx-rp-summary" id="ncxRpSummary">
                <?php
                if ( $pending_count > 0 && $auto_rebuild ) {
                    echo esc_html( sprintf(
                        /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                        _n(
                            '%d changed page is queued for automatic deployment. It will refresh on the next cron tick — no manual action needed.',
                            '%d changed pages are queued for automatic deployment. They will refresh on the next cron tick — no manual action needed.',
                            $pending_count, 'nexora-engine'
                        ),
                        $pending_count
                    ) );
                } elseif ( $pending_count > 0 ) {
                    echo esc_html( sprintf(
                        /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                        _n(
                            '%d changed page is ready for a focused refresh. Visitors keep seeing the last stable mirror until it finishes.',
                            '%d changed pages are ready for focused refresh. Visitors keep seeing the last stable mirror until they finish.',
                            $pending_count, 'nexora-engine'
                        ),
                        $pending_count
                    ) );
                } else {
                    echo esc_html__( 'Every regenerate action runs here, so builds stay visible and controlled from one place.', 'nexora-engine' );
                }
                ?>
            </p>
            <div class="ncx-rp-bar-wrap">
                <div class="ncx-rp-bar-track">
                    <div class="ncx-rp-bar-fill" id="ncxRpFill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
                </div>
                <span class="ncx-rp-pct" id="ncxRpPct"><?php echo esc_html( $pct ); ?>%</span>
            </div>
            <div class="ncx-rp-meta">
                <span id="ncxRpCount"><?php echo esc_html( $done ); ?> / <?php echo $total > 0 ? esc_html( $total ) : '-'; ?></span>
                <span class="ncx-rp-divider">.</span>
                <span id="ncxRpMode"><?php esc_html_e( 'Central build queue', 'nexora-engine' ); ?></span>
            </div>
            <div class="ncx-rp-url" id="ncxRpUrl"><?php echo ! empty( $bulk['last_url'] ) ? esc_html( $bulk['last_url'] ) : esc_html__( 'Waiting for the next build command.', 'nexora-engine' ); ?></div>
            <div class="ncx-rp-signals">
                <div><span><?php esc_html_e( 'Last build', 'nexora-engine' ); ?></span><strong id="ncxRpLastBuild"><?php echo esc_html( $last_write ); ?></strong></div>
                <div><span><?php esc_html_e( 'Mirror size', 'nexora-engine' ); ?></span><strong id="ncxRpSize"><?php echo esc_html( size_format( (int) ( $stats['total_bytes'] ?? 0 ) ) ); ?></strong></div>
                <div><span><?php esc_html_e( 'Focused updates', 'nexora-engine' ); ?></span><strong id="ncxRpPending"><?php echo esc_html( $pending_count ); ?></strong></div>
                <div><span><?php esc_html_e( 'Next job', 'nexora-engine' ); ?></span><strong id="ncxRpCron"><?php
                /* translators: %s: human-readable time until the next scheduled job. */
                echo $next_cron ? esc_html( sprintf( __( 'Due in %s', 'nexora-engine' ), human_time_diff( time(), $next_cron ) ) ) : esc_html__( 'Standby', 'nexora-engine' ); ?></strong></div>
            </div>

            <?php if ( ! $auto_regen_ok ) : ?>
            <div class="ncx-rp-cron-notice">
                <span class="dashicons dashicons-info-outline"></span>
                <?php esc_html_e( 'Auto-refresh is in manual mode. Edit a page, then refresh changed pages to publish the updated mirror.', 'nexora-engine' ); ?>
            </div>
            <?php endif; ?>

            <?php
            // Total pending items shown to the user = changed posts + archive entry (if any).
            // Used to decide whether the "Refresh Changed Pages" button renders and what
            // badge count it shows.  Both values come from server state so the button is
            // always correct on first paint — no waiting for the JS poll.
            $total_pending_display = $pending_count + ( $archives_missing ? 1 : 0 );
            ?>

            <?php if ( $archives_missing && ! $is_running ) : ?>
            <div class="ncx-rp-archive-notice" id="ncxArchiveNotice">
                <span class="dashicons dashicons-category"></span>
                <div class="ncx-rp-archive-notice-body">
                    <strong><?php esc_html_e( 'Category & tag pages are not static yet', 'nexora-engine' ); ?></strong>
                    <p><?php
                        echo esc_html( sprintf(
                            /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                            _n(
                                '%d archive page (category, tag, or blog index) has not been captured — use "Refresh Changed Pages" below to build it now.',
                                '%d archive pages (categories, tags, blog index) have not been captured — use "Refresh Changed Pages" below to build them now.',
                                $archives_missing_count,
                                'nexora-engine'
                            ),
                            $archives_missing_count
                        ) );
                    ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // Always render the pending container so the JS live-poll can inject
            // items that appear after page load (Elementor AJAX saves, REST edits).
            // Open and visible from first paint whenever posts OR archives are pending.
            ?>
            <details class="ncx-rp-pending-details" id="ncxPendingDetails"
                     <?php echo esc_attr( $total_pending_display > 0 ? 'open' : '' ); ?>
                     style="<?php echo esc_attr( $total_pending_display > 0 ? '' : 'display:none' ); ?>">
                <summary class="ncx-rp-pending-summary">
                    <span class="ncx-rp-queue-dot"></span>
                    <span id="ncxPendingCountText"><?php echo esc_html( sprintf(
                        /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                        _n( '%d change queued for deployment', '%d changes queued for deployment', max( 1, $total_pending_display ), 'nexora-engine' ),
                        max( 1, $total_pending_display )
                    ) ); ?></span>
                    <span class="ncx-rp-queue-tag"><?php esc_html_e( 'QUEUE', 'nexora-engine' ); ?></span>
                </summary>
                <ul class="ncx-rp-pending-list" id="ncxPendingList">
                    <?php
                    // ── Archive virtual item — server-rendered immediately so the user
                    // sees it on first paint without waiting for the 5-second JS poll.
                    // The JS poll uses the same data-id="ncx-virtual-archives" so it
                    // updates the label in-place rather than creating a duplicate.
                    if ( $archives_missing ) :
                        $arc_label = sprintf(
                            /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                            _n(
                                '%d archive page (categories, tags, authors)',
                                '%d archive pages (categories, tags, authors)',
                                $archives_missing_count,
                                'nexora-engine'
                            ),
                            $archives_missing_count
                        );
                    ?>
                    <li class="ncx-rp-pending-item ncx-rp-pending-item--archive" data-id="ncx-virtual-archives">
                        <div class="ncx-rp-pending-main">
                            <div class="ncx-rp-pending-indicator" style="background:#f59e0b"></div>
                            <div class="ncx-rp-pending-info">
                                <span class="ncx-rp-pending-title"><?php echo esc_html( $arc_label ); ?></span>
                                <span class="ncx-rp-pending-meta">
                                    <span class="ncx-rp-pending-reason"><?php esc_html_e( 'Included in Refresh', 'nexora-engine' ); ?></span>
                                </span>
                            </div>
                        </div>
                        <div class="ncx-rp-item-actions"></div>
                    </li>
                    <?php endif; ?>

                    <?php foreach ( $pending_posts as $pid => $entry ) :
                        $title  = ! empty( $entry['title'] ) ? $entry['title'] : '#' . $pid;
                        $raw_reason = $entry['reason'] ?? 'edit';
                        $reason     = match ( strtolower( $raw_reason ) ) {
                            'seo'       => 'SEO',
                            'publish'   => 'Published',
                            'scheduled' => 'Scheduled',
                            'manual'    => 'Manual',
                            'priority'  => 'Priority',
                            default     => ucfirst( $raw_reason ),
                        };
                        $age    = ! empty( $entry['ts'] ) ? human_time_diff( (int) $entry['ts'] ) . ' ago' : '';
                    ?>
                    <li class="ncx-rp-pending-item" data-id="<?php echo esc_attr( $pid ); ?>">
                        <div class="ncx-rp-pending-main">
                            <div class="ncx-rp-pending-indicator"></div>
                            <div class="ncx-rp-pending-info">
                                <span class="ncx-rp-pending-title"><?php echo esc_html( $title ); ?></span>
                                <span class="ncx-rp-pending-meta">
                                    <span class="ncx-rp-pending-reason"><?php echo esc_html( $reason ); ?></span>
                                    <?php if ( $age ) : ?>
                                    <span class="ncx-rp-pending-dot">&middot;</span>
                                    <span class="ncx-rp-pending-age"><?php echo esc_html( $age ); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="ncx-rp-item-actions">
                            <button type="button"
                                    class="ncx-btn ncx-btn-xs ncx-regen-one"
                                    data-id="<?php echo esc_attr( $pid ); ?>"
                                    title="<?php esc_attr_e( 'Deploy this page now', 'nexora-engine' ); ?>">
                                <span class="dashicons dashicons-image-rotate"></span>
                            </button>
                            <button type="button"
                                    class="ncx-btn ncx-btn-xs ncx-rp-dismiss-one"
                                    data-id="<?php echo esc_attr( $pid ); ?>"
                                    title="<?php esc_attr_e( 'Remove from queue', 'nexora-engine' ); ?>">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="ncx-rp-queue-footer">
                    <?php if ( ! $auto_rebuild ) : ?>
                    <p class="ncx-rp-queue-hint">
                        <span class="dashicons dashicons-info-outline" style="font-size:13px;width:13px;height:13px;line-height:1;vertical-align:middle;color:#64748b;"></span>
                        <?php esc_html_e( 'Auto-build is off — click "Refresh Changed Pages" above to deploy these updates manually.', 'nexora-engine' ); ?>
                    </p>
                    <?php endif; ?>
                    <button type="button" class="ncx-btn ncx-btn-xs ncx-btn-outline ncx-rp-clear-queue"
                            id="ncxClearQueueBtn"
                            title="<?php esc_attr_e( 'Remove all items from the queue without rebuilding pages', 'nexora-engine' ); ?>">
                        <span class="dashicons dashicons-dismiss"></span>
                        <?php esc_html_e( 'Clear queue', 'nexora-engine' ); ?>
                    </button>
                </div>
            </details>

            <div class="ncx-rp-advice" id="ncxRpAdvice">
                <?php
                $total_pending_display_adv = $pending_count + ( $archives_missing ? 1 : 0 );
                if ( $total_pending_display_adv > 0 && ! $auto_rebuild && ! $is_running ) {
                    esc_html_e( 'Updates are queued but won\'t deploy automatically — Auto-build is off. Use "Refresh Changed Pages" to publish the static mirror now.', 'nexora-engine' );
                } elseif ( $total_pending_display_adv > 0 && $auto_rebuild && ! $is_running ) {
                    esc_html_e( 'Updates are queued and will deploy automatically on the next cron tick. You can also click "Refresh Changed Pages" to deploy right now.', 'nexora-engine' );
                } else {
                    esc_html_e( 'Use focused refresh for content edits. Run a full mirror rebuild after theme, menu, layout, or plugin-wide changes.', 'nexora-engine' );
                }
                ?>
            </div>
            <div class="ncx-rp-primary-actions">
                <?php if ( $ssg_on && $total_pending_display > 0 ) : ?>
                <button type="button" class="ncx-btn ncx-btn-primary ncx-regen-pending" id="ncxRegenPendingBtn" data-count="<?php echo esc_attr( $total_pending_display ); ?>">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php esc_html_e( 'Refresh Changed Pages', 'nexora-engine' ); ?>
                    <span class="ncx-rp-pending-badge"><?php echo esc_html( $total_pending_display ); ?></span>
                </button>
                <?php endif; ?>
                <?php if ( $ssg_on ) : ?>
                <button type="button" class="ncx-btn <?php echo esc_attr( $total_pending_display > 0 ? 'ncx-btn-outline' : 'ncx-btn-primary' ); ?> ncx-regen-all">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Rebuild Full Mirror', 'nexora-engine' ); ?>
                </button>
                <?php else : ?>
                <button type="button" class="ncx-btn ncx-btn-primary" disabled style="opacity:.4;cursor:not-allowed"
                        title="<?php esc_attr_e( 'Static Delivery is off — enable it above to run a build', 'nexora-engine' ); ?>">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Rebuild Full Mirror', 'nexora-engine' ); ?>
                </button>
                <?php endif; ?>
            </div>
            <?php /* ── Clear Static Cache — destructive action, shown in-place with context ── */ ?>
            <div class="ncx-rp-danger-zone" id="ncxDangerZone">
                <p class="ncx-rp-danger-desc"><?php esc_html_e( 'Deletes all captured static files. Visitors receive dynamic PHP pages until you run a full rebuild.', 'nexora-engine' ); ?></p>
                <button type="button" class="ncx-btn ncx-btn-sm ncx-btn-danger ncx-tool-action"
                        data-action="ssg_purge"
                        data-confirm="<?php esc_attr_e( 'Delete all cached static files? Visitors will be served dynamic PHP pages until you regenerate.', 'nexora-engine' ); ?>"
                        data-reload="1">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e( 'Clear Static Cache', 'nexora-engine' ); ?>
                </button>
            </div>
            <div class="ncx-rp-controls">
                <button type="button" class="ncx-btn ncx-btn-sm ncx-btn-outline" id="ncxRpPauseBtn">
                    <span class="dashicons dashicons-controls-pause" id="ncxRpPauseIcon"></span>
                    <span id="ncxRpPauseLabel"><?php esc_html_e( 'Pause', 'nexora-engine' ); ?></span>
                </button>
                <button type="button" class="ncx-btn ncx-btn-sm ncx-btn-danger" id="ncxRpStopBtn">
                    <span class="dashicons dashicons-no-alt"></span>
                    <?php esc_html_e( 'Stop', 'nexora-engine' ); ?>
                </button>
            </div>
            <p class="ncx-rp-note" id="ncxRpNote"><?php esc_html_e( 'No active build. The next regenerate action will start here.', 'nexora-engine' ); ?></p>
            <!-- Build result box — JS populates this after a build finishes -->
            <div id="ncxBuildResultBox" class="ncx-rp-result-box" style="display:none"></div>
            </div><?php /* /.ncx-build-panel-body */ ?>
        </section>
        </aside>
        <?php
        NEXENG_Inline_Assets::script( 'window.ncxCentralRegenAll = true;' );
    }

    private function render_admin_frame_close() {
        echo '</div></main>';
        $this->render_shared_regen_panel();
        echo '</div>';
    }

    private function render_admin_help_sidebar() {
        // Base URL — single constant makes future domain changes a one-liner.
        $base = 'https://auralogicslabs.com/products/nexora-engine';

        $cards = [
            [
                'icon'  => 'dashicons-book-alt',
                'title' => __( 'Getting Started', 'nexora-engine' ),
                'cta'   => __( 'View Documentation', 'nexora-engine' ),
                'url'   => $base . '/docs/getting-started/',
            ],
            [
                'icon'  => 'dashicons-video-alt3',
                'title' => __( 'Video Tutorials', 'nexora-engine' ),
                'cta'   => __( 'Watch Tutorials', 'nexora-engine' ),
                'url'   => $base . '/tutorials/',
            ],
            [
                'icon'  => 'dashicons-lightbulb',
                'title' => __( 'Feature Request', 'nexora-engine' ),
                'cta'   => __( 'Request a Feature', 'nexora-engine' ),
                'url'   => $base . '/feature-request/',
            ],
            [
                'icon'  => 'dashicons-sos',
                'title' => __( 'Create a Ticket', 'nexora-engine' ),
                'cta'   => __( 'Open Support Ticket', 'nexora-engine' ),
                'url'   => $base . '/support/',
            ],
            [
                'icon'  => 'dashicons-star-filled',
                'title' => __( 'Submit a Review', 'nexora-engine' ),
                'cta'   => __( 'Review on WordPress.org', 'nexora-engine' ),
                // wp.org review page — plugin slug must match the directory name once published.
                'url'   => 'https://wordpress.org/support/plugin/nexora-engine/reviews/#new-post',
            ],
        ];

        echo '<div class="ncx-product-help-sidebar" aria-label="' . esc_attr__( 'Nexora Engine resources', 'nexora-engine' ) . '">';
        echo '<div class="ncx-product-help-sticky">';
        echo '<div class="ncx-product-help-head"><span class="ncx-product-help-kicker">' . esc_html__( 'Resources', 'nexora-engine' ) . '</span><strong>' . esc_html__( 'Help links', 'nexora-engine' ) . '</strong></div>';
        foreach ( $cards as $card ) {
            echo '<a class="ncx-product-help-card" href="' . esc_url( $card['url'] ) . '" target="_blank" rel="noopener noreferrer">';
            echo '<span class="ncx-product-help-icon dashicons ' . esc_attr( $card['icon'] ) . '" aria-hidden="true"></span>';
            echo '<div class="ncx-product-help-copy">';
            echo '<strong>' . esc_html( $card['title'] ) . '</strong>';
            echo '<p>' . esc_html( $card['cta'] ) . '</p>';
            echo '</div>';
            echo '</a>';
        }

        // ── Re-run Setup Wizard — only when wizard has already been completed ──
        // Moved here from the topbar to remove visual confusion with the
        // "Run Diagnostic" primary button. As an occasional-use action it
        // belongs in this resources panel, not next to the always-relevant
        // diagnostic CTA.
        if ( class_exists( 'NEXENG_Wizard' ) && NEXENG_Wizard::get_instance()->is_completed() ) {
            $rerun_url = esc_url( NEXENG_Wizard::get_admin_url( true ) );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rerun_url is esc_url()'d above; the title attribute uses esc_attr__().
            echo '<a class="ncx-product-help-card ncx-product-help-card--wizard" href="' . $rerun_url . '" title="' . esc_attr__( 'Reset setup completion and walk through the wizard again', 'nexora-engine' ) . '">';
            echo '<span class="ncx-product-help-icon dashicons dashicons-admin-generic" aria-hidden="true"></span>';
            echo '<div class="ncx-product-help-copy">';
            echo '<strong>' . esc_html__( 'Re-run Setup Wizard', 'nexora-engine' ) . '</strong>';
            echo '<p>' . esc_html__( 'Walk through setup again', 'nexora-engine' ) . '</p>';
            echo '</div>';
            echo '</a>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * Views that have been migrated to the React SPA. When these are
     * requested we output a single mount div instead of including the legacy
     * PHP view — the React app owns the whole admin chrome for migrated pages.
     *
     * To migrate another page later, add its slug here (e.g. 'tools', 'security').
     * Removing a slug here falls back to the original PHP view, so this is a
     * safe rollback switch.
     *
     * @var string[]
     */
    private $react_migrated_views = [ 'dashboard', 'settings', 'headless', 'security', 'redirects', 'tools', 'addons', 'updates', 'seo-report', 'wizard' ];

    public function __call( $name, $args ) {
        if ( strpos( $name, 'render_' ) === 0 ) {
            $view = str_replace( [ 'render_', '_' ], [ '', '-' ], $name );

            // ── React SPA mount ───────────────────────────────────────
            // Migrated views: bypass the PHP chrome wrapper completely. The
            // React app reads the current view from the page query var and
            // renders its own sidebar/topbar/right-rail.
            if ( in_array( $view, $this->react_migrated_views, true ) ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    return;
                }
                echo '<div id="nexora-engine-root" data-view="' . esc_attr( $view ) . '"></div>';
                return;
            }

            $path = plugin_dir_path( dirname( __FILE__ ) );
            $file = $path . "admin/views/{$view}.php";
            if ( file_exists( $file ) ) {
                // NOTE: No .wrap wrapper here by design.
                // WordPress fires admin_notices (PHP-rendered, from any plugin)
                // BEFORE this page callback runs — those notices land in the DOM
                // BEFORE .ncx-admin-wrapper and render correctly above our box.
                // JS-injected notices (Elementor, etc.) that target .wrap and
                // end up inside our box are relocated by the evictNotices() loop
                // in admin.js immediately on DOMContentLoaded + MutationObserver.
                // Wizard gets a modifier class so the wrapper chrome is stripped.
                $extra_class = ( $view === 'wizard' ) ? ' ncx-admin-wrapper--wizard' : '';
                echo '<div class="ncx-admin-wrapper' . esc_attr( $extra_class ) . '">';
                // Persistent banner shown on every Nexora page when a Pro
                // activation invalidated the existing static cache. Wizard
                // pages skip it — the wizard runs its own regen flow.
                if ( $view !== 'wizard' ) {
                    $this->render_admin_frame_open( $view );
                    $this->maybe_render_pro_regen_banner();
                }
                include $file;
                if ( $view !== 'wizard' ) {
                    $this->render_admin_frame_close();
                }
                echo '</div>';
            } else {
                echo '<div class="wrap"><div class="notice notice-error"><p>';
                echo esc_html(
                    sprintf(
                        /* translators: %s: relative admin view path */
                        __( 'Nexora Engine could not load the admin view (%s). Re-upload the plugin or reinstall the missing file.', 'nexora-engine' ),
                        "admin/views/{$view}.php"
                    )
                );
                echo '</p></div></div>';
            }
        }
    }

    // AJAX Handlers
    public function handle_save_settings() {
        $this->verify_request();

        $settings = isset( $_POST['settings'] ) ? (array) $_POST['settings'] : array();
        if ( empty( $settings ) ) {
            wp_send_json_error( array( 'message' => 'No settings provided' ) );
        }

        // Allowlist: only these option keys may be written via the settings form.
        // Add new keys here when new settings panels are introduced.
        // NOTE: headless_mode and ssg_enabled are kept here because the Headless
        // page master toggle also fires save_settings to flip those options.
        // The Settings page UI no longer shows a Headless Mode toggle (deduplication),
        // but the backend must still accept it for the Headless page master toggle.
        $allowed_keys = array(
            'headless_mode', 'admin_bar_badge', 'auto_rebuild',
            'analytics_enabled', 'anonymize_ips',
            'sitemap_enabled', 'schema_enabled',
            'asset_mode', 'ssg_enabled', 'ssg_excluded_types', 'ssg_script_hosts',
            // Staging HTTP Basic Auth (capture loopback credentials)
            'http_auth_user', 'http_auth_pass',
            // CDN / Edge Cache integration
            'cdn_auto_purge',
            'cdn_cf_zone_id', 'cdn_cf_api_token',
            'cdn_bunny_zone_id', 'cdn_bunny_api_key',
            // Security hardening toggles (secure_files removed in 2026-05 audit —
            // see class-ncx-hardening.php header comment for rationale).
            'secure_users_api', 'secure_author_enum', 'secure_xmlrpc',
            'secure_rest_tighten', 'secure_rate_limit',
            'secure_strong_pass', 'secure_login_rename', 'secure_login_errors',
            'secure_remove_version', 'secure_disable_file_edit', 'secure_headers',
            // Security text input
            'secure_login_slug',
        );

        // Keys that are boolean toggles — stored as 'on' or 'off'.
        $toggle_keys = [
            'headless_mode', 'admin_bar_badge', 'auto_rebuild', 'analytics_enabled', 'anonymize_ips',
            'sitemap_enabled', 'schema_enabled', 'ssg_enabled', 'cdn_auto_purge',
            // Security toggles
            'secure_users_api', 'secure_author_enum', 'secure_xmlrpc',
            'secure_rest_tighten', 'secure_rate_limit',
            'secure_strong_pass', 'secure_login_rename', 'secure_login_errors',
            'secure_remove_version', 'secure_disable_file_edit', 'secure_headers',
        ];

        // Snapshot the login-rename state BEFORE we write changes so we can
        // detect whether this save actually altered the slug or first enabled
        // the feature. If yes, set a one-shot transient that the Security view
        // reads on next render to show the "new login URL" success banner.
        $_lr_was_on   = get_option( 'nexeng_secure_login_rename' ) === 'on';
        $_lr_was_slug = (string) get_option( 'nexeng_secure_login_slug', '' );

        $saved = array();
        foreach ( $settings as $raw_key => $val ) {
            // Strip the nexeng_ prefix if the form sends it with the prefix.
            $key = str_replace( 'nexeng_', '', sanitize_key( $raw_key ) );

            if ( ! in_array( $key, $allowed_keys, true ) ) {
                continue; // Silently skip unrecognised keys.
            }

            if ( is_array( $val ) ) {
                $sanitized_val = array_map( 'sanitize_text_field', $val );
            } elseif ( in_array( $key, $toggle_keys, true ) ) {
                // Normalise checkbox toggles: only 'on' is truthy, everything else → 'off'.
                $sanitized_val = ( sanitize_text_field( $val ) === 'on' ) ? 'on' : 'off';
            } elseif ( $key === 'secure_login_slug' ) {
                // Extra-strict sanitisation for the login slug: lowercase, hyphen-safe,
                // strip any stray slashes the form might have included.
                $slug = strtolower( trim( sanitize_text_field( $val ), " \t\n\r\0\x0B/" ) );
                $sanitized_val = preg_replace( '/[^a-z0-9-]/', '', $slug );
            } else {
                $sanitized_val = sanitize_text_field( $val );
            }

            update_option( "nexeng_{$key}", $sanitized_val );
            $saved[] = $key;
        }

        // Post-save: detect a login-rename state change and arm the banner.
        $_lr_now_on   = get_option( 'nexeng_secure_login_rename' ) === 'on';
        $_lr_now_slug = (string) get_option( 'nexeng_secure_login_slug', '' );
        if ( $_lr_now_on && ( ! $_lr_was_on || $_lr_was_slug !== $_lr_now_slug ) && $_lr_now_slug !== '' ) {
            set_transient(
                'nexeng_login_rename_just_saved',
                [ 'url' => home_url( '/' . $_lr_now_slug . '/' ) ],
                MINUTE_IN_SECONDS * 10
            );
        }

        wp_send_json_success( array( 'message' => 'Settings synchronized successfully.', 'saved' => $saved ) );
    }

    public function handle_flush_permalinks() {
        $this->verify_request();
        flush_rewrite_rules();
        wp_send_json_success( [ 'message' => 'Permalink cache flushed. Sitemap and paths rebuilt.' ] );
    }

    public function handle_purge_analytics() {
        $this->verify_request();
        $analytics = NEXENG_Analytics::get_instance();
        $analytics->purge_logs();
        wp_send_json_success( [ 'message' => 'Neural Pulse logs purged successfully.' ] );
    }

    public function handle_export_settings() {
        $this->verify_request();
        $settings = [];
        $options = [ 'headless_mode', 'debug_mode', 'analytics_enabled', 'anonymize_ips', 'sitemap_enabled', 'schema_enabled', 'asset_mode' ];
        foreach ( $options as $opt ) {
            $settings[$opt] = get_option( "nexeng_{$opt}" );
        }
        wp_send_json_success( [ 'message' => 'Configuration exported.', 'config' => $settings ] );
    }

    public function handle_generate_api_key() {
        $this->verify_request();
        $key = 'nexeng_' . wp_generate_password( 32, false );
        update_option( 'nexeng_api_key', $key );
        wp_send_json_success( [ 'key' => $key ] );
    }

    public function handle_test_api_connection() {
        $this->verify_request();
        $key = get_option( 'nexeng_api_key' );
        // Mock connection test
        if ( $key ) {
            wp_send_json_success( [ 'message' => 'Connected' ] );
        } else {
            wp_send_json_error( [ 'message' => 'No key found' ] );
        }
    }

    public function handle_run_full_scan() {
        $this->verify_request();

        $conflicts = [];
        if ( class_exists( 'NEXENG_Conflict_Detector' ) ) {
            $detector = new NEXENG_Conflict_Detector();
            // Try each possible method name gracefully.
            foreach ( [ 'get_active_conflicts', 'detect', 'scan' ] as $method ) {
                if ( method_exists( $detector, $method ) ) {
                    $conflicts = (array) $detector->$method();
                    break;
                }
            }
        }

        self::bust_issue_count_cache();

        wp_send_json_success( [
            'message'   => sprintf( 'Scan complete. %d conflict(s) detected.', count( $conflicts ) ),
            'conflicts' => array_values( $conflicts ),
            'dropin'    => class_exists( 'NEXENG_Dropin' ) ? NEXENG_Dropin::status() : 'unknown',
            'stats'     => class_exists( 'NEXENG_SSG' ) ? NEXENG_SSG::get_instance()->stats() : [],
        ] );
    }

    public function handle_scan_single_page() {
        $this->verify_request();

        $post_id = (int) ( $_POST['post_id'] ?? 0 );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid or missing post_id.' ] );
        }

        if ( class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled() ) {
            $result = NEXENG_SSG::get_instance()->capture( $post_id );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            }
            wp_send_json_success( [
                'message' => 'Page analysed and static file refreshed.',
                'entry'   => NEXENG_SSG::get_instance()->manifest_entry( $post_id ),
            ] );
        }

        wp_send_json_success( [ 'message' => 'Page analysed. SSG is not active — no static file generated.' ] );
    }

    public function handle_clear_cache() {
        $this->verify_request();
        if ( empty( $_POST['nexeng_purge_confirmed'] ) ) {
            wp_send_json_error( [ 'message' => 'Action requires explicit confirmation.' ], 403 );
        }

        $result = class_exists( 'NEXENG_SSG' )
            ? NEXENG_SSG::get_instance()->purge_all()
            : [ 'deleted' => 0 ];

        self::bust_issue_count_cache();

        wp_send_json_success( array_merge(
            is_array( $result ) ? $result : [],
            [ 'message' => 'Static cache cleared successfully.' ]
        ) );
    }

    public function handle_reset_scan_data() {
        $this->verify_request();

        // Clear analytics / Neural Pulse logs.
        if ( class_exists( 'NEXENG_Analytics' ) ) {
            NEXENG_Analytics::get_instance()->purge_logs();
        }

        // Clear issue engine records for this blog.
        if ( class_exists( 'NEXENG_Issue_Engine' ) ) {
            $engine = NEXENG_Issue_Engine::get_instance();
            if ( method_exists( $engine, 'clear_all' ) ) {
                $engine->clear_all( get_current_blog_id() );
            }
        }

        // Clear stored SSG errors.
        delete_option( 'nexeng_ssg_errors' );
        delete_option( 'nexeng_dropin_last_error' );

        self::bust_issue_count_cache();

        wp_send_json_success( [ 'message' => 'Scan data, analytics and error logs cleared successfully.' ] );
    }

    public function handle_dismiss_banner() {
        $this->verify_request();
        $key = sanitize_key( $_POST['key'] ?? 'default' );
        update_option( "nexeng_banner_dismissed_{$key}", time() );
        wp_send_json_success( [ 'message' => 'Banner dismissed.' ] );
    }

    /**
     * Persistent banner displayed across every Nexora admin page after a
     * Pro license activation, until the user either:
     *   • clicks "Regenerate Now"   → fires the standard bulk-start flow
     *   • clicks "Dismiss"          → handle_dismiss_pro_regen_banner clears it
     *   • the SSG completes a full bulk_start() from anywhere               → auto-clears
     *
     * Skipped automatically when: option not set, plan no longer Pro, or
     * we're inside the wizard (wizard runs its own regen).
     */
    public function maybe_render_pro_regen_banner() {
        if ( ! get_option( 'nexeng_pro_regen_needed' ) ) {
            return;
        }
        // Sanity check: only show to Pro users (in case license expired since flag was set).
        if ( ! class_exists( 'NEXENG_Licence' ) || ! NEXENG_Licence::is_pro() ) {
            delete_option( 'nexeng_pro_regen_needed' );
            return;
        }
        ?>
        <div class="ncx-pro-regen-banner" id="ncxProRegenBanner" role="alert">
            <div class="ncx-prb-icon">🚀</div>
            <div class="ncx-prb-body">
                <strong><?php esc_html_e( 'Welcome to Pro — refresh your cache to apply Pro optimisations', 'nexora-engine' ); ?></strong>
                <p><?php esc_html_e( 'Your existing static pages were built under the Free tier. Use Build Control to rebuild them with Pro features: advanced SEO meta capture, Stealth Proxy-ready URLs, and security-header parity on cached pages.', 'nexora-engine' ); ?></p>
                <div class="ncx-prb-progress" id="ncxProRegenProgress" style="display:none;">
                    <div class="ncx-prb-bar"><div class="ncx-prb-fill" id="ncxProRegenFill" style="width:0%"></div></div>
                    <span id="ncxProRegenCount">0 / —</span>
                </div>
            </div>
            <div class="ncx-prb-actions">
                <button type="button" class="ncx-btn ncx-btn-primary" id="ncxProRegenStart">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Start in Build Control', 'nexora-engine' ); ?>
                </button>
                <button type="button" class="ncx-btn ncx-btn-text" id="ncxProRegenDismiss">
                    <?php esc_html_e( 'Dismiss', 'nexora-engine' ); ?>
                </button>
            </div>
        </div>
        <?php ob_start(); ?>
        .ncx-pro-regen-banner {
            display: flex; gap: 18px; align-items: center;
            margin: 0 0 20px; padding: 16px 22px;
            background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
            border: 1px solid #BFDBFE; border-left: 4px solid #0252FA;
            border-radius: 12px;
            animation: ncxProRegenPop .4s cubic-bezier(.4,0,.2,1);
        }
        @keyframes ncxProRegenPop { from { transform:translateY(-6px); opacity:0 } to { transform:translateY(0); opacity:1 } }
        .ncx-prb-icon { font-size: 30px; line-height: 1; flex-shrink: 0; }
        .ncx-prb-body { flex: 1; min-width: 0; }
        .ncx-prb-body strong { display: block; font-size: 14px; color: #1E3A8A; margin-bottom: 3px; }
        .ncx-prb-body p { margin: 0; font-size: 12px; color: #1E40AF; line-height: 1.55; }
        .ncx-prb-progress { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .ncx-prb-bar { flex: 1; height: 6px; background: rgba(2,82,250,.15); border-radius: 4px; overflow: hidden; }
        .ncx-prb-fill { height: 100%; background: linear-gradient(90deg, #0252FA, #6366F1); transition: width .3s ease; }
        .ncx-prb-progress span { font-size: 11px; font-weight: 600; color: #1E40AF; min-width: 60px; text-align: right; }
        .ncx-prb-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .ncx-btn-text { background: none; border: none; color: #64748B; font-size: 12px; cursor: pointer; padding: 8px 12px; }
        .ncx-btn-text:hover { color: #1E3A8A; text-decoration: underline; }
        <?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>
        <?php ob_start(); ?>
        (function(){
            var startBtn = document.getElementById('ncxProRegenStart');
            var dismBtn  = document.getElementById('ncxProRegenDismiss');
            var banner   = document.getElementById('ncxProRegenBanner');
            var progress = document.getElementById('ncxProRegenProgress');
            var fill     = document.getElementById('ncxProRegenFill');
            var countEl  = document.getElementById('ncxProRegenCount');

            if (dismBtn) dismBtn.addEventListener('click', async function() {
                if (!confirm('<?php echo esc_js( __( 'Dismiss this reminder? Your existing pages will keep their pre-Pro versions until you regenerate manually later.', 'nexora-engine' ) ); ?>')) return;
                await ncxCall('dismiss_pro_regen_banner');
                if (banner) banner.remove();
            });

            if (startBtn) startBtn.addEventListener('click', async function() {
                if (window.ncxCentralRegenAll && window.ncxRegenerateAll) {
                    window.ncxRegenerateAll(true);
                    return;
                }
                ncxSetLoading(startBtn, true);
                if (progress) progress.style.display = 'flex';
                if (dismBtn)  dismBtn.style.display  = 'none';

                var start = await ncxCall('ssg_regen_all_start');
                if (!start || !start.success) {
                    ncxToast((start && start.data && start.data.message) || 'Regen failed to start.', 'error');
                    ncxSetLoading(startBtn, false);
                    return;
                }
                var total = start.data.total || 0;
                if (countEl) countEl.textContent = '0 / ' + total;
                startBtn.querySelector('.dashicons').classList.add('ncx-spin');

                var retries = 0;
                var poll = async function() {
                    try {
                        var batch = await ncxCall('ssg_regen_all_batch');
                        if (batch && batch.success) {
                            retries = 0;
                            var processed = batch.data.processed || 0;
                            var pct = total > 0 ? Math.round((processed / total) * 100) : 100;
                            if (fill)    fill.style.width = pct + '%';
                            if (countEl) countEl.textContent = processed + ' / ' + total;
                            if (batch.data.done) {
                                ncxToast('<?php echo esc_js( __( 'Cache rebuilt with Pro optimisations.', 'nexora-engine' ) ); ?>', 'success');
                                if (banner) {
                                    banner.style.transition = 'opacity .4s';
                                    banner.style.opacity = '0';
                                    setTimeout(function() { banner.remove(); }, 450);
                                }
                                return;
                            }
                            setTimeout(poll, 1500);
                        } else if (++retries < 6) {
                            setTimeout(poll, 3000);
                        } else {
                            ncxToast('<?php echo esc_js( __( 'Regen poll lost connection — refresh to retry.', 'nexora-engine' ) ); ?>', 'error');
                        }
                    } catch (e) {
                        setTimeout(poll, 3000);
                    }
                };
                poll();
            });
        })();
        <?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
        <?php
    }

    public function handle_dismiss_pro_regen_banner() {
        $this->verify_request();
        delete_option( 'nexeng_pro_regen_needed' );
        wp_send_json_success();
    }

    public function handle_dismiss_ghost_banner() {
        $this->verify_request();
        delete_transient( 'nexeng_ghost_auto_enabled' );
        wp_send_json_success();
    }

    /**
     * Tests Cloudflare credentials by fetching zone details.
     * Called by the "Test Connection" button in Settings → SEO Engine → CDN.
     */
    public function handle_cdn_test_cloudflare() {
        $this->verify_request();
        if ( ! class_exists( 'NEXENG_CDN' ) ) {
            wp_send_json_error( [ 'message' => 'CDN class not loaded.' ] );
            return;
        }
        $result = NEXENG_CDN::test_cloudflare();
        if ( $result['success'] ) {
            wp_send_json_success( [ 'message' => $result['message'] ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ] );
        }
    }

    /**
     * Fires a CDN purge-all immediately (manual "Purge All Now" button).
     */
    public function handle_cdn_purge_all() {
        $this->verify_request();
        if ( ! class_exists( 'NEXENG_CDN' ) ) {
            wp_send_json_error( [ 'message' => 'CDN class not loaded.' ] );
            return;
        }
        if ( ! NEXENG_CDN::is_configured() ) {
            wp_send_json_error( [ 'message' => 'No CDN provider is configured. Add credentials in Settings → SEO Engine → CDN.' ] );
            return;
        }
        $result = NEXENG_CDN::purge_all();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        } else {
            wp_send_json_success( [ 'message' => 'CDN cache purged successfully. Edge nodes will serve the fresh static mirror on next request.' ] );
        }
    }

    /**
     * Returns the current pending-regen queue as JSON for the Build Control
     * live-poll. Called every 5 s by admin.js so the panel updates without
     * a page reload after Elementor / REST API saves.
     */
    public function handle_get_pending_list() {
        $this->verify_request();
        $ssg = class_exists( 'NEXENG_SSG' ) ? NEXENG_SSG::get_instance() : null;
        if ( ! $ssg ) {
            wp_send_json_success( [ 'count' => 0, 'items' => [] ] );
            return;
        }
        $pending = $ssg->pending_posts();
        $count   = count( $pending );
        $items   = [];
        foreach ( $pending as $pid => $entry ) {
            $ts     = (int) ( $entry['ts'] ?? 0 );
            $items[] = [
                'id'     => (int) $pid,
                'title'  => esc_html( ! empty( $entry['title'] ) ? $entry['title'] : '#' . $pid ),
                'reason' => sanitize_text_field( $entry['reason'] ?? 'content' ),
                'age'    => $ts > 0 ? human_time_diff( $ts ) . ' ago' : '',
            ];
        }
        $is_pro_plan  = class_exists( 'NEXENG_Licence' ) && NEXENG_Licence::is_pro();
        $auto_rebuild = get_option( 'nexeng_auto_rebuild', $is_pro_plan ? 'on' : 'off' ) === 'on';

        // Live signal data — returned every 5 s so the Build Control panel stays
        // up-to-date without a page reload (no separate AJAX round-trip needed).
        $stats     = $ssg->stats();
        $last_text = ! empty( $stats['last_write'] )
            ? human_time_diff( (int) $stats['last_write'] ) . ' ago'
            : __( 'Not built yet', 'nexora-engine' );
        $size_text = ! empty( $stats['total_bytes'] )
            ? size_format( (int) $stats['total_bytes'] )
            : '—';
        $cron_ev   = array_filter( [
            wp_next_scheduled( 'nexeng_ssg_regen' ),
            wp_next_scheduled( 'nexeng_ssg_global_invalidate' ),
            wp_next_scheduled( 'nexeng_ssg_bulk_tick' ),
            wp_next_scheduled( 'nexeng_ssg_bulk_watchdog' ),
        ] );
        $cron_text = ! empty( $cron_ev )
            /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
            ? sprintf( __( 'Due in %s', 'nexora-engine' ), human_time_diff( time(), min( $cron_ev ) ) )
            : __( 'Standby', 'nexora-engine' );

        // Archive pending state — exposed so the JS poll can render a virtual
        // queue item for archives alongside the regular post list.
        // "archives_pending" is non-zero when archives are dirty (global change
        // flagged them for rebuild) OR when some archive URLs were never captured.
        // Suppressed for 1 hour after the user explicitly clicks "Clear queue" so the
        // archive virtual item doesn't immediately re-appear in the live-poll output.
        $suppress_archive      = (bool) get_transient( 'nexeng_suppress_archive_notice' );
        $archives_dirty        = ! $suppress_archive && (bool) get_option( 'nexeng_ssg_archives_dirty' );
        $missing_arc_count     = ( ! $suppress_archive && NEXENG_SSG::is_enabled() ) ? count( $ssg->missing_archives() ) : 0;
        $archives_pending_flag = $archives_dirty || $missing_arc_count > 0;
        $archives_pending_count = $missing_arc_count;   // count shown in the virtual item

        wp_send_json_success( [
            'count'                  => $count,
            'items'                  => $items,
            'auto_rebuild'           => $auto_rebuild,
            'last_build'             => $last_text,
            'mirror_size'            => $size_text,
            'next_cron'              => $cron_text,
            'archives_pending'       => $archives_pending_flag,
            'archives_pending_count' => $archives_pending_count,
        ] );
    }

    /**
     * Action: wp_ajax_nexeng_ssg_remove_pending
     * Removes a single post from the pending-regeneration queue.
     * Used by the dismiss (✕) button in the Build Control queue.
     */
    public function handle_ssg_remove_pending() {
        $this->verify_request();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Missing post_id.', 'nexora-engine' ) ] );
            return;
        }
        $pending = get_option( 'nexeng_ssg_pending_posts', [] );
        if ( ! is_array( $pending ) ) {
            $pending = [];
        }
        $title = ! empty( $pending[ $post_id ]['title'] )
            ? $pending[ $post_id ]['title']
            : '#' . $post_id;
        unset( $pending[ $post_id ] );
        update_option( 'nexeng_ssg_pending_posts', $pending, false );
        wp_send_json_success( [
            /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
            'message' => sprintf( __( '"%s" removed from queue.', 'nexora-engine' ), $title ),
            'post_id' => $post_id,
        ] );
    }

    /**
     * Action: wp_ajax_nexeng_ssg_clear_all_pending
     * Clears every item from the pending-regeneration queue and cancels any
     * scheduled global-invalidate cron event so stale triggers don't re-queue
     * items immediately after clearing.  Escape hatch for stuck queues.
     */
    public function handle_ssg_clear_all_pending() {
        $this->verify_request();
        $count = 0;
        $pending = get_option( 'nexeng_ssg_pending_posts', [] );
        if ( is_array( $pending ) ) {
            $count = count( $pending );
        }
        // Wipe the pending list.
        update_option( 'nexeng_ssg_pending_posts', [], false );
        // Cancel the global-invalidate cron so it doesn't instantly re-fill the queue.
        wp_clear_scheduled_hook( 'nexeng_ssg_global_invalidate' );
        // ── Halt any in-progress bulk build — the whole point of "Clear queue" is
        // a full stop, not just emptying the pending-posts list. Without these
        // clears, a previously-started bulk would keep ticking even after the
        // user clears the queue, re-populating the UI on the next live-poll.
        wp_clear_scheduled_hook( 'nexeng_ssg_bulk_tick' );
        wp_clear_scheduled_hook( 'nexeng_ssg_bulk_watchdog' );
        wp_clear_scheduled_hook( 'nexeng_ssg_regen' );
        delete_transient( 'nexeng_ssg_bulk_queue' );
        delete_transient( 'nexeng_ssg_bulk_total' );
        delete_transient( 'nexeng_ssg_bulk_done' );
        delete_transient( 'nexeng_ssg_bulk_errors' );
        delete_transient( 'nexeng_ssg_bulk_running' );
        delete_transient( 'nexeng_ssg_bulk_mode' );
        delete_transient( 'nexeng_ssg_bulk_breakdown' );
        delete_transient( 'nexeng_ssg_bulk_attempts' );
        delete_transient( 'nexeng_ssg_bulk_last_url' );
        delete_transient( 'nexeng_ssg_bulk_paused' );
        delete_transient( 'nexeng_ssg_browser_active' );
        // Clear the archives-dirty flag so it doesn't re-trigger the virtual archive item.
        delete_option( 'nexeng_ssg_archives_dirty' );
        // Suppress the archive virtual item in the live-poll for 24 hours.
        // The user consciously dismissed the queue — don't re-inject the archive item
        // every 5 seconds just because archive files haven't been captured yet.
        // The suppression lifts naturally once the archives are actually captured
        // (missing_archives() returns empty) or after 24 hours.
        set_transient( 'nexeng_suppress_archive_notice', 1, DAY_IN_SECONDS );
        wp_send_json_success( [
            'message' => sprintf(
                /* translators: placeholders are dynamic values (counts, names, dates) inserted into the message. */
                _n( 'Queue cleared (%d item removed).', 'Queue cleared (%d items removed).', $count, 'nexora-engine' ),
                $count
            ),
            'count' => 0,
        ] );
    }

    public function handle_resolve_issue() {
        $this->verify_request();
        $issue_key = sanitize_key( $_POST['issue_key'] ?? '' );
        $post_id   = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $issue_key ) {
            wp_send_json_error( [ 'message' => 'Missing issue_key.' ] );
        }
        if ( ! class_exists( 'NEXENG_Issue_Engine' ) ) {
            wp_send_json_error( [ 'message' => 'Issue engine unavailable.' ] );
        }
        $engine = NEXENG_Issue_Engine::get_instance();
        $engine->resolve_issue( get_current_blog_id(), $post_id ?: null, $issue_key );
        self::bust_issue_count_cache();
        wp_send_json_success( [ 'message' => 'Issue resolved.' ] );
    }

    public function handle_ignore_issue() {
        $this->verify_request();
        $issue_key = sanitize_key( $_POST['issue_key'] ?? '' );
        $post_id   = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $issue_key ) {
            wp_send_json_error( [ 'message' => 'Missing issue_key.' ] );
        }
        if ( ! class_exists( 'NEXENG_Issue_Engine' ) ) {
            wp_send_json_error( [ 'message' => 'Issue engine unavailable.' ] );
        }
        $engine = NEXENG_Issue_Engine::get_instance();
        $engine->ignore_issue( get_current_blog_id(), $post_id ?: null, $issue_key );
        self::bust_issue_count_cache();
        wp_send_json_success( [ 'message' => 'Issue dismissed.' ] );
    }

    // ─── Phase 2 SSG AJAX Handlers ────────────────────────────────────────────

    public function handle_ssg_toggle() {
        $this->verify_request();
        $on = ! empty( $_POST['enabled'] ) && wp_unslash( $_POST['enabled'] ) !== 'false'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        update_option( 'nexeng_ssg_enabled', $on ? 'on' : 'off' );
        // Drop-in kill switch and serve rules are handled by
        // NEXENG_SSG::on_toggle(), hooked to the option update above.
        $ssg = NEXENG_SSG::get_instance();
        wp_send_json_success( [
            'enabled'    => $on,
            'serve_rule' => $ssg->serve_rule_installed(),
            'subdir'     => $ssg->is_subdir_install(),
        ] );
    }

    public function handle_ssg_regen_one() {
        $this->verify_request();
        // Master kill-switch — refuse to capture when Static Delivery is off.
        // Surfaces the stop reason to the JS toast so the UI can update the row.
        if ( ! NEXENG_SSG::is_enabled() ) {
            wp_send_json_error( [
                'message' => __( 'Static Delivery is disabled — enable it on the Static Delivery page before regenerating pages.', 'nexora-engine' ),
                'code'    => 'ssg_disabled',
            ] );
        }
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Missing post_id' ] );
        }

        $ssg = NEXENG_SSG::get_instance();

        // ── Cron-dispatch busy guard ──────────────────────────────────────────
        // fastcgi_cron_dispatch() holds a PHP-FPM worker during the debounce
        // sleep. On low-worker pools (LocalWP = 2 workers) no free worker exists
        // for the loopback inside capture(). Detect the busy window and queue
        // instead — the page captures once the dispatch worker wakes up.
        if ( get_transient( 'nexeng_ssg_cron_busy' ) ) {
            $ssg->mark_pending( $post_id, 'manual' );
            $ssg->schedule_regen( $post_id );
            wp_send_json_success( [
                'message' => 'A recent save is being processed — page queued and will refresh in a few seconds.',
                'queued'  => true,
                'url'     => get_permalink( $post_id ),
            ] );
            return;
        }

        // ── Priority queue injection ──────────────────────────────────────────
        // If a bulk build is running, attempting a direct loopback capture right
        // now would compete for the same PHP-FPM workers (admin AJAX + current
        // loopback already occupy the pool on low-worker hosts). Instead, inject
        // this page at the FRONT of the running queue so it is captured in the
        // very next batch poll — typically within 2 seconds.
        if ( get_transient( 'nexeng_ssg_bulk_running' ) ) {
            $queue = (array) get_transient( 'nexeng_ssg_bulk_queue' );
            // Remove any existing occurrence so the page isn't captured twice.
            $queue = array_values(
                array_filter( $queue, static fn( $item ) => is_array( $item ) || (int) $item !== $post_id )
            );
            // Prepend — next batch poll processes this first.
            array_unshift( $queue, $post_id );
            set_transient( 'nexeng_ssg_bulk_queue', $queue, 4 * HOUR_IN_SECONDS );
            // Keep the pending list consistent so Build Control shows it.
            $ssg->mark_pending( $post_id, 'priority' );
            wp_send_json_success( [
                'message' => 'Build is running — page moved to the front of the queue. It will be captured in the next batch (usually within seconds).',
                'queued'  => true,
                'url'     => get_permalink( $post_id ),
            ] );
            return;
        }

        // If this page has a known persistent fatal (OOM, PHP error), clear the
        // block so this explicit manual retry can attempt a fresh capture.
        // The user clicked regen intentionally — assume they've already raised
        // memory or fixed the source page.  If it fails again, cron_regen will
        // re-mark it as fatal on the next save, and the JS will show an amber toast.
        $ssg->clear_fatal( $post_id );

        // No bulk running. Still use the global capture mutex so a manual row
        // action cannot compete with a cron capture on low-worker live servers.
        if ( ! $ssg->acquire_capture_lock() ) {
            $ssg->mark_pending( $post_id, 'manual' );
            $ssg->schedule_regen( $post_id );
            wp_send_json_success( [
                'message' => 'A build is already running. This page is queued and will refresh shortly.',
                'queued'  => true,
                'url'     => get_permalink( $post_id ),
            ] );
            return;
        }
        try {
            $result = $ssg->capture( $post_id );
            if ( is_wp_error( $result ) ) {
                $err_code = $result->get_error_code();
                $err_msg  = $result->get_error_message();
                // Memory exhaustion is a special case: it's not a transient error,
                // but it IS recoverable (user can raise WP_MEMORY_LIMIT). Return a
                // distinct 'memory_limit' code so the JS shows an amber warning
                // with actionable advice instead of a hard red error toast.
                if ( $err_code === 'nexeng_ssg_source_fatal' && stripos( $err_msg, 'PHP memory' ) !== false ) {
                    wp_send_json_error( [
                        'message' => $err_msg . ' To fix: add define(\'WP_MEMORY_LIMIT\', \'512M\'); to wp-config.php, or ask your host to raise the PHP memory limit above 256MB.',
                        'code'    => 'memory_limit',
                    ] );
                    return;
                }
                wp_send_json_error( [ 'message' => $err_msg, 'code' => $err_code ] );
                return;
            }
        } finally {
            $ssg->release_capture_lock();
        }
        wp_send_json_success( [
            'message' => 'Page regenerated. Static mirror is now up to date.',
            'queued'  => false,
            'entry'   => $ssg->manifest_entry( $post_id ),
            'url'     => get_permalink( $post_id ),
        ] );
    }

    public function handle_ssg_regen_all_start() {
        $this->verify_request();
        if ( ! NEXENG_SSG::is_enabled() ) {
            wp_send_json_error( [
                'message' => __( 'Static Delivery is disabled — enable it on the Static Delivery page before starting a build.', 'nexora-engine' ),
                'code'    => 'ssg_disabled',
            ] );
        }

        // Start builds directly. The manual diagnostic still exposes loopback
        // warnings, but generation must not be hard-blocked by a temporary
        // LocalWP/FPM timeout or host loopback quirk.
        $ssg = NEXENG_SSG::get_instance();
        $include_archives = ! isset( $_POST['include_archives'] ) || filter_var( wp_unslash( $_POST['include_archives'] ), FILTER_VALIDATE_BOOLEAN );
        $count = $ssg->bulk_start( $include_archives );
        if ( is_wp_error( $count ) ) {
            $bulk = $ssg->bulk_status();
            wp_send_json_error( [
                'message' => $count->get_error_message(),
                'busy'    => ! empty( $bulk['running'] ) && empty( $bulk['done'] ),
                'bulk'    => $bulk,
            ] );
        }

        // Primary path: browser-driven (one capture per poll in handle_ssg_regen_all_batch).
        // This avoids PHP worker starvation on low-worker hosts (LocalWP, shared hosting
        // with pm.max_children ≤ 2): the cron tick would hold a worker for the full 8s
        // TICK_BUDGET while loopbacks from that same tick need another free worker.
        //
        // Fallback cron fires 5 min later — only takes over when the browser disconnects.
        // The browser-active transient (refreshed every batch poll) prevents double-processing.
        if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
        }

        $status = $ssg->bulk_status();
        wp_send_json_success( [
            'total'         => $count,
            'breakdown'     => $status['breakdown'] ?? [],
            'build_session' => $status['build_session'] ?? '',
        ] );
    }

    public function handle_ssg_regen_archives_start() {
        $this->verify_request();

        $ssg = NEXENG_SSG::get_instance();
        if ( ! NEXENG_SSG::is_enabled() ) {
            wp_send_json_error( [ 'message' => __( 'Static delivery is disabled.', 'nexora-engine' ) ] );
        }

        $count = $ssg->bulk_start_archives_only();
        if ( is_wp_error( $count ) ) {
            $bulk = $ssg->bulk_status();
            wp_send_json_error( [
                'message' => $count->get_error_message(),
                'busy'    => ! empty( $bulk['running'] ) && empty( $bulk['done'] ),
                'bulk'    => $bulk,
            ] );
        }

        if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
        }

        $status = $ssg->bulk_status();
        wp_send_json_success( [
            'total'         => $count,
            'breakdown'     => $status['breakdown'] ?? [],
            'build_session' => $status['build_session'] ?? '',
            'message'       => $count > 0
                ? sprintf(
                    /* translators: %d: number of archive pages queued */
                    _n( '%d archive page queued.', '%d archive pages queued.', $count, 'nexora-engine' ),
                    $count
                )
                : __( 'No archive pages to capture.', 'nexora-engine' ),
        ] );
    }

    /**
     * Starts a focused bulk regeneration for only the pending (changed) pages.
     *
     * The browser then drives capture via the existing ssg_regen_all_batch endpoint
     * (same polling loop, same progress display) — no second batch handler needed.
     * Returns { total: N } on success or { total: 0 } when there is nothing pending.
     */
    public function handle_ssg_regen_pending() {
        $this->verify_request();
        if ( ! NEXENG_SSG::is_enabled() ) {
            wp_send_json_error( [
                'message' => __( 'Static Delivery is disabled — enable it on the Static Delivery page before refreshing pages.', 'nexora-engine' ),
                'code'    => 'ssg_disabled',
            ] );
        }

        $ssg = NEXENG_SSG::get_instance();
        $bulk = $ssg->bulk_status();
        if ( ! empty( $bulk['running'] ) && empty( $bulk['done'] ) ) {
            wp_send_json_error( [
                'busy'    => true,
                'message' => 'A build is already running. Check Build Control for progress.',
            ] );
        }

        $count = $ssg->bulk_start_pending();

        if ( $count === 0 ) {
            wp_send_json_success( [ 'total' => 0, 'message' => 'No changed pages to refresh.' ] );
        }

        // Schedule a cron fallback (mirrors handle_ssg_regen_all_start).
        if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
        }

        wp_send_json_success( [ 'total' => $count ] );
    }

    /**
     * Standalone pre-flight check — called by the wizard before "Start Build"
     * and also wired into handle_ssg_regen_all_start / handle_ssg_regen_pending.
     *
     * Returns { ok: true, ttfb: 234 } on pass, or
     *         { ok: false, code: 'nexeng_preflight_500', message: '...' } on fail.
     */
    public function handle_ssg_preflight() {
        $this->verify_request();
        $t0       = microtime( true );
        $result   = NEXENG_SSG::get_instance()->capture_preflight();
        $ttfb     = (int) ( ( microtime( true ) - $t0 ) * 1000 );

        if ( is_wp_error( $result ) ) {
            wp_send_json_success( [
                'ok'      => false,
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'ttfb'    => $ttfb,
            ] );
        }

        wp_send_json_success( [ 'ok' => true, 'ttfb' => $ttfb ] );
    }

    /**
     * Returns the nginx location / block the client must add to their server
     * config to enable Nexora Tier-1 static delivery.
     *
     * Returns a conservative snippet. LocalWP should avoid fake sentinel paths.
     * the box — no manual debugging required on the client's server.
     */
    public function handle_ssg_nginx_config() {
        $this->verify_request();
        wp_send_json_success( [
            'config' => NEXENG_SSG::get_instance()->nginx_serve_config(),
        ] );
    }

    public function handle_ssg_regen_all_batch() {
        $this->verify_request();
        if ( ! NEXENG_SSG::is_enabled() ) {
            // Don't surface a hard error here — the build was mid-flight when
            // SSG was toggled off. bulk_batch() already clears the queue and
            // returns done=true with reason=ssg_disabled. Tell the UI to stop.
            wp_send_json_success( [ 'done' => true, 'reason' => 'ssg_disabled', 'remaining' => 0 ] );
        }
        $ssg = NEXENG_SSG::get_instance();

        // Heartbeat: tell cron the browser session is alive.
        // cron_bulk_tick() checks this and skips processing while the browser
        // is driving — prevents double-processing on low-worker hosts (LocalWP).
        set_transient( 'nexeng_ssg_browser_active', 1, 2 * MINUTE_IN_SECONDS );

        // Process ONE item per browser poll (browser-driven capture).
        // Each AJAX round trip naturally paces captures ~1–2 s apart without
        // any artificial sleep, freeing PHP workers between items.
        // When paused: skip capture so the user's pause is respected, but
        // still return the current status so the UI stays updated.
        //
        // Belt-and-suspenders mutex: even if a stale cron tick fires while the
        // browser is polling (browser-active guard is the primary protection),
        // the capture lock ensures only one process calls wp_remote_get() at a
        // time — preventing PHP-FPM pool exhaustion on low-worker hosts.
        if ( get_transient( 'nexeng_ssg_bulk_running' ) && ! get_transient( 'nexeng_ssg_bulk_paused' ) ) {
            if ( $ssg->acquire_capture_lock() ) {
                try {
                    $ssg->bulk_batch( 1 );
                } finally {
                    $ssg->release_capture_lock();
                }
                // If lock was unavailable, cron is mid-capture — skip this poll
                // and return current status so the UI heartbeat stays alive.
            }
        }

        $status = $ssg->bulk_status();

        // When the build just completed, fire a CDN zone-wide purge so every
        // edge node starts serving the freshly rebuilt static mirror immediately.
        if ( ! empty( $status['done'] ) && class_exists( 'NEXENG_CDN' ) && NEXENG_CDN::is_configured() ) {
            $cdn_result = NEXENG_CDN::purge_all();
            if ( is_wp_error( $cdn_result ) ) {
                $status['cdn_purge_error'] = $cdn_result->get_error_message();
            } else {
                $status['cdn_purged'] = true;
            }
        }

        wp_send_json_success( $status );
    }

    /**
     * Immediately stops and discards the running bulk regeneration queue.
     * Existing captured static files are preserved — only the pending work is cleared.
     */
    public function handle_ssg_bulk_stop() {
        $this->verify_request();
        NEXENG_SSG::get_instance()->bulk_stop();
        wp_send_json_success( [ 'message' => 'Bulk regeneration stopped.', 'stopped' => true ] );
    }

    /**
     * Pauses the running bulk regeneration without losing queue state.
     * Returns full status so the UI can update its progress display immediately.
     */
    public function handle_ssg_bulk_pause() {
        $this->verify_request();
        NEXENG_SSG::get_instance()->bulk_pause();
        wp_send_json_success( array_merge( NEXENG_SSG::get_instance()->bulk_status(), [ 'paused' => true ] ) );
    }

    /**
     * Resumes a paused bulk regeneration from where it left off.
     * Schedules a cron tick immediately so processing restarts within seconds.
     */
    public function handle_ssg_bulk_resume() {
        $this->verify_request();
        NEXENG_SSG::get_instance()->bulk_resume();
        wp_send_json_success( array_merge( NEXENG_SSG::get_instance()->bulk_status(), [ 'paused' => false ] ) );
    }

    public function handle_ssg_purge() {
        $this->verify_request();
        if ( empty( $_POST['nexeng_purge_confirmed'] ) ) {
            wp_send_json_error( [ 'message' => 'Action requires explicit confirmation.' ], 403 );
        }
        $result = NEXENG_SSG::get_instance()->purge_all();
        wp_send_json_success( $result );
    }

    /**
     * Re-queue all pages that failed during the last build so the user can
     * retry them without running a full Rebuild Full Mirror.
     */
    public function handle_ssg_retry_errors() {
        $this->verify_request();
        if ( ! NEXENG_SSG::is_enabled() ) {
            wp_send_json_error( [
                'message' => __( 'Static Delivery is disabled — enable it on the Static Delivery page before retrying failed pages.', 'nexora-engine' ),
                'code'    => 'ssg_disabled',
            ] );
        }

        if ( get_transient( 'nexeng_ssg_bulk_running' ) ) {
            wp_send_json_error( [ 'message' => 'A build is already running. Wait for it to finish before retrying errors.' ] );
        }

        $ssg    = NEXENG_SSG::get_instance();
        $log    = (array) get_option( 'nexeng_ssg_errors', [] );
        $queue  = [];

        foreach ( $log as $entry ) {
            $post_id = (int) ( $entry['post_id'] ?? 0 );
            if ( $post_id > 0 && $ssg->is_eligible( $post_id ) ) {
                // Clear any fatal block so this post is attempted again.
                $ssg->clear_fatal( $post_id );
                $queue[] = $post_id;
            }
        }

        // De-duplicate in case the same post appears multiple times in the log.
        $queue = array_values( array_unique( $queue ) );

        if ( empty( $queue ) ) {
            wp_send_json_success( [ 'message' => 'No eligible failed pages to retry.', 'queued' => 0 ] );
        }

        $total = count( $queue );
        $ttl   = 4 * HOUR_IN_SECONDS;

        set_transient( 'nexeng_ssg_bulk_queue',   $queue, $ttl );
        set_transient( 'nexeng_ssg_bulk_total',   $total, $ttl );
        set_transient( 'nexeng_ssg_bulk_done',    0,      $ttl );
        set_transient( 'nexeng_ssg_bulk_errors',  0,      $ttl );
        set_transient( 'nexeng_ssg_bulk_running', 1,      $ttl );
        delete_transient( 'nexeng_ssg_bulk_attempts' );
        delete_transient( 'nexeng_ssg_bulk_last_url' );
        // Reset the error log so the result box reflects only this retry run.
        update_option( 'nexeng_ssg_errors', [], false );

        wp_send_json_success( [
            'message' => sprintf( '%d failed page(s) re-queued for capture.', $total ),
            'queued'  => $total,
            'total'   => $total,
        ] );
    }

    /**
     * One-click exclude a specific page from future static captures.
     * Called from the "Exclude this page" button in failed-build error rows
     * so users can resolve chronic timeouts without digging into Settings.
     *
     * Side-effects:
     *  - Marks the post with _nexeng_exclude=1 (the per-post exclusion flag).
     *  - Removes any existing static file for the page (it'll serve dynamically now).
     *  - Removes the post from the pending-regen queue so it stops being retried.
     *  - Removes the post from the recent-errors log so the error row disappears.
     */
    public function handle_ssg_exclude_page() {
        $this->verify_request();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Missing post_id.', 'nexora-engine' ) ] );
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => __( 'Post not found.', 'nexora-engine' ) ] );
        }

        $title = get_the_title( $post_id );

        // 1. Set the per-post exclude flag (mirrors the editor metabox setting).
        update_post_meta( $post_id, '_nexeng_exclude', '1' );

        // 2. Delete any existing static file so visitors immediately get the
        //    dynamic PHP version (no stale broken capture lingering).
        if ( class_exists( 'NEXENG_SSG' ) ) {
            $ssg = NEXENG_SSG::get_instance();
            if ( method_exists( $ssg, 'delete_static_file' ) ) {
                $ssg->delete_static_file( $post_id );
            } elseif ( method_exists( $ssg, 'schedule_delete' ) ) {
                $ssg->schedule_delete( $post_id );
            }
            if ( method_exists( $ssg, 'clear_pending' ) ) {
                $ssg->clear_pending( $post_id );
            }
            if ( method_exists( $ssg, 'clear_fatal' ) ) {
                $ssg->clear_fatal( $post_id );
            }
        }

        // 3. Remove this post from the recent-errors log so the failed-row
        //    disappears from the UI (otherwise the user has to refresh).
        $errors = get_option( 'nexeng_ssg_errors', [] );
        if ( is_array( $errors ) ) {
            $errors = array_values( array_filter( $errors, static function ( $e ) use ( $post_id ) {
                return ! is_array( $e ) || (int) ( $e['post_id'] ?? 0 ) !== $post_id;
            } ) );
            update_option( 'nexeng_ssg_errors', $errors, false );
        }

        wp_send_json_success( [
            'post_id' => $post_id,
            'message' => sprintf(
                /* translators: %s: post title */
                __( '"%s" excluded — it will now serve dynamically and won\'t appear in future builds.', 'nexora-engine' ),
                $title
            ),
        ] );
    }

    public function handle_ssg_save_exclusions() {
        $this->verify_request();
        $types = isset( $_POST['types'] ) ? (array) $_POST['types'] : [];
        $types = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
        update_option( 'nexeng_ssg_excluded_types', $types );

        $hosts_raw = isset( $_POST['hosts'] ) ? (string) wp_unslash( $_POST['hosts'] ) : '';
        
        $clean = [];
        foreach ( preg_split( '/\R+/', $hosts_raw ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue;
            if ( preg_match( '/^[a-z0-9.\-]+$/i', $line ) ) {
                $clean[] = strtolower( $line );
            }
        }
        update_option( 'nexeng_ssg_script_hosts', implode( "\n", $clean ) );

        wp_send_json_success( [
            'message'        => 'Saved',
            'excluded_types' => $types,
            'script_hosts'   => $clean,
        ] );
    }

    public function handle_ssg_set_asset_mode() {
        $this->verify_request();
        if ( empty( $_POST['nexeng_purge_confirmed'] ) ) {
            wp_send_json_error( [ 'message' => 'Action requires explicit confirmation.' ], 403 );
        }

        $mode = isset( $_POST['mode'] ) && $_POST['mode'] === 'proxy' ? 'proxy' : 'direct';

        // Stealth Proxy is a Pro-only feature — enforce server-side.
        if ( $mode === 'proxy' && ! \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' ) ) {
            wp_send_json_error( [ 'message' => 'Stealth Proxy requires Nexora Engine Pro.' ] );
        }

        $old_mode = (string) get_option( 'nexeng_asset_mode', 'direct' );
        update_option( 'nexeng_asset_mode', $mode );

        // Refresh drop-in so it bakes the new mode into advanced-cache.php.
        NEXENG_Dropin::install();

        // Install or remove the Apache _ncx_v12/.htaccess stealth asset router.
        // This creates a real _ncx_v12/ directory so Apache serves stealth assets
        // natively without PHP — same speed as Direct mode on Apache servers.
        $ssg = NEXENG_SSG::get_instance();
        if ( $mode === 'proxy' && NEXENG_SSG::is_enabled() ) {
            $ssg->install_stealth_asset_rule();
        } else {
            $ssg->uninstall_stealth_asset_rule();
        }

        // When the mode actually changes, existing static HTML files contain the
        // old asset URL format (e.g. /wp-content/ vs /_ncx_v12/). Purge them all
        // and trigger a full rebuild so every page reflects the new URL scheme.
        $rebuilding = false;
        $total      = 0;
        if ( $mode !== $old_mode ) {
            $ssg->purge_all();

            if ( NEXENG_SSG::is_enabled() ) {
                $count = $ssg->bulk_start();
                if ( ! is_wp_error( $count ) && $count > 0 ) {
                    $total = $count;
                    if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
                        wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
                    }
                    $rebuilding = true;
                }
            }
        }

        wp_send_json_success( [
            'mode'       => $mode,
            'rebuilding' => $rebuilding,
            'total'      => $total,
            'message'    => $rebuilding
                ? "Switched to {$mode} mode. Purged cache and started rebuild of {$total} pages."
                : "Asset mode set to {$mode}.",
        ] );
    }

    public function handle_ssg_list() {
        $this->verify_request();
        wp_send_json_success( [ 'rows' => NEXENG_SSG::get_instance()->list_status( 200 ) ] );
    }

    public function handle_ssg_delete_one() {
        $this->verify_request();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Missing post_id' ] );
        }
        NEXENG_SSG::get_instance()->delete_post( $post_id );
        wp_send_json_success( [ 'message' => 'Deleted' ] );
    }

    public function handle_ssg_stats() {
        $this->verify_request();
        $ssg = NEXENG_SSG::get_instance();

        // Pull real measured TTFB P50 from hit logs (last 24 h of cache-hit rows).
        // Falls back to 0 when no data exists yet (new install, empty log table).
        $ttfb_p50 = 0;
        if ( class_exists( 'NEXENG_Analytics' ) ) {
            try {
                $a_stats  = NEXENG_Analytics::get_instance()->get_stats();
                $ttfb_p50 = (int) ( $a_stats['ttfb_p50'] ?? 0 );
            } catch ( \Throwable $e ) {
                // Non-fatal — wizard still shows the tier estimate as fallback.
            }
        }

        // Detect Nginx and whether the static-serve rule is already in the conf.
        $server_sw        = strtolower( NEXENG_Request::server( 'SERVER_SOFTWARE' ) );
        $is_nginx         = str_contains( $server_sw, 'nginx' );
        $nginx_rule_active = false;
        if ( $is_nginx ) {
            $abspath_fwd = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
            $is_localwp  = strpos( $abspath_fwd, '/Local Sites/' ) !== false;
            if ( $is_localwp ) {
                $site_root = preg_replace( '#/app/public$#', '', $abspath_fwd );
                $conf_file = str_replace( '/', DIRECTORY_SEPARATOR, $site_root . '/conf/nginx/includes/wordpress-single.conf.hbs' );
                if ( is_readable( $conf_file ) ) {
                    $nginx_rule_active = strpos( file_get_contents( $conf_file ), 'nexora-static' ) !== false;
                }
            } else {
                $host       = wp_parse_url( home_url(), PHP_URL_HOST );
                $candidates = [
                    '/etc/nginx/sites-enabled/' . $host,
                    '/etc/nginx/sites-available/' . $host,
                    '/etc/nginx/conf.d/' . $host . '.conf',
                ];
                foreach ( $candidates as $conf_file ) {
                    if ( is_readable( $conf_file ) && strpos( file_get_contents( $conf_file ), 'nexora-static' ) !== false ) {
                        $nginx_rule_active = true;
                        break;
                    }
                }
            }
        }

        wp_send_json_success( [
            'enabled'           => NEXENG_SSG::is_enabled(),
            'serve_rule'        => $ssg->serve_rule_installed(),
            'subdir'            => $ssg->is_subdir_install(),
            'dropin'            => class_exists( 'NEXENG_Dropin' ) && NEXENG_Dropin::status() === 'ours',
            'is_nginx'          => $is_nginx,
            'nginx_rule_active' => $nginx_rule_active,
            'cdn_configured'    => class_exists( 'NEXENG_CDN' ) && NEXENG_CDN::is_configured(),
            'fatal_pages_count' => count( $ssg->get_fatal_pages() ),
            'stats'             => $ssg->stats(),
            'bulk'              => $ssg->bulk_status(),
            'last_bulk_at'      => (int) get_option( 'nexeng_ssg_last_bulk_at', 0 ),
            'last_purge_at'     => (int) get_option( 'nexeng_ssg_last_purge_at', 0 ),
            'errors'            => array_slice( (array) get_option( 'nexeng_ssg_errors', [] ), 0, 10 ),
            'ttfb_p50'          => $ttfb_p50,   // 0 = no data yet; JS falls back to tier estimate
        ] );
    }

    // ─── Wizard AJAX Handlers ──────────────────────────────────────────────────

    public function handle_wizard_activate() {
        $this->verify_request();

        // 1. Enable SSG — always, for every plan
        update_option( 'nexeng_ssg_enabled', 'on' );

        // 2. Ghost Protocol — Pro only. Free users get SSG; Ghost Protocol is the Pro upsell.
        if ( class_exists( 'NEXENG_Licence' ) && NEXENG_Licence::is_pro() ) {
            update_option( 'nexeng_headless_mode', 'on' );
        }

        // 3. Asset mode — always reset to 'direct' on wizard run unless the user
        //    has explicitly chosen 'proxy' (Ghost Protocol stealth mode).
        //    This prevents stale proxy-mode captures from creating /_ncx_v12/
        //    URLs that break when PHP workers are unavailable.
        $current_mode = (string) get_option( 'nexeng_asset_mode', 'direct' );
        if ( $current_mode !== 'proxy' ) {
            update_option( 'nexeng_asset_mode', 'direct' );
        }

        // 4. Install drop-in
        $dropin_ok     = false;
        $dropin_result = NEXENG_Dropin::install();
        if ( ! is_wp_error( $dropin_result ) ) {
            $dropin_ok = NEXENG_Dropin::status() === 'ours';
        }

        // 5. Ensure static root + install serve rule (single-site Apache/LiteSpeed only)
        $ssg      = NEXENG_SSG::get_instance();
        $ssg->ensure_root();
        $serve_result = $ssg->install_serve_rule();
        $serve_ok     = ( true === $serve_result );

        // Multisite: rebuild the network map so the network drop-in resolves each site.
        if ( is_multisite() && class_exists( 'NEXENG_Multisite' ) ) {
            NEXENG_Multisite::rebuild_network_map();
        }

        // 5a. On re-install (stale static files already on disk from a previous
        // activation), purge the old files and manifest so the wizard build
        // captures fresh HTML instead of the loopback being served old statics
        // by the leftover .htaccess serve rule.  Only purge when there are
        // actually files present — first-time installs skip this entirely.
        $stats = $ssg->stats();
        if ( ! empty( $stats['total_files'] ) && (int) $stats['total_files'] > 0 ) {
            $ssg->purge_all();
        }

        // Determine achieved tier
        $server_sw = strtolower( NEXENG_Request::server( 'SERVER_SOFTWARE' ) );
        $is_apache = str_contains( $server_sw, 'apache' );
        $is_ls     = str_contains( $server_sw, 'litespeed' );
        $is_nginx  = str_contains( $server_sw, 'nginx' );

        if ( $serve_ok && ( $is_apache || $is_ls ) ) {
            $tier = 1; $tier_label = 'Full Speed'; $tier_ttfb = '~15ms';
        } elseif ( $dropin_ok ) {
            $tier = 2; $tier_label = 'Speed Active'; $tier_ttfb = '~45ms';
        } else {
            $tier = 3; $tier_label = 'Pages Built'; $tier_ttfb = '~80ms';
        }

        // Detect whether the Nginx serve rule is already in the config file.
        // For Nginx, install_serve_rule() only manages .htaccess (Apache), so
        // serve_rule_installed() returns false even when the Nginx rule is live.
        // We probe the config file directly so the UI can show "✅ Full Speed Active"
        // instead of prompting the user to apply a rule that's already there.
        $nginx_rule_active = false;
        if ( $is_nginx ) {
            $abspath_fwd = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
            $is_localwp  = strpos( $abspath_fwd, '/Local Sites/' ) !== false;
            if ( $is_localwp ) {
                $site_root  = preg_replace( '#/app/public$#', '', $abspath_fwd );
                $conf_file  = str_replace( '/', DIRECTORY_SEPARATOR, $site_root . '/conf/nginx/includes/wordpress-single.conf.hbs' );
                if ( is_readable( $conf_file ) ) {
                    $nginx_rule_active = strpos( file_get_contents( $conf_file ), 'nexora-static' ) !== false;
                }
            } else {
                // Generic Nginx: check common config paths for the nexora rule.
                $host      = wp_parse_url( home_url(), PHP_URL_HOST );
                $candidates = [
                    '/etc/nginx/sites-enabled/' . $host,
                    '/etc/nginx/sites-available/' . $host,
                    '/etc/nginx/conf.d/' . $host . '.conf',
                ];
                foreach ( $candidates as $conf_file ) {
                    if ( is_readable( $conf_file ) && strpos( file_get_contents( $conf_file ), 'nexora-static' ) !== false ) {
                        $nginx_rule_active = true;
                        break;
                    }
                }
            }
            // If Nginx rule is confirmed active, bump tier to 1.
            if ( $nginx_rule_active ) {
                $tier = 1; $tier_label = 'Full Speed'; $tier_ttfb = '~15ms';
            }
        }

        wp_send_json_success( [
            'tier'              => $tier,
            'tier_label'        => $tier_label,
            'tier_ttfb'         => $tier_ttfb,
            'serve_rule'        => $serve_ok,
            'dropin'            => $dropin_ok,
            'is_nginx'          => $is_nginx,
            'is_multisite'      => is_multisite(),
            'nginx_rule_active' => $nginx_rule_active,
        ] );
    }

    public function handle_wizard_disable_conflict() {
        $this->verify_request();
        $slug = sanitize_text_field( $_POST['slug'] ?? '' );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'Missing conflict slug.' ] );
        }

        $result = NEXENG_Wizard::get_instance()->disable_conflict_plugin( $slug );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Conflict resolution applied. Refreshing status...' ] );
    }

    public function handle_wizard_check_diag() {
        $this->verify_request();

        $cache_key = 'nexeng_diag_html_' . get_current_blog_id();
        $lock_key  = 'nexeng_diag_lock_' . get_current_blog_id();
        $cached    = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            wp_send_json_success( [ 'html' => $cached, 'cached' => true ] );
        }

        if ( get_transient( $lock_key ) ) {
            wp_send_json_success( [
                'html' => '<div class="ncx-diag-verdict-block is-warn"><span class="dashicons dashicons-update ncx-diag-verdict-icon"></span><div class="ncx-diag-verdict-text"><h4>' . esc_html__( 'Diagnostic already running', 'nexora-engine' ) . '</h4><p>' . esc_html__( 'A system check is already in progress. Wait a few seconds and open this panel again.', 'nexora-engine' ) . '</p></div></div>',
                'busy' => true,
            ] );
        }

        set_transient( $lock_key, 1, 20 );
        try {
            // Run diagnostic directly without relying on GET parameter.
            $diag = $this->run_diagnostic_check();
            set_transient( $cache_key, $diag, 20 );
        } finally {
            delete_transient( $lock_key );
        }
        wp_send_json_success( [ 'html' => $diag ] );
    }

    public function handle_wizard_reset_completion() {
        $this->verify_request();
        
        $wizard = NEXENG_Wizard::get_instance();
        $wizard->reset_completion();
        
        wp_send_json_success( [ 'message' => 'Wizard completion reset. You can now run the wizard again.' ] );
    }

    /**
     * Run diagnostic check for wizard completion.
     */
    private function run_diagnostic_check(): string {
        ob_start();
        
        $ssg            = NEXENG_SSG::get_instance();
        $home_url       = home_url( '/' );
        $home_path      = wp_parse_url( $home_url, PHP_URL_PATH ) ?: '/';
        $document_root  = NEXENG_Request::server( 'DOCUMENT_ROOT', '(unknown)' );
        $abspath        = ABSPATH;
        $upload         = wp_get_upload_dir();
        $static_root    = trailingslashit( $upload['basedir'] ) . 'nexora-static';
        $static_index   = $static_root . '/index.html';
        $htaccess_path  = trailingslashit( $abspath ) . '.htaccess';

        $ssg_enabled    = NEXENG_SSG::is_enabled();
        $headless_on    = get_option( 'nexeng_headless_mode', 'off' ) === 'on';
        $rule_installed = $ssg->serve_rule_installed();
        $dropin_status  = NEXENG_Dropin::status();
        $wp_cache_on    = NEXENG_Dropin::wp_cache_active();
        $dropin_conflict = NEXENG_Dropin::detect_conflict();
        $server_software = NEXENG_Request::server( 'SERVER_SOFTWARE', '(unknown)' );
        $is_nginx = stripos( $server_software, 'nginx' ) !== false;
        $is_apache = stripos( $server_software, 'apache' ) !== false;
        $is_litespeed = stripos( $server_software, 'litespeed' ) !== false;

        // Is our block before or after WordPress's block in .htaccess?
        $rule_position  = 'unknown';
        $htaccess_excerpt = '';
        if ( file_exists( $htaccess_path ) && is_readable( $htaccess_path ) ) {
            $contents = file_get_contents( $htaccess_path );
            $htaccess_excerpt = $contents;
            $nexeng_pos = strpos( $contents, '# BEGIN Nexora SSG' );
            $wp_pos  = strpos( $contents, '# BEGIN WordPress' );
            if ( $nexeng_pos === false ) {
                $rule_position = 'NOT PRESENT';
            } elseif ( $wp_pos === false ) {
                $rule_position = 'present (no WP block)';
            } elseif ( $nexeng_pos < $wp_pos ) {
                $rule_position = 'BEFORE WordPress block ✅';
            } else {
                $rule_position = 'AFTER WordPress block ❌ (Apache hits WP rule first)';
            }
        }

        // ── Probe: anonymous loopback HTTP request ────────────────────────────────
        // wp_remote_head() makes a fresh server-side request with NO browser
        // cookies, so this always tests the anonymous-visitor code path. Logged-in
        // users intentionally bypass the drop-in cache (they need fresh nonces /
        // admin bar) — that is correct behaviour, not a bug.
        $probe_clean = $home_url;
        $t0          = microtime( true );
        $probe       = wp_remote_head( $probe_clean, [
            'timeout'    => 3,
            'sslverify'  => false,
            'redirection'=> 1,
            'cookies'    => [],   // explicitly no cookies — anonymous test
        ] );
        $probe_ms        = (int) ( ( microtime( true ) - $t0 ) * 1000 );
        $probe_body_head = '';
        $probe_status    = 0;

        // ── Robust header extraction ──────────────────────────────────────────────
        // WP's Requests library can return a CaseInsensitiveDictionary object
        // whose (array) cast does not give usable key-value pairs in all WP versions.
        // Iterate via getAll() when available; fall back to ArrayAccess / cast.
        $probe_headers_flat = [];   // normalised lowercase key → string value
        if ( ! is_wp_error( $probe ) ) {
            $probe_status = wp_remote_retrieve_response_code( $probe );
            $raw_hdrs     = wp_remote_retrieve_headers( $probe );
            $probe_body_head = substr( wp_remote_retrieve_body( $probe ), 0, 600 );

            $iter = [];
            if ( method_exists( $raw_hdrs, 'getAll' ) ) {
                $iter = $raw_hdrs->getAll();          // WP 5.9+ CaseInsensitiveDictionary
            } elseif ( is_array( $raw_hdrs ) ) {
                $iter = $raw_hdrs;
            } else {
                $iter = (array) $raw_hdrs;            // last resort
            }
            foreach ( $iter as $k => $v ) {
                // Object-cast keys can have NUL-byte prefixes — strip them.
                $key = strtolower( ltrim( (string) $k, "\0* " ) );
                $probe_headers_flat[ $key ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
            }
        } else {
            $probe_status = 'ERROR: ' . $probe->get_error_message();
        }

        // ── Drop-in detection ─────────────────────────────────────────────────────
        // Our drop-in sets THREE unique headers that WordPress core never emits:
        //   • X-Nexora-Cache: HIT   (primary signal)
        //   • X-Nextjs-Cache: HIT   (secondary signal)
        //   • X-Powered-By: Next.js (tertiary — WordPress always says PHP/x.x.x)
        // Any one of them is sufficient to confirm a drop-in HIT.
        $hdr_nexeng_cache    = $probe_headers_flat['x-nexora-cache']  ?? '';
        $hdr_nextjs_cache = $probe_headers_flat['x-nextjs-cache']  ?? '';
        $hdr_xpb          = $probe_headers_flat['x-powered-by']    ?? '';

        $served_by_dropin =
            ( $hdr_nexeng_cache !== ''    && stripos( $hdr_nexeng_cache,    'HIT'     ) !== false ) ||
            ( $hdr_nextjs_cache !== '' && stripos( $hdr_nextjs_cache, 'HIT'     ) !== false ) ||
            ( stripos( $hdr_xpb, 'next.js' ) !== false );

        // ── PHP execution detection ───────────────────────────────────────────────
        // Flag PHP only when X-Powered-By says PHP (WordPress default) but NOT when
        // it says Next.js — that header is exclusively set by our drop-in.
        $php_in_xpb    = stripos( $hdr_xpb, 'php' ) !== false && stripos( $hdr_xpb, 'next' ) === false;
        $body_has_loader = stripos( $probe_body_head, 'ncx-loader' ) !== false
                        || stripos( $probe_body_head, '__NEXORA_PROPS__' ) !== false;

        // The static file was captured from the headless shell and may itself
        // contain "ncx-loader" — if the drop-in served it, that marker is expected
        // and does NOT mean PHP ran.
        if ( $served_by_dropin ) {
            $served_by_php   = false;
            $body_has_loader = false;
        } else {
            $served_by_php = $php_in_xpb || $body_has_loader;
        }

        // Display value for Cache Header row — no internal mechanism names.
        if ( $hdr_nexeng_cache !== '' ) {
            $display_cache_hdr = $hdr_nexeng_cache;      // e.g. "HIT" or "304"
        } elseif ( $served_by_dropin ) {
            $display_cache_hdr = 'HIT';                // detected via secondary signal
        } else {
            $display_cache_hdr = '';
        }

        if ( $served_by_dropin ) {
            $verdict = '<span style="color:#0a0;font-weight:bold">✅ FAST PATH — Page served from static cache before WordPress loaded. Zero database queries.</span>';
        } elseif ( ! $served_by_php ) {
            $verdict = '<span style="color:#0a0;font-weight:bold">✅ FAST PATH — Static file served by the web server directly. PHP did not run.</span>';
        } else {
            $verdict = '<span style="color:#c00;font-weight:bold">❌ SLOW PATH — PHP rendered every request. Neither the cache layer nor a web-server rule is active.</span>';
        }

        $home_file_exists  = file_exists( $static_index );
        $home_file_bytes   = $home_file_exists ? filesize( $static_index ) : 0;
        $home_file_mtime   = $home_file_exists ? gmdate( 'Y-m-d H:i:s', filemtime( $static_index ) ) : '—';

        $stats = $ssg->stats();

        $rows_with_warnings = [];
        if ( method_exists( $ssg, 'list_status' ) ) {
            foreach ( $ssg->list_status( 50 ) as $row ) {
                if ( ! empty( $row['warnings'] ) ) {
                    $rows_with_warnings[] = $row;
                }
            }
        }

        $is_fast = $served_by_dropin || ! $served_by_php;
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Diagnostic overlay markup: all dynamic values are individually esc_html()/esc_attr()/(int)-cast below; the remaining ternaries emit only hardcoded class names and status labels (intentional trusted strings), not user input.
        ?>

        <!-- ── Verdict banner ──────────────────────────────────────────────── -->
        <div class="ncx-diag-verdict-block <?php echo esc_attr( $is_fast ? 'is-good' : 'is-warn' ); ?>">
            <span class="dashicons <?php echo esc_attr( $is_fast ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?> ncx-diag-verdict-icon"></span>
            <div class="ncx-diag-verdict-text">
                <h4><?php echo $served_by_dropin ? 'Cache Active ⚡' : ( ! $served_by_php ? 'Fast Path (Web Server)' : 'Cache Not Active' ); ?></h4>
                <p><?php
                    if ( $served_by_dropin ) {
                        echo 'Pages are served from static cache before WordPress loads — zero database queries, zero PHP overhead.';
                    } elseif ( ! $served_by_php ) {
                        echo 'Static files are delivered directly by the web server. PHP is not running per request.';
                    } else {
                        echo 'PHP is rendering every request. Enable SSG and generate your pages to activate the cache layer.';
                    }
                ?></p>
            </div>
        </div>

        <!-- ── Anonymous probe note ───────────────────────────────────────── -->
        <div class="ncx-diag-probe-note">
            ℹ️ This probe tests anonymous visitor access (no session cookies). Logged-in users always see dynamically rendered pages — this is expected and correct.
        </div>

        <?php
        // ── CAPTURE SELF-TEST ──────────────────────────────────────────────
        // Runs ONE real capture() server-side and prints the exact outcome.
        // This is the decisive build diagnostic: when "Total static files: 0"
        // it tells us WHY (the actual WP_Error code/message) instead of leaving
        // us guessing why live captures nothing while LocalWP works.
        $cap_eligible = method_exists( $ssg, 'eligible_post_ids' ) ? $ssg->eligible_post_ids() : [];
        $cap_count    = count( $cap_eligible );
        $cap_out      = [];
        $cap_out[] = 'Eligible pages (queue source): ' . $cap_count;

        if ( $cap_count > 0 ) {
            $cap_pid  = (int) $cap_eligible[0];
            $cap_url  = '';
            if ( method_exists( $ssg, 'capture_url' ) ) {
                try {
                    $ref = new ReflectionMethod( 'NEXENG_SSG', 'capture_url' );
                    $ref->setAccessible( true );
                    $cap_url = (string) $ref->invoke( $ssg, $cap_pid );
                } catch ( \Throwable $e ) { $cap_url = '(n/a)'; }
            }
            $cap_out[] = 'Test page: #' . $cap_pid . ' — ' . get_the_title( $cap_pid );
            $cap_out[] = 'Permalink: ' . get_permalink( $cap_pid );
            $cap_out[] = 'Capture URL (loopback fetches this): ' . $cap_url;

            $t0  = microtime( true );
            $res = $ssg->capture( $cap_pid );
            $ms  = (int) ( ( microtime( true ) - $t0 ) * 1000 );

            if ( is_wp_error( $res ) ) {
                $cap_out[] = 'RESULT: ❌ ERROR [' . $res->get_error_code() . '] ' . $res->get_error_message() . '  (' . $ms . 'ms)';
            } else {
                $cap_out[] = 'RESULT: ✅ ' . wp_json_encode( $res ) . '  (' . $ms . 'ms)';
                // Confirm a file actually landed.
                $rel  = trim( (string) wp_make_link_relative( get_permalink( $cap_pid ) ), '/' );
                $file = trailingslashit( $upload['basedir'] ) . 'nexora-static/' . ( $rel ? $rel . '/' : '' ) . 'index.html';
                $cap_out[] = 'File written: ' . ( file_exists( $file ) ? 'YES (' . size_format( filesize( $file ) ) . ') ' . $file : 'NO — ' . $file );
            }
        } else {
            $cap_out[] = 'No eligible pages found — nothing to capture (this would explain 0 files).';
        }

        // Show the most recent logged build error, if any.
        $cap_errlog = (array) get_option( 'nexeng_ssg_errors', [] );
        if ( ! empty( $cap_errlog ) ) {
            $e0 = $cap_errlog[0];
            $cap_out[] = 'Last logged build error: [' . ( $e0['code'] ?? '' ) . '] ' . ( $e0['message'] ?? '' )
                . ( isset( $e0['url'] ) ? '  @ ' . $e0['url'] : '' );
        }
        ?>
        <p class="ncx-diag-section-title">Capture Self-Test (build)</p>
        <pre style="background:#0b1020;color:#9fe6a0;padding:14px;border-radius:8px;overflow:auto;white-space:pre-wrap;font-size:12px;line-height:1.6;"><?php
            echo esc_html( implode( "\n", $cap_out ) );
        ?></pre>

        <!-- ── Configuration ─────────────────────────────────────────────── -->
        <p class="ncx-diag-section-title">Configuration</p>

        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Headless Mode</span>
            <span class="ncx-diag-val <?php echo esc_attr( $headless_on ? 'ok' : 'err' ); ?>"><?php echo esc_attr( $headless_on ? 'Enabled' : 'Disabled' ); ?></span>
        </div>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Static Generation</span>
            <span class="ncx-diag-val <?php echo esc_attr( $ssg_enabled ? 'ok' : 'err' ); ?>"><?php echo esc_attr( $ssg_enabled ? 'Active' : 'Inactive' ); ?></span>
        </div>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Cache Layer</span>
            <span class="ncx-diag-val <?php
                if ( $dropin_status === 'ours' )    echo 'ok">Installed';
                elseif ( $dropin_status === 'foreign' ) echo 'warn">Conflict — another cache plugin detected';
                else echo 'err">Not installed';
            ?></span>
        </div>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Object Cache</span>
            <span class="ncx-diag-val <?php echo esc_attr( $wp_cache_on ? 'ok' : 'err' ); ?>"><?php echo esc_attr( $wp_cache_on ? 'Enabled' : 'Disabled' ); ?></span>
        </div>

        <!-- ── Filesystem ────────────────────────────────────────────────── -->
        <p class="ncx-diag-section-title">Filesystem</p>

        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Cached Pages</span>
            <span class="ncx-diag-val <?php echo esc_attr( $stats['total_files'] > 0 ? 'ok' : 'warn' ); ?>"><?php echo number_format( $stats['total_files'] ); ?> files</span>
        </div>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Homepage Cache</span>
            <span class="ncx-diag-val <?php echo esc_attr( $home_file_exists ? 'ok' : 'err' ); ?>">
                <?php echo $home_file_exists ? 'Ready (' . number_format( $home_file_bytes ) . ' bytes)' : 'Not generated'; ?>
            </span>
        </div>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Storage</span>
            <span class="ncx-diag-val <?php echo esc_attr( $stats['root_writable'] ? 'ok' : 'err' ); ?>"><?php echo esc_attr( $stats['root_writable'] ? 'Writable' : 'Read-only — check permissions' ); ?></span>
        </div>

        <!-- ── Live probe ─────────────────────────────────────────────────── -->
        <p class="ncx-diag-section-title">Live Probe</p>

        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Response Time</span>
            <span class="ncx-diag-val <?php echo esc_attr( $probe_ms < 80 ? 'ok' : ( $probe_ms < 500 ? 'warn' : 'err' ) ); ?>">
                <?php echo (int) $probe_ms; ?>ms<?php echo esc_attr( $probe_ms < 80 ? ' ⚡' : '' ); ?>
            </span>
        </div>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">HTTP Status</span>
            <span class="ncx-diag-val <?php echo esc_attr( $probe_status == 200 ? 'ok' : 'err' ); ?>"><?php echo esc_html( (string) $probe_status ); ?></span>
        </div>
        <?php
        // ── Served From — explains *which layer* delivered the response ──────────
        // Three possible outcomes (best → worst):
        //   1. Web server  — Apache/Nginx serves the static file directly via
        //                    .htaccess / nginx rewrite rule. PHP never starts.
        //                    This is Tier 1, the fastest path.
        //   2. PHP cache   — Drop-in (advanced-cache.php) intercepts before WP boots.
        //                    Tier 2, still bypasses WP + DB.
        //   3. WordPress   — Full PHP render. The slow path.
        if ( $served_by_dropin ) {
            $served_label  = 'PHP Cache (drop-in)';
            $served_class  = 'ok';
            $served_detail = esc_html( $display_cache_hdr );  // e.g. "HIT" or "304"
        } elseif ( ! $served_by_php ) {
            // No drop-in markers AND no PHP signature → the web server's rewrite
            // rule served the file. This is the *best* outcome, not a failure.
            $served_label  = 'Web Server (fastest)';
            $served_class  = 'ok';
            $served_detail = 'static file, PHP did not load';
        } else {
            $served_label  = 'WordPress (no cache)';
            $served_class  = 'err';
            $served_detail = 'PHP rendered the response';
        }
        ?>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">Served From</span>
            <span class="ncx-diag-val <?php echo esc_attr( $served_class ); ?>">
                <?php echo esc_html( $served_label ); ?>
                <?php if ( $served_detail ) : ?>
                <small style="font-weight:400;opacity:.7;display:block;font-size:10px;margin-top:1px;">
                    <?php echo esc_html( $served_detail ); ?>
                </small>
                <?php endif; ?>
            </span>
        </div>
        <div class="ncx-diag-row">
            <span class="ncx-diag-label">PHP Execution</span>
            <span class="ncx-diag-val <?php echo esc_attr( ! $served_by_php ? 'ok' : 'warn' ); ?>"><?php echo esc_attr( ! $served_by_php ? 'Bypassed ✓' : 'Executed' ); ?></span>
        </div>

        <?php if ( ! empty( $rows_with_warnings ) ) : ?>
        <p class="ncx-diag-section-title" style="color:#d97706;">Asset Warnings</p>
        <div class="ncx-diag-row" style="background:#fffbeb;">
            <span class="ncx-diag-label"><?php echo count( $rows_with_warnings ); ?> pages with broken asset references</span>
            <span class="ncx-diag-val warn">Use Build Control</span>
        </div>
        <?php endif; ?>

        <?php
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        return ob_get_clean();
    }

    public function handle_wizard_finish() {
        $this->verify_request();
        NEXENG_Wizard::get_instance()->complete_wizard();
        // Clear the bulk-running flag so a subsequent page load can never
        // mistake a completed build for an in-progress one and re-trigger
        // the wizard guard in maybe_redirect_to_wizard().
        delete_transient( 'nexeng_ssg_bulk_running' );
        wp_send_json_success( [ 'url' => admin_url( 'admin.php?page=nexora' ) ] );
    }

    // ─── Portal Connectivity Handlers ──────────────────────────────────────────

    /**
     * Connect site to Auralogics Portal.
     * Validates the key format, stores it, and registers a site ID.
     */
    public function handle_portal_connect() {
        $this->verify_request();

        if ( ! \NexoraEngine\Core\Features::is_tier_or_above( 'pro' ) ) {
            wp_send_json_error( [ 'message' => 'Portal connectivity requires Nexora Engine Pro.' ] );
        }

        $key = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );

        if ( empty( $key ) || strpos( $key, 'prtl_' ) !== 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid portal key format.' ] );
        }

        // Generate a unique site ID for this WordPress install.
        $site_id = get_option( 'nexeng_portal_site_id' );
        if ( empty( $site_id ) ) {
            $site_id = 'site_' . substr( md5( home_url() . wp_generate_password( 8, false ) ), 0, 16 );
            update_option( 'nexeng_portal_site_id', $site_id );
        }

        update_option( 'nexeng_portal_key', $key );
        update_option( 'nexeng_portal_connected', time(), false );

        wp_send_json_success( [
            'message' => 'Site connected to Auralogics Portal.',
            'site_id' => $site_id,
        ] );
    }

    /**
     * Disconnect site from Auralogics Portal.
     */
    public function handle_portal_disconnect() {
        $this->verify_request();
        delete_option( 'nexeng_portal_key' );
        delete_option( 'nexeng_portal_site_id' );
        delete_option( 'nexeng_portal_connected' );
        delete_option( 'nexeng_portal_token' );
        wp_send_json_success( [ 'message' => 'Site disconnected from portal.' ] );
    }

    /**
     * Sync site metrics with Auralogics Portal.
     * Stub — full cloud sync implementation in future release.
     */
    public function handle_portal_sync() {
        $this->verify_request();

        if ( ! \NexoraEngine\Core\Features::is_tier_or_above( 'pro' ) ) {
            wp_send_json_error( [ 'message' => 'Portal sync requires Nexora Engine Pro.' ] );
        }

        $portal_key = get_option( 'nexeng_portal_key', '' );
        if ( empty( $portal_key ) ) {
            wp_send_json_error( [ 'message' => 'Site is not connected to the portal.' ] );
        }

        // TODO: POST site metrics to auralogicslabs.com/portal/api/v1/sync
        // For now: return current local stats as confirmation.
        $ssg   = class_exists( 'NEXENG_SSG' ) ? \NEXENG_SSG::get_instance()->stats() : [];
        $dash  = class_exists( 'NEXENG_Dashboard' ) ? \NEXENG_Dashboard::get_instance()->get_stats() : [];

        wp_send_json_success( [
            'message'   => 'Portal sync initiated. Full cloud push ships in the next release.',
            'site_id'   => get_option( 'nexeng_portal_site_id' ),
            'ssg_stats' => $ssg,
        ] );
    }

    /**
     * Regenerates the site's portal token and returns a masked version.
     */
    public function handle_regenerate_portal_token() {
        $this->verify_request();
        if ( ! class_exists( 'NEXENG_Portal_API' ) ) {
            wp_send_json_error( [ 'message' => 'Portal API not available.' ] );
        }
        $token = NEXENG_Portal_API::regenerate_token();
        wp_send_json_success( [
            'masked' => substr( $token, 0, 6 ) . str_repeat( '•', 26 ),
        ] );
    }

    private function verify_request() {
        check_ajax_referer( 'nexeng_dashboard', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'nexora-engine' ) );
        }
    }

    // Helpers
    public static function get_score_color( $score ) {
        if ( $score >= 85 ) return '#F39A09';   /* brand amber — excellent */
        if ( $score >= 65 ) return '#F59E0B';   /* amber — good */
        if ( $score >= 40 ) return '#D85A30';   /* orange — needs improvement */
        return '#E24B4A';                       /* red — poor */
    }

    public static function get_score_bg( $score ) {
        if ( $score >= 85 ) return '#E1F5EE';
        if ( $score >= 65 ) return '#FAEEDA';
        if ( $score >= 40 ) return '#FAECE7';
        return '#FCEBEB';
    }

    public static function get_score_label( $score ) {
        if ( $score >= 85 ) return 'Excellent';
        if ( $score >= 65 ) return 'Good';
        if ( $score >= 40 ) return 'Needs Work';
        return 'Critical';
    }

    public static function render_severity_badge( $sev ) {
        $colors = [
            'critical' => [ 'bg' => '#FCEBEB', 'text' => '#A32D2D', 'dot' => '#E24B4A' ],
            'high'     => [ 'bg' => '#FAEEDA', 'text' => '#633806', 'dot' => '#EF9F27' ],
            'medium'   => [ 'bg' => '#E6F1FB', 'text' => '#0C447C', 'dot' => '#378ADD' ],
            'low'      => [ 'bg' => '#F1EFE8', 'text' => '#444441', 'dot' => '#888780' ],
        ];
        $c = $colors[$sev] ?? $colors['low'];
        return sprintf(
            '<span class="ncx-badge" style="background:%s;color:%s"><span class="ncx-dot" style="background:%s"></span>%s</span>',
            $c['bg'], $c['text'], $c['dot'], ucfirst($sev)
        );
    }

    public static function render_score_ring( $score, $size = 80 ) {
        $color = self::get_score_color($score);
        $radius = ($size / 2) - 6;
        $circ = 2 * pi() * $radius;
        ?>
        <div class="ncx-ring-container" style="width:<?php echo (int) $size; ?>px;height:<?php echo (int) $size; ?>px" data-score="<?php echo (int) $score; ?>">
            <svg class="ncx-ring-svg" width="<?php echo (int) $size; ?>" height="<?php echo (int) $size; ?>">
                <circle class="ncx-ring-circle" cx="<?php echo (int) ( $size / 2 ); ?>" cy="<?php echo (int) ( $size / 2 ); ?>" r="<?php echo esc_attr( round( $radius, 2 ) ); ?>" stroke="<?php echo esc_attr( $color ); ?>"></circle>
            </svg>
            <div class="ncx-ring-text" style="color:<?php echo esc_attr( $color ); ?>"><?php echo (int) $score; ?></div>
        </div>
        <?php
    }

    public static function render_score_bar( $label, $score ) {
        $color = self::get_score_color($score);
        ?>
        <div class="ncx-bar-row">
            <div class="ncx-bar-info">
                <span><?php echo esc_html( $label ); ?></span>
                <span style="color:<?php echo esc_attr( $color ); ?>"><?php echo (int) $score; ?></span>
            </div>
            <div class="ncx-bar-track">
                <div class="ncx-bar-fill" data-score="<?php echo (int) $score; ?>" style="background:<?php echo esc_attr( $color ); ?>"></div>
            </div>
        </div>
        <?php
    }

    public static function is_insights_active() {
        return class_exists('WP_Insights') || function_exists('wpi_is_active') || is_plugin_active('wp-insights/wp-insights.php');
    }

    /**
     * Counts real actionable issues so the menu badge reflects live site health.
     * Cached for 2 minutes (deleted immediately on state-changing events).
     *
     * Checks (each counts as 1):
     *  1. Stored drop-in install error
     *  2. SSG enabled but drop-in absent
     *  3. Foreign caching plugin owns advanced-cache.php
     *  4. Drop-in installed but WP_CACHE is not active (files never served)
     *  5. SSG enabled but zero pages generated yet
     *  6. NEXENG_Issue_Engine critical issues (if the class exists)
     *
     * @return int  0 = no badge shown, >0 = badge with that count.
     */
    private function get_critical_issue_count(): int {
        $cached = get_transient( 'nexeng_issue_count' );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $count  = 0;
        $ssg_on = ( get_option( 'nexeng_ssg_enabled' ) === 'on' );

        // 1. Active drop-in install error (stored by nexeng_dropin_sync_with_ssg).
        if ( get_option( 'nexeng_dropin_last_error' ) ) {
            $count++;
        }

        if ( class_exists( 'NEXENG_Dropin' ) ) {
            $dropin = NEXENG_Dropin::status();

            // 2. SSG on but drop-in is missing.
            if ( $ssg_on && $dropin === 'absent' ) {
                $count++;
            }

            // 3. Another caching plugin owns advanced-cache.php.
            if ( $dropin === 'foreign' ) {
                $count++;
            }

            // 4. Drop-in is ours but WP_CACHE is off — files will never be served.
            if ( $dropin === 'ours' && ! NEXENG_Dropin::wp_cache_active() ) {
                $count++;
            }
        }

        // 5. SSG on but no pages captured yet — nothing to serve.
        if ( $ssg_on && class_exists( 'NEXENG_SSG' ) ) {
            $stats = NEXENG_SSG::get_instance()->stats();
            if ( empty( $stats['total_files'] ) ) {
                $count++;
            }
        }

        // 6. Issue engine (future extension point — graceful when class absent).
        if ( class_exists( 'NEXENG_Issue_Engine' ) ) {
            $engine = NEXENG_Issue_Engine::get_instance();
            if ( method_exists( $engine, 'count_critical' ) ) {
                $count += (int) $engine->count_critical( get_current_blog_id() );
            }
        }

        set_transient( 'nexeng_issue_count', $count, 2 * MINUTE_IN_SECONDS );
        return $count;
    }

    /** Busts the issue-count cache so the badge updates immediately. */
    public static function bust_issue_count_cache(): void {
        delete_transient( 'nexeng_issue_count' );
    }

    /**
     * Handle regenerate all from dashboard.
     */
    public function handle_regenerate_all() {
        $this->verify_request();
        // Use the safe cron-based approach — never block AJAX workers with loopback captures.
        $count = NEXENG_SSG::get_instance()->bulk_start();
        if ( is_wp_error( $count ) ) {
            wp_send_json_error( [ 'message' => $count->get_error_message() ] );
        }
        if ( ! wp_next_scheduled( 'nexeng_ssg_bulk_tick' ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'nexeng_ssg_bulk_tick' );
        }
        wp_send_json_success( [ 'total' => $count ] );
    }

    /**
     * Handle purge cache from dashboard.
     */
    public function handle_purge_cache() {
        $this->verify_request();
        if ( empty( $_POST['nexeng_purge_confirmed'] ) ) {
            wp_send_json_error( [ 'message' => 'Action requires explicit confirmation.' ], 403 );
        }
        $result = NEXENG_SSG::get_instance()->purge_all();
        wp_send_json_success( $result );
    }

    public function handle_ttfb_beacon() {
        NEXENG_Logging::get_instance()->handle_ttfb_beacon();
    }

    public function handle_get_neural_pulse() {
        $this->verify_request();
        $analytics = NEXENG_Analytics::get_instance();
        $analytics->ingest_logs();
        $activity = $analytics->get_latest_activity( 15 );
        wp_send_json_success( [ 'activity' => $activity ] );
    }

    // ─── Licensing Recovery (Tools page) ─────────────────────────────────────

    /**
     * Clear the entitlement cache + grace period + per-request memo.
     * After this call the next FeatureGate::get_plan() goes straight to Freemius.
     */
    public function handle_licensing_clear_cache() {
        $this->verify_request();
        \NexoraEngine\Licensing\FeatureGate::bust_all_caches();
        wp_send_json_success( array( 'message' => 'Licence cache cleared. Plan state will be re-verified on next page load.' ) );
    }

    /**
     * Returns the current plan state as JSON for the recovery panel live-refresh.
     */
    public function handle_licensing_get_state() {
        $this->verify_request();

        $adapter = \NexoraEngine\Licensing\FreemiusAdapter::instance();
        $cache   = \NexoraEngine\Licensing\EntitlementCache::get();
        $grace   = \NexoraEngine\Licensing\GracePeriod::is_active();
        $env     = \NexoraEngine\Licensing\Environment::current();
        $dev_on  = \NexoraEngine\Licensing\DevOverrides::is_active();

        $age_s  = $cache ? \NexoraEngine\Licensing\EntitlementCache::age_seconds() : -1;
        $age    = $age_s >= 0 ? round( $age_s / 60, 1 ) . ' min ago' : '—';

        $plan   = \NexoraEngine\Licensing\FeatureGate::get_plan();

        wp_send_json_success( array(
            'plan'          => $plan,
            'environment'   => $env,
            'dev_override'  => $dev_on,
            'cache_plan'    => $cache ? $cache['plan']   : null,
            'cache_status'  => $cache ? $cache['status'] : null,
            'cache_age'     => $age,
            'cache_ttl_s'   => \NexoraEngine\Licensing\EntitlementCache::active_ttl(),
            'grace_active'  => $grace,
            'grace_seconds' => $grace ? \NexoraEngine\Licensing\GracePeriod::seconds_remaining() : 0,
            'fs_available'  => $adapter->is_available(),
        ) );
    }

    /**
     * Reset sandbox state — clears Freemius API cache transient.
     * Dev / staging environments only.  Blocked on production.
     */
    public function handle_licensing_reset_sandbox() {
        $this->verify_request();

        if ( \NexoraEngine\Licensing\Environment::is_production() ) {
            wp_send_json_error( array( 'message' => 'Sandbox reset is not available on production.' ) );
        }

        // Bust our entitlement caches.
        \NexoraEngine\Licensing\FeatureGate::bust_all_caches();

        // Clear Freemius API response cache (product-ID-keyed transient).
        // This forces Freemius to re-fetch install + plan data from its API
        // on the next admin page load — equivalent to ?fs_clear_api_cache=1.
        delete_transient( 'fs_cache_29612' );
        delete_option( 'fs_cache_29612' );

        wp_send_json_success( array( 'message' => 'Dev state cleared. Reload the admin to re-verify licence state.' ) );
    }

    // ─── Redirect Manager ─────────────────────────────────────────────────────

    /**
     * Explicit render for the Redirect Manager page.
     * Injects $db into view scope so the view doesn't need to bootstrap it.
     */
    public function render_redirects() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'nexora-engine' ) );
        }
        $db   = NEXENG_Database::get_instance(); // injected into included view scope
        $path = plugin_dir_path( dirname( __FILE__ ) );
        $file = $path . 'admin/views/redirects.php';
        if ( file_exists( $file ) ) {
            echo '<div class="ncx-admin-wrapper">';
            $this->render_admin_frame_open( 'redirects' );
            include $file;
            $this->render_admin_frame_close();
            echo '</div>';
        }
    }

    /**
     * Rewrites the .htaccess redirect block, when the Redirect Manager is present.
     *
     * NEXENG_Redirect_Manager lives in a __premium_only file, so it is absent
     * from the free build. The four call sites that need this each guarded
     * nothing before, which would have been a fatal the moment a free user
     * added or toggled a redirect; the REST equivalents were already guarded.
     * One guarded helper keeps the two paths from drifting apart again.
     *
     * @param int $blog_id Blog whose rules should be rewritten.
     */
    private function sync_redirect_htaccess( $blog_id ): void {
        if ( class_exists( 'NEXENG_Redirect_Manager' )
            && method_exists( 'NEXENG_Redirect_Manager', 'sync_htaccess' ) ) {
            NEXENG_Redirect_Manager::sync_htaccess( $blog_id );
        }
    }

    /**
     * Handle the "Add Redirect" form submitted to admin-post.php.
     * Action: nexeng_save_redirects (registered in constructor).
     */
    public function handle_save_redirects() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'nexora-engine' ) );
        }
        check_admin_referer( 'nexeng_save_redirects' );

        // Gate: Redirect Manager is a Pro feature.
        if ( ! \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' ) ) {
            wp_die( esc_html__( 'Redirect Manager requires a Pro license.', 'nexora-engine' ) );
        }

        $source = isset( $_POST['nexeng_source'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nexeng_source'] ) ) ) : '';
        $target = isset( $_POST['nexeng_target'] ) ? esc_url_raw( wp_unslash( $_POST['nexeng_target'] ) ) : '';
        $type   = isset( $_POST['nexeng_type'] ) ? (int) $_POST['nexeng_type'] : 301;

        if ( empty( $source ) || empty( $target ) ) {
            wp_die( esc_html__( 'Source path and target URL are required.', 'nexora-engine' ) );
        }

        // Ensure source starts with /
        if ( '/' !== substr( $source, 0, 1 ) ) {
            $source = '/' . $source;
        }

        $db      = NEXENG_Database::get_instance();
        $blog_id = get_current_blog_id();
        $db->insert_redirect( $blog_id, $source, $target, $type );
        delete_transient( 'nexeng_redirects_' . $blog_id );
        $this->sync_redirect_htaccess( $blog_id );

        wp_safe_redirect(
            add_query_arg( 'saved', '1', admin_url( 'admin.php?page=ncx-redirects' ) )
        );
        exit;
    }

    /**
     * AJAX handler: add a redirect.
     * Action: wp_ajax_nexeng_add_redirect
     */
    public function handle_add_redirect() {
        $this->verify_request();

        if ( ! \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' ) ) {
            wp_send_json_error( array( 'message' => 'Pro license required.' ) );
        }

        $source    = isset( $_POST['source'] )    ? trim( sanitize_text_field( wp_unslash( $_POST['source'] ) ) ) : '';
        $target    = isset( $_POST['target'] )    ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';
        $type      = isset( $_POST['type'] )      ? (int) $_POST['type'] : 301;
        $is_active = isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 1;
        $notes     = isset( $_POST['notes'] )     ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';

        if ( empty( $source ) || empty( $target ) ) {
            wp_send_json_error( [ 'message' => 'Source path and target URL are required.' ] );
        }

        if ( '/' !== substr( $source, 0, 1 ) ) {
            $source = '/' . $source;
        }

        $db      = NEXENG_Database::get_instance();
        $blog_id = get_current_blog_id();
        $ok      = $db->insert_redirect( $blog_id, $source, $target, $type, $is_active, $notes );

        if ( $ok ) {
            delete_transient( 'nexeng_redirects_' . $blog_id );
            $this->sync_redirect_htaccess( $blog_id );
            wp_send_json_success( [ 'message' => 'Redirect saved.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Database error — redirect not saved.' ] );
        }
    }

    /**
     * AJAX handler: delete a redirect by ID.
     * Action: wp_ajax_nexeng_delete_redirect
     */
    public function handle_delete_redirect() {
        $this->verify_request();

        if ( ! \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' ) ) {
            wp_send_json_error( array( 'message' => 'Pro license required.' ) );
        }

        $id = isset( $_POST['redirect_id'] ) ? (int) $_POST['redirect_id'] : 0;
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid redirect ID.' ] );
        }

        $db      = NEXENG_Database::get_instance();
        $blog_id = get_current_blog_id();
        $ok      = $db->delete_redirect( $id, $blog_id );

        if ( $ok ) {
            delete_transient( 'nexeng_redirects_' . $blog_id );
            $this->sync_redirect_htaccess( $blog_id );
            wp_send_json_success( [ 'message' => 'Redirect deleted.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Redirect not found or already deleted.' ] );
        }
    }

    /**
     * AJAX handler: toggle a redirect active/inactive.
     * Action: wp_ajax_nexeng_toggle_redirect
     */
    public function handle_toggle_redirect() {
        $this->verify_request();

        if ( ! \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' ) ) {
            wp_send_json_error( array( 'message' => 'Pro license required.' ) );
        }

        $id        = isset( $_POST['redirect_id'] ) ? (int) $_POST['redirect_id'] : 0;
        $is_active = isset( $_POST['is_active'] )   ? (bool) (int) $_POST['is_active'] : false;

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid redirect ID.' ] );
        }

        $db      = NEXENG_Database::get_instance();
        $blog_id = get_current_blog_id();
        $ok      = $db->toggle_redirect( $id, $blog_id, $is_active );

        if ( $ok ) {
            delete_transient( 'nexeng_redirects_' . $blog_id );
            $this->sync_redirect_htaccess( $blog_id );
            wp_send_json_success( [ 'message' => 'Status updated.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to update redirect status.' ] );
        }
    }

    /**
     * AJAX handler: export all redirects as CSV.
     * Action: wp_ajax_nexeng_export_redirects
     */
    public function handle_export_redirects() {
        $this->verify_request();

        if ( ! \NexoraEngine\Licensing\FeatureGate::is_plan_or_above( 'pro' ) ) {
            wp_send_json_error( array( 'message' => 'Pro license required.' ) );
        }

        $db      = NEXENG_Database::get_instance();
        $blog_id = get_current_blog_id();
        $rows    = $db->get_redirects( $blog_id, 9999, 0 );

        $lines = [ 'Source URL,Target URL,Type,Status,Hits,Notes,Created At' ];
        foreach ( $rows as $r ) {
            $lines[] = sprintf(
                '"%s","%s",%d,%s,%d,"%s","%s"',
                str_replace( '"', '""', $r['source_url'] ),
                str_replace( '"', '""', $r['target_url'] ),
                (int) $r['redirect_type'],
                empty( $r['is_active'] ) ? 'Inactive' : 'Active',
                (int) $r['hit_count'],
                str_replace( '"', '""', $r['notes'] ?? '' ),
                $r['created_at']
            );
        }

        wp_send_json_success( [
            'csv'      => implode( "\n", $lines ),
            'filename' => 'nexora-redirects-' . gmdate( 'Y-m-d' ) . '.csv',
            'count'    => count( $rows ),
        ] );
    }
}
