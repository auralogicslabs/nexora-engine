<?php
/**
 * Nexora Engine - Static Delivery Command Center
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_pro       = NEXENG_Licence::is_pro();
$ssg_on       = NEXENG_SSG::is_enabled();
// WP Masking depends on SSG — if a stale config leaves headless_mode = on
// while SSG is off (e.g. import from another site, manual DB edit), force
// it to off here so the UI accurately reflects effective state.
if ( ! $ssg_on && get_option( 'nexeng_headless_mode' ) === 'on' ) {
    update_option( 'nexeng_headless_mode', 'off' );
}
$ghost_on     = get_option( 'nexeng_headless_mode', 'off' ) === 'on';
$asset_mode   = get_option( 'nexeng_asset_mode', 'direct' );
$ssg          = NEXENG_SSG::get_instance();
$ssg_stats    = $ssg->stats();
$just_enabled = $is_pro && get_transient( 'nexeng_ghost_auto_enabled' );

// ── Data for embedded Pages & Posts table ────────────────────────────────────
$nexeng_manifest    = $ssg->get_manifest();
$nexeng_fatal_pages = method_exists( $ssg, 'get_fatal_pages' ) ? $ssg->get_fatal_pages() : [];
$nexeng_pending_cnt = method_exists( $ssg, 'pending_count' ) ? $ssg->pending_count() : 0;
$_nexeng_pub_types  = get_post_types( [ 'public' => true ], 'names' );
// Strip internal / ineligible CPTs that must never appear in the mirror table.
// Matches the exclusion list in NEXENG_SSG::is_eligible().
foreach ( [ 'attachment', 'elementor_library', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ] as $_nexeng_internal ) {
    unset( $_nexeng_pub_types[ $_nexeng_internal ] );
}
$nexeng_posts = get_posts( [
    'post_type'      => array_values( $_nexeng_pub_types ),
    'post_status'    => 'publish',
    'posts_per_page' => 200,
    'orderby'        => 'type',
    'order'          => 'ASC',
] );

$upgrade_url = function_exists( 'NexoraEngine\\get_upgrade_url' )
    ? \NexoraEngine\get_upgrade_url( 'pro' )
    : 'https://auralogicslabs.com/nexora-engine/#pricing';

if ( $is_pro && $ssg_on && $ghost_on ) {
    $sys_label = __( 'Full Headless Active', 'nexora-engine' );
    $sys_class = 'ncx-status--full';
} elseif ( $ssg_on ) {
    $sys_label = __( 'Static Active', 'nexora-engine' );
    $sys_class = 'ncx-status--static';
} else {
    $sys_label = __( 'Offline', 'nexora-engine' );
    $sys_class = 'ncx-status--offline';
}
?>

<!-- ── Page header ─────────────────────────────────────────────────────── -->
<div class="ncx-header">
    <div class="ncx-header-title">
        <h1><?php esc_html_e( 'Static Delivery', 'nexora-engine' ); ?></h1>
        <p><?php esc_html_e( 'Manage headless delivery, WP Masking, and your static mirror from one place.', 'nexora-engine' ); ?></p>
        <div class="ncx-hcc-hstat-chips">
            <div class="ncx-hcc-hchip">
                <svg class="ncx-hcc-hchip-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z" stroke="currentColor" stroke-width="1.6"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                <span class="ncx-hcc-hchip-val"><?php echo (int) $ssg_stats['total_files']; ?></span>
                <span class="ncx-hcc-hchip-lbl"><?php esc_html_e( 'Mirror Files', 'nexora-engine' ); ?></span>
            </div>
            <div class="ncx-hcc-hchip">
                <svg class="ncx-hcc-hchip-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3" stroke="currentColor" stroke-width="1.5"/><path d="M3 5v6c0 1.66 4.03 3 9 3s9-1.34 9-3V5M3 11v6c0 1.66 4.03 3 9 3s9-1.34 9-3v-6" stroke="currentColor" stroke-width="1.5"/></svg>
                <span class="ncx-hcc-hchip-val"><?php echo esc_html( size_format( (int) $ssg_stats['total_bytes'] ) ); ?></span>
                <span class="ncx-hcc-hchip-lbl"><?php esc_html_e( 'Total Size', 'nexora-engine' ); ?></span>
            </div>
            <div class="ncx-hcc-hchip">
                <svg class="ncx-hcc-hchip-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/><path d="M12 7v5l3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="ncx-hcc-hchip-val"><?php echo $ssg_stats['last_write'] ? esc_html( human_time_diff( $ssg_stats['last_write'] ) . ' ago' ) : esc_html__( 'never', 'nexora-engine' ); ?></span>
                <span class="ncx-hcc-hchip-lbl"><?php esc_html_e( 'Last Build', 'nexora-engine' ); ?></span>
            </div>
            <div class="ncx-hcc-hchip ncx-hcc-hchip--<?php echo esc_attr( $ssg_on ? 'on' : 'off' ); ?>">
                <span class="ncx-hcc-hchip-dot"></span>
                <span class="ncx-hcc-hchip-lbl"><?php echo $ssg_on ? esc_html__( 'Static Active', 'nexora-engine' ) : esc_html__( 'PHP Fallback', 'nexora-engine' ); ?></span>
            </div>
        </div>
    </div>
    <?php /* SSG toggle in Mirror Build Control panel */ ?>
</div>

<!-- ── Ghost Protocol auto-enabled banner ──────────────────────────────── -->
<?php if ( $just_enabled ) : ?>
<div class="ncx-hcc-promo-banner ncx-banner--success" id="ncxGhostBanner">
    <span class="ncx-banner-icon">🚀</span>
    <div class="ncx-banner-body">
        <strong><?php esc_html_e( 'WP Masking automatically activated!', 'nexora-engine' ); ?></strong>
        <span><?php esc_html_e( 'WordPress fingerprints are now removed from all responses. Asset Proxy is also active to cloak your asset paths.', 'nexora-engine' ); ?></span>
    </div>
    <button type="button" class="ncx-banner-dismiss" onclick="
        this.closest('.ncx-hcc-promo-banner').remove();
        ncxCall('dismiss_ghost_banner');
    ">&times;</button>
</div>
<?php endif; ?>

<!-- ── Nexora Media ecosystem tip (hidden once dismissed via localStorage) ── -->
<?php if ( $ssg_on && ! class_exists( 'NXM_Init' ) ) : ?>
<div class="ncx-ecosystem-tip" id="ncxNxmEcoTip">
    <span class="ncx-eco-tip-icon dashicons dashicons-images-alt2"></span>
    <div class="ncx-eco-tip-body">
        <strong><?php esc_html_e( 'Nexora Media', 'nexora-engine' ); ?></strong>
        <?php esc_html_e( 'Install Nexora Media to serve AVIF/WebP images from your static mirror — smaller files, better Core Web Vitals.', 'nexora-engine' ); ?>
    </div>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-addons' ) ); ?>"
       class="ncx-btn ncx-btn-sm ncx-btn-outline ncx-eco-tip-cta">
        <?php esc_html_e( 'View Addons', 'nexora-engine' ); ?>
    </a>
    <button type="button" class="ncx-eco-tip-dismiss"
            onclick="localStorage.setItem('nexeng_nxm_tip_dismissed','1');this.closest('.ncx-ecosystem-tip').remove();"
            aria-label="<?php esc_attr_e( 'Dismiss', 'nexora-engine' ); ?>">
        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
    </button>
</div>
<?php ob_start(); ?>
    (function(){
        if(localStorage.getItem('nexeng_nxm_tip_dismissed')==='1'){
            var el=document.getElementById('ncxNxmEcoTip');
            if(el)el.remove();
        }
    })();
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════
     HEADLESS CMS — full-width
     Stealth Proxy (always on Pro) + Ghost Protocol toggle
