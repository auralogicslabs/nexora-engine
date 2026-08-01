<?php
/**
 * Nexora Engine — Universal Stealth Shell (V63 - V1.8.1 HTTP Ghost Protocol)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── HTTP Identity Stealth ─────────────────────────────────────────────────
// Remove PHP/Apache fingerprints from response headers
header_remove('X-Powered-By');   // Hides PHP/8.x
header_remove('X-Pingback');     // Hides WordPress
header_remove('Link');           // Hides WP REST discovery in headers
header_remove('X-Robots-Tag');

// Disguise as a Next.js / Node.js application
// Wappalyzer and Builtwith detect these signals
header('X-Powered-By: Next.js');             // Wappalyzer: detects Next.js
header('X-Nextjs-Cache: HIT');               // Mimics Next.js CDN cache header
header('Cache-Control: public, max-age=300, s-maxage=3600, stale-while-revalidate=86400, must-revalidate');
header('X-Content-Type-Options: nosniff');   // Security header (also on Next.js apps)
header('X-Frame-Options: SAMEORIGIN');       // Security + Next.js signature
header('Referrer-Policy: strict-origin-when-cross-origin'); // Next.js default
// Note: 'Server' header requires Apache mod_headers (.htaccess) to override reliably

add_action('wp_enqueue_scripts', function() {
    wp_dequeue_script('elementor-frontend');
    wp_deregister_script('elementor-frontend');
    wp_dequeue_script('wp-emoji-release');
}, 9999);

ob_start();
wp_head();
$head_content = ob_get_clean();

// Ghost Protocol: Strip elementorFrontendConfig injected by Elementor
// Our shell already defines a clean neutral version — strip Elementor's copy
$head_content = preg_replace(
    '/<script(?:[^>]*)>[^<]*elementorFrontendConfig[\s\S]*?<\/script>/i',
    '',
    $head_content
);

// Strip ALL generator meta tags (Elementor + WordPress versions)
$head_content = preg_replace('/<meta[^>]*name=["\']generator["\'][^>]*>/i', '', $head_content);

$initial_post_id = get_the_ID();

// The page body render is resolved BEFORE this template is included — by
// NEXENG_Init::maybe_render_shell()'s preflight — and handed to us here. The
// preflight only includes this shell when it has a usable, cached-or-fresh
// render, so $initial_html is guaranteed non-empty (no broken/CSS-less page
// can be emitted). If the global is somehow absent, fall back to safe empties.
$nexeng_render        = isset($GLOBALS['nexeng_shell_render']) && is_array($GLOBALS['nexeng_shell_render'])
    ? $GLOBALS['nexeng_shell_render']
    : ['html' => '', 'body_class' => '', 'lcp' => ''];
$initial_html       = (string) ($nexeng_render['html'] ?? '');
$initial_body_class = (string) ($nexeng_render['body_class'] ?? '');
$lcp_preload        = (string) ($nexeng_render['lcp'] ?? '');

ob_start();
wp_footer();
$footer_content = ob_get_clean();

/**
 * Ghost Protocol: Path Masking & Safe Fingerprint Removal
 * NOTE: data-widget_type, data-element_type, data-id are intentionally
 * kept intact — Elementor JS depends on these selectors for slider init.
 */
