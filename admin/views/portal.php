<?php
/**
 * Nexora Engine — Auralogics Portal Connectivity
 *
 * Connects this WordPress install to the auralogicslabs.com cloud portal
 * for centralized infrastructure management, reporting, and licensing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use NexoraEngine\Core\Features;
use NexoraEngine\Licensing\LicenseManager;

$tier        = LicenseManager::instance()->get_tier();
$is_pro      = Features::is_tier_or_above( 'pro' );
$portal_key        = get_option( 'nexeng_portal_key', '' );
$portal_site       = get_option( 'nexeng_portal_site_id', '' );
$portal_url        = defined( 'NEXORA_PORTAL_BASE' ) ? rtrim( NEXORA_PORTAL_BASE, '/' ) . '/portal' : 'https://auralogicslabs.com/portal';
$portal_connected_at = (int) get_option( 'nexeng_portal_connected', 0 );
// Connected via token handshake callback OR legacy prtl_ key flow.
$connected         = $portal_connected_at > 0
                  || ( ! empty( $portal_key ) && ! empty( $portal_site ) );

// Portal token for silent-connection handshake.
// get_connect_url() generates a fresh one-time token — only call it when not
// yet connected so we don't invalidate an active portal telemetry session.
$nexeng_token       = class_exists( 'NEXENG_Portal_API' ) ? NEXENG_Portal_API::get_token() : '';
$nexeng_connect_url = ( ! $connected && class_exists( 'NEXENG_Portal_API' ) )
                       ? NEXENG_Portal_API::get_connect_url()
                       : '';
$token_masked    = $nexeng_token ? ( substr( $nexeng_token, 0, 6 ) . str_repeat( '•', 26 ) ) : '—';
?>

<div class="ncx-header">
    <div class="ncx-header-title">
        <h1>Auralogics Portal</h1>
        <p>Connect this site to the Auralogics cloud for centralized infrastructure management. For plugin license activation, see <a href="<?php echo esc_url( admin_url( 'admin.php?page=ncx-updates' ) ); ?>">Version &amp; Licensing →</a></p>
    </div>
    <div class="ncx-header-actions">
        <?php if ( $connected ): ?>
            <span class="ncx-status-pill ncx-status-pill--active">
                <span class="ncx-status-dot"></span> Connected
            </span>
        <?php else: ?>
            <span class="ncx-status-pill ncx-status-pill--inactive">
                <span class="ncx-status-dot"></span> Not Connected
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if ( ! $is_pro ): ?>
<!-- Free-tier upgrade notice -->
<div class="ncx-card ncx-glass-card ncx-portal-upgrade" style="margin-bottom:24px; border-left:3px solid var(--ncx-blue);">
    <div class="ncx-card-body" style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
        <div class="ncx-card-icon" style="flex-shrink:0;">
            <span class="dashicons dashicons-cloud" style="font-size:28px; width:28px; height:28px; color:var(--ncx-blue);"></span>
        </div>
        <div style="flex:1; min-width:200px;">
            <strong style="display:block; font-size:15px; color:#1e293b; margin-bottom:4px;">Portal Connectivity requires Nexora Engine Pro</strong>
            <span style="font-size:13px; color:#64748b;">Upgrade to link this site to the Auralogics cloud dashboard and access centralized reporting, multi-site intelligence, and remote management.</span>
        </div>
        <a href="<?php echo esc_url( $portal_url . '/upgrade' ); ?>" target="_blank" class="ncx-btn ncx-btn-primary" style="flex-shrink:0;">
            Upgrade to Pro →
        </a>
    </div>
</div>
<?php endif; ?>

<div class="ncx-portal-grid">

    <!-- Connection Card -->
    <div class="ncx-card ncx-glass-card ncx-portal-connect-card <?php echo esc_attr( $connected ? 'ncx-active-card' : '' ); ?>">
        <div class="ncx-card-header">
            <div class="ncx-card-icon"><span class="dashicons dashicons-admin-network"></span></div>
            <h3>Site Connection</h3>
        </div>
        <div class="ncx-card-body">
            <?php if ( $connected ): ?>
                <div class="ncx-portal-connected-state">
                    <div class="ncx-status-indicator" style="color:#A96A06; font-weight:700; display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                        <span class="dashicons dashicons-yes-alt" style="font-size:20px; width:20px; height:20px;"></span>
                        <span>Site linked to Auralogics Portal</span>
                    </div>

                    <?php if ( $portal_connected_at > 0 ) : ?>
                    <div class="ncx-portal-info-row" style="background:var(--ncx-brand-offwhite); border-radius:10px; padding:12px 16px; margin-bottom:16px;">
                        <span style="display:block; font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin-bottom:4px;">Connected</span>
                        <code style="font-size:13px; color:#1e293b;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $portal_connected_at ) ); ?></code>
                    </div>
                    <?php endif; ?>

                    <?php if ( $portal_site ) : ?>
                    <div class="ncx-portal-info-row" style="background:var(--ncx-brand-offwhite); border-radius:10px; padding:12px 16px; margin-bottom:16px;">
                        <span style="display:block; font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin-bottom:4px;">Site ID</span>
                        <code style="font-size:13px; color:#1e293b;"><?php echo esc_html( $portal_site ); ?></code>
                    </div>
                    <?php endif; ?>

                    <?php if ( $portal_key ) : ?>
                    <div class="ncx-portal-info-row" style="background:var(--ncx-brand-offwhite); border-radius:10px; padding:12px 16px; margin-bottom:20px;">
                        <span style="display:block; font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin-bottom:4px;">Portal Key</span>
                        <code style="font-size:13px; color:#1e293b;">••••••••••••<?php echo esc_html( substr( $portal_key, -6 ) ); ?></code>
                    </div>
                    <?php endif; ?>

                    <?php if ( $nexeng_token ) : ?>
                    <details style="margin-bottom:16px;">
                        <summary style="font-size:12px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; cursor:pointer; list-style:none; display:flex; align-items:center; gap:6px;">
                            <span class="dashicons dashicons-lock" style="font-size:13px; width:13px; height:13px;"></span>
                            API Credential
                        </summary>
                        <div style="margin-top:10px; background:var(--ncx-brand-offwhite); border-radius:10px; padding:12px 16px;">
                            <span style="display:block; font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin-bottom:6px;">Site Token <span style="font-weight:400; text-transform:none; font-size:11px;">— used by the portal to authenticate REST API calls back to this site</span></span>
                            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                <code id="ncx-token-display" style="font-size:13px; color:#1e293b; flex:1;"><?php echo esc_html( $token_masked ); ?></code>
                                <button class="ncx-btn ncx-btn-outline" id="btn-regenerate-token" style="white-space:nowrap; font-size:12px; padding:6px 12px;">
                                    <span class="dashicons dashicons-update" style="font-size:13px; width:13px; height:13px; margin-right:4px; margin-top:2px;"></span>
                                    Regenerate
                                </button>
                            </div>
                        </div>
                    </details>
                    <?php endif; ?>

                    <div style="display:flex; gap:12px; margin-top:<?php echo esc_attr( ( $portal_site || $portal_key ) ? '0' : '8px' ); ?>;">
                        <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" class="ncx-btn ncx-btn-primary">
                            <span class="dashicons dashicons-external" style="font-size:14px; width:14px; height:14px; margin-right:6px; margin-top:2px;"></span>
                            Open Portal Dashboard
                        </a>
                        <button class="ncx-btn ncx-btn-outline" id="btn-disconnect-portal" <?php disabled( ! $is_pro ); ?>>
                            Disconnect
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <p class="ncx-p-muted" style="margin-bottom:20px;">
                    Connect this site to the <strong>Auralogics Portal</strong> to access centralized
                    infrastructure monitoring, cross-site reporting, and remote cache management.
                </p>

                <!-- Primary: silent connect via portal URL -->
                <a href="<?php echo esc_url( $nexeng_connect_url ?: '#' ); ?>"
                   target="_blank"
                   class="ncx-btn ncx-btn-primary ncx-btn-block <?php echo esc_attr( ! $is_pro ? 'ncx-btn-disabled' : '' ); ?>"
                   id="btn-connect-via-portal"
                   style="display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:12px;"
                   <?php
                   // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emits hardcoded HTML attributes (tabindex/aria-disabled) into the anchor tag for the Free tier; esc_attr would corrupt the embedded quotes. No dynamic data.
                   echo ! $is_pro ? 'tabindex="-1" aria-disabled="true"' : ''; ?>>
                    <span class="dashicons dashicons-cloud" style="font-size:16px; width:16px; height:16px;"></span>
                    Connect via Portal
                </a>

                <!-- Divider -->
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                    <div style="flex:1; height:1px; background:var(--ncx-border);"></div>
                    <span style="font-size:11px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">or connect manually</span>
                    <div style="flex:1; height:1px; background:var(--ncx-border);"></div>
                </div>

                <!-- Fallback: paste prtl_ key -->
                <div class="ncx-field-group" style="margin-bottom:12px;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.05em;">Portal API Key</label>
                    <input type="password"
                           id="ncx-portal-key-input"
                           class="ncx-input"
                           style="width:100%; padding:10px 14px; border:1px solid var(--ncx-border); border-radius:10px; font-size:14px;"
                           placeholder="prtl_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                           <?php echo esc_attr( ! $is_pro ? 'disabled' : '' ); ?>>
                </div>
                <button class="ncx-btn ncx-btn-outline ncx-btn-block"
                        id="btn-connect-portal"
                        <?php disabled( ! $is_pro ); ?>>
                    <span class="dashicons dashicons-admin-network" style="font-size:14px; width:14px; height:14px; margin-right:6px; margin-top:2px;"></span>
                    Connect with Key
                </button>
                <p style="margin-top:12px; font-size:12px; color:#94a3b8; text-align:center;">
                    Get a portal key at <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank">auralogicslabs.com/portal</a>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Capabilities Card -->
    <div class="ncx-card ncx-glass-card">
        <div class="ncx-card-header">
            <div class="ncx-card-icon"><span class="dashicons dashicons-chart-bar"></span></div>
            <h3>Portal Capabilities</h3>
        </div>
        <div class="ncx-card-body">
            <ul class="ncx-portal-features-list">
                <?php
                $capabilities = [
                    [ 'icon' => 'dashicons-chart-line', 'label' => 'Centralized performance monitoring', 'pro' => true ],
                    [ 'icon' => 'dashicons-networking',  'label' => 'Multi-site infrastructure map',     'pro' => true ],
                    [ 'icon' => 'dashicons-update',      'label' => 'Remote cache invalidation',         'pro' => true ],
                    [ 'icon' => 'dashicons-media-text',  'label' => 'Aggregated infrastructure reports', 'pro' => true ],
                    [ 'icon' => 'dashicons-bell',        'label' => 'Score regression alerts',           'pro' => true ],
                ];
                foreach ( $capabilities as $cap ):
                    $available = $is_pro;
                ?>
                <li class="ncx-portal-feature-item <?php echo esc_attr( $available ? '' : 'ncx-locked' ); ?>">
                    <span class="dashicons <?php echo esc_attr( $cap['icon'] ); ?>"></span>
                    <span><?php echo esc_html( $cap['label'] ); ?></span>
                    <?php if ( ! $available ): ?>
                        <span class="ncx-badge ncx-badge-locked" style="margin-left:auto;"><?php esc_html_e( 'Pro', 'nexora-engine' ); ?></span>
                    <?php else: ?>
                        <span class="dashicons dashicons-yes-alt" style="margin-left:auto; color:#A96A06; font-size:16px;"></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <?php /* Portal Status / Sync card removed — backend cloud-push not yet implemented.
              The "Open Portal Dashboard" link in the connection card is the single
              source of truth for live status; will reinstate this section when
              real-time site metrics push goes live. */ ?>

