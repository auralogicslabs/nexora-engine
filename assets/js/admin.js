/**
 * Nexora Engine — Admin Interactions (V2.0.0)
 */

/**
 * Promise-based confirmation modal.
 * Resolves true when user clicks the primary action, false on cancel/close.
 *
 * @param {Object} opts
 * @param {string} opts.title       - Modal heading
 * @param {string} opts.body        - HTML body content
 * @param {string} opts.confirmText - Primary button label
 * @param {string} opts.confirmClass - Extra CSS class for the primary button (e.g. 'ncx-btn-danger')
 * @returns {Promise<boolean>}
 */
const ncxConfirmModal = ({ title, body, confirmText = 'Confirm', confirmClass = 'ncx-btn-primary', type = 'default' } = {}) =>
    new Promise(resolve => {
        document.getElementById('ncxConfirmModalOverlay')?.remove();

        // Icon and accent colour per modal type.
        const meta = {
            danger:  { icon: '🗑', accentClass: 'ncx-cm--danger'  },
            warning: { icon: '⚠',  accentClass: 'ncx-cm--warning' },
            build:   { icon: '⚡', accentClass: 'ncx-cm--build'   },
            stop:    { icon: '⏹',  accentClass: 'ncx-cm--stop'    },
            default: { icon: '✦',  accentClass: ''                 },
        };
        const { icon, accentClass } = meta[type] || meta.default;

        const overlay = document.createElement('div');
        overlay.id = 'ncxConfirmModalOverlay';
        overlay.className = 'ncx-modal-overlay';
        overlay.innerHTML = `
            <div class="ncx-cm ${accentClass}" role="dialog" aria-modal="true" aria-labelledby="ncxConfirmTitle">
                <div class="ncx-cm-stripe"></div>
                <div class="ncx-cm-inner">
                    <div class="ncx-cm-header">
                        <span class="ncx-cm-icon">${icon}</span>
                        <strong class="ncx-cm-title" id="ncxConfirmTitle">${title}</strong>
                        <button type="button" class="ncx-cm-close" aria-label="Cancel">&times;</button>
                    </div>
                    <div class="ncx-cm-body">${body}</div>
                    <div class="ncx-cm-footer">
                        <button type="button" class="ncx-btn ncx-btn-outline ncx-cm-cancel">Cancel</button>
                        <button type="button" class="ncx-btn ${confirmClass} ncx-cm-ok">${confirmText}</button>
                    </div>
                </div>
            </div>`;

        const close = (result) => { overlay.remove(); resolve(result); };

        overlay.querySelector('.ncx-cm-close').addEventListener('click', () => close(false));
        overlay.querySelector('.ncx-cm-cancel').addEventListener('click', () => close(false));
        overlay.querySelector('.ncx-cm-ok').addEventListener('click', () => close(true));
        overlay.addEventListener('click', e => { if (e.target === overlay) close(false); });

        // Animate in.
        overlay.style.opacity = '0';
        document.body.appendChild(overlay);
        requestAnimationFrame(() => { overlay.style.transition = 'opacity .18s ease'; overlay.style.opacity = '1'; });
        setTimeout(() => overlay.querySelector('.ncx-cm-cancel')?.focus(), 60);
    });

const ncxToast = (message, type = 'success') => {
    let container = document.querySelector('.ncx-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'ncx-toast-container';
        document.body.appendChild(container);
    }
    const colors = { success: '#F39A09', error: '#E24B4A', info: '#0252FA', warning: '#F59E0B' };
    const toast = document.createElement('div');
    toast.className = `ncx-toast`;
    const dot = document.createElement('span');
    dot.className = 'ncx-dot';
    dot.style.cssText = `background:${colors[type]};width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:10px;`;
    const msgSpan = document.createElement('span');
    msgSpan.textContent = message;
    toast.appendChild(dot);
    toast.appendChild(msgSpan);
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
};

/**
 * ncxPanelNotice — shows a brief action-result message inside the Build
 * Control panel itself (anchored, not a floating toast).  Falls back to
 * ncxToast when the panel notice element isn't present on the current page.
 *
 * @param {string} message  Human-readable text to display.
 * @param {string} type     'success' | 'info' | 'warning' | 'error'
 */
const ncxPanelNotice = (message, type = 'success') => {
    const el = document.getElementById('ncxPanelNotice');
    if (!el) { ncxToast(message, type); return; }
    const icons = {
        success: 'dashicons-yes-alt',
        error:   'dashicons-dismiss',
        info:    'dashicons-info-outline',
        warning: 'dashicons-warning',
    };
    const iconEl = el.querySelector('.ncx-pn-icon');
    const textEl = el.querySelector('.ncx-pn-text');
    if (iconEl) iconEl.className = 'ncx-pn-icon dashicons ' + (icons[type] || 'dashicons-info-outline');
    if (textEl) textEl.textContent = message;
    el.className = 'ncx-panel-notice ncx-panel-notice--' + type;
    el.style.display = 'flex';
    el.style.opacity = '1';
    el.style.transition = '';
    clearTimeout(el._ncxTimer);
    el._ncxTimer = setTimeout(() => {
        el.style.transition = 'opacity .3s ease';
        el.style.opacity = '0';
        setTimeout(() => { el.style.display = 'none'; el.style.opacity = '1'; el.style.transition = ''; }, 330);
    }, 3500);
};

/**
 * ncxShowPreflightError — surfaces a server-configuration failure as a
 * prominent modal/panel instead of a dismissable toast.
 *
 * Called when the capture loopback pre-flight fails before a bulk build.
 * Provides the specific error code + message so the user (or support) can
 * act immediately without having to dig through logs.
 *
 * @param {object} data  { code, message, [ttfb] } from the PHP handler.
 */
const ncxShowPreflightError = (data) => {
    const code    = data?.code    || 'nexeng_preflight_unknown';
    const message = data?.message || 'Capture loopback check failed — the server is not correctly routing requests to PHP.';

    // Remove any existing preflight modal.
    document.querySelector('.ncx-preflight-modal')?.remove();

    const modal = document.createElement('div');
    modal.className = 'ncx-preflight-modal';
    modal.style.cssText = [
        'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center',
        'background:rgba(0,0,0,.55);backdrop-filter:blur(3px)',
    ].join(';');

    modal.innerHTML = `
<div style="background:#1a1a2e;border:1px solid #e24b4a;border-radius:12px;padding:32px 36px;max-width:560px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.6)">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">
    <span style="font-size:24px">⚠️</span>
    <h3 style="margin:0;color:#fff;font-size:16px;font-weight:600">Server Compatibility Diagnostic</h3>
  </div>
  <p style="margin:0 0 12px;color:#f87171;font-size:13px;line-height:1.6">${message.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</p>
  <div style="background:#0d0d1a;border:1px solid #333;border-radius:8px;padding:12px 14px;margin-bottom:20px">
    <p style="margin:0;color:#6b7280;font-size:11px;font-family:monospace">Error code: <span style="color:#f59e0b">${code}</span></p>
  </div>
  <p style="margin:0 0 20px;color:#9ca3af;font-size:12px;line-height:1.5">
    This diagnostic should be checked before a large production build, but a temporary LocalWP worker timeout can also trigger it while the site is busy.<br><br>
    <strong style="color:#e2e8f0">Common causes:</strong><br>
    • LocalWP/PHP-FPM: another heavy build or editor request exhausted the worker pool<br>
    • nginx: query-string capture requests are being caught by a static-file rule before WordPress<br>
    • Apache: missing or conflicting <code style="color:#f59e0b">.htaccess</code> Nexora block<br>
    • Host: loopback HTTP requests blocked by firewall
  </p>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <button class="ncx-preflight-nginx-btn" style="background:#0252fa;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:13px;cursor:pointer;font-weight:600">
      📋 Show nginx Config Snippet
    </button>
    <button class="ncx-preflight-close-btn" style="background:transparent;color:#9ca3af;border:1px solid #374151;border-radius:8px;padding:10px 18px;font-size:13px;cursor:pointer">
      Dismiss
    </button>
  </div>
</div>`;

    document.body.appendChild(modal);

    modal.querySelector('.ncx-preflight-close-btn').addEventListener('click', () => modal.remove());
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });

    modal.querySelector('.ncx-preflight-nginx-btn').addEventListener('click', async () => {
        const res = await ncxCall('ssg_nginx_config');
        if (!res?.success) { ncxToast('Could not load config snippet.', 'error'); return; }
        const snippet = res.data?.config || '';

        const snipWrap = modal.querySelector('.ncx-preflight-nginx-snippet') || (() => {
            const d = document.createElement('div');
            d.className = 'ncx-preflight-nginx-snippet';
            d.style.cssText = 'margin-top:16px;background:#0d0d1a;border:1px solid #374151;border-radius:8px;padding:14px;';
            d.innerHTML = `<p style="margin:0 0 8px;color:#9ca3af;font-size:11px">Paste this into your nginx <code style="color:#f59e0b">server { }</code> block, then reload nginx:</p>
                           <pre style="margin:0;color:#34d399;font-size:11px;white-space:pre-wrap;word-break:break-all;font-family:monospace">${snippet.replace(/</g,'&lt;')}</pre>
                           <button class="ncx-preflight-copy" style="margin-top:10px;background:#1e293b;color:#e2e8f0;border:1px solid #374151;border-radius:6px;padding:6px 14px;font-size:11px;cursor:pointer">Copy</button>`;
            modal.querySelector('div').appendChild(d);
            return d;
        })();

        snipWrap.style.display = '';
        snipWrap.querySelector('.ncx-preflight-copy')?.addEventListener('click', () => {
            navigator.clipboard.writeText(snippet).then(() => ncxToast('nginx config copied!', 'success'));
        });
    });
};

const ncxSetLoading = (btn, isLoading) => {
    if (isLoading) {
        btn.dataset.originalText = btn.innerHTML;
        // Icon-only buttons (no visible text) get a spinner alone — no text label —
        // so small icon buttons don't expand and break table row layout.
        const btnText = (btn.innerText || '').trim();
        btn.innerHTML = '<span class="ncx-spinner-tiny"></span>' + (btnText ? ' ' + btnText : '');
        btn.disabled = true;
    } else {
        btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
        btn.disabled = false;
    }
};