function nexeng_ghost_cleaner( $html ) {
    $site_url = untrailingslashit( site_url() );
    $asset_base = untrailingslashit( get_option('nexeng_asset_base', $site_url) );
    // Universal proxy prefix — '' for Apache, '/index.php' for Nginx hosts.
    $prefix   = NEXENG_Init::proxy_prefix();
    $v_assets = $asset_base . $prefix . '/_ncx_v12/assets';
    $v_lib    = $asset_base . $prefix . '/_ncx_v12/lib';
    
    // 1. Mask Core Paths
    $html = str_replace( trailingslashit(content_url()), $v_assets . '/', $html );
    $html = str_replace( trailingslashit(includes_url()), $v_lib . '/', $html );
    
    // 2. Strip WordPress version strings (?ver=)
    $html = preg_replace('/(?:\?|&|#)ver=[^"\']*/i', '', $html);
    
    // 3. Strip CSS Link IDs (removes id='elementor-post-6-css' etc)
    $html = preg_replace('/ id=["\'][^"\']*-css["\']/i', '', $html);

    // 4. Ghost Namespacing: Rename window.wp to window.ncx (safe — only affects JS globals)
    $html = str_replace('window.wp', 'window.ncx', $html);
    $html = str_replace('wp.i18n', 'ncx.i18n', $html);
    $html = str_replace('wp.hooks', 'ncx.hooks', $html);

    // 5. Mask plugin and theme names in asset paths — source looks like a custom JS-built site
    // Order matters: elementor-pro first, then elementor (to avoid partial match)
    // We deliberately do NOT compress arbitrary plugin slugs to "pkg/" — the
    // serve handler can't reverse-map pkg → plugin name, so those URLs 404.
    $html = str_replace('/_ncx_v12/assets/plugins/elementor-pro/', '/_ncx_v12/assets/ep/', $html);
    $html = str_replace('/_ncx_v12/assets/plugins/elementor/', '/_ncx_v12/assets/e/', $html);
    $html = str_replace('/_ncx_v12/assets/themes/', '/_ncx_v12/assets/t/', $html);

    // 6. Mask uploads/elementor path (exposes Elementor in CSS hrefs)
    $html = str_replace('/_ncx_v12/assets/uploads/elementor/', '/_ncx_v12/assets/uploads/ncx/', $html);

    // 7. Strip section-level Elementor data attributes — SAFE (not used by slider/widget JS)
    // These are page/template identifiers only: data-elementor-type, data-elementor-id, data-elementor-post-type
    $html = preg_replace('/ data-elementor-(type|id|post-type)=["\'][^"\']*["\']/i', '', $html);

    return $html;
}

