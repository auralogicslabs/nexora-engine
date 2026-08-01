<?php
/**
 * Nexora Engine — Tools & Maintenance
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_pro = NEXENG_Licence::is_pro();

// ── Licence recovery state (Pro only) ────────────────────────────────────────
if ( $is_pro ) {
    $_env       = \NexoraEngine\Licensing\Environment::current();
    $_plan      = \NexoraEngine\Licensing\FeatureGate::get_plan();
    $_cache     = \NexoraEngine\Licensing\EntitlementCache::get();
    $_grace     = \NexoraEngine\Licensing\GracePeriod::is_active();
    $_dev_on    = \NexoraEngine\Licensing\DevOverrides::is_active();
    $_fs_ok     = \NexoraEngine\Licensing\FreemiusAdapter::instance()->is_available();
    $_allow_dev = \NexoraEngine\Licensing\Environment::allows_dev_tools();

    $_cache_age  = $_cache  ? \NexoraEngine\Licensing\EntitlementCache::age_seconds()   : -1;
    $_cache_ttl  = \NexoraEngine\Licensing\EntitlementCache::active_ttl();
    $_grace_secs = $_grace  ? \NexoraEngine\Licensing\GracePeriod::seconds_remaining()  : 0;

    // Source label — no vendor names exposed
    if ( $_dev_on && \NexoraEngine\Licensing\DevOverrides::get_plan() !== null ) {
        $_plan_source = 'Dev mode (simulated)';
    } elseif ( $_fs_ok ) {
        $_plan_source = 'Live verification';
    } elseif ( $_cache ) {
        $_plan_source = 'Cached locally';
    } elseif ( $_grace ) {
        $_plan_source = 'Grace period';
    } else {
        $_plan_source = 'Default free';
    }

    $_sync_url = wp_nonce_url(
        add_query_arg( 'nexeng_sync', '1', admin_url( 'admin.php?page=ncx-updates' ) ),
        'nexeng_sync_license'
    );
}
?>

<div class="ncx-header">
    <div class="ncx-header-title">
        <h1><?php esc_html_e( 'Maintenance & Utilities', 'nexora-engine' ); ?></h1>
        <p><?php esc_html_e( 'Advanced tools to keep your static engine and security hardening in peak condition.', 'nexora-engine' ); ?></p>
    </div>
</div>

<!-- ── Top tool cards ───────────────────────────────────────────────────────── -->
<div class="ncx-tools-grid">

    <!-- System Status -->
    <?php
    $_ssg_stats  = class_exists( 'NEXENG_SSG' ) ? NEXENG_SSG::get_instance()->stats() : [];
    $_page_count = (int) ( $_ssg_stats['total_files'] ?? 0 ); // stats() key is total_files, not total_pages
    $_mirror_sz  = size_format( (int) ( $_ssg_stats['total_bytes'] ?? 0 ) );
    $_ssg_on     = class_exists( 'NEXENG_SSG' ) && NEXENG_SSG::is_enabled();
    ?>
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-card-header">
            <div class="ncx-card-icon"><span class="dashicons dashicons-performance"></span></div>
            <h3><?php esc_html_e( 'System Status', 'nexora-engine' ); ?></h3>
        </div>
        <div class="ncx-card-body">
            <table class="ncx-status-table">
                <tr>
                    <td><?php esc_html_e( 'PHP', 'nexora-engine' ); ?></td>
                    <td><span class="ncx-state-tag ncx-state-tag--warm"><?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ); ?></span></td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'WordPress', 'nexora-engine' ); ?></td>
                    <td><span class="ncx-state-tag ncx-state-tag--warm"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Static Delivery', 'nexora-engine' ); ?></td>
                    <td>
                        <span class="ncx-state-tag <?php echo esc_attr( $_ssg_on ? 'ncx-state-tag--warm' : 'ncx-state-tag--off' ); ?>">
                            <?php echo $_ssg_on ? esc_html__( 'Active', 'nexora-engine' ) : esc_html__( 'Off', 'nexora-engine' ); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Static Pages', 'nexora-engine' ); ?></td>
                    <td><strong><?php echo esc_html( $_page_count > 0 ? number_format_i18n( $_page_count ) : '—' ); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Mirror Size', 'nexora-engine' ); ?></td>
                    <td><strong><?php echo esc_html( $_page_count > 0 ? $_mirror_sz : '—' ); ?></strong></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Rewrite Rules -->
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-card-header">
            <div class="ncx-card-icon"><span class="dashicons dashicons-admin-links"></span></div>
            <h3><?php esc_html_e( 'Rewrite Rules', 'nexora-engine' ); ?></h3>
        </div>
        <div class="ncx-card-body">
            <p class="ncx-p-muted"><?php esc_html_e( 'If your sitemap.xml, custom login URL, or static paths stop resolving, flush the permalink cache to rebuild them.', 'nexora-engine' ); ?></p>
            <div class="ncx-btn-group-vertical">
                <button type="button" class="ncx-btn ncx-btn-block ncx-tool-action" data-action="flush_permalinks">
                    <span class="dashicons dashicons-admin-links" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                    <?php esc_html_e( 'Flush Rewrite Rules', 'nexora-engine' ); ?>
                </button>
                <button type="button" class="ncx-btn ncx-btn-block ncx-btn-outline" id="btn-run-diagnostic">
                    <span class="dashicons dashicons-search" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                    <?php esc_html_e( 'Run Diagnostic', 'nexora-engine' ); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Data Management -->
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-card-header">
            <div class="ncx-card-icon"><span class="dashicons dashicons-database"></span></div>
            <h3><?php esc_html_e( 'Data Management', 'nexora-engine' ); ?></h3>
        </div>
        <div class="ncx-card-body">
            <p class="ncx-p-muted"><?php esc_html_e( 'Export your configuration for backup or migration, or purge legacy analytics data to keep your database lean.', 'nexora-engine' ); ?></p>
            <div class="ncx-btn-group-vertical">
                <button type="button" class="ncx-btn ncx-btn-block ncx-tool-action" data-action="export_settings">
                    <span class="dashicons dashicons-download" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                    <?php esc_html_e( 'Export Configuration', 'nexora-engine' ); ?>
                </button>
                <button type="button" class="ncx-btn ncx-btn-block ncx-btn-outline ncx-tool-action"
                        data-action="purge_analytics"
                        data-confirm="<?php esc_attr_e( 'Wipe all analytics history? This cannot be undone.', 'nexora-engine' ); ?>">
                    <span class="dashicons dashicons-trash" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                    <?php esc_html_e( 'Purge Analytics Data', 'nexora-engine' ); ?>
                </button>
            </div>
        </div>
    </div>

</div><!-- /.ncx-tools-grid -->

<!-- ── Licence Recovery — Pro only ──────────────────────────────────────────── -->
<?php if ( $is_pro ) : ?>
<div class="ncx-card ncx-glass-card ncx-recovery-card" id="ncx-recovery-panel">
    <div class="ncx-card-header">
        <div class="ncx-card-icon ncx-card-icon--pro">
            <span class="dashicons dashicons-shield-alt"></span>
        </div>
        <div style="flex:1;">
            <?php
            // Environment badge — clearly labelled as a *server* indicator (not a plan badge).
            $_env_tip = '';
            if ( $_env === 'local' ) {
                $_env_tip = __( 'Server detected as a local development install (LocalWP, MAMP, XAMPP, .local TLD, etc). Local installs don\'t count against your license quota.', 'nexora-engine' );
            } elseif ( $_env === 'staging' ) {
                $_env_tip = __( 'Server detected as a staging/preview environment. Staging installs don\'t count against your production license quota — they\'re free.', 'nexora-engine' );
            } else {
                $_env_tip = __( 'Server detected as a production environment. This install counts against your license quota.', 'nexora-engine' );
            }
            ?>
            <h3 style="margin:0 0 3px;">
                <?php esc_html_e( 'Licence Recovery', 'nexora-engine' ); ?>
                <span class="ncx-env-badge ncx-env-badge--<?php echo esc_attr( $_env ); ?>"
                      title="<?php echo esc_attr( $_env_tip ); ?>"
                      style="cursor:help;">
                    <span style="opacity:.6;font-weight:600;">ENV ·</span>
                    <?php echo esc_html( \NexoraEngine\Licensing\Environment::label() ); ?>
                </span>
            </h3>
            <p style="margin:0;font-size:12px;color:var(--ncx-muted);">
                <?php esc_html_e( 'Use when your plan badge appears incorrect or after a network interruption.', 'nexora-engine' ); ?>
            </p>
        </div>
    </div>

    <div class="ncx-card-body">
        <div class="ncx-recovery-grid">

            <!-- State panel -->
            <div id="ncx-recovery-state-block">
                <h4 class="ncx-recovery-section-label"><?php esc_html_e( 'Current State', 'nexora-engine' ); ?></h4>
                <table class="ncx-state-table">
                    <tr>
                        <td><?php esc_html_e( 'Active plan', 'nexora-engine' ); ?></td>
                        <td>
                            <span class="ncx-plan-pill ncx-plan-pill--<?php echo esc_attr( $_plan ); ?>">
                                <?php echo esc_html( strtoupper( $_plan ) ); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Verified via', 'nexora-engine' ); ?></td>
                        <td><?php echo esc_html( $_plan_source ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Environment', 'nexora-engine' ); ?></td>
                        <td><?php echo esc_html( ucfirst( $_env ) ); ?></td>
                    </tr>
                    <tr>
                        <td>
                            <?php esc_html_e( 'Local cache', 'nexora-engine' ); ?>
                            <span title="<?php esc_attr_e( 'Licence entitlement cache — stores the plan/features locally so the licence server does not need to be contacted on every page load. Empty is normal on a fresh installation or after the cache expires.', 'nexora-engine' ); ?>" style="cursor:help;opacity:.6;font-size:11px;"> ⓘ</span>
                        </td>
                        <td>
                            <?php if ( $_cache_age >= 0 ) : ?>
                                <?php echo esc_html( round( $_cache_age / 60, 1 ) ); ?> min old
                                <span class="ncx-state-tag ncx-state-tag--warm"><?php esc_html_e( 'warm', 'nexora-engine' ); ?></span>
                            <?php else : ?>
                                — <span class="ncx-state-tag ncx-state-tag--cold"><?php esc_html_e( 'empty', 'nexora-engine' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Offline grace', 'nexora-engine' ); ?></td>
                        <td>
                            <?php if ( $_grace ) : ?>
                                <span class="ncx-state-tag ncx-state-tag--warm">
                                    <?php echo esc_html( round( $_grace_secs / 3600, 1 ) . ' h remaining' ); ?>
                                </span>
                            <?php else : ?>
                                <span class="ncx-state-tag ncx-state-tag--off"><?php esc_html_e( 'not active', 'nexora-engine' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Licence server', 'nexora-engine' ); ?></td>
                        <td>
                            <?php if ( $_fs_ok ) : ?>
                                <span class="ncx-state-tag ncx-state-tag--warm"><?php esc_html_e( 'reachable', 'nexora-engine' ); ?></span>
                            <?php else : ?>
                                <span class="ncx-state-tag ncx-state-tag--off"><?php esc_html_e( 'unreachable', 'nexora-engine' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $_dev_on ) : ?>
                    <tr>
                        <td><?php esc_html_e( 'Dev mode', 'nexora-engine' ); ?></td>
                        <td><span class="ncx-state-tag ncx-state-tag--dev"><?php esc_html_e( 'ACTIVE', 'nexora-engine' ); ?></span></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Actions panel -->
            <div class="ncx-recovery-actions">
                <h4 class="ncx-recovery-section-label"><?php esc_html_e( 'Recovery Actions', 'nexora-engine' ); ?></h4>
                <div class="ncx-btn-group-vertical">

                    <button type="button" class="ncx-btn ncx-btn-block ncx-tool-action"
                            data-action="licensing_clear_cache">
                        <span class="dashicons dashicons-trash" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                        <?php esc_html_e( 'Clear local licence cache', 'nexora-engine' ); ?>
                    </button>

                    <a href="<?php echo esc_url( $_sync_url ); ?>"
                       class="ncx-btn ncx-btn-block ncx-btn-outline">
                        <span class="dashicons dashicons-update" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                        <?php esc_html_e( 'Force licence re-sync', 'nexora-engine' ); ?>
                    </a>

                    <button type="button" class="ncx-btn ncx-btn-block ncx-btn-outline"
                            id="ncx-btn-refresh-state">
                        <span class="dashicons dashicons-visibility" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                        <?php esc_html_e( 'Refresh state display', 'nexora-engine' ); ?>
                    </button>

                    <?php if ( $_dev_on ) : ?>
                    <?php /* "Reset dev state" visible only when NEXORA_DEV_MODE is explicitly active.
                             Staging domains alone do NOT show it — regular clients test on staging
                             but don't need developer sandbox controls. */ ?>
                    <button type="button" class="ncx-btn ncx-btn-block ncx-btn-outline ncx-tool-action"
                            data-action="licensing_reset_sandbox"
                            data-confirm="<?php esc_attr_e( 'Clear all entitlement transients and reset dev state? Use this to simulate a fresh install in dev/staging only.', 'nexora-engine' ); ?>"
                            style="border-color:#f59e0b;color:#92400e;">
                        <span class="dashicons dashicons-warning" style="font-size:14px;vertical-align:middle;margin-right:5px;"></span>
                        <?php esc_html_e( 'Reset dev state', 'nexora-engine' ); ?>
                        <span class="ncx-dev-chip">DEV</span>
                    </button>
                    <?php endif; ?>

                </div>

                <p class="ncx-recovery-hint">
                    <?php esc_html_e( '"Clear cache" removes the locally stored plan and forces a live re-check on next page load. "Force re-sync" re-fetches directly from the activation server. Use re-sync when the plan badge is wrong after checkout.', 'nexora-engine' ); ?>
                </p>
            </div>

        </div><!-- /.ncx-recovery-grid -->
    </div><!-- /.ncx-card-body -->
