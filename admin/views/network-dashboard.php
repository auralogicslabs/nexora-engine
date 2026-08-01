<?php
/**
 * Nexora Engine — Network Fleet Dashboard
 * Rendered in wp-admin/network/ by NEXENG_Network_Admin::render_fleet_page().
 *
 * $is_pro      — bool (from controller)
 * $upgrade_url — string (from controller)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$total_sites = get_blog_count();
// NEXENG_Multisite ships only in the Pro build; class_exists keeps this view
// safe even if it is ever reached without it.
$fleet       = ( $is_pro && class_exists( 'NEXENG_Multisite' ) ) ? NEXENG_Multisite::get_fleet_summary() : [];

$fleet_ssg_active  = 0;
$fleet_files       = 0;
$fleet_bytes       = 0;
foreach ( $fleet as $s ) {
    if ( $s['ssg_enabled'] ) $fleet_ssg_active++;
    $fleet_files += (int) $s['file_count'];
    $fleet_bytes += (int) $s['total_bytes'];
}

$nonce = wp_create_nonce( 'nexeng_admin_nonce' );
$ajax  = admin_url( 'admin-ajax.php' );
?>
<div class="ncx-fleet-wrap">

    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="ncx-header ncx-fleet-header">
        <div class="ncx-header-title">
            <h1 style="display:flex;align-items:center;gap:10px;">
                Nexora Fleet
                <span class="ncx-fleet-badge">NETWORK</span>
            </h1>
            <p>Infrastructure orchestration across your WordPress multisite network</p>
        </div>
        <?php if ( $is_pro ) : ?>
        <div class="ncx-header-actions">
            <div class="ncx-quick-actions">
                <button class="ncx-btn ncx-btn-outline" id="ncx-net-regen-all">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M12 7A5 5 0 112 4.5M2 2v2.5H4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Regenerate All Sites
                </button>
                <button class="ncx-btn ncx-btn-outline" id="ncx-net-enable-all">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M7 1v6M4 3L2 5l2 2M10 3l2 2-2 2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Enable SSG Network-Wide
                </button>
                <button class="ncx-btn ncx-btn-outline ncx-btn-danger" id="ncx-net-disable-all">Disable SSG All Sites</button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( ! $is_pro ) : ?>
    <!-- ── Pro upgrade CTA ─────────────────────────────────────────────── -->
    <div class="ncx-fleet-upgrade">
        <div class="ncx-fleet-upgrade-inner">
            <div class="ncx-fleet-upgrade-icon">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none"><circle cx="28" cy="28" r="28" fill="#EBF1FF"/><path d="M28 12L40 19V33L28 40L16 33V19L28 12Z" fill="#0252FA" opacity=".15"/><path d="M28 18L36 22.5V31.5L28 36L20 31.5V22.5L28 18Z" fill="#0252FA"/><circle cx="28" cy="28" r="4" fill="white"/></svg>
            </div>
            <div class="ncx-fleet-upgrade-body">
                <div class="ncx-fleet-upgrade-eyebrow">Pro Feature</div>
                <h2>Multisite Fleet Orchestration</h2>
                <p>Manage SSG, cache, and regeneration across every site in your network from one dashboard. Enable, disable, and monitor all <?php echo esc_html( $total_sites ); ?> sites simultaneously.</p>
                <ul class="ncx-fleet-feature-list">
                    <li>✓ Fleet overview: all sites, SSG status, file counts, TTFB</li>
                    <li>✓ One-click enable/disable SSG network-wide</li>
                    <li>✓ Centralized regeneration queue for all sites</li>
                    <li>✓ Per-site SSG toggle from the network dashboard</li>
                    <li>✓ Network-wide cache invalidation</li>
                    <li>✓ Fleet analytics: aggregate performance across the network</li>
                </ul>
            </div>
            <div class="ncx-fleet-upgrade-action">
                <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" class="ncx-btn ncx-btn-primary ncx-btn-lg">
                    Upgrade to Pro →
                </a>
                <p class="ncx-fleet-upgrade-note">Includes Ghost Protocol, SEO intelligence, and fleet controls</p>
            </div>
        </div>
    </div>

    <!-- Read-only network overview for non-Pro -->
    <div class="ncx-fleet-readonly-notice">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="#0252FA" stroke-width="1.3"/><path d="M8 5v1M8 7.5v4" stroke="#0252FA" stroke-width="1.3" stroke-linecap="round"/></svg>
        <p>Network overview (read-only) — <?php echo esc_html( $total_sites ); ?> sites detected on this network.</p>
    </div>

    <?php else : ?>
    <!-- ── Fleet metrics row ───────────────────────────────────────────────── -->
    <div class="ncx-fleet-metrics">
        <div class="ncx-fleet-metric">
            <div class="ncx-fleet-metric-icon">🌐</div>
            <div class="ncx-fleet-metric-val"><?php echo esc_html( number_format( $total_sites ) ); ?></div>
            <div class="ncx-fleet-metric-label">Total Sites</div>
        </div>
        <div class="ncx-fleet-metric-divider"></div>
        <div class="ncx-fleet-metric">
            <div class="ncx-fleet-metric-icon">⚡</div>
            <div class="ncx-fleet-metric-val"><?php echo esc_html( $fleet_ssg_active ); ?></div>
            <div class="ncx-fleet-metric-label">SSG Active</div>
        </div>
        <div class="ncx-fleet-metric-divider"></div>
        <div class="ncx-fleet-metric">
            <div class="ncx-fleet-metric-icon">📄</div>
            <div class="ncx-fleet-metric-val"><?php echo esc_html( number_format( $fleet_files ) ); ?></div>
            <div class="ncx-fleet-metric-label">Cached Pages</div>
        </div>
        <div class="ncx-fleet-metric-divider"></div>
        <div class="ncx-fleet-metric">
            <div class="ncx-fleet-metric-icon">💾</div>
            <div class="ncx-fleet-metric-val"><?php echo esc_html( size_format( $fleet_bytes ) ); ?></div>
            <div class="ncx-fleet-metric-label">Cache Size</div>
        </div>
    </div>

    <!-- ── Site table ──────────────────────────────────────────────────────── -->
    <div class="ncx-fleet-table-wrap">
        <div class="ncx-fleet-table-header">
            <h2 class="ncx-fleet-table-title">Sites</h2>
            <button class="ncx-btn ncx-btn-sm ncx-btn-outline" id="ncx-fleet-refresh">
                <svg width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M11.5 6.5A5 5 0 112 4M2 2v2.5H4.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Refresh
            </button>
        </div>
        <table class="ncx-fleet-table" id="ncx-fleet-table">
            <thead>
                <tr>
                    <th>Site</th>
                    <th>URL</th>
                    <th>SSG</th>
                    <th>Cached Pages</th>
                    <th>Cache Size</th>
                    <th>Last Regen</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $fleet as $site ) :
                $last_regen = $site['last_regen']
                    ? human_time_diff( $site['last_regen'] ) . ' ago'
                    : 'Never';
            ?>
            <tr data-blog-id="<?php echo esc_attr( $site['blog_id'] ); ?>">
                <td>
                    <strong><?php echo esc_html( $site['name'] ); ?></strong>
                    <span class="ncx-fleet-site-id">#<?php echo esc_html( $site['blog_id'] ); ?></span>
                </td>
                <td>
                    <a href="<?php echo esc_url( $site['home_url'] ); ?>" target="_blank" class="ncx-fleet-url">
                        <?php echo esc_html( rtrim( $site['home_url'], '/' ) ); ?>
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M2 8L8 2M5 2h3v3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                </td>
                <td>
                    <label class="ncx-fleet-toggle">
                        <input type="checkbox" class="ncx-site-ssg-toggle"
                            data-blog-id="<?php echo esc_attr( $site['blog_id'] ); ?>"
                            <?php checked( $site['ssg_enabled'] ); ?>>
                        <span class="ncx-fleet-toggle-slider"></span>
                    </label>
                </td>
                <td><?php echo esc_html( number_format( $site['file_count'] ) ); ?></td>
                <td><?php echo esc_html( size_format( $site['total_bytes'] ) ); ?></td>
                <td><?php echo esc_html( $last_regen ); ?></td>
                <td>
                    <a href="<?php echo esc_url( get_admin_url( $site['blog_id'], 'admin.php?page=ncx-dashboard' ) ); ?>"
                        class="ncx-btn ncx-btn-xs ncx-btn-outline">Manage →</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $fleet ) ) : ?>
            <tr><td colspan="7" class="ncx-fleet-empty">No sites found or none have SSG enabled yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div><!-- /.ncx-fleet-wrap -->

<?php ob_start(); ?>
/* ── Fleet wrap ────────────────────────────────────────────────────────────── */
.ncx-fleet-wrap{max-width:1200px;margin:20px 0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0e1f3f}
.ncx-fleet-header{margin-bottom:24px}
.ncx-fleet-badge{
    display:inline-flex;align-items:center;padding:3px 9px;
    background:linear-gradient(135deg,#0252FA,#063CE6);color:#fff;
    font-size:11px;font-weight:700;letter-spacing:.06em;border-radius:4px;
}
/* ── Upgrade CTA ───────────────────────────────────────────────────────────── */
.ncx-fleet-upgrade{
    background:linear-gradient(135deg,#0252FA,#0631A0);
    border-radius:2px;padding:3px;margin-bottom:24px;
}
.ncx-fleet-upgrade-inner{
    display:flex;align-items:flex-start;gap:28px;
    padding:32px 36px;
    background:linear-gradient(135deg,#EBF1FF 0%,#F5F8FF 100%);
    border-radius:0px;flex-wrap:wrap;
}
.ncx-fleet-upgrade-icon{flex-shrink:0}
.ncx-fleet-upgrade-body{flex:1;min-width:280px}
.ncx-fleet-upgrade-eyebrow{
    font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
    color:#0252FA;margin-bottom:8px;
}
.ncx-fleet-upgrade-body h2{font-size:22px;font-weight:800;color:#0e1f3f;margin:0 0 10px;letter-spacing:-.02em}
.ncx-fleet-upgrade-body p{margin:0 0 16px;font-size:15px;color:#4b5e7a;line-height:1.65}
.ncx-fleet-feature-list{margin:0;padding:0 0 0 18px;list-style:none}
.ncx-fleet-feature-list li{font-size:14px;color:#2d5fc9;margin-bottom:5px;padding-left:0}
.ncx-fleet-feature-list li::before{content:'';display:none}
.ncx-fleet-upgrade-action{flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:8px;align-self:center}
.ncx-fleet-upgrade-note{margin:0;font-size:12px;color:#7d8fa8;text-align:center}
.ncx-fleet-readonly-notice{
    display:flex;align-items:center;gap:10px;
    padding:12px 18px;background:#EBF1FF;border:1.5px solid #c7d7fd;
    border-radius:10px;font-size:14px;color:#1e3a5f;margin-bottom:24px;
}
.ncx-fleet-readonly-notice p{margin:0}
/* ── Fleet metrics ─────────────────────────────────────────────────────────── */
.ncx-fleet-metrics{
    display:flex;justify-content:space-between;align-items:center;
    background:#fff;border:1.5px solid #eef2f8;border-radius:18px;
    overflow:hidden;margin-bottom:28px;
    box-shadow:0 2px 16px rgba(2,82,250,.06);
}
.ncx-fleet-metric{flex:1;text-align:center;padding:24px 16px}
.ncx-fleet-metric-divider{width:1.5px;align-self:stretch;background:#eef2f8}
.ncx-fleet-metric-icon{font-size:24px;margin-bottom:6px;line-height:1}
.ncx-fleet-metric-val{font-size:34px;font-weight:800;color:#0252FA;line-height:1;letter-spacing:-.03em}
.ncx-fleet-metric-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#7d8fa8;margin-top:5px}
/* ── Site table ─────────────────────────────────────────────────────────────── */
.ncx-fleet-table-wrap{
    background:#fff;border:1.5px solid #eef2f8;border-radius:16px;overflow:hidden;
    box-shadow:0 2px 16px rgba(2,82,250,.06);
}
.ncx-fleet-table-header{
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 24px;border-bottom:1px solid #eef2f8;
}
.ncx-fleet-table-title{margin:0;font-size:16px;font-weight:700;color:#0e1f3f}
.ncx-fleet-table{width:100%;border-collapse:collapse}
.ncx-fleet-table thead th{
    padding:12px 18px;text-align:left;
    font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
    color:#94a3b8;background:#FAFBFF;border-bottom:1px solid #eef2f8;
}
.ncx-fleet-table tbody td{
    padding:14px 18px;border-bottom:1px solid #f5f7fb;
    font-size:14px;color:#1e3a5f;vertical-align:middle;
}
.ncx-fleet-table tbody tr:last-child td{border-bottom:none}
.ncx-fleet-table tbody tr:hover td{background:#FAFBFF}
.ncx-fleet-site-id{font-size:11px;color:#94a3b8;margin-left:6px}
.ncx-fleet-url{
    display:inline-flex;align-items:center;gap:4px;
    color:#0252FA;text-decoration:none;font-size:13px;
}
.ncx-fleet-url:hover{text-decoration:underline}
.ncx-fleet-empty{text-align:center;color:#94a3b8;padding:40px!important;font-size:14px}
/* ── Fleet toggle ───────────────────────────────────────────────────────────── */
.ncx-fleet-toggle{display:inline-flex;cursor:pointer;align-items:center}
.ncx-fleet-toggle input{position:absolute;opacity:0;width:0;height:0}
.ncx-fleet-toggle-slider{
    width:38px;height:22px;background:#dde4ef;border-radius:11px;
    transition:background .2s;position:relative;display:inline-block;
}
.ncx-fleet-toggle-slider::after{
    content:'';position:absolute;top:3px;left:3px;
    width:16px;height:16px;border-radius:50%;background:#fff;
    transition:transform .2s;box-shadow:0 1px 4px rgba(0,0,0,.15);
}
.ncx-fleet-toggle input:checked + .ncx-fleet-toggle-slider{background:#0252FA}
.ncx-fleet-toggle input:checked + .ncx-fleet-toggle-slider::after{transform:translateX(16px)}
/* ── Buttons ─────────────────────────────────────────────────────────────── */
.ncx-btn-xs{padding:5px 12px!important;font-size:12px!important;border-radius:7px!important}
.ncx-btn-danger{color:#ef4444!important;border-color:#fecaca!important}
.ncx-btn-danger:hover{background:#fef2f2!important;border-color:#ef4444!important}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
(function(){
    const ajax  = <?php echo wp_json_encode( $ajax ); ?>;
    const nonce = <?php echo wp_json_encode( $nonce ); ?>;

    function ncxNetCall(action, extra) {
        return fetch(ajax, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'nexeng_' + action, nonce, ...extra }),
        }).then(r => r.json());
    }

    function toast(msg, type) {
        if (typeof ncxToast === 'function') { ncxToast(msg, type); return; }
        alert(msg);
    }

    <?php if ( $is_pro ) : ?>
    // Network-wide controls
    document.getElementById('ncx-net-enable-all')?.addEventListener('click', async () => {
        if (!confirm('Enable SSG on all sites in this network?')) return;
        const res = await ncxNetCall('network_enable_ssg');
        toast(res.success ? res.data.message : (res.data?.message || 'Error'), res.success ? 'success' : 'error');
        if (res.success) setTimeout(() => location.reload(), 1500);
    });

    document.getElementById('ncx-net-disable-all')?.addEventListener('click', async () => {
        if (!confirm('Disable SSG on ALL sites and remove the drop-in cache?')) return;
        const res = await ncxNetCall('network_disable_ssg');
        toast(res.success ? res.data.message : (res.data?.message || 'Error'), res.success ? 'success' : 'error');
        if (res.success) setTimeout(() => location.reload(), 1500);
    });

    document.getElementById('ncx-net-regen-all')?.addEventListener('click', async () => {
        if (!confirm('Queue regeneration on all active SSG sites?')) return;
        const res = await ncxNetCall('network_regenerate_all');
        toast(res.success ? res.data.message : (res.data?.message || 'Error'), res.success ? 'success' : 'error');
    });

    document.getElementById('ncx-fleet-refresh')?.addEventListener('click', () => location.reload());

    // Per-site SSG toggles
    document.querySelectorAll('.ncx-site-ssg-toggle').forEach(toggle => {
        toggle.addEventListener('change', async function() {
            const blogId = this.dataset.blogId;
            const enable = this.checked ? '1' : '0';
            this.disabled = true;
            const res = await ncxNetCall('network_site_toggle_ssg', { blog_id: blogId, enable });
            this.disabled = false;
            toast(res.success ? res.data.message : (res.data?.message || 'Toggle failed'), res.success ? 'success' : 'error');
            if (!res.success) { this.checked = !this.checked; } // revert on error
        });
    });
    <?php endif; ?>
})();
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
