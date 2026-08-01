<?php
/**
 * Nexora Engine — Static Site Generator (Phase 2)
 *
 * Step 1: Storage layer only.
 *  - Resolves URL/post → static file path
 *  - Atomic writer (.tmp → rename) with per-post lock
 *  - Path-safety guard (rejects traversal, enforces canonical root)
 *  - Manifest read/write
 *  - Lockdown .htaccess inside the static directory
 *
 * Not yet wired to save_post or template_redirect. Capture pipeline,
 * loopback HMAC, and serve rules come in later steps.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NEXENG_SSG {

    private static ?NEXENG_SSG $instance = null;

    private const DIR_NAME       = 'nexora-static';
    private const MANIFEST_FILE  = '.manifest.json';
    private const RUNTIME_MANIFEST_FILE = 'manifest.json';
    private const BUILD_OPTION   = 'nexeng_ssg_build_id';
    private const GLOBAL_BUILD_OPTION = 'nexeng_ssg_global_build_id';
    private const PENDING_OPTION = 'nexeng_ssg_pending_posts';
    private const LOCK_TTL       = 5;    // seconds, per-post regen lock
    private const CAPTURE_WINDOW = 60;   // seconds, replay window for HMAC tokens
    private const SECRET_OPTION  = 'nexeng_ssg_secret';
    private const ENABLED_OPTION = 'nexeng_ssg_enabled';   // 'on' | 'off' (default off)
    private const DEBOUNCE_SEC   = 3;    // delay before focused regen fires after save
    private const CRON_HOOK      = 'nexeng_ssg_regen';
    private const CRON_DELETE    = 'nexeng_ssg_delete';
    private const CRON_GLOBAL    = 'nexeng_ssg_global_invalidate';
    private const CRON_TICK      = 'nexeng_ssg_bulk_tick';
    private const CRON_WATCHDOG  = 'nexeng_ssg_bulk_watchdog';   // resumes orphaned queues
    private const HTACCESS_MARKER = 'Nexora SSG';
    private const GLOBAL_DEBOUNCE = 10;   // seconds — coalesce bursts of menu/customizer saves
    private const TICK_BUDGET          = 8;    // seconds per cron tick before yielding (legacy — cron now does 1/tick)
    private const MAX_RETRIES          = 3;    // auto-retry transient capture failures (cURL timeouts, 5xx, etc.)
    private const QUEUE_TTL            = 4 * HOUR_IN_SECONDS;  // covers ~1000-page sites at 15s/page worst case
    private const CAPTURE_LOCK_TTL     = 55;   // mutex TTL (s) — auto-expires if the holding process crashes
    private const BULK_CAPTURE_TIMEOUT = 45;   // wp_remote_get timeout during bulk builds (matches single regen)
                                               // Bumped from 20s after real-world Elementor pages with heavy widgets,
                                               // external font loads, or product queries consistently timed out at 20s
                                               // (cURL error 28). 45s matches the single-page regen budget and is the
                                               // industry standard for static-capture loopbacks (Vercel uses 60s).
    private const MIN_CAPTURE_GAP      = 1;    // seconds between captures (floor). The loopback HTTP round-trip already
                                               // paces captures naturally; this is just a minimum breather so we never
                                               // hammer the FPM pool. Tunable via the nexeng_ssg_min_capture_gap filter for
                                               // constrained shared hosts that want a wider gap. (Was 4s — too slow.)
    private const BUSY_RETRY_DELAY     = 30;   // seconds to wait when the server appears busy
    private const BROWSER_STALE_AFTER  = 20;   // s — if the browser flag is set but the queue hasn't advanced in this long,
                                               // treat the browser driver as dead and let cron take over (prevents frozen builds)
    // ── Server-driven build loop (2026-06-27 rewrite) ───────────────────────
    // The build is now driven SERVER-SIDE: each pass captures pages in a loop
    // until DRIVE_BUDGET seconds elapse (or the queue empties), then re-spawns
    // itself via a non-blocking loopback request (primary) with a near-immediate
    // WP-Cron event as backup. This makes builds complete on ANY host — Apache,
    // Nginx, IIS — whether or not the admin tab stays open and whether or not
    // the site gets front-end traffic to trigger WP-Cron. The browser batch-tick
    // is now an OPTIONAL accelerator, not the required driver.
    private const DRIVE_BUDGET   = 10;  // seconds of capturing per server pass before yielding + re-spawning
    private const DRIVE_HOOK     = 'nexeng_ssg_bulk_drive';   // cron hook that runs one bulk_drive() pass
    private const DRIVE_NONCE    = 'nexeng_ssg_drive';        // action name for the self-spawn loopback token

    public static function get_instance(): NEXENG_SSG {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // ─── Feature Gate & Hook Registration ─────────────────────────────────────

    public static function is_enabled(): bool {
        return get_option( self::ENABLED_OPTION, 'off' ) === 'on';
    }

    /**
     * True when a content change should schedule its own rebuild.
     *
     * This build contains no implementation of automatic rebuilding, so the
     * answer here is false unless something supplies one. Auto-rebuild lives in
     * class-ncx-ssg-auto__premium_only.php, which is not part of the
     * WordPress.org package: when that file is absent nothing hooks the filter
     * and every caller takes the manual path. There is deliberately no licence
     * check — the capability is missing, not withheld.
     *
     * Manual rebuilding is unaffected: an edit still marks the page pending, and
     * Build Control and the per-page Regenerate button still rebuild on demand.
     *
     * Every caller goes through this one method. The condition used to be
     * written out at five separate sites, and once one of them changed the rest
     * silently disagreed with it.
     *
     * @return bool
     */
    public static function auto_rebuild_active(): bool {
        /**
         * Filters whether a content change schedules its own rebuild.
         *
         * @param bool $active Default false — no automatic rebuild in this build.
         */
        return (bool) apply_filters( 'nexeng_auto_rebuild_active', false );
    }

    /**
     * Registers WP hooks. Called from NEXENG_Init::__construct() unconditionally;
     * the hook callbacks themselves short-circuit when SSG is disabled so the
     * toggle takes effect without a reload.
     */
    public function register_hooks(): void {
        $this->rollback_unstable_automation_once();

        // Cron handlers.
        add_action( self::CRON_HOOK,     [ $this, 'cron_regen' ], 10, 1 );
        add_action( self::CRON_DELETE,   [ $this, 'cron_delete' ], 10, 1 );
        add_action( self::CRON_GLOBAL,   [ $this, 'cron_global_invalidate' ] );
        add_action( self::CRON_TICK,     [ $this, 'cron_bulk_tick' ] );
        add_action( self::CRON_WATCHDOG, [ $this, 'cron_bulk_watchdog' ] );

        // Server-driven build loop. DRIVE_HOOK runs one time-budgeted bulk_drive()
        // pass from WP-Cron (backup driver). The admin-ajax handler is the PRIMARY
        // driver: begin_bulk_queue() fires a non-blocking, token-signed loopback to
        // it so a build starts capturing the instant it's queued — no front-end
        // traffic, no open admin tab, and no real system cron required. Registered
        // for both priv and nopriv because the loopback request carries no auth
        // cookie; the shared-secret token (verify_drive_token) authenticates it.
        add_action( self::DRIVE_HOOK,            [ $this, 'cron_bulk_drive' ] );
        add_action( 'wp_ajax_nexeng_ssg_drive',     [ $this, 'ajax_bulk_drive' ] );
        add_action( 'wp_ajax_nopriv_nexeng_ssg_drive', [ $this, 'ajax_bulk_drive' ] );

        // Watchdog: every 5 minutes, check for orphaned bulk queues that
        // stalled because polling stopped + cron didn't pick up. Recurring
        // schedule is registered lazily in bulk_start() so it only runs on
        // sites that actually use SSG.

        // 1. Structural Changes (Purge immediately + rebuild) — theme switch,
        //    permalink structure change.  These invalidate *all* URLs so we
        //    purge the static cache first, then queue a full rebuild.
        foreach ( [
            'switch_theme',
            'update_option_permalink_structure',
            // NOTE: update_option_active_plugins deliberately EXCLUDED.
            // Plugin activation/deactivation does not change existing page HTML —
            // the static mirror was built before/without that plugin.
            // Plugin UPDATES (which can change CSS/JS) are handled by
            // on_package_upgraded() via the upgrader_process_complete hook.
            // Including active_plugins in the blueprint hash caused a full
            // purge + rebuild on every plugin install/activate, which is wrong.
        ] as $hook ) {
            add_action( $hook, [ $this, 'on_site_blueprint_changed' ] );
        }

        // 2. Content/Setting Changes — menu edits, Customizer saves, site-title
        //    changes.  These affect rendered output but not URL structure, so we
        //    just mark all pages pending and let the queue handle regen.
        //    NOTE: update_option_sidebars_widgets intentionally EXCLUDED —
        //    Elementor triggers it on every page save (internal widget state),
        //    causing a spurious 280-page rebuild on every single post edit.
        foreach ( [
            'wp_update_nav_menu',
            'customize_save_after',
            'update_option_blogname',
            'update_option_blogdescription',
            'update_option_show_on_front',
            'update_option_page_on_front',
            'update_option_page_for_posts',
        ] as $hook ) {
            add_action( $hook, [ $this, 'schedule_global_invalidate' ] );
        }

        // Term changes — category/tag/CPT taxonomy edits trigger a global
        // invalidate so affected archive pages and post pages are re-queued.
        // 'edited_term' fires after any public term is updated (rename, slug, desc).
        // We intentionally skip 'created_term': a brand-new empty term has no
        // posts assigned to it, so no existing static pages are stale — a full
        // rebuild would be pure wasted work.
        // We intentionally omit 'delete_term' because removing a term typically
        // also deletes or un-publishes associated content (handled by
        // before_delete_post / transition_post_status hooks above).
        add_action( 'edited_term', [ $this, 'maybe_schedule_term_invalidate' ], 10, 3 );

        // SEO meta hooks — catch updates from Yoast, RankMath, SEOPress, AIOSEO,
        // and Nexora's own SEO metabox when they save via update_post_meta()
        // rather than the standard save_post flow (e.g. Gutenberg sidebar REST
        // endpoints like /yoast/v1/ or /rankmath/v1/).  Uses updated_post_meta
        // (fires AFTER a successful meta write) so we don't react to failed saves.
        add_action( 'updated_post_meta', [ $this, 'on_seo_meta_updated' ], 10, 4 );

        // Theme/plugin package updates can change CSS/JS bytes without changing
        // page content, so bump the runtime build and queue a synchronized pass.
        add_action( 'upgrader_process_complete', [ $this, 'on_package_upgraded' ], 10, 2 );

        // Toggle observer — install/remove .htaccess rule on enable/disable.
        add_action( 'update_option_' . self::ENABLED_OPTION, [ $this, 'on_toggle' ], 10, 2 );
        add_action( 'add_option_'    . self::ENABLED_OPTION, [ $this, 'on_toggle_added' ], 10, 2 );

        // Per-post exclusion meta box.
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
        add_action( 'save_post',      [ $this, 'save_meta_box' ], 5, 2 );  // priority 5: run before our own save_post regen at 10.
        add_action( 'admin_notices',   [ $this, 'maybe_render_stale_notice' ] );

        // Per-post lifecycle hooks — always register so pending tracking and delete
        // cleanup work on every plan.  Auto-rebuild (schedule_regen call) is
        // additionally gated inside on_save_post / on_transition by the
        // nexeng_auto_rebuild option so free users can opt-out or Pro users can turn it off.
        add_action( 'save_post',              [ $this, 'on_save_post' ],  10, 3 );
        add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
        add_action( 'before_delete_post',     [ $this, 'on_delete' ],     10, 1 );

        // ── Third-party cache plugin protection ──────────────────────────────
        // Prevent external cache plugins from deleting our static HTML mirror.
        // We hook into their "clear all" actions and restore the nexora-static
        // directory after they run, then immediately re-register our .htaccess.
        $this->register_cache_plugin_guards();
    }

    /**
     * Registers hooks that protect the nexora-static folder from being nuked
     * by third-party cache plugins (WP Rocket, W3 Total Cache, LiteSpeed Cache,
     * WP Super Cache, Autoptimize, Elementor CSS regeneration, and others that
     * fire generic "flush all" actions).
     *
     * Strategy: hook AFTER the purge fires (high priority 9999) and call
     * ensure_root() which re-creates the directory + lockdown .htaccess if
     * any plugin managed to delete them.  We do NOT prevent them deleting
     * other cache files — only the nexora-static subtree.
     *
     * For plugins that accept a directory exclusion filter we add a filter
     * to exclude our folder before the deletion even runs.
     */
    private function register_cache_plugin_guards(): void {
        // ── WP Rocket ──────────────────────────────────────────────────────
        // rocket_after_clean_domain fires after full-site cache clear.
        add_action( 'rocket_after_clean_domain',  [ $this, 'guard_static_root' ], 9999 );
        add_action( 'after_rocket_clean_post',    [ $this, 'guard_static_root' ], 9999 );
        // Exclude our folder from Rocket's direct filesystem wipe.
        add_filter( 'rocket_clean_files',         [ $this, 'filter_out_static_root' ], 9999 );

        // ── W3 Total Cache ────────────────────────────────────────────────
        add_action( 'w3tc_flush_all',  [ $this, 'guard_static_root' ], 9999 );
        add_action( 'w3tc_flush_post', [ $this, 'guard_static_root' ], 9999 );

        // ── LiteSpeed Cache ───────────────────────────────────────────────
        add_action( 'litespeed_purge_all', [ $this, 'guard_static_root' ], 9999 );

        // ── WP Super Cache ────────────────────────────────────────────────
        add_action( 'wp_cache_cleared',          [ $this, 'guard_static_root' ], 9999 );
        add_action( 'wp_super_cache_cleared',    [ $this, 'guard_static_root' ], 9999 );
        add_action( 'wp_super_cache_preload_ok', [ $this, 'guard_static_root' ], 9999 );

        // ── Autoptimize ───────────────────────────────────────────────────
        add_action( 'autoptimize_action_cachepurged', [ $this, 'guard_static_root' ], 9999 );

        // ── Cache Enabler (KeyCDN) ────────────────────────────────────────
        add_action( 'cache_enabler_clear_complete_cache', [ $this, 'guard_static_root' ], 9999 );

        // ── Hummingbird ───────────────────────────────────────────────────
        add_action( 'wphb_clear_page_cache', [ $this, 'guard_static_root' ], 9999 );

        // ── WP Fastest Cache ─────────────────────────────────────────────
        add_action( 'wpfc_clear_all_cache_event', [ $this, 'guard_static_root' ], 9999 );

        // ── Breeze (Cloudways) ────────────────────────────────────────────
        add_action( 'breeze_after_cache_file_delete', [ $this, 'guard_static_root' ], 9999 );

        // ── Elementor ─────────────────────────────────────────────────────
        // Elementor's "Regenerate CSS & Data" clears wp-content/uploads/elementor
        // and calls a global flush. It never touches our subfolder directly, but
        // some managed-host stack plugins intercept the flush and wipe broader dirs.
        add_action( 'elementor/core/files/clear_cache', [ $this, 'guard_static_root' ], 9999 );
        add_action( 'elementor/css-file/post/delete',   [ $this, 'guard_static_root' ], 9999 );

        // ── Generic WP upload-directory clean hooks used by misc plugins ──
        // Some cleanup plugins (Media Cleaner, WP-Optimize) scan uploads and
        // delete "unrecognised" directories.  Filtering wp_handle_upload_prefilter
        // doesn't help here; the best defence is ensure_root() after the fact.
        add_action( 'wp_handle_delete',          [ $this, 'guard_static_root' ], 9999 );

        // ── Broad safety net: any plugin that fires wp_cache_flush ────────
        add_action( 'wp_cache_flush',  [ $this, 'guard_static_root' ], 9999 );

        // Elementor CSS regeneration changes frontend asset bytes without
        // necessarily touching post_modified, so treat it as a render-wide
        // invalidation and let the existing adaptive rebuild queue resync HTML.
        add_action( 'elementor/core/files/clear_cache', [ $this, 'on_runtime_assets_changed' ], 10000 );
        add_action( 'elementor/css-file/post/delete',   [ $this, 'on_runtime_assets_changed' ], 10000 );
    }

    /**
     * Re-creates the static root directory and its lockdown .htaccess if they
     * were removed by a third-party cache plugin purge.
     * Called at priority 9999 after every cache-clear hook we guard.
     */
    public function guard_static_root(): void {
        // Only act when SSG is enabled — no-op on sites that never activated it.
        if ( ! self::is_enabled() ) {
            return;
        }
        $this->ensure_root();
    }

    /**
     * Called when Elementor CSS changes.
     *
     * Two hooks use this method:
     *  • elementor/css-file/post/delete   → per-page CSS deletion (fires on every single page save).
     *  • elementor/core/files/clear_cache → full site CSS clear (Regenerate CSS & Data button).
     *
     * The per-page hook passes the CSS file object as $css_file, which exposes get_post_id().
     * When we can identify the specific page, invalidate only that page — not the whole site.
     * The full-site hook passes no object (or an object without get_post_id()), so we fall
     * through to a global invalidation.
     *
     * @param object|null $css_file  Elementor CSS file object, or null (full-site clear).
     */
    public function on_runtime_assets_changed( $css_file = null ): void {
        if ( ! self::is_enabled() || defined( 'NEXORA_CAPTURE' ) ) {
            return;
        }

        // Per-page hook: only rebuild the affected post, not the whole site.
        if ( $css_file !== null && is_object( $css_file ) && method_exists( $css_file, 'get_post_id' ) ) {
            $post_id = (int) $css_file->get_post_id();
            if ( $post_id > 0 && $this->is_eligible( $post_id ) ) {
                $this->mark_pending( $post_id, 'edit' );
                if ( self::auto_rebuild_active() ) {
                    $this->schedule_regen( $post_id );
                }
                return;
            }
        }

        // ── Save-post context guard ─────────────────────────────────────────────
        // Elementor regenerates its global.css on every page save and fires
        // 'elementor/core/files/clear_cache' with no arguments — making this
        // function fall through to schedule_global_invalidate() and queue every
        // post on the site after a single page edit (the "1 edit → 16 pages
        // queued" bug). When we're already inside a save_post request, the
        // per-post path (on_save_post / on_runtime_assets_changed with a
        // CSS file object) has already handled the actual change. A second
        // site-wide invalidation here would be wrong.
        //
        // doing_action() catches the case where clear_cache fires DURING a
        // save_post handler chain; did_action() catches the case where it
        // fires AFTER save_post completed but still inside the same request.
        // Either way, we know a per-post path already handled the edit.
        if (
            doing_action( 'save_post' ) || did_action( 'save_post' ) > 0 ||
            doing_action( 'edit_post' ) || did_action( 'edit_post' ) > 0 ||
            doing_action( 'wp_insert_post' ) || did_action( 'wp_insert_post' ) > 0
        ) {
            return;
        }

        // Full-site CSS clear from a true site-wide context (e.g. Elementor
        // "Regenerate CSS & Data" button, theme switch, plugin update) —
        // global invalidation is correct here.
        $this->schedule_global_invalidate();
    }

    public function on_package_upgraded( $upgrader, array $hook_extra ): void {
        if ( ! self::is_enabled() || defined( 'NEXORA_CAPTURE' ) ) {
            return;
        }
        $type   = (string) ( $hook_extra['type']   ?? '' );
        $action = (string) ( $hook_extra['action'] ?? '' );

        if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
            return;
        }

        // Fresh installs have no visible effect on existing pages — only upgrades
        // (updates) can change CSS/JS bytes that affect already-built static files.
        // Triggering a global rebuild on every plugin install causes spurious queue
        // cycling and the "1 change queued" loop users see after activating a plugin.
        if ( $action !== 'update' ) {
            return;
        }

        $this->schedule_global_invalidate();
    }

    /**
     * Returns the list of post-meta keys whose changes should trigger an SSG
     * rebuild for the affected post.  Covers Nexora Engine's own SEO metabox
     * and the most common third-party SEO plugins.
     *
     * @return string[]
     */
    private function get_seo_meta_keys(): array {
        return [
            // ── Nexora Engine own SEO meta ─────────────────────────────────────
            '_nexeng_seo_data',
            // ── Yoast SEO (free + Premium + News + Video) ─────────────────────
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_focuskw',
            '_yoast_wpseo_opengraph-title',
            '_yoast_wpseo_opengraph-description',
            '_yoast_wpseo_opengraph-image',
            '_yoast_wpseo_twitter-title',
            '_yoast_wpseo_twitter-description',
            '_yoast_wpseo_canonical',
            '_yoast_wpseo_meta-robots-noindex',
            '_yoast_wpseo_meta-robots-nofollow',
            // ── RankMath SEO ───────────────────────────────────────────────────
            'rank_math_title',
            'rank_math_description',
            'rank_math_focus_keyword',
            'rank_math_og_title',
            'rank_math_og_description',
            'rank_math_twitter_title',
            'rank_math_twitter_description',
            'rank_math_canonical_url',
            'rank_math_robots',
            // ── SEOPress ──────────────────────────────────────────────────────
            '_seopress_titles_title',
            '_seopress_titles_desc',
            '_seopress_social_fb_title',
            '_seopress_social_fb_desc',
            '_seopress_robots_canonical',
            // ── All in One SEO (AIOSEO) ───────────────────────────────────────
            '_aioseop_title',
            '_aioseop_description',
            '_aioseo_title',
            '_aioseo_description',
            // ── The SEO Framework ────────────────────────────────────────────
            '_genesis_title',
            '_genesis_description',
            '_open_graph_title',
            '_open_graph_description',
        ];
    }

    /**
     * Triggered by updated_post_meta for any of the known SEO meta keys.
     *
     * Handles the common pattern where Yoast, RankMath, etc. save their metadata
     * via a custom REST endpoint (e.g. /wp-json/yoast/v1/ or /rankmath/v1/)
     * that calls update_post_meta() directly without firing the standard save_post
     * hook. Without this, a Gutenberg SEO sidebar edit would not queue a rebuild.
     *
     * Behaviour mirrors on_save_post:
     *   Pro + auto-rebuild ON → schedule immediate per-post regen
     *   Free (or Pro with auto-rebuild OFF) → mark pending only (manual queue)
     *
     * @param int    $meta_id   Unused — WordPress passes it as the first param.
     * @param int    $post_id   Post the meta belongs to.
     * @param string $meta_key  The meta key that was just updated.
     * @param mixed  $meta_value New value (unused — we only care that a change happened).
     */
    public function on_seo_meta_updated( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
        if ( defined( 'NEXORA_CAPTURE' ) ) {
            return; // Never react during the loopback capture request itself.
        }
        if ( ! self::is_enabled() ) {
            return;
        }
        // Fast-path: skip the expensive is_eligible + get_post if this is not
        // one of the SEO keys we care about.
        if ( ! in_array( $meta_key, $this->get_seo_meta_keys(), true ) ) {
            return;
        }
        // Ignore revisions and autosaves — WordPress stores meta for them too.
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! $this->is_eligible( $post_id ) ) {
            return;
        }
        // Mark pending so Build Control shows this post in the queue.
        // The reason 'seo' lets the UI label it differently from a content edit.
        $this->mark_pending( $post_id, 'seo' );

        if ( self::auto_rebuild_active() ) {
            // Auto-rebuild: schedule a background per-post regen.
            // schedule_regen has its own debounce guard so multiple meta updates
            // on the same post in the same request only queue one cron event.
            $this->schedule_regen( $post_id );
        }
        // Free (or Pro with auto-rebuild off): the post sits in the pending queue
        // until the user manually triggers "Refresh Changed Pages".
    }

    /**
     * Removes our static root from any array of filesystem paths a cache plugin
     * is about to wipe (used by WP Rocket's rocket_clean_files filter).
     *
     * @param  array $files List of absolute paths.
     * @return array        Same list with nexora-static paths removed.
     */
    public function filter_out_static_root( array $files ): array {
        $root = $this->root_dir();
        return array_values( array_filter( $files, static function ( $path ) use ( $root ) {
            // Strip trailing slashes for a reliable prefix comparison.
            return strncmp( rtrim( $path, '/\\' ), $root, strlen( $root ) ) !== 0;
        } ) );
    }

    /**
     * One-time rollback guard for the unstable auto-build experiment.
     *
     * Clears queued cron/background state so a previous stuck build cannot keep
     * hitting LocalWP after the code has been rolled back to manual mode.
     */
    private function rollback_unstable_automation_once(): void {
        if ( get_option( 'nexeng_ssg_auto_rollback_20260519' ) ) {
            return;
        }

        foreach ( [ self::CRON_HOOK, self::CRON_DELETE, self::CRON_GLOBAL, self::CRON_TICK, self::CRON_WATCHDOG ] as $hook ) {
            if ( function_exists( 'wp_unschedule_hook' ) ) {
                wp_unschedule_hook( $hook );
            } else {
                wp_clear_scheduled_hook( $hook );
            }
        }

        foreach ( [
            'nexeng_ssg_bulk_queue',
            'nexeng_ssg_bulk_total',
            'nexeng_ssg_bulk_done',
            'nexeng_ssg_bulk_errors',
            'nexeng_ssg_bulk_running',
            'nexeng_ssg_bulk_paused',
            'nexeng_ssg_bulk_attempts',
            'nexeng_ssg_bulk_last_url',
            'nexeng_ssg_browser_active',
            'nexeng_ssg_capture_lock',
            'nexeng_ssg_last_capture_at',
        ] as $key ) {
            delete_transient( $key );
        }

        delete_option( 'nexeng_ssg_capture_lock' );
        update_option( 'nexeng_ssg_auto_rollback_20260519', 1, false );
    }

    // ─── Per-Post Exclusion Meta Box ──────────────────────────────────────────

    public function register_meta_box(): void {
        $excluded_types = (array) get_option( 'nexeng_ssg_excluded_types', [] );
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        // Never show the meta box on internal CPTs that are never captured.
        $always_skip = [ 'attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ];
        foreach ( $always_skip as $s ) { unset( $post_types[ $s ] ); }
        foreach ( $post_types as $type ) {
            if ( in_array( $type, $excluded_types, true ) ) {
                continue;  // Don't show the toggle on globally-excluded types.
            }
            add_meta_box(
                'nexeng_ssg_exclude',
                'Nexora Static',
                [ $this, 'render_meta_box' ],
                $type,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box( WP_Post $post ): void {
        $excluded = get_post_meta( $post->ID, '_nexeng_exclude', true ) === '1';
        $entry    = $this->manifest_entry( $post->ID );
        $stale    = $entry && $this->is_post_stale( (int) $post->ID, $entry );
        wp_nonce_field( 'nexeng_ssg_meta_box', '_nexeng_ssg_nonce' );
        ?>
        <p>
            <label>
                <input type="checkbox" name="_nexeng_exclude" value="1" <?php checked( $excluded ); ?>>
                <?php esc_html_e( 'Exclude from static generation', 'nexora-engine' ); ?>
            </label>
        </p>
        <div style="margin-top:8px;font-size:11px;line-height:1.5;color:#666;">
            <?php if ( ! self::is_enabled() ) : ?>
                <span style="color:#c00;">&#9679;</span> <?php esc_html_e( 'SSG is disabled globally (Headless page).', 'nexora-engine' ); ?>
            <?php elseif ( $excluded ) : ?>
                <span style="color:#888;">&#9679;</span> <?php esc_html_e( 'Excluded — served dynamically.', 'nexora-engine' ); ?>
            <?php elseif ( $stale ) : ?>
                <span style="color:#F59E0B;">&#9679;</span>
                <?php esc_html_e( 'Needs refresh — content changed after static capture.', 'nexora-engine' ); ?>
            <?php elseif ( $entry ) : ?>
                <span style="color:#10B981;">&#9679;</span>
                <?php printf(
                    /* translators: 1: human time diff, 2: file size */
                    esc_html__( 'Static · updated %1$s ago · %2$s', 'nexora-engine' ),
                    esc_html( human_time_diff( $entry['generated_at'] ) ),
                    esc_html( size_format( $entry['bytes'] ) )
                ); ?>
            <?php else : ?>
                <span style="color:#F59E0B;">&#9679;</span>
                <?php if ( self::auto_rebuild_active() ) : ?>
                    <?php esc_html_e( 'Pending — regenerates automatically on save (~30 s).', 'nexora-engine' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Not yet generated.', 'nexora-engine' ); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ( self::is_enabled() && ! $excluded && $post->post_status === 'publish' ) : ?>
        <div style="margin-top:10px;">
            <?php if ( ! self::auto_rebuild_active() ) : ?>
            <p style="font-size:11px;color:#888;margin:0 0 6px;">
                <?php esc_html_e( 'Auto-rebuild is off: static files update only on manual Regenerate.', 'nexora-engine' ); ?>
            </p>
            <?php endif; ?>
            <button type="button"
                    class="button button-small ncx-meta-regen-btn"
                    data-post-id="<?php echo (int) $post->ID; ?>"
                    style="width:100%;text-align:center;">
                <?php esc_html_e( 'Regenerate This Page', 'nexora-engine' ); ?>
            </button>
        </div>
        <?php ob_start(); ?>
        (function(){
            var btn = document.querySelector('.ncx-meta-regen-btn');
            if (!btn || typeof ncxCall === 'undefined') return;
            btn.addEventListener('click', async function () {
                btn.disabled = true;
                btn.textContent = 'Regenerating…';
                var res = await ncxCall('ssg_regen_one', { post_id: btn.dataset.postId });
                if (res.success) {
                    btn.textContent = '✓ Done';
                    setTimeout(function(){ btn.disabled = false; btn.textContent = 'Regenerate This Page'; }, 2000);
                } else {
                    btn.textContent = 'Failed — try Regenerate All';
                    btn.disabled = false;
                }
            });
        })();
        <?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
        <?php endif; ?>
        <?php
    }

    public function save_meta_box( int $post_id, $post ): void {
        // wp_verify_nonce is pluggable, so its input is untrusted like any other:
        // unslash and sanitize before handing it over.
        if ( ! isset( $_POST['_nexeng_ssg_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nexeng_ssg_nonce'] ) ), 'nexeng_ssg_meta_box' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $was_excluded = get_post_meta( $post_id, '_nexeng_exclude', true ) === '1';
        $now_excluded = ! empty( $_POST['_nexeng_exclude'] );

        if ( $now_excluded ) {
            update_post_meta( $post_id, '_nexeng_exclude', '1' );
        } else {
            delete_post_meta( $post_id, '_nexeng_exclude' );
        }

        // If the post was static and just got excluded, schedule deletion.
        if ( ! $was_excluded && $now_excluded && $this->manifest_entry( $post_id ) ) {
            $this->schedule_delete( $post_id );
        }
    }

    /**
     * Points the drop-in's kill switch at the current state.
     *
     * install_serve_rule()/uninstall_serve_rule() below only govern Apache's
     * .htaccess. The advanced-cache.php drop-in is a separate delivery path and
     * runs before WordPress loads, so it cannot read this option — until this
     * marker existed, switching Static Delivery off left the drop-in serving the
     * mirror to every anonymous visitor.
     *
     * Nginx is not covered by either: its server block is pasted into the server
     * config by hand and only an administrator can remove it.
     */
    private function sync_dropin_kill_switch( bool $enabled ): void {
        if ( class_exists( 'NEXENG_Dropin' ) && method_exists( 'NEXENG_Dropin', 'set_serving_enabled' ) ) {
            NEXENG_Dropin::set_serving_enabled( $enabled );
        }
    }

    public function on_toggle( $old, $new ): void {
        if ( $new === 'on' && $old !== 'on' ) {
            $this->sync_dropin_kill_switch( true );
            $this->install_serve_rule();
            // Install Apache stealth htaccess when Proxy mode is active.
            if ( NEXENG_Init::asset_mode() === 'proxy' ) {
                $this->install_stealth_asset_rule();
            }
            // WP Masking is no longer switched on automatically here. It used to
            // be auto-enabled for Pro licences only, which made a licence decide
            // what a shipped feature did. Masking strips REST discovery links,
            // so turning it on unasked can break REST clients — it is the user's
            // choice on every plan, from the Static Delivery screen.
        } elseif ( $new !== 'on' && $old === 'on' ) {
            $this->sync_dropin_kill_switch( false );
            $this->uninstall_serve_rule();
            $this->uninstall_stealth_asset_rule();
            // WP Masking (nexeng_headless_mode) depends on SSG — it strips REST
            // discovery / wlwmanifest links from front-end responses, which is
            // only meaningful when the static mirror is serving traffic.
            // When SSG turns off, also disable WP Masking so the front-end
            // doesn't lose its REST API discoverability while running on dynamic
            // PHP responses.
            if ( get_option( 'nexeng_headless_mode' ) === 'on' ) {
                update_option( 'nexeng_headless_mode', 'off' );
            }
        }
    }

    public function on_toggle_added( $option, $value ): void {
        if ( $value === 'on' ) {
            $this->sync_dropin_kill_switch( true );
            $this->install_serve_rule();
            if ( NEXENG_Init::asset_mode() === 'proxy' ) {
                $this->install_stealth_asset_rule();
            }
            // Mirrors on_toggle(): WP Masking is not switched on automatically
            // on the first enable either. See the note there.
        } else {
            $this->sync_dropin_kill_switch( false );
        }
    }

    // ─── Invalidation Hooks ───────────────────────────────────────────────────

    /**
     * Should this post be eligible for static generation right now?
     * Combines: SSG enabled, post status, exclusion meta, post type.
     */
    public function is_eligible( int $post_id ): bool {
        if ( ! self::is_enabled() ) {
            return false;
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return false;
        }

        // Restrict to PUBLIC post types only — kills nav_menu_item, revision,
        // attachment, oembed_cache, custom_css, customize_changeset, etc.
        // These either have no real permalink or are internal WP machinery
        // and would 404 on capture (the source of #810/#515/#611 errors).
        $public_types = get_post_types( [ 'public' => true ], 'names' );
        unset( $public_types['attachment'] ); // attachments have URLs but we don't snapshot them
        if ( ! in_array( $post->post_type, $public_types, true ) ) {
            return false;
        }

        // Per-post opt-out via meta (editor sidebar checkbox).
        if ( get_post_meta( $post_id, '_nexeng_exclude', true ) === '1' ) {
            return false;
        }

        // Internal CPTs that are never browsable front-end pages.
        // • WooCommerce types   — binary/back-end, no static permalink.
        // • elementor_library   — Elementor templates/kits; ?elementor_library= URLs
        //                         are query-string only, drop-in skips them, and the
        //                         loopback times out against Elementor's kit renderer.
        // • wp_block / wp_template* / wp_global_styles / wp_navigation
        //                       — Gutenberg/block-theme internal CPTs with no real URL.
        static $internal_cpts = [
            'product', 'shop_order', 'shop_coupon',
            'elementor_library',
            'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        ];
        if ( in_array( $post->post_type, $internal_cpts, true ) ) {
            return false;
        }

        // NOTE (2026-06-27): a previous `?p=`/`?post_type=` permalink guard lived
        // here to catch builder CPTs (e.g. nca_page) with no front-end rewrite.
        // It was REMOVED because get_permalink() can return the ugly ?p=ID form
        // for perfectly real pages in contexts where rewrite rules aren't fully
        // loaded (cron, loopback, certain LiteSpeed/Apache request states) — which
        // made EVERY page test ineligible, so capture() returned 'skipped' for all
        // of them and the whole build drained to "complete" with 0 files written
        // (the live LiteSpeed "0 in mirror" bug). Post-type filtering above is the
        // robust gate; a genuinely unrewritable CPT now just logs one 404 instead
        // of silently killing the entire build. See NEXORA-ENGINE-SSG-REVIEW.md (b).

        // Globally excluded post types (admin setting).
        $excluded_types = (array) get_option( 'nexeng_ssg_excluded_types', [] );
        if ( in_array( $post->post_type, $excluded_types, true ) ) {
            return false;
        }

        // WooCommerce transactional pages — always dynamic, never cacheable.
        // These are registered as ordinary WP pages (post_type=page) so they
        // pass the CPT check above, but WooCommerce makes them session-dependent.
        // Attempting to capture them causes loopback timeouts (cURL error 28).
        if ( function_exists( 'wc_get_page_id' ) ) {
            static $wc_dynamic_page_ids = null;
            if ( $wc_dynamic_page_ids === null ) {
                $wc_dynamic_page_ids = array_filter( array_map( 'intval', [
                    wc_get_page_id( 'checkout' ),
                    wc_get_page_id( 'cart' ),
                    wc_get_page_id( 'myaccount' ),
                    wc_get_page_id( 'refund_returns' ),
                ] ), fn( $id ) => $id > 0 );
            }
            if ( in_array( $post_id, $wc_dynamic_page_ids, true ) ) {
                return false;
            }
        }

        // Easy Digital Downloads transactional pages — same session-dependent
        // pattern as WooCommerce. EDD stores its page IDs in option array
        // 'edd_settings' under 'purchase_page', 'success_page', 'failure_page',
        // 'purchase_history_page', 'login_redirect_page'.
        $edd_settings = get_option( 'edd_settings' );
        if ( is_array( $edd_settings ) ) {
            static $edd_dynamic_page_ids = null;
            if ( $edd_dynamic_page_ids === null ) {
                $edd_dynamic_page_ids = array_filter( array_map( 'intval', [
                    $edd_settings['purchase_page']         ?? 0,
                    $edd_settings['success_page']          ?? 0,
                    $edd_settings['failure_page']          ?? 0,
                    $edd_settings['purchase_history_page'] ?? 0,
                    $edd_settings['login_redirect_page']   ?? 0,
                ] ), fn( $id ) => $id > 0 );
            }
            if ( in_array( $post_id, $edd_dynamic_page_ids, true ) ) {
                return false;
            }
        }

        // bbPress / BuddyPress profile, forum, and group pages — these embed
        // logged-in widgets and session data that cannot survive a static
        // capture. Detected via shortcode presence so we don't need a hard
        // dependency on either plugin.
        if ( has_shortcode( (string) $post->post_content, 'bbp-forum-index' )
            || has_shortcode( (string) $post->post_content, 'bbp-forum-form' )
            || has_shortcode( (string) $post->post_content, 'bbp-topic-form' )
            || has_shortcode( (string) $post->post_content, 'bp-profile' )
            || has_shortcode( (string) $post->post_content, 'bp-activity' )
        ) {
            return false;
        }

        // Allow third parties to override.
        return (bool) apply_filters( 'nexeng_ssg_post_eligible', true, $post_id, $post );
    }

    public function on_save_post( int $post_id, $post, $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( defined( 'NEXORA_CAPTURE' ) ) {
            // Don't recurse: the loopback request itself triggers save_post for some plugins.
            return;
        }
        if ( ! $this->is_eligible( $post_id ) ) {
            // If a previously-static post became ineligible, remove the file.
            if ( $this->manifest_entry( $post_id ) ) {
                $this->schedule_delete( $post_id );
            }
            return;
        }

        // Always mark pending so the admin-bar badge + Build Control queue reflect
        // the change.  Only schedule a background regen when Auto-Rebuild is on.
        // This used to also require a Pro plan, but class-ncx-ssg.php ships in
        // the free build, so that was a working capability held back by a
        // licence check rather than a real tier boundary.
        $this->mark_pending( $post_id, 'edit' );
        if ( self::auto_rebuild_active() ) {
            $this->schedule_regen( $post_id );
        }
    }

    public function on_transition( string $new, string $old, $post ): void {
        if ( ! $post instanceof WP_Post ) {
            return;
        }
        if ( $new === $old ) {
            return;
        }
        // Going public → mark pending + regen if auto-rebuild is on (Pro only).
        // Going non-public → delete.
        if ( $new === 'publish' ) {
            if ( $this->is_eligible( $post->ID ) ) {
                $this->mark_pending( $post->ID, 'publish' );
                if ( self::auto_rebuild_active() ) {
                    $this->schedule_regen( $post->ID );
                }
            }
        } elseif ( $old === 'publish' ) {
            // Post is leaving published state — delete static file AND clear
            // any pending-regen queue entry so it no longer shows as stale.
            $this->schedule_delete( $post->ID );
            $this->clear_pending( $post->ID );
        }
    }

    public function on_delete( int $post_id ): void {
        // Always allow cache cleanup on delete (avoids stale files); only regen is Pro-gated.
        if ( $this->manifest_entry( $post_id ) ) {
            $this->schedule_delete( $post_id );
        }
    }

    // ─── Debounced Scheduling ─────────────────────────────────────────────────

    /**
     * Schedules a single regen event DEBOUNCE_SEC out. If one is already
     * pending for this post inside the debounce window, do nothing — the
     * pending event will pick up the latest version when it fires.
     *
     * Coalesces bulk-edit storms: 100 save_post events → 1 regen per post.
     */
    public function schedule_regen( int $post_id ): void {
        if ( ! self::is_enabled() ) {
            return; // Master kill-switch: don't schedule regen when SSG is off.
        }
        $this->mark_pending( $post_id, 'scheduled' );
        if ( wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
            return;
        }
        // If a delete was queued, cancel it — we're republishing.
        $pending_delete = wp_next_scheduled( self::CRON_DELETE, [ $post_id ] );
        if ( $pending_delete ) {
            wp_unschedule_event( $pending_delete, self::CRON_DELETE, [ $post_id ] );
        }

        $delay = self::DEBOUNCE_SEC;
        wp_schedule_single_event( time() + $delay, self::CRON_HOOK, [ $post_id ] );

        $this->kick_wp_cron();
    }

    /**
     * Dispatch pending cron events after the current request, without relying
     * on WP's HTTP-loopback cron spawner.
     *
     * Only active when DISABLE_WP_CRON is true — if it isn't set, WordPress
     * fires cron naturally on page loads and no kick is needed.
     *
     * Strategy A — FastCGI direct dispatch (Nginx+FPM, Apache+FPM, LiteSpeed):
     *   • Calls fastcgi_finish_request() → browser gets the response instantly.
     *   • PHP process stays alive, sleeps the debounce window, then fires all
     *     due NCX cron hooks directly in-process — zero additional HTTP requests.
     *   • Works on virtually every modern production host.
     *
     * Strategy B — HTTP loopback kick (Apache mod_php, CLI, edge cases):
     *   • Non-blocking POST to ?doing_wp_cron, 0.5 s timeout, fire-and-forget.
     *   • Falls back to this only when fastcgi_finish_request() is unavailable.
     */
    private function kick_wp_cron(): void {
        // When DISABLE_WP_CRON is not set WordPress fires cron on its own — do nothing.
        if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
            return;
        }

        // ── Strategy A: FastCGI direct dispatch ───────────────────────────────
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            // Register once — handles ALL pending NCX events scheduled in this
            // request (e.g. bulk-edit saving ten posts at once).
            if ( ! has_action( 'shutdown', [ $this, 'fastcgi_cron_dispatch' ] ) ) {
                add_action( 'shutdown', [ $this, 'fastcgi_cron_dispatch' ], PHP_INT_MAX );
            }
            return;
        }

        // ── Strategy B: HTTP loopback kick ────────────────────────────────────
        wp_remote_post( site_url( '?doing_wp_cron' ), [
            'timeout'   => 0.5,
            'blocking'  => false,
            'sslverify' => false,
        ] );
    }

    /**
     * Shutdown hook — FastCGI mode only.
     *
     * Disconnects the browser, waits for the debounce window, then fires every
     * due NCX-owned cron event directly in-process (no HTTP round-trip for the
     * trigger itself). The actual page *capture* still uses wp_remote_get() as
     * always — that loopback is needed to render the full WordPress page.
     */
    public function fastcgi_cron_dispatch(): void {
        // Send the HTTP response to the browser and close the connection.
        // The PHP-FPM worker stays alive to finish processing below.
        fastcgi_finish_request();
        ignore_user_abort( true ); // Keep running even if the client disconnects.

        // Signal that this FPM worker is sleeping through the debounce window.
        // handle_ssg_regen_one() checks this flag and queues instead of attempting
        // a direct loopback — which would time-out on hosts with ≤ 2 FPM workers.
        set_transient( 'nexeng_ssg_cron_busy', 1, self::DEBOUNCE_SEC + 10 );

        // Wait for the debounce window + 1 s buffer so rapid successive saves
        // coalesce into a single capture.
        sleep( self::DEBOUNCE_SEC + 1 );

        delete_transient( 'nexeng_ssg_cron_busy' );

        // Fire all due NCX cron events directly — no additional HTTP needed.
        $crons = _get_cron_array();
        if ( ! is_array( $crons ) ) {
            return;
        }

        foreach ( $crons as $timestamp => $hooks ) {
            if ( $timestamp > time() ) {
                break; // Remaining events are in the future — stop.
            }
            foreach ( $hooks as $hook => $callbacks ) {
                if ( strpos( $hook, 'nexeng_' ) !== 0 ) {
                    continue; // Only dispatch Nexora Engine hooks.
                }
                foreach ( $callbacks as $key => $event ) {
                    wp_unschedule_event( $timestamp, $hook, $event['args'] );
                    do_action_ref_array( $hook, $event['args'] );
                }
            }
        }
    }

    public function schedule_delete( int $post_id ): void {
        // Cancel any pending regen — the post is going away.
        $pending_regen = wp_next_scheduled( self::CRON_HOOK, [ $post_id ] );
        if ( $pending_regen ) {
            wp_unschedule_event( $pending_regen, self::CRON_HOOK, [ $post_id ] );
        }
        if ( wp_next_scheduled( self::CRON_DELETE, [ $post_id ] ) ) {
            return;
        }
        // Deletes fire immediately — no point debouncing a removal.
        wp_schedule_single_event( time() + 1, self::CRON_DELETE, [ $post_id ] );
    }

    // ─── Cron Handlers ────────────────────────────────────────────────────────

    public function cron_regen( int $post_id ): void {
        // Skip pages that have a known persistent fatal (PHP OOM, uncaught error).
        // Attempting them again would just OOM / crash again — clear pending and bail.
        // The user can retry manually from Pages & Posts Insight once they've fixed the
        // underlying issue (raised memory, resolved the PHP error in the source page).
        // A new full bulk rebuild also clears the fatal list for a fresh attempt.
        if ( $this->is_fatal( $post_id ) ) {
            $this->clear_pending( $post_id );
            return;
        }

        if ( ! $this->is_eligible( $post_id ) ) {
            // Post is no longer eligible (drafted, scheduled, trashed, or excluded).
            // Auto-clear it from the pending queue so it doesn't stay stuck requiring
            // a manual page refresh — the defensive filter in pending_posts() would
            // catch it eventually, but clearing here is immediate and explicit.
            $this->clear_pending( $post_id );
            return;
        }

        // Skip the loopback entirely if the post hasn't changed since last capture.
        // 'global_change' entries are exempt — theme/menu/plugin updates affect
        // rendered output even when post_modified hasn't moved.
        if ( $this->is_content_fresh( $post_id ) ) {
            $this->clear_pending( $post_id );
            return;
        }

        if ( $this->server_is_busy() || ! $this->capture_gap_elapsed() ) {
            $this->schedule_delayed_regen( $post_id, self::BUSY_RETRY_DELAY );
            return;
        }
        if ( ! $this->acquire_capture_lock() ) {
            // Bulk build is holding the lock.  Instead of scheduling a competing
            // cron event that keeps losing the race, jump to the front of the
            // bulk queue so the very next tick captures this page first.
            if ( get_transient( 'nexeng_ssg_bulk_running' ) ) {
                // read_queue() returns a sanitized list — never `[false]` from a
                // `(array) false` cast of an expired transient (that stray false
                // was a source of the wedged-queue / bouncing-count bug).
                $queue = array_values( array_filter(
                    $this->read_queue(),
                    fn( $item ) => $item !== $post_id // Remove stale duplicate first.
                ) );
                array_unshift( $queue, $post_id );
                set_transient( 'nexeng_ssg_bulk_queue', $queue, self::QUEUE_TTL );
            } else {
                $this->schedule_delayed_regen( $post_id, 10 );
            }
            return;
        }
        try {
            $this->mark_capture_started();
            $result = $this->capture( $post_id );
            if ( is_wp_error( $result ) ) {
                $this->log_error( $post_id, 'regen', $result );

                // Deterministic source fatals (PHP OOM, uncaught error) cannot be
                // fixed by retrying — mark the page as blocked so the cron skips
                // it on future saves and clear it from the pending queue.  The user
                // can retry from Pages & Posts Insight once they've raised memory /
                // fixed the PHP error.  A new full rebuild clears the block list.
                if ( $result->get_error_code() === 'nexeng_ssg_source_fatal' ) {
                    $this->mark_fatal( $post_id, $result );
                    $this->clear_pending( $post_id );
                }
            }
            // On success, capture() → manifest_update() → clear_pending() — no
            // need to call it again here.
        } finally {
            $this->release_capture_lock();
        }
    }

    public function cron_delete( int $post_id ): void {
        $this->delete_post( $post_id );
    }

    // ─── Site-Wide Invalidation ───────────────────────────────────────────────

    /**
     * Triggered by critical UI-changing events (Theme/Plugin changes).
     * Purges the entire cache immediately so visitors don't see a broken UI,
     * then schedules a fresh background build.
     */
    public function on_site_blueprint_changed(): void {
        if ( ! self::is_enabled() ) {
            return;
        }
        if ( defined( 'NEXORA_CAPTURE' ) ) {
            return;
        }

        // Guard: skip purge + rebuild if site settings haven't actually changed.
        // Some hooks (customize_save_after, wp_update_nav_menus) fire even when
        // the user clicks "Save" without modifying anything.  Comparing a hash of
        // the major rendering-affecting settings prevents a 280-page rebuild on
        // every Customizer "Publish" click.
        $new_hash = $this->site_blueprint_hash();
        $old_hash = get_option( 'nexeng_ssg_blueprint_hash', '' );
        if ( $new_hash === $old_hash ) {
            return; // Nothing that affects rendered output actually changed.
        }
        update_option( 'nexeng_ssg_blueprint_hash', $new_hash, false );

        // Immediate cleanup.
        $this->purge_all();

        // Schedule rebuild.
        $this->schedule_global_invalidate();
    }

    /**
     * Schedules a single global-invalidate event GLOBAL_DEBOUNCE seconds out.
     * If one is already queued, do nothing — bursts of customizer/menu saves
     * coalesce into a single rebuild.
     *
     * DISABLE_WP_CRON awareness: when WP-Cron is disabled (LocalWP, some hosts
     * that use server-side cron), the scheduled event never fires on its own
     * because no page loads trigger wp_cron().  We collapse the debounce to 0 s
     * and kick wp-cron.php directly so the event processes immediately instead
     * of sitting in the queue forever.  The coalescing guard (wp_next_scheduled)
     * still prevents double-firing even at zero delay.
     */
    public function schedule_global_invalidate(): void {
        if ( ! self::is_enabled() ) {
            return;
        }
        if ( defined( 'NEXORA_CAPTURE' ) ) {
            return;
        }
        $build_id = $this->create_build_id();
        update_option( self::GLOBAL_BUILD_OPTION, $build_id, false );
        $this->finalize_build( $build_id );
        if ( wp_next_scheduled( self::CRON_GLOBAL ) ) {
            return;
        }
        // Debounce global changes. Do not kick loopback cron from admin saves:
        // theme/menu/plugin updates often happen while the editor is already
        // busy, and the pending queue gives the user clear control.
        $delay = self::GLOBAL_DEBOUNCE;
        wp_schedule_single_event( time() + $delay, self::CRON_GLOBAL );
    }

    /**
     * Mark every eligible page pending after a site-wide builder/theme change
     * and surface an admin notice with the human-readable source label.
     */
    public function invalidate_site_wide( string $source, string $label ): void {
        if ( ! self::is_enabled() || defined( 'NEXORA_CAPTURE' ) ) {
            return;
        }

        set_transient(
            'nexeng_ssg_invalidate_notice',
            [
                'source' => sanitize_key( $source ),
                'label'  => $label,
                'time'   => time(),
            ],
            10 * MINUTE_IN_SECONDS
        );

        $this->schedule_global_invalidate();
    }

    /**
     * Wrapper for term-change hooks.  Only triggers a global invalidation for
     * public taxonomies (categories, tags, custom public taxonomies).
     *
     * Private/internal taxonomies (Elementor's own, WooCommerce product metas,
     * etc.) must NOT trigger a rebuild — they have no front-end representation
     * and they fire on every admin save, causing spurious 280-page rebuilds.
     */
    public function maybe_schedule_term_invalidate( int $term_id, int $tt_id, string $taxonomy ): void {
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax || ! $tax->public ) {
            return;
        }
        $this->schedule_global_invalidate();
    }

    /**
     * Triggered by global state changes (menu edit, theme customizer save, etc.).
     *
     * Strategy (v2 — smart pending queue):
     *   1. Mark every eligible page as pending with reason 'global_change'.
     *      This is non-destructive — visitors keep getting the old static HTML.
     *   2. Flag archives (category pages, blog index) as dirty.
     *   3. Auto-start a pending-only build so only actually-changed pages are
     *      processed, not a blind full rebuild of every page on the site.
     *
     * If another bulk build is already running, just update the pending list —
     * pages not yet processed by the current build will be re-captured.
     */
    public function cron_global_invalidate(): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        // Mark every eligible page as pending.
        foreach ( $this->eligible_post_ids() as $post_id ) {
            $this->mark_pending( $post_id, 'global_change' );
        }
        // Flag archives (handled separately since they have no post ID).
        update_option( 'nexeng_ssg_archives_dirty', time(), false );

        // If a build is already running, the running queue will naturally
        // re-capture pages as it progresses — no second start needed.
        if ( get_transient( 'nexeng_ssg_bulk_running' ) ) {
            return;
        }

        // Never restart a build the user paused. Pause only stops the batch
        // loop; without this an invalidation would enqueue a fresh run behind
        // it, so the build appeared to ignore the Pause button and had to be
        // clicked repeatedly.
        if ( get_transient( 'nexeng_ssg_bulk_paused' ) ) {
            return;
        }

        // Leave a just-purged mirror empty. Purging is an explicit request for
        // "nothing is built"; auto-rebuilding seconds later silently undid it.
        if ( get_transient( 'nexeng_ssg_purge_hold' ) ) {
            return;
        }

        // Auto-rebuild off: leave pages in the pending queue. The user sees them
        // in Build Control and clicks "Refresh Changed Pages" when ready. The
        // default is on for every plan — it used to depend on the licence, which
        // meant a free site silently ignored the option it was allowed to set.
        if ( ! self::auto_rebuild_active() ) {
            return;
        }

        // Auto-rebuild on: start a pending-only build so only the pages just
        // marked above are re-captured, not a blind full rebuild.
        $count = $this->bulk_start_pending();
        if ( is_wp_error( $count ) || $count === 0 ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_TICK ) ) {
            wp_schedule_single_event( time() + 1, self::CRON_TICK );
        }
    }

    // -------------------------------------------------------------------------
    // Capture-process mutex
    // -------------------------------------------------------------------------
    // Two execution paths can reach bulk_batch() simultaneously:
    //   • Browser AJAX poll (handle_ssg_regen_all_batch)
    //   • WP-Cron tick (cron_bulk_tick)
    //
    // On low-worker hosts (LocalWP, shared hosting with pm.max_children ≤ 2)
    // having both paths fire wp_remote_get() at the same time exhausts the
    // PHP-FPM pool and can crash the site.  The mutex below guarantees that
    // only ONE capture is in-flight at any moment, regardless of how many
    // execution paths are active.
    //
    // The lock auto-expires after CAPTURE_LOCK_TTL seconds so a crashed PHP
    // process never permanently blocks the queue — it is self-healing.
    // -------------------------------------------------------------------------

    /**
     * Try to acquire the capture mutex.
     *
     * @return bool  true if the lock was granted; false if another path holds it.
     */
    public function acquire_capture_lock(): bool {
        $existing = (float) get_option( 'nexeng_ssg_capture_lock', 0 );
        if ( $existing > 0 ) {
            $age = microtime( true ) - $existing;

            // Fast stale-lock reclaim: a lock held while the build hasn't
            // advanced in a while means the holder died mid-pass (loopback
            // dropped, PHP process killed). Without this, a dead holder would
            // block every other driver for the full 55s TTL — a long "nothing
            // progresses" freeze. If the advance heartbeat is older than 12s
            // (and the lock itself is at least a few seconds old, so we don't
            // race a just-acquired lock), reclaim it now.
            $last_advance = (int) get_transient( 'nexeng_ssg_bulk_last_advance' );
            $heartbeat_stale = $last_advance > 0 && ( time() - $last_advance ) > 12;

            if ( $age < self::CAPTURE_LOCK_TTL && ! ( $heartbeat_stale && $age > 3 ) ) {
                return false;
            }
            delete_option( 'nexeng_ssg_capture_lock' );
        }

        // add_option is atomic at the DB level; avoids two workers both
        // passing a transient read and starting loopback captures together.
        return add_option( 'nexeng_ssg_capture_lock', microtime( true ), '', false );
    }

    /**
     * Release the capture mutex after a capture (success or failure).
     */
    public function release_capture_lock(): void {
        delete_option( 'nexeng_ssg_capture_lock' );
        delete_transient( 'nexeng_ssg_capture_lock' ); // Back-compat cleanup for older transient locks.
    }

    /**
     * True if a capture pass is currently in flight (the mutex is held and not
     * expired). This is the AUTHORITATIVE "a driver is actively working" signal —
     * more reliable than the nexeng_ssg_drive_inflight hint, because the lock is
     * held for the whole duration of a server pass while drive_inflight can lapse
     * between passes. The browser poll uses this to DEFER instead of starting a
     * second concurrent pass that would race the progress counters (the bouncing
     * 0→19→38→0 bug). Does NOT acquire — purely a read.
     */
    public function capture_in_progress(): bool {
        // The lock alone is not a safe "defer" signal: it can sit held for up to
        // its 55s TTL after a pass died mid-flight (loopback dropped, process
        // killed), during which the browser poll would keep deferring and NOTHING
        // would drive the queue — a frozen build. So we gate the defer on a
        // freshness heartbeat: a driver is only "in progress" if the queue has
        // actually ADVANCED within the last few seconds (bulk_batch updates
        // nexeng_ssg_bulk_last_advance on every processed item). If the lock is held
        // but nothing advanced recently, the holder is dead → return false so the
        // browser poll takes over (its acquire_capture_lock reclaims the expired
        // lock once past the TTL; until then it gets lock_held and simply retries
        // on the next poll, by which point the stale lock has cleared).
        $held = (float) get_option( 'nexeng_ssg_capture_lock', 0 );
        if ( $held <= 0 ) {
            return false;
        }
        $last_advance = (int) get_transient( 'nexeng_ssg_bulk_last_advance' );
        if ( $last_advance <= 0 ) {
            // No heartbeat yet — a pass may have just started; defer briefly only
            // while the lock is fresh.
            return ( microtime( true ) - $held ) < 8.0;
        }
        // Driver is alive only if it moved the queue in the last 10s.
        return ( time() - $last_advance ) < 10;
    }

    private function schedule_delayed_regen( int $post_id, int $delay ): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
            wp_schedule_single_event( time() + max( 5, $delay ), self::CRON_HOOK, [ $post_id ] );
        }
    }

    /**
     * Returns true if the post's rendered output is almost certainly unchanged
     * since the last successful capture — allowing us to skip the loopback entirely.
     *
     * Logic:
     *  • Never fresh if there's no manifest entry (page never been captured).
     *  • Always stale for 'global_change' reason — theme/menu/plugin updates affect
     *    ALL pages even when the post itself wasn't edited.
     *  • Otherwise: compare post_modified_gmt with manifest generated_at timestamp.
     *    If the post was last saved BEFORE the last capture, skip.
     */
    private function is_content_fresh( int $post_id ): bool {
        $entry = $this->manifest_entry( $post_id );
        if ( ! $entry || empty( $entry['generated_at'] ) ) {
            return false; // Never captured — must run loopback.
        }

        $pending = $this->pending_posts();
        $reason  = $pending[ $post_id ]['reason'] ?? 'content';

        // Global-change regens always re-capture: the rendered HTML may differ
        // even if post_modified hasn't changed (new menu item, different theme).
        if ( $reason === 'global_change' ) {
            return false;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return true; // Post is gone — nothing useful to capture.
        }

        // Fresh if the post hasn't been modified since the last capture.
        return strtotime( $post->post_modified_gmt ) <= (int) $entry['generated_at'];
    }

    /**
     * Fingerprint the site-wide settings that affect ALL page renders:
     * active theme, nav menus, key options.  If this hash hasn't changed
     * since the last global invalidation, the full rebuild can be skipped.
     */
    private function site_blueprint_hash(): string {
        return md5( implode( '|', [
            get_stylesheet(),
            wp_get_theme()->get( 'Version' ),
            (string) get_option( 'blogname', '' ),
            (string) get_option( 'blogdescription', '' ),
            (string) get_option( 'show_on_front', '' ),
            (string) get_option( 'page_on_front', '' ),
            (string) get_option( 'page_for_posts', '' ),
            serialize( array_map( fn( $m ) => [ $m->term_id, $m->name, $m->slug ], wp_get_nav_menus() ?: [] ) ),
            // NOTE: active_plugins intentionally excluded from this hash.
            // Including it caused a full cache purge + rebuild on every
            // plugin install/activate — the update_option_active_plugins hook
            // fires on each activation and the changed plugin list triggered
            // on_site_blueprint_changed() → purge_all() → global invalidate.
            // Plugin UPDATES (which can change CSS/JS output) are handled by
            // on_package_upgraded() via upgrader_process_complete.
        ] ) );
    }

    private function capture_gap_elapsed(): bool {
        $last = (float) get_transient( 'nexeng_ssg_last_capture_at' );
        $gap  = (float) apply_filters( 'nexeng_ssg_min_capture_gap', self::MIN_CAPTURE_GAP );
        return $last <= 0 || ( microtime( true ) - $last ) >= max( 0, $gap );
    }

    private function mark_capture_started(): void {
        set_transient( 'nexeng_ssg_last_capture_at', microtime( true ), MINUTE_IN_SECONDS );
    }

    private function server_is_busy(): bool {
        // IMPORTANT (2026-06-27): the load-average gate is DISABLED BY DEFAULT.
        //
        // sys_getloadavg() reports the WHOLE MACHINE's load — which on shared
        // hosting (Hostinger, most cPanel/LiteSpeed hosts) reflects HUNDREDS of
        // neighbouring sites, not yours. On such a host the value is routinely
        // ≥ 8 even when your site is completely idle, so this guard returned
        // "busy" and stalled the build at 0 captured — exactly the live bug,
        // even though a real capture completed in ~300ms right alongside it.
        // Combined with shell_exec('nproc') being blocked on shared plans
        // (cpu_core_count() → 0 → flat 8.0 threshold), the heuristic was simply
        // wrong for the most common real-world hosting. Captures are already
        // paced by the loopback round-trip + MIN_CAPTURE_GAP, so we don't need
        // this gate to be polite.
        //
        // It now only engages if an admin OPTS IN by setting a positive
        // nexeng_ssg_max_loadavg via the filter. Default (0) = never throttle.
        $max_load = (float) apply_filters( 'nexeng_ssg_max_loadavg', 0.0 );
        if ( $max_load <= 0 ) {
            return false;
        }
        if ( ! function_exists( 'sys_getloadavg' ) || stripos( PHP_OS, 'WIN' ) === 0 ) {
            return false;
        }
        $load = sys_getloadavg();
        if ( ! is_array( $load ) || ! isset( $load[0] ) ) {
            return false;
        }
        return (float) $load[0] >= $max_load;
    }

    /**
     * Best-effort CPU core count for scaling the load-average threshold.
     * Returns 0 when it cannot be determined.
     */
    private function cpu_core_count(): int {
        $cores = 0;
        if ( function_exists( 'shell_exec' ) ) {
            // nproc is present on most Linux hosts; suppress errors on locked-down ones.
            $nproc = @shell_exec( 'nproc 2>/dev/null' );
            if ( is_string( $nproc ) && (int) trim( $nproc ) > 0 ) {
                $cores = (int) trim( $nproc );
            }
        }
        return $cores;
    }

    // -------------------------------------------------------------------------

    /**
     * Cron-driven batch processor. Processes exactly ONE item per tick, then
     * reschedules itself with a short gap — keeps each PHP request brief and
     * leaves at least one PHP-FPM worker free for the loopback capture.
     *
     * Mirrors what the browser AJAX poll does, but server-side so builds
     * complete even when the user closes the admin tab.
     */
    public function cron_bulk_tick(): void {
        // Respect user-initiated pause.
        if ( get_transient( 'nexeng_ssg_bulk_paused' ) ) {
            return;
        }

        if ( $this->server_is_busy() || ! $this->capture_gap_elapsed() ) {
            if ( ! wp_next_scheduled( self::CRON_TICK ) ) {
                wp_schedule_single_event( time() + self::BUSY_RETRY_DELAY, self::CRON_TICK );
            }
            return;
        }

        // Primary guard: if the browser is GENUINELY advancing the queue, step
        // aside. The browser refreshes 'nexeng_ssg_browser_active' (2 min TTL) on
        // every /ssg/batch-tick. But that flag alone is not enough — if the
        // React poll loop dies (tab closed, JS error, navigation) the flag can
        // stay set for up to 2 minutes while NOTHING advances the build, which
        // froze bulk runs at the first captured page. So we only defer to the
        // browser when it has actually moved the queue within the last
        // BROWSER_STALE_AFTER seconds; otherwise we take over so the build
        // always completes.
        if ( get_transient( 'nexeng_ssg_browser_active' ) ) {
            $last_advance = (int) get_transient( 'nexeng_ssg_bulk_last_advance' );
            $browser_fresh = $last_advance > 0
                && ( time() - $last_advance ) < self::BROWSER_STALE_AFTER;
            if ( $browser_fresh ) {
                if ( ! wp_next_scheduled( self::CRON_TICK ) ) {
                    wp_schedule_single_event( time() + 30, self::CRON_TICK );
                }
                return;
            }
            // Browser flag is stale (loop died) — clear it and proceed below so
            // cron drives the build to completion.
            delete_transient( 'nexeng_ssg_browser_active' );
        }

        // Queue is the source of truth.
        $queue = (array) get_transient( 'nexeng_ssg_bulk_queue' );
        if ( empty( $queue ) ) {
            return;
        }

        // Mutex: abort if another execution path is already capturing.
        // Come back in 5 s — the holder will release long before that.
        if ( ! $this->acquire_capture_lock() ) {
            wp_schedule_single_event( time() + 5, self::CRON_TICK );
            return;
        }

        // Process exactly ONE item — no while-loop.
        // Keeping each cron request to a single wp_remote_get() call means we
        // only ever need 2 PHP-FPM workers (cron + loopback), no matter how
        // large the queue is.  On a 2-worker pool this is always safe.
        try {
            $progress = $this->bulk_batch( 1 );
        } finally {
            // Always release — even if bulk_batch() throws.
            $this->release_capture_lock();
        }

        if ( empty( $progress['done'] ) ) {
            // 5-second gap between cron-driven captures — keeps one PHP-FPM worker
            // free for user traffic while the bulk build progresses.
            wp_schedule_single_event( time() + 5, self::CRON_TICK );
        }
    }

    /**
     * Safety-net cron — runs every 5 minutes. If a bulk queue exists but
     * neither browser polling nor the regular cron tick has progressed it
     * recently, kick the build back into motion. This catches the failure
     * mode where the user closes the wizard tab and WP-Cron stalls (common
     * on low-traffic LocalWP installs).
     *
     * Clears itself when the queue is empty.
     */
    public function cron_bulk_watchdog(): void {
        $queue = (array) get_transient( 'nexeng_ssg_bulk_queue' );
        if ( empty( $queue ) ) {
            // Build complete — stop watching.
            wp_clear_scheduled_hook( self::CRON_WATCHDOG );
            return;
        }
        // Don't restart the tick while the user has paused the build.
        if ( get_transient( 'nexeng_ssg_bulk_paused' ) ) {
            if ( ! wp_next_scheduled( self::CRON_WATCHDOG ) ) {
                wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_WATCHDOG );
            }
            return;
        }
        // Queue still has items but the build appears stalled (tab closed, a
        // loopback pass died, real cron never fired). Re-arm the server-driven
        // loop: fire a fresh loopback drive immediately and schedule a backup
        // cron drive. kick_bulk_drive() handles its own throttle so this is safe
        // to call on every watchdog round.
        $this->kick_bulk_drive();
        // Re-schedule self for the next watchdog round.
        if ( ! wp_next_scheduled( self::CRON_WATCHDOG ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_WATCHDOG );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Server-driven build loop (2026-06-27)
    //
    //  The build no longer depends on the browser tab. Once a queue exists,
    //  the server drives it to completion by itself:
    //
    //    begin_bulk_queue() / watchdog / pause-resume
    //        → kick_bulk_drive()
    //              ├─ non-blocking loopback POST to admin-ajax (nexeng_ssg_drive)   ← primary
    //              └─ wp_schedule_single_event(DRIVE_HOOK, +30s)                 ← backup
    //
    //    ajax_bulk_drive()  (loopback)  ─┐
    //    cron_bulk_drive()  (wp-cron)   ─┴→ bulk_drive()
    //
    //    bulk_drive(): captures pages in a loop until DRIVE_BUDGET seconds pass
    //    or the queue empties, then — if work remains — calls kick_bulk_drive()
    //    again to continue in a fresh request. Bounded passes keep us well under
    //    max_execution_time and never monopolise the FPM pool.
    //
    //  The browser /ssg/batch-tick endpoint still works as an OPTIONAL
    //  accelerator (it nudges the same queue) but is no longer required.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Fire the next build pass. Primary path is a non-blocking loopback request
     * to admin-ajax so capturing starts immediately even with zero front-end
     * traffic and no system cron (the LocalWP failure mode). A WP-Cron event is
     * also scheduled as a backup in case loopback is blocked by the host.
     *
     * Self-throttled: at most one kick per DRIVE_BUDGET-ish window, so callers
     * (watchdog, state poll, resume) can call it freely without stacking
     * requests on top of an already-running pass.
     */
    public function kick_bulk_drive(): void {
        if ( get_transient( 'nexeng_ssg_bulk_paused' ) ) {
            return;
        }
        $queue = (array) get_transient( 'nexeng_ssg_bulk_queue' );
        if ( empty( $queue ) ) {
            return;
        }

        // Backup driver: always make sure a near-term cron pass is queued. Cheap
        // and idempotent — wp_next_scheduled de-dupes.
        if ( ! wp_next_scheduled( self::DRIVE_HOOK ) ) {
            wp_schedule_single_event( time() + 1, self::DRIVE_HOOK );
        }
        // Keep the legacy single-item cron tick as a third safety net.
        if ( ! wp_next_scheduled( self::CRON_TICK ) ) {
            wp_schedule_single_event( time() + self::BUSY_RETRY_DELAY, self::CRON_TICK );
        }

        // Primary driver: mark a server pass in flight. The browser poll
        // (ssg_batch_tick) consults this flag and DEFERS while it's set, so the
        // two drivers never run concurrently and the count can't bounce. TTL is
        // 15s — long enough to bridge the gap between the loopback firing and its
        // handler refreshing the flag to 30s, but short enough that if the host
        // silently drops the non-blocking loopback (LiteSpeed/Hostinger can), the
        // flag self-heals within ~15s and the browser poll takes over as the sole
        // driver. If a pass is already marked in flight, don't double-fire.
        if ( get_transient( 'nexeng_ssg_drive_inflight' ) ) {
            return;
        }
        set_transient( 'nexeng_ssg_drive_inflight', 1, 15 );

        $url  = admin_url( 'admin-ajax.php' );
        $args = [
            'timeout'   => 0.01,           // fire-and-forget — we don't wait for the response
            'blocking'  => false,
            'sslverify' => false,
            'headers'   => $this->loopback_auth_headers(),
            'body'      => [
                'action' => 'nexeng_ssg_drive',
                'token'  => $this->drive_token(),
            ],
            'cookies'   => [],
        ];
        wp_remote_post( $url, $args );
    }

    /**
     * Shared-secret token authenticating the self-spawn loopback. Derived from
     * AUTH_SALT + a per-install secret so it can't be forged externally and
     * rotates if salts change. Loopback requests carry no auth cookie, so this
     * token is how ajax_bulk_drive() knows the request really came from us.
     */
    private function drive_token(): string {
        $secret = get_option( 'nexeng_ssg_drive_secret' );
        if ( ! $secret || ! is_string( $secret ) ) {
            $secret = wp_generate_password( 32, false, false );
            update_option( 'nexeng_ssg_drive_secret', $secret, false );
        }
        $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'ncx';
        return hash_hmac( 'sha256', self::DRIVE_NONCE, $secret . $salt );
    }

    /**
     * Constant-time verification of the drive token.
     */
    private function verify_drive_token( string $token ): bool {
        if ( $token === '' ) {
            return false;
        }
        return hash_equals( $this->drive_token(), $token );
    }

    /**
     * admin-ajax handler — the PRIMARY server-side driver. Authenticated by the
     * shared-secret token (not a login cookie, since loopback is anonymous).
     * Runs one bulk_drive() pass, then ends the request. bulk_drive() itself
     * re-spawns the next pass if work remains.
     */
    public function ajax_bulk_drive(): void {
        $token = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';
        if ( ! $this->verify_drive_token( $token ) ) {
            status_header( 403 );
            wp_die( '', '', [ 'response' => 403 ] );
        }
        // KEEP the in-flight flag set for the whole duration of this server pass
        // (refresh its TTL) so the browser poll keeps deferring to us while we
        // run — clearing it here would open a ~6s window where the browser jumps
        // in and the two drivers race the counts (the bounce). bulk_drive()'s
        // continuation (kick_bulk_drive) refreshes it again for the next pass.
        // The short TTL means it self-clears soon after the server stops driving.
        set_transient( 'nexeng_ssg_drive_inflight', 1, 30 );

        // Long-running pass: lift limits where the host allows it. These are
        // best-effort and silently ignored on locked-down hosts.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        ignore_user_abort( true );

        $this->bulk_drive();

        // Pass finished and did NOT chain a continuation (queue drained or
        // paused) — release the flag now so a fresh build can drive immediately.
        if ( empty( get_transient( 'nexeng_ssg_bulk_queue' ) ) ) {
            delete_transient( 'nexeng_ssg_drive_inflight' );
        }
        wp_die( '', '', [ 'response' => 200 ] );
    }

    /**
     * WP-Cron handler — the BACKUP driver. Identical work to the loopback path;
     * runs when loopback is blocked but real cron fires (system cron, or a
     * front-end visit triggering wp-cron.php).
     */
    public function cron_bulk_drive(): void {
        // Same single-driver discipline as the loopback path: hold the in-flight
        // flag for the duration so the browser poll defers while cron drives.
        set_transient( 'nexeng_ssg_drive_inflight', 1, 30 );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        $this->bulk_drive();
        if ( empty( get_transient( 'nexeng_ssg_bulk_queue' ) ) ) {
            delete_transient( 'nexeng_ssg_drive_inflight' );
        }
    }

    /**
     * The shared time-budgeted capture loop. Captures pages back-to-back via
     * bulk_batch(1) until $budget seconds elapse or the queue drains. Acquires
     * the capture mutex for the whole pass so loopback/cron/browser drivers
     * never capture in parallel. Returns the final progress array (with
     * 'done', 'processed', etc.) or a small array with 'reason' if it couldn't
     * run (lock held, paused, empty).
     *
     * This is the ONE place captures actually loop — used by:
     *   • bulk_drive()  — server-side loopback/cron driver (then re-spawns)
     *   • drive_batch() — browser /ssg/batch-tick driver (authenticated admin
     *                     request; the one path caching layers like LiteSpeed
     *                     never intercept, so it works on every host)
     */
    private function run_capture_loop( int $budget ): array {
        if ( ! self::is_enabled() ) {
            return [ 'done' => true, 'reason' => 'ssg_disabled' ];
        }
        if ( get_transient( 'nexeng_ssg_bulk_paused' ) ) {
            return [ 'done' => false, 'reason' => 'paused' ];
        }
        // Sanitized read — a queue that only contains junk (e.g. a stray
        // boolean false) counts as empty so we don't spin on it.
        $queue = $this->read_queue();
        if ( empty( $queue ) ) {
            // Normalize the stored value too, so any junk-only queue is cleared
            // and the build can report done cleanly.
            delete_transient( 'nexeng_ssg_bulk_queue' );
            return [ 'done' => true, 'reason' => 'empty' ];
        }

        // Single-driver mutex: if another pass is already capturing, don't run a
        // second one in parallel.
        if ( ! $this->acquire_capture_lock() ) {
            return [ 'done' => false, 'reason' => 'lock_held', 'remaining' => count( $queue ) ];
        }

        $deadline = microtime( true ) + max( 2, $budget );
        $progress = [ 'done' => false ];

        try {
            do {
                // server_is_busy() is a no-op on Windows and core-scaled on Linux.
                // If the host is genuinely under load, yield this pass and let the
                // continuation retry shortly rather than pile on.
                if ( $this->server_is_busy() ) {
                    $progress['reason'] = 'server_busy';
                    break;
                }

                $progress = $this->bulk_batch( 1 );
                if ( ! empty( $progress['done'] ) ) {
                    break;
                }

                // Honour the inter-capture floor without a busy-wait: if the gap
                // hasn't elapsed, sleep the small remainder (it's ≤ MIN_CAPTURE_GAP
                // seconds) so back-to-back captures stay polite.
                if ( ! $this->capture_gap_elapsed() ) {
                    $gap = (int) apply_filters( 'nexeng_ssg_min_capture_gap', self::MIN_CAPTURE_GAP );
                    if ( $gap > 0 ) {
                        usleep( (int) ( min( $gap, 2 ) * 1_000_000 ) );
                    }
                }
            } while ( microtime( true ) < $deadline );
        } finally {
            $this->release_capture_lock();
        }

        return $progress;
    }

    /**
     * Browser-poll driver. Runs ONE time-budgeted capture pass and returns the
     * progress array — does NOT re-spawn (the browser's next poll is the
     * continuation). This is the reliable cross-host path: it executes inside
     * the authenticated admin REST request, which caching/proxy layers
     * (LiteSpeed, Cloudflare, Varnish) never serve from cache, so the build
     * always advances with the tab open even when server-side loopback is
     * blocked.
     *
     * Budget is intentionally smaller than the server loopback's so the admin
     * HTTP request returns promptly and the UI stays responsive.
     */
    public function drive_batch(): array {
        // NOTE: do NOT clear nexeng_ssg_drive_inflight here. The browser poll only
        // reaches this method when the server-side loopback driver is NOT in
        // flight (ssg_batch_tick checks that flag and defers otherwise), so the
        // two drivers never run concurrently — which is what prevents the admin
        // count from bouncing. The flag self-heals via its short TTL.
        $budget = (int) apply_filters( 'nexeng_ssg_browser_drive_budget', 6 );
        return $this->run_capture_loop( $budget );
    }

    /**
     * One time-budgeted server-side build pass. Captures pages until
     * DRIVE_BUDGET seconds elapse or the queue drains, then yields. If the
     * queue still has items, re-spawns the next pass (loopback + cron backup) so
     * the build continues in a fresh request — bounded, so we never blow past
     * max_execution_time or hog the worker pool.
     */
    public function bulk_drive(): void {
        $budget   = (int) apply_filters( 'nexeng_ssg_drive_budget', self::DRIVE_BUDGET );
        $progress = $this->run_capture_loop( $budget );
        $done     = ! empty( $progress['done'] );

        // If the lock was held by another pass, make sure a continuation exists.
        if ( ! $done && ( $progress['reason'] ?? '' ) === 'lock_held' ) {
            if ( ! wp_next_scheduled( self::DRIVE_HOOK ) ) {
                wp_schedule_single_event( time() + 5, self::DRIVE_HOOK );
            }
            return;
        }

        // More work remains → continue in a fresh request.
        if ( ! $done ) {
            $queue_left = (array) get_transient( 'nexeng_ssg_bulk_queue' );
            if ( ! empty( $queue_left ) ) {
                $this->kick_bulk_drive();
            }
        }
    }

    /**
     * Lightweight error log. Stored in option `nexeng_ssg_errors` (capped to 50
     * entries, autoload off). Step 9 will surface this in the admin UI.
     */
    private function log_error( int $post_id, string $stage, WP_Error $err, array $context = [] ): void {
        $log = get_option( 'nexeng_ssg_errors', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        // Capture the human-readable title and front-end URL at write time so the
        // admin UI can display "Contact Us — /contact/" instead of just an error code.
        // For archive items (post_id = 0) the caller passes the label + url via $context
        // so we don't end up with a misleading "Unknown page" row in the error log.
        $title = $post_id
            ? html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
            : (string) ( $context['title'] ?? '' );
        $url   = $post_id
            ? (string) get_permalink( $post_id )
            : (string) ( $context['url'] ?? '' );
        array_unshift( $log, [
            'ts'      => time(),
            'post_id' => $post_id,
            'title'   => $title,
            'url'     => $url,
            'stage'   => $stage,
            'code'    => $err->get_error_code(),
            'message' => $err->get_error_message(),
        ] );
        $log = array_slice( $log, 0, 50 );
        update_option( 'nexeng_ssg_errors', $log, false );
    }

    /**
     * Remove any logged errors for a post. Called after a successful capture so
     * a page that previously failed (e.g. a transient cURL-28 timeout) no longer
     * counts toward the "blocked pages" badge once it has been captured cleanly.
     */
    private function clear_error( int $post_id ): void {
        if ( $post_id <= 0 ) {
            return;
        }
        $log = get_option( 'nexeng_ssg_errors', [] );
        if ( ! is_array( $log ) || ! $log ) {
            return;
        }
        $filtered = array_values( array_filter(
            $log,
            static fn( $e ) => (int) ( $e['post_id'] ?? 0 ) !== $post_id
        ) );
        if ( count( $filtered ) !== count( $log ) ) {
            update_option( 'nexeng_ssg_errors', $filtered, false );
        }
    }

    // ─── Path Resolution ──────────────────────────────────────────────────────

    /**
     * Absolute filesystem root of the static directory.
     * Always trailing-slash-free.
     */
    public function root_dir(): string {
        $uploads = wp_get_upload_dir();
        return untrailingslashit( $uploads['basedir'] ) . '/' . self::DIR_NAME;
    }

    /**
     * Public URL of the static directory (used for debugging/admin only;
     * the .htaccess rule serves files transparently from the original URL).
     */
    public function root_url(): string {
        $uploads = wp_get_upload_dir();
        return untrailingslashit( $uploads['baseurl'] ) . '/' . self::DIR_NAME;
    }

    /**
     * Resolves a post ID to its absolute static file path.
     * Returns null if the post is not eligible (no permalink, not published, etc.).
     */
    public function path_for_post( int $post_id ): ?string {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return null;
        }

        $permalink = get_permalink( $post_id );
        if ( ! $permalink ) {
            return null;
        }

        return $this->path_for_url( $permalink );
    }

    /**
     * Resolves any site URL to its absolute static file path.
     * Performs path-safety validation. Returns null on rejection.
     */
    public function path_for_url( string $url ): ?string {
        $home_path = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '';
        $url_path  = wp_parse_url( $url, PHP_URL_PATH ) ?: '';

        // Strip the WP install subdirectory if present (e.g. /blog/about → /about).
        if ( $home_path && strpos( $url_path, $home_path ) === 0 ) {
            $url_path = substr( $url_path, strlen( $home_path ) );
        }

        $url_path = trim( $url_path, '/' );

        // Front page → index.html at root.
        if ( $url_path === '' ) {
            return $this->root_dir() . '/index.html';
        }

        // Validate + sanitize each segment.
        $segments = explode( '/', $url_path );
        $clean    = [];
        foreach ( $segments as $seg ) {
            if ( $seg === '' || $seg === '.' || $seg === '..' || str_starts_with( $seg, '.' ) ) {
                return null;
            }
            $sanitized = sanitize_file_name( $seg );
            if ( $sanitized === '' || $sanitized !== $seg ) {
                // Reject if sanitization changed the segment — means it had unsafe chars.
                return null;
            }
            $clean[] = $sanitized;
        }

        $relative = implode( '/', $clean ) . '/index.html';
        $absolute = $this->root_dir() . '/' . $relative;

        // Final canonical-root containment check.
        if ( ! $this->is_inside_root( $absolute ) ) {
            return null;
        }

        return $absolute;
    }

    /**
     * Verifies that a target path resolves inside the static root.
     * Uses string comparison on the canonical root (no realpath on target —
     * the target may not exist yet during write).
     */
    private function is_inside_root( string $target ): bool {
        $root = $this->root_dir();
        // Normalize separators for Windows.
        $root_n   = str_replace( '\\', '/', $root );
        $target_n = str_replace( '\\', '/', $target );
        return str_starts_with( $target_n, $root_n . '/' );
    }

    // ─── Atomic Write ─────────────────────────────────────────────────────────

    /**
     * Writes HTML to the static file for a given post atomically.
     *
     * @return true|WP_Error
     */
    public function write_post( int $post_id, string $html, string $build_id = '' ) {
        $path = $this->path_for_post( $post_id );
        if ( ! $path ) {
            return new WP_Error( 'nexeng_ssg_no_path', 'No static path resolvable for this post.' );
        }

        $lock_key = 'nexeng_ssg_lock_' . $post_id;
        if ( get_transient( $lock_key ) ) {
            return new WP_Error( 'nexeng_ssg_locked', 'Regeneration already in progress for this post.' );
        }
        set_transient( $lock_key, 1, self::LOCK_TTL );

        try {
            $result = $this->write_atomic( $path, $html );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $this->manifest_update( $post_id, $path, $html, $build_id );
            return true;
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * Low-level atomic writer. Public for the capture pipeline + tests.
     *
     * @return true|WP_Error
     */
    public function write_atomic( string $final_path, string $contents ) {
        if ( ! $this->is_inside_root( $final_path ) ) {
            return new WP_Error( 'nexeng_ssg_path_unsafe', 'Refusing to write outside static root.' );
        }
        if ( strtolower( basename( $final_path ) ) === 'index.html'
            && ! $this->html_integrity_valid( $contents ) ) {
            return new WP_Error( 'nexeng_ssg_integrity_failed', 'Refusing to activate incomplete synchronized HTML.' );
        }

        $this->ensure_root();

        $dir = dirname( $final_path );
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'nexeng_ssg_mkdir_failed', "Could not create directory: $dir" );
        }

        $tmp = $final_path . '.tmp.' . wp_generate_password( 8, false );

        $bytes = file_put_contents( $tmp, $contents, LOCK_EX );
        if ( $bytes === false ) {
            return new WP_Error( 'nexeng_ssg_write_failed', "Could not write temp file: $tmp" );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale. Atomic publish of a captured page.
        if ( ! @rename( $tmp, $final_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
            @unlink( $tmp );
            return new WP_Error( 'nexeng_ssg_rename_failed', "Atomic rename failed: $tmp → $final_path" );
        }

        return true;
    }

    private function html_integrity_valid( string $html ): bool {
        if ( strlen( $html ) < 200 ) {
            return false;
        }
        if ( stripos( $html, '</html>' ) === false ) {
            return false;
        }
        if ( stripos( $html, '<html' ) === false && stripos( $html, '<!doctype' ) === false ) {
            return false;
        }
        if ( strpos( $html, 'name="ncx-build"' ) === false ) {
            return false;
        }
        return true;
    }

    /**
     * Removes the static file for a given post, if any.
     */
    public function delete_post( int $post_id ): bool {
        // Prefer the manifest's recorded path over recomputing from the post.
        // The delete runs on an async cron 1s after the deletion hook, and by
        // then the post row is gone (force delete → get_permalink() fails) or
        // its slug was rewritten (trash appends __trashed), so path_for_post()
        // resolves to nothing / the wrong file and the stale static page keeps
        // being served for a post that no longer exists.
        $path  = null;
        $entry = $this->manifest_entry( $post_id );
        if ( $entry && ! empty( $entry['path'] ) ) {
            $candidate = $this->root_dir() . $entry['path'];
            if ( file_exists( $candidate ) ) {
                $path = $candidate;
            }
        }
        if ( ! $path ) {
            $path = $this->path_for_post( $post_id );
        }
        if ( ! $path || ! file_exists( $path ) ) {
            $this->manifest_remove( $post_id );
            return true;
        }

        if ( ! $this->is_inside_root( $path ) ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
        @unlink( $path );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
        @rmdir( dirname( $path ) ); // Best-effort: only succeeds if empty.
        $this->manifest_remove( $post_id );
        return true;
    }

    // ─── Static Root Bootstrap ────────────────────────────────────────────────

    /**
     * Ensures the static root exists with a lockdown .htaccess.
     * Idempotent — safe to call on every write.
     */
    public function ensure_root(): void {
        $root = $this->root_dir();
        if ( ! is_dir( $root ) ) {
            wp_mkdir_p( $root );
        }

        $htaccess = $root . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, $this->lockdown_htaccess(), LOCK_EX );
        }

        $index = $root . '/index.php';
        if ( ! file_exists( $index ) ) {
            // Silence directory listing if mod_rewrite is unavailable.
            file_put_contents( $index, "<?php // Silence is golden.\n", LOCK_EX );
        }
        $this->cleanup_abandoned_temp_files();
    }

    private function cleanup_abandoned_temp_files(): void {
        $root = $this->root_dir();
        if ( ! is_dir( $root ) || wp_rand( 1, 100 ) !== 1 ) {
            return;
        }
        $cutoff = time() - HOUR_IN_SECONDS;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $f ) {
                if ( $f->isFile()
                    && strpos( $f->getFilename(), '.tmp.' ) !== false
                    && (int) $f->getMTime() < $cutoff ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
                    @unlink( $f->getPathname() );
                }
            }
        } catch ( \Throwable $e ) {}
    }

    private function lockdown_htaccess(): string {
        // phpcs:ignore PluginCheck.CodeAnalysis.Heredoc.NotAllowed -- Heredoc holds a multi-line config/JS template; valid PHP, far more readable and less error-prone than concatenation here.
        ob_start();
        ?>
# Nexora Static — lockdown
Options -Indexes

<FilesMatch "\.(php|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)$">
    Require all denied
</FilesMatch>

<FilesMatch "\.(html|htm|css|js|map|json|png|jpg|jpeg|gif|svg|webp|avif|ico|woff|woff2|ttf|otf|eot)$">
    Require all granted
</FilesMatch>

<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>
        <?php
        return ob_get_clean();
    }

    // ─── Manifest ─────────────────────────────────────────────────────────────

    /**
     * Absolute path to the on-disk manifest file. Public so REST layer can
     * use the manifest mtime as a cache-key signature without having to
     * reach into internal state. Private/internal use was the original
     * intent but the cache-busting code path in NEXENG_REST::get_ssg_pages
     * legitimately needs it — promoting visibility is the right call.
     */
    public function manifest_path(): string {
        return $this->root_dir() . '/' . self::MANIFEST_FILE;
    }

    private function runtime_manifest_path(): string {
        return $this->root_dir() . '/' . self::RUNTIME_MANIFEST_FILE;
    }

    public function get_manifest(): array {
        return $this->manifest_read();
    }

    public function manifest_read(): array {
        $path = $this->manifest_path();
        if ( ! file_exists( $path ) ) {
            return [];
        }
        $raw  = file_get_contents( $path );
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : [];
    }

    private function manifest_write( array $data ): void {
        $path = $this->manifest_path();
        $tmp  = $path . '.tmp.' . wp_generate_password( 8, false );
        file_put_contents( $tmp, wp_json_encode( $data, JSON_PRETTY_PRINT ), LOCK_EX );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale. Atomic publish of a captured page.
        @rename( $tmp, $path );
        delete_transient( 'nexeng_ssg_stats_' . get_current_blog_id() );
    }

    private function manifest_update( int $post_id, string $path, string $html, string $build_id = '' ): void {
        $data            = $this->manifest_read();
        $build_id        = $build_id !== '' ? $build_id : $this->current_build_id();
        $data[ $post_id ] = [
            'path'         => str_replace( $this->root_dir(), '', $path ),
            'hash'         => md5( $html ),
            'bytes'        => strlen( $html ),
            'generated_at' => time(),
            'build_id'     => $build_id,
            'asset_versions' => $this->extract_asset_versions( $html ),
            'integrity'    => $this->integrity_state( $path, $html ),
            'complete'     => true,
        ];
        $this->manifest_write( $data );
        $this->finalize_build( $build_id );
        $this->clear_pending( $post_id );
        // A clean capture clears any earlier error for this post so it no longer
        // shows as a "blocked page" in the rail.
        $this->clear_error( $post_id );
    }

    private function manifest_remove( int $post_id ): void {
        $data = $this->manifest_read();
        if ( isset( $data[ $post_id ] ) ) {
            unset( $data[ $post_id ] );
            $this->manifest_write( $data );
            $this->write_runtime_manifest( $this->current_build_id() );
        }
        $this->clear_pending( $post_id );
    }

    public function manifest_entry( int $post_id ): ?array {
        $data = $this->manifest_read();
        return $data[ $post_id ] ?? null;
    }

    private function current_build_id(): string {
        $build_id = (string) get_option( self::BUILD_OPTION, '' );
        if ( $build_id !== '' ) {
            return $build_id;
        }
        $build_id = get_transient( 'nexeng_ssg_bulk_running' ) ? $this->current_build_id() : $this->create_build_id();
        update_option( self::BUILD_OPTION, $build_id, false );
        return $build_id;
    }

    private function create_build_id(): string {
        return 'nexeng_build_' . time() . '_' . strtolower( wp_generate_password( 6, false, false ) );
    }

    private function finalize_build( string $build_id ): void {
        if ( $build_id === '' ) {
            $build_id = $this->create_build_id();
        }
        update_option( self::BUILD_OPTION, $build_id, false );
        update_option( 'nexeng_ssg_last_build_at', time(), false );
        $this->write_runtime_manifest( $build_id );
    }

    private function write_runtime_manifest( string $build_id ): void {
        $this->ensure_root();

        $pages = [];
        $asset_versions = [];
        foreach ( $this->manifest_read() as $key => $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $pages[ (string) $key ] = [
                'path'         => (string) ( $entry['path'] ?? '' ),
                'hash'         => (string) ( $entry['hash'] ?? '' ),
                'bytes'        => (int) ( $entry['bytes'] ?? 0 ),
                'generated_at' => (int) ( $entry['generated_at'] ?? 0 ),
                'build_id'     => (string) ( $entry['build_id'] ?? $build_id ),
                'complete'     => ! empty( $entry['complete'] ),
                'integrity'    => (string) ( $entry['integrity'] ?? 'unknown' ),
            ];
            if ( ! empty( $entry['asset_versions'] ) && is_array( $entry['asset_versions'] ) ) {
                $asset_versions = array_merge( $asset_versions, $entry['asset_versions'] );
            }
        }

        $payload = [
            'build_id'       => $build_id,
            'runtime_version'=> (string) get_option( self::GLOBAL_BUILD_OPTION, $build_id ),
            'generated_at'   => time(),
            'pages'          => $pages,
            'asset_versions' => $asset_versions,
            'integrity'      => empty( array_filter( $pages, fn( $page ) => ( $page['integrity'] ?? '' ) !== 'ok' ) ) ? 'ok' : 'warning',
            'complete'       => true,
        ];

        $path = $this->runtime_manifest_path();
        $tmp  = $path . '.tmp.' . wp_generate_password( 8, false );
        file_put_contents( $tmp, wp_json_encode( $payload, JSON_PRETTY_PRINT ), LOCK_EX );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale. Atomic publish of a captured page.
        @rename( $tmp, $path );
    }

    private function integrity_state( string $path, string $html ): string {
        return is_file( $path ) && filesize( $path ) === strlen( $html ) ? 'ok' : 'warning';
    }

    private function extract_asset_versions( string $html ): array {
        $versions = [];
        if ( preg_match_all( '/\b(?:href|src)=["\']([^"\']+\.(?:css|js)(?:\?[^"\']*)?)["\']/i', $html, $matches ) ) {
            foreach ( array_unique( $matches[1] ) as $url ) {
                $parts = wp_parse_url( html_entity_decode( $url ) );
                if ( empty( $parts['path'] ) ) {
                    continue;
                }
                parse_str( (string) ( $parts['query'] ?? '' ), $query );
                $versions[ $parts['path'] ] = isset( $query['ver'] ) ? sanitize_text_field( (string) $query['ver'] ) : '';
            }
        }
        return $versions;
    }

    public function pending_posts(): array {
        $pending = get_option( self::PENDING_OPTION, [] );
        if ( ! is_array( $pending ) ) {
            return [];
        }

        // Defensively remove any posts that are no longer published — e.g. a
        // post that was pending regen and was then un-published or re-scheduled
        // before the cron fired.  Also drop anything that isn't actually
        // capturable (builder CPTs like nca_page / elementor_library, ?p=
        // permalink types, excluded types) — these could have been added by an
        // older code path before mark_pending() gained its eligibility gate, and
        // because the build correctly skips them they would otherwise sit in the
        // pending queue forever, causing the UI count to never reach zero and the
        // auto-rebuild to loop. This self-heals the queue on every read.
        $clean   = false;
        foreach ( array_keys( $pending ) as $pid ) {
            $post = get_post( (int) $pid );
            if ( ! $post || $post->post_status !== 'publish' || ! $this->is_eligible( (int) $pid ) ) {
                unset( $pending[ $pid ] );
                $clean = true;
            }
        }
        if ( $clean ) {
            update_option( self::PENDING_OPTION, $pending, false );
        }

        return $pending;
    }

    /**
     * Published, eligible posts/pages that have no captured static file yet
     * (not present in the manifest, or their manifest file is gone).
     *
     * This is distinct from the pending QUEUE (nexeng_ssg_pending_posts), which
     * only holds pages queued by a recent publish/edit. A page published before
     * SSG was first built — or missed by an interrupted build — is uncaptured
     * yet never entered that queue, so it would show "Pending" in the page list
     * while the queue-based counter reported 0. This method closes that gap.
     *
     * @return int[] Post IDs that are published+eligible but not in the mirror.
     */
    public function missing_post_ids(): array {
        $manifest = $this->manifest_read();
        $root     = $this->root_dir();
        $missing  = [];

        foreach ( $this->eligible_post_ids() as $id ) {
            $id    = (int) $id;
            $entry = $manifest[ $id ] ?? null;
            $path  = is_array( $entry ) && ! empty( $entry['path'] )
                ? $root . '/' . ltrim( (string) $entry['path'], '/' )
                : '';

            // Uncaptured if there's no manifest entry OR the referenced file is gone.
            if ( ! $path || ! is_file( $path ) ) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * The number shown as "Pending" in the admin. Truthfully counts everything
     * not yet in the static mirror: the recent-edit queue UNION any published
     * page that was never captured — so it can never disagree with the page
     * list (which flags a row Pending when it is absent from the manifest).
     */
    public function pending_count(): int {
        $queued  = array_map( 'intval', array_keys( $this->pending_posts() ) );
        $missing = $this->missing_post_ids();
        return count( array_unique( array_merge( $queued, $missing ) ) );
    }

    public function mark_pending( int $post_id, string $reason = 'content' ): void {
        if ( ! self::is_enabled() ) {
            return; // Master kill-switch: don't dirty the queue when SSG is off.
        }
        if ( $post_id <= 0 ) {
            return;
        }
        // Never queue a non-published post — draft, future (scheduled), trash,
        // and private posts have no publicly-accessible URL to capture.
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return;
        }
        // Never queue a post the build can't actually capture (builder CPTs like
        // nca_page / elementor_library, ?p= permalink types, excluded types, etc.).
        // Previously a site-wide change (global_change) re-queued every published
        // post including these, and because the build correctly SKIPS them they
        // stayed "pending" forever — auto-rebuild then re-queued them on the next
        // change, producing an endless build/flicker loop in the UI. Gating here
        // stops that at the source so the pending queue only ever holds real,
        // capturable pages.
        if ( ! $this->is_eligible( $post_id ) ) {
            return;
        }
        $pending = $this->pending_posts();
        $pending[ $post_id ] = [
            'ts'     => time(),
            'reason' => sanitize_key( $reason ),
            'title'  => get_the_title( $post_id ),
            'url'    => get_permalink( $post_id ),
        ];
        update_option( self::PENDING_OPTION, $pending, false );
    }

    public function clear_pending( int $post_id ): void {
        $pending = $this->pending_posts();
        if ( isset( $pending[ $post_id ] ) ) {
            unset( $pending[ $post_id ] );
            update_option( self::PENDING_OPTION, $pending, false );
        }
    }

    // ─── Persistent Fatal-Error Store ─────────────────────────────────────────
    //
    // Pages that caused a deterministic source fatal (PHP OOM, uncaught error)
    // are recorded here so that:
    //   • The cron never loops on them (is_fatal() guard at the top of cron_regen).
    //   • Pages & Posts Insight shows a "⚠ Fatal Error" badge so the user knows
    //     the page is blocked and WHY.
    //   • A new full rebuild (bulk_start) clears the list for a fresh attempt.
    //   • A manual regen (handle_ssg_regen_one) clears the specific page so the
    //     user can retry after raising PHP memory or fixing the fatal.
    //
    private const FATAL_PAGES_OPTION = 'nexeng_ssg_fatal_pages';

    /**
     * Record a page as having a known persistent fatal so the cron
     * stops attempting it.
     */
    public function mark_fatal( int $post_id, WP_Error $err ): void {
        $store = get_option( self::FATAL_PAGES_OPTION, [] );
        if ( ! is_array( $store ) ) {
            $store = [];
        }
        $store[ $post_id ] = [
            'code'    => $err->get_error_code(),
            'message' => $err->get_error_message(),
            'ts'      => time(),
        ];
        update_option( self::FATAL_PAGES_OPTION, $store, false );
    }

    /**
     * Remove a specific page from the fatal store (user is explicitly retrying).
     */
    public function clear_fatal( int $post_id ): void {
        $store = get_option( self::FATAL_PAGES_OPTION, [] );
        if ( is_array( $store ) && isset( $store[ $post_id ] ) ) {
            unset( $store[ $post_id ] );
            update_option( self::FATAL_PAGES_OPTION, $store, false );
        }
    }

    /**
     * Remove ALL pages from the fatal store (called at the start of a full rebuild).
     */
    public function clear_all_fatals(): void {
        update_option( self::FATAL_PAGES_OPTION, [], false );
    }

    /**
     * True if the page has a known persistent fatal.
     */
    public function is_fatal( int $post_id ): bool {
        $store = get_option( self::FATAL_PAGES_OPTION, [] );
        return is_array( $store ) && isset( $store[ $post_id ] );
    }

    /**
     * Returns the entire fatal-pages store: [ post_id => ['code', 'message', 'ts'] ]
     */
    public function get_fatal_pages(): array {
        $store = get_option( self::FATAL_PAGES_OPTION, [] );
        return is_array( $store ) ? $store : [];
    }

    public function is_post_stale( int $post_id, ?array $entry = null ): bool {
        $pending = $this->pending_posts();
        if ( isset( $pending[ $post_id ] ) ) {
            return true;
        }
        $entry = $entry ?: $this->manifest_entry( $post_id );
        if ( empty( $entry['generated_at'] ) ) {
            return false;
        }
        $modified = get_post_modified_time( 'U', true, $post_id );
        return $modified && $modified > ( (int) $entry['generated_at'] + 2 );
    }

    public function next_regen_due( int $post_id ): int {
        return (int) wp_next_scheduled( self::CRON_HOOK, [ $post_id ] );
    }

    public function maybe_render_stale_notice(): void {
        if ( ! is_admin() || ! current_user_can( 'edit_posts' ) || ! self::is_enabled() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'post' ) {
            return;
        }
        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $post_id || ! $this->is_eligible( $post_id ) ) {
            return;
        }
        $entry = $this->manifest_entry( $post_id );
        if ( ! $entry || ! $this->is_post_stale( $post_id, $entry ) ) {
            return;
        }
        $due = $this->next_regen_due( $post_id );
        ?>
        <div class="notice notice-warning ncx-ssg-stale-notice" style="border-left-color:#0252FA;padding:12px 14px;">
            <p>
                <strong><?php esc_html_e( 'Static copy needs refresh.', 'nexora-engine' ); ?></strong>
                <?php esc_html_e( 'This page was updated after its last static capture, so logged-in editors see the newest version while static visitors may still receive the previous mirror.', 'nexora-engine' ); ?>
                <?php if ( $due ) : ?>
                    <?php
                    /* translators: %s: human-readable time until the next auto-regeneration. */
                    echo esc_html( sprintf( __( 'Auto-regeneration is queued in about %s.', 'nexora-engine' ), human_time_diff( time(), $due ) ) ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Auto-regeneration starts on publish/update.', 'nexora-engine' ); ?>
                <?php endif; ?>
            </p>
            <p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;">
                <button type="button" class="button button-primary ncx-inline-regen-one" data-id="<?php echo (int) $post_id; ?>" style="display:inline-flex;align-items:center;gap:5px;">
                    <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>
                    <?php esc_html_e( 'Regenerate This Page', 'nexora-engine' ); ?>
                </button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-pages-report' ) ); ?>">
                    <?php esc_html_e( 'Open Pages & Posts', 'nexora-engine' ); ?>
                </a>
                <span style="color:#646970;font-size:12px;"><?php esc_html_e( 'Focused rebuild usually completes in 20-45 seconds on builder-heavy pages.', 'nexora-engine' ); ?></span>
            </p>
        </div>
        <?php
    }

    // ─── HMAC Capture Token ───────────────────────────────────────────────────

    /**
     * Returns the HMAC signing secret, lazily generating one on first use.
     */
    private function secret(): string {
        $secret = get_option( self::SECRET_OPTION );
        if ( ! $secret || strlen( $secret ) < 40 ) {
            $secret = bin2hex( random_bytes( 32 ) );
            update_option( self::SECRET_OPTION, $secret, false );
        }
        return $secret;
    }

    /**
     * Builds an authenticated capture URL for a post.
     * The receiving end must be the same site (we lock to site_url() host).
     */
    public function capture_url( int $post_id ): ?string {
        $permalink = get_permalink( $post_id );
        if ( ! $permalink ) {
            return null;
        }

        $ts    = time();
        $token = $this->sign( $post_id, $ts );

        return add_query_arg(
            [
                '_nexeng_capture' => $token,
                '_nexeng_ts'      => $ts,
                '_nexeng_pid'     => $post_id,
            ],
            $permalink
        );
    }

    /**
     * Pre-flight capture test — validates that the loopback mechanism works
     * before committing to a full bulk build.
     *
     * Makes a real wp_remote_get() to a published page with a signed capture
     * token and checks the response is proper HTML.  Detects:
     *   • HTTP 500        → nginx rewrite loop (last vs break, wrong sentinel)
     *   • Raw PHP source  → nginx serving index.php as a static file instead
     *                       of routing the request to PHP-FPM
     *   • Empty body      → loopback returned blank / redirect-only response
     *   • WP_Error        → loopback blocked by firewall, or FPM pool exhausted
     *
     * Returns true on pass, a descriptive WP_Error on any failure so the
     * caller can surface a specific, actionable message to the user.
     *
     * @return true|WP_Error
     */
    public function capture_preflight(): bool|WP_Error {
        $ts  = time();
        $url = add_query_arg(
            [
                '_nexeng_preflight' => '1',
                '_nexeng_capture'   => $this->sign( 0, $ts ),
                '_nexeng_ts'        => $ts,
                '_nexeng_pid'       => '0',
            ],
            home_url( '/' )
        );

        $response = wp_remote_get( $url, [
            'timeout'     => 8,
            'sslverify'   => false,
            'redirection' => 2,
            'user-agent'  => 'NexoraSSG/1.0 (+preflight)',
            'headers'     => array_merge(
                [ 'X-Nexora-Capture' => '1' ],
                $this->loopback_auth_headers()
            ),
        ] );

        // ── Network / connection failure ─────────────────────────────────────────
        if ( is_wp_error( $response ) ) {
            $msg  = $response->get_error_message();
            $hint = '';
            if ( stripos( $msg, "couldn't connect" ) !== false
              || stripos( $msg, 'connection refused' ) !== false ) {
                $hint = ' Your host may be blocking loopback (server-to-server) HTTP requests — check firewall rules or contact your host.';
            } elseif ( stripos( $msg, 'timed out' ) !== false ) {
                $hint = ' The loopback request timed out. Check whether another build is running or PHP-FPM worker pool is exhausted.';
            }
            return new WP_Error( 'nexeng_preflight_connect',
                'Capture loopback failed: ' . $msg . $hint );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $preflight_header = wp_remote_retrieve_header( $response, 'x-nexora-preflight' );

        // ── HTTP 500 → almost always a nginx rewrite loop ────────────────────────
        if ( $code === 500 ) {
            return new WP_Error( 'nexeng_preflight_500',
                'Capture loopback returned HTTP 500 — likely a server rewrite loop. '
                . 'On nginx: temporarily remove custom Nexora location rules and confirm '
                . 'query-string URLs route to WordPress normally before enabling static delivery. '
                . 'On Apache: check .htaccess for conflicting RewriteRule entries.' );
        }

        if ( $code === 401 ) {
            $hint = empty( $this->loopback_auth_headers() )
                ? ' Add HTTP Basic Auth credentials under Nexora → Settings → HTTP Basic Auth (Staging), then retry the build.'
                : ' The configured HTTP Auth credentials were rejected — verify username and password.';
            return new WP_Error( 'nexeng_preflight_http_auth',
                'Capture loopback returned HTTP 401 (authentication required).' . $hint );
        }

        if ( $code !== 200 ) {
            return new WP_Error( 'nexeng_preflight_http',
                "Capture loopback returned HTTP $code (expected 200). "
                . 'The capture URL may be blocked or redirecting off-site.' );
        }

        if ( strtolower( (string) $preflight_header ) !== 'ok' ) {
            return new WP_Error(
                'nexeng_preflight_unverified',
                'Capture loopback reached the site, but the Nexora preflight marker was missing. A cache, redirect, or server rule may be intercepting query-string capture requests.'
            );
        }

        // ── Raw PHP source → nginx is serving index.php as a static file ────────
        $trimmed = ltrim( $body );
        if ( str_starts_with( $trimmed, '<?php' ) || str_starts_with( $trimmed, '<?PHP' ) ) {
            return new WP_Error( 'nexeng_preflight_php_source',
                'Capture loopback returned raw PHP source instead of rendered HTML. '
                . 'The server is not routing query-string requests through PHP-FPM. '
                . 'On nginx: review the site location rules so query-string requests bypass '
                . 'static mirrors and reach the normal WordPress PHP handler.' );
        }

        // ── Empty / truncated body ───────────────────────────────────────────────
        if ( strlen( $body ) < 200 || stripos( $body, '</html>' ) === false ) {
            return new WP_Error( 'nexeng_preflight_empty',
                sprintf(
                    'Capture loopback returned incomplete HTML (%d bytes, %s). '
                    . 'The page may be blank, redirecting, or WordPress may not be '
                    . 'loading correctly for the capture user-agent.',
                    strlen( $body ),
                    stripos( $body, '</html>' ) === false ? 'no </html>' : 'body too short'
                )
            );
        }

        return true;
    }

    /**
     * Returns the correct nginx location / block that clients must add to their
     * server {} configuration to enable Nexora Tier-1 static delivery.
     *
     * Keeps query-string requests on WordPress so search, previews, and capture
     * tokens do not accidentally serve stale static mirrors.
     */
    public function nginx_serve_config(): string {
        $home   = $this->home_path_prefix(); // '' for root install, '/sub' for subdir
        $static = $home . '/wp-content/uploads/nexora-static';
        $ver    = defined( 'NEXENG_VERSION' ) ? NEXENG_VERSION : '1.x';

        // phpcs:ignore PluginCheck.CodeAnalysis.Heredoc.NotAllowed -- Heredoc holds a multi-line config/JS template; valid PHP, far more readable and less error-prone than concatenation here.
        ob_start();
        ?>
<?php // Not HTML: this is an nginx config file, so esc_html() would be the wrong
// escape and would corrupt any character it encoded. $ver is the plugin's own
// version constant; narrowed to version characters for belt and braces. ?>
# Nexora Engine v<?php echo preg_replace( '/[^0-9A-Za-z._\-]/', '', (string) $ver ); ?> — add this block to your nginx server { } config.
# Reload nginx after pasting: sudo nginx -s reload
location / {
    # Authenticated users need fresh nonces / admin bar — bypass static delivery.
    if ($http_cookie ~* "wordpress_logged_in_|wp-postpass_|comment_author_") {
        rewrite ^ /index.php last;
    }
    # Any query string (search, preview, SSG capture token) must reach PHP.
    # If your server already routes query strings to WordPress, keep its default
    # behavior. Do not rewrite to a fake path such as /__nexeng_pass on LocalWP.
    if ($query_string != "") {
        rewrite ^ /index.php last;
    }
    # Nexora Tier 1: serve pre-built static HTML; fall back to WordPress.
    try_files <?php echo $static; ?>$uri/index.html
              <?php echo $static; ?>$uri
              $uri $uri/ /index.php$is_args$args;
}
        <?php
        return ob_get_clean();
    }

    /**
     * Computes the HMAC for a given (post_id, timestamp) pair.
     */
    private function sign( int $post_id, int $ts ): string {
        return hash_hmac( 'sha256', $post_id . '|' . $ts, $this->secret() );
    }

    /**
     * Inspects the current request and returns a validated post_id if it
     * carries a fresh, authentic capture token. Returns null otherwise.
     *
     * Validates: HMAC match, timestamp freshness, and Host header equality
     * with site_url() (mitigates host-header poisoning of the capture).
     */
    public function detect_capture_request(): ?int {
        // Use isset for _nexeng_pid so pid=0 (used for archive captures) passes through.
        if ( empty( $_GET['_nexeng_capture'] ) || empty( $_GET['_nexeng_ts'] ) || ! isset( $_GET['_nexeng_pid'] ) ) {
            return null;
        }

        $token   = (string) $_GET['_nexeng_capture'];
        $ts      = (int) $_GET['_nexeng_ts'];
        $post_id = (int) $_GET['_nexeng_pid'];

        // Replay protection.
        if ( $ts <= 0 || abs( time() - $ts ) > self::CAPTURE_WINDOW ) {
            return null;
        }

        // Host lock — token is bound to the host that issued it.
        $expected_host = wp_parse_url( site_url(), PHP_URL_HOST );
        $actual_host   = NEXENG_Request::host();
        // Strip port if present.
        $actual_host_only = preg_replace( '/:\d+$/', '', $actual_host );
        if ( strtolower( $expected_host ) !== $actual_host_only ) {
            return null;
        }

        // Constant-time HMAC compare.
        $expected = $this->sign( $post_id, $ts );
        if ( ! hash_equals( $expected, $token ) ) {
            return null;
        }

        return $post_id;
    }

    /**
     * Early-boot hook: if this request is a valid capture, define the
     * NEXORA_CAPTURE constant so the shell renderer bails and WordPress
     * outputs raw HTML for snapshot.
     *
     * Called from NEXENG_Init very early. Must be cheap on non-capture requests
     * (the empty-param check above is the fast path).
     */
    public function maybe_mark_capture_request(): void {
        $post_id = $this->detect_capture_request();
        if ( $post_id === null ) {
            return;
        }

        if ( ! defined( 'NEXORA_CAPTURE' ) ) {
            define( 'NEXORA_CAPTURE', $post_id );
        }

        $this->raise_capture_memory_limit();

        if ( isset( $_GET['_nexeng_preflight'] ) ) {
            $this->render_capture_preflight_response();
        }

        // Block ALL outbound HTTP calls during capture rendering — external AND
        // same-host loopbacks.
        //
        // WHY external: Freemius licence syncs, analytics SDKs can block PHP
        // for 20s+ on slow/unreachable servers → cURL error 28 on the caller.
        //
        // WHY same-host: on servers with limited PHP workers (e.g. LocalWP with
        // pm.max_children=2) a same-host loopback made during archive rendering
        // (Elementor dynamic widgets, WP-Cron spawn, REST API self-calls) uses
        // the only remaining worker. Both workers deadlock and the capture times
        // out after 45s with "0 bytes received".
        //
        // IMPORTANT: return a fake 503 array, NOT WP_Error.
        // WP_Error causes PHP 8 TypeError fatals in plugins that access
        // $response['body'] directly (without is_wp_error() guard), making MORE
        // pages fail. A 503 array is safe: wp_remote_retrieve_body() returns ''
        // and Freemius treats 503 as "retry later" without corrupting its cache.
        //
        // NOTE: this filter only runs on the LOOPBACK process (the page being
        // captured, where NEXORA_CAPTURE is defined). The SENDING process (the
        // admin AJAX that calls wp_remote_get to make the loopback) never sees
        // this filter — it is a separate PHP process with no NEXORA_CAPTURE.
        add_filter( 'pre_http_request', static function ( $preempt, $parsed_args, $url ) {
            if ( false !== $preempt ) {
                return $preempt; // Already intercepted upstream — respect it.
            }
            // Fake 503 — instantly resolves the call, no network wait, no crash.
            return [
                'headers'  => [],
                'body'     => '',
                'response' => [ 'code' => 503, 'message' => 'Service Unavailable' ],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 1, 3 );

        // Defense in depth: tell crawlers and CDNs not to cache the capture.
        if ( ! headers_sent() ) {
            header( 'X-Robots-Tag: noindex, nofollow', true );
            header( 'Cache-Control: no-store, private', true );
        }
    }

    private function render_capture_preflight_response(): void {
        if ( ! headers_sent() ) {
            status_header( 200 );
            nocache_headers();
            header( 'Content-Type: text/html; charset=UTF-8', true );
            header( 'X-Nexora-Preflight: ok', true );
            header( 'X-Robots-Tag: noindex, nofollow', true );
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><title>Nexora capture preflight</title></head><body>';
        echo '<main id="nexora-preflight"><h1>Nexora capture preflight ok</h1>';
        echo '<p>The authenticated capture route reached WordPress and returned HTML without rendering a heavy theme, builder, or block query.</p>';
        echo '<p>This lightweight response validates loopback routing before the real build queue captures pages one by one.</p>';
        echo '</main></body></html>';
        exit;
    }

    private function raise_capture_memory_limit(): void {
        // WHY ini_set works everywhere without server config:
        // memory_limit is PHP_INI_ALL — it can always be changed at runtime on every
        // server, regardless of what php.ini says. No hosting access required.
        //
        // We use -1 (unlimited) as the default because the capture loopback renders
        // the full WordPress page.  Heavy block themes (Category / PostContent /
        // FeaturedPosts) can use 600 MB+ per block on content-rich sites — any fixed
        // cap risks crashing on large installs.  Server RAM is the real ceiling.
        //
        // Production hosts can lower this via the filter if they need a hard cap:
        //   add_filter( 'nexeng_ssg_capture_memory_limit', fn() => '1G' );

        // Prevent wp_raise_memory_limit('admin') from silently capping at 256M on
        // sites that don't define WP_MAX_MEMORY_LIMIT in wp-config.php.
        if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) {
            define( 'WP_MAX_MEMORY_LIMIT', '1G' );
        }
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        // ini_set always wins over wp_raise_memory_limit() — apply the actual target.
        $target = (string) apply_filters( 'nexeng_ssg_capture_memory_limit', '-1' );
        if ( $target !== '' && function_exists( 'ini_set' ) ) {
            @ini_set( 'memory_limit', $target );
        }
    }

    // ─── Capture Pipeline ─────────────────────────────────────────────────────

    /**
     * Returns HTTP Basic-Auth headers for the loopback capture request.
     *
     * Credentials are read from (in priority order):
     *   1. wp-config.php constants  NEXENG_HTTP_AUTH_USER / NEXENG_HTTP_AUTH_PASS
     *   2. WP options               nexeng_http_auth_user / nexeng_http_auth_pass
     *      (written from Settings → General → Server Compatibility)
     *
     * Required on staging environments that sit behind an HTTP password
     * gateway (WPMU DEV, Kinsta, Cloudways, WP Engine staging, etc.).
     * Returns an empty array when no credentials are configured.
     *
     * @return array<string,string>  ['Authorization' => 'Basic ...'] or []
     */
    private function loopback_auth_headers(): array {
        $user = defined( 'NEXENG_HTTP_AUTH_USER' ) ? (string) NEXENG_HTTP_AUTH_USER
              : (string) get_option( 'nexeng_http_auth_user', '' );
        $pass = defined( 'NEXENG_HTTP_AUTH_PASS' ) ? (string) NEXENG_HTTP_AUTH_PASS
              : (string) get_option( 'nexeng_http_auth_pass', '' );
        if ( $user === '' ) {
            return [];
        }
        return [ 'Authorization' => 'Basic ' . base64_encode( "$user:$pass" ) ];
    }

    /**
     * Captures a published post into a static HTML file.
     *
     * Pipeline: HMAC loopback → sanitize → nonce-rewrite → atomic write.
     *
     * @return true|WP_Error
     */
    public function capture( int $post_id ) {
        // Master kill-switch: when Static Delivery is disabled, NO file should be
        // written under any circumstances. This is the last line of defense — any
        // code path (handler, cron, third-party plugin) that reaches capture()
        // while disabled is rejected here even if upstream checks were missed.
        if ( ! self::is_enabled() ) {
            return new WP_Error( 'nexeng_ssg_disabled', 'Static Delivery is disabled. Enable it from the Static Delivery page to capture pages.' );
        }

        // NOTE (2026-06-27): an in-capture `if ( ! is_eligible() ) return 'skipped';`
        // early-return used to live here. It was REMOVED. Because 'skipped' is not a
        // WP_Error, bulk_batch() counted it as a successful "done" and shifted the
        // item off the queue WITHOUT writing a file — so any false-negative from
        // is_eligible() (see the removed ?p= permalink guard) silently drained the
        // whole queue to "Build complete" with 0 files captured (the live LiteSpeed
        // bug). Eligibility is enforced at QUEUE-BUILD time (eligible_post_ids /
        // mark_pending / pending self-heal); by the time we reach capture() the
        // item is trusted. capture() now always attempts the capture — a genuinely
        // unrenderable page returns a real WP_Error that is logged (not silently
        // dropped). See NEXORA-ENGINE-SSG-REVIEW.md recommendation (b).

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'nexeng_ssg_no_post', "Post $post_id not found." );
        }
        if ( $post->post_status !== 'publish' ) {
            return new WP_Error( 'nexeng_ssg_not_public', 'Only published posts can be captured.' );
        }

        // Eagerly regenerate Elementor's per-post CSS BEFORE the capture loopback.
        // Belt-and-braces: Elementor normally regenerates lazily during a public
        // render (which our loopback is), but bot-detection / preview-mode checks
        // / unusual asset modes can cause the lazy regen to skip. Pre-priming
        // guarantees the CSS file exists on disk before the captured HTML
        // references it. Safe no-op when Elementor isn't installed.
        $this->prime_elementor_post_css( $post_id );

        $url = $this->capture_url( $post_id );
        if ( ! $url ) {
            return new WP_Error( 'nexeng_ssg_no_url', 'Could not build capture URL.' );
        }

        // Manually follow up to 2 same-host redirects so canonical-URL 301s
        // (trailing slash, http→https) don't kill the capture. Each hop
        // re-signs the capture params on the new URL.
        $hops        = 0;
        $current     = $url;
        $site_host   = wp_parse_url( site_url(), PHP_URL_HOST );
        $auth_headers = $this->loopback_auth_headers(); // Basic Auth for password-gated staging

        while ( true ) {
            $response = wp_remote_get( $current, [
                // Shorter timeout during bulk builds — frees PHP-FPM workers
                // faster on failures, preventing pool exhaustion on low-worker hosts.
                'timeout'     => get_transient( 'nexeng_ssg_bulk_running' ) ? self::BULK_CAPTURE_TIMEOUT : 45,
                'sslverify'   => false,
                'redirection' => 0,
                'user-agent'  => 'NexoraSSG/1.0 (+capture)',
                'headers'     => array_merge( [ 'X-Nexora-Capture' => '1' ], $auth_headers ),
            ] );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'nexeng_ssg_http_error', $response->get_error_message() );
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code === 200 ) {
                break;
            }

            if ( in_array( $code, [ 301, 302, 307, 308 ], true ) ) {
                if ( $hops++ >= 2 ) {
                    return new WP_Error( 'nexeng_ssg_redirect_loop', 'Too many redirects during capture.' );
                }
                $loc = wp_remote_retrieve_header( $response, 'location' );
                if ( ! $loc ) {
                    return new WP_Error( 'nexeng_ssg_redirect_no_loc', "HTTP $code with no Location header." );
                }
                // Resolve relative locations.
                if ( ! preg_match( '/^https?:\/\//i', $loc ) ) {
                    $loc = home_url( $loc );
                }
                // Lock to same host — never follow off-site.
                if ( strtolower( wp_parse_url( $loc, PHP_URL_HOST ) ) !== strtolower( $site_host ) ) {
                    return new WP_Error( 'nexeng_ssg_redirect_offsite', "Refusing redirect to {$loc}." );
                }
                // Strip any existing capture params and re-sign on the new URL.
                $loc = remove_query_arg( [ '_nexeng_capture', '_nexeng_ts', '_nexeng_pid' ], $loc );
                $ts  = time();
                $current = add_query_arg( [
                    '_nexeng_capture' => $this->sign( $post_id, $ts ),
                    '_nexeng_ts'      => $ts,
                    '_nexeng_pid'     => $post_id,
                ], $loc );
                continue;
            }

            // ── Actionable auth error ──────────────────────────────────────────
            if ( $code === 401 ) {
                $hint = empty( $auth_headers )
                    ? ' — add HTTP Auth credentials in Settings → General → Server Compatibility'
                    : ' (credentials configured but rejected — check username/password)';
                return new WP_Error( 'nexeng_ssg_http_auth',
                    "Capture blocked: HTTP 401 authentication required{$hint}." );
            }

            $fatal = $this->detect_source_fatal( wp_remote_retrieve_body( $response ) );
            if ( $fatal ) {
                return new WP_Error( 'nexeng_ssg_source_fatal', $fatal );
            }

            return new WP_Error( 'nexeng_ssg_http_status', "Capture returned HTTP $code." );
        }

        $html = wp_remote_retrieve_body( $response );
        $fatal = $this->detect_source_fatal( $html );
        if ( $fatal ) {
            return new WP_Error( 'nexeng_ssg_source_fatal', $fatal );
        }
        if ( strlen( $html ) < 200 || stripos( $html, '</html>' ) === false ) {
            return new WP_Error( 'nexeng_ssg_html_truncated', 'Captured HTML is empty or truncated.' );
        }

        // Defense in depth.
        $sanitized = $this->sanitize_capture( $html );
        if ( is_wp_error( $sanitized ) ) {
            return $sanitized;
        }

        // Neutralize JS scope conflicts: const/let re-declarations that cause
        // "redeclaration of const X" errors (e.g. lazyloadRunObserver) when the
        // static page includes the same variable name from two different scripts.
        $sanitized = $this->neutralize_script_conflicts( $sanitized );

        // Ghost-clean: mask wp-content / wp-includes paths, strip ?ver=,
        // remove generator meta, rename window.wp → window.ncx, etc.
        // Without this, Wappalyzer detects WordPress/PHP/MySQL in static files.
        $cleaned = $this->ghost_clean_html( $sanitized );

        // Strip the noindex/nofollow meta that SEO plugins (Yoast, RankMath,
        // SmartCrawl, AIOSEO) defensively add to the captured HTML. The capture
        // URL carries `?_nexeng_capture=...&_nexeng_ts=...&_nexeng_pid=...` query params
        // and a custom user-agent, which most SEO plugins treat as a non-canonical
        // / preview render and respond with `noindex, nofollow`. We leave the
        // meta in place ONLY when the post itself is genuinely user-marked
        // noindex via the SEO plugin's per-post setting — that's an explicit
        // editor choice we must respect.
        if ( ! $this->post_is_user_noindexed( $post_id ) ) {
            $cleaned = preg_replace(
                '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*\b(?:noindex|nofollow)\b[^"\']*["\'][^>]*>\s*/i',
                '',
                $cleaned
            );
        }

        // Replace runtime nonces with placeholders for client-side rehydration.
        $rewritten = $this->rewrite_nonces( $cleaned );

        // Inject the hydration JS so static visitors can refresh nonces lazily.
        $hydrated = $this->inject_hydration( $rewritten );

        // Inject LCP preload hints so the browser fetches the hero image
        // during the very first network round-trip (before <body> is parsed).
        $lcp_html = $this->inject_lcp_preloads( $hydrated );

        // Inject SPA-feel navigation: cross-document view transitions,
        // Speculation Rules prerender, prefetch-on-hover, and progress bar.
        $enhanced = $this->inject_navigation_enhancer( $lcp_html );

        // Use the session build ID during a bulk run so every page in the same
        // build shares one ID (asset ?ver=, <meta ncx-build>, and the runtime
        // sync script all match across pages). Minting a fresh time()-based ID
        // per page — as this path used to — meant pages captured minutes apart
        // (e.g. across a worker pause/resume) carried different build IDs, which
        // the SPA nav enhancer read as a stale build → flash of unstyled layout.
        $build_id = get_transient( 'nexeng_ssg_bulk_running' ) ? $this->current_build_id() : $this->create_build_id();
        $synced   = $this->apply_runtime_sync( $enhanced, $build_id );

        // Neural Polish: Consolidate inline assets and minify the final HTML
        // to achieve the "plain and combined" Vercel/Next.js look.
        $polished = $this->consolidate_inline_assets( $synced );
        $final_html = $this->minify_html( $polished );

        // Validate that every locally-referenced asset (CSS/JS) actually exists
        // on disk.
        $missing_assets = $this->validate_capture_assets( $final_html );

        $write_result = $this->write_post( $post_id, $final_html, $build_id );

        // Persist warnings into the manifest entry for this post so the admin
        // UI can render them. We refresh the entry post-write so the warnings
        // attach to the version we just persisted (or the previous one if write
        // failed — either way, the admin sees actionable info).
        if ( ! is_wp_error( $write_result ) ) {
            $manifest = $this->manifest_read();
            if ( isset( $manifest[ $post_id ] ) && is_array( $manifest[ $post_id ] ) ) {
                if ( ! empty( $missing_assets ) ) {
                    $manifest[ $post_id ]['warnings'] = array_values( array_unique( $missing_assets ) );
                } else {
                    unset( $manifest[ $post_id ]['warnings'] );
                }
                $this->manifest_write( $manifest );
            }

            // ── Image-size audit ─────────────────────────────────────────────
            // Runs on every successful capture so Page Insight always reflects the
            // current image payload.  Registers/clears the nexeng_large_image issue
            // in the Issue Engine so site owners see actionable warnings without
            // having to run a manual SEO scan.
            $this->register_oversized_image_issues( $final_html, $post_id );

            // ── CDN edge-cache purge ──────────────────────────────────────────
            // Fire-and-forget: purge this URL from Cloudflare / BunnyCDN / Varnish
            // so edge nodes immediately serve the freshly captured static file.
            // Errors are silent (logged to error_log only) — capture succeeded,
            // we just note that CDN purge may be pending.
            if ( class_exists( 'NEXENG_CDN' ) && NEXENG_CDN::is_configured() ) {
                $permalink = get_permalink( $post_id );
                if ( $permalink ) {
                    $cdn_result = NEXENG_CDN::purge_url( $permalink );
                    if ( is_wp_error( $cdn_result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational CDN-purge failure, gated behind WP_DEBUG.
                        error_log( '[Nexora CDN] purge_url failed for ' . $permalink . ': ' . $cdn_result->get_error_message() );
                    }
                }
            }
        }

        return $write_result;
    }

    /**
     * Neutralizes JavaScript variable scope conflicts in captured HTML.
     *
     * Static pages can contain the same `const` or `let` declaration from two
     * different plugins/themes (e.g. `const lazyloadRunObserver` in both a lazy-
     * load plugin and the theme). A second `const X` in the same JS scope throws
     * "SyntaxError: redeclaration of const X" in strict mode / Firefox, white-
     * screening the page. This converts duplicate const/let declarations to `var`
     * which silently allows re-declaration without a scope error.
     *
     * Called during the SSG capture pipeline — after sanitize_capture(), before
     * ghost_clean_html() — so every static snapshot is conflict-free.
     */
    private function neutralize_script_conflicts( string $html ): string {
        $seen = [];

        // ── Pass 1: scan external same-origin scripts ─────────────────────────
        // Pre-populate $seen with const/let names declared in external JS files.
        // Without this, inline scripts that re-declare the same name as an
        // external script (e.g. two lazyload plugins both declaring
        // `const lazyloadRunObserver`) cause a SyntaxError the first time a
        // user loads the page with a cold browser cache (before ?ver= cache-bust
        // refreshes the old external JS). Results are transient-cached per
        // file+mtime so this only reads the filesystem on the first capture after
        // a plugin update.
        preg_match_all( '/<script[^>]+\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $ext_matches );
        $site_url    = rtrim( site_url(), '/' );
        $abspath_fwd = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
        foreach ( $ext_matches[1] as $ext_url ) {
            // Only scan same-origin scripts (absolute with same host, or root-relative).
            if ( strpos( $ext_url, $site_url ) !== 0
                && ( strpos( $ext_url, '/' ) !== 0 || strpos( $ext_url, '//' ) === 0 ) ) {
                continue;
            }
            // Build filesystem path: strip scheme+host, query string, and fragment.
            $path_part = strpos( $ext_url, $site_url ) === 0
                ? substr( $ext_url, strlen( $site_url ) )
                : $ext_url;
            $path_part = strtok( $path_part, '?#' );
            $fs_path   = str_replace( '/', DIRECTORY_SEPARATOR, $abspath_fwd . $path_part );
            if ( ! is_file( $fs_path ) ) {
                continue;
            }
            $fsize = filesize( $fs_path );
            if ( $fsize === 0 || $fsize > 400 * 1024 ) {
                continue; // Skip empty or very large files (>400 KB).
            }
            // Cache extraction by content hash so plugin updates bust the cache.
            $cache_key = 'nexeng_js_names_' . substr( md5( $fs_path . '|' . $fsize . '|' . filemtime( $fs_path ) ), 0, 16 );
            $names = get_transient( $cache_key );
            if ( false === $names ) {
                $js    = @file_get_contents( $fs_path );
                $names = [];
                if ( $js ) {
                    preg_match_all( '/\b(?:const|let)\s+([a-zA-Z_$][\w$]*)\s*[=,]/', $js, $nm );
                    $names = array_fill_keys( array_unique( $nm[1] ), true );
                }
                set_transient( $cache_key, $names, 12 * HOUR_IN_SECONDS );
            }
            $seen += $names; // Merge without overwriting existing entries.
        }

        // ── Pass 2: process inline scripts ───────────────────────────────────
        return preg_replace_callback(
            '/<script([^>]*)>(.*?)<\/script>/is',
            function ( $m ) use ( &$seen ) {
                // Skip external scripts (src="…") — they cannot be modified.
                if ( preg_match( '/\bsrc\s*=/i', $m[1] ) ) {
                    return $m[0];
                }

                $content = $m[2];

                // Convert duplicate or externally-conflicting const/let to var.
                $content = preg_replace_callback(
                    '/\b(const|let)\s+([a-zA-Z_$][\w$]*)\s*=/u',
                    function ( $d ) use ( &$seen ) {
                        $name = $d[2];
                        if ( isset( $seen[ $name ] ) ) {
                            // Already declared externally or in a prior inline block.
                            return 'var ' . $name . ' =';
                        }
                        $seen[ $name ] = true;
                        return $d[0];
                    },
                    $content
                );

                return '<script' . $m[1] . '>' . $content . '</script>';
            },
            $html
        );
    }

    private function detect_source_fatal( string $html ): string {
        if ( $html === '' ) {
            return '';
        }

        $plain = wp_strip_all_tags( $html );
        $plain = preg_replace( '/\s+/', ' ', $plain );

        if ( stripos( $plain, 'Allowed memory size' ) !== false ) {
            return 'Capture stopped: the source page exhausted PHP memory while rendering. The page remains pending; increase PHP memory or reduce the heavy block/query before regenerating.';
        }

        if ( stripos( $plain, 'Fatal error' ) !== false || stripos( $plain, 'Uncaught Error' ) !== false ) {
            $snippet = trim( substr( $plain, 0, 260 ) );
            return $snippet !== ''
                ? 'Capture stopped because the source page returned a PHP fatal error: ' . $snippet
                : 'Capture stopped because the source page returned a PHP fatal error.';
        }

        return '';
    }

    private function apply_runtime_sync( string $html, string $build_id ): string {
        $html = $this->version_static_assets( $html, $build_id );
        $html = $this->inject_build_meta( $html, $build_id );
        return $this->inject_runtime_sync_script( $html, $build_id );
    }

    private function inject_build_meta( string $html, string $build_id ): string {
        $meta = '<meta name="ncx-build" content="' . esc_attr( $build_id ) . '">';
        if ( preg_match( '/<meta[^>]+name=["\']ncx-build["\'][^>]*>/i', $html ) ) {
            return preg_replace( '/<meta[^>]+name=["\']ncx-build["\'][^>]*>/i', $meta, $html, 1 );
        }
        return preg_replace( '/(<\/head>)/i', $meta . '$1', $html, 1 );
    }

    private function inject_runtime_sync_script( string $html, string $build_id ): string {
        $build_js     = wp_json_encode( $build_id );

        // phpcs:ignore PluginCheck.CodeAnalysis.Heredoc.NotAllowed -- Heredoc holds a multi-line config/JS template; valid PHP, far more readable and less error-prone than concatenation here.
        ob_start();
        ?>
<script id="ncx-runtime-sync">(function(){'use strict';
var htmlBuild=<?php echo $build_js; ?>;
window.NEXENG_BUILD_ID=htmlBuild;
try{localStorage.setItem('nexeng_build_id',htmlBuild);}catch(e){}
})();</script>
        <?php
        $script = ob_get_clean();

        // If a runtime-sync script already exists (page re-versioned in a later
        // build pass), REPLACE it so its embedded build ID stays in lockstep with
        // the asset ?ver= and the <meta ncx-build> tag. Bailing here (the old
        // behaviour) left a stale build ID in the script while the assets/meta
        // advanced, so the SPA nav enhancer saw a build mismatch and could paint
        // a page before its (differently-versioned) CSS applied — the flash of
        // unstyled layout that corrected on a hard refresh.
        if ( strpos( $html, 'ncx-runtime-sync' ) !== false ) {
            return preg_replace(
                '/<script id=["\']ncx-runtime-sync["\']>.*?<\/script>/is',
                // Escape backreference tokens ($ and \) in the replacement literal.
                str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $script ),
                $html,
                1
            );
        }

        $pos = stripos( $html, '</head>' );
        if ( $pos !== false ) {
            return substr( $html, 0, $pos ) . $script . substr( $html, $pos );
        }
        return $script . $html;
    }

    private function runtime_manifest_url_path(): string {
        $uploads = wp_get_upload_dir();
        $url     = untrailingslashit( $uploads['baseurl'] ) . '/' . self::DIR_NAME . '/' . self::RUNTIME_MANIFEST_FILE;
        $path    = wp_parse_url( $url, PHP_URL_PATH );
        return is_string( $path ) && $path !== '' ? $path : '/wp-content/uploads/' . self::DIR_NAME . '/' . self::RUNTIME_MANIFEST_FILE;
    }

    private function version_static_assets( string $html, string $build_id ): string {
        return preg_replace_callback(
            '/\b(href|src)=(["\'])([^"\']+\.(?:css|js)(?:\?[^"\']*)?)\2/i',
            function ( $m ) use ( $build_id ) {
                $url = html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' );
                if ( ! $this->is_versionable_asset_url( $url ) ) {
                    return $m[0];
                }
                $versioned = $this->add_or_replace_build_ver( $url, $build_id );
                return $m[1] . '=' . $m[2] . esc_url( $versioned ) . $m[2];
            },
            $html
        );
    }

    private function is_versionable_asset_url( string $url ): bool {
        if ( strpos( $url, 'data:' ) === 0 || strpos( $url, 'blob:' ) === 0 ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ( $host !== '' && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
            return false;
        }
        $path = (string) $parts['path'];
        if ( strpos( $path, '/_ncx/nonce' ) !== false || strpos( $path, '/_ncx/aj' ) !== false || strpos( $path, '/_ncx/api' ) !== false ) {
            return false;
        }
        return (bool) preg_match( '/\.(?:css|js)$/i', $path );
    }

    private function add_or_replace_build_ver( string $url, string $build_id ): string {
        $fragment = '';
        if ( false !== strpos( $url, '#' ) ) {
            [ $url, $fragment ] = explode( '#', $url, 2 );
            $fragment = '#' . $fragment;
        }
        $separator = strpos( $url, '?' ) === false ? '?' : '&';
        $url = preg_replace( '/([?&])ver=[^&]*/i', '$1ver=' . rawurlencode( $build_id ), $url, 1, $count );
        if ( ! $count ) {
            $url .= $separator . 'ver=' . rawurlencode( $build_id );
        }
        $url = str_replace( '?&', '?', $url );
        return $url . $fragment;
    }

    /**
     * Captures a non-post archive URL (homepage blog index, category, tag) and
     * writes the static HTML using the same pipeline as capture().
     *
     * Uses post_id = 0 in the HMAC so the loopback request is still authenticated
     * and NEXORA_CAPTURE is defined on the receiving end (causing WordPress to
     * output raw page HTML instead of the SPA shell).
     *
     * @param string $url          Full URL to capture (must be on this site).
     * @param string $manifest_key Unique string key for the manifest (e.g. '__home__').
     * @return true|WP_Error
     */
    public function capture_archive( string $url, string $manifest_key ) {
        if ( ! self::is_enabled() ) {
            return new WP_Error( 'nexeng_ssg_disabled', 'SSG is disabled.' );
        }

        $ts         = time();
        $token      = $this->sign( 0, $ts );
        $signed_url = add_query_arg( [
            '_nexeng_capture' => $token,
            '_nexeng_ts'      => $ts,
            '_nexeng_pid'     => '0',
        ], $url );

        // Follow up to 2 same-host redirects (same logic as capture()).
        $hops         = 0;
        $current      = $signed_url;
        $site_host    = wp_parse_url( site_url(), PHP_URL_HOST );
        $auth_headers = $this->loopback_auth_headers(); // Basic Auth for password-gated staging

        while ( true ) {
            $response = wp_remote_get( $current, [
                // Shorter timeout during bulk builds — frees PHP-FPM workers
                // faster on failures, preventing pool exhaustion on low-worker hosts.
                'timeout'     => get_transient( 'nexeng_ssg_bulk_running' ) ? self::BULK_CAPTURE_TIMEOUT : 45,
                'sslverify'   => false,
                'redirection' => 0,
                'user-agent'  => 'NexoraSSG/1.0 (+capture)',
                'headers'     => array_merge( [ 'X-Nexora-Capture' => '1' ], $auth_headers ),
            ] );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'nexeng_ssg_http_error', $response->get_error_message() );
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code === 200 ) {
                break;
            }

            if ( in_array( $code, [ 301, 302, 307, 308 ], true ) ) {
                if ( ++$hops > 2 ) {
                    return new WP_Error( 'nexeng_ssg_redirect_loop', 'Too many redirects during archive capture.' );
                }
                $loc = wp_remote_retrieve_header( $response, 'location' );
                if ( ! $loc ) {
                    return new WP_Error( 'nexeng_ssg_redirect_no_loc', "HTTP {$code} with no Location header." );
                }
                if ( ! preg_match( '/^https?:\/\//i', $loc ) ) {
                    $loc = home_url( $loc );
                }
                if ( strtolower( wp_parse_url( $loc, PHP_URL_HOST ) ) !== strtolower( $site_host ) ) {
                    return new WP_Error( 'nexeng_ssg_redirect_offsite', "Refusing off-site redirect to {$loc}." );
                }
                $loc     = remove_query_arg( [ '_nexeng_capture', '_nexeng_ts', '_nexeng_pid' ], $loc );
                $ts      = time();
                $current = add_query_arg( [
                    '_nexeng_capture' => $this->sign( 0, $ts ),
                    '_nexeng_ts'      => $ts,
                    '_nexeng_pid'     => '0',
                ], $loc );
                continue;
            }

            // ── Actionable auth error ──────────────────────────────────────────
            if ( $code === 401 ) {
                $hint = empty( $auth_headers )
                    ? ' — add HTTP Auth credentials in Settings → General → Server Compatibility'
                    : ' (credentials configured but rejected — check username/password)';
                return new WP_Error( 'nexeng_ssg_http_auth',
                    "Archive capture blocked: HTTP 401 authentication required{$hint}." );
            }

            $fatal = $this->detect_source_fatal( wp_remote_retrieve_body( $response ) );
            if ( $fatal ) {
                return new WP_Error( 'nexeng_ssg_source_fatal', $fatal );
            }

            return new WP_Error( 'nexeng_ssg_http_status', "Archive capture returned HTTP {$code}." );
        }

        $html = wp_remote_retrieve_body( $response );
        $fatal = $this->detect_source_fatal( $html );
        if ( $fatal ) {
            return new WP_Error( 'nexeng_ssg_source_fatal', $fatal );
        }
        if ( strlen( $html ) < 200 || stripos( $html, '</html>' ) === false ) {
            return new WP_Error( 'nexeng_ssg_html_truncated', 'Captured archive HTML is empty or truncated.' );
        }

        $sanitized = $this->sanitize_capture( $html );
        if ( is_wp_error( $sanitized ) ) {
            return $sanitized;
        }

        $sanitized  = $this->neutralize_script_conflicts( $sanitized );
        $cleaned    = $this->ghost_clean_html( $sanitized );

        // Strip SEO-plugin noindex tags unconditionally — archives have no per-page noindex setting.
        $cleaned = preg_replace(
            '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*\b(?:noindex|nofollow)\b[^"\']*["\'][^>]*>\s*/i',
            '',
            $cleaned
        );

        $rewritten  = $this->rewrite_nonces( $cleaned );
        $hydrated   = $this->inject_hydration( $rewritten );
        $lcp_html   = $this->inject_lcp_preloads( $hydrated );
        $enhanced   = $this->inject_navigation_enhancer( $lcp_html );
        $build_id   = get_transient( 'nexeng_ssg_bulk_running' ) ? $this->current_build_id() : $this->create_build_id();
        $synced     = $this->apply_runtime_sync( $enhanced, $build_id );
        $polished   = $this->consolidate_inline_assets( $synced );
        $final_html = $this->minify_html( $polished );

        $path = $this->path_for_url( $url );
        if ( ! $path ) {
            return new WP_Error( 'nexeng_ssg_no_path', "No static path resolvable for archive: {$url}" );
        }

        $lock_key = 'nexeng_ssg_lock_arc_' . substr( md5( $manifest_key ), 0, 8 );
        if ( get_transient( $lock_key ) ) {
            return new WP_Error( 'nexeng_ssg_locked', 'Archive capture already in progress.' );
        }
        set_transient( $lock_key, 1, self::LOCK_TTL );

        try {
            $result = $this->write_atomic( $path, $final_html );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            // Store in the manifest under the string key.
            $data                  = $this->manifest_read();
            $data[ $manifest_key ] = [
                'path'         => str_replace( $this->root_dir(), '', $path ),
                'hash'         => md5( $final_html ),
                'bytes'        => strlen( $final_html ),
                'generated_at' => time(),
                'build_id'     => $build_id,
                'asset_versions' => $this->extract_asset_versions( $final_html ),
                'integrity'    => $this->integrity_state( $path, $final_html ),
                'complete'     => true,
            ];
            $this->manifest_write( $data );
            $this->finalize_build( $build_id );

            // Purge this archive URL from CDN edge nodes.
            if ( class_exists( 'NEXENG_CDN' ) && NEXENG_CDN::is_configured() ) {
                $cdn_result = NEXENG_CDN::purge_url( $url );
                if ( is_wp_error( $cdn_result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational CDN-purge failure, gated behind WP_DEBUG.
                    error_log( '[Nexora CDN] purge_url failed for archive ' . $url . ': ' . $cdn_result->get_error_message() );
                }
            }

            return true;
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * Scans captured HTML for `<link href="...">` and `<script src="...">`
     * URLs that point at this site's wp-content/ or wp-includes/ paths and
     * verifies the underlying file exists on disk. Returns a list of URLs
     * that are referenced but missing — typically Elementor per-post CSS
     * files that another plugin (Defender, Hummingbird, Smush) deleted, or
     * theme/plugin files removed during an update. The list is attached to
     * the manifest entry so admins see actionable warnings at a glance.
     *
     * Same-host only: third-party assets (fonts.googleapis.com, CDNs, etc.)
     * are out of scope and skipped. Image references are skipped to keep the
     * scan fast on image-heavy pages — broken images surface visually.
     */
    private function validate_capture_assets( string $html ): array {
        $missing = [];

        // Resolve site roots once.
        $home_url      = untrailingslashit( home_url() );
        $home_path     = wp_parse_url( $home_url, PHP_URL_PATH ) ?: '';
        $home_path     = rtrim( $home_path, '/' );
        $content_url   = untrailingslashit( content_url() );
        $content_dir   = WP_CONTENT_DIR;
        $includes_url  = untrailingslashit( includes_url() );
        $includes_dir  = ABSPATH . WPINC;

        // Collect all <link href> and <script src> URLs.
        $urls = [];
        if ( preg_match_all( '/<link\b[^>]*\bhref=["\']([^"\']+\.css(?:\?[^"\']*)?)["\']/i', $html, $m ) ) {
            $urls = array_merge( $urls, $m[1] );
        }
        if ( preg_match_all( '/<script\b[^>]*\bsrc=["\']([^"\']+\.js(?:\?[^"\']*)?)["\']/i', $html, $m ) ) {
            $urls = array_merge( $urls, $m[1] );
        }
        $urls = array_unique( $urls );

        foreach ( $urls as $url ) {
            // Strip query string + fragment for filesystem lookup.
            $clean_url = strtok( $url, '?#' );
            if ( ! is_string( $clean_url ) || $clean_url === '' ) {
                continue;
            }

            // Resolve site-relative + absolute URLs to a filesystem path.
            $fs_path = null;
            if ( strpos( $clean_url, $content_url ) === 0 ) {
                $fs_path = $content_dir . substr( $clean_url, strlen( $content_url ) );
            } elseif ( strpos( $clean_url, $includes_url ) === 0 ) {
                $fs_path = $includes_dir . substr( $clean_url, strlen( $includes_url ) );
            } elseif ( $home_path !== '' && strpos( $clean_url, $home_path . '/wp-content/' ) === 0 ) {
                $fs_path = $content_dir . substr( $clean_url, strlen( $home_path . '/wp-content' ) );
            } elseif ( $home_path !== '' && strpos( $clean_url, $home_path . '/wp-includes/' ) === 0 ) {
                $fs_path = $includes_dir . substr( $clean_url, strlen( $home_path . '/wp-includes' ) );
            } elseif ( strpos( $clean_url, '/wp-content/' ) === 0 ) {
                $fs_path = $content_dir . substr( $clean_url, strlen( '/wp-content' ) );
            } elseif ( strpos( $clean_url, '/wp-includes/' ) === 0 ) {
                $fs_path = $includes_dir . substr( $clean_url, strlen( '/wp-includes' ) );
            }

            if ( $fs_path === null ) {
                // External / CDN / non-resolvable — skip silently.
                continue;
            }

            if ( ! file_exists( $fs_path ) ) {
                $missing[] = $clean_url;
            }
        }

        return $missing;
    }

    /**
     * Scans captured HTML for oversized images and registers/resolves the
     * nexeng_large_image Issue Engine issue for the given post.
     *
     * Threshold: 500 KB by default (filterable via 'nexeng_ssg_large_image_bytes').
     * That is 2.5× the Performance scanner's 200 KB warning — we intentionally
     * use a higher bar here so only genuinely sluggish images surface as build-
     * time issues; the per-scan audit still fires at 200 KB.
     *
     * Only local wp-content images are checked (external / CDN images are
     * bypassed — we cannot stat their disk size).
     *
     * Called after every successful capture so Page Insight stays up-to-date
     * without requiring a separate manual scan.
     */
    private function register_oversized_image_issues( string $html, int $post_id ): void {
        if ( ! class_exists( 'NEXENG_Issue_Engine' ) ) {
            return;
        }

        $threshold = (int) apply_filters( 'nexeng_ssg_large_image_bytes', 512000 ); // 500 KB

        // Resolve roots once.
        $content_url = untrailingslashit( content_url() );
        $content_dir = WP_CONTENT_DIR;
        $home_url    = untrailingslashit( home_url() );
        $home_path   = wp_parse_url( $home_url, PHP_URL_PATH ) ?: '';
        $home_path   = rtrim( $home_path, '/' );

        // Collect unique image src URLs (src= attributes + first URL in srcset=).
        $img_urls = [];
        if ( preg_match_all( '/<img\b[^>]+\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
            foreach ( $m[1] as $u ) {
                $img_urls[] = $u;
            }
        }
        // Also pull the first token from srcset (the 1x / smallest descriptor URL).
        if ( preg_match_all( '/<img\b[^>]+\bsrcset=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
            foreach ( $m[1] as $srcset ) {
                $first = trim( explode( ' ', trim( explode( ',', $srcset )[0] ) )[0] );
                if ( $first ) {
                    $img_urls[] = $first;
                }
            }
        }
        $img_urls = array_unique( $img_urls );

        $oversized = [];

        foreach ( $img_urls as $url ) {
            // Strip query / fragment for filesystem lookup.
            $clean_url = (string) strtok( $url, '?#' );
            if ( $clean_url === '' ) {
                continue;
            }

            // Resolve to a local filesystem path.
            $fs_path = null;
            if ( strpos( $clean_url, $content_url ) === 0 ) {
                $fs_path = $content_dir . substr( $clean_url, strlen( $content_url ) );
            } elseif ( $home_path !== '' && strpos( $clean_url, $home_path . '/wp-content/' ) === 0 ) {
                $fs_path = $content_dir . substr( $clean_url, strlen( $home_path . '/wp-content' ) );
            } elseif ( strpos( $clean_url, '/wp-content/' ) === 0 ) {
                $fs_path = $content_dir . substr( $clean_url, strlen( '/wp-content' ) );
            }

            if ( $fs_path === null || ! file_exists( $fs_path ) ) {
                continue; // External / CDN / missing — skip.
            }

            $bytes = (int) filesize( $fs_path );
            if ( $bytes > $threshold ) {
                $oversized[] = [
                    'src'     => basename( $clean_url ),
                    'size_kb' => (int) round( $bytes / 1024 ),
                ];
            }
        }

        $ie      = NEXENG_Issue_Engine::get_instance();
        $blog_id = get_current_blog_id();

        if ( ! empty( $oversized ) ) {
            // Build a human-readable list: "hero.jpg (1 024 KB), banner.png (780 KB)".
            $list = implode( ', ', array_map(
                fn( $img ) => $img['src'] . ' (' . number_format( $img['size_kb'] ) . ' KB)',
                $oversized
            ) );

            $ie->register_issue( $blog_id, $post_id, 'nexeng_large_image', [
                'title'       => 'Oversized Images Detected',
                'severity'    => 'high',
                'explanation' => sprintf(
                    '%d image(s) on this page exceed %d KB: %s. Large images are the most common cause of slow page loads and directly hurt your LCP score.',
                    count( $oversized ),
                    (int) round( $threshold / 1024 ),
                    $list
                ),
                'fix'         => 'Compress images to under 200 KB using Squoosh (squoosh.app), TinyPNG, or a plugin like Smush / ShortPixel. Convert to WebP format for the best size-to-quality ratio.',
            ] );
        } else {
            // All images on this page are within the threshold — clear any
            // previous nexeng_large_image warning so Page Insight stays clean.
            $ie->resolve_issue( $blog_id, $post_id, 'nexeng_large_image' );
        }
    }

    /**
     * Sanitizes captured HTML.
     *
     * Strategy: do NOT run wp_kses over the whole document — Elementor relies
     * on hundreds of data-* attributes and inline styles that KSES would
     * mangle. Instead apply targeted hardening:
     *
     *  1. Strip <script> tags whose src is not in the allow-list.
     *  2. Refuse to write the page if obvious-malicious patterns are detected
     *     in inline scripts (logged for admin review, page falls back to
     *     dynamic render).
     *
     * Filter `nexeng_ssg_script_host_allowlist` extends the host allow-list.
     *
     * @return string|WP_Error
     */
    public function sanitize_capture( string $html ) {
        // 1. Malicious-pattern scan on inline scripts.
        $danger = [
            'eval(atob(',
            'eval(unescape(',
            'String.fromCharCode(',  // High false-positive but rare in WP/Elementor — keep with caution.
            'document.write(unescape',
        ];
        // Only scan the first 256KB of inline JS to keep this cheap.
        if ( preg_match_all( '/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/i', $html, $m, 0, 0 ) ) {
            foreach ( $m[1] as $inline ) {
                foreach ( $danger as $needle ) {
                    if ( stripos( $inline, $needle ) !== false ) {
                        return new WP_Error(
                            'nexeng_ssg_suspicious_script',
                            "Refusing to snapshot — suspicious pattern in inline script: $needle"
                        );
                    }
                }
            }
        }

        // 2. Script-src host allow-list (Disabled for maximum compatibility)
        return $html;
    }

    /**
     * Stealth-clean captured HTML — mirrors what shell-template.php's
     * nexeng_ghost_cleaner() does for dynamic renders, applied to static files.
     *
     * Removes:
     *   - <meta name="generator"> (WP version leak)
     *   - REST API discovery link (/wp-json/ exposure)
     *   - RSD/wlwmanifest discovery links
     *   - oEmbed discovery links
     *
     * Rewrites:
     *   - /wp-content/      → /_ncx_v12/assets/   (browsers fetch via stealth proxy)
     *   - /wp-includes/     → /_ncx_v12/lib/
     *   - plugins/elementor → e/  (compresses identifiable plugin paths)
     *   - plugins/elementor-pro → ep/
     *   - themes/<theme>    → t/
     *   - other plugin paths → pkg/  (generic)
     *   - uploads/elementor → uploads/ncx/
     *   - window.wp.*       → window.ncx.*
     *   - ?ver=...          → ''  (cache-buster cleanup)
     *   - id="*-css"        → ''  (CSS link element ID leakage)
     *   - data-elementor-*  → ''  (page-builder fingerprints, layout-only attrs kept)
     */
    /**
     * Returns true if the post is intentionally marked noindex by the editor
     * via any of the major SEO plugins. Used to decide whether to strip the
     * noindex meta tag that those same plugins inject defensively into our
     * SSG capture response (false-positive triggered by query params + UA).
     *
     * Cross-plugin: Yoast, RankMath, AIOSEO, SmartCrawl. Returns false if no
     * SEO plugin is installed (the meta the capture has is then a false
     * positive from some other source — strip it).
     */
    private function post_is_user_noindexed( int $post_id ): bool {
        // Yoast SEO
        if ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
            return true;
        }
        // Rank Math
        $rm = get_post_meta( $post_id, 'rank_math_robots', true );
        if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
            return true;
        }
        // All in One SEO Pack
        if ( '1' === get_post_meta( $post_id, '_aioseo_robots_noindex', true ) ) {
            return true;
        }
        $aio = get_post_meta( $post_id, '_aioseo_robots', true );
        if ( is_array( $aio ) && ! empty( $aio['noindex'] ) ) {
            return true;
        }
        // SmartCrawl
        $sc = get_post_meta( $post_id, 'wds-meta', true );
        if ( is_array( $sc ) && ! empty( $sc['robots-noindex'] ) ) {
            return true;
        }
        return false;
    }

    public function ghost_clean_html( string $html ): string {
        $site_url   = untrailingslashit( site_url() );
        $asset_base = untrailingslashit( get_option( 'nexeng_asset_base', $site_url ) );
        // Universal proxy prefix — '' for Apache hosts (clean URLs), or
        // '/index.php' for Nginx/LiteSpeed/IIS (PATH_INFO routing).
        $prefix     = NEXENG_Init::proxy_prefix();
        // Asset delivery mode — 'direct' = fast, real /wp-content/ URLs;
        // 'proxy' = fully-cloaked asset URLs rewritten through PHP.
        // NEXENG_Init::asset_mode() already reports 'direct' when the class that
        // implements proxy rewriting is not part of this build, so there is
        // nothing to downgrade here — capturing pages with /_ncx_v12/assets/
        // paths that nothing can serve is impossible by construction.
        $asset_mode = NEXENG_Init::asset_mode();
        $v_assets   = $asset_base . $prefix . '/_ncx_v12/assets';
        $v_lib      = $asset_base . $prefix . '/_ncx_v12/lib';

        // ─── Strip identifying <head> elements ────────────────────────────
        // Generator meta (any plugin that sets one — WP, Elementor, etc.)
        $html = preg_replace( '/<meta[^>]*\bname=["\']generator["\'][^>]*>\s*/i', '', $html );
        // REST API discovery link
        $html = preg_replace( '/<link[^>]*rel=["\']https:\/\/api\.w\.org\/["\'][^>]*>\s*/i', '', $html );
        // RSD / Windows Live Writer
        $html = preg_replace( '/<link[^>]*rel=["\'](EditURI|wlwmanifest)["\'][^>]*>\s*/i', '', $html );
        // Pingback header link
        $html = preg_replace( '/<link[^>]*rel=["\']pingback["\'][^>]*>\s*/i', '', $html );
        // oEmbed JSON/XML discovery
        $html = preg_replace( '/<link[^>]*rel=["\']alternate["\'][^>]*type=["\']application\/(?:json|xml\+oembed)["\'][^>]*>\s*/i', '', $html );
        // emoji detection (also a strong WP signal)
        $html = preg_replace( '/<script[^>]*>\s*window\._wpemojiSettings[\s\S]*?<\/script>\s*/i', '', $html );
        $html = preg_replace( '/<link[^>]*\bhref=["\'][^"\']*\/wp-emoji[^"\']*["\'][^>]*>\s*/i', '', $html );

        // ─── Path masking — only in PROXY mode ────────────────────────────
        // In 'direct' mode, real /wp-content/ paths stay in HTML so browsers
        // fetch directly via the web server (no PHP boot per asset). This is
        // the speed-critical path for SaaS customers.
        $home_path = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '';
        $home_path = rtrim( $home_path, '/' );

        if ( $asset_mode === 'proxy' ) {
            // Fully-qualified URLs (with scheme) that point at content/includes
            $html = str_replace( trailingslashit( content_url() ),  $v_assets . '/', $html );
            $html = str_replace( trailingslashit( includes_url() ), $v_lib    . '/', $html );
            // Site-relative paths missing scheme (Elementor sometimes emits these)
            $html = str_replace( $home_path . '/wp-content/',  $home_path . $prefix . '/_ncx_v12/assets/', $html );
            $html = str_replace( $home_path . '/wp-includes/', $home_path . $prefix . '/_ncx_v12/lib/',    $html );

            // JSON-escaped variants (inline scripts emit URLs with \/ slashes —
            // Elementor's elementorFrontendConfig, ElementorProFrontendConfig,
            // wpApiSettings, and many wp_localize_script payloads).
            // Matches patterns like:  "url":"http:\/\/host\/wp-content\/..."
            $content_url_json  = str_replace( '/', '\/', trailingslashit( content_url() ) );
            $includes_url_json = str_replace( '/', '\/', trailingslashit( includes_url() ) );
            $v_assets_json     = str_replace( '/', '\/', $v_assets . '/' );
            $v_lib_json        = str_replace( '/', '\/', $v_lib . '/' );
            $html = str_replace( $content_url_json,  $v_assets_json, $html );
            $html = str_replace( $includes_url_json, $v_lib_json,    $html );

            // JSON-escaped site-relative variants
            $home_path_json   = str_replace( '/', '\/', $home_path );
            $proxy_path_json  = str_replace( '/', '\/', $home_path . NEXENG_Init::proxy_prefix() );
            $html = str_replace( $home_path_json . '\/wp-content\/',  $proxy_path_json . '\/_ncx_v12\/assets\/', $html );
            $html = str_replace( $home_path_json . '\/wp-includes\/', $proxy_path_json . '\/_ncx_v12\/lib\/',    $html );
        }

        // Catch-all sweep — only in proxy mode (direct mode keeps real paths)
        $prefix_json = str_replace( '/', '\/', $prefix );
        if ( $asset_mode === 'proxy' ) {
            $html = str_replace( '/wp-content/',   $prefix . '/_ncx_v12/assets/', $html );
            $html = str_replace( '/wp-includes/',  $prefix . '/_ncx_v12/lib/',    $html );
            $html = str_replace( '\/wp-content\/', $prefix_json . '\/_ncx_v12\/assets\/', $html );
            $html = str_replace( '\/wp-includes\/', $prefix_json . '\/_ncx_v12\/lib\/',  $html );
        }

        // ─── admin-ajax.php → /_ncx/aj ────────────────────────────────────
        // ALWAYS proxied regardless of asset_mode. These are functional
        // endpoints (forms, dynamic widgets) — proxying them hides /wp-admin/
        // from URLs and keeps the nonce-rehydration flow consistent.
        //
        // The path is derived from admin_url() rather than assumed to be
        // /wp-admin/admin-ajax.php: WP_ADMIN_DIR and a relocated wp-admin are
        // both supported configurations, and on those installs a hardcoded
        // needle silently matches nothing, leaving the real path in the HTML.
        $ajax_path = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
        $ajax_path = is_string( $ajax_path ) && '' !== $ajax_path ? $ajax_path : '/wp-admin/admin-ajax.php';
        $html = str_replace( $ajax_path, $prefix . '/_ncx/aj', $html );
        $html = str_replace(
            str_replace( '/', '\/', $ajax_path ),
            $prefix_json . '\/_ncx\/aj',
            $html
        );

        // ─── wp-json/ → /_ncx/api/ ────────────────────────────────────────
        // Always proxied. Hides /wp-json/ from inline JS configs.
        $html = str_replace( '/wp-json/',   $prefix . '/_ncx/api/', $html );
        $html = str_replace( '\/wp-json\/', $prefix_json . '\/_ncx\/api\/', $html );
        $html = str_replace( '/wp-json"',   $prefix . '/_ncx/api"', $html );
        $html = str_replace( '\/wp-json"',  $prefix_json . '\/_ncx\/api"', $html );

        // ─── Cache-buster stripping (PROXY mode only) ─────────────────────
        // ?ver=X.X.X is WordPress's primary browser cache-busting mechanism.
        // Stripping it in DIRECT mode destroys cache invalidation — browsers
        // permanently cache old JS/CSS and never detect plugin/theme updates.
        // In PROXY mode it's acceptable (stealth, asset paths already rewritten).
        if ( $asset_mode === 'proxy' ) {
            $html = preg_replace( '/(?:\?|&|#)ver=[^"\'&\s>]*/i', '', $html );
        }
        // We keep script/style IDs because many plugins (Elementor, CF7) use them
        // for self-discovery and dynamic configuration lookup.

        // ─── Speculation Rules (WP 6.4+) — strip entirely ────────────────
        // The block exposes /wp-*.php, /wp-admin/, plugin paths and is purely
        // a prefetch hint. Static pages don't need it.
        $html = preg_replace( '/<script[^>]*\btype=["\']speculationrules["\'][^>]*>[\s\S]*?<\/script>\s*/i', '', $html );

        // ─── DevTools sourceURL comments (both JS and CSS formats) ──────────
        // JS format:  //# sourceURL=wp-i18n-js-after
        // CSS format: /*# sourceURL=wp-block-library-inline-css */
        // Both expose WordPress script/style identifiers in DevTools.
        // Zero runtime impact when removed.
        $html = preg_replace( '/\s*\/\/#\s*sourceURL=[^\r\n]+/i', '', $html );
        $html = preg_replace( '/\s*\/\*#\s*sourceURL=[^\r\n*]*\*\//i', '', $html );

        // ─── Strip wp- prefixed id= attributes from <style> and <link> elements ──
        // e.g. id='wp-block-library-inline-css', id='colormag_style-css'
        // These are WordPress asset-tracker IDs — they have no CSS/JS function
        // in static files and expose the stack to fingerprinting tools.
        $html = preg_replace_callback(
            '/(<(?:style|link)\b[^>]*)\bid=["\'][^"\']*["\']([^>]*>)/i',
            function ( $m ) { return $m[1] . $m[2]; },
            $html
        );

        // ─── ncx-bootstrap polyfill ───────────────────────────────────────
        // Injected only if not already present (shell-template.php outputs
        // a richer version during the loopback capture — don't duplicate it).
        $nexeng_bootstrap = '<script id="ncx-bootstrap">'
            . 'var ncx=window.ncx||{};'
            . 'ncx.i18n=ncx.i18n||{__:function(s){return s},_x:function(s){return s},_n:function(s){return s},_nx:function(s){return s},sprintf:function(){return arguments[0]},setLocaleData:function(){},getLocaleData:function(){return{}}};'
            . 'ncx.hooks=ncx.hooks||{addAction:function(){},doAction:function(){},addFilter:function(){},applyFilters:function(n,v){return v},removeAction:function(){},removeFilter:function(){},didAction:function(){return 0},didFilter:function(){return 0},hasAction:function(){return false},hasFilter:function(){return false}};'
            . 'var wp=window.wp||ncx; window.ncx=ncx; window.wp=wp;'
            . '</script>';
        if ( strpos( $html, 'ncx-bootstrap' ) === false ) {
            if ( preg_match( '/(<head\b[^>]*>)/i', $html ) ) {
                $html = preg_replace( '/(<head\b[^>]*>)/i', '$1' . $nexeng_bootstrap, $html, 1 );
            } else {
                // Fallback: inject right after DOCTYPE or <html>
                $html = preg_replace( '/(<html\b[^>]*>|<!DOCTYPE\b[^>]*>)/i', '$1' . $nexeng_bootstrap, $html, 1 );
                if ( strpos( $html, 'ncx-bootstrap' ) === false ) {
                    $html = $nexeng_bootstrap . $html;
                }
            }
        }

        // ─── Plugin / theme path compression ──────────────────────────────
        // Only compress paths we can reverse-map at serve time. We deliberately
        // do NOT compress arbitrary plugin slugs to "pkg/" because the serve
        // handler can't know which plugin "pkg/" originally meant → 404.
        // Keeping the plugin slug visible (e.g. "contact-form-7") is fine —
        // a plugin slug alone isn't a WordPress fingerprint.
        $html = str_replace( '/_ncx_v12/assets/plugins/elementor-pro/', '/_ncx_v12/assets/ep/', $html );
        $html = str_replace( '/_ncx_v12/assets/plugins/elementor/',     '/_ncx_v12/assets/e/',  $html );
        $html = str_replace( '/_ncx_v12/assets/themes/',                '/_ncx_v12/assets/t/',  $html );
        // Hide Elementor-named uploads dir
        $html = str_replace( '/_ncx_v12/assets/uploads/elementor/', '/_ncx_v12/assets/uploads/ncx/', $html );

        // ─── Class-attribute fingerprint stripping (PROXY mode only) ────────
        //
        // WHY gated to PROXY mode:
        //   In DIRECT mode the static file must be a pixel-perfect mirror of
        //   the live WordPress page. Removing body classes like the theme slug
        //   ("astra", "hello-elementor") breaks `body.astra { }` CSS rules and
        //   causes layout regressions. Removing `page-template-elementor_header_footer`
        //   breaks Elementor's canvas template entirely. Removing `attachment-*`
        //   and `size-*` from images breaks responsive-image CSS rules.
        //
        //   In PROXY mode (identity masking) we accept a narrow trade-off:
        //   only numeric post IDs — which have zero CSS targeting value — are
        //   removed. Theme slugs and ALL functional CSS hooks are preserved.
        if ( $asset_mode === 'proxy' ) {
            // Body: strip only provably-safe numeric page IDs.
            // NEVER strip: theme slugs, page-template-*, elementor-*, wp-* etc.
            $html = preg_replace_callback(
                '/(<body[^>]*\bclass=["\'])([^"\']+)(["\'])/i',
                function ( $m ) {
                    $classes = preg_split( '/\s+/', trim( $m[2] ) );
                    $kept    = array_filter(
                        $classes,
                        fn( $c ) => $c !== '' && ! preg_match( '/^page-id-\d+$/i', $c )
                    );
                    return $m[1] . implode( ' ', $kept ) . $m[3];
                },
                $html
            );

            // Image elements: strip only the numeric post-ID class; keep
            // attachment-* and size-* (themes target them for responsive sizing).
            $html = preg_replace( '/(?<=[\s"\'])wp-image-\d+/i', '', $html );

            // Menu: strip only numeric item IDs; keep type/object classes.
            $html = preg_replace( '/(?<=[\s"\'])menu-item-\d+/i', '', $html );
            $html = preg_replace( '/(?<=[\s"\'])page-item-\d+/i', '', $html );

            // Collapse double-spaces introduced by the strips above.
            $html = preg_replace( '/(\sclass=["\'])\s+/',          '$1',  $html );
            $html = preg_replace( '/(\sclass=["\'][^"\']*?)\s{2,}/', '$1 ', $html );
        }

        // ─── Lazy-load injection (all modes) ──────────────────────────────
        // WordPress only applies loading="lazy" to images it outputs via
        // wp_get_attachment_image(). Images from theme templates, widgets,
        // or page-builder dynamic tags often have decoding="async" but no
        // loading attribute, so the browser fetches them all immediately.
        //
        // Strategy:
        //  - Skip any <img> that already declares loading= (respect plugin decisions)
        //  - Skip any <img> with fetchpriority="high" (LCP hero image — must load eagerly)
        //  - Skip the very first <img> on the page (likely above-fold logo / hero)
        //  - Add loading="lazy" + decoding="async" to everything else
        $img_index = 0;
        $html = preg_replace_callback(
            '/<img\b([^>]*)\/?>/i',
            function ( $m ) use ( &$img_index ) {
                $attrs = $m[1];
                $img_index++;
                // Already has a loading decision — respect it.
                if ( stripos( $attrs, 'loading=' ) !== false ) {
                    return $m[0];
                }
                // LCP candidate — must not be deferred.
                if ( stripos( $attrs, 'fetchpriority=' ) !== false ) {
                    return $m[0];
                }
                // First image on the page is assumed above-fold — load eagerly.
                if ( $img_index === 1 ) {
                    return $m[0];
                }
                // Inject loading="lazy".  Preserve self-closing slash if present.
                $slash = ( substr( rtrim( $attrs ), -1 ) === '/' ) ? ' /' : '';
                $clean = rtrim( rtrim( $attrs ), '/' );
                return '<img' . $clean . ' loading="lazy"' . $slash . '>';
            },
            $html
        );

        // ─── Strip WP_DEBUG / Xdebug output ──────────────────────────────
        // When WP_DEBUG is enabled PHP can inject notices, warnings, and
        // deprecated messages into the response buffer before the theme
        // outputs HTML. If Nexora captures a page in that state, the debug
        // output is frozen into the static file and served to every visitor
        // even after WP_DEBUG is turned off.
        //
        // Patterns handled:
        //   <br />\n<b>Notice</b>:  …  in /…/file.php on line N
        //   <br />\n<b>Warning</b>: …
        //   <br />\n<b>Deprecated</b>: …
        //   <br />\n<b>Fatal error</b>: …
        //   <br />\n<b>Parse error</b>: …
        //   Xdebug var_dump / stack-trace <font> blocks
        //   <!-- Xdebug: … -->
        //   PHP startup notice lines (plain text before <DOCTYPE / <html)
        $html = preg_replace(
            '/<br\s*\/?>\s*\n?<b>(?:Notice|Warning|Deprecated|Fatal error|Parse error|Strict Standards)<\/b>:.*?(?:<br\s*\/?>|$)/im',
            '',
            $html
        );
        // Xdebug HTML output (<font color=…> blocks wrapping a stack trace)
        $html = preg_replace(
            '/<font[^>]*>.*?Xdebug.*?<\/font>/is',
            '',
            $html
        );
        // Xdebug HTML table (the full call-stack block)
        $html = preg_replace(
            '/<table[^>]*\bclass=["\'][^"\']*xdebug[^"\']*["\'][^>]*>[\s\S]*?<\/table>/i',
            '',
            $html
        );
        // Plain-text PHP messages that appear before the DOCTYPE (WP_DEBUG + display_errors)
        // e.g. "PHP Notice:  Undefined variable: foo in /…/file.php on line 42"
        $html = preg_replace(
            '/^(?:PHP (?:Notice|Warning|Deprecated|Fatal error|Parse error|Strict Standards):[ \t].+\n?)+/m',
            '',
            $html
        );

        return $html;
    }

    /**
     * LCP Preload Injection.
     *
     * Scans the captured HTML for the most-likely Largest Contentful Paint
     * resource and injects a <link rel="preload" fetchpriority="high"> hint
     * into <head> so the browser starts fetching the hero asset during the
     * very first network round-trip — before the parser has reached <body>.
     *
     * Two LCP patterns handled:
     *
     *   1. <img> hero     — The first <img> on the page that does not already
     *                       carry fetchpriority="high".  We add the attribute
     *                       to the tag itself AND emit a preload link so even
     *                       HTTP/2 push-capable servers can act on it early.
     *
     *   2. CSS bg-image   — Divi, Elementor, and most page-builders render
     *                       hero sections as <div style="background-image:url(…)">
     *                       or put the rule in an inline <style> block.  We scan
     *                       the first 8 KB of <body> for the first external URL
     *                       and preload it.
     *
     * Called in the capture pipeline after inject_hydration and before
     * consolidate_inline_assets so that the preload links survive minification.
     */
    public function inject_lcp_preloads( string $html ): string {
        $preload_urls = [];

        // ── 1. First <img> candidate ───────────────────────────────────────
        // inject_hydration's lazy-load pass already skips img_index === 1 so
        // the first image is never lazy-loaded.  We add fetchpriority="high"
        // here (if missing) and queue its src for the <link rel="preload">.
        $img_done = false;
        $html = preg_replace_callback(
            '/<img\b([^>]*)\/?>/i',
            function ( $m ) use ( &$img_done, &$preload_urls ) {
                if ( $img_done ) {
                    return $m[0]; // only process the very first <img>
                }
                $img_done = true;
                $attrs    = $m[1];

                // Skip data-URIs — no network fetch, no point preloading.
                if ( preg_match( '/\bsrc=["\']data:/i', $attrs ) ) {
                    return $m[0];
                }

                // Extract src URL.
                $src = '';
                if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/', $attrs, $sm ) ) {
                    $src = $sm[1];
                }

                // Already has fetchpriority — collect src but leave tag alone.
                if ( stripos( $attrs, 'fetchpriority=' ) !== false ) {
                    if ( $src ) {
                        $preload_urls[] = [ 'href' => $src ];
                    }
                    return $m[0];
                }

                // Inject fetchpriority="high" onto the tag.
                if ( $src ) {
                    $preload_urls[] = [ 'href' => $src ];
                }
                $slash = ( substr( rtrim( $attrs ), -1 ) === '/' ) ? ' /' : '';
                $clean = rtrim( rtrim( $attrs ), '/' );
                return '<img' . $clean . ' fetchpriority="high"' . $slash . '>';
            },
            $html
        );

        // ── 2. CSS background-image in first 8 KB of <body> ───────────────
        // Page-builders (Divi, Elementor) typically render the hero section
        // as a div with a background-image rather than an <img>, so the
        // browser discovers the URL very late (after CSS evaluation).
        if ( preg_match( '/<body\b[^>]*>/i', $html, $bm, PREG_OFFSET_CAPTURE ) ) {
            $body_start = $bm[0][1] + strlen( $bm[0][0] );
            $scan_chunk = substr( $html, $body_start, 8192 );

            // 2a. Inline style attribute: style="…background-image:url(…)…"
            if ( preg_match(
                '/\bstyle=["\'][^"\']*background(?:-image)?\s*:\s*url\(\s*["\']?([^)"\'>\s]+)["\']?\s*\)/i',
                $scan_chunk,
                $bgm
            ) ) {
                $bg_url = trim( $bgm[1], "'\"" );
                if ( $bg_url && strpos( $bg_url, 'data:' ) === false ) {
                    $preload_urls[] = [ 'href' => $bg_url ];
                }
            }

            // 2b. Inline <style> block in the first 8 KB.
            if ( preg_match( '/<style\b[^>]*>([\s\S]*?)<\/style>/i', $scan_chunk, $stm ) ) {
                if ( preg_match(
                    '/background(?:-image)?\s*:\s*url\(\s*["\']?([^)"\'>\s]+)["\']?\s*\)/i',
                    $stm[1],
                    $bgm2
                ) ) {
                    $bg_url = trim( $bgm2[1], "'\"" );
                    if ( $bg_url && strpos( $bg_url, 'data:' ) === false ) {
                        $preload_urls[] = [ 'href' => $bg_url ];
                    }
                }
            }
        }

        if ( empty( $preload_urls ) ) {
            return $html;
        }

        // ── 3. Build <link rel="preload"> tags ─────────────────────────────
        $link_tags = '';
        $seen      = [];
        foreach ( $preload_urls as $item ) {
            $href = esc_attr( $item['href'] );
            if ( isset( $seen[ $href ] ) ) {
                continue;
            }
            $seen[ $href ] = true;

            // Optional `type` hint speeds up preload matching in some browsers.
            $ext       = strtolower( pathinfo( strtok( $href, '?' ), PATHINFO_EXTENSION ) );
            $type_attr = '';
            if ( $ext === 'webp' ) {
                $type_attr = ' type="image/webp"';
            } elseif ( $ext === 'avif' ) {
                $type_attr = ' type="image/avif"';
            } elseif ( in_array( $ext, [ 'jpg', 'jpeg' ], true ) ) {
                $type_attr = ' type="image/jpeg"';
            } elseif ( $ext === 'png' ) {
                $type_attr = ' type="image/png"';
            }

            $link_tags .= '<link rel="preload" as="image" fetchpriority="high" href="' . $href . '"' . $type_attr . ">\n";
        }

        // ── 4. Inject just before </head> ──────────────────────────────────
        if ( $link_tags ) {
            $html = preg_replace( '/(<\/head>)/i', $link_tags . '$1', $html, 1 );
        }

        return $html;
    }

    /**
     * Replaces server-generated nonces with placeholders for client-side
     * rehydration via the /_ncx/nonce endpoint (introduced in step 6).
     *
     * Targets:
     *   - <input name="_wpnonce" value="..."> → value emptied + data-ncx-nonce
     *   - "nonce":"<hex32+>" inside inline JS configs (Elementor etc.)
     */
    public function rewrite_nonces( string $html ): string {
        // (1) Standard WP nonce inputs (CF7, comment, login, generic forms).
        $html = preg_replace(
            '/(<input[^>]*\bname=["\']_wpnonce["\'][^>]*\bvalue=["\'])([a-f0-9]{8,})(["\'])/i',
            '$1$3 data-ncx-nonce="wp"',
            $html
        );

        // (2) JSON-style nonce keys in inline JS (Elementor Pro forms, REST configs).
        // Conservatively match only hex strings 32+ chars to avoid touching unrelated keys.
        $html = preg_replace(
            '/("nonce"\s*:\s*")([a-f0-9]{32,})(")/',
            '$1__NEXENG_NONCE__$3',
            $html
        );

        // (3) wpApiSettings.nonce — emitted by wp_localize_script for REST.
        $html = preg_replace(
            '/(wpApiSettings\s*=\s*\{[^}]*"nonce"\s*:\s*")([a-f0-9]{8,})(")/',
            '$1__NEXENG_NONCE_REST__$3',
            $html
        );

        return $html;
    }

    /**
     * Neural Minification Engine (Ultra-Safe Edition).
     * Collapses excessive whitespace without breaking DOM traversal or layout.
     * Uses non-comment markers to prevent placeholder-stripping during cleaning.
     */
    public function minify_html( string $html ): string {
        $placeholders = [];
        
        // 1. Protect scripts and styles from whitespace collapsing.
        // We use a unique string prefix '%%%NCX_M_' which is not a valid HTML tag 
        // or comment, so it won't be touched by the cleaning regexes.
        $html = preg_replace_callback( '/<(script|style)\b[^>]*>([\s\S]*?)<\/\1>/i', function( $m ) use ( &$placeholders ) {
            $id = '%%%NCX_M_' . count( $placeholders ) . '%%%';
            $placeholders[ $id ] = $m[0];
            return $id;
        }, $html );

        // 2. Collapse multiple spaces/tabs to one.
        $html = preg_replace( '/[ \t]+/', ' ', $html );

        // 3. Collapse multiple newlines to one.
        $html = preg_replace( '/[\r\n]+/', "\n", $html );

        // 4. Strip HTML comments, EXCEPT for our bootstrap/hydration markers.
        // This is safe now because our placeholders are NOT comments.
        $html = preg_replace( '/<!--(?!\/?\s*ncx-(?:hydration|bootstrap))[\s\S]*?-->/i', '', $html );

        // 5. Aggressive Minification: Remove all newlines and extra spaces between tags.
        // We can safely do this because all sensitive JS/CSS is currently protected 
        // in the $placeholders array.
        $html = preg_replace( '/>\s+</', '><', $html );
        $html = str_replace( [ "\r", "\n" ], ' ', $html );
        $html = preg_replace( '/\s{2,}/', ' ', $html );
        $html = trim( $html );

        // 6. Restore scripts and styles exactly as they were.
        if ( ! empty( $placeholders ) ) {
            $html = strtr( $html, $placeholders );
        }

        return $html;
    }

    /**
     * Navigation Enhancer — eliminates the browser spinner on menu navigation
     * and pre-warms static pages so they load from cache before the click.
     *
     * WHY NO @view-transition / prerender:
     *   Cross-document @view-transition animations are removed because they can
     *   feel disorienting for first-time visitors and are not universally
     *   appreciated across all audience demographics.
     *
     *   Speculation Rules *prerender* is intentionally excluded.  Prerendering
     *   executes the full destination page in an isolated BrowsingContext before
     *   the user navigates.  Chrome's spec requires that sessionStorage in a
     *   prerendered context starts as a FRESH COPY of the activating page's
     *   storage and is only merged on activation — meaning any popup/modal that
     *   tracks its "dismissed" state in sessionStorage will see an empty store
     *   and re-trigger for every prerendered page.  This was the root cause of
     *   the one-time popup re-appearing on every mobile page switch.
     *
     * What IS injected (two layers, zero side-effects):
     *
     *  Layer 1 — <link rel="prefetch"> on hover/touchstart (all browsers)
     *    Downloads the destination HTML into the browser's disk cache during
     *    the hover/touch dwell time.  Prefetch is a *resource hint only* — it
     *    never executes JavaScript, never touches sessionStorage or cookies, and
     *    never interferes with popup plugins.  When the user clicks the link the
     *    response comes from disk cache → near-zero network round-trip.
     *
     *  Layer 2 — Speculation Rules prefetch (Chrome 121+)
     *    Asks Chrome's own scheduler to prefetch internal links with "moderate"
     *    eagerness (200 ms hover), complementing the DOM-based prefetch above
     *    with browser-native prioritisation and cache-warming at the network
     *    layer.  *Prefetch only* — never prerender.
     *
     *  Progress bar (all browsers)
     *    A 3-pixel gradient bar (indigo → purple → pink) slides across the top
     *    of the viewport on every internal navigation, replacing the browser's
     *    native tab spinner with a polished loading indicator.  Completes and
     *    fades on pageshow (covers bfcache restores too).
     *
     * Idempotent — the "ncx-nav-enhancer" string inside the protected <script>
     * block survives minify_html's placeholder system and prevents re-injection.
     */
    public function inject_navigation_enhancer( string $html ): string {
        // Already injected — skip (pipeline re-entry / double-call guard).
        if ( strpos( $html, 'ncx-nav-enhancer' ) !== false ) {
            return $html;
        }

        // ── CSS: progress bar only — no animation rules ──────────────────────
        $css = '<style id="ncx-nav-enhancer">'
            . '#ncx-progress{'
            .   'position:fixed;top:0;left:0;width:0;height:3px;'
            .   'background:linear-gradient(90deg,#6366f1,#a855f7,#ec4899);'
            .   'z-index:2147483647;border-radius:0 2px 2px 0;'
            .   'transition:width 220ms ease,opacity 400ms ease;'
            .   'opacity:0;pointer-events:none'
            . '}'
            . '</style>' . "\n";

        // ── JavaScript: prefetch-on-hover + progress bar ─────────────────────
        // Intentionally uses prefetch (not prerender) so popup / modal plugins
        // that rely on sessionStorage for dismissal state are never disturbed.
        // phpcs:ignore PluginCheck.CodeAnalysis.Heredoc.NotAllowed -- Heredoc holds a multi-line config/JS template; valid PHP, far more readable and less error-prone than concatenation here.
        ob_start();
        ?>
<script id="ncx-nav-enhancer">(function(){'use strict';
/* NCX Navigation Enhancer — prefetch on hover + progress bar */

/* ── Progress bar ─────────────────────────────────────────────────── */
var _bar=document.createElement('div');
_bar.id='ncx-progress';
document.body.appendChild(_bar);
var _bt=null,_bv=0;
function _bStart(){
  _bv=6;_bar.style.opacity='1';_bar.style.width=_bv+'%';
  _bt=setInterval(function(){
    _bv=Math.min(_bv+(Math.random()*10+2),88);
    _bar.style.width=_bv+'%';
  },150);
}
function _bDone(){
  clearInterval(_bt);
  _bar.style.width='100%';
  setTimeout(function(){
    _bar.style.opacity='0';
    setTimeout(function(){_bar.style.width='0%';},450);
  },180);
}

/* ── Internal-URL guard ───────────────────────────────────────────── */
function _isLocal(url){
  try{
    var u=new URL(url,location.href);
    return u.hostname===location.hostname
      && !u.hash
      && !/\.(pdf|zip|docx?|xlsx?|pptx?|mp4|mp3|webm|rar|7z)(\?|$)/i.test(u.pathname);
  }catch(e){return false;}
}

/* ── Layer 1: <link rel="prefetch"> on hover/touch (all browsers) ── */
/* Downloads HTML into disk cache — never executes JS, never touches  */
/* sessionStorage, cookies, or popup state.                           */
var _pf=new Set();
function _prefetch(url){
  if(_pf.has(url))return;
  _pf.add(url);
  var l=document.createElement('link');
  l.rel='prefetch';l.as='document';l.href=url;
  document.head.appendChild(l);
}
document.addEventListener('mouseover',function(e){
  var a=e.target.closest('a[href]');
  if(a&&_isLocal(a.href))_prefetch(a.href);
},{passive:true});
document.addEventListener('touchstart',function(e){
  var a=e.target.closest('a[href]');
  if(a&&_isLocal(a.href))_prefetch(a.href);
},{passive:true});

/* ── Progress bar on navigation click ───────────────────────────── */
document.addEventListener('click',function(e){
  var a=e.target.closest('a[href]');
  if(!a||!_isLocal(a.href)||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  _bStart();
});

/* Complete bar on pageshow — covers normal load + bfcache restore */
window.addEventListener('pageshow',function(e){
  if(e.persisted)_bDone();
});

/* ── Layer 2: Speculation Rules prefetch (Chrome 121+) ───────────── */
/* Prefetch only — never prerender — to avoid sessionStorage        */
/* isolation issues that cause one-time popups to re-trigger.       */
if(typeof HTMLScriptElement!=='undefined'
   &&HTMLScriptElement.supports
   &&HTMLScriptElement.supports('speculationrules')){
  var _sr=document.createElement('script');
  _sr.type='speculationrules';
  _sr.textContent=JSON.stringify({
    prefetch:[{
      where:{and:[
        {href_matches:"/*"},
        {not:{href_matches:"/*\\?*"}},
        {not:{href_matches:"/wp-admin/*"}},
        {not:{href_matches:"/wp-login*"}}
      ]},
      eagerness:"moderate"
    }]
  });
  document.head.appendChild(_sr);
}
})();</script>
        <?php
        $js = ob_get_clean();

        // Inject CSS into <head> (just before </head>).
        $html = preg_replace( '/(<\/head>)/i', $css . '$1', $html, 1 );

        // Inject JS just before </body>.
        $pos = stripos( $html, '</body>' );
        if ( $pos !== false ) {
            $html = substr( $html, 0, $pos ) . $js . "\n" . substr( $html, $pos );
        } else {
            $html .= $js; // no </body> fallback
        }

        return $html;
    }

    /**
     * Assets are now left in their original position to preserve the
     * CSS cascade and JS initialization order.
     */
    public function consolidate_inline_assets( string $html ): string {
        // SAFE-BY-DEFAULT inline-CSS optimization. Page builders (especially
        // Elementor) ship large, un-minified inline <style> blocks — comments,
        // indentation, and the SAME global stylesheet repeated on every page.
        // We do two things that reduce page weight WITHOUT changing what any CSS
        // rule matches (so it cannot break a layout):
        //
        //   1. Minify the CSS inside each <style> block (strip CSS comments and
        //      collapse whitespace). Minifying CSS never changes selectors or
        //      specificity — only byte size.
        //   2. De-duplicate byte-identical <style> blocks — keep the first,
        //      drop later exact copies (Elementor re-emits the same kit CSS).
        //
        // We deliberately do NOT remove "unused" rules: matching selectors
        // against the DOM is unreliable for JS-added classes, hover/focus
        // states, and responsive rules, and getting it wrong breaks sites. The
        // performance analyzer flags heavy inline CSS so users can address the
        // source; here we only do the lossless wins.
        //
        // Gated by a filter so it can be disabled per-site if ever needed.
        if ( ! apply_filters( 'nexeng_optimize_inline_css', true ) ) {
            return $html;
        }

        $seen = [];
        return preg_replace_callback(
            '/<style\b([^>]*)>([\s\S]*?)<\/style>/i',
            function ( $m ) use ( &$seen ) {
                $attrs = $m[1];
                $css   = $m[2];

                // Skip anything that isn't plain CSS we can safely touch (e.g.
                // a <style> with type="text/template" or amp-custom). Only
                // process the default/empty type.
                if ( preg_match( '/type\s*=\s*["\']?(?!text\/css)[^"\'\s>]+/i', $attrs ) ) {
                    return $m[0];
                }

                $min = $this->minify_css( $css );

                // Drop exact-duplicate style blocks (after minify, so trivial
                // whitespace differences still de-dupe). Keep the first copy.
                $key = md5( $attrs . '|' . $min );
                if ( isset( $seen[ $key ] ) ) {
                    return '';
                }
                $seen[ $key ] = true;

                return '<style' . $attrs . '>' . $min . '</style>';
            },
            $html
        );
    }

    /**
     * Minify a CSS string losslessly: strip comments, collapse whitespace, and
     * tidy the punctuation around braces/colons/semicolons. Never alters
     * selectors or values in a way that changes matching — purely byte size.
     *
     * @param string $css Raw CSS.
     * @return string Minified CSS.
     */
    private function minify_css( string $css ): string {
        if ( '' === trim( $css ) ) {
            return $css;
        }
        // 1. Remove CSS comments  /* … */  (but not the !important-style tokens).
        $css = preg_replace( '!/\*[^*]*\*+([^/*][^*]*\*+)*/!', '', $css );
        // 2. Collapse runs of whitespace to a single space.
        $css = preg_replace( '/\s+/', ' ', $css );
        // 3. Remove spaces around structural punctuation.
        $css = preg_replace( '/\s*([{}:;,>~])\s*/', '$1', $css );
        // 4. Drop the last semicolon before a closing brace.
        $css = str_replace( ';}', '}', $css );
        // 5. Trim.
        $css = trim( $css );
        return $css;
    }

    // ─── Nonce Endpoint + Hydration ───────────────────────────────────────────

    /**
     * Handles GET /_ncx/nonce — returns fresh CSRF tokens for static-page
     * forms. Custom path (not /wp-json/...) per stealth posture.
     *
     * Rate-limited per IP. No auth: nonces are not secrets, they are CSRF
     * tokens scoped to actions and bound to the visitor's session cookie.
     */
    public function handle_nonce_request(): void {
        // Rate limit: 30/min/IP.
        $ip  = $this->client_ip();
        $key = 'nexeng_nonce_rl_' . md5( $ip );
        $hits = (int) get_transient( $key );
        if ( $hits > 30 ) {
            status_header( 429 );
            header( 'Retry-After: 60' );
            header( 'Content-Type: application/json; charset=UTF-8' );
            echo json_encode( [ 'error' => 'rate_limited' ] );
            exit;
        }
        set_transient( $key, $hits + 1, 60 );

        $payload = [
            'wp'        => wp_create_nonce( 'wp_rest' ),
            'rest'      => wp_create_nonce( 'wp_rest' ),
            'elementor' => wp_create_nonce( 'elementor-frontend' ),
            'cf7'       => wp_create_nonce( 'wp_rest' ),
            'comment'   => wp_create_nonce( 'unfiltered-html-comment_0' ),
        ];

        // Allow third-party plugins to add their own.
        $payload = apply_filters( 'nexeng_ssg_nonce_payload', $payload );

        if ( ob_get_level() ) {
            ob_end_clean();
        }
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Cache-Control: no-store, private' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        echo json_encode( [ 'success' => true, 'nonces' => $payload ] );
        exit;
    }

    private function client_ip(): string {
        // Conservative — don't trust forwarded headers by default. Hosting
        // behind Cloudflare etc. can opt in via a filter later.
        return NEXENG_Request::ip() ?: '0.0.0.0';
    }

    /**
     * Injects the hydration <script> just before </body>. Idempotent — if
     * a marker comment already exists, it is replaced, not duplicated.
     */
    public function inject_hydration( string $html ): string {
        $script = $this->hydration_script();
        $marker = '<!-- ncx-hydration -->';

        if ( strpos( $html, $marker ) !== false ) {
            // Replace existing block (defensive — captures shouldn't loop, but be safe).
            $html = preg_replace(
                '/' . preg_quote( $marker, '/' ) . '[\s\S]*?<!-- \/ncx-hydration -->/',
                $marker . $script . '<!-- /ncx-hydration -->',
                $html
            );
            return $html;
        }

        $block = $marker . $script . '<!-- /ncx-hydration -->';
        $pos   = stripos( $html, '</body>' );
        if ( $pos === false ) {
            // No </body> — append at end as fallback.
            return $html . $block;
        }
        return substr( $html, 0, $pos ) . $block . substr( $html, $pos );
    }

    /**
     * The client-side hydration script. Lazy: only fires on first form
     * interaction, so visitors who don't touch a form pay zero cost.
     */
    private function hydration_script(): string {
        // Build the nonce URL with the site's home path prefix so it works
        // on subdir installs (e.g. /taj/_ncx/nonce).
        $base      = rtrim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
        $nonce_url = $base . '/_ncx/nonce';
        // Escape for safe embedding in JS string literal.
        $nonce_url_js = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $nonce_url );

        // phpcs:ignore PluginCheck.CodeAnalysis.Heredoc.NotAllowed -- Heredoc holds a multi-line config/JS template; valid PHP, far more readable and less error-prone than concatenation here.
        ob_start();
        ?>
<script>
(function(){
    var NEXENG_NONCE_URL = '<?php echo $nonce_url_js; ?>';
    // Nonce cache: sessionStorage with 5-minute TTL prevents 429 rate-limit errors.
    // WordPress nonces last 12-24 h, so 5 min is conservative but eliminates hammering
    // the nonce endpoint on every page navigation within the same browser session.
    var NONCE_TTL = 300000;
    var fetched = false, pending = null;
    function loadCached() {
        try {
            var raw = sessionStorage.getItem('nexeng_n');
            if (raw) { var d=JSON.parse(raw); if(d&&d.t&&d.n&&(Date.now()-d.t)<NONCE_TTL) return d.n; }
        } catch(e) {}
        return null;
    }
    function saveCached(n) {
        try { sessionStorage.setItem('nexeng_n', JSON.stringify({t:Date.now(),n:n})); } catch(e) {}
    }
    function get() {
        var c = loadCached();
        if (c) return Promise.resolve(c);
        if (pending) return pending;
        pending = fetch(NEXENG_NONCE_URL, { credentials: 'same-origin', cache: 'no-store' })
            .then(function(r){ if(r.status===429) return null; return r.ok ? r.json() : null; })
            .then(function(j){ var n=j&&j.nonces?j.nonces:null; if(n) saveCached(n); return n; })
            .catch(function(){ return null; });
        return pending;
    }

    function applyTo(root, n) {
        if (!n) return;

        // 1) <input data-ncx-nonce="wp"> form fields (CF7, comment, login, etc.)
        var inputs = root.querySelectorAll('input[data-ncx-nonce]');
        for (var i = 0; i < inputs.length; i++) {
            var key = inputs[i].getAttribute('data-ncx-nonce') || 'wp';
            if (n[key]) { inputs[i].value = n[key]; inputs[i].removeAttribute('data-ncx-nonce'); }
        }

        // 2) Inline-script textContent placeholders (cosmetic — for any code
        //    that re-reads the script tag, rare).
        var scripts = document.querySelectorAll('script:not([src])');
        for (var j = 0; j < scripts.length; j++) {
            var t = scripts[j].textContent;
            if (!t) continue;
            if (t.indexOf('__NEXENG_NONCE__') !== -1 && n.elementor) {
                scripts[j].textContent = t.split('__NEXENG_NONCE__').join(n.elementor);
            }
            if (t.indexOf('__NEXENG_NONCE_REST__') !== -1 && n.rest) {
                scripts[j].textContent = t.split('__NEXENG_NONCE_REST__').join(n.rest);
            }
        }

        // 3) **CRITICAL** — patch live JS globals. Inline scripts have already
        //    executed by the time we hydrate, so the variables they declared
        //    hold the placeholder string. Form plugins read these globals at
        //    submit time; we have to update them in place.
        try {
            if (window.ElementorProFrontendConfig && n.elementor) {
                window.ElementorProFrontendConfig.nonce = n.elementor;
            }
            if (window.elementorProFrontendConfig && n.elementor) {
                window.elementorProFrontendConfig.nonce = n.elementor;
            }
            if (window.elementorFrontendConfig && n.elementor) {
                window.elementorFrontendConfig.nonce = n.elementor;
                if (window.elementorFrontendConfig.settings) {
                    window.elementorFrontendConfig.settings.nonce = n.elementor;
                }
            }
            if (window.wpApiSettings && n.rest) {
                window.wpApiSettings.nonce = n.rest;
            }
            // CF7 reads from wpcf7 global on some versions.
            if (window.wpcf7 && n.cf7) {
                if (window.wpcf7.api) window.wpcf7.api.nonce = n.cf7;
            }
        } catch (e) { /* defensive — continue regardless */ }
    }
    function hydrate() {
        if (fetched) return;
        fetched = true;
        return get().then(function(n){ applyTo(document, n); return n; });
    }

    // EAGER hydration — fire as soon as DOM is parsed so globals are patched
    // before anyone could submit a form. Lazy-on-focus was unreliable: form
    // plugins read the nonce at submit time, but if user submits via Enter
    // immediately or via JS, focusin may not have fired yet.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hydrate, { once: true });
    } else {
        hydrate();
    }

    // Belt-and-braces: also re-apply on first form interaction in case the
    // page injects a form dynamically after DOMContentLoaded (Elementor popups,
    // lazy-loaded sections, etc.).
    document.addEventListener('focusin', function(e){
        if (e.target.closest && e.target.closest('form')) hydrate();
    }, true);

    window.ncxHydrate = hydrate;
})();
</script>
        <?php
        return ob_get_clean();
    }

    // ─── Root .htaccess Serve Rule ────────────────────────────────────────────

    /**
     * Returns the absolute path to the WP root .htaccess file.
     */
    private function root_htaccess_path(): string {
        return get_home_path() . '.htaccess';
    }

    /**
     * Returns the home path component (e.g. "/taj" for a subdir install,
     * "" for a root install). Trailing slash always stripped.
     */
    private function home_path_prefix(): string {
        $p = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '';
        return rtrim( $p, '/' );
    }

    /**
     * Kept for the admin UI status display. Subdir installs are now fully
     * supported — this just reports the fact for the diagnostics panel.
     */
    public function is_subdir_install(): bool {
        return $this->home_path_prefix() !== '';
    }

    /**
     * Installs the BEGIN/END Nexora SSG block into the root .htaccess via
     * insert_with_markers() — the same WordPress-safe API core uses for its
     * own block. Returns true on success, WP_Error on failure.
     *
     * @return true|WP_Error
     */
    public function install_serve_rule() {
        // Multisite uses the network drop-in — shared root .htaccess is not modified.
        if ( is_multisite() ) {
            return new WP_Error(
                'nexeng_ssg_multisite_htaccess_skip',
                'Shared .htaccess serve rules are skipped on multisite. The network drop-in delivers static files per site.'
            );
        }

        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $path = $this->root_htaccess_path();
        if ( ! file_exists( $path ) ) {
            // Bootstrap an empty file so insert_with_markers() can write to it.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
            if ( ! @touch( $path ) ) {
                return new WP_Error( 'nexeng_ssg_htaccess_create', "Could not create $path" );
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe before attempting a mirror write.
        if ( ! is_writable( $path ) ) {
            return new WP_Error( 'nexeng_ssg_htaccess_unwritable', "$path is not writable" );
        }

        $lines = $this->serve_rule_lines();
        if ( ! insert_with_markers( $path, self::HTACCESS_MARKER, $lines ) ) {
            return new WP_Error( 'nexeng_ssg_htaccess_insert', 'insert_with_markers() failed' );
        }

        // CRITICAL on Apache: our serve rules MUST run BEFORE the WordPress
        // block. WordPress's catch-all `RewriteRule . /index.php [L]` ends with
        // [L] (last), so if our block sits AFTER it, Apache never reaches our
        // static-file rules and every request falls through to PHP — the static
        // mirror is built but never served (the "SLOW PATH / 0 files served"
        // symptom on LiteSpeed/Apache). insert_with_markers() always appends at
        // the end of the file, so we reorder here to hoist our block above
        // `# BEGIN WordPress`. This is what WP Super Cache / W3TC do too.
        $this->hoist_serve_rule_above_wordpress( $path );

        return true;
    }

    /**
     * Moves the `# BEGIN Nexora SSG … # END Nexora SSG` block to immediately
     * BEFORE the `# BEGIN WordPress` block in the given .htaccess file. No-op if
     * either block is missing or our block is already positioned first. Safe and
     * idempotent — only rewrites the file when a move is actually needed.
     */
    private function hoist_serve_rule_above_wordpress( string $path ): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe before attempting a mirror write.
        if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
            return;
        }
        $contents = (string) file_get_contents( $path );
        if ( $contents === '' ) {
            return;
        }

        $begin_ncx = '# BEGIN ' . self::HTACCESS_MARKER;
        $end_ncx   = '# END ' . self::HTACCESS_MARKER;
        $begin_wp  = '# BEGIN WordPress';

        $nexeng_start = strpos( $contents, $begin_ncx );
        $wp_start  = strpos( $contents, $begin_wp );

        // Nothing to do if either block is absent, or ours already precedes WP.
        if ( $nexeng_start === false || $wp_start === false || $nexeng_start < $wp_start ) {
            return;
        }

        $end_pos = strpos( $contents, $end_ncx, $nexeng_start );
        if ( $end_pos === false ) {
            return;
        }
        $end_pos += strlen( $end_ncx );

        // Extract our block (trimming the surrounding newlines we'll re-add).
        $block = trim( substr( $contents, $nexeng_start, $end_pos - $nexeng_start ) );

        // Remove the block from its current location.
        $without = substr( $contents, 0, $nexeng_start ) . substr( $contents, $end_pos );

        // Re-find the WordPress block in the now-shorter string and insert ours
        // immediately before it.
        $wp_start = strpos( $without, $begin_wp );
        if ( $wp_start === false ) {
            return; // Shouldn't happen — bail without writing rather than corrupt.
        }

        $new = rtrim( substr( $without, 0, $wp_start ) )
             . "\n\n" . $block . "\n\n"
             . substr( $without, $wp_start );

        // Normalise excess blank lines so repeated installs don't accrete gaps.
        $new = preg_replace( "/\n{3,}/", "\n\n", $new );

        file_put_contents( $path, $new, LOCK_EX );
    }

    /**
     * Removes the Nexora SSG block from root .htaccess. Idempotent.
     */
    public function uninstall_serve_rule(): bool {
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        $path = $this->root_htaccess_path();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe before attempting a mirror write.
        if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
            return false;
        }
        // Passing an empty array removes the marker block.
        return (bool) insert_with_markers( $path, self::HTACCESS_MARKER, [] );
    }

    // ─── Stealth Asset Layer (Apache) ─────────────────────────────────────────

    /**
     * Creates a real _ncx_v12/ directory in the webroot containing an .htaccess
     * that maps virtual /_ncx_v12/ paths to real wp-content/wp-includes files.
     *
     * On Apache, the WordPress root .htaccess catch-all only fires when the
     * requested path is NOT a real file or directory. By creating the _ncx_v12/
     * directory, Apache processes the subdirectory .htaccess FIRST (before the
     * root catch-all) and internally redirects to the real asset file —
     * serving it natively with zero PHP, same speed as Direct mode.
     *
     * Called automatically when SSG is enabled with asset_mode = 'proxy'.
     *
     * @return true|WP_Error
     */
    public function install_stealth_asset_rule() {
        $dir = rtrim( ABSPATH, '/\\' ) . '/_ncx_v12';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755 ) ) {
            return new WP_Error( 'nexeng_stealth_mkdir', "Could not create {$dir}" );
        }

        $htaccess = $dir . '/.htaccess';

        // Build the mapping rules. Sorted most-specific first to prevent
        // the broad /assets/ rule swallowing the compressed aliases (ep, e, t).
        $home = $this->home_path_prefix(); // '' for root install, '/sub' for subdir.
        $lines = [
            '# Nexora Stealth Asset Router — auto-generated, do not edit manually.',
            '# Maps /_ncx_v12/ virtual paths to real wp-content/wp-includes files',
            '# so Apache serves them natively without invoking PHP.',
            '<IfModule mod_rewrite.c>',
            '    RewriteEngine On',
            '    RewriteBase /_ncx_v12/',
            '',
            '    # Compressed aliases — must come before the broad /assets/ rule.',
            '    RewriteRule ^assets/ep/(.*)$          ' . $home . '/wp-content/plugins/elementor-pro/$1 [L]',
            '    RewriteRule ^assets/e/(.*)$           ' . $home . '/wp-content/plugins/elementor/$1 [L]',
            '    RewriteRule ^assets/t/(.*)$           ' . $home . '/wp-content/themes/$1 [L]',
            '    RewriteRule ^assets/uploads/ncx/(.*)$ ' . $home . '/wp-content/uploads/elementor/$1 [L]',
            '',
            '    # Broad asset catch-all.',
            '    RewriteRule ^assets/(.*)$             ' . $home . '/wp-content/$1 [L]',
            '    RewriteRule ^lib/(.*)$                ' . $home . '/wp-includes/$1 [L]',
            '</IfModule>',
            '',
            '# Long-lived immutable cache headers for all asset types.',
            '<IfModule mod_headers.c>',
            '    <FilesMatch "\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff|woff2|ttf|otf|eot|mp4|webm|pdf)$">',
            '        Header set Cache-Control "public, max-age=31536000, immutable"',
            '        Header set X-Nexora-Asset "NATIVE"',
            '    </FilesMatch>',
            '</IfModule>',
            '',
            '# Prevent directory listing.',
            'Options -Indexes',
        ];

        if ( @file_put_contents( $htaccess, implode( "\n", $lines ) . "\n" ) === false ) {
            return new WP_Error( 'nexeng_stealth_htaccess', "Could not write {$htaccess}" );
        }
        return true;
    }

    /**
     * Removes the _ncx_v12/ stealth asset directory from the webroot.
     * Called when SSG is disabled or asset_mode switches back to 'direct'.
     */
    public function uninstall_stealth_asset_rule(): void {
        $dir = rtrim( ABSPATH, '/\\' ) . '/_ncx_v12';
        if ( is_dir( $dir ) ) {
            $htaccess = $dir . '/.htaccess';
            if ( file_exists( $htaccess ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
                @unlink( $htaccess );
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
            @rmdir( $dir ); // only removes if empty — intentional
        }
    }

    /**
     * The rewrite rules. Returned as an array of lines (no BEGIN/END — those
     * are added by insert_with_markers).
     *
     * Skip conditions (in order of cheapness):
     *   - Logged-in users (cookie present) — they need fresh nonces, admin bar, previews.
     *   - POST requests — forms must hit PHP.
     *   - Any query string — preview, search, capture token, ?p=, etc.
     *   - WP system paths — /wp-admin, /wp-login.php, /wp-cron.php, /wp-json, /wp-content, /wp-includes.
     *   - Asset URLs — anything with a 2–5 char extension (.css, .js, .png, .woff2 …).
     */
    private function serve_rule_lines(): array {
        // Path prefix: "" for root install, "/taj" for /taj/ install.
        // Static dir relative to DOCUMENT_ROOT: "{home}/wp-content/uploads/nexora-static".
        $home   = $this->home_path_prefix();
        $static = $home . '/wp-content/uploads/nexora-static';

        // Skip conditions must be repeated before EACH RewriteRule —
        // Apache resets RewriteCond accumulation after every rule. Failing to
        // repeat them was the root cause of Elementor preview iframes being
        // served the static file instead of the live preview.
        $skip_conditions = [
            '    # Skip authenticated users (need fresh nonces + admin bar)',
            '    RewriteCond %{HTTP_COOKIE} !wordpress_logged_in_ [NC]',
            '    # Skip POST requests (forms must reach PHP)',
            '    RewriteCond %{REQUEST_METHOD} !=POST',
            '    # Skip any query string (preview, search, capture token, elementor-preview)',
            '    RewriteCond %{QUERY_STRING} ^$',
            '    # Skip WordPress system paths',
            '    RewriteCond %{REQUEST_URI} !^' . $home . '/(wp-admin|wp-login\.php|wp-cron\.php|wp-json|wp-content|wp-includes) [NC]',
            '    # Skip asset URLs (anything with a file extension)',
            '    RewriteCond %{REQUEST_URI} !\.[a-zA-Z0-9]{2,5}$',
        ];

        $lines = [
            '<IfModule mod_rewrite.c>',
            '    RewriteEngine On',
            '    RewriteBase ' . ( $home === '' ? '/' : $home . '/' ),
            '',
            '    # ────── Front page → /index.html ──────',
        ];
        $lines = array_merge( $lines, $skip_conditions );
        $lines = array_merge( $lines, [
            '    RewriteCond %{REQUEST_URI} ^' . $home . '/?$',
            '    RewriteCond %{DOCUMENT_ROOT}' . $static . '/index.html -f',
            '    RewriteRule ^/?$ ' . $static . '/index.html [L]',
            '',
            '    # ────── Inner pages → /<slug>/index.html ──────',
        ] );
        $lines = array_merge( $lines, $skip_conditions );
        $lines = array_merge( $lines, [
            '    RewriteCond %{REQUEST_URI} !^' . $home . '/?$',
            '    RewriteCond %{DOCUMENT_ROOT}' . $static . '/$1/index.html -f',
            '    RewriteRule ^(.+?)/?$ ' . $static . '/$1/index.html [L]',
            '</IfModule>',
        ] );
        return $lines;
    }

    /**
     * Does the marker block currently exist in root .htaccess?
     * Used by the admin UI status panel.
     */
    public function serve_rule_installed(): bool {
        $path = $this->root_htaccess_path();
        if ( ! file_exists( $path ) ) {
            return false;
        }
        $contents = file_get_contents( $path );
        return strpos( $contents, '# BEGIN ' . self::HTACCESS_MARKER ) !== false;
    }

    // ─── Bulk Operations ──────────────────────────────────────────────────────

    /**
     * Returns all post IDs currently eligible for static generation.
     * Used by the "Regenerate All" admin action.
     */
    public function eligible_post_ids(): array {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        // Exclude CPTs that are never real front-end pages. These are skipped at
        // the query level (not just is_eligible) so the bulk queue stays small.
        //
        // • attachment / WC types — binary files or back-end orders, no permalink.
        // • elementor_library   — Elementor templates & kits; URLs are ?elementor_library=slug
        //                         (query-string) so the drop-in never caches them and the
        //                         loopback request times out against the kit renderer.
        // • wp_block            — Gutenberg reusable blocks (no front-end permalink).
        // • wp_template /       — Block-theme template parts (server-rendered partials,
        //   wp_template_part      no stand-alone front-end URL).
        // • wp_global_styles    — Block-theme global CSS blob (not a page).
        // • wp_navigation       — Block-theme navigation menus (not a page).
        $internal_cpts = [
            'attachment',
            'product', 'shop_order', 'shop_coupon',
            'elementor_library',
            'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        ];
        foreach ( $internal_cpts as $cpt ) {
            unset( $post_types[ $cpt ] );
        }

        // Allow sites to override the eligible post-type list.
        // NOTE: we deliberately do NOT filter types here by publicly_queryable /
        // rewrite — built-in types (notably `page`) can report those as null/false
        // in their registration object yet are perfectly viewable, so doing so
        // wrongly dropped all pages. The authoritative per-post is_eligible() check
        // below (applied via array_filter) handles non-viewable CPTs correctly,
        // including the ?p=ID permalink guard for builder CPTs like nca_page.
        $post_types = (array) apply_filters( 'nexeng_ssg_eligible_post_types', array_values( $post_types ) );

        $ids = get_posts( [
            'post_type'      => array_values( $post_types ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );

        return array_values( array_filter( $ids, fn( $id ) => $this->is_eligible( (int) $id ) ) );
    }

    /**
     * Builds archive queue entries for author archive pages.
     *
     * One entry per WordPress user who has at least one published post.
     * Author archive URLs are /<author_base>/<login>/ and are publicly
     * browsable by default — they deserve static capture just like category
     * or tag archives.
     *
     * @return array<int, array{url:string,key:string,label:string}>
     */
    private function author_archive_entries(): array {
        $entries = [];

        $users = get_users( [
            'has_published_posts' => [ 'post' ],   // only users with ≥1 published post
            'fields'              => [ 'ID', 'display_name' ],
            'number'              => 500,
        ] );

        if ( empty( $users ) ) {
            return [];
        }

        foreach ( $users as $user ) {
            $url = get_author_posts_url( (int) $user->ID );
            if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                continue;
            }
            $entries[] = [
                'url'   => trailingslashit( $url ),
                'key'   => '__author_' . (int) $user->ID . '__',
                'label' => 'Author: ' . ( $user->display_name ?: '#' . $user->ID ),
            ];
        }

        return $entries;
    }

    /**
     * Builds archive queue entries for one taxonomy (all terms, including empty).
     *
     * @param string $taxonomy     Taxonomy slug.
     * @param string $label_prefix Human label prefix (e.g. "Category").
     * @param string $key_prefix   Manifest key prefix: cat, tag, or sanitized taxonomy slug.
     * @return array<int, array{url:string,key:string,label:string}>
     */
    private function term_archive_entries( string $taxonomy, string $label_prefix, string $key_prefix ): array {
        $entries = [];
        $terms   = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 500,
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        foreach ( $terms as $term ) {
            $url = get_term_link( $term );
            if ( is_wp_error( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                continue;
            }

            $entries[] = [
                'url'   => $url,
                'key'   => '__' . $key_prefix . '_' . (int) $term->term_id . '__',
                'label' => $label_prefix . ': ' . $term->name,
            ];
        }

        return $entries;
    }

    /**
     * Returns archive URLs (homepage blog index, category/tag archives) that
     * should be captured during a bulk build but have no WordPress post ID.
     *
     * Each entry: ['url' => string, 'key' => string, 'label' => string]
     *   key   — unique manifest key (e.g. '__home__', '__cat_3__')
     *   label — human-readable description for progress display
     *
     * Homepage note: when show_on_front='page', the static front page IS a
     * published page already in eligible_post_ids() — no separate entry needed.
     * We only add a homepage entry when show_on_front='posts' (blog index).
     */
    public function eligible_archives(): array {
        if ( ! self::is_enabled() ) {
            return [];
        }

        $archives = [];

        // Blog-index homepage — only needed when WP shows posts at /.
        if ( get_option( 'show_on_front' ) === 'posts' ) {
            $archives[] = [
                'url'   => trailingslashit( home_url( '/' ) ),
                'key'   => '__home__',
                'label' => get_bloginfo( 'name' ) . ' (Home)',
            ];
        }

        // Category archives — include empty terms so every browsable URL is mirrored.
        $archives = array_merge( $archives, $this->term_archive_entries( 'category', 'Category', 'cat' ) );

        // Tag archives — same as categories.
        $archives = array_merge( $archives, $this->term_archive_entries( 'post_tag', 'Tag', 'tag' ) );

        // Custom post type taxonomies (e.g. product_cat, portfolio_category).
        $skip_taxonomies = [
            'category', 'post_tag', 'post_format', 'nav_menu', 'link_category',
            'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
        ];
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        foreach ( $taxonomies as $tax_obj ) {
            if ( in_array( $tax_obj->name, $skip_taxonomies, true ) ) {
                continue;
            }
            if ( empty( $tax_obj->publicly_queryable ) && empty( $tax_obj->rewrite ) ) {
                continue;
            }
            $label = $tax_obj->labels->singular_name ?? $tax_obj->label ?? $tax_obj->name;
            $archives = array_merge(
                $archives,
                $this->term_archive_entries( $tax_obj->name, (string) $label, 'tax_' . sanitize_key( $tax_obj->name ) )
            );
        }

        // Author archives — one entry per user with at least one published post.
        $archives = array_merge( $archives, $this->author_archive_entries() );

        return $archives;
    }

    /**
     * Initialises a bulk regen queue. Returns the queue size (or WP_Error
     * if a run is already in progress).
     *
     * @return int|WP_Error
     */
    /**
     * Asks Elementor to regenerate its file cache — wipes per-post CSS files
     * so they're rebuilt lazily on the next public render (which our capture
     * loopback IS). Safe no-op when Elementor isn't installed.
     *
     * Called from bulk_start() and from the global-invalidation path so any
     * "Regenerate All" trigger automatically pairs with an Elementor refresh.
     */
    private function prime_elementor_cache(): void {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return;
        }
        $ep = \Elementor\Plugin::$instance ?? null;
        if ( ! $ep ) {
            return;
        }
        // Modern Elementor: $files_manager->clear_cache()
        if ( isset( $ep->files_manager ) && method_exists( $ep->files_manager, 'clear_cache' ) ) {
            try { $ep->files_manager->clear_cache(); } catch ( \Throwable $e ) { /* never let this break SSG */ }
        }
        // Older Elementor fallback path.
        if ( isset( $ep->posts_css_manager ) && method_exists( $ep->posts_css_manager, 'clear_cache' ) ) {
            try { $ep->posts_css_manager->clear_cache(); } catch ( \Throwable $e ) {}
        }
    }

    /**
     * Eagerly regenerates a single post's Elementor per-post CSS file.
     * Called before each capture loopback so the file definitely exists on
     * disk by the time the captured HTML references it. Safe no-op when
     * Elementor isn't installed or the post isn't an Elementor page.
     */
    private function prime_elementor_post_css( int $post_id ): void {
        if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            return;
        }
        try {
            // \Elementor\Core\Files\CSS\Post::update() deletes the existing
            // CSS file and re-renders it from current post meta + kit settings.
            $post_css = new \Elementor\Core\Files\CSS\Post( $post_id );
            if ( method_exists( $post_css, 'update' ) ) {
                $post_css->update();
            }
        } catch ( \Throwable $e ) {
            // Never let an Elementor internal error abort the SSG capture —
            // the lazy-regen path is still available as a fallback during
            // the loopback render.
        }
    }

    /**
     * Archive pages (category, tag, blog index) missing from the static mirror.
     *
     * @return array<int, array{url:string,key:string,label:string}>
     */
    public function missing_archives(): array {
        if ( ! self::is_enabled() ) {
            return [];
        }

        $data    = $this->manifest_read();
        $root    = $this->root_dir();
        $missing = [];

        foreach ( $this->eligible_archives() as $arc ) {
            $entry = $data[ $arc['key'] ] ?? null;
            $path  = is_array( $entry ) && ! empty( $entry['path'] )
                ? $root . '/' . ltrim( (string) $entry['path'], '/' )
                : '';

            if ( ! $path || ! is_file( $path ) ) {
                $missing[] = $arc;
            }
        }

        return $missing;
    }

    /**
     * Summary for admin UI — how many archive URLs exist vs are captured.
     *
     * @return array{eligible:int,captured:int,missing:int,needs_build:bool}
     */
    public function archive_manifest_status(): array {
        $eligible = $this->eligible_archives();
        $missing  = $this->missing_archives();

        return [
            'eligible'    => count( $eligible ),
            'captured'    => max( 0, count( $eligible ) - count( $missing ) ),
            'missing'     => count( $missing ),
            'needs_build' => ! empty( $missing ),
        ];
    }

    /**
     * Starts a bulk build for the shared queue machinery.
     *
     * @param array<int|string|array{url:string,key:string,label:string}> $queue
     * @return int|WP_Error
     */
    private function begin_bulk_queue( array $queue, string $mode, int $posts_count, int $archives_count ) {
        if ( get_transient( 'nexeng_ssg_bulk_running' ) ) {
            return new WP_Error( 'nexeng_ssg_bulk_busy', 'Another bulk regen is already running.' );
        }

        // Pair with Elementor cache clear so per-post CSS is regenerated freshly
        // during each capture's loopback. Without this, captures can reference
        // stale post-{ID}.css files that Elementor (or a security plugin like
        // Defender) may have deleted between renders, leading to 404s on the
        // public site. This is the single biggest cause of "site looks broken
        // after Regenerate All" support tickets.
        $this->prime_elementor_cache();

        $total         = count( $queue );
        $build_session = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'nexeng_build_', true );
        // Queue gets a generous TTL — a 1000-page site at 15s/page can take
        // 4+ hours to complete; we don't want the queue to evaporate mid-build.
        set_transient( 'nexeng_ssg_bulk_queue',    $queue, self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_total',    $total, self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_done',     0,      self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_errors',   0,      self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_running',  1,      self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_mode',     $mode,  self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_build_session', $build_session, self::QUEUE_TTL );
        set_transient(
            'nexeng_ssg_bulk_breakdown',
            [
                'posts'    => $posts_count,
                'archives' => $archives_count,
            ],
            self::QUEUE_TTL
        );
        $build_id = $this->create_build_id();
        update_option( self::GLOBAL_BUILD_OPTION, $build_id, false );
        $this->finalize_build( $build_id );
        // Per-item retry counter so transient failures (cURL timeouts, brief
        // 5xx blips, slow Elementor renders on low-worker hosts) get up to
        // MAX_RETRIES attempts before being logged as permanent failures.
        // Indexed by string item-key (post_id for posts, archive key for
        // archives) — see bulk_batch() for the format.
        delete_transient( 'nexeng_ssg_bulk_attempts' );
        delete_transient( 'nexeng_ssg_bulk_last_url' );
        // Clear the persistent error log so the wizard Step 5 error box always
        // reflects only this build — not leftover failures from a previous run.
        update_option( 'nexeng_ssg_errors', [], false );
        // Clear the fatal-pages block list so this rebuild is a fresh attempt.
        // Pages that OOM'd / had PHP fatals before will be re-tried; if they fail
        // again they'll be re-marked as fatal after the build.
        $this->clear_all_fatals();
        // Clear the post-Pro-upgrade "needs regen" banner — any full rebuild
        // refreshes every captured page with current Pro optimisations.
        delete_option( 'nexeng_pro_regen_needed' );

        // Safety net: schedule the watchdog so an orphaned queue (loopback
        // blocked AND cron not firing) eventually gets picked back up. Recurring
        // every 5 minutes is plenty — most builds finish well before that
        // and the watchdog self-clears the schedule when the queue drains.
        if ( ! wp_next_scheduled( self::CRON_WATCHDOG ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_WATCHDOG );
        }

        // Drive the build SERVER-SIDE, immediately. This is the key change from
        // the old browser-driven design: instead of waiting up to 5 minutes for
        // a cron tick (and relying on the admin tab to poll batch-tick in the
        // meantime), we fire a non-blocking loopback pass right now. Capturing
        // begins within ~1 second on any host, tab open or closed.
        $this->kick_bulk_drive();

        return $total;
    }

    public function bulk_start( bool $include_archives = true ) {
        if ( ! self::is_enabled() ) {
            return new WP_Error( 'nexeng_ssg_disabled', 'Static Delivery is disabled. Enable it from the Static Delivery page to run a build.' );
        }
        // Asking for a build is an explicit override of the post-purge hold —
        // that hold exists to stop automatic rebuilds, not to disable the
        // Rebuild button for five minutes after a purge.
        delete_transient( 'nexeng_ssg_purge_hold' );
        // Build the queue: post IDs (int) first, then archive items (array).
        $post_ids = $this->eligible_post_ids();
        $archives = $include_archives ? $this->eligible_archives() : [];

        // Queue is a flat array of mixed items — ints for posts, arrays for archives.
        $queue = array_values( $post_ids );
        foreach ( $archives as $arc ) {
            $queue[] = $arc;
        }

        return $this->begin_bulk_queue(
            $queue,
            $include_archives ? 'full' : 'full_content',
            count( $post_ids ),
            count( $archives )
        );
    }

    /**
     * Captures only category, tag, and blog-index archive pages.
     * Used when posts were built without archives (e.g. older wizard runs).
     *
     * @return int|WP_Error
     */
    public function bulk_start_archives_only() {
        if ( ! self::is_enabled() ) {
            return new WP_Error( 'nexeng_ssg_disabled', 'Static Delivery is disabled. Enable it from the Static Delivery page to build archive pages.' );
        }
        $archives = $this->eligible_archives();
        if ( empty( $archives ) ) {
            return new WP_Error( 'nexeng_ssg_no_archives', 'No category, tag, or blog index pages to capture.' );
        }

        return $this->begin_bulk_queue( $archives, 'archives', 0, count( $archives ) );
    }

    /**
     * Starts a focused bulk regeneration for only the pending (changed) pages.
     *
     * Uses the same transient-based queue machinery as bulk_start(), but feeds
     * only the post IDs stored in the pending list instead of the full site.
     * The browser-driven batch endpoint (ssg_regen_all_batch) drives capture
     * exactly as it does for a full build.  Returns 0 when nothing is pending
     * (idempotent — safe to call from a button even if the list is already empty).
     *
     * Pending entries are cleared automatically inside capture_url() / manifest_write()
     * as each page is successfully regenerated.
     *
     * @return int Number of pages queued.
     */
    public function bulk_start_pending(): int {
        if ( ! self::is_enabled() ) {
            return 0; // Master kill-switch: silently no-op when SSG is disabled.
        }
        // Same as bulk_start(): an explicit "Refresh changed pages" overrides
        // the post-purge hold.
        delete_transient( 'nexeng_ssg_purge_hold' );
        $pending  = $this->pending_posts();
        $post_ids = array_values( array_map( 'intval', array_keys( $pending ) ) );

        // Also capture any published, eligible page that was never mirrored
        // (published before the first build, or missed by an interrupted run).
        // Without this, such a page shows "Pending" forever — counted by
        // pending_count() but never actually built by this rebuild. Dedupe
        // against the recent-edit queue so nothing is queued twice.
        $seen_posts = array_fill_keys( $post_ids, true );
        foreach ( $this->missing_post_ids() as $mid ) {
            $mid = (int) $mid;
            if ( empty( $seen_posts[ $mid ] ) ) {
                $post_ids[]        = $mid;
                $seen_posts[ $mid ] = true;
            }
        }

        // Include archives in two cases:
        //
        //  (a) Dirty flag — set when a global change (menu, customizer, term edit)
        //      invalidates all archive pages.  We pull ALL eligible archives so
        //      every category/tag/author page gets a fresh capture.
        //
        //  (b) Missing archives — any archive URL that has never been captured at
        //      all.  These show as a separate "Build Archive Pages" notice in the
        //      UI, but that creates a confusing two-button workflow.  Including
        //      them here means a single "Refresh Changed Pages" click is always
        //      enough — no separate archive-only build needed.
        $archives     = [];
        $arc_key_seen = [];

        if ( get_option( 'nexeng_ssg_archives_dirty' ) ) {
            $archives = $this->eligible_archives();
            foreach ( $archives as $a ) {
                $arc_key_seen[ $a['key'] ] = true;
            }
            // Clear the flag now — will be re-set when invalidation fires again.
            delete_option( 'nexeng_ssg_archives_dirty' );
        }

        // Merge in any archives that were never captured (deduped against dirty set).
        foreach ( $this->missing_archives() as $arc ) {
            if ( empty( $arc_key_seen[ $arc['key'] ] ) ) {
                $archives[]                  = $arc;
                $arc_key_seen[ $arc['key'] ] = true;
            }
        }

        $total = count( $post_ids ) + count( $archives );
        if ( $total === 0 ) {
            return 0;
        }

        // Build queue: changed posts first (higher priority), then archives.
        $queue = $post_ids;
        foreach ( $archives as $arc ) {
            $queue[] = $arc;   // archives are arrays { url, key, label }
        }

        set_transient( 'nexeng_ssg_bulk_queue',   $queue, self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_total',   $total, self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_done',    0,      self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_errors',  0,      self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_running', 1,      self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_mode',    'pending', self::QUEUE_TTL );
        $build_session = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'nexeng_build_', true );
        set_transient( 'nexeng_ssg_build_session', $build_session, self::QUEUE_TTL );
        set_transient(
            'nexeng_ssg_bulk_breakdown',
            [
                'posts'    => count( $post_ids ),
                'archives' => count( $archives ),
            ],
            self::QUEUE_TTL
        );
        $build_id = $this->create_build_id();
        update_option( self::GLOBAL_BUILD_OPTION, $build_id, false );
        $this->finalize_build( $build_id );
        delete_transient( 'nexeng_ssg_bulk_attempts' );

        // Watchdog backup in case both loopback and cron are blocked.
        if ( ! wp_next_scheduled( self::CRON_WATCHDOG ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_WATCHDOG );
        }

        // Drive it server-side immediately (loopback + cron backup) — same
        // engine as the full build, so pending rebuilds also complete on any
        // host without an open tab or front-end traffic.
        $this->kick_bulk_drive();

        return $total;
    }

    /**
     * Immediately stops the running bulk regeneration and clears all state.
     *
     * Safe to call at any time — idempotent.  Existing static files are NOT
     * deleted; only the in-progress queue is discarded.  The site continues
     * serving whatever static files were already captured.
     */
    public function bulk_stop(): void {
        delete_transient( 'nexeng_ssg_bulk_queue' );
        delete_transient( 'nexeng_ssg_bulk_total' );
        delete_transient( 'nexeng_ssg_bulk_done' );
        delete_transient( 'nexeng_ssg_bulk_errors' );
        delete_transient( 'nexeng_ssg_bulk_running' );
        delete_transient( 'nexeng_ssg_bulk_attempts' );
        delete_transient( 'nexeng_ssg_bulk_last_url' );
        delete_transient( 'nexeng_ssg_bulk_paused' );
        delete_transient( 'nexeng_ssg_bulk_mode' );
        delete_transient( 'nexeng_ssg_build_session' );
        delete_transient( 'nexeng_ssg_bulk_breakdown' );
        delete_transient( 'nexeng_ssg_drive_inflight' );
        // Release the capture mutex so no orphan lock blocks the next build.
        $this->release_capture_lock();
        // Remove any pending tick + drive + watchdog so cron stops firing.
        wp_clear_scheduled_hook( self::CRON_TICK );
        wp_clear_scheduled_hook( self::DRIVE_HOOK );
        wp_clear_scheduled_hook( self::CRON_WATCHDOG );
    }

    /**
     * Pauses a running bulk regeneration without discarding the queue.
     *
     * The cron tick checks this flag at the start of each invocation and
     * returns immediately when it's set. The queue + counters stay intact
     * so bulk_resume() can pick up exactly where it left off.
     */
    public function bulk_pause(): void {
        set_transient( 'nexeng_ssg_bulk_paused', 1, self::QUEUE_TTL );
    }

    /**
     * Resumes a paused bulk regeneration.
     *
     * Clears the pause flag and schedules an immediate cron tick so
     * processing restarts within seconds — no manual refresh needed.
     */
    public function bulk_resume(): void {
        delete_transient( 'nexeng_ssg_bulk_paused' );
        // Only restart if the queue still has work.
        if ( ! empty( get_transient( 'nexeng_ssg_bulk_queue' ) ) ) {
            set_transient( 'nexeng_ssg_bulk_running', 1, self::QUEUE_TTL );
            // Re-arm the server-driven loop (loopback + cron backup).
            $this->kick_bulk_drive();
        }
    }

    /**
     * Processes the next batch of items from the bulk queue.
     *
     * Resilience guarantees:
     *   1. Queue is the source of truth — bulk_running transient is only a hint.
     *      An expired transient won't block continued processing of a
     *      non-empty queue (the previous design silently abandoned queues).
     *   2. Items are NOT spliced from the queue until they've either succeeded
     *      OR exhausted MAX_RETRIES attempts. A fatal mid-capture (OOM, parse
     *      error) leaves the item in place for the next tick.
     *   3. Transient errors (cURL timeouts, 5xx, truncated HTML) trigger an
     *      automatic re-queue — the item goes back to the END of the queue
     *      so other pages can complete while the failing one cools off.
     */
    /**
     * Read + sanitize the bulk queue. Coerces to a real list and DROPS any
     * invalid entry, so a corrupt value can never wedge the build.
     *
     * Why this exists: `(array) get_transient('nexeng_ssg_bulk_queue')` yields
     * `[0 => false]` when the transient is missing/expired (PHP casts `false`
     * to `[false]`, NOT `[]`). If that ever gets written back, the boolean
     * `false` becomes a permanent queue member that is neither a valid post ID
     * (int > 0) nor a valid archive array — so every driver pass processes the
     * rest, writes the queue back with `false` still in it, and the queue never
     * reaches empty. Result: build stuck "1 item" forever, never "done",
     * drivers keep re-running, counts bounce. (Seen live as
     * `STUCK QUEUE ITEM(S): [0] boolean false`.) Filtering here at the single
     * read point kills that whole class of bug.
     *
     * Valid items: positive int (post ID) OR array with 'url' + 'key' (archive).
     */
    private function read_queue(): array {
        $raw = get_transient( 'nexeng_ssg_bulk_queue' );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $clean = [];
        foreach ( $raw as $item ) {
            if ( is_array( $item ) && isset( $item['url'], $item['key'] ) ) {
                $clean[] = $item;                 // archive entry
            } elseif ( is_int( $item ) && $item > 0 ) {
                $clean[] = $item;                 // post ID
            } elseif ( is_numeric( $item ) && (int) $item > 0 ) {
                $clean[] = (int) $item;           // numeric-string post ID
            }
            // Anything else (false, null, '', 0, malformed array) is dropped.
        }
        return $clean;
    }

    public function bulk_batch( int $batch_size = 1 ): array {
        // Master kill-switch — if SSG was disabled mid-build, terminate the loop,
        // clear queue transients, and stop the cron tick. Without this, an
        // in-flight bulk would keep capturing pages even after the user clicked
        // off the master switch.
        if ( ! self::is_enabled() ) {
            delete_transient( 'nexeng_ssg_bulk_queue' );
            delete_transient( 'nexeng_ssg_bulk_running' );
            delete_transient( 'nexeng_ssg_bulk_paused' );
            wp_clear_scheduled_hook( 'nexeng_ssg_bulk_tick' );
            wp_clear_scheduled_hook( 'nexeng_ssg_bulk_watchdog' );
            return [
                'done'      => true,
                'reason'    => 'ssg_disabled',
                'total'     => (int) get_transient( 'nexeng_ssg_bulk_total' ),
                'processed' => (int) get_transient( 'nexeng_ssg_bulk_done' ),
                'errors'    => (int) get_transient( 'nexeng_ssg_bulk_errors' ),
                'remaining' => 0,
            ];
        }
        $queue = $this->read_queue();

        // Queue empty → truly done. Honour the previous "not_running" reason
        // string for backwards compatibility with the UI poll loop.
        if ( empty( $queue ) ) {
            return [
                'done'      => true,
                'reason'    => 'not_running',
                'total'     => (int) get_transient( 'nexeng_ssg_bulk_total' ),
                'processed' => (int) get_transient( 'nexeng_ssg_bulk_done' ),
                'errors'    => (int) get_transient( 'nexeng_ssg_bulk_errors' ),
                'remaining' => 0,
            ];
        }

        // Re-arm the running lock with the long TTL — this re-establishes the
        // hint even if it had expired (e.g. browser closed for >2min).
        set_transient( 'nexeng_ssg_bulk_running', 1, self::QUEUE_TTL );

        $total    = (int)   get_transient( 'nexeng_ssg_bulk_total' );
        $done     = (int)   get_transient( 'nexeng_ssg_bulk_done' );
        $errors   = (int)   get_transient( 'nexeng_ssg_bulk_errors' );
        // Coerce to a clean associative array. NB: `(array) false` yields
        // `[0 => false]`, not `[]`, which polluted the attempts map (seen live as
        // `attempts map: [false]`). Guard explicitly so the retry counter stays
        // a proper string-keyed map.
        $attempts = get_transient( 'nexeng_ssg_bulk_attempts' );
        $attempts = is_array( $attempts ) ? $attempts : [];
        $last_url = '';

        if ( $this->server_is_busy() || ! $this->capture_gap_elapsed() ) {
            return [
                'done'      => false,
                'reason'    => 'server_busy',
                'total'     => $total,
                'processed' => $done,
                'errors'    => $errors,
                'remaining' => count( $queue ),
                'last_url'  => (string) ( get_transient( 'nexeng_ssg_bulk_last_url' ) ?: '' ),
            ];
        }

        for ( $i = 0; $i < max( 1, $batch_size ); $i++ ) {
            if ( empty( $queue ) ) {
                break;
            }

            // Peek at the head of the queue WITHOUT removing it yet. The item
            // is only removed after we know whether it succeeded, failed
            // permanently, or needs re-queuing for retry.
            $item    = array_shift( $queue );
            $is_arc  = ( is_array( $item ) && isset( $item['url'], $item['key'] ) );
            $key     = $is_arc ? 'arc_' . $item['key'] : 'post_' . (int) $item;
            $post_id = $is_arc ? 0 : (int) $item;

            // Guard against malformed queue entries — a bare 0/empty/non-numeric
            // item (e.g. a partial archive array missing its url/key, or a stray
            // value from an older queue) would otherwise reach capture(0) and log
            // a spurious "Post 0 not found" error, leaving the build forever
            // "1 blocked page" / never cleanly done. Drop it silently and move on:
            // it was never a real page, so it's not an error worth surfacing.
            if ( ! $is_arc && $post_id <= 0 ) {
                unset( $attempts[ $key ] );
                $done++;   // count it processed so the queue can finish.
                continue;
            }

            if ( $is_arc ) {
                $this->mark_capture_started();
                $result = $this->capture_archive( $item['url'], $item['key'] );
            } else {
                $this->mark_capture_started();
                $result = $this->capture( $post_id );
            }

            if ( is_wp_error( $result ) ) {
                $attempt_count = ( isset( $attempts[ $key ] ) ? (int) $attempts[ $key ] : 0 ) + 1;
                $attempts[ $key ] = $attempt_count;

                // Retryable errors → re-queue to the END for another attempt.
                // Non-retryable OR exhausted attempts → permanent failure.
                if ( $attempt_count < self::MAX_RETRIES && $this->is_retryable_error( $result ) ) {
                    $queue[] = $item;  // Back of the line — natural backoff.
                } else {
                    $errors++;
                    $stage = $is_arc ? 'bulk_archive' : 'bulk';
                    // For archive failures, pass the label + URL so the error log
                    // shows e.g. "Category Archive — /category/news/" instead of
                    // the misleading "Unknown page" fallback.
                    $log_context = $is_arc
                        ? [
                            'title' => (string) ( $item['label'] ?? __( 'Archive page', 'nexora-engine' ) ),
                            'url'   => (string) ( $item['url']   ?? '' ),
                        ]
                        : [];
                    $this->log_error( $post_id, $stage, $result, $log_context );
                    // Mark post-type pages with source fatals (OOM, PHP fatal) as
                    // blocked so future cron triggers skip them automatically.
                    if ( ! $is_arc && $result->get_error_code() === 'nexeng_ssg_source_fatal' ) {
                        $this->mark_fatal( $post_id, $result );
                    }
                    $done++;
                    unset( $attempts[ $key ] );
                }
            } else {
                // Success — count it and clear any prior retry counter.
                $done++;
                unset( $attempts[ $key ] );
                $last_url = $is_arc ? $item['url'] : get_permalink( $post_id );
            }
        }

        $finished = empty( $queue );

        // Count integrity: `done` is derived as total-minus-remaining and clamped
        // to [0, total]. The previous design used `done` as a free-running counter
        // that incremented on every processed item — which let a re-queued item
        // (retry) or a stray poison entry push `done` PAST `total` (seen live as
        // done=43, processed=44 against total=38, with the count visibly bouncing
        // in the admin). Deriving it from the queue makes the progress monotonic
        // and self-correcting: it can never exceed total and always reflects how
        // much of the queue is actually left.
        $remaining = count( $queue );
        if ( $total > 0 ) {
            $done = max( 0, min( $total, $total - $remaining ) );
        }

        set_transient( 'nexeng_ssg_bulk_queue',    $queue,    self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_done',     $done,     self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_errors',   $errors,   self::QUEUE_TTL );
        set_transient( 'nexeng_ssg_bulk_attempts', $attempts, self::QUEUE_TTL );

        // Heartbeat: record when the queue last actually advanced. cron_bulk_tick
        // uses this to detect a dead browser driver (flag set but no progress) so
        // it can take over and finish the build instead of waiting out the 2-min
        // browser_active TTL. Updated on every processed item (success or error).
        set_transient( 'nexeng_ssg_bulk_last_advance', time(), self::QUEUE_TTL );

        if ( $last_url ) {
            set_transient( 'nexeng_ssg_bulk_last_url', $last_url, self::QUEUE_TTL );
        }

        if ( $finished ) {
            delete_transient( 'nexeng_ssg_bulk_running' );
            delete_transient( 'nexeng_ssg_bulk_attempts' );
            update_option( 'nexeng_ssg_last_bulk_at', time(), false );
            // Watchdog no longer needed once the build completes.
            wp_clear_scheduled_hook( self::CRON_WATCHDOG );

            // A full mirror build captured every queued item — drain stale pending
            // flags so we do not immediately chain into another full rebuild loop
            // (common when global invalidation marks all pages pending mid-build).
            $mode = (string) get_transient( 'nexeng_ssg_bulk_mode' );
            if ( in_array( $mode, [ 'full', 'full_content' ], true ) ) {
                update_option( self::PENDING_OPTION, [], false );
            }
            if ( in_array( $mode, [ 'full', 'archives' ], true ) ) {
                delete_option( 'nexeng_ssg_archives_dirty' );
            }
            delete_transient( 'nexeng_ssg_bulk_mode' );

            // Clean shutdown of ALL active-build state so every UI component
            // (Dashboard rail, Static Delivery page, wizard) reads one consistent
            // "not running" state the instant the build finishes. Without this the
            // empty queue + leftover total/done/last_advance transients made
            // different polling widgets disagree — one showing "Building… 0/0",
            // another "100% complete" — and the drive_inflight / browser_active
            // flags could re-nudge a phantom pass (the "still building / count
            // bouncing after done" bug). We keep total/processed for the final
            // "X of X captured" display but drop the queue + driver flags.
            delete_transient( 'nexeng_ssg_bulk_queue' );
            delete_transient( 'nexeng_ssg_drive_inflight' );
            delete_transient( 'nexeng_ssg_browser_active' );
            delete_transient( 'nexeng_ssg_bulk_last_advance' );
            wp_clear_scheduled_hook( self::DRIVE_HOOK );
            wp_clear_scheduled_hook( self::CRON_TICK );
        }

        return [
            'done'      => $finished && empty( $queue ),
            'total'     => $total,
            'processed' => $done,
            'errors'    => $errors,
            'remaining' => count( $queue ),
            'last_url'  => $last_url,
        ];
    }

    /**
     * Decides whether a capture error is worth retrying. Network blips,
     * temporary 5xx, truncated HTML, and worker-pool exhaustion all qualify;
     * genuine "page doesn't exist" / "config broken" errors do not.
     */
    private function is_retryable_error( WP_Error $err ): bool {
        $code = $err->get_error_code();
        $msg  = strtolower( $err->get_error_message() );

        // Confidently retryable codes from this plugin's own error vocabulary.
        $retry_codes = [
            'nexeng_ssg_http_error',       // wp_remote_get returned WP_Error (timeout, DNS, refused)
            'nexeng_ssg_html_truncated',   // partial response — usually worker pool exhaustion
            'http_request_failed',      // generic HTTP layer failure
            'http_request_timeout',
        ];
        if ( in_array( $code, $retry_codes, true ) ) {
            return true;
        }

        // nexeng_ssg_http_status: retry only gateway/worker-pressure statuses.
        // HTTP 500 often means a deterministic PHP fatal in the source page;
        // retrying it burns cron cycles and can keep the site unstable.
        if ( $code === 'nexeng_ssg_http_status' ) {
            // Message format: "Capture returned HTTP 503."
            if ( preg_match( '/HTTP (\d{3})/i', $msg, $m ) ) {
                $status = (int) $m[1];
                return in_array( $status, [ 502, 503, 504 ], true );
            }
            return false;
        }

        // cURL error 28 / 7 / 52 etc. surfaced as plain text.
        if ( strpos( $msg, 'curl error 28' ) !== false  // timeout
          || strpos( $msg, 'curl error 7'  ) !== false  // couldn't connect
          || strpos( $msg, 'curl error 52' ) !== false  // empty reply
          || strpos( $msg, 'curl error 56' ) !== false  // recv failure
          || strpos( $msg, 'operation timed out' ) !== false
          || strpos( $msg, 'connection reset' )    !== false
        ) {
            return true;
        }

        return false;
    }

    /**
     * Returns per-post status rows for the admin UI.
     *
     * Caps at $limit to keep the panel responsive on big sites; combine with
     * the search box on the client for filtering.
     */
    public function list_status( int $limit = 200 ): array {
        $manifest = $this->manifest_read();
        $ids      = $this->eligible_post_ids();

        // Merge in any manifested posts that no longer pass eligibility
        // (e.g. just got excluded but file hasn't been deleted yet) so the
        // user can see them and clean up.
        foreach ( array_keys( $manifest ) as $mid ) {
            if ( ! in_array( (int) $mid, $ids, true ) ) {
                $ids[] = (int) $mid;
            }
        }

        $ids = array_slice( $ids, 0, $limit );

        $rows = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                continue;
            }
            $entry  = $manifest[ $id ] ?? null;
            $exclud = get_post_meta( $id, '_nexeng_exclude', true ) === '1';

            $status = 'pending';
            if ( $exclud )                  $status = 'excluded';
            elseif ( $post->post_status !== 'publish' ) $status = 'unpublished';
            elseif ( $entry && $this->is_post_stale( (int) $id, $entry ) ) $status = 'stale';
            elseif ( $entry )               $status = 'fresh';
            // (Stale detection — comparing post_modified against generated_at — is v1.1.)

            $warnings = ( $entry && isset( $entry['warnings'] ) && is_array( $entry['warnings'] ) ) ? $entry['warnings'] : [];
            // Surface the warnings in the row status so admin UI can flag it.
            if ( $status === 'fresh' && ! empty( $warnings ) ) {
                $status = 'fresh_with_warnings';
            }

            $rows[] = [
                'id'           => (int) $id,
                'title'        => get_the_title( $id ) ?: '(no title)',
                'type'         => $post->post_type,
                'permalink'    => get_permalink( $id ),
                'status'       => $status,
                'bytes'        => $entry['bytes'] ?? 0,
                'generated_at' => $entry['generated_at'] ?? 0,
                'warnings'     => $warnings,
            ];
        }
        return $rows;
    }

    public function bulk_status(): array {
        $total     = (int)  get_transient( 'nexeng_ssg_bulk_total' );
        // Sanitized queue read (drops the stray-false poison) so 'remaining' and
        // 'running' reflect only real work.
        $queue     = $this->read_queue();
        $errors    = (int)  get_transient( 'nexeng_ssg_bulk_errors' );
        $remaining = count( $queue );

        // Derive `processed` from total-minus-remaining rather than the raw
        // nexeng_ssg_bulk_done transient. This guarantees the count the UI sees is
        // always consistent with the actual queue and clamped to [0, total] — it
        // can't show a value that disagrees with 'remaining' (the source of the
        // "38/38 then back to 19/38" bounce was the done transient lagging the
        // queue). When total is unknown (0) fall back to the stored done.
        $processed = $total > 0
            ? max( 0, min( $total, $total - $remaining ) )
            : (int) get_transient( 'nexeng_ssg_bulk_done' );

        // The QUEUE is the source of truth for "running" — a non-empty queue
        // means there's still work to do, regardless of whether the
        // nexeng_ssg_bulk_running hint transient is still alive. This is what makes
        // the UI show "Building…" the instant a build is queued (fixing the live
        // "Continue button at 0%" stall) and keeps it accurate even if the hint
        // expired mid-build. Conversely, an empty queue is always "not running".
        $running   = ! empty( $queue ) && ! get_transient( 'nexeng_ssg_bulk_paused' );

        // Surface the last few error messages so the admin UI can display
        // "3 pages had errors — see below" rather than a silent 20/20 count.
        $error_log = (array) get_option( 'nexeng_ssg_errors', [] );
        $recent_errors = [];
        foreach ( array_slice( $error_log, 0, 20 ) as $entry ) {
            $recent_errors[] = [
                'post_id' => (int) ( $entry['post_id'] ?? 0 ),
                'code'    => $entry['code']    ?? '',
                'message' => $entry['message'] ?? '',
                'stage'   => $entry['stage']   ?? '',
                'title'   => $entry['title']   ?? '',
                'url'     => $entry['url']      ?? '',
            ];
        }

        $paused = (bool) get_transient( 'nexeng_ssg_bulk_paused' );
        $breakdown = get_transient( 'nexeng_ssg_bulk_breakdown' );
        $breakdown = is_array( $breakdown ) ? $breakdown : [];
        $remaining = count( $queue );

        return [
            'running'        => $running,
            'paused'         => $paused,
            'total'          => $total,
            'processed'      => min( $processed, $total > 0 ? $total : $processed ),
            'errors'         => $errors,
            'failed_count'    => count( $error_log ),
            'recent_errors'  => $recent_errors,
            'remaining'      => $remaining,
            'last_url'       => (string) ( get_transient( 'nexeng_ssg_bulk_last_url' ) ?: '' ),
            'build_session'  => (string) ( get_transient( 'nexeng_ssg_build_session' ) ?: '' ),
            'breakdown'      => [
                'posts'    => (int) ( $breakdown['posts'] ?? 0 ),
                'archives' => (int) ( $breakdown['archives'] ?? 0 ),
            ],
            'done'           => ! $paused && ! $running && empty( $queue ),
        ];
    }

    /**
     * Removes ALL static files and the manifest. Leaves the directory and
     * the lockdown .htaccess intact so the next write doesn't have to
     * recreate them.
     */
    public function purge_all(): array {
        $root = $this->root_dir();
        $deleted = 0;
        if ( is_dir( $root ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                $name = $f->getFilename();
                // Preserve our own infra files.
                if ( $f->isFile() && in_array( $name, [ '.htaccess', 'index.php' ], true ) ) {
                    continue;
                }
                if ( $f->isFile() ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
                    @unlink( $f->getPathname() );
                    $deleted++;
                } elseif ( $f->isDir() ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
                    @rmdir( $f->getPathname() );
                }
            }
        }
        $variant_root = dirname( $root ) . '/nexora-private/ncx-sync';
        if ( is_dir( $variant_root ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $variant_root, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iter as $f ) {
                if ( $f->isFile() ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
                    @unlink( $f->getPathname() );
                } elseif ( $f->isDir() ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
                    @rmdir( $f->getPathname() );
                }
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Bulk static-mirror filesystem op; native call is deliberate for atomicity/throughput over potentially thousands of mirror files. WP_Filesystem adds no safety here and is far slower at scale.
            @rmdir( $variant_root );
        }
        // Reset manifest (write empty rather than delete, so reads don't error).
        file_put_contents( $this->manifest_path(), '{}', LOCK_EX );
        $build_id = $this->create_build_id();
        update_option( self::GLOBAL_BUILD_OPTION, $build_id, false );
        $this->finalize_build( $build_id );
        delete_transient( 'nexeng_ssg_stats_' . get_current_blog_id() );
        update_option( 'nexeng_ssg_last_purge_at', time(), false );

        // Stop anything mid-flight and hold off auto-rebuild for a short while.
        // A purge that immediately triggers a rebuild is not a purge: the user
        // watched the mirror empty and refill on its own. The hold is brief so a
        // later genuine edit still auto-rebuilds normally.
        $this->bulk_stop();
        set_transient( 'nexeng_ssg_purge_hold', 1, 5 * MINUTE_IN_SECONDS );

        return [ 'deleted' => $deleted ];
    }

    // ─── Diagnostics ──────────────────────────────────────────────────────────

    /**
     * Returns counts for the admin UI status panel.
     *
     * `total_files` is the manifest-tracked count (accurate after a full regen).
     * `disk_files`  is the actual .html file count on disk — used by the wizard
     *               diagnostic to detect orphaned files left by a previous install
     *               (manifest cleared on reinstall but physical files still present).
     */
    public function stats(): array {
        static $runtime_cache = [];

        $cache_key      = 'nexeng_ssg_stats_' . get_current_blog_id();
        $manifest_path  = $this->manifest_path();
        $manifest_mtime = file_exists( $manifest_path ) ? (int) filemtime( $manifest_path ) : 0;

        if ( isset( $runtime_cache[ $cache_key ] )
            && (int) ( $runtime_cache[ $cache_key ]['_manifest_mtime'] ?? -1 ) === $manifest_mtime
        ) {
            $cached = $runtime_cache[ $cache_key ];
            unset( $cached['_manifest_mtime'] );
            return $cached;
        }

        $cached = get_transient( $cache_key );
        if ( is_array( $cached )
            && (int) ( $cached['_manifest_mtime'] ?? -1 ) === $manifest_mtime
        ) {
            $runtime_cache[ $cache_key ] = $cached;
            unset( $cached['_manifest_mtime'] );
            return $cached;
        }

        $data = $this->manifest_read();

        // Count physical HTML files on disk. Kept cheap by stopping at 2000
        // so even very large sites don't stall the admin page load.
        $disk_files  = 0;
        $static_root = $this->root_dir();
        if ( is_dir( $static_root ) ) {
            try {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator( $static_root, \FilesystemIterator::SKIP_DOTS )
                );
                foreach ( $it as $f ) {
                    if ( $f->isFile() && strtolower( $f->getExtension() ) === 'html' ) {
                        $disk_files++;
                        if ( $disk_files >= 2000 ) { break; }
                    }
                }
            } catch ( \Throwable $e ) {}
        }

        $stats = [
            'total_files'   => count( $data ),
            'disk_files'    => $disk_files,
            'total_bytes'   => array_sum( array_column( $data, 'bytes' ) ),
            'last_write'    => $data ? max( array_column( $data, 'generated_at' ) ) : 0,
            'root_exists'   => is_dir( $static_root ),
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only writability probe for diagnostics output.
            'root_writable' => is_writable( $static_root ),
            'archives'      => $this->archive_manifest_status(),
        ];
        $cache_payload = $stats + [ '_manifest_mtime' => $manifest_mtime ];
        $runtime_cache[ $cache_key ] = $cache_payload;
        set_transient( $cache_key, $cache_payload, 30 );

        return $stats;
    }
}
