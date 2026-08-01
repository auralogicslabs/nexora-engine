<?php
/**
 * Nexora Engine — Redirect Manager (PRO)
 *
 * @var NEXENG_Database $db injected by render_redirects()
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'nexora-engine' ) ); }

$upgrade_url = function_exists( 'NexoraEngine\\get_upgrade_url' )
    ? \NexoraEngine\get_upgrade_url( 'pro' )
    : 'https://auralogicslabs.com/nexora-engine/#pricing';

// ── Pro gate ─────────────────────────────────────────────────────────────────
if ( ! NEXENG_Licence::is_pro() ) { ?>
    <div class="ncx-header">
        <div class="ncx-header-title">
            <h1><?php esc_html_e( 'Redirect Manager', 'nexora-engine' ); ?></h1>
        </div>
    </div>
    <div style="max-width:560px;margin:48px auto;text-align:center;padding:48px 40px;background:#fff;border:1px solid var(--ncx-brand-border,#DCE0E8);border-radius:16px;">
        <div style="width:64px;height:64px;background:rgba(2,82,250,0.08);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#0252FA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        </div>
        <h2 style="font-size:20px;font-weight:700;color:#0f1c35;margin:0 0 10px;"><?php esc_html_e( 'Redirect Manager is a Pro Feature', 'nexora-engine' ); ?></h2>
        <p style="font-size:14px;color:#435162;line-height:1.6;margin:0 0 8px;"><?php esc_html_e( 'Intelligent 301/302 redirect management with wildcard rules, instant toggle, hit tracking, chain detection, and CSV export.', 'nexora-engine' ); ?></p>
        <p style="font-size:13px;color:#8492a6;margin:0 0 28px;"><?php esc_html_e( 'Every redirect fires before WordPress boots — zero performance overhead.', 'nexora-engine' ); ?></p>
        <a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener"
           style="display:inline-flex;align-items:center;gap:8px;padding:13px 32px;background:linear-gradient(135deg,#0252FA,#063CE6);color:#fff;font-weight:600;font-size:14px;border-radius:8px;text-decoration:none;">
            <?php esc_html_e( 'Upgrade to Pro →', 'nexora-engine' ); ?>
        </a>
    </div>
<?php
    return;
}

// ── Data ─────────────────────────────────────────────────────────────────────
$blog_id     = get_current_blog_id();
$per_page    = 50;
$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
$offset      = ( $paged - 1 ) * $per_page;
$redirects   = $db->get_redirects( $blog_id, $per_page, $offset );
$stats       = $db->get_redirect_stats( $blog_id );
$total_pages = max( 1, (int) ceil( $stats['total'] / $per_page ) );

// Detect redirect chains: source_url appears as target_url of another rule.
$all_targets  = array_map( function( $r ) { return rtrim( $r['target_url'], '/' ); }, $db->get_redirects( $blog_id, 9999, 0 ) );
$chain_ids    = [];
foreach ( $redirects as $r ) {
    $full = rtrim( home_url( $r['source_url'] ), '/' );
    if ( in_array( $full, $all_targets, true ) ) {
        $chain_ids[] = (int) $r['id'];
    }
}
?>

<!-- ── Header ───────────────────────────────────────────────────────────────── -->
<div class="ncx-header">
    <div class="ncx-header-title">
        <h1><?php esc_html_e( 'Redirect Manager', 'nexora-engine' ); ?></h1>
        <p><?php esc_html_e( 'Smart 301/302 rules. Fires before PHP boots — zero latency overhead. Supports wildcards.', 'nexora-engine' ); ?></p>
    </div>
    <div class="ncx-header-actions">
        <button type="button" class="ncx-btn ncx-btn-outline" id="ncx-export-csv">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <?php esc_html_e( 'Export CSV', 'nexora-engine' ); ?>
        </button>
        <button type="button" class="ncx-btn ncx-btn-primary" id="ncx-toggle-add-form">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?php esc_html_e( 'Add Redirect', 'nexora-engine' ); ?>
        </button>
    </div>
</div>

<!-- ── Stats bar ─────────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
    <div class="ncx-card ncx-glass-card" style="padding:20px 24px;display:flex;align-items:center;gap:16px;">
        <div style="width:44px;height:44px;border-radius:10px;background:rgba(2,82,250,.08);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0252FA" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        </div>
        <div>
            <div style="font-size:26px;font-weight:700;color:var(--ncx-gray-900);line-height:1;"><?php echo number_format( $stats['total'] ); ?></div>
            <div style="font-size:12px;color:var(--ncx-muted);margin-top:3px;"><?php esc_html_e( 'Total Rules', 'nexora-engine' ); ?></div>
        </div>
    </div>
    <div class="ncx-card ncx-glass-card" style="padding:20px 24px;display:flex;align-items:center;gap:16px;">
        <div style="width:44px;height:44px;border-radius:10px;background:rgba(16,185,129,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div>
            <div style="font-size:26px;font-weight:700;color:var(--ncx-gray-900);line-height:1;"><?php echo number_format( $stats['active'] ); ?></div>
            <div style="font-size:12px;color:var(--ncx-muted);margin-top:3px;"><?php esc_html_e( 'Active Rules', 'nexora-engine' ); ?></div>
        </div>
    </div>
    <div class="ncx-card ncx-glass-card" style="padding:20px 24px;display:flex;align-items:center;gap:16px;">
        <div style="width:44px;height:44px;border-radius:10px;background:rgba(245,158,11,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        </div>
        <div>
            <div style="font-size:26px;font-weight:700;color:var(--ncx-gray-900);line-height:1;"><?php echo number_format( $stats['hits'] ); ?></div>
            <div style="font-size:12px;color:var(--ncx-muted);margin-top:3px;"><?php esc_html_e( 'Total Hits', 'nexora-engine' ); ?></div>
        </div>
    </div>
</div>

<?php
// ── Nginx notice ──────────────────────────────────────────────────────────────
$server_software = strtolower( NEXENG_Request::server( 'SERVER_SOFTWARE' ) );
$is_nginx        = str_contains( $server_software, 'nginx' );
$ssg_on          = get_option( 'nexeng_ssg_enabled', 'off' ) === 'on';

if ( $is_nginx && $ssg_on ) :
    // Build the nginx rewrite lines from active rules.
    $all_rules   = $db->get_redirects( $blog_id, 9999, 0 );
    $home_prefix = rtrim( (string) ( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '' ), '/' );
    $nginx_lines = [];
    foreach ( $all_rules as $nr ) {
        if ( empty( $nr['is_active'] ) ) { continue; }
        $src   = $home_prefix . '/' . ltrim( $nr['source_url'], '/' );
        $flag  = (int) $nr['redirect_type'] === 301 ? 'permanent' : 'redirect';
        if ( strpos( $nr['source_url'], '*' ) !== false ) {
            $pattern     = str_replace( '*', '(.*)', $src );
            $nginx_lines[] = "rewrite ^{$pattern}/?$ {$nr['target_url']} {$flag};";
        } else {
            $nginx_lines[] = "rewrite ^" . preg_quote( $src, '/' ) . "/?$ {$nr['target_url']} {$flag};";
        }
    }
    $config_block  = "# Nexora Engine — Redirect Rules\n";
    $config_block .= "# Paste inside your server {} block, BEFORE the try_files / static-file rule.\n";
    if ( ! empty( $nginx_lines ) ) {
        $config_block .= implode( "\n", $nginx_lines );
    } else {
        $config_block .= "# (No active rules yet — add redirects above and revisit this block.)";
    }
?>
<div id="ncx-nginx-notice" style="border:1px solid var(--ncx-brand-border);border-radius:14px;background:#fff;margin-bottom:22px;overflow:hidden;box-shadow:0 8px 24px rgba(15,23,42,.04);">
    <div style="padding:18px 20px;">
        <!-- Header row -->
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <span style="display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;background:#DBEAFE;flex-shrink:0;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </span>
                <strong style="font-size:14px;color:#111827;">
                    <?php esc_html_e( 'Nginx redirect routing detected', 'nexora-engine' ); ?>
                </strong>
                <span style="font-size:11px;font-weight:700;color:#0252FA;background:rgba(2,82,250,.08);padding:4px 9px;border-radius:12px;flex-shrink:0;">
                    <?php esc_html_e( 'Server rule advised', 'nexora-engine' ); ?>
                </span>
            </div>
            <button type="button" onclick="document.getElementById('ncx-nginx-notice').remove();"
                    style="background:none;border:none;cursor:pointer;color:#94A3B8;font-size:20px;line-height:1;padding:0 4px;flex-shrink:0;" title="<?php esc_attr_e( 'Dismiss', 'nexora-engine' ); ?>">&times;</button>
        </div>

        <!-- What works / what doesn't — clear bullet form -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
            <div style="background:#fff;border:1px solid #DBEAFE;border-radius:8px;padding:11px 13px;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <span style="color:#059669;font-size:14px;">✓</span>
                    <strong style="font-size:12px;color:#065F46;"><?php esc_html_e( 'Works right now', 'nexora-engine' ); ?></strong>
                </div>
                <p style="margin:0;font-size:12px;color:#475569;line-height:1.55;">
                    <?php esc_html_e( 'Redirects fire on every request that reaches WordPress (dynamic pages, missed-cache pages, logged-in users).', 'nexora-engine' ); ?>
                </p>
            </div>
            <div style="background:#fff;border:1px solid #FED7AA;border-radius:8px;padding:11px 13px;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <span style="color:#D97706;font-size:14px;">⚠</span>
                    <strong style="font-size:12px;color:#92400E;"><?php esc_html_e( 'Needs the config below', 'nexora-engine' ); ?></strong>
                </div>
                <p style="margin:0;font-size:12px;color:#475569;line-height:1.55;">
                    <?php esc_html_e( 'When Nginx serves a cached static page directly (before PHP loads), the PHP-side redirect never runs. The config below makes Nginx check redirect rules first.', 'nexora-engine' ); ?>
                </p>
            </div>
        </div>

        <!-- Why heading + reveal -->
        <p style="margin:0 0 10px;font-size:12px;color:#1E40AF;line-height:1.6;">
            <strong><?php esc_html_e( 'Why this is needed:', 'nexora-engine' ); ?></strong>
            <?php esc_html_e( 'Nginx ignores .htaccess files — every redirect rule must be added to the Nginx config directly. The block below is auto-generated from your active rules and updates whenever you add or remove redirects.', 'nexora-engine' ); ?>
        </p>

        <button type="button" id="ncx-nginx-toggle"
                style="font-size:12px;font-weight:600;color:#2563EB;background:#DBEAFE;border:1px solid #BFDBFE;border-radius:7px;cursor:pointer;padding:7px 14px;display:inline-flex;align-items:center;gap:5px;">
            <svg id="ncx-nginx-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform .2s;"><polyline points="6 9 12 15 18 9"/></svg>
            <?php esc_html_e( 'Show Nginx config block', 'nexora-engine' ); ?>
        </button>
    </div>

    <div id="ncx-nginx-config" style="display:none;border-top:1px solid #BFDBFE;background:#F8FAFC;padding:16px 18px;">
        <!-- Step-by-step -->
        <ol style="margin:0 0 12px;padding-left:20px;font-size:12px;color:#334155;line-height:1.8;">
            <li><?php esc_html_e( 'Copy the block below.', 'nexora-engine' ); ?></li>
            <li>
                <?php
                printf(
                    /* translators: 1: server context code, 2: try_files code */
                    esc_html__( 'Paste it inside your %1$s context, before any %2$s or static-file location block.', 'nexora-engine' ),
                    '<code style="background:#E2E8F0;padding:1px 5px;border-radius:3px;font-size:11px;">server { }</code>',
                    '<code style="background:#E2E8F0;padding:1px 5px;border-radius:3px;font-size:11px;">try_files</code>'
                );
                ?>
            </li>
            <li><?php esc_html_e( 'Reload Nginx:', 'nexora-engine' ); ?> <code style="background:#E2E8F0;padding:1px 5px;border-radius:3px;font-size:11px;">sudo nginx -t &amp;&amp; sudo systemctl reload nginx</code></li>
        </ol>

        <div style="display:flex;align-items:center;justify-content:space-between;padding-bottom:8px;">
            <span style="font-size:11px;font-weight:700;color:#1E40AF;text-transform:uppercase;letter-spacing:.06em;"><?php esc_html_e( 'Nginx config block', 'nexora-engine' ); ?></span>
            <button type="button" id="ncx-nginx-copy"
                    style="font-size:11px;font-weight:600;color:#2563EB;background:#fff;border:1px solid #BFDBFE;border-radius:6px;padding:5px 12px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                <?php esc_html_e( 'Copy', 'nexora-engine' ); ?>
            </button>
        </div>
        <pre id="ncx-nginx-code" style="margin:0;padding:14px 16px;background:#0F172A;color:#7DD3FC;font-size:12px;line-height:1.8;border-radius:7px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;font-family:'SF Mono',Consolas,Monaco,monospace;"><?php echo esc_html( $config_block ); ?></pre>
        <p style="margin:10px 0 0;font-size:11px;color:#64748B;line-height:1.6;">
            <?php esc_html_e( 'This block regenerates automatically whenever you add, edit, or remove redirect rules — re-copy it each time you make changes.', 'nexora-engine' ); ?>
        </p>
    </div>