═══════════════════════════════════════════════════════════════════════ -->
<div class="ncx-hcc-layout ncx-hcc-layout--single">

    <!-- Headless CMS -->

    <?php
    // Pro users: silently upgrade to Stealth Proxy if they're still on Direct.
    // This aligns asset mode with their paid tier — no manual migration needed.
    if ( $is_pro && $ssg_on && $asset_mode !== 'proxy' ) {
        update_option( 'nexeng_asset_mode', 'proxy' );
        $asset_mode = 'proxy';
        NEXENG_Dropin::install();
    }
    $stealth_on = $is_pro && $ghost_on; // Ghost is the visible "stealth" toggle for Pro.
    ?>

    <?php if ( ! $is_pro ) : ?>
    <!-- ── FREE: Standard delivery info + upgrade prompt ──────────────── -->
    <div class="ncx-hcc-section-card ncx-hcc-stealth-teaser">

        <div class="ncx-hcc-section-header ncx-hcc-section-header--blue">
            <div class="ncx-hcc-section-icon ncx-icon--stealth-blue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2L20 6V12C20 16.4 16.5 20.5 12 22C7.5 20.5 4 16.4 4 12V6L12 2Z"
                          fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.65)"
                          stroke-width="1.5" stroke-linejoin="round"/>
                    <path d="M8 12.5l3 3 5-6" stroke="rgba(255,255,255,0.7)"
                          stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="ncx-hcc-section-meta">
                <h2 class="ncx-blue-section-title">
                    <?php esc_html_e( 'Headless CMS', 'nexora-engine' ); ?>
                    <span class="ncx-blue-pro-badge">
                        <svg width="9" height="9" viewBox="0 0 10 10" fill="none"><path d="M5 1l1.2 2.5L9 4.1 7 6l.5 2.9L5 7.5 2.5 8.9 3 6 1 4.1l2.8-.6L5 1Z" fill="currentColor"/></svg>
                        PRO
                    </span>
                </h2>
                <p><?php esc_html_e( 'Same high-speed SSG with WordPress fingerprints hidden from public delivery.', 'nexora-engine' ); ?></p>
            </div>
        </div>

        <div class="ncx-hcc-teaser-body">
            <ul class="ncx-hcc-teaser-list">
                <li>
                    <div class="ncx-teaser-feat-icon">🛡️</div>
                    <div>
                        <strong><?php esc_html_e( 'Asset Proxy', 'nexora-engine' ); ?></strong>
                        <span><?php esc_html_e( 'Rewrites /wp-content/ and /wp-includes/ to neutral /_ncx_v12/ endpoints so WordPress stays invisible in source.', 'nexora-engine' ); ?></span>
                    </div>
                </li>
                <li>
                    <div class="ncx-teaser-feat-icon">👻</div>
                    <div>
                        <strong><?php esc_html_e( 'WP Masking', 'nexora-engine' ); ?></strong>
                        <span><?php esc_html_e( 'Strips generator meta, X-Powered-By headers, REST discovery links, and wlwmanifest from every response.', 'nexora-engine' ); ?></span>
                    </div>
                </li>
                <li>
                    <div class="ncx-teaser-feat-icon">⚡</div>
                    <div>
                        <strong><?php esc_html_e( 'Auto-Rebuild on Publish', 'nexora-engine' ); ?></strong>
                        <span><?php esc_html_e( 'Content publishes and theme changes automatically queue a mirror refresh — no manual builds needed.', 'nexora-engine' ); ?></span>
                    </div>
                </li>
            </ul>
        </div>

        <div class="ncx-hcc-teaser-cta">
            <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank"
               class="ncx-btn ncx-btn-primary ncx-hcc-upgrade-btn">
                <?php esc_html_e( 'Unlock Headless CMS', 'nexora-engine' ); ?> →
            </a>
            <p class="ncx-hcc-teaser-cta-note">
                <?php esc_html_e( 'Asset Proxy activates automatically on upgrade — no config needed.', 'nexora-engine' ); ?>
            </p>
        </div>

    </div><!-- /.ncx-hcc-stealth-teaser -->

    <?php else : ?>
    <!-- ── PRO: Single Stealth Mode toggle (Ghost + Proxy combined) ─────── -->
    <div class="ncx-hcc-section-card ncx-hcc-stealth-full">

        <div class="ncx-hcc-section-header ncx-hcc-section-header--blue">
            <div class="ncx-hcc-section-icon ncx-icon--stealth-blue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2L20 6V12C20 16.4 16.5 20.5 12 22C7.5 20.5 4 16.4 4 12V6L12 2Z"
                          fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.65)"
                          stroke-width="1.5" stroke-linejoin="round"/>
                    <path d="M9 12l2 2 4-4" stroke="rgba(255,255,255,0.9)"
                          stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="ncx-hcc-section-meta">
                <h2 class="ncx-blue-section-title">
                    <?php esc_html_e( 'Headless CMS', 'nexora-engine' ); ?>
                    <span class="ncx-blue-pro-badge">
                        <svg width="9" height="9" viewBox="0 0 10 10" fill="none"><path d="M5 1l1.2 2.5L9 4.1 7 6l.5 2.9L5 7.5 2.5 8.9 3 6 1 4.1l2.8-.6L5 1Z" fill="currentColor"/></svg>
                        PRO
                    </span>
                </h2>
                <p><?php esc_html_e( 'Asset Proxy is your default headless CMS delivery layer.', 'nexora-engine' ); ?></p>
            </div>
            <div class="ncx-hcc-pro-status-chip">
                <span class="ncx-pro-status-dot"></span>
                <?php esc_html_e( 'Proxy Active', 'nexora-engine' ); ?>
            </div>
        </div>

        <div class="ncx-hcc-section-body ncx-hcc-stealth-body">
            <div class="ncx-hcc-stealth-cols">

            <!-- Asset Proxy: always-on indicator for Pro -->
            <div class="ncx-stealth-proxy-row">
                <div class="ncx-stealth-proxy-icon">🛡️</div>
                <div class="ncx-stealth-proxy-info">
                    <strong><?php esc_html_e( 'Asset Proxy', 'nexora-engine' ); ?>
                        <span class="ncx-stealth-proxy-active-badge"><?php esc_html_e( 'Always On', 'nexora-engine' ); ?></span>
                    </strong>
                    <span><?php esc_html_e( 'All /wp-content/ and /wp-includes/ paths are rewritten to /_ncx_v12/ neutral endpoints. WordPress is invisible in source code.', 'nexora-engine' ); ?></span>
                    <code class="ncx-stealth-url-example">/_ncx_v12/assets/t/theme/style.css</code>
                </div>
            </div>

            <!-- WP Masking: separate toggle -->
            <div class="ncx-hcc-ghost-row<?php echo esc_attr( ! $ssg_on ? ' ncx-hcc-ghost-row--locked' : '' ); ?>" id="ncxWpMaskingRow">
                <div class="ncx-hcc-ghost-icon-wrap">
                    <span aria-hidden="true">👻</span>
                </div>
                <div class="ncx-hcc-ghost-info">
                    <strong><?php esc_html_e( 'WP Masking', 'nexora-engine' ); ?></strong>
                    <span><?php esc_html_e( 'Strips generator meta, X-Powered-By, REST discovery links, and wlwmanifest from every response.', 'nexora-engine' ); ?></span>
                    <?php if ( ! $ssg_on ) : ?>
                    <em class="ncx-hcc-ghost-requires"><?php esc_html_e( 'Requires Static Delivery — enable it above first.', 'nexora-engine' ); ?></em>
                    <?php endif; ?>
                </div>
                <div class="ncx-hcc-ghost-toggle">
                    <div class="ncx-ghost-toggle-state <?php echo esc_attr( $ghost_on ? 'ncx-ghost-toggle-state--on' : 'ncx-ghost-toggle-state--off' ); ?>" id="ncxWpMaskingState">
                        <?php echo $ghost_on ? esc_html__( 'ON', 'nexora-engine' ) : esc_html__( 'OFF', 'nexora-engine' ); ?>
                    </div>
                    <label class="ncx-switch ncx-switch--lg<?php echo esc_attr( ! $ssg_on ? ' ncx-switch--disabled' : '' ); ?>"
                           title="<?php echo ! $ssg_on ? esc_attr__( 'Enable Static Delivery first to use WP Masking', 'nexora-engine' ) : ''; ?>">
                        <input type="checkbox" class="ncx-toggle-auto"
                               id="ncxWpMaskingToggle"
                               data-option="headless_mode"
                               data-label="<?php esc_attr_e( 'WP Masking', 'nexora-engine' ); ?>"
                               <?php checked( $ghost_on ); ?>
                               <?php disabled( ! $ssg_on ); ?>>
                        <span class="ncx-slider"></span>
                    </label>
                </div>
            </div>
            </div><!-- /.ncx-hcc-stealth-cols -->

            <!-- Rebuild progress (shown when Regenerate All is running) -->
            <div class="ncx-mode-rebuild-panel" id="ncxModeRebuildPanel" style="display:none">
                <div class="ncx-mode-rebuild-head">
                    <div class="ncx-wiz-spinner"></div>
                    <strong id="ncxRebuildHeading"><?php esc_html_e( 'Rebuilding static cache…', 'nexora-engine' ); ?></strong>
                </div>
                <div class="ncx-mode-rebuild-bar">
                    <div class="ncx-mode-rebuild-fill" id="ncxRebuildFill" style="width:0%"></div>
                </div>
                <div class="ncx-mode-rebuild-meta">
                    <span id="ncxRebuildCount">0 / —</span>
                    <span><?php esc_html_e( 'pages rebuilt', 'nexora-engine' ); ?></span>
                </div>
            </div>

            <!-- Done state -->
            <div class="ncx-mode-rebuild-done" id="ncxModeRebuildDone" style="display:none">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="8" fill="#22c55e"/><path d="M4.5 8L7 10.5l4.5-5" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <strong id="ncxRebuildDoneMsg"><?php esc_html_e( 'All pages rebuilt successfully.', 'nexora-engine' ); ?></strong>
            </div>

        </div><!-- /.ncx-hcc-stealth-body -->
    </div><!-- /.ncx-hcc-stealth-full -->
    <?php endif; ?>

