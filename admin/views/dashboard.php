<?php
/**
 * Nexora Engine - Status Dashboard View
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$dashboard = NEXENG_Dashboard::get_instance();
$stats = $dashboard->get_stats();

$is_pro             = NEXENG_Licence::is_pro();
$hit_ratio          = $stats['hit_ratio'];
$traffic_total_24h  = $stats['traffic_total_24h'] ?? 0;
$last_hit_at        = $stats['last_hit_at']        ?? null;
$static_files_count = $stats['static_files_count'];
$static_total_bytes = $stats['static_total_bytes'];
$last_regen         = $stats['last_regen'];
$pending_count      = (int) ( $stats['pending_count'] ?? 0 );
$build_running      = ! empty( $stats['build_running'] );
$build_processed    = (int) ( $stats['build_processed'] ?? 0 );
$build_total        = (int) ( $stats['build_total'] ?? 0 );
$ttfb_p50           = $stats['ttfb_p50'];
$ttfb_p95           = $stats['ttfb_p95'];
$ttfb_samples       = (int) ( $stats['ttfb_samples'] ?? 0 );
$vitals_samples     = $stats['vitals_samples'] ?? [ 'LCP' => 0, 'INP' => 0, 'CLS' => 0 ];
$vitals_method      = strtoupper( $stats['vitals_method'] ?? 'p75' );
$stuck_warning      = $stats['stuck_warning'];
$upgrade_url        = function_exists( 'NexoraEngine\\get_upgrade_url' )
    ? \NexoraEngine\get_upgrade_url( 'pro' )
    : 'https://auralogicslabs.com/nexora-engine/#pricing';
?>

<div class="ncx-dashboard-container">
    <div class="ncx-header ncx-dashboard-header">
        <div class="ncx-header-title">
            <h1>Nexora Engine Dashboard</h1>
            <p>Real-time performance insights and site optimization</p>
        </div>
    </div>

    <div class="ncx-dashboard-grid">
        <div class="ncx-metric-card">
            <div class="ncx-card-header">
                <div class="ncx-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM10 17L5 12L6.41 10.59L10 14.17L17.59 6.58L19 8L10 17Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="ncx-card-title">Cache Hit Ratio</div>
            </div>
            <?php if ( $traffic_total_24h > 0 ) : ?>
            <div class="ncx-metric-value"><?php echo esc_html( $hit_ratio ); ?>%</div>
            <div class="ncx-metric-subtitle"><?php echo esc_html( number_format( $traffic_total_24h ) ); ?> requests · last 24 h</div>
            <div class="ncx-metric-chart">
                <div class="ncx-progress-bar">
                    <div class="ncx-progress-fill" style="width: <?php echo esc_attr( $hit_ratio ); ?>%"></div>
                </div>
            </div>
            <?php elseif ( $last_hit_at ) : ?>
            <div class="ncx-metric-value" style="font-size:28px;color:#94a3b8;">—</div>
            <div class="ncx-metric-subtitle" style="color:#94a3b8;">No anonymous traffic today</div>
            <div class="ncx-metric-detail" style="color:#94a3b8;">Last data: <?php echo esc_html( human_time_diff( $last_hit_at, time() ) ); ?> ago</div>
            <?php else : ?>
            <div class="ncx-metric-value" style="font-size:28px;color:#94a3b8;">—</div>
            <div class="ncx-metric-subtitle" style="color:#94a3b8;">No traffic recorded yet</div>
            <div class="ncx-metric-detail">Logged-in browsing is excluded from metrics</div>
            <?php endif; ?>
        </div>

        <div class="ncx-metric-card">
            <div class="ncx-card-header">
                <div class="ncx-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM13 17H11V15H13V17ZM13 13H11V7H13V13Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="ncx-card-title">TTFB Performance</div>
            </div>
            <?php
            // Show TTFB only when real cache-hit samples exist.
            // Display "<1ms" for values of 0 or 1 (Windows microtime artefact — sub-ms
            // cache hits round to 0 on Windows; we floor to 1 so they appear in stats).
            // On production Linux servers real values like 3-8ms are typical.
            $ttfb_p50_label = $ttfb_p50 <= 1 ? '&lt;1' : $ttfb_p50;
            $ttfb_p95_label = $ttfb_p95 <= 1 ? '&lt;1' : $ttfb_p95;
            ?>
            <?php if ( $ttfb_samples > 0 && $ttfb_p50 >= 1 ) : ?>
            <div class="ncx-metric-value" title="<?php esc_attr_e( 'Server-side TTFB for the HTML document only — measured from cache-hit requests. Asset (CSS/JS/font) loading time is separate and reflected by LCP.', 'nexora-engine' ); ?>"><?php echo esc_html( $ttfb_p50_label ); ?>ms</div>
            <div class="ncx-metric-subtitle">P50: <?php echo esc_html( $ttfb_p50_label ); ?>ms | P95: <?php echo esc_html( $ttfb_p95_label ); ?>ms</div>
            <?php if ( $static_files_count === 0 ) : ?>
            <div class="ncx-metric-detail" style="color:#f59e0b" title="<?php esc_attr_e( 'These samples were collected during the previous build when static files existed. Rebuild the mirror to start collecting fresh measurements.', 'nexora-engine' ); ?>">From prior build · rebuild mirror to refresh <span style="cursor:help">ⓘ</span></div>
            <?php else : ?>
            <div class="ncx-metric-detail"><?php echo esc_html( number_format_i18n( $ttfb_samples ) ); ?> cache-hit samples &middot; 24h <span title="<?php esc_attr_e( 'Measures how fast the static HTML file was sent to the browser, not asset load time. LCP reflects full page render speed.', 'nexora-engine' ); ?>" style="cursor:help;opacity:.6;">ⓘ</span></div>
            <?php endif; ?>
            <?php else : ?>
            <div class="ncx-metric-value" style="font-size:28px;color:#94a3b8;">—</div>
            <div class="ncx-metric-subtitle" style="color:#94a3b8;">No cache-hit samples yet</div>
            <div class="ncx-metric-detail">Open the site as an anonymous visitor after building static pages</div>
            <?php endif; ?>
        </div>
        <div class="ncx-metric-card">
            <div class="ncx-card-header">
                <div class="ncx-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M13 3H6V21H18V8L13 3ZM16 18H8V17H16V18ZM16 15H8V14H16V15ZM16 12H8V11H16V12ZM12 9V4.5L16.5 9H12Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="ncx-card-title">Real-User Perf (CWV)</div>
            </div>
            <div class="ncx-vitals-list">
                <?php
                $v = $stats['vitals'];
                $lcp = $v['LCP'] ?? 0;
                $inp = $v['INP'] ?? 0;
                $cls = $v['CLS'] ?? 0;
                $lcp_samples = (int) ( $vitals_samples['LCP'] ?? 0 );
                $inp_samples = (int) ( $vitals_samples['INP'] ?? 0 );
                $cls_samples = (int) ( $vitals_samples['CLS'] ?? 0 );
                $total_vital_samples = $lcp_samples + $inp_samples + $cls_samples;
                $vitals_stale = $static_files_count === 0 && ( $lcp > 0 || $inp > 0 || $cls > 0 );
                ?>
                <div class="ncx-vital-item">
                    <span class="label">LCP</span>
                    <span class="value <?php echo esc_attr( $lcp_samples > 0 ? ( $lcp < 2500 ? 'good' : ( $lcp < 4000 ? 'meh' : 'poor' ) ) : 'empty' ); ?>"><?php echo $lcp_samples > 0 ? esc_html( number_format( $lcp ) ) . 'ms' : '-'; ?></span>
                </div>
                <div class="ncx-vital-item">
                    <span class="label">INP</span>
                    <span class="value <?php echo esc_attr( $inp_samples > 0 ? ( $inp < 200 ? 'good' : ( $inp < 500 ? 'meh' : 'poor' ) ) : 'empty' ); ?>"><?php echo $inp_samples > 0 ? esc_html( number_format( $inp ) ) . 'ms' : '-'; ?></span>
                </div>
                <div class="ncx-vital-item">
                    <span class="label">CLS</span>
                    <span class="value <?php echo esc_attr( $cls_samples > 0 ? ( $cls < 0.1 ? 'good' : ( $cls < 0.25 ? 'meh' : 'poor' ) ) : 'empty' ); ?>"><?php echo $cls_samples > 0 ? esc_html( $cls ) : '-'; ?></span>
                </div>
            </div>
            <?php if ( $vitals_stale ) : ?>
            <div class="ncx-metric-detail" style="color:#f59e0b;margin-top:6px;" title="<?php esc_attr_e( 'CWV samples were collected while the static mirror was live. Rebuild to start collecting fresh field data.', 'nexora-engine' ); ?>">From prior build · rebuild mirror to refresh <span style="cursor:help">ⓘ</span></div>
            <?php elseif ( $total_vital_samples > 0 ) : ?>
            <div class="ncx-metric-detail" style="margin-top:6px;"><?php echo esc_html( $vitals_method ); ?> field samples · 7d</div>
            <?php else : ?>
            <div class="ncx-metric-detail" style="margin-top:6px;">Collecting real-user samples</div>
            <?php endif; ?>
        </div>

        <div class="ncx-metric-card">
            <div class="ncx-card-header">
                <div class="ncx-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M14 2H6C4.9 2 4 2.9 4 4V20C4 21.1 4.9 22 6 22H18C19.1 22 20 21.1 20 20V8L14 2ZM16 18H8V16H16V18ZM16 14H8V12H16V14ZM12 10H8V8H12V10Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="ncx-card-title">Static Files</div>
            </div>
            <div class="ncx-metric-value"><?php echo esc_html( number_format( $static_files_count ) ); ?></div>
            <div class="ncx-metric-subtitle"><?php echo esc_html( size_format( $static_total_bytes ) ); ?> total</div>
            <div class="ncx-metric-detail">Last regen: <?php echo esc_html( $last_regen ); ?></div>
        </div>

        <div class="ncx-metric-card">
            <div class="ncx-card-header">
                <div class="ncx-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2L4 5.5V11C4 16.05 7.41 20.74 12 22C16.59 20.74 20 16.05 20 11V5.5L12 2ZM11 15.4L7.6 12L9 10.6L11 12.58L15.6 8L17 9.42L11 15.4Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="ncx-card-title">Mirror Freshness</div>
            </div>
            <?php if ( $build_running ) : ?>
            <div class="ncx-metric-value" style="font-size:28px;color:#0252FA;"><?php esc_html_e( 'Building', 'nexora-engine' ); ?></div>
            <div class="ncx-metric-subtitle"><?php echo esc_html( $build_processed ); ?> / <?php echo $build_total > 0 ? esc_html( $build_total ) : '-'; ?> <?php esc_html_e( 'items processed', 'nexora-engine' ); ?></div>
            <div class="ncx-metric-detail"><?php esc_html_e( 'Build Control is publishing the static mirror.', 'nexora-engine' ); ?></div>
            <?php elseif ( $pending_count > 0 ) : ?>
            <div class="ncx-metric-value" style="font-size:28px;color:#d97706;"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></div>
            <div class="ncx-metric-subtitle"><?php echo esc_html( _n( 'changed page waiting', 'changed pages waiting', $pending_count, 'nexora-engine' ) ); ?></div>
            <div class="ncx-metric-detail">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-headless' ) ); ?>" style="font-weight:600;color:#d97706;"><?php esc_html_e( '→ Open Build Control to deploy', 'nexora-engine' ); ?></a>
            </div>
            <?php else : ?>
            <div class="ncx-metric-value" style="font-size:28px;color:#059669;"><?php esc_html_e( 'Current', 'nexora-engine' ); ?></div>
            <div class="ncx-metric-subtitle"><?php esc_html_e( 'No focused updates waiting', 'nexora-engine' ); ?></div>
            <div class="ncx-metric-detail"><?php esc_html_e( 'Public mirror is aligned with tracked edits.', 'nexora-engine' ); ?></div>
            <?php endif; ?>
        </div>

        

        <?php if ( $stuck_warning ): ?>
        <div class="ncx-metric-card ncx-warning-card">
            <div class="ncx-card-header">
                <div class="ncx-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM13 17H11V15H13V17ZM13 13H11V7H13V13Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="ncx-card-title">Cache Status</div>
            </div>
            <div class="ncx-warning-text"><?php echo esc_html( $stuck_warning ); ?></div>
            <div class="ncx-metric-detail"><?php esc_html_e( 'Use Build Control to rebuild the static mirror.', 'nexora-engine' ); ?></div>
        </div>
        <?php endif; ?>

        <?php if ( $is_pro ) : ?>
        <div class="ncx-metric-card">
            <div class="ncx-card-header">
                <div class="ncx-card-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 1L3 5V11C3 16.55 6.84 21.74 12 23C17.16 21.74 21 16.55 21 11V5L12 1ZM12 11.99H19C18.47 16.11 15.72 19.78 12 20.93V11.99H5V6.3L12 3.19V11.99Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="ncx-card-title"><?php esc_html_e( 'Security Hardening', 'nexora-engine' ); ?></div>
            </div>
            <div class="ncx-metric-value"><?php echo esc_html( $stats['security_score'] ); ?>%</div>
            <div class="ncx-metric-subtitle"><?php echo esc_html( $stats['hardening_active'] ); ?> <?php esc_html_e( 'rules active', 'nexora-engine' ); ?></div>
            <div class="ncx-metric-detail"><a href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-security' ) ); ?>"><?php esc_html_e( 'Hardening Panel →', 'nexora-engine' ); ?></a></div>
        </div>
        <?php endif; ?>

        

    </div><!-- /.ncx-dashboard-grid -->

    <!-- ── Static Delivery CTA — always visible, guides new users to the command centre ── -->
    <div class="ncx-dashboard-cta ncx-static-delivery-cta">
        <div class="ncx-sdc-left">
            <div class="ncx-cta-eyebrow"><?php esc_html_e( 'Static Delivery', 'nexora-engine' ); ?></div>
            <h2><?php esc_html_e( 'Control how your site is served to visitors', 'nexora-engine' ); ?></h2>
            <p><?php esc_html_e( 'Enable static delivery, manage the build queue, configure WP Masking, and set your serve mode — all from the Static Delivery page.', 'nexora-engine' ); ?></p>
            <?php if ( $static_files_count > 0 ) : ?>
            <span class="ncx-sdc-stat"><?php echo esc_html( number_format( $static_files_count ) ); ?> <?php esc_html_e( 'pages live', 'nexora-engine' ); ?></span>
            <?php else : ?>
            <span class="ncx-sdc-stat ncx-sdc-stat--empty"><?php esc_html_e( 'No pages built yet', 'nexora-engine' ); ?></span>
            <?php endif; ?>
        </div>
        <div class="ncx-sdc-right">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-headless' ) ); ?>" class="ncx-btn ncx-btn-primary">
                <?php esc_html_e( 'Open Static Delivery →', 'nexora-engine' ); ?>
            </a>
        </div>
    </div>

    <?php if ( $is_pro ) : ?>
    <!-- Pro: SEO & Traffic CTA -->
    <div class="ncx-dashboard-cta">
        <div>
            <div class="ncx-cta-eyebrow"><?php esc_html_e( 'SEO & Traffic', 'nexora-engine' ); ?></div>
            <h2><?php esc_html_e( 'Review page-level traffic alongside SEO health in one unified report', 'nexora-engine' ); ?></h2>
            <p><?php esc_html_e( 'Traffic is tracked for public frontend pages only. Admin, capture, REST, and asset requests are excluded from reporting.', 'nexora-engine' ); ?></p>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-seo-report' ) ); ?>" class="ncx-btn ncx-btn-primary">
            <?php esc_html_e( 'Open SEO Report', 'nexora-engine' ); ?>
        </a>
    </div>
    <?php else : ?>
    <!-- Free: Pro feature grid — locked cards with clear upgrade path -->
    <div class="ncx-dashboard-pro-section">
        <div class="ncx-dashboard-pro-section-head">
            <div>
                <div class="ncx-pro-card-eyebrow"><?php esc_html_e( 'Nexora Pro', 'nexora-engine' ); ?></div>
                <h3 class="ncx-pro-section-title"><?php esc_html_e( 'Infrastructure features available on Pro', 'nexora-engine' ); ?></h3>
                <p class="ncx-pro-section-desc"><?php esc_html_e( 'Static delivery is active on Free. Upgrade to unlock automation, stealth, analytics, and advanced security.', 'nexora-engine' ); ?></p>
            </div>
            <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener" class="ncx-btn ncx-btn-upgrade">
                <?php esc_html_e( 'Upgrade to Pro →', 'nexora-engine' ); ?>
            </a>
        </div>
        <div class="ncx-pro-features-grid">
            <?php
            $pro_feature_cards = [
                [
                    'title' => __( 'Security Hardening', 'nexora-engine' ),
                    'desc'  => __( 'Advanced rules, scoring, and hardening panel', 'nexora-engine' ),
                    'icon'  => 'shield',
                ],
                [
                    'title' => __( 'WP Masking', 'nexora-engine' ),
                    'desc'  => __( 'Asset Proxy and full WordPress fingerprint removal', 'nexora-engine' ),
                    'icon'  => 'ghost',
                ],
                [
                    'title' => __( 'Auto-Build', 'nexora-engine' ),
                    'desc'  => __( 'Deploy static updates automatically on publish', 'nexora-engine' ),
                    'icon'  => 'auto',
                ],
                [
                    'title' => __( 'Content Analytics', 'nexora-engine' ),
                    'desc'  => __( 'Frontend traffic and page performance insight', 'nexora-engine' ),
                    'icon'  => 'chart',
                ],
                [
                    'title' => __( 'Core Web Vitals', 'nexora-engine' ),
                    'desc'  => __( 'LCP, INP, and CLS tracking in the dashboard', 'nexora-engine' ),
                    'icon'  => 'vitals',
                ],
                [
                    'title' => __( 'SEO Intelligence', 'nexora-engine' ),
                    'desc'  => __( 'On-page scoring and SEO reporting', 'nexora-engine' ),
                    'icon'  => 'seo',
                ],
            ];
            foreach ( $pro_feature_cards as $card ) :
            ?>
            <div class="ncx-pro-feature-card is-locked">
                <div class="ncx-pro-feature-card-icon ncx-pro-feature-card-icon--<?php echo esc_attr( $card['icon'] ); ?>" aria-hidden="true">
                    <?php if ( $card['icon'] === 'shield' ) : ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" fill="currentColor"/></svg>
                    <?php elseif ( $card['icon'] === 'ghost' ) : ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 2C7.03 2 3 6.03 3 11v9l2.5-1.5L8 20l2.5-1.5L13 20l2.5-1.5L18 20l3 2v-11c0-4.97-4.03-9-9-9z" fill="currentColor"/></svg>
                    <?php elseif ( $card['icon'] === 'auto' ) : ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46A7.93 7.93 0 0020 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74A7.93 7.93 0 004 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z" fill="currentColor"/></svg>
                    <?php elseif ( $card['icon'] === 'chart' ) : ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 13h2v8H3v-8zm4-6h2v14H7V7zm4 4h2v10h-2V11zm4-6h2v16h-2V5z" fill="currentColor"/></svg>
                    <?php elseif ( $card['icon'] === 'vitals' ) : ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M3 17h2v-4H3v4zm4 0h2V7H7v10zm4 0h2v-6h-2v6zm4 0h2v-9h-2v9zm4 0h2V4h-2v13z" fill="currentColor"/></svg>
                    <?php else : ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zM9.5 14C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" fill="currentColor"/></svg>
                    <?php endif; ?>
                </div>
                <div class="ncx-pro-feature-card-body">
                    <strong><?php echo esc_html( $card['title'] ); ?></strong>
                    <p><?php echo esc_html( $card['desc'] ); ?></p>
                </div>
                <span class="ncx-pro-feature-lock"><?php esc_html_e( 'Pro', 'nexora-engine' ); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php ob_start(); ?>
.ncx-dashboard-cta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin-top: 28px;
    padding: 24px 28px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}
.ncx-dashboard-cta h2 {
    margin: 4px 0 6px;
    font-size: 20px;
    line-height: 1.3;
}
.ncx-dashboard-cta p {
    margin: 0;
    color: var(--ncx-muted);
    font-size: 13px;
}
.ncx-cta-eyebrow {
    color: var(--ncx-green);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
@media (max-width: 782px) {
    .ncx-dashboard-cta {
        align-items: flex-start;
        flex-direction: column;
    }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