$head_content = nexeng_ghost_cleaner( $head_content );
$footer_content = nexeng_ghost_cleaner( $footer_content );
$initial_html = nexeng_ghost_cleaner( $initial_html );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <?php
    // No remote web fonts are loaded here. The shell inherits the site theme /
    // page builder typography so it matches the live site and avoids any external
    // request (and the WordPress.org "no remote files" guideline).
    ?>
    <?php if (!empty($lcp_preload)): ?>
    <link rel="preload" as="image" href="<?php echo esc_url($lcp_preload); ?>" fetchpriority="high">
    <?php endif; ?>
    
    <script id="ncx-bootstrap">
        var ncx = window.ncx || {};
        ncx.i18n = ncx.i18n || { __: (s) => s, setLocaleData: () => {} };
        ncx.hooks = ncx.hooks || { addAction: () => {}, doAction: () => {}, addFilter: () => {}, applyFilters: (n, v) => v };
        var wp = window.wp || ncx; window.ncx = ncx; window.wp = wp;
        
        window.elementorFrontendConfig = {
            environmentMode: { edit: false, wpPreview: false, isScriptDebug: false },
            is_rtl: false,
            breakpoints: { xs: 0, sm: 480, md: 768, lg: 1025, xl: 1440, xxl: 1600 },
            activeBreakpoints: ["viewport_mobile", "viewport_tablet"],
            urls: { assets: "" }, post: { id: 0 }, experimentalFeatures: { "container": true }
        };
        window.ncxScriptMemory = new Set();
    </script>

    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $head_content is WordPress's own rendered <head> markup captured via output buffering (wp_head); it is intentional trusted HTML and must be emitted verbatim — escaping it would print the markup as visible text and break the page.
    echo $head_content; ?>
    <?php
    /*
     * The <style> and <script> tags in this file are NOT enqueueable, and are
     * deliberately raw.
     *
     * This template does not render inside a WordPress theme — it *is* the
     * whole document for headless/shell mode, assembled here and sent instead
     * of a theme render. wp_enqueue_style()/wp_enqueue_script() write into
     * wp_head/wp_footer, neither of which runs for this response, so an
     * enqueued asset would simply never be printed.
     *
     * The blocks below are also load-bearing at first paint: the loader styles
     * must apply before anything is visible, and #ncx-global-kit is the target
     * the runtime fills with critical CSS. Deferring them to an external file
     * would guarantee the flash of unstyled content this mode exists to avoid.
     */
    ?>
    <style id="ncx-global-kit"></style>

    <style>
        .ncx-l { position: fixed; inset: 0; background: #fff; display: flex; align-items: center; justify-content: center; z-index: 10000; transition: opacity 0.3s ease; }
        .ncx-s { width: 40px; height: 40px; border: 2px solid #0252FA; border-radius: 50%; border-top-color: transparent; animation: n-s 0.6s linear infinite; }
        @keyframes n-s { to { transform: rotate(360deg); } }
        #nexora-root { opacity: 1; transition: opacity 0.4s ease; min-height: 100vh; }
        #nexora-root.loading { opacity: 0.5; pointer-events: none; }
    </style>

    <script id="__NEXORA_PROPS__" type="application/json">
        <?php 
        $site_url = untrailingslashit( site_url() );
        $asset_base = untrailingslashit( get_option('nexeng_asset_base', $site_url) );
        $home_path = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '';
        echo wp_json_encode([
            'api'         => rest_url('nexeng/v1'),
            'origContent' => trailingslashit(content_url()),
            'origLib'     => trailingslashit(includes_url()),
            'vAssets'     => trailingslashit($asset_base) . '_ncx_v12/assets/',
            'vLib'        => trailingslashit($asset_base) . '_ncx_v12/lib/',
            'base'        => rtrim($home_path, '/'),
            'path'        => str_replace(home_url(), '', get_permalink())
        ]); ?>
    </script>
</head>
<body class="nexora-web-app <?php echo esc_attr($initial_body_class); ?>">
    <div id="ncx-loader" class="ncx-l" style="display:none;"><div class="ncx-s"></div></div>
    <div id="nexora-root"><?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $initial_html is the server-rendered page body (the full WordPress content render) injected into the SPA root; it is intentional trusted HTML and must not be escaped.
    echo $initial_html; ?></div>
    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $footer_content is WordPress's own rendered footer markup captured via output buffering (wp_footer); intentional trusted HTML, emitted verbatim.
    echo $footer_content; ?>

    <script>
        (function() {
            const p = JSON.parse(document.getElementById('__NEXORA_PROPS__').textContent);
            const r = document.getElementById('nexora-root');
            const l = document.getElementById('ncx-loader');
            let isFetching = false;

            async function router(path, isInitial = false) {
                if (isFetching) return;
                isFetching = true;
                if (!isInitial) {
                    l.style.display = 'flex'; l.style.opacity = '1';
                    r.classList.add('loading');
                }

                try {
                    let cleanPath = path.replace(p.base, '').replace(/^\/|\/$/g, '');
                    const apiUrl = cleanPath === '' ? `${p.api}/public/page` : `${p.api}/public/page/${cleanPath}`;
                    
                    const res = await fetch(apiUrl);
                    const json = await res.json();
                    if (!json.success || !json.data || !json.data.content) {
                        window.location.href = path; return;
                    }
                    
                    const data = json.data;
                    if (data.config) window.elementorFrontendConfig = Object.assign(window.elementorFrontendConfig, data.config);
                    if (data.body_class) document.body.className = `nexora-web-app ${data.body_class}`;

                    document.querySelectorAll('style[data-ncx-global]').forEach(s => s.remove());
                    if (data.global_style) {
                        const style = document.getElementById('ncx-global-kit') || document.createElement('style');
                        style.id = 'ncx-global-kit';
                        style.setAttribute('data-ncx-global', 'true');
                        style.textContent = data.global_style.split(p.origContent).join(p.vAssets).split(p.origLib).join(p.vLib);
                        if (!style.parentNode) document.head.appendChild(style);
                    }

                    const ghostMask = (txt) => {
                        let clean = txt.split(p.origContent).join(p.vAssets).split(p.origLib).join(p.vLib);
                        clean = clean.split('data-widget_type=').join('data-ncx-type=');
                        clean = clean.split('data-element_type=').join('data-ncx-el=');
                        clean = clean.split('data-id=').join('data-ncx-id=');
                        clean = clean.replace(/(?:\?|&|#)ver=[^"\']*/ig, '');
                        clean = clean.replace(/window\.wp/g, 'window.ncx').replace(/wp\.hooks/g, 'ncx.hooks');
                        return clean;
                    };

                    const newContent = ghostMask(data.content || '');
                    
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(newContent, 'text/html');
                    doc.querySelectorAll('script').forEach(s => s.remove());

                    const newInner = doc.querySelector('#ncx-v') || doc.querySelector('.elementor') || doc.querySelector('main') || doc.body;
                    const currentV = r.querySelector('#ncx-v') || r.querySelector('.elementor') || r.querySelector('main') || r;
                    
                    if (currentV && newInner) {
                        currentV.innerHTML = newInner.innerHTML;
                    } else {
                        r.innerHTML = newInner ? newInner.innerHTML : newContent;
                    }

                    window.scrollTo(0, 0); 
                    
                    setTimeout(() => {
                        r.classList.remove('loading');
                        l.style.opacity = '0';
                        setTimeout(() => l.style.display = 'none', 300);
                        isFetching = false;
                    }, 50);

                    const rawScripts = parser.parseFromString(newContent, 'text/html').querySelectorAll('script');
                    for (const s of rawScripts) {
                        let content = s.textContent;
                        const src = s.src;
                        const scriptId = src || content.substring(0, 500);
                        if (window.ncxScriptMemory.has(scriptId)) continue;
                        window.ncxScriptMemory.add(scriptId);

                        const n = document.createElement('script');
                        Array.from(s.attributes).forEach(a => n.setAttribute(a.name, a.value));
                        if (src) n.src = src.replace(p.origContent, p.vAssets).replace(p.origLib, p.vLib);
                        else n.textContent = content.replace(/(^|;|\n)(?:const|let)\s+([a-zA-Z0-9_$]+)\s*=/g, '$1window.$2=');
                        document.body.appendChild(n);
                    }

                    /**
                     * Wake Up Logic (V1.7.1 Functional Ghost)
                     */
                    setTimeout(() => {
                        if (window.elementorFrontend && typeof window.elementorFrontend.init === 'function') window.elementorFrontend.init();
                        if (window.jQuery) {
                            window.jQuery(window).trigger('elementor/frontend/init');
                            const wakeUp = () => {
                                if (window.elementorFrontend && window.elementorFrontend.hooks) {
                                    window.jQuery('.elementor-widget, .elementor-column, .elementor-section').each(function() {
                                        const type = window.jQuery(this).attr('data-ncx-type') || 'global';
                                        window.elementorFrontend.hooks.doAction('frontend/element_ready/global', window.jQuery(this));
                                        window.elementorFrontend.hooks.doAction('frontend/element_ready/' + type, window.jQuery(this));
                                    });
                                }
                                window.dispatchEvent(new Event('resize'));
                            };
                            wakeUp(); setTimeout(wakeUp, 200);
                        }
                    }, 500);

                } catch (e) { 
                    console.error('Nexora Error:', e); 
                    window.location.href = path;
                    isFetching = false;
                }
            }

            document.addEventListener('click', e => {
                const a = e.target.closest('a');
                if (a && a.href.includes(window.location.host) && !a.href.includes('wp-admin')) {
                    if (a.getAttribute('href') === '#' || a.getAttribute('href').includes('#')) {
                        e.preventDefault(); return;
                    }
                    const targetPath = a.pathname;
                    if (targetPath !== window.location.pathname) {
                        e.preventDefault();
                        window.history.pushState({}, '', a.href);
                        router(targetPath);
                    }
                }
            });

            window.onpopstate = () => router(window.location.pathname);
            
            document.querySelectorAll('script').forEach(s => {
                if (s.src) window.ncxScriptMemory.add(s.src);
                else if (s.textContent) window.ncxScriptMemory.add(s.textContent.substring(0, 500));
            });
        })();
    </script>
</body>
</html>
