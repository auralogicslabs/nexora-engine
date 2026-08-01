<?php
/**
 * Nexora Engine — System Settings
 *
 * Centralised configuration: General, Analytics, SEO Engine.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_pro = NEXENG_Licence::is_pro();

$tabs = [
    'general'   => 'General',
    'analytics' => 'Analytics',
    'seo'       => 'SEO Engine',
];
?>

<div class="ncx-header">
    <div class="ncx-header-title">
        <h1><?php esc_html_e( 'Global Settings', 'nexora-engine' ); ?></h1>
        <p><?php esc_html_e( 'Fine-tune Nexora Engine behavior for your site.', 'nexora-engine' ); ?></p>
    </div>
</div>

<div class="ncx-settings-layout">

    <!-- Sidebar Navigation -->
    <aside class="ncx-settings-sidebar">
        <div class="ncx-tabs-nav">
            <?php foreach ( $tabs as $id => $label ) : ?>
            <button type="button"
                    class="ncx-tab-trigger <?php echo esc_attr( $id === 'general' ? 'active' : '' ); ?>"
                    data-target="<?php echo esc_attr( $id ); ?>">
                <?php echo esc_html( $label ); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- Content Area -->
    <main class="ncx-settings-main">
        <form id="ncx-settings-form">
            <?php wp_nonce_field( 'nexeng_dashboard', 'nonce' ); ?>

            <!-- Tab: General -->
            <div id="ncx-tab-general" class="ncx-tab-content active">
                <div class="ncx-card ncx-glass-card">
                    <h3><?php esc_html_e( 'Engine Behaviour', 'nexora-engine' ); ?></h3>
                    <p class="ncx-p-muted" style="margin:-4px 0 20px;">
                        <?php esc_html_e( 'Global settings that apply across the entire plugin. Page-specific options (SSG, Stealth) live on their respective pages.', 'nexora-engine' ); ?>
                    </p>
                    <div class="ncx-settings-group">
                        <div class="ncx-setting-row">
                            <div class="ncx-setting-info">
                                <span class="label"><?php esc_html_e( 'Admin Bar Cache Indicator', 'nexora-engine' ); ?></span>
                                <span class="desc"><?php esc_html_e( 'Show a live build-status badge in the WordPress admin bar — including pending-page count and build progress.', 'nexora-engine' ); ?></span>
                            </div>
                            <label class="ncx-switch">
                                <input type="checkbox" name="settings[nexeng_admin_bar_badge]" <?php checked( get_option( 'nexeng_admin_bar_badge', 'on' ), 'on' ); ?>>
                                <span class="ncx-slider"></span>
                            </label>
                        </div>
                        <div class="ncx-setting-row ncx-setting-row--stacked">
                            <div class="ncx-setting-info">
                                <span class="label"><?php esc_html_e( 'HTTP Basic Auth (Staging)', 'nexora-engine' ); ?></span>
                                <span class="desc"><?php esc_html_e( 'Optional. Only needed when your staging URL is behind a browser login popup (WPMU DEV, Kinsta, etc.). Enter credentials during the Setup Wizard — this field is for later edits on production sites.', 'nexora-engine' ); ?></span>
                            </div>
                            <div class="ncx-http-auth-fields">
                                <input type="text"
                                       name="settings[nexeng_http_auth_user]"
                                       value="<?php echo esc_attr( get_option( 'nexeng_http_auth_user', '' ) ); ?>"
                                       placeholder="<?php esc_attr_e( 'Username', 'nexora-engine' ); ?>"
                                       autocomplete="off"
                                       class="ncx-text-input">
                                <input type="password"
                                       name="settings[nexeng_http_auth_pass]"
                                       value="<?php echo esc_attr( get_option( 'nexeng_http_auth_pass', '' ) ); ?>"
                                       placeholder="<?php esc_attr_e( 'Password', 'nexora-engine' ); ?>"
                                       autocomplete="new-password"
                                       class="ncx-text-input">
                            </div>
                        </div>
                        <div class="ncx-setting-row">
                            <div class="ncx-setting-info">
                                <span class="label">
                                    <?php esc_html_e( 'Auto-Rebuild on Save', 'nexora-engine' ); ?>
                                    <?php if ( ! $is_pro ) : ?>
                                    <span class="ncx-pro-chip"><?php esc_html_e( 'PRO', 'nexora-engine' ); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="desc"><?php esc_html_e( 'Automatically queue a focused static rebuild whenever a post or page is saved. Without this, you regenerate manually from Build Control.', 'nexora-engine' ); ?></span>
                            </div>
                            <label class="ncx-switch <?php echo esc_attr( ! $is_pro ? 'is-locked' : '' ); ?>">
                                <input type="checkbox" name="settings[nexeng_auto_rebuild]"
                                    <?php checked( get_option( 'nexeng_auto_rebuild', $is_pro ? 'on' : 'off' ), 'on' ); ?>
                                    <?php echo esc_attr( ! $is_pro ? 'disabled' : '' ); ?>>
                                <span class="ncx-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: Analytics -->
            <div id="ncx-tab-analytics" class="ncx-tab-content">
                <div class="ncx-card ncx-glass-card">
                    <h3><?php esc_html_e( 'Privacy & Tracking', 'nexora-engine' ); ?></h3>
                    <div class="ncx-settings-group">
                        <div class="ncx-setting-row">
                            <div class="ncx-setting-info">
                                <span class="label"><?php esc_html_e( 'Enable Analytics', 'nexora-engine' ); ?></span>
                                <span class="desc"><?php esc_html_e( 'Track real-time hits and cache performance.', 'nexora-engine' ); ?></span>
                            </div>
                            <label class="ncx-switch">
                                <input type="checkbox" name="settings[nexeng_analytics_enabled]" <?php checked( get_option( 'nexeng_analytics_enabled', 'on' ), 'on' ); ?>>
                                <span class="ncx-slider"></span>
                            </label>
                        </div>
                        <div class="ncx-setting-row">
                            <div class="ncx-setting-info">
                                <span class="label"><?php esc_html_e( 'Anonymize IPs (GDPR)', 'nexora-engine' ); ?></span>
                                <span class="desc"><?php esc_html_e( 'Hash IP addresses before storage for privacy compliance.', 'nexora-engine' ); ?></span>
                            </div>
                            <label class="ncx-switch">
                                <input type="checkbox" name="settings[nexeng_anonymize_ips]" <?php checked( get_option( 'nexeng_anonymize_ips', 'on' ), 'on' ); ?>>
                                <span class="ncx-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab: SEO Engine -->
            <div id="ncx-tab-seo" class="ncx-tab-content">
                <div class="ncx-card ncx-glass-card">
                    <h3><?php esc_html_e( 'Sitemap & Metadata', 'nexora-engine' ); ?></h3>
                    <div class="ncx-settings-group">
                        <div class="ncx-setting-row">
                            <div class="ncx-setting-info">
                                <span class="label"><?php esc_html_e( 'Dynamic XML Sitemap', 'nexora-engine' ); ?></span>
                                <span class="desc"><?php esc_html_e( 'Serves /sitemap.xml automatically from the static manifest — no additional sitemap plugin needed.', 'nexora-engine' ); ?></span>
                            </div>
                            <label class="ncx-switch">
                                <input type="checkbox" name="settings[nexeng_sitemap_enabled]" <?php checked( get_option( 'nexeng_sitemap_enabled', 'on' ), 'on' ); ?>>
                                <span class="ncx-slider"></span>
                            </label>
                        </div>
                        <div class="ncx-setting-row">
                            <div class="ncx-setting-info">
                                <span class="label">
                                    <?php esc_html_e( 'Automated Schema', 'nexora-engine' ); ?>
                                    <?php if ( ! $is_pro ) : ?>
                                    <span class="ncx-pro-chip"><?php esc_html_e( 'PRO', 'nexora-engine' ); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="desc"><?php esc_html_e( 'Inject smart JSON-LD schema (Article, BreadcrumbList, WebSite) based on post type and context.', 'nexora-engine' ); ?></span>
                            </div>
                            <label class="ncx-switch <?php echo esc_attr( ! $is_pro ? 'is-locked' : '' ); ?>">
                                <input type="checkbox" name="settings[nexeng_schema_enabled]"
                                    <?php checked( get_option( 'nexeng_schema_enabled', 'on' ), 'on' ); ?>
                                    <?php echo esc_attr( ! $is_pro ? 'disabled' : '' ); ?>>
                                <span class="ncx-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- CDN / Edge Cache Integration -->
                <div class="ncx-card ncx-glass-card" style="margin-top:20px;">
                    <h3><?php esc_html_e( 'CDN / Edge Cache', 'nexora-engine' ); ?></h3>
                    <p class="ncx-p-muted" style="margin:-4px 0 20px;">
                        <?php esc_html_e( 'After each page rebuild, Nexora calls the CDN purge API so edge nodes immediately serve the freshly captured static file — no stale content visible to users.', 'nexora-engine' ); ?>
                    </p>
                    <div class="ncx-settings-group">

                        <div class="ncx-setting-row">
                            <div class="ncx-setting-info">
                                <span class="label"><?php esc_html_e( 'Auto-Purge on Rebuild', 'nexora-engine' ); ?></span>
                                <span class="desc"><?php esc_html_e( 'Automatically call the CDN purge API after every page capture and after full mirror rebuilds.', 'nexora-engine' ); ?></span>
                            </div>
                            <label class="ncx-switch">
                                <input type="checkbox" name="settings[nexeng_cdn_auto_purge]" <?php checked( get_option( 'nexeng_cdn_auto_purge', 'on' ), 'on' ); ?>>
                                <span class="ncx-slider"></span>
                            </label>
                        </div>

                        <!-- Cloudflare -->
                        <div class="ncx-cdn-provider-block">
                            <div class="ncx-cdn-provider-header">
                                <strong><?php esc_html_e( 'Cloudflare', 'nexora-engine' ); ?></strong>
                                <span class="ncx-p-muted"><?php esc_html_e( 'Requires Cache Purge permission on the API token.', 'nexora-engine' ); ?></span>
                            </div>
                            <div class="ncx-cdn-fields">
                                <div class="ncx-cdn-field-row">
                                    <label><?php esc_html_e( 'Zone ID', 'nexora-engine' ); ?></label>
                                    <input type="text"
                                           name="settings[nexeng_cdn_cf_zone_id]"
                                           value="<?php echo esc_attr( get_option( 'nexeng_cdn_cf_zone_id', '' ) ); ?>"
                                           placeholder="e.g. a1b2c3d4e5f6..."
                                           autocomplete="off"
                                           class="ncx-text-input ncx-cdn-input">
                                </div>
                                <div class="ncx-cdn-field-row">
                                    <label><?php esc_html_e( 'API Token', 'nexora-engine' ); ?></label>
                                    <input type="password"
                                           name="settings[nexeng_cdn_cf_api_token]"
                                           value="<?php echo esc_attr( get_option( 'nexeng_cdn_cf_api_token', '' ) ); ?>"
                                           placeholder="Bearer token with Cache Purge scope"
                                           autocomplete="new-password"
                                           class="ncx-text-input ncx-cdn-input">
                                </div>
                                <div class="ncx-cdn-actions">
                                    <button type="button" class="ncx-btn ncx-btn-outline" id="ncxCfTestBtn">
                                        <?php esc_html_e( 'Test Connection', 'nexora-engine' ); ?>
                                    </button>
                                    <button type="button" class="ncx-btn ncx-btn-outline" id="ncxCfPurgeAllBtn">
                                        <?php esc_html_e( 'Purge All Now', 'nexora-engine' ); ?>
                                    </button>
                                    <span id="ncxCfStatus" class="ncx-cdn-status"></span>
                                </div>
                            </div>
                        </div>

                        <!-- BunnyCDN -->
                        <div class="ncx-cdn-provider-block">
                            <div class="ncx-cdn-provider-header">
                                <strong><?php esc_html_e( 'BunnyCDN', 'nexora-engine' ); ?></strong>
                                <span class="ncx-p-muted"><?php esc_html_e( 'Pull Zone ID + Account API key.', 'nexora-engine' ); ?></span>
                            </div>
                            <div class="ncx-cdn-fields">
                                <div class="ncx-cdn-field-row">
                                    <label><?php esc_html_e( 'Pull Zone ID', 'nexora-engine' ); ?></label>
                                    <input type="text"
                                           name="settings[nexeng_cdn_bunny_zone_id]"
                                           value="<?php echo esc_attr( get_option( 'nexeng_cdn_bunny_zone_id', '' ) ); ?>"
                                           placeholder="numeric zone ID"
                                           autocomplete="off"
                                           class="ncx-text-input ncx-cdn-input">
                                </div>
                                <div class="ncx-cdn-field-row">
                                    <label><?php esc_html_e( 'API Key', 'nexora-engine' ); ?></label>
                                    <input type="password"
                                           name="settings[nexeng_cdn_bunny_api_key]"
                                           value="<?php echo esc_attr( get_option( 'nexeng_cdn_bunny_api_key', '' ) ); ?>"
                                           placeholder="BunnyCDN account API key"
                                           autocomplete="new-password"
                                           class="ncx-text-input ncx-cdn-input">
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <div class="ncx-settings-footer">
                <button type="submit" class="ncx-btn ncx-btn-primary ncx-btn-large">
                    <?php esc_html_e( 'Apply All Settings', 'nexora-engine' ); ?>
                </button>
            </div>

        </form>
    </main>
</div>

<?php ob_start(); ?>
document.addEventListener('DOMContentLoaded', function () {

    // ── Tab switching ─────────────────────────────────────────────────────────
    const tabs   = document.querySelectorAll('.ncx-tab-trigger');
    const panels = document.querySelectorAll('.ncx-tab-content');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t)   { t.classList.remove('active'); });
            panels.forEach(function (p) { p.classList.remove('active'); });
            tab.classList.add('active');
            const target = document.getElementById('ncx-tab-' + tab.dataset.target);
            if (target) target.classList.add('active');
        });
    });

    // ── CDN action buttons ────────────────────────────────────────────────────
    const cfTestBtn    = document.getElementById('ncxCfTestBtn');
    const cfPurgeBtn   = document.getElementById('ncxCfPurgeAllBtn');
    const cfStatus     = document.getElementById('ncxCfStatus');

    const ncxCdnStatus = (msg, ok = true) => {
        if (!cfStatus) return;
        cfStatus.textContent = msg;
        cfStatus.style.color = ok ? '#10b981' : '#ef4444';
        setTimeout(() => { cfStatus.textContent = ''; }, 6000);
    };

    if (cfTestBtn) {
        cfTestBtn.addEventListener('click', async function () {
            ncxSetLoading(cfTestBtn, true);
            const res = await ncxCall('cdn_test_cloudflare');
            ncxSetLoading(cfTestBtn, false);
            ncxCdnStatus(
                res.success ? '✓ ' + (res.data?.message || 'Connected') : '✗ ' + (res.data?.message || 'Failed'),
                res.success
            );
        });
    }

    if (cfPurgeBtn) {
        cfPurgeBtn.addEventListener('click', async function () {
            ncxSetLoading(cfPurgeBtn, true);
            const res = await ncxCall('cdn_purge_all');
            ncxSetLoading(cfPurgeBtn, false);
            ncxCdnStatus(
                res.success ? '✓ ' + (res.data?.message || 'Purged') : '✗ ' + (res.data?.message || 'Failed'),
                res.success
            );
        });
    }

    // ── Settings form submit ──────────────────────────────────────────────────
    const form = document.getElementById('ncx-settings-form');
    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            ncxSetLoading(btn, true);

            // Build settings map from all named inputs.
            // FormData omits unchecked checkboxes — we must explicitly set them
            // to 'off' so the PHP handler can disable the corresponding option.
            const settings = {};
            form.querySelectorAll('[name^="settings["]').forEach(function (el) {
                const m = el.name.match(/^settings\[(.+)\]$/);
                if (!m) return;
                const key = m[1];
                if (el.type === 'checkbox') {
                    settings[key] = el.checked ? 'on' : 'off';
                } else if (el.type === 'radio') {
                    if (el.checked) settings[key] = el.value;
                } else {
                    settings[key] = el.value;
                }
            });

            const res = await ncxCall('save_settings', { settings });
            ncxToast(
                res.success
                    ? 'Settings synchronized successfully.'
                    : ( res.data && res.data.message ? res.data.message : 'Failed to save settings' ),
                res.success ? 'success' : 'error'
            );
            ncxSetLoading(btn, false);
        });
    }

});
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