</div>
<?php endif; ?>

<!-- ── Diagnostic backdrop + right-side drawer ───────────────────────────────── -->
<div class="ncx-diag-backdrop" id="ncx-diag-backdrop"></div>

<div class="ncx-diag-drawer" id="ncx-diag-drawer" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'System Diagnostic', 'nexora-engine' ); ?>">

    <div class="ncx-diag-drawer-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="dashicons dashicons-chart-bar" style="color:#0252FA;font-size:20px;width:20px;height:20px;"></span>
            <div>
                <h3 style="margin:0;font-size:15px;font-weight:700;color:#111827;"><?php esc_html_e( 'System Diagnostic', 'nexora-engine' ); ?></h3>
                <p style="margin:0;font-size:11px;color:var(--ncx-muted);"><?php esc_html_e( 'Live performance verification', 'nexora-engine' ); ?></p>
            </div>
        </div>
        <button type="button" class="ncx-diag-drawer-close" id="ncx-diag-close" title="<?php esc_attr_e( 'Close', 'nexora-engine' ); ?>">
            <span class="dashicons dashicons-no-alt"></span>
        </button>
    </div>

    <div class="ncx-diag-drawer-body">
        <div id="ncx-diagnostic-content">
            <!-- content injected by JS -->
        </div>
    </div>