const ncxCall = async (action, data = {}) => {
    const formData = new FormData();
    formData.append('action', 'nexeng_' + action);
    formData.append('nonce', ncxVars.nonce);
    for (const key in data) {
        if (typeof data[key] === 'object' && data[key] !== null) {
            for (const subKey in data[key]) {
                formData.append(`${key}[${subKey}]`, data[key][subKey]);
            }
        } else {
            formData.append(key, data[key]);
        }
    }

    try {
        const res = await fetch(ncxVars.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
        if (!res.ok) {
            return { success: false, data: { message: `Server error (${res.status})` } };
        }
        return await res.json();
    } catch (err) {
        return { success: false, data: { message: 'Network error' } };
    }
};

// ── Admin-notice eviction ────────────────────────────────────────────────────
// PHP-rendered admin_notices fire BEFORE our page callback, so they land in
// the DOM correctly before .ncx-admin-wrapper.
// However, some plugins (Elementor Pro, image-optimiser plugins, etc.) inject
// their notices via JavaScript after page load, targeting .wrap or
// #wpbody-content.  Those notices can end up as direct children of our wrapper.
// This IIFE moves any such notices back to before .ncx-admin-wrapper and uses
// a MutationObserver to catch ones that arrive after DOMContentLoaded.
(function () {
    'use strict';

    function evictNotices() {
        var wrapper = document.querySelector('.ncx-admin-wrapper');
        if (!wrapper || !wrapper.parentNode) return;
        var parent = wrapper.parentNode;

        // Snapshot children to avoid live-collection mutation during iteration.
        var children = Array.prototype.slice.call(wrapper.children);
        children.forEach(function (el) {
            var cls = (el.className || '').toString();
            var id  = (el.id  || '').toString();
            // Never evict Nexora page shells — only WordPress/plugin notices.
            if (cls.indexOf('ncx-wizard-wrap') !== -1 || cls.indexOf('ncx-dashboard') !== -1) {
                return;
            }
            // Match WordPress standard notices and common plugin notice patterns.
            if (
                cls.indexOf('notice')      !== -1 ||
                cls.indexOf('updated')     !== -1 ||
                cls.indexOf('update-nag')  !== -1 ||
                cls.indexOf('elementor')   !== -1 ||
                id.indexOf('elementor')    !== -1 ||
                el.getAttribute('data-dismissible') !== null
            ) {
                parent.insertBefore(el, wrapper);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Handle notices already in the DOM (PHP-rendered late hooks or eager JS).
        evictNotices();

        // Watch for notices injected after DOMContentLoaded (Elementor async check,
        // licence-status AJAX responses, etc.).
        var wrapper = document.querySelector('.ncx-admin-wrapper');
        if (wrapper && window.MutationObserver) {
            new MutationObserver(evictNotices).observe(wrapper, { childList: true });
        }
    });
}());

document.addEventListener('DOMContentLoaded', () => {
    // 1. Tab Switching
    document.querySelectorAll('.ncx-tab-trigger').forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.target;
            document.querySelectorAll('.ncx-tab-trigger').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.ncx-tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            const targetEl = document.getElementById('ncx-tab-' + target);
            if (targetEl) targetEl.classList.add('active');
            localStorage.setItem('nexeng_active_tab', target);
        });
    });

    const lastTab = localStorage.getItem('nexeng_active_tab');
    if (lastTab) {
        const tabEl = document.querySelector(`.ncx-tab-trigger[data-target="${lastTab}"]`);
        if (tabEl) tabEl.click();
    }

    // 2. Global Toggles (Auto-Save)
    document.querySelectorAll('.ncx-toggle-auto').forEach(toggle => {
        toggle.addEventListener('change', async () => {
            const opt = toggle.dataset.option;
            const val = toggle.checked ? 'on' : 'off';
            const res = await ncxCall('save_settings', { [`settings[${opt}]`]: val });
            if (res.success) {
                ncxToast(`${toggle.dataset.label || 'Setting'} updated`, 'success');
                // SSG on/off changes panel state site-wide — reload to reflect new state.
                // headless_mode (Ghost Protocol) also needs a reload to update the right card.
                if (opt === 'ssg_enabled' || opt === 'headless_mode') {
                    setTimeout(() => location.reload(), 1200);
                }
            }
        });
    });

    // 3. Regeneration Logic
    window.ncxRegenerateOne = async function(postId, btn = null) {
        if (btn) ncxSetLoading(btn, true);
        ncxToast('Regenerating page...', 'info');
        const res = await ncxCall('ssg_regen_one', { post_id: postId });
        if (btn) ncxSetLoading(btn, false);
        if (res.success) {
            const msg = res.data?.message || 'Page regenerated. Static mirror is now up to date.';

            // Always keep ncxRpUrl current — without this it shows the last
            // bulk-build URL indefinitely after a Quick Edit single-page regen.
            const urlEl = document.getElementById('ncxRpUrl');
            if (urlEl && res.data?.url) {
                let urlPath = res.data.url;
                try { urlPath = new URL(urlPath).pathname; } catch (_) {}
                urlEl.textContent = res.data.queued
                    ? 'Queued: ' + urlPath
                    : 'Last rebuilt: ' + urlPath;
            }

            if (res.data?.queued) {
                // Build is running — page was injected at the front of the queue.
                // Show info (not success) and open Build Control so user can watch it progress.
                ncxToast(msg, 'info');
                // Open the Build Control panel if it's collapsed.
                const panel = document.querySelector('.ncx-regen-progress-panel--global');
                if (panel && panel.style.display === 'none') panel.style.display = '';
                const regenPanel = document.querySelector('.ncx-regen-panel-wrap');
                if (regenPanel) regenPanel.style.display = '';
                // Do NOT reload — the page hasn't been captured yet.
            } else {
                ncxToast(msg, 'success');
                // Update the row immediately so the user sees live feedback before the reload.
                if (btn) {
                    const row = btn.closest('.ncx-page-row');
                    if (row) {
                        row.dataset.capture = 'captured';
                        // Status badge (3rd column)
                        const badgeCell = row.querySelector('td:nth-child(3)');
                        if (badgeCell) badgeCell.innerHTML = '<span class="ncx-badge success">Captured</span>';
                        // Last-optimised time (4th column)
                        const dateCell = row.querySelector('.ncx-date-cell');
                        if (dateCell) dateCell.textContent = 'just now';
                    }
                }
                setTimeout(() => location.reload(), 1000);
            }
        } else {
            const errCode = res.data?.code;
            const errMsg  = res.data?.message || 'Regeneration failed.';
            // Memory exhaustion is a warning, not a hard error — page will load
            // dynamically until memory is raised. Show amber toast with advice.
            // SSG disabled — reload the page so the UI updates and removes the
            // refresh buttons (master switch flipped off in another tab).
            if (errCode === 'ssg_disabled') {
                ncxToast(errMsg, 'warning');
                setTimeout(() => location.reload(), 1500);
            } else {
                ncxToast(errMsg, errCode === 'memory_limit' ? 'warning' : 'error');
            }
        }
    };

    window.ncxCentralRegenAll = true;

    let ncxRegenPaused = false;
    let ncxRegenRunning = false;
    let ncxRegenPollTimer = null;
    let ncxRegenRetries = 0;
    let ncxRegenTotal = 0;
    let ncxRegenProcessed = 0;
    let ncxRegenBuildSession = '';
    let ncxRegenReloadOnComplete = true;
    let ncxRegenControlsBound = false;

    const ncxGetRegenPanel = () => ({
        panel: document.getElementById('ncxRegenProgressPanel'),
        fill: document.getElementById('ncxRpFill'),
        pct: document.getElementById('ncxRpPct'),
        count: document.getElementById('ncxRpCount'),
        status: document.getElementById('ncxRpStatus'),
        url: document.getElementById('ncxRpUrl'),
        summary: document.getElementById('ncxRpSummary'),
        mode: document.getElementById('ncxRpMode'),
        note: document.getElementById('ncxRpNote'),
        advice: document.getElementById('ncxRpAdvice'),
        lastBuild: document.getElementById('ncxRpLastBuild'),
        cron: document.getElementById('ncxRpCron'),
        pauseBtn: document.getElementById('ncxRpPauseBtn'),
        pauseIcon: document.getElementById('ncxRpPauseIcon'),
        pauseLabel: document.getElementById('ncxRpPauseLabel'),
        stopBtn: document.getElementById('ncxRpStopBtn'),
        resultBox: document.getElementById('ncxBuildResultBox')
    });

    // Minimal HTML-escape helper for JS-generated error card content.
    const ncxEscHtml = s => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const ncxDecodeHtml = s => {
        if (!s) return '';
        const el = document.createElement('textarea');
        el.innerHTML = String(s);
        return el.value;
    };
    const ncxDedupeBuildErrors = (errors = []) => {
        const seen = new Set();
        return errors.filter(e => {
            const key = String(e.post_id || e.url || e.message || e.title || '');
            if (!key || seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    };
    const ncxErrorsAreHttpAuth = (errors = []) => {
        if (!errors.length) return false;
        const authLike = errors.filter(e =>
            e.code === 'nexeng_ssg_http_auth'
            || (e.message && /401|authentication required/i.test(e.message))
        );
        return authLike.length >= Math.max(1, Math.ceil(errors.length * 0.5));
    };

    const ncxShowRegenPanel = () => {
        const ui = ncxGetRegenPanel();
        if (!ui.panel) return null;
        ui.panel.style.display = 'block';
        ui.panel.classList.remove('is-complete', 'is-error', 'is-idle');
        return ui;
    };

    const ncxSetRegenStatus = (text, state = 'running') => {
        const { panel, status } = ncxGetRegenPanel();
        if (status) {
            status.textContent = text;
            status.className = 'ncx-rp-status-pill ncx-rp-' + state;
        }
        if (panel) {
            panel.classList.toggle('is-running', state === 'running');
            panel.classList.toggle('is-paused',  state === 'paused');
            panel.classList.toggle('is-complete', state === 'complete');
            panel.classList.toggle('is-error', state === 'error');
            panel.classList.toggle('is-idle', state === 'idle');
        }
        // Disable destructive/conflicting buttons while a build is active.
        const buildActive = (state === 'running' || state === 'paused');
        document.querySelectorAll('.ncx-regen-all, [data-action="ssg_purge"]').forEach(btn => {
            btn.disabled = buildActive;
            btn.style.opacity = buildActive ? '0.45' : '';
            btn.style.pointerEvents = buildActive ? 'none' : '';
            if (buildActive) {
                btn.title = 'A build is currently active — complete or stop it first.';
            } else {
                btn.title = '';
            }
        });
    };

    const ncxApplyRegenState = (data = {}) => {
        const ui = ncxGetRegenPanel();
        if (data.build_session && data.build_session !== ncxRegenBuildSession) {
            ncxRegenBuildSession = data.build_session;
            ncxRegenProcessed = 0;
            ncxRegenTotal = Number(data.total || 0);
        }
        const processed = Number(data.processed || 0);
        ncxRegenProcessed = processed;
        const total = Number(data.total || ncxRegenTotal);
        if (total > 0) {
            ncxRegenTotal = total;
        }
        ncxRegenPaused = !!data.paused;

        const pct = ncxRegenTotal > 0 ? Math.min(Math.round((processed / ncxRegenTotal) * 100), 100) : 0;
        if (ui.fill) ui.fill.style.width = pct + '%';
        if (ui.pct) ui.pct.textContent = pct + '%';
        if (ui.count) ui.count.textContent = `${processed} / ${ncxRegenTotal || '-'}`;
        if (ui.url) {
            let urlDisplay = data.last_url || '';
            if (urlDisplay) {
                try { urlDisplay = new URL(urlDisplay).pathname; } catch (e) {}
            } else {
                urlDisplay = data.done ? 'Build complete.' : (ncxRegenPaused ? 'Build paused.' : 'Capturing static pages...');
            }
            ui.url.textContent = urlDisplay;
        }
        if (ui.mode) ui.mode.textContent = data.running ? 'Browser driven' : 'Queue controlled';
        if (ui.summary) ui.summary.textContent = data.running ? 'The static mirror is being rebuilt. Existing pages remain live until each new capture is ready.' : 'Regenerate actions from any page appear here.';
        if (ui.note) {
            ui.note.textContent = data.done
                ? ''
                : (ncxRegenPaused ? 'Build paused. Resume when the server is ready.' : 'Capturing one URL per poll to keep LocalWP and shared hosts responsive.');
        }
        if (ui.advice) ui.advice.textContent = data.running ? 'Keep this page open for fastest processing. Cron fallback can continue if you leave.' : 'After content edits, rebuild the changed page when possible. Use a full build after theme, menu, or plugin changes.';
        if (ui.pauseIcon) ui.pauseIcon.className = 'dashicons ' + (ncxRegenPaused ? 'dashicons-controls-play' : 'dashicons-controls-pause');
        if (ui.pauseLabel) ui.pauseLabel.textContent = ncxRegenPaused ? 'Resume' : 'Pause';

        if (data.done) {
            ncxSetRegenStatus('Completed', 'complete');
        } else if (ncxRegenPaused) {
            ncxSetRegenStatus('Paused', 'paused');
        } else if (data.last_url) {
            ncxSetRegenStatus('Running', 'running');
        } else {
            ncxSetRegenStatus('Running', 'running');
        }
    };

    const ncxScheduleRegenPoll = (paused = false) => {
        if (ncxRegenPollTimer) clearTimeout(ncxRegenPollTimer);
        ncxRegenPollTimer = setTimeout(ncxRunRegenBatch, paused ? 4000 : 1500);
    };

    const ncxFinishRegen = (processed = 0, batchData = {}) => {
        ncxRegenRunning = false;
        if (ncxRegenPollTimer) clearTimeout(ncxRegenPollTimer);
        ncxRegenPollTimer = null;
        const ui = ncxGetRegenPanel();
        if (ui.panel) ui.panel.classList.add('is-complete');

        const errCount    = (batchData.errors || 0);
        const recentErrors = batchData.recent_errors || [];
        const successCount = Math.max(0, (processed || ncxRegenTotal || 0) - errCount);

        const statusText  = errCount > 0 ? `${errCount} failed` : 'Completed';
        const statusState = errCount > 0 ? 'warning' : 'complete';
        ncxSetRegenStatus(statusText, statusState);

        const message = errCount > 0
            ? `${successCount} page${successCount !== 1 ? 's' : ''} captured, ${errCount} failed — see errors below.`
            : `${processed || ncxRegenTotal || 'All'} pages regenerated. Static mirror is up to date.`;

        if (ui.summary) ui.summary.textContent = errCount > 0
            ? 'Build finished with errors. Captured pages are live; failed pages load dynamically.'
            : 'Build complete. Static visitors now receive the refreshed mirror.';

        if (ui.url) ui.url.textContent = errCount > 0
            ? `${successCount} captured · ${errCount} skipped`
            : 'Build completed successfully.';

        if (ui.note) ui.note.textContent = '';
        if (ui.lastBuild) ui.lastBuild.textContent = 'Just now';

        // ── Render build result box ───────────────────────────────────────────
        if (ui.resultBox) {
            let html = '';

            if (errCount > 0) {
                // Stats header
                html += `<div class="ncx-rp-result-header">
                    <div class="ncx-rp-result-stat ncx-rp-stat--success">
                        <span class="ncx-rp-stat-num">${successCount}</span>
                        <span class="ncx-rp-stat-lbl">Captured</span>
                    </div>
                    <div class="ncx-rp-result-stat ncx-rp-stat--fail">
                        <span class="ncx-rp-stat-num">${errCount}</span>
                        <span class="ncx-rp-stat-lbl">Failed</span>
                    </div>
                </div>`;

                html += `<p class="ncx-rp-result-tip">Failed pages serve dynamically. Use Retry Failed Pages to recapture only the pages below.</p>`;
                html += `<button type="button" class="ncx-btn ncx-btn-sm ncx-btn-outline ncx-retry-errors-btn" style="margin-bottom:8px;">
                    <span class="dashicons dashicons-update" style="margin-top:3px;"></span> Retry Failed Pages
                </button>`;

                // Error items
                if (recentErrors.length > 0) {
                    html += '<div class="ncx-rp-error-list">';
                    recentErrors.forEach(e => {
                        const title = ncxEscHtml(e.title || 'Unknown page');
                        const rawUrl = e.url || '';
                        const postId = parseInt(e.post_id || 0, 10);
                        let urlPath = rawUrl;
                        try { urlPath = new URL(rawUrl).pathname; } catch (ex) {}
                        const urlHtml = rawUrl
                            ? `<span class="ncx-rp-error-url">${ncxEscHtml(urlPath)}</span>`
                            : '';
                        const msg = e.message || '';
                        const isTimeout = msg.toLowerCase().includes('timed out') || msg.toLowerCase().includes('curl error 28');
                        const isDynamic = /checkout|cart|my-account|order/i.test(rawUrl);
                        let hint = '';
                        if (isTimeout && isDynamic) {
                            hint = `<p class="ncx-rp-error-hint">This is a dynamic WooCommerce page that cannot be statically captured.</p>`;
                        } else if (isTimeout) {
                            hint = `<p class="ncx-rp-error-hint">The server took too long to respond. Try clicking <strong>Visit page</strong> to see if it loads slowly. If it keeps failing, click <strong>Exclude this page</strong> below.</p>`;
                        }
                        const msgHtml = msg ? `<p class="ncx-rp-error-msg">${ncxEscHtml(msg)}</p>` : '';
                        // One-click actions: visit the page (for diagnostics) and exclude
                        // it from future builds (resolves the error permanently).
                        // Exclude is only meaningful when we have a real post_id —
                        // archives can't be excluded per-row (they're tied to taxonomies).
                        let actionsHtml = '<div class="ncx-rp-error-actions">';
                        if (rawUrl) {
                            actionsHtml += `<a class="ncx-btn ncx-btn-xs ncx-btn-outline" href="${ncxEscHtml(rawUrl)}" target="_blank" rel="noopener noreferrer" title="Open the page in a new tab to investigate why it's slow">
                                <span class="dashicons dashicons-external"></span> Visit page
                            </a>`;
                        }
                        if (postId > 0) {
                            actionsHtml += `<button type="button" class="ncx-btn ncx-btn-xs ncx-btn-danger ncx-exclude-page-btn" data-post-id="${postId}" data-title="${title}" title="Mark this page as excluded so it stops appearing in builds and serves dynamically">
                                <span class="dashicons dashicons-no-alt"></span> Exclude this page
                            </button>`;
                        }
                        actionsHtml += '</div>';
                        html += `<div class="ncx-rp-error-item" data-post-id="${postId}">
                            <div class="ncx-rp-error-head">
                                <span class="ncx-rp-error-icon">✗</span>
                                <strong class="ncx-rp-error-title">${title}</strong>
                            </div>
                            ${urlHtml}${msgHtml}${hint}${actionsHtml}
                        </div>`;
                    });
                    html += '</div>';
                }
            } else {
                // All success — compact green summary
                html += `<div class="ncx-rp-result-header">
                    <div class="ncx-rp-result-stat ncx-rp-stat--success" style="flex-direction:row;gap:10px;justify-content:flex-start;padding:12px 16px;">
                        <span class="ncx-rp-stat-num">${successCount || ncxRegenTotal || '✓'}</span>
                        <span class="ncx-rp-stat-lbl" style="text-align:left;text-transform:none;font-size:12px;letter-spacing:0;">pages in the static mirror. Visitors are now being served the fresh build.</span>
                    </div>
                </div>`;
            }

            ui.resultBox.innerHTML = html;
            ui.resultBox.style.display = '';
        }

        try {
            localStorage.setItem('nexeng_last_regen_result', JSON.stringify({ message, processed: processed || ncxRegenTotal || 0, time: Date.now() }));
        } catch (e) {}

        ncxToast(message, errCount > 0 ? 'warning' : 'success');

        // Only auto-reload on clean success — keep errors visible so user can act on them.
        if (ncxRegenReloadOnComplete && errCount === 0) {
            setTimeout(() => location.reload(), 1500);
        }
    };

    async function ncxRunRegenBatch() {
        ncxRegenPollTimer = null;
        try {
            const batch = await ncxCall('ssg_regen_all_batch');
            if (batch && batch.success) {
                ncxRegenRetries = 0;
                ncxApplyRegenState(batch.data);
                if (batch.data.done) {
                    ncxFinishRegen(batch.data.processed || 0, batch.data);
                    return;
                }
                ncxScheduleRegenPoll(!!batch.data.paused);
                return;
            }

            const msg = batch?.data?.message || 'Build interrupted';
            // Retry limit raised to 20 (was 8) — tolerates Chrome's ~1-min background-tab
            // timer throttling when the user has Elementor editor open in another tab.
            if (++ncxRegenRetries < 20) {
                ncxSetRegenStatus('Connection weak. Retrying...', 'warning');
                ncxScheduleRegenPoll(false);
                return;
            }
            ncxRegenRunning = false;
            ncxSetRegenStatus(msg, 'error');
            const { note } = ncxGetRegenPanel();
            if (note) note.textContent = msg;
            ncxToast(msg, 'error');
        } catch (err) {
            // Retry limit raised to 12 (was 6) for same background-throttle reason.
            if (++ncxRegenRetries <= 12) {
                ncxSetRegenStatus(`Connection weak. Retrying (${ncxRegenRetries}/12)`, 'warning');
                ncxScheduleRegenPoll(false);
            } else {
                ncxRegenRunning = false;
                ncxSetRegenStatus('Unable to continue', 'error');
                const { note } = ncxGetRegenPanel();
                if (note) note.textContent = 'The browser lost the build connection. Refresh this page to reattach to the queue.';
                ncxToast('Regeneration failed after multiple retries.', 'error');
            }
        }
    }

    const ncxBindRegenControls = () => {
        if (ncxRegenControlsBound) return;
        ncxRegenControlsBound = true;
        document.addEventListener('click', async (e) => {
            const pauseBtn = e.target.closest('#ncxRpPauseBtn');
            if (pauseBtn) {
                e.preventDefault();
                if (!ncxRegenRunning) return;
                pauseBtn.disabled = true;
                if (ncxRegenPollTimer) {
                    clearTimeout(ncxRegenPollTimer);
                    ncxRegenPollTimer = null;
                }
                if (ncxRegenPaused) {
                    await ncxCall('ssg_bulk_resume');
                    ncxRegenPaused = false;
                } else {
                    await ncxCall('ssg_bulk_pause');
                    ncxRegenPaused = true;
                }
                ncxApplyRegenState({ paused: ncxRegenPaused, total: ncxRegenTotal, processed: ncxRegenProcessed });
                ncxScheduleRegenPoll(ncxRegenPaused);
                pauseBtn.disabled = false;
            }

            const retryBtn = e.target.closest('.ncx-retry-errors-btn');
            if (retryBtn) {
                e.preventDefault();
                if (ncxRegenRunning) {
                    ncxToast('A build is already running — wait for it to finish first.', 'warning');
                    return;
                }
                retryBtn.disabled = true;
                retryBtn.innerHTML = '<span class="ncx-spinner-tiny"></span> Retrying…';
                const res = await ncxCall('ssg_retry_errors');
                if (res.success && res.data?.queued > 0) {
                    ncxToast(`${res.data.queued} failed page(s) re-queued. Starting capture…`, 'info');
                    const ui = ncxGetRegenPanel();
                    if (ui.resultBox) ui.resultBox.style.display = 'none';
                    if (document.getElementById('step-4') && typeof startWizardBuild === 'function') {
                        window._ncxBuildErrors = [];
                        goToStep(4);
                        startWizardBuild();
                    } else {
                        ncxRegenRunning = true;
                        ncxRegenPaused = false;
                        ncxRegenRetries = 0;
                        ncxRegenTotal = res.data.total || res.data.queued || 0;
                        ncxRegenProcessed = 0;
                        ncxApplyRegenState({ running: true, total: ncxRegenTotal, processed: 0, errors: 0, last_url: '' });
                        ncxSetRegenStatus('Running', 'running');
                        ncxRunRegenBatch();
                    }
                } else {
                    ncxToast(res.data?.message || 'Nothing to retry.', 'info');
                    retryBtn.disabled = false;
                    retryBtn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Retry Failed Pages';
                }
                return;
            }

            // ── One-click "Exclude this page" from a failed-build error row ──
            // Fires the ssg_exclude_page AJAX handler which sets _nexeng_exclude=1,
            // deletes any stale static file, and removes the post from both
            // the pending queue and the error log. Updates the UI immediately
            // so the failed row disappears without a page reload.
            const excludeBtn = e.target.closest('.ncx-exclude-page-btn');
            if (excludeBtn) {
                e.preventDefault();
                e.stopPropagation();
                const postId = parseInt(excludeBtn.dataset.postId || 0, 10);
                const title  = excludeBtn.dataset.title || 'this page';
                if (!postId) return;
                if (!confirm(`Exclude "${title}" from static builds? It will serve dynamically and won't appear in future builds.`)) return;
                excludeBtn.disabled = true;
                excludeBtn.innerHTML = '<span class="ncx-spinner-tiny"></span> Excluding…';
                const res = await ncxCall('ssg_exclude_page', { post_id: postId });
                if (res.success) {
                    ncxToast(res.data?.message || 'Page excluded.', 'success');
                    // Fade out the matching error row(s).
                    document.querySelectorAll(`.ncx-rp-error-item[data-post-id="${postId}"]`).forEach(row => {
                        row.style.transition = 'opacity .3s, max-height .3s';
                        row.style.opacity = '0';
                        row.style.maxHeight = '0';
                        row.style.overflow = 'hidden';
                        setTimeout(() => row.remove(), 320);
                    });
                    // Decrement the failed counter in the result header.
                    const failNumEl = document.querySelector('.ncx-rp-stat--fail .ncx-rp-stat-num');
                    if (failNumEl) {
                        const newCount = Math.max(0, parseInt(failNumEl.textContent, 10) - 1);
                        failNumEl.textContent = newCount;
                        // If no failures remain, hide the retry button and tip.
                        if (newCount === 0) {
                            document.querySelectorAll('.ncx-retry-errors-btn, .ncx-rp-result-tip').forEach(el => {
                                el.style.transition = 'opacity .3s';
                                el.style.opacity = '0';
                                setTimeout(() => el.remove(), 320);
                            });
                        }
                    }
                } else {
                    ncxToast(res.data?.message || 'Could not exclude page.', 'error');
                    excludeBtn.disabled = false;
                    excludeBtn.innerHTML = '<span class="dashicons dashicons-no-alt"></span> Exclude this page';
                }
                return;
            }

            const stopBtn = e.target.closest('#ncxRpStopBtn');
            if (stopBtn) {
                e.preventDefault();
                if (!ncxRegenRunning) return;
                const stopOk = await ncxConfirmModal({
                    title: 'Stop Build',
                    body: '<p>Stop the current rebuild? Pages captured so far will stay live. You can start a new build at any time.</p>',
                    confirmText: 'Stop Build',
                    confirmClass: 'ncx-btn-danger',
                    type: 'stop',
                });
                if (!stopOk) return;
                stopBtn.disabled = true;
                if (ncxRegenPollTimer) {
                    clearTimeout(ncxRegenPollTimer);
                    ncxRegenPollTimer = null;
                }
                await ncxCall('ssg_bulk_stop');
                ncxRegenRunning = false;
                ncxSetRegenStatus('Stopped', 'paused');
                const { note, summary } = ncxGetRegenPanel();
                if (summary) summary.textContent = 'Build stopped by user. Captured pages remain available.';
                if (note) note.textContent = 'Pending queue cleared. Start a new build when ready.';
                ncxToast('Regeneration stopped.', 'info');
                stopBtn.disabled = false;
            }
        });
    };

    const ncxHydrateRegenPanel = async () => {
        const ui = ncxGetRegenPanel();
        if (!ui.panel) return;
        ncxBindRegenControls();
        try {
            const saved = JSON.parse(localStorage.getItem('nexeng_last_regen_result') || 'null');
            if (saved && saved.message && Date.now() - saved.time < 24 * 60 * 60 * 1000) {
                if (ui.note) ui.note.textContent = saved.message;
                if (ui.lastBuild) ui.lastBuild.textContent = 'Recently';
                ncxSetRegenStatus('Ready', 'idle');
            }
        } catch (e) {}

        try {
            const batch = await ncxCall('ssg_regen_all_batch');
            if (batch && batch.success && batch.data) {
                ncxApplyRegenState(batch.data);
                if (batch.data.running && !batch.data.done) {
                    ncxRegenRunning = true;
                    // If the build just kicked off and hasn't captured a URL yet,
                    // clear the stale PHP-rendered URL so the old value isn't shown.
                    if (!batch.data.last_url && !batch.data.processed) {
                        if (ui.url) ui.url.textContent = 'Initializing rebuild…';
                    }
                    ncxSetRegenStatus(batch.data.paused ? 'Paused' : 'Building', batch.data.paused ? 'paused' : 'running');
                    ncxScheduleRegenPoll(!!batch.data.paused);
                } else if (!batch.data.running && !batch.data.done) {
                    ncxSetRegenStatus('Ready', 'idle');
                    if (ui.url) ui.url.textContent = 'Waiting for the next build command.';
                    if (ui.note && !ui.note.textContent.trim()) ui.note.textContent = 'No active build. The next regenerate action will start here.';
                }
            }
        } catch (e) {}
    };

    window.ncxRegenerateAll = async function(reloadOnComplete = true) {
        ncxBindRegenControls();
        ncxRegenReloadOnComplete = reloadOnComplete;
        const ui = ncxShowRegenPanel();

        if (ncxRegenRunning) {
            ncxToast('Regeneration is already running in the central panel.', 'info');
            return;
        }

        const rebuildOk = await ncxConfirmModal({
            title: 'Rebuild Full Mirror',
            body: `<p>This will capture every published page, post, and archive as a static HTML file.</p>
                   <ul>
                     <li>The current mirror stays live while the new one builds</li>
                     <li>Each page is replaced as it finishes — no downtime</li>
                     <li>Large sites can take a few minutes to complete</li>
                   </ul>`,
            confirmText: 'Start Rebuild',
            confirmClass: 'ncx-btn-primary',
            type: 'build',
        });
        if (!rebuildOk) return;
        ncxRegenRunning = true;
        ncxRegenPaused = false;
        ncxRegenRetries = 0;
        ncxRegenTotal = 0;
        ncxRegenProcessed = 0;

        // Clear previous result box before starting fresh build.
        if (ui?.resultBox) { ui.resultBox.innerHTML = ''; ui.resultBox.style.display = 'none'; }
        if (ui?.fill) ui.fill.style.width = '0%';
        if (ui?.pct) ui.pct.textContent = '0%';
        if (ui?.count) ui.count.textContent = '0 / -';
        if (ui?.url) ui.url.textContent = 'Initializing capture pipeline...';
        ncxSetRegenStatus('Running', 'running');

        const res = await ncxCall('ssg_regen_all_start');
        if (!res.success) {
            ncxRegenRunning = false;
            const message = res.data?.message || 'Engine failed to start';
            if (message.toLowerCase().includes('already running')) {
                ncxToast('A build is already running. Reattached to the Build Control panel.', 'info');
                const batch = await ncxCall('ssg_regen_all_batch');
                if (batch && batch.success) {
                    ncxRegenRunning = true;
                    ncxApplyRegenState(batch.data);
                    ncxScheduleRegenPoll(!!batch.data.paused);
                }
                return;
            }
            if (res.data?.preflight_failed) {
                ncxSetRegenStatus('Failed to start', 'error');
                ncxToast(res.data.message || 'Build failed to start. Run Diagnostic for details.', 'error');
                return;
            }
            ncxSetRegenStatus('Failed to start', 'error');
            ncxToast(message, 'error');
            return;
        }

        ncxRegenBuildSession = res.data.build_session || '';
        ncxRegenTotal = res.data.total || 0;
        if (ui?.count) ui.count.textContent = `0 / ${ncxRegenTotal}`;
        ncxSetRegenStatus('Running', 'running');
        ncxRunRegenBatch();
    };

    /**
     * Focused rebuild — regenerates only the pending (changed) pages.
     * Uses the same batch polling loop as a full build; the queue is smaller
     * so it completes much faster.  No confirmation needed (non-destructive).
     */
    window.ncxRegeneratePending = async function() {
        ncxBindRegenControls();
        const ui = ncxShowRegenPanel();

        if (ncxRegenRunning) {
            ncxToast('A build is already running. Check Build Control for progress.', 'info');
            return;
        }

        ncxRegenRunning = true;
        ncxRegenPaused  = false;
        ncxRegenRetries = 0;
        ncxRegenTotal   = 0;
        ncxRegenProcessed = 0;

        // Clear previous result box before starting fresh build.
        if (ui?.resultBox) { ui.resultBox.innerHTML = ''; ui.resultBox.style.display = 'none'; }
        if (ui?.fill)  ui.fill.style.width = '0%';
        if (ui?.pct)   ui.pct.textContent  = '0%';
        if (ui?.count) ui.count.textContent = '0 / -';
        if (ui?.url)   ui.url.textContent   = 'Refreshing changed pages...';
        ncxSetRegenStatus('Running', 'running');

        const res = await ncxCall('ssg_regen_pending');
        if (!res || !res.success) {
            ncxRegenRunning = false;
            if (res?.data?.preflight_failed) {
                ncxSetRegenStatus('Failed', 'error');
                ncxToast(res.data.message || 'Focused refresh failed. Run Diagnostic for details.', 'error');
                return;
            }
            ncxSetRegenStatus('Failed', 'error');
            ncxToast((res?.data?.message) || 'Failed to start focused refresh.', 'error');
            return;
        }

        if ((res.data.total || 0) === 0) {
            ncxRegenRunning = false;
            ncxSetRegenStatus('Ready', 'idle');
            ncxToast(res.data.message || 'No changed pages to refresh.', 'info');
            return;
        }

        ncxRegenTotal = res.data.total;
        if (ui?.count) ui.count.textContent = `0 / ${ncxRegenTotal}`;
        ncxSetRegenStatus('Running', 'running');
        ncxRunRegenBatch();
    };

    /**
     * Archive-only rebuild — category, tag, and blog-index pages.
     */
    window.ncxRegenerateArchives = async function() {
        ncxBindRegenControls();
        const ui = ncxShowRegenPanel();

        if (ncxRegenRunning) {
            ncxToast('A build is already running. Check Build Control for progress.', 'info');
            return;
        }

        const archiveNotice = document.getElementById('ncxArchiveNotice');
        const missingCount = Number(document.getElementById('ncxRegenArchivesBtn')?.dataset.count || 0);

        const rebuildOk = await ncxConfirmModal({
            title: 'Build Archive Pages',
            body: `<p>This captures <strong>${missingCount || 'your'}</strong> category, tag, and blog index URLs as static HTML.</p>
                   <ul>
                     <li>Posts and pages already in the mirror stay live</li>
                     <li>After this, archive URLs should show <code>X-Nexora-Cache: HIT</code> in DevTools</li>
                     <li>Usually finishes in under a minute</li>
                   </ul>`,
            confirmText: 'Build Archives',
            confirmClass: 'ncx-btn-primary',
            type: 'build',
        });
        if (!rebuildOk) return;

        ncxRegenRunning = true;
        ncxRegenPaused  = false;
        ncxRegenRetries = 0;
        ncxRegenTotal   = 0;
        ncxRegenProcessed = 0;

        if (ui?.resultBox) { ui.resultBox.innerHTML = ''; ui.resultBox.style.display = 'none'; }
        if (ui?.fill)  ui.fill.style.width = '0%';
        if (ui?.pct)   ui.pct.textContent  = '0%';
        if (ui?.count) ui.count.textContent = '0 / -';
        if (ui?.url)   ui.url.textContent   = 'Capturing category and tag archives...';
        if (archiveNotice) archiveNotice.style.display = 'none';
        ncxSetRegenStatus('Running', 'running');

        const res = await ncxCall('ssg_regen_archives_start');
        if (!res || !res.success) {
            ncxRegenRunning = false;
            if (archiveNotice) archiveNotice.style.display = '';
            const message = res?.data?.message || 'Failed to start archive build';
            if (message.toLowerCase().includes('already running')) {
                ncxToast('A build is already running. Reattached to the Build Control panel.', 'info');
                const batch = await ncxCall('ssg_regen_all_batch');
                if (batch && batch.success) {
                    ncxRegenRunning = true;
                    ncxApplyRegenState(batch.data);
                    ncxScheduleRegenPoll(!!batch.data.paused);
                }
                return;
            }
            ncxSetRegenStatus('Failed', 'error');
            ncxToast(message, 'error');
            return;
        }

        if ((res.data.total || 0) === 0) {
            ncxRegenRunning = false;
            ncxSetRegenStatus('Ready', 'idle');
            ncxToast(res.data.message || 'No archive pages to capture.', 'info');
            return;
        }

        ncxRegenTotal = res.data.total;
        if (ui?.count) ui.count.textContent = `0 / ${ncxRegenTotal}`;
        ncxSetRegenStatus('Running', 'running');
        ncxRunRegenBatch();
    };

    ncxBindRegenControls();
    ncxHydrateRegenPanel();

    // Tab-visibility resumption — Elementor editor (and any other work that moves
    // the Nexora admin tab to background) causes Chrome to throttle setTimeout to
    // ~1 min.  Without this, the build-poll accumulates retries and eventually
    // marks the build as "failed" requiring a page reload to reattach.
    // On tab focus: reset the retry counter and immediately kick the next poll
    // so the progress bar snaps to the current server state.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') return;
        if (ncxRegenRunning) {
            ncxRegenRetries = 0;
            if (ncxRegenPollTimer) clearTimeout(ncxRegenPollTimer);
            ncxRunRegenBatch();
        }
    });

    // 4. Delegate Individual Regenerate & Queue-Dismiss Buttons
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.ncx-regen-one, .ncx-inline-regen-one');
        if (btn) {
            e.preventDefault();
            const postId = btn.dataset.id || btn.dataset.postId;
            ncxRegenerateOne(postId, btn);
        }

        // ── Dismiss (remove) a single item from the pending queue ──
        const dismissBtn = e.target.closest('.ncx-rp-dismiss-one');
        if (dismissBtn) {
            e.preventDefault();
            e.stopPropagation();
            const postId = dismissBtn.dataset.id;
            const item   = dismissBtn.closest('[data-id]');
            // Optimistically fade out before the server responds.
            if (item) { item.style.transition = 'opacity .2s ease'; item.style.opacity = '0'; }
            (async () => {
                const res = await ncxCall('ssg_remove_pending', { post_id: postId });
                if (res.success) {
                    setTimeout(() => { item?.remove(); }, 220);
                    ncxPanelNotice(res.data?.message || 'Removed from queue.', 'info');
                } else {
                    // Restore visibility on failure.
                    if (item) { item.style.transition = ''; item.style.opacity = '1'; }
                    ncxPanelNotice(res.data?.message || 'Could not remove from queue.', 'error');
                }
            })();
        }

        // ── Clear entire pending queue (escape hatch for stuck queues) ──
        const clearQueueBtn = e.target.closest('#ncxClearQueueBtn, .ncx-rp-clear-queue');
        if (clearQueueBtn) {
            e.preventDefault();
            e.stopPropagation();
            if (!confirm('Remove all items from the queue? Pages will not be rebuilt — they will stay as-is until you manually trigger a refresh.')) return;
            clearQueueBtn.disabled = true;
            (async () => {
                const res = await ncxCall('ssg_clear_all_pending');
                if (res.success) {
                    ncxToast(res.data?.message || 'Queue cleared.', 'success');
                    // Immediately remove the virtual archive queue item (before the 5-second
                    // live-poll fires — the server now suppresses it for 1 hour after clearing).
                    const pendingList = document.getElementById('ncxPendingList');
                    if (pendingList) {
                        const archEl = pendingList.querySelector('[data-id="ncx-virtual-archives"]');
                        if (archEl) {
                            archEl.style.transition = 'opacity .3s';
                            archEl.style.opacity = '0';
                            setTimeout(() => archEl.remove(), 320);
                        }
                        // Also fade out all regular post items.
                        pendingList.querySelectorAll('[data-id]').forEach(el => {
                            el.style.transition = 'opacity .3s';
                            el.style.opacity = '0';
                            setTimeout(() => el.remove(), 320);
                        });
                    }
                    // Hide the archive notice banner.
                    const arcNotice = document.getElementById('ncxArchiveNotice');
                    if (arcNotice) arcNotice.style.display = 'none';
                    // Hide the pending details section.
                    setTimeout(() => {
                        const details = document.getElementById('ncxPendingDetails');
                        if (details) details.style.display = 'none';
                    }, 350);
                    // Zero out the pending counter in the signals row.
                    const pendingEl = document.getElementById('ncxRpPending');
                    if (pendingEl) pendingEl.textContent = '0';
                    // Hide the "Refresh Changed Pages" button.
                    const regenPendBtn = document.getElementById('ncxRegenPendingBtn');
                    if (regenPendBtn) regenPendBtn.style.display = 'none';
                    // Switch "Rebuild Full Mirror" back to primary style.
                    const regenAllBtn = document.querySelector('.ncx-regen-all');
                    if (regenAllBtn) {
                        regenAllBtn.classList.add('ncx-btn-primary');
                        regenAllBtn.classList.remove('ncx-btn-outline');
                    }
                    // Reset summary text.
                    const countText = document.getElementById('ncxPendingCountText');
                    if (countText) countText.textContent = '0 changes queued';
                    // Reset summary paragraph.
                    const summary = document.getElementById('ncxRpSummary');
                    if (summary) summary.textContent = 'Every regenerate action runs here, so builds stay visible and controlled from one place.';
                } else {
                    ncxToast(res.data?.message || 'Could not clear queue.', 'error');
                    clearQueueBtn.disabled = false;
                }
            })();
        }

        const regenAllBtn = e.target.closest('.ncx-regen-all');
        if (regenAllBtn) {
            e.preventDefault();
            const reloadOnFinish = regenAllBtn.dataset.reload !== 'false';
            ncxRegenerateAll(reloadOnFinish);
        }

        const regenPendingBtn = e.target.closest('.ncx-regen-pending');
        if (regenPendingBtn) {
            e.preventDefault();
            ncxRegeneratePending();
        }

        const regenArchivesBtn = e.target.closest('.ncx-regen-archives');
        if (regenArchivesBtn) {
            e.preventDefault();
            ncxRegenerateArchives();
        }
    });

    // 5. Tool Actions
    document.querySelectorAll('.ncx-tool-action').forEach(btn => {
        btn.addEventListener('click', async () => {
            const action = btn.dataset.action;

            if (btn.dataset.confirm) {
                // Use the richer modal for ssg_purge; fall back to plain text modal for others.
                let confirmed = false;
                if (action === 'ssg_purge') {
                    confirmed = await ncxConfirmModal({
                        title: 'Clear Static Cache',
                        body: `<p>This will <strong>delete all captured static files</strong> from the mirror.</p>
                               <ul>
                                 <li>Visitors will receive dynamic PHP pages immediately</li>
                                 <li>Performance benefits of static delivery are paused</li>
                                 <li>Run <strong>Rebuild Full Mirror</strong> afterwards to restore them</li>
                               </ul>`,
                        confirmText: 'Yes, Clear Cache',
                        confirmClass: 'ncx-btn-danger',
                        type: 'danger',
                    });
                } else {
                    confirmed = await ncxConfirmModal({
                        title: 'Confirm Action',
                        body: `<p>${btn.dataset.confirm}</p>`,
                        confirmText: 'Proceed',
                        confirmClass: 'ncx-btn-primary',
                    });
                }
                if (!confirmed) return;
            }

            ncxSetLoading(btn, true);
            const isPurge = action === 'ssg_purge' || action === 'clear_cache' || action === 'purge_cache';
            const res = await ncxCall(action, isPurge ? { nexeng_purge_confirmed: 1 } : {});
            ncxSetLoading(btn, false);
            if (res.success) {
                ncxToast(res.data?.message || 'Action completed', 'success');
                if (btn.dataset.reload) setTimeout(() => location.reload(), 1000);
            } else {
                ncxToast(res.data?.message || 'Action failed', 'error');
            }
        });
    });

    // 6. Visual Rings
    const initVisuals = () => {
        document.querySelectorAll('.ncx-ring-container').forEach(el => {
            const circle = el.querySelector('.ncx-ring-circle');
            if (circle) {
                const score = parseInt(el.dataset.score || 0);
                const radius = circle.r.baseVal.value;
                const circumference = 2 * Math.PI * radius;
                circle.style.strokeDasharray = `${circumference} ${circumference}`;
                circle.style.strokeDashoffset = circumference - (score / 100) * circumference;
            }
        });
        document.querySelectorAll('.ncx-bar-fill').forEach(el => {
            setTimeout(() => { el.style.width = (el.dataset.score || 0) + '%'; }, 200);
        });
    };
    initVisuals();

    // 7. Search & Filters
    const searchInput = document.querySelector('.ncx-search-pages');
    const rows = document.querySelectorAll('.ncx-page-row');
    let ncxPageTypeFilter = 'all';
    let ncxPageCaptureFilter = 'all';

    const ncxApplyPageFilters = () => {
        const query = (searchInput?.value || '').toLowerCase();
        rows.forEach(row => {
            const title = row.dataset.title?.toLowerCase() || '';
            const typeMatch = ncxPageTypeFilter === 'all' || row.dataset.type === ncxPageTypeFilter;
            const captureMatch = ncxPageCaptureFilter === 'all' || row.dataset.capture === ncxPageCaptureFilter;
            const searchMatch = !query || title.includes(query);
            row.style.display = (typeMatch && captureMatch && searchMatch) ? 'table-row' : 'none';
        });
    };

    if (searchInput) {
        searchInput.addEventListener('input', ncxApplyPageFilters);
    }

    const captureSelect = document.querySelector('.ncx-capture-filter-select');
    if (captureSelect) {
        ncxPageCaptureFilter = captureSelect.value || 'all';
        captureSelect.addEventListener('change', () => {
            ncxPageCaptureFilter = captureSelect.value || 'all';
            ncxApplyPageFilters();
        });
    }

    document.querySelectorAll('.ncx-btn[data-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            ncxPageTypeFilter = btn.dataset.filter || 'all';
            document.querySelectorAll('.ncx-btn[data-filter]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            ncxApplyPageFilters();
        });
    });

    document.querySelectorAll('.ncx-btn[data-capture-filter]').forEach(btn => {
        btn.addEventListener('click', () => {
            ncxPageCaptureFilter = btn.dataset.captureFilter || 'all';
            document.querySelectorAll('.ncx-btn[data-capture-filter]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            ncxApplyPageFilters();
        });
    });

    const ncxWizardSaveStagingAuth = async (userInput, passInput, btn) => {
        const user = userInput?.value?.trim() || '';
        const pass = passInput?.value || '';
        if (btn) ncxSetLoading(btn, true);
        const res = await ncxCall('save_settings', {
            'settings[http_auth_user]': user,
            'settings[http_auth_pass]': pass,
        });
        if (btn) ncxSetLoading(btn, false);
        if (res.success) {
            ncxToast(user ? 'Staging credentials saved.' : 'Staging credentials cleared.', 'success');
            document.querySelectorAll('.ncx-wiz-auth-user-input').forEach(el => { el.value = user; });
            document.querySelectorAll('.ncx-wiz-auth-pass-input').forEach(el => { el.value = pass; });
            return true;
        }
        ncxToast(res.data?.message || 'Could not save credentials.', 'error');
        return false;
    };

    document.getElementById('ncx-wiz-save-auth')?.addEventListener('click', async (e) => {
        await ncxWizardSaveStagingAuth(
            document.getElementById('ncx-wiz-auth-user'),
            document.getElementById('ncx-wiz-auth-pass'),
            e.currentTarget
        );
    });

    document.querySelectorAll('.ncx-wiz-save-auth-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const wrap = btn.closest('.ncx-wiz-staging-auth-fields, .ncx-wiz-http-auth-notice');
            const userInput = wrap?.querySelector('.ncx-wiz-auth-user-input');
            const passInput = wrap?.querySelector('.ncx-wiz-auth-pass-input');
            await ncxWizardSaveStagingAuth(userInput, passInput, btn);
        });
    });

    // 8. Setup Wizard Logic
    const ncxWizardWrap = document.querySelector('.ncx-wizard-wrap:not(.ncx-wizard-complete)');
    if (ncxWizardWrap) {
    ncxWizardWrap.classList.add('ncx-js-ready');
    try {
    let ncxMaxWizardStep = 1;
    const goToStep = (stepNum) => {
        ncxMaxWizardStep = Math.max(ncxMaxWizardStep, stepNum);
        document.querySelectorAll('.ncx-wizard-step').forEach(s => s.classList.remove('active'));
        const target = document.getElementById('step-' + stepNum);
        if (target) {
            target.classList.add('active');
            target.closest('.ncx-wiz-body')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        document.querySelectorAll('.ncx-wiz-step-dot').forEach(dot => {
            const n = parseInt(dot.dataset.step);
            dot.classList.remove('active', 'completed');
            if (n < stepNum) dot.classList.add('completed');
            if (n === stepNum) dot.classList.add('active');
            dot.classList.toggle('is-revisitable', n < ncxMaxWizardStep);
        });
    };

    document.querySelectorAll('.ncx-wiz-step-dot').forEach(dot => {
        dot.setAttribute('role', 'button');
        dot.setAttribute('tabindex', '0');
        const openStep = () => {
            const step = parseInt(dot.dataset.step, 10);
            if (step && step < ncxMaxWizardStep) goToStep(step);
        };
        dot.addEventListener('click', openStep);
        dot.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openStep();
            }
        });
    });

    document.querySelectorAll('[data-goto-step]').forEach(link => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const step = parseInt(link.getAttribute('data-goto-step') || '0', 10);
            if (step > 0) {
                goToStep(step);
            }
        });
    });

    // Animate system check cards on step-1 load
    const animateSysChecks = () => {
        document.querySelectorAll('.ncx-check-item').forEach((card, i) => {
            setTimeout(() => card.classList.add('ready'), 80 + i * 110);
        });
        const tierCard = document.getElementById('ncx-tier-prediction');
        if (tierCard) {
            const delay = 80 + document.querySelectorAll('.ncx-check-item').length * 110 + 160;
            setTimeout(() => tierCard.classList.add('visible'), delay);
        }
    };
    if (document.getElementById('step-1')?.classList.contains('active')) {
        setTimeout(animateSysChecks, 220);
    }

    // Shared tier display constants — plain-English names, no "Tier X" jargon
    const tColors = { 1: '#22c55e', 2: '#0252FA', 3: '#f59e0b' };
    const tIcons  = { 1: '⚡', 2: '🚀', 3: '📦' };
    const tLabels = { 1: 'Full Speed', 2: 'Speed Active', 3: 'Pages Built' };
    const tDescs  = {
        1: 'Pages load instantly — your server delivers them directly without running PHP.',
        2: 'Smart cache running — visitors get your pages without hitting the database.',
        3: 'Static pages are ready. One server step will unlock full speed.',
    };
    const tTtfb   = { 1: '~15ms', 2: '~45ms', 3: '~80ms' };

    // Populate step-2 with activation results
    const populateActivationStep = (data) => {
        const tier   = data?.tier || 2;
        const color  = tColors[tier];
        const tierEl = document.getElementById('ncx-activation-tier');
        if (tierEl) {
            tierEl.style.borderColor = color + '40';
            tierEl.style.background  = color + '07';
            // Target explicit classes — never bare tag selectors (would hit the emoji)
            const iconWrap  = document.getElementById('ncx-act-tier-icon-wrap');
            const iconEl    = tierEl.querySelector('.tier-icon');
            const titleEl   = tierEl.querySelector('.ncx-act-tier-title');
            const descEl    = tierEl.querySelector('.ncx-act-tier-desc');
            if (iconEl)   iconEl.textContent   = tIcons[tier];
            if (iconWrap) iconWrap.style.background = color + '18';
            if (titleEl)  titleEl.textContent  = data?.tier_label || tLabels[tier];
            if (descEl)   descEl.textContent   = tDescs[tier];
        }
        // Serve rule status
        const serveEl = document.getElementById('ncx-act-serve');
        if (serveEl) {
            const isMultisite   = !!data?.is_multisite;
            const isNginxActive = data?.nginx_rule_active;
            const isServeOk     = data?.serve_rule || isNginxActive;
            if (isMultisite) {
                serveEl.textContent = isServeOk ? 'Active (per-site)' : 'Network drop-in only';
            } else {
                serveEl.textContent = isServeOk ? 'Active' : (data?.is_nginx ? 'Pending (Nginx — see step 5)' : 'Skipped');
            }
            serveEl.className   = 'ncx-act-status ' + ((isServeOk || (isMultisite && data?.dropin)) ? 'ncx-act-status--on' : 'ncx-act-status--skip');
        }
        // Drop-in status
        const dropinEl = document.getElementById('ncx-act-dropin');
        if (dropinEl) {
            dropinEl.textContent = data?.dropin ? 'Installed' : 'Skipped';
            dropinEl.className   = 'ncx-act-status ' + (data?.dropin ? 'ncx-act-status--on' : 'ncx-act-status--skip');
        }
    };

    // Step 1 CTA — activate + chain through steps
    const btnLaunch = document.getElementById('btn-launch-nexora');
    if (btnLaunch) {
        btnLaunch.addEventListener('click', async () => {
            ncxSetLoading(btnLaunch, true);
            const res = await ncxCall('wizard_activate');
            ncxSetLoading(btnLaunch, false);
            if (!res.success) {
                ncxToast(res.data?.message || 'Activation failed', 'error');
                return;
            }
            btnLaunch.disabled = true;
            btnLaunch.classList.add('is-running');
            const launchText = btnLaunch.querySelector('span');
            if (launchText) launchText.textContent = 'Activation running';
            window._ncxActData = res.data;
            populateActivationStep(res.data);
            goToStep(2);

            // Step 2 → 3 after animation (3s)
            setTimeout(() => {
                goToStep(3);
                const conflictEl = document.getElementById('conflict-container');
                const conflictCount = parseInt(conflictEl?.dataset.conflictCount || '0', 10);
                const hasBlocking = conflictEl?.dataset.hasBlocking === '1';
                const hasSuccess = !!document.querySelector('#conflict-container .ncx-success-state');

                if (hasBlocking) {
                    return;
                }

                // Only auto-skip a clean environment — always pause on Step 3 when notes exist.
                if (conflictCount === 0 && hasSuccess) {
                    setTimeout(() => {
                        goToStep(4);
                        startWizardBuild();
                    }, 2600);
                }
            }, 3000);
        });
    }

    // Manual conflict fix buttons
    document.querySelectorAll('.ncx-fix-conflict').forEach(btn => {
        btn.addEventListener('click', async () => {
            ncxSetLoading(btn, true);
            const res = await ncxCall('wizard_disable_conflict', { slug: btn.dataset.slug });
            if (res.success) {
                ncxToast('Conflict resolved!', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                ncxSetLoading(btn, false);
                ncxToast(res.data?.message || 'Auto-fix failed', 'error');
            }
        });
    });

    // Manual next-step buttons (conflict page "Continue to Build")
    document.querySelectorAll('.ncx-next-step-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const next = parseInt(btn.dataset.next);
            if (next === 4) { goToStep(4); startWizardBuild(); }
            else goToStep(next);
        });
    });

    const btnRefreshConflicts = document.getElementById('btn-refresh-conflicts');
    if (btnRefreshConflicts) {
        btnRefreshConflicts.addEventListener('click', () => location.reload());
    }

    let ncxWizardBuildRunning = false;
    let ncxWizardBuildTotal = 0;
    let ncxWizardBuildProcessed = 0;
    let ncxWizardBuildSession = '';
    let ncxWizardLastProgressAt = 0;
    let ncxWizardLastProgressKey = '';
    let ncxWizardStuckTimer = null;

    const ncxWizardShowRecovery = (show) => {
        const panel = document.getElementById('ncx-build-recovery');
        if (panel) panel.hidden = !show;
    };

    const ncxWizardTouchProgress = (data = {}) => {
        const key = [
            data.build_session || ncxWizardBuildSession || '',
            data.processed || 0,
            data.total || 0,
            data.remaining || '',
            data.last_url || ''
        ].join('|');
        if (key !== ncxWizardLastProgressKey) {
            ncxWizardLastProgressKey = key;
            ncxWizardLastProgressAt = Date.now();
            ncxWizardShowRecovery(false);
        }
        if (ncxWizardStuckTimer) clearTimeout(ncxWizardStuckTimer);
        ncxWizardStuckTimer = setTimeout(() => {
            const staleMs = Date.now() - ncxWizardLastProgressAt;
            const remaining = Number(data.remaining ?? 0);
            const running = !!data.running || ncxWizardBuildRunning;
            if (running && staleMs >= 90000 && (remaining > 0 || ncxWizardBuildRunning)) {
                ncxWizardShowRecovery(true);
            }
        }, 90000);
    };

    const ncxWizardFinishAnyway = async (message) => {
        ncxWizardBuildRunning = false;
        ncxWizardShowRecovery(false);
        if (ncxWizardStuckTimer) clearTimeout(ncxWizardStuckTimer);
        const statusSpan = document.querySelector('#step-4 .current-page');
        if (statusSpan && message) statusSpan.innerText = message;
        await ncxCall('wizard_finish').catch(() => {});
        setTimeout(() => { goToStep(5); runFinalDiagnostic(); }, 800);
    };

    const ncxWizardApplyBuildNote = (breakdown) => {
        const note = document.getElementById('ncx-build-queue-note');
        const panel = document.getElementById('ncx-build-breakdown');
        if (!breakdown) return;

        const posts = Number(breakdown.posts || 0);
        const archives = Number(breakdown.archives || 0);
        const postsEl = document.querySelector('.ncx-bd-posts');
        const archivesEl = document.querySelector('.ncx-bd-archives');

        if (panel) {
            if (posts <= 0 && archives <= 0) {
                panel.hidden = true;
            } else {
                panel.hidden = false;
                if (postsEl) postsEl.textContent = String(posts);
                if (archivesEl) archivesEl.textContent = String(archives);
            }
        }

        if (!note) return;
        if (posts <= 0 && archives <= 0) {
            note.hidden = true;
            return;
        }
        note.hidden = false;
        note.textContent = archives > 0
            ? `${posts} content pages and ${archives} archive pages queued for capture.`
            : `${posts} published pages and posts queued for capture.`;
    };

    const ncxWizardSyncProgress = (data = {}) => {
        if (data.build_session && data.build_session !== ncxWizardBuildSession) {
            ncxWizardBuildSession = data.build_session;
            ncxWizardBuildProcessed = 0;
            ncxWizardBuildTotal = Number(data.total || 0);
        }
        const total = Number(data.total || ncxWizardBuildTotal || 0);
        const processed = Number(data.processed || 0);
        if (total > 0) {
            ncxWizardBuildTotal = total;
        }
        ncxWizardBuildProcessed = processed;
        const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : (data.done ? 100 : 0);
        return { total, processed, pct };
    };

    // Step 4 — SSG build
    const startWizardBuild = async () => {
        const countSpan  = document.querySelector('#step-4 .current-count');
        const totalSpan  = document.querySelector('#step-4 .total-count');
        const pctSpan    = document.querySelector('#step-4 .progress-percentage');
        const fill       = document.querySelector('#step-4 .progress-fill');
        const circle     = document.querySelector('#step-4 .progress-circle');
        const statusSpan = document.querySelector('#step-4 .current-page');

        if (ncxWizardBuildRunning) {
            if (statusSpan) statusSpan.innerText = 'Build already running…';
            return;
        }
        ncxWizardBuildRunning = true;

        const updateVisuals = (data) => {
            const { total, processed, pct } = ncxWizardSyncProgress(data);
            if (totalSpan && total) totalSpan.innerText = total;
            if (countSpan) countSpan.innerText = processed;
            if (pctSpan)   pctSpan.innerText   = pct + '%';
            if (fill)      fill.style.width    = pct + '%';
            if (circle) {
                const circ = 2 * Math.PI * circle.r.baseVal.value;
                circle.style.strokeDashoffset = circ - (pct / 100) * circ;
            }
        };

        const applyStatus = (data) => {
            updateVisuals(data);
            ncxWizardTouchProgress(data);
            if (data?.breakdown) {
                ncxWizardApplyBuildNote(data.breakdown);
            }
            if (data?.last_url && statusSpan) {
                try {
                    const path = new URL(data.last_url).pathname;
                    const errCount = data.errors || data.failed_count || 0;
                    const remaining = Number(data.remaining || 0);
                    const errNote = errCount > 0 ? ` (${errCount} capture errors so far)` : (remaining > 0 ? '' : ' — done');
                    statusSpan.innerText = path + errNote;
                } catch(e) {}
            }
        };

        const attachFromStats = async () => {
            const statsRes = await ncxCall('ssg_stats');
            const bulk = statsRes?.success ? statsRes.data?.bulk : null;
            if (bulk && bulk.running && !bulk.done) {
                applyStatus(bulk);
                if (statusSpan) statusSpan.innerText = bulk.paused ? 'Build paused in Build Control…' : 'Reattaching to active build…';
                return true;
            }
            return false;
        };

        let attached = false;
        try {
            attached = await attachFromStats();
        } catch(e) {}

        if (!attached) {
            const preflight = await ncxCall('ssg_preflight').catch(() => null);
            if (preflight?.success && preflight.data && preflight.data.ok === false) {
                const pfMsg = preflight.data.message || 'Capture preflight failed.';
                if (statusSpan) statusSpan.innerText = pfMsg;
                ncxToast(pfMsg, preflight.data.code === 'nexeng_preflight_http_auth' ? 'warning' : 'error');
                if (preflight.data.code === 'nexeng_preflight_http_auth') {
                    const authPanel = document.getElementById('ncx-wiz-staging-auth');
                    if (authPanel) authPanel.open = true;
                    ncxWizardShowRecovery(true);
                }
            }

            const res = await ncxCall('ssg_regen_all_start');
            if (!res.success) {
                const busyBulk = res.data?.bulk;
                if (res.data?.busy && busyBulk) {
                    applyStatus(busyBulk);
                    attached = true;
                } else {
                    ncxWizardBuildRunning = false;
                    ncxToast(res.data?.message || 'Build failed to start', 'error');
                    return;
                }
            } else {
                ncxWizardBuildSession = res.data.build_session || '';
                ncxWizardBuildTotal = Number(res.data.total || 0);
                ncxWizardBuildProcessed = 0;
                ncxWizardApplyBuildNote(res.data.breakdown);
                updateVisuals({ total: ncxWizardBuildTotal, processed: 0, build_session: ncxWizardBuildSession });
            }
        }

        const runBatch = async () => {
            try {
                const batch = await ncxCall('ssg_regen_all_batch');
                if (batch.success) {
                    const { total, processed, pct } = ncxWizardSyncProgress(batch.data);
                    updateVisuals(batch.data);
                    const errCount  = batch.data.errors || 0;
                    if (batch.data.last_url && statusSpan) {
                        try {
                            const path = new URL(batch.data.last_url).pathname;
                            statusSpan.innerText = errCount > 0 ? `${path} (${errCount} capture errors so far)` : path;
                        } catch(e) {}
                    }
                    ncxWizardTouchProgress(batch.data);
                    if (batch.data.done && !batch.data.running) {
                        const built  = Math.max(0, processed - errCount);
                        const doneMsg = errCount > 0
                            ? `${built} of ${total} pages built — ${errCount} had errors`
                            : `All ${total} pages built!`;
                        if (statusSpan) statusSpan.innerText = doneMsg;
                        updateVisuals({ total, processed: total, done: true, build_session: ncxWizardBuildSession });
                        window._ncxBuildErrors = batch.data.recent_errors || [];
                        await ncxCall('wizard_finish').catch(() => {});
                        ncxWizardBuildRunning = false;
                        setTimeout(() => { goToStep(5); runFinalDiagnostic(); }, 2000);
                    } else if (batch.data.done && batch.data.running) {
                        if (statusSpan) statusSpan.innerText = 'Finalizing build…';
                        setTimeout(runBatch, 800);
                    } else {
                        setTimeout(runBatch, 1500);
                    }
                } else {
                    if (statusSpan) statusSpan.innerText = 'Retrying…';
                    setTimeout(runBatch, 4000);
                }
            } catch(e) {
                setTimeout(runBatch, 4000);
            }
        };
        runBatch();
    };

    document.getElementById('ncx-wizard-stop-build')?.addEventListener('click', async () => {
        await ncxCall('ssg_bulk_stop');
        ncxWizardBuildRunning = false;
        ncxWizardShowRecovery(false);
        ncxToast('Build stopped. Captured pages remain live.', 'info');
        const statusSpan = document.querySelector('#step-4 .current-page');
        if (statusSpan) statusSpan.innerText = 'Build stopped — you can continue setup or retry from Build Control.';
    });

    document.getElementById('ncx-wizard-continue-anyway')?.addEventListener('click', async () => {
        await ncxCall('ssg_bulk_stop').catch(() => {});
        await ncxWizardFinishAnyway('Setup continued — retry failed pages anytime from Build Control.');
    });

    // Step 5 — final diagnostic + tier display
    const runFinalDiagnostic = async () => {
        const statsRes = await ncxCall('ssg_stats');
        if (!statsRes.success) return;

        const stats           = statsRes.data.stats || {};
        const bulk            = statsRes.data.bulk || {};
        const manifestFiles   = stats.total_files || 0;
        const diskFiles       = stats.disk_files  || 0;
        const mirrorTotal     = Math.max(manifestFiles, diskFiles);
        const buildProcessed  = Number(bulk.processed || 0);
        const buildErrorsCount = Number(bulk.errors || 0);
        const buildSuccess    = Math.max(0, buildProcessed - buildErrorsCount);
        const serveOk         = !!statsRes.data.serve_rule;
        const dropinOk        = !!statsRes.data.dropin;
        const isNginx         = statsRes.data.is_nginx || (window._ncxActData?.is_nginx) || false;
        const nginxRuleActive = statsRes.data.nginx_rule_active || (window._ncxActData?.nginx_rule_active) || false;

        const tier = (serveOk || nginxRuleActive) ? 1 : (dropinOk ? 2 : 3);

        const ttfbP50     = parseInt(statsRes.data.ttfb_p50 || 0, 10);
        const ttfbDisplay = ttfbP50 > 0 ? ttfbP50 + 'ms' : tTtfb[tier];
        const ttfbLabel   = ttfbP50 > 0 ? 'TTFB (P50, 24h)' : 'Est. TTFB';

        const rawStatsErrors = statsRes.data.errors || [];
        const rawBulkErrors = bulk.recent_errors || [];
        const statsErrors = rawStatsErrors.length ? rawStatsErrors : rawBulkErrors;
        let buildErrors = (window._ncxBuildErrors && window._ncxBuildErrors.length)
            ? window._ncxBuildErrors
            : statsErrors;
        buildErrors = ncxDedupeBuildErrors(buildErrors);
        const failedCount = buildErrors.length;
        const authBlocked = ncxErrorsAreHttpAuth(buildErrors);
        const capturedCnt = buildSuccess > 0 ? buildSuccess : (failedCount > 0 ? 0 : mirrorTotal);

        const launchTitle = document.getElementById('ncx-launch-title');
        const launchSub   = document.getElementById('ncx-launch-subtitle');
        const authNotice  = document.getElementById('ncx-wiz-http-auth-notice');
        if (authBlocked) {
            if (launchTitle) launchTitle.textContent = 'Setup complete — capture blocked';
            if (launchSub) launchSub.textContent = 'Nexora is configured, but HTTP authentication prevented static HTML from being saved. Enter staging credentials below, save, then retry from Mirror Build Control.';
            if (authNotice) authNotice.hidden = false;
        } else if (failedCount > 0 && capturedCnt === 0) {
            if (launchTitle) launchTitle.textContent = 'Setup complete — pages need attention';
            if (launchSub) launchSub.textContent = `${failedCount} page${failedCount !== 1 ? 's' : ''} could not be captured. They will serve dynamically until you retry from Build Control.`;
        } else if (failedCount > 0) {
            if (launchTitle) launchTitle.textContent = 'You\'re mostly live!';
            if (launchSub) launchSub.textContent = `${capturedCnt} page${capturedCnt !== 1 ? 's' : ''} captured as static HTML. ${failedCount} still need a retry.`;
        } else {
            if (launchTitle) launchTitle.textContent = 'You\'re Live!';
            if (launchSub) launchSub.textContent = 'Your WordPress site is now served as high-performance static HTML where captured.';
        }

        const metricFiles = document.getElementById('ncx-metric-files');
        const metricFilesLabel = document.getElementById('ncx-metric-files-label');
        const metricFilesSub = document.getElementById('ncx-metric-files-sub');
        const metricTtfb  = document.getElementById('ncx-metric-ttfb');
        if (metricFiles) metricFiles.textContent = mirrorTotal > 0 ? mirrorTotal.toLocaleString() : '0';
        if (metricFilesLabel) {
            metricFilesLabel.textContent = buildSuccess > 0 ? 'Captured This Build' : 'Static Files on Disk';
        }
        if (metricFilesSub) {
            if (buildSuccess > 0 && mirrorTotal > buildSuccess) {
                metricFilesSub.textContent = `${buildSuccess} new this run · ${mirrorTotal} total on disk`;
            } else if (buildSuccess > 0) {
                metricFilesSub.textContent = 'Successfully captured in this wizard run';
            } else if (mirrorTotal > 0 && failedCount > 0) {
                metricFilesSub.textContent = 'Older files may exist — this run captured 0 new pages';
            } else {
                metricFilesSub.textContent = 'From this and prior builds';
            }
        }
        if (metricTtfb) {
            metricTtfb.textContent = ttfbDisplay;
            const metricLabel = metricTtfb.closest('.ncx-launch-metric')?.querySelector('.ncx-launch-metric-label');
            if (metricLabel) metricLabel.textContent = ttfbLabel;
        }

        // CDN metric: show real state instead of a static "CDN Ready"
        const cdnConfigured = statsRes.data.cdn_configured || false;
        const metricCdnVal = document.getElementById('ncx-metric-cdn-val');
        const metricCdnLbl = document.getElementById('ncx-metric-cdn-lbl');
        if (metricCdnVal) {
            metricCdnVal.textContent = cdnConfigured ? 'Active' : 'Optional';
        }
        if (metricCdnLbl) {
            metricCdnLbl.textContent = cdnConfigured ? 'CDN Edge Cache' : 'CDN (configure in Settings)';
        }

        // Update speed badge — plain English, no "Tier X" jargon
        const tierCard = document.getElementById('ncx-final-tier');
        if (tierCard) {
            const color = tColors[tier];
            tierCard.style.borderColor = color + '30';
            tierCard.style.background  = color + '08';
            const badgeEl = tierCard.querySelector('.ncx-tier-badge');
            const descEl  = document.getElementById('ncx-final-tier-desc');
            const ttfbEl  = document.getElementById('ncx-final-tier-ttfb');
            const iconEl  = tierCard.querySelector('.tier-icon');
            if (badgeEl) { badgeEl.textContent = tLabels[tier]; badgeEl.style.background = color + '18'; badgeEl.style.color = color; }
            if (descEl)  descEl.textContent  = tDescs[tier];
            if (ttfbEl)  { ttfbEl.textContent = ttfbDisplay; ttfbEl.style.color = color; }
            if (iconEl)  iconEl.textContent  = tIcons[tier];
        }

        // Nginx config tip — only show when:
        //  • Server is Nginx
        //  • AND the nexora-static rule is NOT yet in the conf file (not already configured)
        //  • AND not already at Full Speed via .htaccess
        const tip = document.getElementById('ncx-nginx-tip');
        if (tip) {
            if (isNginx && !nginxRuleActive && !serveOk) {
                tip.style.display = 'block';
            } else if (isNginx && nginxRuleActive) {
                // Rule already applied — show a quiet confirmation instead
                tip.style.display = 'none';
                const confirmed = document.getElementById('ncx-nginx-confirmed');
                if (confirmed) confirmed.style.display = 'flex';
            }
        }

        const rawStatsErrors2 = statsRes.data.errors || [];
        const rawBulkErrors2 = statsRes.data.bulk?.recent_errors || [];
        const statsErrors2 = rawStatsErrors2.length ? rawStatsErrors2 : rawBulkErrors2;
        if (!buildErrors.length && statsErrors2.length) {
            buildErrors = ncxDedupeBuildErrors(statsErrors2);
        }
        const errorBox = document.getElementById('ncx-build-errors');
        if (errorBox && buildErrors.length > 0) {
            const successCount = Math.max(0, buildSuccess || capturedCnt);
            let html = '';
            if (authBlocked) {
                html += `<div class="ncx-rp-result-tip" style="margin-bottom:10px;">All failures share the same root cause: HTTP authentication. Fix credentials once, then retry — you do not need to rebuild the whole site.</div>`;
            }
            html += `<div class="ncx-rp-result-header">`;
            html += `<div class="ncx-rp-result-stat ncx-rp-stat--success">`;
            html += `<span class="ncx-rp-stat-num">${successCount}</span>`;
            html += `<span class="ncx-rp-stat-lbl">Captured</span>`;
            html += `</div>`;
            html += `<div class="ncx-rp-result-stat ncx-rp-stat--fail">`;
            html += `<span class="ncx-rp-stat-num">${buildErrors.length}</span>`;
            html += `<span class="ncx-rp-stat-lbl">Failed</span>`;
            html += `</div>`;
            html += `</div>`;
            html += `<p class="ncx-rp-result-tip">Failed pages serve dynamically until recaptured. ${authBlocked ? 'Enter staging credentials in the HTTP password panel on this step, save, then retry.' : 'Retry only the failed pages from Build Control.'}</p>`;
            html += `<button type="button" class="ncx-btn ncx-btn-sm ncx-btn-outline ncx-retry-errors-btn" style="margin-bottom:8px;">
                <span class="dashicons dashicons-update" style="margin-top:3px;"></span> Retry Failed Pages
            </button>`;
            html += `<div class="ncx-rp-error-list">`;
            buildErrors.slice(0, 12).forEach(e => {
                const title = ncxEscHtml(ncxDecodeHtml(e.title || e.message || e.code || 'Unknown page'));
                let urlPath = '';
                if (e.url) {
                    try { urlPath = new URL(e.url).pathname; } catch(_) { urlPath = e.url; }
                }
                const msg = (e.message && (e.title || e.url)) ? ncxEscHtml(e.message) : '';
                const isMemory = (e.code === 'nexeng_ssg_source_fatal') && e.message && e.message.toLowerCase().includes('php memory');
                const postId = parseInt(e.post_id || 0, 10);
                html += `<div class="ncx-rp-error-item" data-post-id="${postId}">`;
                html += `<div class="ncx-rp-error-head">`;
                html += `<span class="ncx-rp-error-icon">${isMemory ? '⚠' : '✕'}</span>`;
                html += `<span class="ncx-rp-error-title">${title}</span>`;
                html += `</div>`;
                if (urlPath) html += `<span class="ncx-rp-error-url">${ncxEscHtml(urlPath)}</span>`;
                if (msg) html += `<p class="ncx-rp-error-msg">${msg}</p>`;
                // One-click actions for wizard build errors — same as Build Control panel.
                let actionsHtml = '<div class="ncx-rp-error-actions">';
                if (e.url) {
                    actionsHtml += `<a class="ncx-btn ncx-btn-xs ncx-btn-outline" href="${ncxEscHtml(e.url)}" target="_blank" rel="noopener noreferrer">
                        <span class="dashicons dashicons-external"></span> Visit page
                    </a>`;
                }
                if (postId > 0) {
                    actionsHtml += `<button type="button" class="ncx-btn ncx-btn-xs ncx-btn-danger ncx-exclude-page-btn" data-post-id="${postId}" data-title="${title}">
                        <span class="dashicons dashicons-no-alt"></span> Exclude this page
                    </button>`;
                }
                actionsHtml += '</div>';
                html += actionsHtml;
                html += `</div>`;
            });
            if (buildErrors.length > 12) {
                html += `<p class="ncx-rp-result-tip">${buildErrors.length - 12} more — see Build Control for the full list.</p>`;
            }
            html += `</div>`;
            errorBox.className = 'ncx-rp-result-box';
            errorBox.innerHTML = html;
            errorBox.style.display = '';
        }

        // ── Transform sidebar for Step 5: replace static signals with live build results ──
        const fatalCount  = parseInt( statsRes.data.fatal_pages_count || 0, 10 );
        const failedSidebar = Math.max( buildErrors.length, fatalCount );
        const capturedSidebar = buildSuccess > 0 ? buildSuccess : (mirrorTotal > 0 && failedSidebar === 0 ? mirrorTotal : 0);

        // Blue card — update kicker, title, description
        const sbKicker = document.getElementById('ncx-wiz-sidebar-kicker');
        const sbTitle  = document.getElementById('ncx-wiz-sidebar-title');
        const sbDesc   = document.getElementById('ncx-wiz-sidebar-desc');
        if (sbKicker) sbKicker.textContent = 'Build Results';
        if (sbTitle)  sbTitle.textContent  = authBlocked ? 'Capture blocked — auth required' : (failedSidebar === 0 ? 'Your mirror is live' : 'Mirror live — check errors');
        if (sbDesc) {
            if (authBlocked) {
                sbDesc.textContent = 'Save staging username and password in the form below, then retry failed pages from Mirror Build Control.';
            } else {
                sbDesc.textContent = capturedSidebar > 0
                    ? `${capturedSidebar.toLocaleString()} pages captured as static HTML in this setup run.`
                    : (mirrorTotal > 0 ? `${mirrorTotal.toLocaleString()} static files exist on disk from earlier attempts.` : 'No static HTML was captured in this run yet.');
            }
        }

        // Signal 1 → Captured pages count (green)
        const s1Val  = document.getElementById('ncx-sig-1-val');
        const s1Sub  = document.getElementById('ncx-sig-1-sub');
        const s1Icon = document.getElementById('ncx-sig-1-icon');
        if (s1Icon) s1Icon.className = 'dashicons dashicons-yes-alt';
        if (s1Val)  {
            s1Val.textContent = capturedSidebar.toLocaleString();
            s1Val.style.color = capturedSidebar > 0 ? '#86efac' : '#fca5a5';
            s1Val.style.fontSize = '20px';
            s1Val.style.fontWeight = '800';
        }
        if (s1Sub)  s1Sub.textContent = capturedSidebar === 1 ? 'page captured this run' : (capturedSidebar > 0 ? 'pages captured this run' : 'none captured this run');

        // Signal 2 → TTFB (blue)
        const s2Val  = document.getElementById('ncx-sig-2-val');
        const s2Sub  = document.getElementById('ncx-sig-2-sub');
        const s2Icon = document.getElementById('ncx-sig-2-icon');
        if (s2Icon) s2Icon.className = 'dashicons dashicons-performance';
        if (s2Val)  { s2Val.textContent = ttfbDisplay; s2Val.style.color = '#93c5fd'; s2Val.style.fontSize = '18px'; s2Val.style.fontWeight = '800'; }
        if (s2Sub)  s2Sub.textContent = ttfbLabel;

        // Signal 3 → Blocked pages (red), CDN (green), or all-clear (green)
        const s3Val  = document.getElementById('ncx-sig-3-val');
        const s3Sub  = document.getElementById('ncx-sig-3-sub');
        const s3Icon = document.getElementById('ncx-sig-3-icon');
        if (failedSidebar > 0) {
            if (s3Icon) s3Icon.className = 'dashicons dashicons-warning';
            if (s3Val)  { s3Val.textContent = failedSidebar; s3Val.style.color = '#fca5a5'; s3Val.style.fontSize = '20px'; s3Val.style.fontWeight = '800'; }
            if (s3Sub)  s3Sub.textContent = failedSidebar === 1 ? 'page blocked — needs fix' : 'pages blocked — needs fix';
        } else if (cdnConfigured) {
            if (s3Icon) s3Icon.className = 'dashicons dashicons-cloud';
            if (s3Val)  { s3Val.textContent = 'Active'; s3Val.style.color = '#86efac'; s3Val.style.fontSize = '14px'; s3Val.style.fontWeight = '700'; }
            if (s3Sub)  s3Sub.textContent = 'CDN edge-cached & live';
        } else {
            if (s3Icon) s3Icon.className = 'dashicons dashicons-shield-alt';
            if (s3Val)  { s3Val.textContent = '✓'; s3Val.style.color = '#86efac'; s3Val.style.fontSize = '18px'; s3Val.style.fontWeight = '800'; }
            if (s3Sub)  s3Sub.textContent = 'No errors detected';
        }

        // Alert panel — amber warning when there are blocked/failed pages
        const sbAlert = document.getElementById('ncx-wiz-sidebar-alert');
        if (sbAlert && failedSidebar > 0) {
            const alertTitle = document.getElementById('ncx-wiz-alert-title');
            const alertDesc  = document.getElementById('ncx-wiz-alert-desc');
            const alertCta   = sbAlert.querySelector('.ncx-wiz-alert-cta');
            if (alertTitle) alertTitle.textContent = `${failedSidebar} page${failedSidebar !== 1 ? 's' : ''} blocked`;
            if (alertDesc) {
                const sidebarItems = buildErrors.slice(0, 3).map(e => {
                    let label = ncxDecodeHtml(e.title || e.url || e.message || 'Unknown page');
                    if (e.url) {
                        try { label = `${label} (${new URL(e.url).pathname})`; } catch(_) {}
                    }
                    return `<li>${ncxEscHtml(label)}</li>`;
                }).join('');
                alertDesc.innerHTML = sidebarItems
                    ? `These pages serve dynamically until they are recaptured.<ul class="ncx-wiz-failed-list">${sidebarItems}</ul>`
                    : 'These pages serve dynamically until they are recaptured. Use Retry Failed Pages from the build result.';
            }
            if (alertCta) alertCta.style.display = 'none';
            sbAlert.style.display = 'flex';
        }

        // Transform overview cards: replace process steps with live build summary
        const ov1 = document.getElementById('ncx-wiz-ov-1');
        const ov2 = document.getElementById('ncx-wiz-ov-2');
        const ov3 = document.getElementById('ncx-wiz-ov-3');
        const capturedLabel = capturedSidebar === 1 ? 'page captured' : 'pages captured';
        if (ov1) ov1.innerHTML = `<span class="dashicons dashicons-yes-alt" style="color:#22c55e;background:#dcfce7"></span><strong>${capturedSidebar.toLocaleString()} ${capturedLabel}</strong><small>${authBlocked ? 'Blocked by HTTP auth — save staging credentials on Step 4 or below' : 'Captured as static HTML in this setup run'}</small>`;
        if (ov2) ov2.innerHTML = `<span class="dashicons dashicons-performance"></span><strong>${ttfbDisplay}</strong><small>${ttfbLabel} · static file serving overhead</small>`;
        if (failedSidebar > 0) {
            if (ov3) ov3.innerHTML = `<span class="dashicons dashicons-warning" style="color:#d97706;background:#fef9c3"></span><strong>${failedSidebar} blocked page${failedSidebar !== 1 ? 's' : ''}</strong><small>${authBlocked ? 'Save staging credentials below, then retry failed pages' : 'Use Retry Failed Pages to recapture them'}</small>`;
        } else if (cdnConfigured) {
            if (ov3) ov3.innerHTML = `<span class="dashicons dashicons-cloud"></span><strong>CDN Active</strong><small>Edge-cached globally for maximum reach</small>`;
        } else {
            if (ov3) ov3.innerHTML = `<span class="dashicons dashicons-shield-alt" style="color:#6366f1;background:#f0f0ff"></span><strong>All Systems Live</strong><small>No errors, no blocked pages detected</small>`;
        }
    };

    const btnFinish = document.getElementById('btn-finish-wizard');
    if (btnFinish) {
        btnFinish.addEventListener('click', async () => {
            ncxSetLoading(btnFinish, true);
            // wizard_finish was already called when the build completed —
            // await it here as a guaranteed fallback so is_completed() is
            // always true before the redirect fires (prevents the redirect
            // loop where maybe_redirect_to_wizard() sends the user back).
            try { await ncxCall('wizard_finish'); } catch(e) {}
            window.location.href = '?page=nexora';
        });
    }


    } catch (wizardErr) {
        console.error('[Nexora] Wizard init failed:', wizardErr);
        ncxWizardWrap.classList.remove('ncx-js-ready');
        document.querySelectorAll('.ncx-wizard-step').forEach(s => s.classList.remove('active'));
        const fallbackStep = document.getElementById('step-1');
        if (fallbackStep) fallbackStep.classList.add('active');
        ncxToast('Setup wizard had a JavaScript error. Step 1 is shown — refresh the page or check the browser console.', 'error');
    }
    }


    window.ncxPurgeCache = async () => {
        const ok = await ncxConfirmModal({
            title: 'Clear Static Cache',
            body: `<p>This will <strong>delete all captured static files</strong> from the mirror.</p>
                   <ul>
                     <li>Visitors will receive dynamic PHP pages immediately</li>
                     <li>Performance benefits of static delivery are paused</li>
                     <li>Run <strong>Rebuild Full Mirror</strong> afterwards to restore them</li>
                   </ul>`,
            confirmText: 'Yes, Clear Cache',
            confirmClass: 'ncx-btn-danger',
            type: 'danger',
        });
        if (!ok) return;
        const res = await ncxCall('purge_cache', { nexeng_purge_confirmed: 1 });
        if (res.success) {
            ncxToast('Cache purged successfully!', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            ncxToast(res.data?.message || 'Failed to purge cache', 'error');
        }
    };

    // ── Neural API Key — show/hide and copy ──────────────────────────────────
    document.addEventListener('click', function (e) {
        // Toggle visibility
        const showBtn = e.target.closest('.ncx-show-key');
        if (showBtn) {
            const group = showBtn.closest('.ncx-input-group');
            const input = group && group.querySelector('.ncx-api-key-input');
            if (input) {
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                const icon = showBtn.querySelector('.dashicons');
                if (icon) {
                    icon.className = isHidden ? 'dashicons dashicons-hidden' : 'dashicons dashicons-visibility';
                }
            }
        }
        // Copy to clipboard
        const copyBtn = e.target.closest('.ncx-copy-key');
        if (copyBtn) {
            const group = copyBtn.closest('.ncx-input-group');
            const input = group && group.querySelector('.ncx-api-key-input');
            if (input) {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value).then(() => {
                        ncxToast('API key copied!', 'success');
                    }).catch(() => {
                        input.select();
                        document.execCommand('copy');
                        ncxToast('API key copied!', 'success');
                    });
                } else {
                    // Fallback for non-secure contexts (http)
                    input.type = 'text';
                    input.select();
                    document.execCommand('copy');
                    input.type = 'password';
                    ncxToast('API key copied!', 'success');
                }
            }
        }
    });

    // ── Live pending-queue poll ────────────────────────────────────────────────
    // Refreshes the Build Control queue every 5 s so items added by Elementor
    // AJAX saves (or REST API edits) appear without a page reload.
    // Only starts when the Build Control panel is present on the page.
    if (document.getElementById('ncxRegenProgressPanel')) {
        let ncxPendingPollTimer = null;

        const ncxPollPendingList = async () => {
            try {
                const res = await ncxCall('get_pending_list');
                // Bail out cleanly on transient errors (offline, nonce expired, 5xx).
                // The setTimeout in the finally block below will still reschedule,
                // so the queue resumes automatically when connectivity / auth returns.
                if (!res || !res.success) return;
                const { count, items, auto_rebuild } = res.data;

                // Archives pending = dirty (global change) OR missing (never captured).
                // "Refresh Changed Pages" now handles both automatically via bulk_start_pending().
                const archivesPending      = !!res.data.archives_pending;
                const archivesPendingCount = Number(res.data.archives_pending_count || 0);

                // Total visible queue length includes the virtual archive item (if any).
                const totalVisible = count + (archivesPending ? 1 : 0);

                const details   = document.getElementById('ncxPendingDetails');
                const countTxt  = document.getElementById('ncxPendingCountText');
                const list      = document.getElementById('ncxPendingList');
                const badge     = document.querySelector('.ncx-rp-pending-badge');
                const rpPending = document.getElementById('ncxRpPending');
                const summary   = document.getElementById('ncxRpSummary');

                // Update counter displays (total = posts + archive entry if any).
                if (countTxt) {
                    countTxt.textContent = totalVisible === 1
                        ? '1 change queued for deployment'
                        : `${totalVisible} changes queued for deployment`;
                }
                if (badge)     badge.textContent     = totalVisible;
                if (rpPending) rpPending.textContent  = count; // signals panel shows post count

                // ── Live signal cells — keep Build Control up-to-date every 5 s ──
                const lastBuildEl = document.getElementById('ncxRpLastBuild');
                const sizeEl      = document.getElementById('ncxRpSize');
                const cronEl      = document.getElementById('ncxRpCron');
                if (lastBuildEl && res.data.last_build) lastBuildEl.textContent = res.data.last_build;
                if (sizeEl      && res.data.mirror_size) sizeEl.textContent     = res.data.mirror_size;
                if (cronEl      && res.data.next_cron)   cronEl.textContent     = res.data.next_cron;

                // ── Mode badge sync (Auto / Manual) — reflects live option value ──
                const modeBadge = document.getElementById('ncxRpModeBadge');
                if (modeBadge) {
                    const isAuto = res.data.auto_rebuild;
                    modeBadge.textContent = isAuto ? 'Auto' : 'Manual';
                    modeBadge.className   = 'ncx-rp-mode-badge ' + (isAuto ? 'ncx-mode-auto' : 'ncx-mode-manual');
                    modeBadge.title = isAuto
                        ? 'Pro — changes deploy automatically on the next cron tick'
                        : 'Free — click Refresh Changed Pages to deploy updates manually';
                }

                // Update summary message to reflect auto vs manual mode.
                if (summary && totalVisible > 0) {
                    summary.textContent = auto_rebuild
                        ? `${totalVisible} ${totalVisible === 1 ? 'item' : 'items'} queued for automatic deployment. Will refresh on the next cron tick — no manual action needed.`
                        : `${totalVisible} ${totalVisible === 1 ? 'item' : 'items'} ready for a focused refresh. Use "Refresh Changed Pages" to deploy.`;
                } else if (summary && totalVisible === 0) {
                    summary.textContent = 'Every regenerate action runs here, so builds stay visible and controlled from one place.';
                }

                // ── Advice text — context-sensitive based on pending state + tier ──
                const adviceEl = document.getElementById('ncxRpAdvice');
                if (adviceEl) {
                    if (totalVisible > 0 && !auto_rebuild) {
                        adviceEl.textContent = 'Updates are queued but won\'t deploy automatically — Auto-build is off. Use "Refresh Changed Pages" to publish the static mirror now.';
                    } else if (totalVisible > 0 && auto_rebuild) {
                        adviceEl.textContent = 'Updates are queued and will deploy automatically on the next cron tick. You can also click "Refresh Changed Pages" to deploy right now.';
                    } else {
                        adviceEl.textContent = 'Use focused refresh for content edits. Run a full mirror rebuild after theme, menu, layout, or plugin-wide changes.';
                    }
                }

                // ── Queue hint text (shown only when auto_rebuild is off) ──────────
                const queueHint = document.querySelector('.ncx-rp-queue-hint');
                if (queueHint) {
                    queueHint.style.display = (totalVisible > 0 && !auto_rebuild) ? '' : 'none';
                }

                // Show / hide the whole details block (show when posts OR archives pending).
                if (details) {
                    details.style.display = totalVisible > 0 ? '' : 'none';
                    if (totalVisible > 0 && !details.open) details.open = true;
                }

                // ── Virtual archive queue item ─────────────────────────────────────
                // When archives are pending (dirty or missing), show them as a named
                // item in the queue so the user knows "Refresh Changed Pages" handles
                // them — no separate "Build Archive Pages" button needed.
                const ARCH_ID = 'ncx-virtual-archives';
                const existingArchEl = list && list.querySelector(`[data-id="${ARCH_ID}"]`);

                if (archivesPending && list) {
                    const arcLabel = archivesPendingCount > 0
                        ? `${archivesPendingCount} archive ${archivesPendingCount === 1 ? 'page' : 'pages'} (categories, tags, authors)`
                        : 'Category, tag & author archive pages';
                    if (existingArchEl) {
                        // Update label in case count changed.
                        const titleEl = existingArchEl.querySelector('.ncx-rp-pending-title');
                        if (titleEl) titleEl.textContent = arcLabel;
                    } else {
                        const li = document.createElement('li');
                        li.className  = 'ncx-rp-pending-item ncx-rp-pending-item--archive';
                        li.dataset.id = ARCH_ID;
                        li.style.opacity = '0';
                        li.innerHTML = `
                            <div class="ncx-rp-pending-main">
                                <div class="ncx-rp-pending-indicator" style="background:#f59e0b"></div>
                                <div class="ncx-rp-pending-info">
                                    <span class="ncx-rp-pending-title">${arcLabel}</span>
                                    <span class="ncx-rp-pending-meta">
                                        <span class="ncx-rp-pending-reason">Included in Refresh</span>
                                    </span>
                                </div>
                            </div>
                            <div class="ncx-rp-item-actions"></div>`;
                        list.insertBefore(li, list.firstChild);
                        requestAnimationFrame(() => {
                            li.style.transition = 'opacity .25s';
                            li.style.opacity = '1';
                        });
                    }
                } else if (!archivesPending && existingArchEl) {
                    // Archives are no longer pending — fade out and remove.
                    existingArchEl.style.transition = 'opacity .3s';
                    existingArchEl.style.opacity    = '0';
                    setTimeout(() => existingArchEl.remove(), 320);
                }

                // Also hide the separate "Build Archive Pages" notice banner when
                // archives are already included in the pending queue / being handled.
                const archiveNotice = document.getElementById('ncxArchiveNotice');
                if (archiveNotice && archivesPending && count > 0) {
                    // Post changes exist + archives pending → banner redundant, hide it.
                    archiveNotice.style.display = 'none';
                } else if (archiveNotice && !archivesPending) {
                    // All archives captured — restore banner visibility (server-render
                    // will have removed it on reload, but keep DOM consistent mid-session).
                    archiveNotice.style.display = '';
                }

                // Sync the "Refresh Changed Pages" button + badge + Rebuild button style.
                // These are server-rendered at page-load based on pending count, so the
                // poll must keep them in sync when the queue empties (auto-rebuild clears
                // it without a page reload).
                const regenPendingBtn = document.getElementById('ncxRegenPendingBtn');
                const regenAllBtn     = document.querySelector('.ncx-regen-all');
                if (regenPendingBtn) {
                    regenPendingBtn.style.display = totalVisible > 0 ? '' : 'none';
                    const btnBadge = regenPendingBtn.querySelector('.ncx-rp-pending-badge');
                    if (btnBadge) btnBadge.textContent = totalVisible;
                    regenPendingBtn.dataset.count = totalVisible;
                }
                if (regenAllBtn) {
                    regenAllBtn.classList.toggle('ncx-btn-primary', totalVisible === 0);
                    regenAllBtn.classList.toggle('ncx-btn-outline',  totalVisible > 0);
                }

                // Sync admin bar build-status node — server-rendered on page load,
                // so we update the live DOM without a full reload.
                const abNode  = document.getElementById('wp-admin-bar-ncx-build-status');
                const abLabel = abNode && abNode.querySelector('.ab-label');
                if (abLabel) {
                    abLabel.textContent = count === 0
                        ? 'Nexora: Static OK'
                        : count === 1 ? 'Nexora: 1 update ready' : `Nexora: ${count} updates ready`;
                }
                if (abNode) {
                    abNode.classList.toggle('has-pending', count > 0);
                }
                const abRefreshNode = document.getElementById('wp-admin-bar-ncx-build-refresh-pending');
                if (abRefreshNode) {
                    if (count > 0) {
                        abRefreshNode.style.display = '';
                        const abRefreshLink = abRefreshNode.querySelector('.ab-item');
                        if (abRefreshLink) {
                            abRefreshLink.textContent = count === 1
                                ? 'Refresh 1 changed page'
                                : `Refresh ${count} changed pages`;
                        }
                    } else {
                        abRefreshNode.style.display = 'none';
                    }
                }

                if (!list) return;

                // Remove items that have been cleared from the server queue.
                const serverIds = new Set(items.map(i => String(i.id)));
                list.querySelectorAll('[data-id]').forEach(el => {
                    if (!serverIds.has(el.dataset.id)) {
                        el.style.transition = 'opacity .3s';
                        el.style.opacity    = '0';
                        setTimeout(() => el.remove(), 320);
                    }
                });

                // Prepend newly queued items.
                const currentIds = new Set([...list.querySelectorAll('[data-id]')].map(el => el.dataset.id));
                items.forEach(item => {
                    if (currentIds.has(String(item.id))) return; // already rendered
                    const reasonMap = { seo: 'SEO', publish: 'Published', scheduled: 'Scheduled', manual: 'Manual', priority: 'Priority', edit: 'Edit' };
                    const reason  = item.reason
                        ? (reasonMap[item.reason.toLowerCase()] || (item.reason.charAt(0).toUpperCase() + item.reason.slice(1)))
                        : 'Edit';
                    const agePart = item.age
                        ? `<span class="ncx-rp-pending-dot">&middot;</span><span class="ncx-rp-pending-age">${item.age}</span>`
                        : '';
                    const li      = document.createElement('li');
                    li.className     = 'ncx-rp-pending-item';
                    li.dataset.id    = item.id;
                    li.style.opacity = '0';
                    li.innerHTML = `
                        <div class="ncx-rp-pending-main">
                            <div class="ncx-rp-pending-indicator"></div>
                            <div class="ncx-rp-pending-info">
                                <span class="ncx-rp-pending-title">${item.title}</span>
                                <span class="ncx-rp-pending-meta">
                                    <span class="ncx-rp-pending-reason">${reason}</span>
                                    ${agePart}
                                </span>
                            </div>
                        </div>
                        <div class="ncx-rp-item-actions">
                            <button type="button" class="ncx-btn ncx-btn-xs ncx-regen-one"
                                    data-id="${item.id}" title="Deploy this page now">
                                <span class="dashicons dashicons-image-rotate"></span>
                            </button>
                            <button type="button" class="ncx-btn ncx-btn-xs ncx-rp-dismiss-one"
                                    data-id="${item.id}" title="Remove from queue">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>`;
                    list.prepend(li);
                    requestAnimationFrame(() => {
                        li.style.transition = 'opacity .3s';
                        li.style.opacity    = '1';
                    });
                });
            } catch (_) { /* silent — no toast spam on transient network errors */ }
            finally {
                // ALWAYS reschedule, even on early-return / exception, so the queue
                // poll resumes automatically when the user comes back online.
                ncxPendingPollTimer = setTimeout(ncxPollPendingList, 5000);
            }
        };

        // Kick off immediately.
        ncxPollPendingList();

        // Immediately re-poll when this admin tab comes back into focus.
        // Chrome throttles background-tab timers to ~1 min, so the pending
        // count can appear stale/frozen while the user is editing in
        // Elementor editor or any other browser tab.
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                clearTimeout(ncxPendingPollTimer);
                ncxPollPendingList();
            }
        });
    }
});