</div>
<?php ob_start(); ?>
(function(){
    var toggle  = document.getElementById('ncx-nginx-toggle');
    var config  = document.getElementById('ncx-nginx-config');
    var chevron = document.getElementById('ncx-nginx-chevron');
    var copyBtn = document.getElementById('ncx-nginx-copy');
    var code    = document.getElementById('ncx-nginx-code');

    if (toggle) {
        toggle.addEventListener('click', function () {
            var open = config.style.display !== 'none';
            config.style.display  = open ? 'none' : 'block';
            chevron.style.transform = open ? '' : 'rotate(180deg)';
            toggle.childNodes[toggle.childNodes.length - 1].textContent = open
                ? ' <?php echo esc_js( __( 'Show Nginx Config', 'nexora-engine' ) ); ?>'
                : ' <?php echo esc_js( __( 'Hide Nginx Config', 'nexora-engine' ) ); ?>';
        });
    }

    if (copyBtn && code) {
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(code.textContent).then(function () {
                copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'nexora-engine' ) ); ?>';
                setTimeout(function () {
                    copyBtn.textContent = '<?php echo esc_js( __( 'Copy', 'nexora-engine' ) ); ?>';
                }, 2000);
            });
        });
    }
}());
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
<?php endif; ?>

<?php if ( ! empty( $chain_ids ) ) : ?>
<!-- ── Chain warning ──────────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;margin-bottom:20px;font-size:13px;color:#92400E;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <span><strong><?php esc_html_e( 'Redirect chain detected:', 'nexora-engine' ); ?></strong> <?php echo esc_html( count( $chain_ids ) ); ?> <?php esc_html_e( 'rule(s) have a source that is also a redirect target. This may cause double-redirect loops.', 'nexora-engine' ); ?></span>
</div>
<?php endif; ?>