</div>

<?php ob_start(); ?>
/* ── Grid ────────────────────────────────────────────────────────────────────── */
.ncx-tools-grid         { display:grid; grid-template-columns:repeat(3,1fr); gap:24px; margin-top:28px; }
.ncx-btn-group-vertical { display:flex; flex-direction:column; gap:12px; margin-top:18px; }
.ncx-btn-block          { width:100%; justify-content:center; font-weight:700; border-radius:10px; }

/* ── System Status table ─────────────────────────────────────────────────────── */
.ncx-status-table                         { width:100%; border-collapse:collapse; font-size:13px; margin-top:8px; }
.ncx-status-table td                      { padding:7px 10px; border-bottom:1px solid var(--ncx-brand-border,#E5E7EB); vertical-align:middle; }
.ncx-status-table td:first-child          { color:var(--ncx-muted); font-size:12px; width:50%; }
.ncx-status-table tr:last-child td        { border-bottom:none; }

/* ── Licence recovery card ───────────────────────────────────────────────────── */
.ncx-recovery-card      { margin-top:24px; }
.ncx-card-icon--pro     { background:linear-gradient(135deg,rgba(2,82,250,.12),rgba(99,102,241,.18)); color:#0252FA; }
.ncx-recovery-grid      { display:grid; grid-template-columns:1fr 1fr; gap:28px; }
.ncx-recovery-section-label {
    margin:0 0 12px; font-size:11px; font-weight:700;
    text-transform:uppercase; letter-spacing:.06em; color:var(--ncx-muted);
}
.ncx-recovery-hint { margin-top:14px; font-size:11px; color:var(--ncx-muted); line-height:1.6; }

/* ── State table ─────────────────────────────────────────────────────────────── */
.ncx-state-table            { width:100%; border-collapse:collapse; font-size:13px; }
.ncx-state-table td         { padding:8px 10px; border-bottom:1px solid var(--ncx-brand-border,#E5E7EB); vertical-align:middle; }
.ncx-state-table td:first-child { color:var(--ncx-muted); width:44%; font-size:12px; }
.ncx-state-table tr:last-child td { border-bottom:none; }

/* ── State tags ──────────────────────────────────────────────────────────────── */
.ncx-state-tag          { font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; display:inline-block; }
.ncx-state-tag--warm    { background:#d1fae5; color:#065f46; }
.ncx-state-tag--cold    { background:#f3f4f6; color:#6b7280; }
.ncx-state-tag--off     { background:#f3f4f6; color:#9ca3af; }
.ncx-state-tag--dev     { background:#fef3c7; color:#92400e; }

/* ── Plan pill ───────────────────────────────────────────────────────────────── */
.ncx-plan-pill          { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; letter-spacing:.04em; }
.ncx-plan-pill--pro     { background:#0252FA; color:#fff; }
.ncx-plan-pill--agency  { background:#7c3aed; color:#fff; }
.ncx-plan-pill--free    { background:#e5e7eb; color:#374151; }

/* ── Environment badge ───────────────────────────────────────────────────────── */
.ncx-env-badge          { font-size:10px; font-weight:700; padding:2px 8px; border-radius:10px; text-transform:uppercase; letter-spacing:.04em; margin-left:8px; vertical-align:middle; }
.ncx-env-badge--local   { background:#fef3c7; color:#92400e; }
.ncx-env-badge--staging { background:#e0e7ff; color:#3730a3; }
.ncx-env-badge--production { background:#d1fae5; color:#065f46; }

/* ── Dev chip ────────────────────────────────────────────────────────────────── */
.ncx-dev-chip { font-size:10px; background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:6px; margin-left:6px; font-weight:700; }

/* ── Diagnostic drawer ───────────────────────────────────────────────────────── */
.ncx-diag-backdrop {
    position: fixed;
    inset: var(--wp-admin--admin-bar--height, 32px) 0 0 0;
    background: rgba(17,24,39,.28);
    backdrop-filter: blur(2px);
    z-index: 100090;
    opacity: 0;
    pointer-events: none;
    transition: opacity .25s ease;
}
.ncx-diag-backdrop.is-open { opacity:1; pointer-events:all; }

.ncx-diag-drawer {
    position: fixed;
    top: var(--wp-admin--admin-bar--height, 32px);
    right: 0;
    width: 500px;
    max-width: 96vw;
    height: calc(100vh - var(--wp-admin--admin-bar--height, 32px));
    background: #fff;
    box-shadow: -6px 0 40px rgba(6,60,230,.13);
    border-left: 1px solid rgba(6,60,230,.08);
    z-index: 100100;
    transform: translateX(100%);
    transition: transform .32s cubic-bezier(.4,0,.2,1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.ncx-diag-drawer.is-open { transform:translateX(0); }

.ncx-diag-drawer-header {
    position: sticky;
    top: 0;
    background: #fff;
    border-bottom: 1px solid rgba(6,60,230,.08);
    padding: 16px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    z-index: 1;
}

.ncx-diag-drawer-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    border-radius: 8px;
    color: #6b7280;
    line-height: 1;
    display: flex;
    align-items: center;
    transition: background .15s, color .15s;
}
.ncx-diag-drawer-close:hover { background:#f3f4f6; color:#111827; }
.ncx-diag-drawer-close .dashicons { font-size:18px; width:18px; height:18px; }

.ncx-diag-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px 22px 24px;
}

/* Spinner animation for loading state */
.ncx-diag-spinner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
    gap: 16px;
    color: var(--ncx-muted);
}
.ncx-diag-spinner .dashicons {
    font-size: 36px; width:36px; height:36px; color:#0252FA;
    animation: ncx-spin 1.4s linear infinite;
}
.ncx-diag-spinner p { margin:0; font-size:13px; }

/* ── Diagnostic result card inside drawer ─────────────────────────────────────── */
.ncx-diag-verdict-block {
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 20px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
}
.ncx-diag-verdict-block.is-good  { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1px solid #6ee7b7; }
.ncx-diag-verdict-block.is-warn  { background:linear-gradient(135deg,#fff7ed,#ffedd5); border:1px solid #fbbf24; }
.ncx-diag-verdict-icon { font-size:22px; width:22px; height:22px; flex-shrink:0; margin-top:1px; }
.ncx-diag-verdict-block.is-good .ncx-diag-verdict-icon { color:#059669; }
.ncx-diag-verdict-block.is-warn .ncx-diag-verdict-icon { color:#d97706; }
.ncx-diag-verdict-text h4 { margin:0 0 4px; font-size:14px; font-weight:700; }
.ncx-diag-verdict-block.is-good .ncx-diag-verdict-text h4 { color:#065f46; }
.ncx-diag-verdict-block.is-warn .ncx-diag-verdict-text h4 { color:#92400e; }
.ncx-diag-verdict-text p  { margin:0; font-size:12px; line-height:1.6; color:inherit; opacity:.85; }

.ncx-diag-section-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .07em; color: var(--ncx-muted);
    margin: 18px 0 8px;
}
.ncx-diag-section-title:first-child { margin-top:0; }

.ncx-diag-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 4px;
    background: #f9fafb;
}
.ncx-diag-row:hover { background:#f3f4f6; }
.ncx-diag-label { color:var(--ncx-muted); font-size:12px; }
.ncx-diag-val   { font-weight:600; font-size:12px; }
.ncx-diag-val.ok   { color:#059669; }
.ncx-diag-val.warn { color:#d97706; }
.ncx-diag-val.err  { color:#dc2626; }

.ncx-diag-probe-note {
    font-size:11px; color:var(--ncx-muted); line-height:1.6;
    background:#f9fafb; border-radius:8px; padding:10px 12px;
    margin-bottom:16px;
    border-left:3px solid rgba(6,60,230,.15);
}

/* ── Responsive ──────────────────────────────────────────────────────────────── */
@media (max-width:900px) {
    .ncx-tools-grid    { grid-template-columns:1fr; }
    .ncx-recovery-grid { grid-template-columns:1fr; }
    .ncx-diag-drawer   { width:100vw; }
}

@media (max-width:782px) {
    .ncx-diag-backdrop {
        inset: 46px 0 0 0;
    }

    .ncx-diag-drawer {
        top: 46px;
        height: calc(100vh - 46px);
    }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
document.addEventListener('DOMContentLoaded', function () {

    // ── Export configuration — JSON file download ─────────────────────────────
    var exportBtn = document.querySelector('[data-action="export_settings"]');
    if (exportBtn) {
        exportBtn.addEventListener('click', async function (e) {
            e.stopPropagation();
            ncxSetLoading(exportBtn, true);
            var res = await ncxCall('export_settings');
            ncxSetLoading(exportBtn, false);
            if (res && res.success && res.data && res.data.config) {
                try {
                    var json  = JSON.stringify(res.data.config, null, 2);
                    var blob  = new Blob([json], { type: 'application/json' });
                    var url   = URL.createObjectURL(blob);
                    var a     = document.createElement('a');
                    a.href     = url;
                    a.download = 'nexora-config-' + new Date().toISOString().slice(0,10) + '.json';
                    a.style.display = 'none';
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 150);
                    ncxToast('<?php echo esc_js( __( 'Configuration exported successfully.', 'nexora-engine' ) ); ?>');
                } catch (err) {
                    ncxToast('<?php echo esc_js( __( 'Download failed — check browser permissions.', 'nexora-engine' ) ); ?>', 'error');
                }
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message
                        : '<?php echo esc_js( __( 'Export failed. Please try again.', 'nexora-engine' ) ); ?>';
                ncxToast(msg, 'error');
            }
        });
    }

    // ── Neural Diagnostic — right-side drawer ─────────────────────────────────
    var diagBtn      = document.getElementById('btn-run-diagnostic');
    var diagDrawer   = document.getElementById('ncx-diag-drawer');
    var diagBackdrop = document.getElementById('ncx-diag-backdrop');
    var diagClose    = document.getElementById('ncx-diag-close');
    var diagContent  = document.getElementById('ncx-diagnostic-content');

    function openDrawer() {
        if (!diagDrawer) return;
        diagDrawer.classList.add('is-open');
        if (diagBackdrop) diagBackdrop.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        diagDrawer.setAttribute('aria-hidden', 'false');
    }
    function closeDrawer() {
        if (!diagDrawer) return;
        diagDrawer.classList.remove('is-open');
        if (diagBackdrop) diagBackdrop.classList.remove('is-open');
        document.body.style.overflow = '';
        diagDrawer.setAttribute('aria-hidden', 'true');
    }

    if (diagClose)    diagClose.addEventListener('click', closeDrawer);
    if (diagBackdrop) diagBackdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });

    var diagRunning = false;
    async function runDiagnostic(button) {
        if (!diagContent) return;
        if (diagRunning) {
            openDrawer();
            return;
        }
        diagRunning = true;
        if (button) button.disabled = true;
        try {
            openDrawer();
            if (button) ncxSetLoading(button, true);

            diagContent.innerHTML =
                '<div class="ncx-diag-spinner">'
                + '<span class="dashicons dashicons-update"></span>'
                + '<p><?php echo esc_js( __( 'Running system scan…', 'nexora-engine' ) ); ?></p>'
                + '</div>';

            var res = await ncxCall('wizard_check_diag');
            if (res && res.success) {
                diagContent.innerHTML = res.data.html;
            } else {
                diagContent.innerHTML =
                    '<div style="padding:18px;background:#FEF2F2;color:#991b1b;border-radius:10px;border:1px solid rgba(239,68,68,.2);font-size:13px;">'
                    + '<?php echo esc_js( __( 'Diagnostic engine failed to respond. Please try again.', 'nexora-engine' ) ); ?></div>';
            }
        } finally {
            diagRunning = false;
            if (button) ncxSetLoading(button, false);
            if (button) button.disabled = false;
        }
    }

    if (diagBtn && diagContent) {
        diagBtn.addEventListener('click', function () {
            runDiagnostic(diagBtn);
        });
    }

    document.querySelectorAll('.ncx-run-diagnostic-global').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!diagDrawer || !diagContent) return;
            e.preventDefault();
            runDiagnostic(btn);
        });
    });

    var shouldOpenDiagnostic =
        window.location.hash === '#run-diagnostic'
        || new URLSearchParams(window.location.search).get('nexeng_open_diag') === '1';
    if (shouldOpenDiagnostic && diagContent) {
        setTimeout(function () {
            runDiagnostic(diagBtn);
        }, 150);
    }

    <?php if ( $is_pro ) : ?>
    // ── Licence recovery: refresh state display ───────────────────────────────
    var refreshBtn = document.getElementById('ncx-btn-refresh-state');
    var stateBlock = document.getElementById('ncx-recovery-state-block');

    if (refreshBtn && stateBlock) {
        refreshBtn.addEventListener('click', async function () {
            ncxSetLoading(refreshBtn, true);
            var res = await ncxCall('licensing_get_state');
            if (res && res.success) {
                var d = res.data;
                var sourceLabel = d.dev_override   ? '<?php echo esc_js( __( 'Dev mode (simulated)', 'nexora-engine' ) ); ?>'
                    : ( d.fs_available             ? '<?php echo esc_js( __( 'Live verification', 'nexora-engine' ) ); ?>'
                    : ( d.cache_plan               ? '<?php echo esc_js( __( 'Cached locally', 'nexora-engine' ) ); ?>'
                    : ( d.grace_active             ? '<?php echo esc_js( __( 'Grace period', 'nexora-engine' ) ); ?>'
                                                   : '<?php echo esc_js( __( 'Default free', 'nexora-engine' ) ); ?>' ) ) );
                var cacheInfo  = d.cache_plan
                    ? (Math.round(d.cache_age) + ' min <span class="ncx-state-tag ncx-state-tag--warm"><?php echo esc_js( __( 'warm', 'nexora-engine' ) ); ?></span>')
                    : '— <span class="ncx-state-tag ncx-state-tag--cold"><?php echo esc_js( __( 'empty', 'nexora-engine' ) ); ?></span>';
                var graceInfo  = d.grace_active
                    ? '<span class="ncx-state-tag ncx-state-tag--warm">' + (Math.round(d.grace_seconds/3600*10)/10) + ' h <?php echo esc_js( __( 'remaining', 'nexora-engine' ) ); ?></span>'
                    : '<span class="ncx-state-tag ncx-state-tag--off"><?php echo esc_js( __( 'not active', 'nexora-engine' ) ); ?></span>';
                var serverInfo = d.fs_available
                    ? '<span class="ncx-state-tag ncx-state-tag--warm"><?php echo esc_js( __( 'reachable', 'nexora-engine' ) ); ?></span>'
                    : '<span class="ncx-state-tag ncx-state-tag--off"><?php echo esc_js( __( 'unreachable', 'nexora-engine' ) ); ?></span>';
                var devRow = d.dev_override
                    ? '<tr><td><?php echo esc_js( __( 'Dev mode', 'nexora-engine' ) ); ?></td><td><span class="ncx-state-tag ncx-state-tag--dev">ACTIVE</span></td></tr>'
                    : '';
                var planClass = (d.plan === 'pro' || d.plan === 'agency') ? 'pro' : 'free';

                stateBlock.innerHTML =
                    '<h4 class="ncx-recovery-section-label"><?php echo esc_js( __( 'Current State', 'nexora-engine' ) ); ?></h4>'
                    + '<table class="ncx-state-table">'
                    + '<tr><td><?php echo esc_js( __( 'Active plan', 'nexora-engine' ) ); ?></td>'
                    + '<td><span class="ncx-plan-pill ncx-plan-pill--' + planClass + '">' + d.plan.toUpperCase() + '</span></td></tr>'
                    + '<tr><td><?php echo esc_js( __( 'Verified via', 'nexora-engine' ) ); ?></td><td>' + sourceLabel + '</td></tr>'
                    + '<tr><td><?php echo esc_js( __( 'Environment', 'nexora-engine' ) ); ?></td><td>' + d.environment.charAt(0).toUpperCase() + d.environment.slice(1) + '</td></tr>'
                    + '<tr><td><?php echo esc_js( __( 'Local cache', 'nexora-engine' ) ); ?></td><td>' + cacheInfo + '</td></tr>'
                    + '<tr><td><?php echo esc_js( __( 'Offline grace', 'nexora-engine' ) ); ?></td><td>' + graceInfo + '</td></tr>'
                    + '<tr><td><?php echo esc_js( __( 'Licence server', 'nexora-engine' ) ); ?></td><td>' + serverInfo + '</td></tr>'
                    + devRow + '</table>';

                ncxToast('<?php echo esc_js( __( 'State refreshed.', 'nexora-engine' ) ); ?>');
            } else {
                ncxToast('<?php echo esc_js( __( 'Failed to fetch licence state.', 'nexora-engine' ) ); ?>', 'error');
            }
            ncxSetLoading(refreshBtn, false);
        });
    }
    <?php endif; ?>

});
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