</div><!-- /.ncx-hcc-layout -->

<!-- ══════════════════════════════════════════════════════════════════════
     PAGES & POSTS — capture status + per-page refresh
     Moved here from the stand-alone "Pages & Posts" menu page.
     Traffic/analytics insights live in SEO Report (Pro).
══════════════════════════════════════════════════════════════════════ -->
<div class="ncx-hcc-pages-section">

    <div class="ncx-hcc-pages-header">
        <div>
            <h2><?php esc_html_e( 'Pages & Posts', 'nexora-engine' ); ?></h2>
            <p><?php esc_html_e( 'Static capture status across your content library. Refresh individual pages after edits.', 'nexora-engine' ); ?></p>
        </div>
        <?php if ( $nexeng_pending_cnt > 0 ) : ?>
        <span class="ncx-hcc-pages-pending-chip">
            <?php echo esc_html( sprintf(
                /* translators: %d: number of items. */
                _n( '%d page needs refresh', '%d pages need refresh', $nexeng_pending_cnt, 'nexora-engine' ),
                $nexeng_pending_cnt
            ) ); ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $nexeng_fatal_pages ) ) : ?>
    <div class="ncx-pages-fatal-notice">
        <span class="dashicons dashicons-warning"></span>
        <div>
            <strong><?php echo esc_html( sprintf(
                /* translators: %d: number of items. */
                _n( '%d page is blocked — PHP fatal error on last capture attempt', '%d pages are blocked — PHP fatal error on last capture attempt', count( $nexeng_fatal_pages ), 'nexora-engine' ),
                count( $nexeng_fatal_pages )
            ) ); ?></strong>
            <p><?php esc_html_e( 'These pages serve dynamically until fixed. Common cause: PHP memory exhausted (add define(\'WP_MEMORY_LIMIT\',\'512M\') to wp-config.php). Once fixed, click ↻ on each row to retry.', 'nexora-engine' ); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="ncx-report-filters">
        <div class="ncx-filter-group ncx-filter-group--type">
            <span class="ncx-filter-label"><?php esc_html_e( 'Type', 'nexora-engine' ); ?></span>
            <button class="ncx-btn active" data-filter="all"><?php esc_html_e( 'All', 'nexora-engine' ); ?></button>
            <?php
            $_types_in_list = array_unique( array_column( $nexeng_posts, 'post_type' ) );
            sort( $_types_in_list );
            foreach ( $_types_in_list as $_pt ) :
                $pto   = get_post_type_object( $_pt );
                $label = $pto ? $pto->labels->name : ucfirst( $_pt );
            ?>
            <button class="ncx-btn" data-filter="<?php echo esc_attr( $_pt ); ?>"><?php echo esc_html( $label ); ?></button>
            <?php endforeach; ?>
        </div>
        <div class="ncx-filter-tools">
            <label class="ncx-filter-select-wrap">
                <span><?php esc_html_e( 'Status', 'nexora-engine' ); ?></span>
                <select class="ncx-capture-filter-select" aria-label="<?php esc_attr_e( 'Filter by capture status', 'nexora-engine' ); ?>">
                    <option value="all"><?php esc_html_e( 'All statuses', 'nexora-engine' ); ?></option>
                    <option value="captured"><?php esc_html_e( 'Captured', 'nexora-engine' ); ?></option>
                    <option value="stale"><?php esc_html_e( 'Needs Refresh', 'nexora-engine' ); ?></option>
                    <option value="pending"><?php esc_html_e( 'Pending', 'nexora-engine' ); ?></option>
                    <?php if ( ! empty( $nexeng_fatal_pages ) ) : ?>
                    <option value="fatal"><?php esc_html_e( 'Blocked (Fatal)', 'nexora-engine' ); ?></option>
                    <?php endif; ?>
                </select>
            </label>
            <div class="ncx-search-wrap">
                <span class="dashicons dashicons-search"></span>
                <input type="text" class="ncx-search-pages" placeholder="<?php esc_attr_e( 'Search by title…', 'nexora-engine' ); ?>">
            </div>
        </div>
    </div>

    <div class="ncx-pages-table-container">
        <table class="ncx-pages-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Page / URL', 'nexora-engine' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'nexora-engine' ); ?></th>
                    <th><?php esc_html_e( 'Capture Status', 'nexora-engine' ); ?></th>
                    <th><?php esc_html_e( 'Last Optimized', 'nexora-engine' ); ?></th>
                    <th style="text-align:right"><?php esc_html_e( 'Actions', 'nexora-engine' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $nexeng_posts as $p ) :
                $url          = get_permalink( $p->ID );
                $rel_url      = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
                $home_path    = rtrim( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '', '/' );
                if ( $home_path && strpos( $rel_url, $home_path ) === 0 ) {
                    $rel_url = substr( $rel_url, strlen( $home_path ) );
                }
                $rel_url = '/' . trim( $rel_url, '/' );
                if ( $rel_url !== '/' ) $rel_url .= '/';

                $is_captured    = isset( $nexeng_manifest[ $p->ID ] );
                $is_stale       = $is_captured && $ssg->is_post_stale( (int) $p->ID, $nexeng_manifest[ $p->ID ] );
                $is_fatal       = isset( $nexeng_fatal_pages[ $p->ID ] );
                $fatal_info     = $is_fatal ? $nexeng_fatal_pages[ $p->ID ] : null;
                $capture_state  = $is_fatal ? 'fatal' : ( $is_stale ? 'stale' : ( $is_captured ? 'captured' : 'pending' ) );
                $mtime          = $is_captured ? $nexeng_manifest[ $p->ID ]['generated_at'] : 0;
            ?>
            <tr class="ncx-page-row" data-type="<?php echo esc_attr( $p->post_type ); ?>" data-capture="<?php echo esc_attr( $capture_state ); ?>" data-title="<?php echo esc_attr( $p->post_title ); ?>"<?php if ( $is_fatal ) echo ' data-fatal="1"'; ?>>
                <td>
                    <div class="ncx-page-info">
                        <span class="title"><?php echo esc_html( $p->post_title ); ?></span>
                        <a href="<?php echo esc_url( $url ); ?>" target="_blank" class="url"><?php echo esc_html( $rel_url ); ?></a>
                    </div>
                </td>
                <td><span class="ncx-badge-type"><?php echo esc_html( ucfirst( $p->post_type ) ); ?></span></td>
                <td>
                    <?php if ( $is_fatal ) :
                        $fatal_msg = htmlspecialchars( $fatal_info['message'] ?? 'PHP fatal error during capture', ENT_QUOTES, 'UTF-8' );
                        $fatal_age = $fatal_info['ts'] ? human_time_diff( $fatal_info['ts'], time() ) . ' ago' : '';
                    ?>
                        <span class="ncx-badge ncx-badge-fatal" title="<?php echo esc_attr( $fatal_msg . ( $fatal_age ? ' · ' . $fatal_age : '' ) ); ?>">
                            <span class="dashicons dashicons-warning" style="font-size:12px;width:12px;height:12px;margin-right:3px;vertical-align:middle;"></span><?php esc_html_e( 'Blocked', 'nexora-engine' ); ?>
                        </span>
                    <?php elseif ( $is_stale ) : ?>
                        <span class="ncx-badge warning"><?php esc_html_e( 'Needs Refresh', 'nexora-engine' ); ?></span>
                    <?php elseif ( $is_captured ) : ?>
                        <span class="ncx-badge success"><?php esc_html_e( 'Captured', 'nexora-engine' ); ?></span>
                    <?php else : ?>
                        <span class="ncx-badge warning"><?php esc_html_e( 'Pending', 'nexora-engine' ); ?></span>
                    <?php endif; ?>
                </td>
                <td class="ncx-date-cell"><?php echo $mtime ? esc_html( human_time_diff( $mtime ) . ' ago' ) : esc_html__( 'Never', 'nexora-engine' ); ?></td>
                <td style="text-align:right">
                    <div class="ncx-row-actions">
                        <?php if ( $ssg_on ) : ?>
                        <button class="ncx-btn ncx-btn-sm <?php echo esc_attr( $is_fatal ? 'ncx-btn-fatal-retry' : '' ); ?> ncx-regen-one"
                                data-id="<?php echo esc_attr( $p->ID ); ?>"
                                title="<?php echo $is_fatal ? esc_attr__( 'Retry capture', 'nexora-engine' ) : esc_attr__( 'Regenerate this page', 'nexora-engine' ); ?>">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                        <?php else : ?>
                        <button class="ncx-btn ncx-btn-sm" disabled
                                title="<?php esc_attr_e( 'Static Delivery is off — enable it to regenerate this page', 'nexora-engine' ); ?>"
                                style="opacity:.4;cursor:not-allowed">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" class="ncx-btn ncx-btn-sm" title="<?php esc_attr_e( 'Edit', 'nexora-engine' ); ?>">
                            <span class="dashicons dashicons-edit"></span>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div><!-- /.ncx-hcc-pages-section -->