<!-- ── Add form (slide-down) ─────────────────────────────────────────────────── -->
<div id="ncx-add-form-wrap" style="display:none;margin-bottom:24px;">
    <div class="ncx-card ncx-glass-card" style="border-left:3px solid #0252FA;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;font-size:15px;"><?php esc_html_e( 'New Redirect Rule', 'nexora-engine' ); ?></h3>
            <button type="button" id="ncx-close-add-form" style="background:none;border:none;cursor:pointer;color:var(--ncx-muted);font-size:18px;line-height:1;padding:4px;">✕</button>
        </div>
        <div class="ncx-redirect-form-grid">
            <div class="ncx-form-field">
                <label class="ncx-field-label"><?php esc_html_e( 'Source Path', 'nexora-engine' ); ?> <span style="color:#E24B4A;">*</span></label>
                <input type="text" id="ncx-r-source" class="ncx-input" placeholder="/old-page-url">
                <span class="ncx-field-hint"><?php esc_html_e( 'Relative path, e.g. /old-page or /blog/*', 'nexora-engine' ); ?></span>
            </div>
            <div class="ncx-form-field">
                <label class="ncx-field-label"><?php esc_html_e( 'Target URL', 'nexora-engine' ); ?> <span style="color:#E24B4A;">*</span></label>
                <input type="text" id="ncx-r-target" class="ncx-input" placeholder="https://example.com/new-page">
                <span class="ncx-field-hint"><?php esc_html_e( 'Full URL or relative path', 'nexora-engine' ); ?></span>
            </div>
            <div class="ncx-form-field">
                <label class="ncx-field-label"><?php esc_html_e( 'Type', 'nexora-engine' ); ?></label>
                <select id="ncx-r-type" class="ncx-select">
                    <option value="301">301 — Permanent (SEO-safe)</option>
                    <option value="302">302 — Temporary</option>
                </select>
            </div>
            <div class="ncx-form-field">
                <label class="ncx-field-label"><?php esc_html_e( 'Notes', 'nexora-engine' ); ?></label>
                <input type="text" id="ncx-r-notes" class="ncx-input" placeholder="<?php esc_attr_e( 'Optional — reason for this redirect', 'nexora-engine' ); ?>">
            </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:20px;flex-wrap:wrap;gap:12px;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;color:var(--ncx-gray-900);">
                <div class="ncx-toggle-wrap">
                    <input type="checkbox" id="ncx-r-active" checked>
                    <span class="ncx-toggle-track"></span>
                </div>
                <?php esc_html_e( 'Activate immediately', 'nexora-engine' ); ?>
            </label>
            <button type="button" class="ncx-btn ncx-btn-primary" id="ncx-save-redirect" style="min-width:130px;">
                <?php esc_html_e( 'Add Rule', 'nexora-engine' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- ── Search + filter bar ───────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
    <div style="position:relative;flex:1;min-width:200px;max-width:320px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--ncx-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="ncx-search" placeholder="<?php esc_attr_e( 'Search source or target…', 'nexora-engine' ); ?>"
               style="width:100%;padding:8px 10px 8px 32px;border:1px solid var(--ncx-brand-border);border-radius:7px;font-size:13px;color:var(--ncx-gray-900);box-sizing:border-box;">
    </div>
    <div class="ncx-filter-tabs" style="display:flex;gap:4px;">
        <button class="ncx-filter-btn active" data-filter="all"><?php esc_html_e( 'All', 'nexora-engine' ); ?></button>
        <button class="ncx-filter-btn" data-filter="301">301</button>
        <button class="ncx-filter-btn" data-filter="302">302</button>
        <button class="ncx-filter-btn" data-filter="active"><?php esc_html_e( 'Active', 'nexora-engine' ); ?></button>
        <button class="ncx-filter-btn" data-filter="inactive"><?php esc_html_e( 'Inactive', 'nexora-engine' ); ?></button>
    </div>
    <div style="margin-left:auto;font-size:12px;color:var(--ncx-muted);" id="ncx-visible-count">
        <?php echo number_format( $stats['total'] ); ?> <?php esc_html_e( 'rules', 'nexora-engine' ); ?>
    </div>
</div>

<!-- ── Redirects table ─────────────────────────────────────────────────────────── -->
<div class="ncx-card ncx-glass-card" style="padding:0;overflow:hidden;" id="ncx-redirects-card">
    <?php if ( empty( $redirects ) ) : ?>
    <div style="text-align:center;padding:64px 24px;">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#CBD5E1" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 16px;"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
        <h3 style="font-size:16px;font-weight:600;color:var(--ncx-gray-900);margin:0 0 8px;"><?php esc_html_e( 'No redirect rules yet', 'nexora-engine' ); ?></h3>
        <p style="color:var(--ncx-muted);font-size:14px;margin:0 0 20px;"><?php esc_html_e( 'Add your first rule to start routing traffic automatically.', 'nexora-engine' ); ?></p>
        <button type="button" class="ncx-btn ncx-btn-primary" onclick="document.getElementById('ncx-toggle-add-form').click()">
            <?php esc_html_e( '+ Add First Redirect', 'nexora-engine' ); ?>
        </button>
    </div>
    <?php else : ?>
    <table id="ncx-redirects-table" style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="background:var(--ncx-glass-bg,rgba(248,250,252,.8));border-bottom:2px solid var(--ncx-brand-border);">
                <th style="text-align:left;padding:12px 16px;font-weight:600;color:var(--ncx-muted);text-transform:uppercase;font-size:10px;letter-spacing:.07em;width:30%;"><?php esc_html_e( 'Source', 'nexora-engine' ); ?></th>
                <th style="text-align:left;padding:12px 16px;font-weight:600;color:var(--ncx-muted);text-transform:uppercase;font-size:10px;letter-spacing:.07em;width:33%;"><?php esc_html_e( 'Target', 'nexora-engine' ); ?></th>
                <th style="text-align:center;padding:12px 8px;font-weight:600;color:var(--ncx-muted);text-transform:uppercase;font-size:10px;letter-spacing:.07em;width:60px;"><?php esc_html_e( 'Type', 'nexora-engine' ); ?></th>
                <th style="text-align:center;padding:12px 8px;font-weight:600;color:var(--ncx-muted);text-transform:uppercase;font-size:10px;letter-spacing:.07em;width:80px;"><?php esc_html_e( 'Status', 'nexora-engine' ); ?></th>
                <th style="text-align:center;padding:12px 8px;font-weight:600;color:var(--ncx-muted);text-transform:uppercase;font-size:10px;letter-spacing:.07em;width:60px;"><?php esc_html_e( 'Hits', 'nexora-engine' ); ?></th>
                <th style="text-align:left;padding:12px 8px;font-weight:600;color:var(--ncx-muted);text-transform:uppercase;font-size:10px;letter-spacing:.07em;width:95px;"><?php esc_html_e( 'Created', 'nexora-engine' ); ?></th>
                <th style="width:50px;padding:12px 8px;"></th>
            </tr>
        </thead>
        <tbody id="ncx-redirect-rows">
        <?php foreach ( $redirects as $r ) :
            $is_chain = in_array( (int) $r['id'], $chain_ids, true );
        ?>
            <tr data-id="<?php echo (int) $r['id']; ?>"
                data-type="<?php echo (int) $r['redirect_type']; ?>"
                data-active="<?php echo esc_attr( $r['is_active'] ? '1' : '0' ); ?>"
                data-source="<?php echo esc_attr( strtolower( $r['source_url'] ) ); ?>"
                data-target="<?php echo esc_attr( strtolower( $r['target_url'] ) ); ?>"
                class="ncx-redir-row<?php echo esc_attr( $r['is_active'] ? '' : ' ncx-redir-inactive' ); ?>"
                style="border-bottom:1px solid var(--ncx-brand-border,#E5E7EB);transition:background .12s,opacity .2s;">
                <td style="padding:14px 16px;">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <?php if ( $is_chain ) : ?>
                        <span title="<?php esc_attr_e( 'Redirect chain — this source is also a redirect target', 'nexora-engine' ); ?>" style="color:#F59E0B;flex-shrink:0;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        </span>
                        <?php endif; ?>
                        <code style="font-size:12px;color:<?php echo esc_attr( $r['is_active'] ? 'var(--ncx-gray-900)' : 'var(--ncx-muted)' ); ?>;background:rgba(0,0,0,.03);padding:2px 6px;border-radius:4px;word-break:break-all;"><?php echo esc_html( $r['source_url'] ); ?></code>
                    </div>
                    <?php if ( ! empty( $r['notes'] ) ) : ?>
                    <div style="font-size:11px;color:var(--ncx-muted);margin-top:3px;padding-left:<?php echo esc_attr( $is_chain ? '18' : '0' ); ?>px;"><?php echo esc_html( $r['notes'] ); ?></div>
                    <?php endif; ?>
                </td>
                <td style="padding:14px 16px;max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <a href="<?php echo esc_url( $r['target_url'] ); ?>" target="_blank" rel="noopener"
                       style="color:<?php echo esc_attr( $r['is_active'] ? '#0252FA' : 'var(--ncx-muted)' ); ?>;text-decoration:none;font-size:12px;"
                       title="<?php echo esc_attr( $r['target_url'] ); ?>">
                        <?php echo esc_html( $r['target_url'] ); ?>
                    </a>
                </td>
                <td style="padding:14px 8px;text-align:center;">
                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:.02em;
                        <?php echo (int) $r['redirect_type'] === 301
                            ? 'background:rgba(2,82,250,.09);color:#0252FA;'
                            : 'background:rgba(245,158,11,.12);color:#B45309;'; ?>">
                        <?php echo (int) $r['redirect_type']; ?>
                    </span>
                </td>
                <td style="padding:14px 8px;text-align:center;">
                    <label class="ncx-status-toggle" title="<?php esc_attr_e( 'Toggle active/inactive', 'nexora-engine' ); ?>">
                        <input type="checkbox" class="ncx-redir-toggle"
                               data-id="<?php echo (int) $r['id']; ?>"
                               <?php checked( ! empty( $r['is_active'] ) ); ?>>
                        <span class="ncx-status-track"></span>
                    </label>
                </td>
                <td style="padding:14px 8px;text-align:center;font-weight:600;color:<?php echo esc_attr( (int) $r['hit_count'] > 0 ? 'var(--ncx-gray-900)' : 'var(--ncx-muted)' ); ?>;">
                    <?php echo number_format( (int) $r['hit_count'] ); ?>
                </td>
                <td style="padding:14px 8px;color:var(--ncx-muted);font-size:11px;white-space:nowrap;">
                    <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $r['created_at'] ) ) ); ?>
                </td>
                <td style="padding:14px 8px;text-align:right;">
                    <button type="button" class="ncx-redir-delete"
                            data-id="<?php echo (int) $r['id']; ?>"
                            title="<?php esc_attr_e( 'Delete redirect', 'nexora-engine' ); ?>"
                            style="background:none;border:1px solid transparent;color:var(--ncx-muted);border-radius:5px;cursor:pointer;padding:4px 7px;transition:color .15s,border-color .15s;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
    <div style="padding:14px 16px;border-top:1px solid var(--ncx-brand-border);display:flex;align-items:center;gap:8px;justify-content:flex-end;font-size:12px;color:var(--ncx-muted);">
        <?php
        /* translators: 1: current page number, 2: total number of pages. */
        printf( esc_html__( 'Page %1$d of %2$d', 'nexora-engine' ), (int) $paged, (int) $total_pages ); ?>
        <?php if ( $paged > 1 ) : ?>
        <a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" class="ncx-btn ncx-btn-outline" style="padding:4px 10px;font-size:12px;">&larr;</a>
        <?php endif; ?>
        <?php if ( $paged < $total_pages ) : ?>
        <a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" class="ncx-btn ncx-btn-outline" style="padding:4px 10px;font-size:12px;">&rarr;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php ob_start(); ?>