</div>

<?php ob_start(); ?>
.ncx-portal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-top: 24px;
}
.ncx-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.ncx-status-pill--active  { background: rgba(243,154,9,0.12); color: #A96A06; }
.ncx-status-pill--inactive { background: rgba(100,116,139,0.1); color: #64748b; }
.ncx-status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
}
.ncx-portal-features-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.ncx-portal-feature-item {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    color: #1e293b;
    font-weight: 500;
}
.ncx-portal-feature-item .dashicons { color: var(--ncx-blue); }
.ncx-portal-feature-item.ncx-locked { opacity: 0.5; }
.ncx-badge-locked {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 2px 8px;
    border-radius: 20px;
    background: rgba(100,116,139,0.1);
    color: #64748b;
}
.ncx-btn-disabled {
    opacity: 0.5;
    pointer-events: none;
    cursor: not-allowed;
}
@media (max-width: 900px) {
    .ncx-portal-grid { grid-template-columns: 1fr; }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
document.addEventListener('DOMContentLoaded', function() {

    // Silent connect via portal URL — just a regular link, no JS needed,
    // but prevent click when Pro is disabled.
    const connectViaPortal = document.getElementById('btn-connect-via-portal');
    if (connectViaPortal && connectViaPortal.classList.contains('ncx-btn-disabled')) {
        connectViaPortal.addEventListener('click', function(e) { e.preventDefault(); });
    }

    // Manual key connect
    const connectBtn = document.getElementById('btn-connect-portal');
    if (connectBtn) {
        connectBtn.addEventListener('click', async function() {
            const keyInput = document.getElementById('ncx-portal-key-input');
            const key = keyInput ? keyInput.value.trim() : '';
            if (!key) return ncxToast('Please enter your Portal API key.', 'warning');
            if (!key.startsWith('prtl_')) return ncxToast('Invalid key format. Portal keys begin with prtl_', 'error');

            ncxSetLoading(connectBtn, true);
            const res = await ncxCall('portal_connect', { key });
            if (res.success) {
                ncxToast('Site connected to Auralogics Portal!');
                setTimeout(() => location.reload(), 1200);
            } else {
                ncxToast(res.data?.message || 'Connection failed. Check your key and try again.', 'error');
                ncxSetLoading(connectBtn, false);
            }
        });
    }

    const disconnectBtn = document.getElementById('btn-disconnect-portal');
    if (disconnectBtn) {
        disconnectBtn.addEventListener('click', async function() {
            if (!confirm('Disconnect this site from the Auralogics Portal?')) return;
            ncxSetLoading(disconnectBtn, true);
            await ncxCall('portal_disconnect');
            ncxToast('Site disconnected from portal.');
            setTimeout(() => location.reload(), 1000);
        });
    }

    // Regenerate site token
    const regenBtn = document.getElementById('btn-regenerate-token');
    if (regenBtn) {
        regenBtn.addEventListener('click', async function() {
            if (!confirm('Regenerate the site token? Any existing portal connection will need to be re-established.')) return;
            ncxSetLoading(regenBtn, true);
            const res = await ncxCall('regenerate_portal_token');
            if (res.success) {
                const display = document.getElementById('ncx-token-display');
                if (display) display.textContent = res.data.masked;
                ncxToast('Site token regenerated. Reconnect this site via the portal.');
            } else {
                ncxToast('Failed to regenerate token.', 'error');
            }
            ncxSetLoading(regenBtn, false);
        });
    }
});
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