<?php ob_start(); ?>
/* ── Pages & Posts section on Static Delivery page ──────────────────────────── */
.ncx-hcc-pages-section {
    margin-top: 28px;
}
.ncx-hcc-pages-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.ncx-hcc-pages-header h2 {
    font-size: 16px;
    font-weight: 800;
    color: var(--ncx-gray-900, #111827);
    margin: 0 0 3px;
}
.ncx-hcc-pages-header p {
    font-size: 12px;
    color: var(--ncx-muted, #6B7280);
    margin: 0;
}
.ncx-hcc-pages-pending-chip {
    display: inline-flex;
    align-items: center;
    padding: 5px 12px;
    border-radius: 999px;
    background: rgba(245,158,11,.1);
    color: #92400e;
    border: 1px solid rgba(245,158,11,.3);
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    flex-shrink: 0;
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
/* ── Status badge ─────────────────────────────────────────────────────────── */
.ncx-hcc-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 7px 16px; border-radius: 24px;
    font-size: 13px; font-weight: 700; letter-spacing: .02em;
}
.ncx-hcc-badge-dot { width:8px; height:8px; border-radius:50%; background:currentColor; }
.ncx-status--full    { background:rgba(16,185,129,.12); color:#059669; border:1px solid rgba(16,185,129,.25); }
.ncx-status--full .ncx-hcc-badge-dot { animation: ncx-pulse 2s infinite; }
.ncx-status--static  { background:rgba(2,82,250,.1);    color:#0252FA; border:1px solid rgba(2,82,250,.2); }
.ncx-status--offline { background:rgba(107,114,128,.1); color:#6B7280; border:1px solid rgba(107,114,128,.2); }
@keyframes ncx-pulse { 0%,100%{opacity:1} 50%{opacity:.35} }

/* ── Auto-enable banner ───────────────────────────────────────────────────── */
.ncx-hcc-promo-banner {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px; border-radius: 12px; margin-bottom: 4px; position: relative;
}
.ncx-banner--success { background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.25); }
.ncx-banner-icon     { font-size:22px; flex-shrink:0; }
.ncx-banner-body     { flex:1; }
.ncx-banner-body strong { display:block; font-size:14px; color:#065f46; margin-bottom:3px; }
.ncx-banner-body span   { font-size:13px; color:#047857; line-height:1.5; }
.ncx-banner-dismiss {
    background:none; border:none; cursor:pointer; font-size:20px;
    color:#6B7280; line-height:1; padding:0; margin-left:auto; flex-shrink:0;
}
.ncx-banner-dismiss:hover { color:#111; }

/* ══════════════════════════════════════════════════════════════════════════
   TWO-COLUMN LAYOUT
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
    margin-top: 22px;
    align-items: start;
}

/* ── Section cards ────────────────────────────────────────────────────────── */
.ncx-hcc-section-card {
    background: var(--ncx-bg-card, #fff);
    border: 1.5px solid var(--ncx-brand-border, #E5E7EB);
    border-radius: 18px;
    overflow: hidden;
    transition: border-color .2s, box-shadow .2s;
    display: flex;
    flex-direction: column;
}
.ncx-section--active {
    border-color: rgba(16,185,129,.3);
    box-shadow: 0 0 0 3px rgba(16,185,129,.07);
}
.ncx-hcc-stealth-teaser,
.ncx-hcc-stealth-full {
    border-color: rgba(2,82,250,.2);
    box-shadow: 0 0 0 3px rgba(2,82,250,.05);
}

/* ── Section header (SSG — white) ─────────────────────────────────────────── */
.ncx-hcc-section-header {
    display: flex; align-items: center; gap: 16px;
    padding: 22px 24px 18px;
    border-bottom: 1px solid var(--ncx-brand-border, #E5E7EB);
}
.ncx-hcc-section-icon {
    width: 48px; height: 48px; flex-shrink: 0;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
}
.ncx-icon--ssg { background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(5,150,105,.2)); color:#059669; }
.ncx-hcc-section-icon .dashicons { font-size:24px; width:24px; height:24px; }
.ncx-hcc-section-meta { flex:1; min-width:0; }
.ncx-hcc-section-meta h2 { font-size:16px; font-weight:800; color:var(--ncx-gray-900,#111); margin:0 0 4px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.ncx-hcc-section-meta p  { font-size:12px; color:var(--ncx-muted,#6B7280); margin:0; line-height:1.5; }

/* Toggle in SSG header */
.ncx-hcc-section-toggle { display:flex; align-items:center; gap:10px; flex-shrink:0; }
.ncx-hcc-toggle-label   { font-size:12px; font-weight:700; letter-spacing:.05em; }
.ncx-tl--on  { color:#10B981; }
.ncx-tl--off { color:#9CA3AF; }

/* Section body */
.ncx-hcc-section-body { padding:20px 24px; flex:1; display:flex; flex-direction:column; gap:0; }

/* ══════════════════════════════════════════════════════════════════════════
   STEALTH LAYER — BRAND BLUE HEADER
   Distinct from SSG's white header. Uses #0252FA gradient to signal Pro power.
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-section-header--blue {
    background: linear-gradient(135deg, #0140C8 0%, #0252FA 58%, #1563ff 100%);
    border-bottom: none;
    padding: 22px 24px 20px;
    position: relative;
    overflow: hidden;
}
/* Subtle radial glow inside header */
.ncx-hcc-section-header--blue::before {
    content: '';
    position: absolute;
    top: -30px; right: -30px;
    width: 140px; height: 140px;
    background: radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%);
    pointer-events: none;
}

.ncx-icon--stealth-blue {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    flex-shrink: 0;
}

/* Title on blue */
.ncx-blue-section-title {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    font-size: 16px; font-weight: 800;
    color: #ffffff !important;
    margin: 0 0 4px;
}

/* PRO badge on blue */
.ncx-blue-pro-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px;
    background: rgba(255,255,255,.18);
    color: rgba(255,255,255,.95);
    border: 1px solid rgba(255,255,255,.3);
    font-size: 10px; font-weight: 700; letter-spacing: .08em;
}

/* Subtitle on blue */
.ncx-hcc-section-header--blue .ncx-hcc-section-meta p {
    color: rgba(255,255,255,.72);
    font-size: 12px;
}

/* "Active" status chip on blue header */
.ncx-hcc-pro-status-chip {
    display: flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 20px;
    background: rgba(34,197,94,.25);
    color: #86efac;
    border: 1px solid rgba(34,197,94,.4);
    font-size: 11px; font-weight: 700; flex-shrink: 0; white-space: nowrap;
}
.ncx-pro-status-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #86efac; animation: ncx-pulse 2s infinite;
}

/* ── Stats row ────────────────────────────────────────────────────────────── */
.ncx-hcc-stats-row {
    display: flex; align-items: center;
    background: var(--ncx-bg-100, #F9FAFB);
    border: 1px solid var(--ncx-brand-border, #E5E7EB);
    border-radius: 12px; overflow: hidden;
    margin-bottom: 16px;
}
.ncx-hcc-stat-cell { flex:1; padding:13px 12px; text-align:center; }
.ncx-stat-divider  { width:1px; background:var(--ncx-brand-border,#E5E7EB); align-self:stretch; flex-shrink:0; }
.ncx-stat-val { display:block; font-size:18px; font-weight:800; color:var(--ncx-gray-900,#111); line-height:1.2; }
.ncx-stat-lbl { display:block; font-size:10px; text-transform:uppercase; color:var(--ncx-muted,#9CA3AF); font-weight:600; letter-spacing:.06em; margin-top:2px; }

/* ── Info pills ───────────────────────────────────────────────────────────── */
.ncx-hcc-info-pill {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 13px; border-radius: 8px;
    font-size: 12px; font-weight: 500; margin-bottom: 16px;
}
.ncx-hcc-info-pill .dashicons { font-size:14px; width:14px; height:14px; flex-shrink:0; }
.ncx-pill--pro  { background:rgba(16,185,129,.09); color:#065f46; border:1px solid rgba(16,185,129,.2); }
.ncx-pill--pro .dashicons { color:#10B981; }
.ncx-pill--free { background:rgba(2,82,250,.07);   color:#1e40af; border:1px solid rgba(2,82,250,.15); }
.ncx-pill--free a { color:#0252FA; font-weight:700; text-decoration:none; margin-left:2px; }
.ncx-pill--free a:hover { text-decoration:underline; }

/* ── Action buttons ───────────────────────────────────────────────────────── */
.ncx-hcc-actions { display:flex; gap:10px; margin-top:auto; padding-top:4px; }

/* ══════════════════════════════════════════════════════════════════════════
   STEALTH LAYER — TEASER (FREE) — Vertical single-column layout
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-teaser-body {
    padding: 22px 24px 16px;
    flex: 1;
}
.ncx-hcc-teaser-headline {
    font-size: 14px; font-weight: 700;
    color: var(--ncx-gray-900,#111);
    margin: 0 0 8px;
}
.ncx-hcc-teaser-sub {
    font-size: 12px; color: var(--ncx-muted,#6B7280);
    line-height: 1.6; margin: 0 0 20px;
}

/* Feature list */
.ncx-hcc-teaser-list {
    list-style: none; padding: 0; margin: 0;
    display: flex; flex-direction: column; gap: 14px;
}
.ncx-hcc-teaser-list li {
    display: flex; align-items: flex-start; gap: 12px;
}
.ncx-teaser-feat-icon {
    width: 36px; height: 36px; flex-shrink: 0;
    border-radius: 10px;
    background: rgba(2,82,250,.07);
    border: 1px solid rgba(2,82,250,.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; line-height: 1;
}
.ncx-hcc-teaser-list strong {
    display: block; font-size: 13px; font-weight: 700;
    color: var(--ncx-gray-900,#111); margin-bottom: 2px;
}
.ncx-hcc-teaser-list span {
    font-size: 11px; color: var(--ncx-muted,#6B7280); line-height: 1.4;
}

/* CTA section */
.ncx-hcc-teaser-cta {
    padding: 18px 24px 22px;
    border-top: 1px solid rgba(2,82,250,.1);
    background: rgba(2,82,250,.02);
    text-align: center;
}
.ncx-hcc-upgrade-btn {
    width: 100%; justify-content: center;
    padding: 12px 24px; font-size: 14px; font-weight: 700;
    margin-bottom: 10px;
}
.ncx-hcc-teaser-cta-note {
    font-size: 11px; color: var(--ncx-muted,#9CA3AF);
    margin: 0; line-height: 1.5;
}

/* ══════════════════════════════════════════════════════════════════════════
   STEALTH LAYER — PRO FULL CONTROLS
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-stealth-body { padding-top: 0; }

/* Ghost Protocol row */
.ncx-hcc-ghost-row {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 24px;
    background: rgba(2,82,250,.03);
    border-bottom: 1px solid rgba(2,82,250,.1);
}
.ncx-hcc-ghost-icon-wrap {
    width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
    background: rgba(2,82,250,.08); border: 1px solid rgba(2,82,250,.14);
    display: flex; align-items: center; justify-content: center; font-size: 20px;
}
.ncx-hcc-ghost-info { flex:1; min-width:0; }
.ncx-hcc-ghost-info strong { display:block; font-size:13px; font-weight:700; color:var(--ncx-gray-900,#111); margin-bottom:3px; }
.ncx-hcc-ghost-info span   { font-size:11px; color:var(--ncx-muted,#6B7280); line-height:1.5; display:block; }
.ncx-hcc-ghost-toggle { display:flex; align-items:center; gap:10px; flex-shrink:0; }

.ncx-ghost-toggle-state {
    font-size:11px; font-weight:700; letter-spacing:.05em;
    padding:3px 10px; border-radius:12px;
}
.ncx-ghost-toggle-state--on  { background:rgba(2,82,250,.1);   color:#0252FA; }
.ncx-ghost-toggle-state--off { background:rgba(107,114,128,.1); color:#9CA3AF; }

/* ── Asset delivery mode section ──────────────────────────────────────────── */
.ncx-hcc-mode-section { padding:20px 24px 24px; }
.ncx-hcc-mode-header {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 12px; margin-bottom: 16px;
}
.ncx-hcc-mode-header > div:first-child { flex:1; }
.ncx-hcc-mode-header strong { display:block; font-size:13px; font-weight:700; color:var(--ncx-gray-900,#111); margin-bottom:4px; }
.ncx-hcc-mode-header > div:first-child > span { font-size:12px; color:var(--ncx-muted,#6B7280); line-height:1.5; }

/* Current-mode badge */
.ncx-mode-current-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 11px; border-radius: 20px;
    font-size: 11px; font-weight: 700; flex-shrink: 0; white-space: nowrap;
}
.ncx-mode-current-badge--direct { background:rgba(16,185,129,.1); color:#059669; border:1px solid rgba(16,185,129,.2); }
.ncx-mode-current-badge--proxy  { background:rgba(2,82,250,.1);   color:#0252FA; border:1px solid rgba(2,82,250,.2); }

/* ── Mode option cards ────────────────────────────────────────────────────── */
.ncx-mode-selector { display:flex; flex-direction:column; gap:8px; margin-bottom:12px; }

.ncx-mode-option {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; border-radius: 12px;
    background: var(--ncx-bg-100,#F9FAFB);
    border: 2px solid var(--ncx-brand-border,#E5E7EB);
    cursor: pointer; text-align: left; width: 100%;
    transition: border-color .18s, box-shadow .18s, background .18s;
}
.ncx-mode-option:hover:not(:disabled) {
    border-color: rgba(2,82,250,.3);
    box-shadow: 0 2px 12px rgba(2,82,250,.07);
    background: rgba(2,82,250,.02);
}
.ncx-mode-option--active {
    border-color: #0252FA !important;
    background: rgba(2,82,250,.03) !important;
    box-shadow: 0 0 0 3px rgba(2,82,250,.09) !important;
}
.ncx-mode-option--proxy:hover:not(:disabled) {
    border-color: rgba(99,102,241,.4);
    box-shadow: 0 2px 12px rgba(99,102,241,.08);
    background: rgba(99,102,241,.025);
}
.ncx-mode-option--proxy.ncx-mode-option--active {
    border-color: #6366f1 !important;
    background: rgba(99,102,241,.04) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,.1) !important;
}
.ncx-mode-option:disabled { opacity:.55; cursor:default; }

/* Radio dot */
.ncx-mode-option-radio {
    width:16px; height:16px; flex-shrink:0; border-radius:50%;
    border:2px solid #D1D5DB; background:#fff;
    display:flex; align-items:center; justify-content:center;
    transition:border-color .18s;
}
.ncx-mode-option--active .ncx-mode-option-radio { border-color:#0252FA; }
.ncx-mode-option--proxy.ncx-mode-option--active .ncx-mode-option-radio { border-color:#6366f1; }
.ncx-mode-option-radio-dot {
    width:7px; height:7px; border-radius:50%;
    background:#0252FA; display:block; opacity:0; transition:opacity .18s;
}
.ncx-mode-option--active .ncx-mode-option-radio-dot { opacity:1; }
.ncx-mode-option--proxy.ncx-mode-option--active .ncx-mode-option-radio-dot { background:#6366f1; opacity:1; }

.ncx-mode-option-icon { font-size:20px; flex-shrink:0; line-height:1; }

.ncx-mode-option-body { flex:1; min-width:0; }
.ncx-mode-option-name {
    display:flex; align-items:center; gap:7px;
    font-size:13px; font-weight:700; color:var(--ncx-gray-900,#111); margin-bottom:3px;
}
.ncx-mode-option-body p    { font-size:11px; color:var(--ncx-muted,#6B7280); margin:0 0 5px; line-height:1.5; }
.ncx-mode-option-body code {
    font-size:10px; background:rgba(0,0,0,.05); padding:3px 7px; border-radius:5px;
    color:#374151; border:1px solid rgba(0,0,0,.07); display:inline-block; word-break:break-all;
}
.ncx-mode-option--active .ncx-mode-option-body code { background:rgba(2,82,250,.06); border-color:rgba(2,82,250,.14); color:#1e40af; }
.ncx-mode-option--proxy.ncx-mode-option--active .ncx-mode-option-body code { background:rgba(99,102,241,.06); border-color:rgba(99,102,241,.14); color:#3730a3; }

/* Tags */
.ncx-mode-opt-tag { padding:2px 7px; border-radius:10px; font-size:10px; font-weight:700; letter-spacing:.04em; }
.ncx-mode-opt-tag--default { background:rgba(107,114,128,.1); color:#6B7280; }
.ncx-mode-opt-tag--pro     { background:rgba(2,82,250,.1);    color:#0252FA; }

/* Side badge */
.ncx-mode-option-badge {
    display:flex; align-items:center; gap:4px; flex-shrink:0;
    padding:4px 10px; border-radius:20px;
    font-size:10px; font-weight:700; letter-spacing:.04em;
}
.ncx-mode-option-badge--direct  { background:rgba(16,185,129,.1); color:#059669; border:1px solid rgba(16,185,129,.2); }
.ncx-mode-option-badge--stealth { background:rgba(99,102,241,.1); color:#6366f1; border:1px solid rgba(99,102,241,.2); }

/* ── Impact warning ───────────────────────────────────────────────────────── */
.ncx-mode-impact-notice {
    display:flex; align-items:flex-start; gap:8px;
    padding:10px 13px; border-radius:9px;
    background:rgba(245,158,11,.07); border:1px solid rgba(245,158,11,.3);
    font-size:11px; color:#92400e; line-height:1.5;
    margin-top:2px; margin-bottom:8px;
}
.ncx-mode-impact-notice svg { flex-shrink:0; margin-top:1px; }

/* ── Rebuild progress panel ───────────────────────────────────────────────── */
.ncx-mode-rebuild-panel {
    padding:16px 18px; border-radius:12px;
    background:var(--ncx-bg-100,#F9FAFB);
    border:1.5px solid rgba(2,82,250,.15);
    margin-top:12px;
}
.ncx-mode-rebuild-head { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.ncx-mode-rebuild-head strong { font-size:12px; font-weight:700; color:var(--ncx-gray-900,#111); }
.ncx-mode-rebuild-bar { height:6px; background:rgba(0,0,0,.07); border-radius:10px; overflow:hidden; margin-bottom:7px; }
.ncx-mode-rebuild-fill { height:100%; width:0%; background:linear-gradient(90deg,#0252FA,#6366f1); border-radius:10px; transition:width .5s ease; }
.ncx-mode-rebuild-meta { display:flex; gap:5px; font-size:11px; color:var(--ncx-muted,#6B7280); }
.ncx-mode-rebuild-meta span:first-child { font-weight:700; color:var(--ncx-gray-900,#111); }

/* ── Rebuild done ─────────────────────────────────────────────────────────── */
.ncx-mode-rebuild-done {
    display:flex; align-items:center; gap:9px;
    padding:11px 14px; border-radius:9px;
    background:rgba(34,197,94,.08); border:1px solid rgba(34,197,94,.25);
    font-size:12px; color:#15803d; margin-top:10px;
}
.ncx-mode-rebuild-done strong { font-weight:700; }

/* ── Large switch ─────────────────────────────────────────────────────────── */
.ncx-switch--lg { width:54px; }
.ncx-switch--lg .ncx-slider { height:28px; }
.ncx-switch--lg .ncx-slider::before { width:20px; height:20px; top:4px; left:4px; }
.ncx-switch--lg input:checked + .ncx-slider::before { transform:translateX(26px); }

/* ══════════════════════════════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 1100px) {
    .ncx-hcc-layout { grid-template-columns: 1fr; }
}
@media (max-width: 782px) {
    .ncx-hcc-stats-row  { flex-wrap:wrap; }
    .ncx-hcc-actions    { flex-direction:column; }
    .ncx-stat-divider   { display:none; }
    .ncx-hcc-stat-cell  { flex:1 0 40%; }
    .ncx-mode-option    { flex-wrap:wrap; }
    .ncx-mode-option-badge { margin-left:0; }
    .ncx-hcc-mode-header { flex-direction:column; }
    .ncx-hcc-section-header--blue { padding:18px 20px; }
}

/* ══════════════════════════════════════════════════════════════════════════
   SSG MASTER ENABLE — SUB-HEADER ROW
═══════════════════════════════════════════════════════════════════════════ */
.ncx-ssg-enable-row {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 13px 16px;
    background: var(--ncx-bg-100, #F9FAFB);
    border: 1.5px solid var(--ncx-brand-border, #E5E7EB);
    border-radius: 12px;
    margin-bottom: 16px;
    transition: background .2s, border-color .2s;
}
.ncx-ssg-enable-row--on {
    background: rgba(16,185,129,.06);
    border-color: rgba(16,185,129,.28);
}
.ncx-ssg-enable-text { flex: 1; min-width: 0; }
.ncx-ssg-enable-text strong { display:block; font-size:13px; font-weight:700; color:var(--ncx-gray-900,#111); margin-bottom:2px; }
.ncx-ssg-enable-text span   { font-size:11px; color:var(--ncx-muted,#6B7280); line-height:1.4; }
.ncx-ssg-enable-ctrl { display:flex; align-items:center; gap:10px; flex-shrink:0; }

/* ── Panel styles are in admin.css — no overrides here ──────────────────── */

/* ══════════════════════════════════════════════════════════════════════════
   STEALTH LAYER — TIER ROWS (FREE TEASER)
═══════════════════════════════════════════════════════════════════════════ */
.ncx-stealth-tier-row {
    display:flex; align-items:flex-start; gap:12px;
    padding:14px 16px; border-radius:12px;
    margin-bottom:10px; border:1.5px solid transparent;
}
.ncx-stealth-tier-row--free { background:rgba(107,114,128,.05); border-color:rgba(107,114,128,.15); }
.ncx-stealth-tier-row--pro  { background:rgba(2,82,250,.04);    border-color:rgba(2,82,250,.18); }
.ncx-stealth-tier-icon { font-size:20px; flex-shrink:0; line-height:1; margin-top:2px; }
.ncx-stealth-tier-row strong { display:block; font-size:13px; font-weight:700; color:var(--ncx-gray-900,#111); margin-bottom:4px; }
.ncx-stealth-tier-row p      { font-size:11px; color:var(--ncx-muted,#6B7280); margin:0 0 6px; line-height:1.5; }

/* ── Stealth Proxy always-on row (Pro view) ──────────────────────────────── */
.ncx-stealth-proxy-row {
    display:flex; align-items:flex-start; gap:14px;
    padding:16px 24px 14px;
    background:rgba(2,82,250,.03);
    border-bottom:1px solid rgba(2,82,250,.1);
}
.ncx-stealth-proxy-icon { font-size:24px; flex-shrink:0; line-height:1; margin-top:2px; }
.ncx-stealth-proxy-info { flex:1; min-width:0; }
.ncx-stealth-proxy-info strong {
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    font-size:13px; font-weight:700; color:var(--ncx-gray-900,#111); margin-bottom:5px;
}
.ncx-stealth-proxy-info > span {
    display:block; font-size:11px; color:var(--ncx-muted,#6B7280); line-height:1.55; margin-bottom:6px;
}
.ncx-stealth-proxy-active-badge {
    display:inline-flex; align-items:center;
    padding:2px 9px; border-radius:10px;
    font-size:10px; font-weight:700; letter-spacing:.04em;
    background:rgba(16,185,129,.12); color:#059669;
    border:1px solid rgba(16,185,129,.25);
}

/* URL example code (Free + Pro views) */
.ncx-stealth-url-example {
    display:inline-block; font-size:10px;
    background:rgba(0,0,0,.05); padding:3px 8px;
    border-radius:5px; color:#374151;
    border:1px solid rgba(0,0,0,.07);
    word-break:break-all; max-width:100%;
}
.ncx-hcc-control-stack {
    min-width: 280px;
    justify-content: flex-start;
}
.ncx-hcc-control-stack .ncx-hcc-module-toggle {
    min-width: 280px;
    padding: 10px 12px 10px 14px;
    border-color: rgba(2,82,250,.24);
    background: #fff;
    box-shadow: 0 10px 24px rgba(2,82,250,.08);
}
.ncx-hcc-header-status-row {
    display: flex;
    align-items: center;
    gap: 0;
    flex-wrap: nowrap;
    margin-top: 10px;
    max-width: 780px;
    overflow: hidden;
    border: 1px solid #e1e8f2;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(15,23,42,.035);
}
.ncx-hcc-header-status-row .ncx-hcc-system-note,
.ncx-hcc-header-status-row .ncx-hcc-rebuild-note {
    width: auto;
    min-width: 0;
    flex: 1 1 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    padding: 8px 12px;
}
.ncx-hcc-header-status-row .ncx-hcc-system-note {
    border-right: 1px solid #e1e8f2;
}
.ncx-hcc-rebuild-note {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #e6ecf5;
    border-radius: 11px;
    background: #fff;
    box-shadow: 0 4px 14px rgba(15,23,42,.04);
}
.ncx-hcc-rebuild-note > .dashicons {
    width: 16px;
    height: 16px;
    margin-top: 1px;
    font-size: 16px;
}
.ncx-hcc-rebuild-note.is-automatic {
    border-color: rgba(16,185,129,.22);
    background: #f0fdf7;
}
.ncx-hcc-rebuild-note.is-automatic > .dashicons {
    color: #059669;
}
.ncx-hcc-rebuild-note.is-manual {
    border-color: rgba(2,82,250,.18);
    background: #f8fbff;
}
.ncx-hcc-rebuild-note.is-manual > .dashicons {
    color: #0252FA;
}
.ncx-hcc-rebuild-note strong {
    display: block;
    color: var(--ncx-gray-950,#111827);
    font-size: 11px;
    font-weight: 700;
    line-height: 1.25;
}
.ncx-hcc-rebuild-note small {
    display: block;
    margin-top: 1px;
    color: #667085;
    font-size: 10.5px;
    line-height: 1.25;
}
.ncx-hcc-layout {
    align-items: stretch !important;
}
.ncx-hcc-layout > .ncx-hcc-section-card {
    height: 100%;
}
.ncx-hcc-layout .ncx-hcc-section-body,
.ncx-hcc-teaser-body {
    flex: 1 1 auto;
}
.ncx-hcc-teaser-cta {
    margin-top: auto;
}
.ncx-hcc-section-body > .ncx-pill--pro,
.ncx-hcc-section-body > .ncx-pill--free {
    display: none !important;
}
.ncx-delivery-flow {
    grid-template-columns: 1fr !important;
    gap: 12px !important;
}
.ncx-delivery-flow-card {
    position: relative;
    padding: 16px 16px 16px 58px !important;
    border-color: #e1e8f2 !important;
    border-radius: 12px !important;
    background: linear-gradient(180deg,#fff 0%,#fbfdff 100%) !important;
}
.ncx-delivery-flow-card > .dashicons {
    position: absolute;
    left: 16px;
    top: 16px;
    width: 32px !important;
    height: 32px !important;
    border-radius: 9px !important;
}
.ncx-delivery-flow-card strong {
    font-size: 13px !important;
}
.ncx-delivery-flow-card p {
    max-width: 520px;
    font-size: 12px !important;
    line-height: 1.55 !important;
}
.ncx-stealth-proxy-row,
.ncx-hcc-ghost-row {
    border: 1px solid #e1e8f2;
    border-radius: 13px;
    background: #fff;
    margin: 0 0 12px;
}
.ncx-hcc-ghost-row {
    border-bottom: 1px solid #e1e8f2;
}
.ncx-hcc-hardening-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    margin: 0 0 14px;
}
.ncx-hcc-hardening-card {
    display: grid;
    grid-template-columns: 34px minmax(0,1fr);
    column-gap: 12px;
    align-items: start;
    padding: 14px;
    border: 1px solid #e1e8f2;
    border-radius: 12px;
    background: #fbfdff;
}
.ncx-hcc-hardening-card > .dashicons {
    grid-row: span 2;
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: rgba(2,82,250,.08);
    color: #0252FA;
    font-size: 17px;
}
.ncx-hcc-hardening-card strong {
    color: var(--ncx-gray-950,#111827);
    font-size: 12px;
    font-weight: 700;
}
.ncx-hcc-hardening-card p {
    margin: 3px 0 0;
    color: #667085;
    font-size: 11px;
    line-height: 1.5;
}
@media (max-width: 960px) {
    .ncx-hcc-control-stack {
        width: 100%;
        min-width: 0;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   HEADER STATS CHIPS
   Each mirror metric is a small bordered chip with icon + value + label.
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-hstat-chips {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.ncx-hcc-hchip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px 4px 8px;
    background: #f8fafc;
    border: 1px solid #eef2f7;
    border-radius: 8px;
    white-space: nowrap;
}
.ncx-hcc-hchip-icon {
    color: #94a3b8;
    flex-shrink: 0;
}
.ncx-hcc-hchip-val {
    font-size: 12px;
    font-weight: 700;
    color: #111827;
}
.ncx-hcc-hchip-lbl {
    font-size: 10px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.ncx-hcc-hchip-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.ncx-hcc-hchip--on { background: #f0fdf4; border-color: #bbf7d0; }
.ncx-hcc-hchip--on .ncx-hcc-hchip-lbl { color: #15803d; font-weight: 700; }
.ncx-hcc-hchip--on .ncx-hcc-hchip-dot { background: #16a34a; box-shadow: 0 0 0 2px rgba(22,163,74,.15); }
.ncx-hcc-hchip--off .ncx-hcc-hchip-dot { background: #94a3b8; }

/* ══════════════════════════════════════════════════════════════════════════
   FULL-WIDTH SINGLE-COLUMN LAYOUT
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-layout--single {
    grid-template-columns: 1fr !important;
}

/* ══════════════════════════════════════════════════════════════════════════
   STEALTH COLS — Proxy + Ghost side-by-side on full-width card
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-stealth-cols {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.ncx-hcc-stealth-cols > * {
    margin: 0 !important;
}

/* ══════════════════════════════════════════════════════════════════════════
   FREE TEASER FULL-WIDTH — 3-col feature list + inline CTA
═══════════════════════════════════════════════════════════════════════════ */
.ncx-hcc-stealth-teaser .ncx-hcc-teaser-list {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.ncx-hcc-stealth-teaser .ncx-hcc-teaser-list li {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    padding: 16px;
    background: var(--ncx-bg-100, #F9FAFB);
    border: 1px solid var(--ncx-brand-border, #E5E7EB);
    border-radius: 12px;
}
.ncx-hcc-stealth-teaser .ncx-hcc-teaser-list strong {
    font-size: 13px;
}
.ncx-hcc-stealth-teaser .ncx-hcc-teaser-list span {
    font-size: 11.5px;
    line-height: 1.55;
}
.ncx-hcc-stealth-teaser .ncx-hcc-teaser-cta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    text-align: left;
}
.ncx-hcc-stealth-teaser .ncx-hcc-upgrade-btn {
    width: auto !important;
    flex-shrink: 0;
    white-space: nowrap;
}
.ncx-hcc-stealth-teaser .ncx-hcc-teaser-cta-note {
    margin: 0;
    text-align: left;
}

@media (max-width: 900px) {
    .ncx-hcc-stealth-cols { grid-template-columns: 1fr; }
    .ncx-hcc-stealth-teaser .ncx-hcc-teaser-list { grid-template-columns: 1fr 1fr; }
    .ncx-hcc-stealth-teaser .ncx-hcc-teaser-cta { flex-direction: column; align-items: stretch; }
    .ncx-hcc-stealth-teaser .ncx-hcc-upgrade-btn { width: 100% !important; justify-content: center; }
}
@media (max-width: 600px) {
    .ncx-hcc-stealth-teaser .ncx-hcc-teaser-list { grid-template-columns: 1fr; }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
document.addEventListener('DOMContentLoaded', function () {

    var activeBtn    = document.querySelector('.ncx-mode-option--active');
    var currentMode  = activeBtn ? activeBtn.dataset.mode : 'direct';
    var proxyBtn     = document.querySelector('.ncx-mode-option--proxy');
    var impactNotice = document.getElementById('ncxModeImpact');

    if (proxyBtn && impactNotice) {
        proxyBtn.addEventListener('mouseenter', function () {
            if (currentMode !== 'proxy') impactNotice.style.display = 'flex';
        });
        proxyBtn.addEventListener('mouseleave', function () {
            if (!proxyBtn.classList.contains('ncx-mode-option--active')) {
                impactNotice.style.display = 'none';
            }
        });
        proxyBtn.addEventListener('focus', function () {
            if (currentMode !== 'proxy') impactNotice.style.display = 'flex';
        });
        proxyBtn.addEventListener('blur', function () {
            if (!proxyBtn.classList.contains('ncx-mode-option--active')) {
                impactNotice.style.display = 'none';
            }
        });
    }

    document.querySelectorAll('.ncx-mode-option').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var mode = this.dataset.mode;
            if (!mode || this.classList.contains('ncx-mode-option--active')) return;

            var modeTitle = mode === 'proxy'
                ? '<?php echo esc_js( __( 'Switch to Asset Proxy?', 'nexora-engine' ) ); ?>'
                : '<?php echo esc_js( __( 'Switch to Direct Mode?', 'nexora-engine' ) ); ?>';
            var modeBody = mode === 'proxy'
                ? '<p><?php echo esc_js( __( 'This will purge the static cache and rebuild all pages with cloaked /_ncx_v12/ URLs.', 'nexora-engine' ) ); ?></p><ul><li><?php echo esc_js( __( 'The rebuild takes 1–5 minutes and cannot be interrupted', 'nexora-engine' ) ); ?></li><li><?php echo esc_js( __( 'Visitors receive dynamic pages during the rebuild', 'nexora-engine' ) ); ?></li></ul>'
                : '<p><?php echo esc_js( __( 'This will purge the static cache and rebuild all pages with standard /wp-content/ URLs.', 'nexora-engine' ) ); ?></p><ul><li><?php echo esc_js( __( 'Visitors receive dynamic pages during the rebuild', 'nexora-engine' ) ); ?></li></ul>';
            var modeConfirmed = await ncxConfirmModal({
                title: modeTitle,
                body: modeBody,
                confirmText: '<?php echo esc_js( __( 'Yes, Switch Mode', 'nexora-engine' ) ); ?>',
                confirmClass: 'ncx-btn-primary',
                type: 'warning',
            });
            if (!modeConfirmed) return;

            document.querySelectorAll('.ncx-mode-option').forEach(function (b) { b.disabled = true; });
            document.querySelectorAll('.ncx-mode-option').forEach(function (b) { b.classList.remove('ncx-mode-option--active'); });
            this.classList.add('ncx-mode-option--active');

            var rebuildPanel   = document.getElementById('ncxModeRebuildPanel');
            var rebuildDone    = document.getElementById('ncxModeRebuildDone');
            var rebuildFill    = document.getElementById('ncxRebuildFill');
            var rebuildCount   = document.getElementById('ncxRebuildCount');
            var rebuildHeading = document.getElementById('ncxRebuildHeading');

            if (impactNotice) impactNotice.style.display = 'none';
            if (rebuildPanel) rebuildPanel.style.display = 'block';
            if (rebuildDone)  rebuildDone.style.display  = 'none';

            var res = await ncxCall('ssg_set_asset_mode', { mode: mode, nexeng_purge_confirmed: 1 });

            if (!res.success) {
                ncxToast(
                    (res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Failed to switch mode.', 'nexora-engine' ) ); ?>',
                    'error'
                );
                if (rebuildPanel) rebuildPanel.style.display = 'none';
                document.querySelectorAll('.ncx-mode-option').forEach(function (b) { b.disabled = false; });
                return;
            }

            if (!res.data.rebuilding) {
                if (rebuildPanel) rebuildPanel.style.display = 'none';
                // SSG may be off or nothing eligible to build — tell the user
                // they need to run Regenerate All manually so the page isn't
                // left with just "mode updated" and no guidance.
                var toastMsg = mode === 'proxy'
                    ? '<?php echo esc_js( __( 'Switched to Asset Proxy. Static cache cleared — use Build Control to rebuild.', 'nexora-engine' ) ); ?>'
                    : '<?php echo esc_js( __( 'Switched to Direct mode. Static cache cleared — use Build Control to rebuild.', 'nexora-engine' ) ); ?>';
                ncxToast(toastMsg, 'success');
                setTimeout(function () { location.reload(); }, 2000);
                return;
            }

            var total = res.data.total || 0;
            if (rebuildCount)   rebuildCount.textContent   = '0 / ' + total;
            if (rebuildHeading) rebuildHeading.textContent = '<?php echo esc_js( __( 'Rebuilding static cache…', 'nexora-engine' ) ); ?>';

            var retries = 0;
            var maxRetries = 12;
            var doneLabel = mode === 'proxy'
                ? '<?php echo esc_js( __( 'Asset Proxy active — all pages rebuilt with cloaked paths.', 'nexora-engine' ) ); ?>'
                : '<?php echo esc_js( __( 'Direct mode active — all pages rebuilt with fast paths.', 'nexora-engine' ) ); ?>';

            var poll = async function () {
                try {
                    var batch = await ncxCall('ssg_regen_all_batch');
                    if (batch.success) {
                        retries = 0;
                        var processed = batch.data.processed || 0;
                        var pct = total > 0 ? Math.round((processed / total) * 100) : 100;
                        if (rebuildFill)    rebuildFill.style.width    = pct + '%';
                        if (rebuildCount)   rebuildCount.textContent   = processed + ' / ' + total;
                        if (rebuildHeading) rebuildHeading.textContent = '<?php echo esc_js( __( 'Rebuilding…', 'nexora-engine' ) ); ?> ' + pct + '%';

                        if (batch.data.done) {
                            if (rebuildPanel) rebuildPanel.style.display = 'none';
                            if (rebuildDone) {
                                var doneMsg = document.getElementById('ncxRebuildDoneMsg');
                                if (doneMsg) doneMsg.textContent = processed + ' <?php echo esc_js( __( 'pages rebuilt successfully.', 'nexora-engine' ) ); ?>';
                                rebuildDone.style.display = 'flex';
                            }
                            ncxToast(doneLabel, 'success');
                            setTimeout(function () { location.reload(); }, 2500);
                        } else {
                            setTimeout(poll, 2000);
                        }
                    } else {
                        if (++retries < maxRetries) setTimeout(poll, 3000);
                        else {
                            ncxToast('<?php echo esc_js( __( 'Rebuild is running in the background. Reload to check status.', 'nexora-engine' ) ); ?>', 'info');
                            setTimeout(function () { location.reload(); }, 2000);
                        }
                    }
                } catch (e) {
                    if (++retries < maxRetries) setTimeout(poll, 3000);
                    else location.reload();
                }
            };

            setTimeout(poll, 2500);
        });
    });

    // ncx-toggle-auto reload is now handled globally in admin.js
    // (fires location.reload after 1200ms for ssg_enabled and headless_mode toggles).

    /* ── Regenerate All Pages — live progress panel ─────────────────────── */

});
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