/* ── Redirect Manager Styles ────────────────────────────────────────────── */
.ncx-redirect-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 160px 1fr;
    gap: 14px;
    align-items: start;
}
.ncx-form-field { display: flex; flex-direction: column; gap: 5px; }
.ncx-field-label { font-size: 12px; font-weight: 600; color: var(--ncx-gray-900); }
.ncx-field-hint  { font-size: 11px; color: var(--ncx-muted); }
.ncx-input, .ncx-select {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--ncx-brand-border, #DCE0E8);
    border-radius: 6px;
    font-size: 13px;
    color: var(--ncx-gray-900);
    box-sizing: border-box;
    transition: border-color .15s;
}
.ncx-input:focus, .ncx-select:focus { outline: none; border-color: #0252FA; box-shadow: 0 0 0 2px rgba(2,82,250,.1); }

/* Filter tabs */
.ncx-filter-btn {
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid var(--ncx-brand-border, #DCE0E8);
    border-radius: 6px;
    background: transparent;
    color: var(--ncx-muted);
    cursor: pointer;
    transition: background .12s, color .12s, border-color .12s;
}
.ncx-filter-btn.active, .ncx-filter-btn:hover {
    background: rgba(2,82,250,.08);
    color: #0252FA;
    border-color: rgba(2,82,250,.2);
}

/* Table rows */
.ncx-redir-row:hover { background: rgba(2,82,250,.02); }
.ncx-redir-row:hover .ncx-redir-delete { color: #E24B4A !important; border-color: rgba(226,75,74,.25) !important; }
.ncx-redir-inactive td { opacity: .6; }

/* Status toggle */
.ncx-status-toggle { position: relative; display: inline-block; width: 36px; height: 20px; cursor: pointer; }
.ncx-status-toggle input { opacity: 0; width: 0; height: 0; }
.ncx-status-track {
    position: absolute; inset: 0;
    background: #CBD5E1;
    border-radius: 20px;
    transition: background .2s;
}
.ncx-status-track::before {
    content: '';
    position: absolute;
    width: 14px; height: 14px;
    border-radius: 50%;
    background: #fff;
    top: 3px; left: 3px;
    transition: transform .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.ncx-status-toggle input:checked + .ncx-status-track { background: #10B981; }
.ncx-status-toggle input:checked + .ncx-status-track::before { transform: translateX(16px); }

/* Inline active toggle for add form */
.ncx-toggle-wrap { position: relative; display: inline-block; width: 36px; height: 20px; flex-shrink: 0; }
.ncx-toggle-wrap input { opacity: 0; width: 0; height: 0; }
.ncx-toggle-track {
    position: absolute; inset: 0;
    background: #CBD5E1;
    border-radius: 20px;
    cursor: pointer;
    transition: background .2s;
}
.ncx-toggle-track::before {
    content: '';
    position: absolute;
    width: 14px; height: 14px;
    border-radius: 50%;
    background: #fff;
    top: 3px; left: 3px;
    transition: transform .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.ncx-toggle-wrap input:checked + .ncx-toggle-track { background: #10B981; }
.ncx-toggle-wrap input:checked + .ncx-toggle-track::before { transform: translateX(16px); }

@media (max-width: 900px) {
    .ncx-redirect-form-grid { grid-template-columns: 1fr 1fr !important; }
}
@media (max-width: 600px) {
    .ncx-redirect-form-grid { grid-template-columns: 1fr !important; }
    .ncx-filter-tabs { flex-wrap: wrap; }
}
<?php NEXENG_Inline_Assets::style( ob_get_clean() ); ?>

<?php ob_start(); ?>
document.addEventListener('DOMContentLoaded', function () {

    // ── Add form toggle ───────────────────────────────────────────────────────
    var toggleBtn = document.getElementById('ncx-toggle-add-form');
    var closeBtn  = document.getElementById('ncx-close-add-form');
    var formWrap  = document.getElementById('ncx-add-form-wrap');

    function openForm() {
        formWrap.style.display = 'block';
        toggleBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><?php esc_html_e( 'Close Form', 'nexora-engine' ); ?>';
        document.getElementById('ncx-r-source').focus();
    }
    function closeForm() {
        formWrap.style.display = 'none';
        toggleBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg><?php esc_html_e( 'Add Redirect', 'nexora-engine' ); ?>';
    }

    if (toggleBtn) toggleBtn.addEventListener('click', function () {
        formWrap.style.display === 'none' ? openForm() : closeForm();
    });
    if (closeBtn) closeBtn.addEventListener('click', closeForm);

    // ── Save redirect ─────────────────────────────────────────────────────────
    var saveBtn = document.getElementById('ncx-save-redirect');
    if (saveBtn) {
        saveBtn.addEventListener('click', async function () {
            var source    = document.getElementById('ncx-r-source').value.trim();
            var target    = document.getElementById('ncx-r-target').value.trim();
            var type      = document.getElementById('ncx-r-type').value;
            var notes     = document.getElementById('ncx-r-notes').value.trim();
            var is_active = document.getElementById('ncx-r-active').checked ? 1 : 0;

            if (!source || !target) {
                ncxToast('<?php echo esc_js( __( 'Source path and target URL are required.', 'nexora-engine' ) ); ?>', 'error');
                return;
            }
            if (source.charAt(0) !== '/') source = '/' + source;

            ncxSetLoading(saveBtn, true);
            var res = await ncxCall('add_redirect', { source, target, type, is_active, notes });
            ncxSetLoading(saveBtn, false);

            if (res.success) {
                ncxToast('<?php echo esc_js( __( 'Redirect rule added successfully.', 'nexora-engine' ) ); ?>', 'success');
                setTimeout(function () { location.reload(); }, 900);
            } else {
                ncxToast(res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Failed to add redirect.', 'nexora-engine' ) ); ?>', 'error');
            }
        });
    }

    // ── Delete redirect ───────────────────────────────────────────────────────
    document.querySelectorAll('.ncx-redir-delete').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var id  = parseInt(btn.dataset.id);
            var row = document.querySelector('.ncx-redir-row[data-id="' + id + '"]');
            if (!confirm('<?php echo esc_js( __( 'Delete this redirect rule? This cannot be undone.', 'nexora-engine' ) ); ?>')) return;

            btn.disabled = true;
            var res = await ncxCall('delete_redirect', { redirect_id: id });
            if (res.success) {
                if (row) {
                    row.style.opacity = '0';
                    row.style.transition = 'opacity .25s';
                    setTimeout(function () {
                        row.remove();
                        updateVisibleCount();
                    }, 260);
                }
                ncxToast('<?php echo esc_js( __( 'Redirect deleted.', 'nexora-engine' ) ); ?>', 'success');
            } else {
                btn.disabled = false;
                ncxToast(res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Delete failed.', 'nexora-engine' ) ); ?>', 'error');
            }
        });
    });

    // ── Toggle active/inactive ────────────────────────────────────────────────
    document.querySelectorAll('.ncx-redir-toggle').forEach(function (chk) {
        chk.addEventListener('change', async function () {
            var id        = parseInt(chk.dataset.id);
            var is_active = chk.checked ? 1 : 0;
            var row       = chk.closest('.ncx-redir-row');

            var res = await ncxCall('toggle_redirect', { redirect_id: id, is_active: is_active });
            if (res.success) {
                if (row) {
                    row.dataset.active = is_active;
                    row.classList.toggle('ncx-redir-inactive', !is_active);
                }
                ncxToast(is_active
                    ? '<?php echo esc_js( __( 'Redirect activated.', 'nexora-engine' ) ); ?>'
                    : '<?php echo esc_js( __( 'Redirect paused.', 'nexora-engine' ) ); ?>',
                'success');
            } else {
                chk.checked = !chk.checked; // revert on failure
                ncxToast(res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Failed to update status.', 'nexora-engine' ) ); ?>', 'error');
            }
        });
    });

    // ── Export CSV ────────────────────────────────────────────────────────────
    var exportBtn = document.getElementById('ncx-export-csv');
    if (exportBtn) {
        exportBtn.addEventListener('click', async function () {
            ncxSetLoading(exportBtn, true);
            var res = await ncxCall('export_redirects', {});
            ncxSetLoading(exportBtn, false);
            if (res.success && res.data.csv) {
                var blob = new Blob([res.data.csv], { type: 'text/csv;charset=utf-8;' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = res.data.filename || 'nexora-redirects.csv';
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
                ncxToast('<?php echo esc_js( __( 'CSV exported', 'nexora-engine' ) ); ?> (' + (res.data.count || 0) + ' <?php echo esc_js( __( 'rules', 'nexora-engine' ) ); ?>).', 'success');
            } else {
                ncxToast('<?php echo esc_js( __( 'Export failed.', 'nexora-engine' ) ); ?>', 'error');
            }
        });
    }

    // ── Search + filter ───────────────────────────────────────────────────────
    var searchInput   = document.getElementById('ncx-search');
    var filterBtns    = document.querySelectorAll('.ncx-filter-btn');
    var currentFilter = 'all';

    function applyFilters() {
        var q    = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var rows = document.querySelectorAll('.ncx-redir-row');
        var vis  = 0;
        rows.forEach(function (row) {
            var src    = row.dataset.source    || '';
            var tgt    = row.dataset.target    || '';
            var type   = row.dataset.type      || '';
            var active = row.dataset.active    || '0';

            var matchSearch = !q || src.indexOf(q) !== -1 || tgt.indexOf(q) !== -1;
            var matchFilter = true;
            if (currentFilter === '301')      matchFilter = type === '301';
            else if (currentFilter === '302') matchFilter = type === '302';
            else if (currentFilter === 'active')   matchFilter = active === '1';
            else if (currentFilter === 'inactive') matchFilter = active === '0';

            var show = matchSearch && matchFilter;
            row.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        var cnt = document.getElementById('ncx-visible-count');
        if (cnt) cnt.textContent = vis + ' <?php echo esc_js( __( 'rules', 'nexora-engine' ) ); ?>';
    }

    function updateVisibleCount() { applyFilters(); }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    filterBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filterBtns.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            applyFilters();
        });
    });

});
<?php NEXENG_Inline_Assets::script( ob_get_clean() ); ?>
