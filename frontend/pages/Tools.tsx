import { useState } from 'react';
import { createPortal } from 'react-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Wrench, Activity, Link2, Database, Download, Trash2,
  RefreshCw, ShieldCheck, Search, AlertTriangle, ServerCog, X, Loader2,
  Power, ShieldAlert,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import DiagnosticReport, { type DiagnosticPayload } from '../components/DiagnosticReport';
import { api } from '../lib/api';
import { useStore } from '../lib/store';
import { formatBytes, formatNumber } from '../lib/format';

type ToolsStatus = {
  system: {
    php: string;
    wordpress: string;
    engine_version: string;
    static_delivery: boolean;
    static_pages: number;
    mirror_bytes: number;
  };
  license: {
    plan?: string;
    source?: string;
    environment?: string;
    env_label?: string;
    cache_age_minutes?: number | null;
    grace_active?: boolean;
    grace_hours_left?: number;
    server_reachable?: boolean;
    dev_mode_active?: boolean;
    allow_dev_tools?: boolean;
    sync_url?: string;
  };
  is_pro: boolean;
};

function StatusTag({
  tone, children,
}: { tone: 'warm' | 'cold' | 'off' | 'dev' | 'info'; children: React.ReactNode }) {
  // Cockpit pills — neon classes from index.css. "warm" = OK (lime),
  // "dev" = warning (amber), "info" = cyan, "cold"/"off" = subdued chip.
  const cls =
    tone === 'warm' ? 'np-badge-ok'
    : tone === 'dev' ? 'np-badge-medium'
    : tone === 'info' ? 'np-badge-low'
    : 'np-chip';
  return <span className={cls}>{children}</span>;
}

function Card({
  icon: Icon, title, children,
}: { icon: React.FC<any>; title: string; children: React.ReactNode }) {
  return (
    <div className="np-card p-5">
      <div className="flex items-center gap-2.5 mb-4">
        <div
          className="w-9 h-9 rounded-xl flex items-center justify-center"
          style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
        >
          <Icon className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
        </div>
        <h3 className="text-sm font-bold text-[color:var(--np-text)]">{title}</h3>
      </div>
      {children}
    </div>
  );
}

function StatRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between py-2 border-b last:border-0" style={{ borderColor: 'var(--np-border)' }}>
      <span className="text-xs text-[color:var(--np-text-muted)]">{label}</span>
      <span className="text-sm font-semibold text-[color:var(--np-text)]">{value}</span>
    </div>
  );
}

function downloadBlob(name: string, content: string, mime: string) {
  const blob = new Blob([content], { type: mime });
  const url  = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

function DiagnosticDrawer({
  open, data, loading, onClose, onRerun,
}: {
  open: boolean;
  data: DiagnosticPayload | null;
  loading: boolean;
  onClose: () => void;
  onRerun: () => void;
}) {
  if (!open) return null;
  // Render outside #nexora-engine-root via portal. Our Layout's <main>
  // carries an active CSS transform from the fade-in animation, which
  // turns it into the containing block for any descendant `position:
  // fixed` — so an in-tree modal would center inside <main> (offset by
  // the sidebar) instead of the viewport. Portal-ing to document.body
  // restores viewport-relative centering.
  return createPortal(
    <div
      className="fixed inset-0 z-[9999] flex items-center justify-center p-4"
      style={{ background: 'rgba(8, 14, 30, 0.80)', backdropFilter: 'blur(2px)' }}
      onClick={onClose}
    >
      <div
        className="np-card w-full max-w-4xl max-h-[88vh] flex flex-col relative overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        <span
          className="absolute top-0 left-0 right-0 h-px"
          style={{
            background: 'var(--np-brand-primary)',
            boxShadow: '0 0 12px 0 rgb(2 82 250 / 0.10)',
          }}
        />
        <div
          className="flex items-center justify-between px-5 py-3 border-b"
          style={{ borderColor: 'var(--np-border)', background: 'var(--np-bg-subtle)' }}
        >
          <div className="flex items-center gap-3">
            <div
              className="w-9 h-9 rounded-xl flex items-center justify-center"
              style={{
                background: 'var(--np-bg-card)',
                border: '1px solid var(--np-border)',
              }}
            >
              <Activity className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
            </div>
            <div>
              <p
                className="text-[11px] font-bold uppercase tracking-wider"
                style={{ color: 'var(--np-brand-primary)' }}
              >
                System diagnostic
              </p>
              <p
                className="text-sm font-bold mt-0.5 leading-tight"
                style={{ color: 'var(--np-text-primary)' }}
              >
                Live performance verification
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onRerun}
              disabled={loading}
              className="np-btn-secondary"
              title="Re-run diagnostic"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
              Re-run
            </button>
            <button
              type="button"
              onClick={onClose}
              className="opacity-50 hover:opacity-100 transition-opacity"
              style={{ color: 'var(--np-text-muted)' }}
              aria-label="Close"
            >
              <X className="w-4 h-4" />
            </button>
          </div>
        </div>
        <div className="px-5 py-5 overflow-y-auto flex-1 np-scrollbar">
          {loading && !data ? (
            <div className="flex items-center gap-2 py-12 justify-center">
              <Loader2 className="w-4 h-4 animate-spin" style={{ color: 'var(--np-brand-primary)' }} />
              <span
                className="text-sm font-semibold"
                style={{ color: 'var(--np-text-muted)' }}
              >
                Probing system…
              </span>
            </div>
          ) : data ? (
            <DiagnosticReport data={data} />
          ) : (
            <p className="text-xs text-center py-10" style={{ color: 'var(--np-text-muted)' }}>
              No diagnostic data.
            </p>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}

export default function Tools() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const [diagOpen, setDiagOpen] = useState(false);
  const [diagData, setDiagData] = useState<DiagnosticPayload | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['tools-status'],
    queryFn: () => api.get<ToolsStatus>('tools/status'),
    refetchInterval: 30000,
  });

  // Diagnostic uses the structured JSON endpoint so React renders every probe
  // in the console aesthetic instead of injecting legacy admin HTML (which
  // shipped with its own ncx-diag-* stylesheet that doesn't load here).
  const runDiagnostic = useMutation({
    mutationFn: () => api.post<DiagnosticPayload>('wizard/diag-json'),
    onSuccess: (r) => {
      setDiagData(r);
    },
    onError: (e: any) => {
      pushToast('error', e?.message ?? 'Diagnostic failed');
    },
  });

  function openDiagnostic() {
    setDiagData(null);
    setDiagOpen(true);
    runDiagnostic.mutate();
  }

  function makeAction(label: string, path: string) {
    return useMutation({
      mutationFn: () => api.post<any>(path),
      onSuccess: (r) => {
        pushToast('success', r?.message ?? `${label} complete`);
        qc.invalidateQueries({ queryKey: ['tools-status'] });
      },
      onError: (e: any) => pushToast('error', e?.message ?? `${label} failed`),
    });
  }

  // These calls are at the top level on every render — same call order, so
  // React's hooks invariants are satisfied even though they're wrapped.
  const flush      = makeAction('Flush rules', 'tools/flush-permalinks');
  const purge      = makeAction('Purge analytics', 'tools/purge-analytics');
  const clearCache = makeAction('Clear license cache', 'tools/license-clear-cache');
  const resetDev   = makeAction('Reset sandbox', 'tools/license-reset-sandbox');

  const exportSettings = useMutation({
    mutationFn: () => api.get<{ filename: string; json: string }>('tools/export-settings'),
    onSuccess: (r) => {
      if (r?.json) {
        downloadBlob(r.filename || 'nexora-engine-config.json', r.json, 'application/json');
        pushToast('success', 'Configuration exported');
      }
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Export failed'),
  });

  // Factory reset — wipes every Nexora touch point so the next admin page
  // load redirects to the wizard. The server enforces the confirm token but
  // we also gate the UI behind a two-step prompt to avoid accidental clicks.
  const factoryReset = useMutation({
    mutationFn: () =>
      api.post<{ message: string; redirect_url: string; steps: string[] }>(
        'tools/factory-reset',
        { confirm: 'FACTORY_RESET' },
      ),
    onSuccess: (r) => {
      pushToast('success', r?.message ?? 'Factory reset complete');
      // Hard-redirect to the wizard. We use window.location instead of
      // navigate() because the SPA's state cache and TanStack Query data
      // are now stale relative to the wiped server — a fresh page load
      // re-bootstraps everything from scratch.
      setTimeout(() => {
        window.location.href = r?.redirect_url ?? '/wp-admin/admin.php?page=ncx-wizard';
      }, 800);
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Reset failed'),
  });

  async function runFactoryReset() {
    const ok = await useStore.getState().askConfirm({
      title: 'Factory reset — start over from the wizard?',
      message: 'This rolls Nexora Engine back to its first-install state on this site. The next admin page load redirects to the setup wizard.',
      details: [
        'Disable static delivery',
        'Uninstall the cache drop-in',
        'Purge the entire static mirror',
        'Clear the pending queue and all build state',
        'Reset the wizard so setup runs again',
        'License activation, plugin files, and your WordPress content are NOT touched',
      ],
      confirmLabel: 'Factory reset',
      tone: 'danger',
      icon: 'power',
      requireTyped: 'RESET',
    });
    if (!ok) {
      pushToast('info', 'Factory reset cancelled.');
      return;
    }
    factoryReset.mutate();
  }

  if (isLoading || !data) return <Spinner label="Loading tools…" />;

  const sys = data.system;
  const lic = data.license;

  return (
    <div>
      <PageHeader
        title="Maintenance & Utilities"
        subtitle="Advanced tools to keep your static engine and security hardening in peak condition."
        icon={Wrench}
      />

      <div className="p-6 space-y-5">
        {/* Three top cards */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

          {/* System Status */}
          <Card icon={Activity} title="System Status">
            <div className="space-y-0">
              <StatRow label="PHP" value={<StatusTag tone="warm">{sys.php}</StatusTag>} />
              <StatRow label="WordPress" value={<StatusTag tone="warm">{sys.wordpress}</StatusTag>} />
              <StatRow
                label="Static Delivery"
                value={
                  sys.static_delivery
                    ? <StatusTag tone="warm">Active</StatusTag>
                    : <StatusTag tone="off">Off</StatusTag>
                }
              />
              <StatRow
                label="Static Pages"
                value={sys.static_pages > 0 ? formatNumber(sys.static_pages) : '—'}
              />
              <StatRow
                label="Mirror Size"
                value={sys.mirror_bytes > 0 ? formatBytes(sys.mirror_bytes) : '—'}
              />
              {sys.engine_version && (
                <StatRow label="Engine version" value={sys.engine_version} />
              )}
            </div>
          </Card>

          {/* Rewrite Rules */}
          <Card icon={Link2} title="Rewrite Rules">
            <p className="text-xs text-[color:var(--np-text-muted)] leading-snug mb-4">
              If your sitemap.xml, custom login URL, or static paths stop resolving, flush the permalink cache to rebuild them.
            </p>
            <div className="space-y-2">
              <button
                type="button"
                onClick={() => flush.mutate()}
                disabled={flush.isPending}
                className="np-btn-primary w-full justify-center text-xs"
              >
                <Link2 className="w-3.5 h-3.5" />
                {flush.isPending ? 'Flushing…' : 'Flush Rewrite Rules'}
              </button>
              <button
                type="button"
                onClick={openDiagnostic}
                disabled={runDiagnostic.isPending && !diagOpen}
                className="np-btn-secondary w-full justify-center text-xs"
                title="Open the system diagnostic drawer"
              >
                {runDiagnostic.isPending && !diagOpen
                  ? <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  : <Search className="w-3.5 h-3.5" />}
                {runDiagnostic.isPending && !diagOpen ? 'Running…' : 'Run Diagnostic'}
              </button>
            </div>
          </Card>

          {/* Data Management */}
          <Card icon={Database} title="Data Management">
            <p className="text-xs text-[color:var(--np-text-muted)] leading-snug mb-4">
              Export your configuration for backup or migration, or purge legacy analytics data to keep your database lean.
            </p>
            <div className="space-y-2">
              <button
                type="button"
                onClick={() => exportSettings.mutate()}
                disabled={exportSettings.isPending}
                className="np-btn-primary w-full justify-center text-xs"
              >
                <Download className="w-3.5 h-3.5" />
                {exportSettings.isPending ? 'Exporting…' : 'Export Configuration'}
              </button>
              <button
                type="button"
                onClick={async () => {
                  const ok = await useStore.getState().askConfirm({
                    title: 'Purge analytics history?',
                    message: 'Drops every recorded hit, TTFB sample, and Core Web Vitals data point from the analytics tables.',
                    details: [
                      'Dashboard charts reset to empty.',
                      'New data starts collecting immediately as visitors arrive.',
                      'Cannot be undone.',
                    ],
                    confirmLabel: 'Purge analytics',
                    tone: 'danger',
                    icon: 'trash',
                  });
                  if (ok) purge.mutate();
                }}
                disabled={purge.isPending}
                className="np-btn-secondary text-[#B91C1C] w-full justify-center text-xs"
              >
                <Trash2 className="w-3.5 h-3.5" />
                {purge.isPending ? 'Purging…' : 'Purge Analytics Data'}
              </button>
            </div>
          </Card>
        </div>

        {/* Licence Recovery — Pro only */}
        {data.is_pro && lic && (
          <div className="np-card p-5">
            <div className="flex items-start gap-3 mb-4">
              <div
                className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0"
                style={{ background: 'var(--np-bg-subtle)', border: '1px solid rgb(2 82 250 / 0.10)', boxShadow: 'inset 0 0 0 1px rgb(2 82 250 / 0.10)' }}
              >
                <ShieldCheck className="w-5 h-5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <h3 className="text-sm font-bold text-[color:var(--np-text)]">Licence Recovery</h3>
                  {lic.environment && (
                    <span
                      className="text-[10px] font-bold px-2 py-0.5 rounded-full"
                      title={
                        lic.environment === 'local'
                          ? "Local install — doesn't count against your license quota."
                          : lic.environment === 'staging'
                            ? "Staging install — doesn't count against your license quota."
                            : 'Production install — counts against your license quota.'
                      }
                      style={{
                        background:
                          lic.environment === 'production' ? '#DBEAFE'
                          : lic.environment === 'staging' ? '#FEF3C7'
                          : '#E0F2FE',
                        color:
                          lic.environment === 'production' ? '#1E40AF'
                          : lic.environment === 'staging' ? '#92400E'
                          : '#075985',
                      }}
                    >
                      ENV · {lic.env_label}
                    </span>
                  )}
                </div>
                <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5">
                  Use when your plan badge appears incorrect or after a network interruption.
                </p>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
              {/* Current state */}
              <div>
                <p className="np-section-label mb-2">Current State</p>
                <div>
                  <StatRow
                    label="Active plan"
                    value={
                      <span
                        className="text-[11px] font-bold px-2 py-0.5 rounded-full"
                        style={{
                          background: lic.plan === 'pro' ? 'var(--np-brand-primary)' : '#E5E7EB',
                          color: lic.plan === 'pro' ? '#FFFFFF' : '#374151',
                        }}
                      >
                        {(lic.plan ?? 'free').toUpperCase()}
                      </span>
                    }
                  />
                  <StatRow label="Verified via" value={lic.source ?? '—'} />
                  <StatRow label="Environment" value={(lic.environment ?? '—').replace(/^./, (c) => c.toUpperCase())} />
                  <StatRow
                    label="Local cache"
                    value={
                      lic.cache_age_minutes != null
                        ? <><span>{lic.cache_age_minutes} min old</span> <StatusTag tone="warm">warm</StatusTag></>
                        : <StatusTag tone="cold">empty</StatusTag>
                    }
                  />
                  <StatRow
                    label="Offline grace"
                    value={
                      lic.grace_active
                        ? <StatusTag tone="warm">{lic.grace_hours_left} h remaining</StatusTag>
                        : <StatusTag tone="off">not active</StatusTag>
                    }
                  />
                  <StatRow
                    label="Licence server"
                    value={
                      lic.server_reachable
                        ? <StatusTag tone="warm">reachable</StatusTag>
                        : <StatusTag tone="off">unreachable</StatusTag>
                    }
                  />
                  {lic.dev_mode_active && (
                    <StatRow label="Dev mode" value={<StatusTag tone="dev">ACTIVE</StatusTag>} />
                  )}
                </div>
              </div>

              {/* Actions */}
              <div>
                <p className="np-section-label mb-2">Recovery Actions</p>
                <div className="space-y-2">
                  <button
                    type="button"
                    onClick={() => clearCache.mutate()}
                    disabled={clearCache.isPending}
                    className="np-btn-primary w-full justify-center text-xs"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                    Clear local licence cache
                  </button>
                  {lic.sync_url && (
                    <a href={lic.sync_url} className="np-btn-secondary w-full justify-center text-xs">
                      <RefreshCw className="w-3.5 h-3.5" />
                      Force licence re-sync
                    </a>
                  )}
                  <button
                    type="button"
                    onClick={() => qc.invalidateQueries({ queryKey: ['tools-status'] })}
                    className="np-btn-secondary w-full justify-center text-xs"
                  >
                    <ServerCog className="w-3.5 h-3.5" />
                    Refresh state display
                  </button>
                  {lic.dev_mode_active && (
                    <button
                      type="button"
                      onClick={async () => {
                        const ok = await useStore.getState().askConfirm({
                          title: 'Reset dev licence state?',
                          message: 'Clears all entitlement transients so the next page load re-fetches plan data as if this were a fresh install. For dev / staging environments only.',
                          details: [
                            'Locally cached entitlements are dropped.',
                            'Next admin request re-queries the licence server.',
                            'Production licences are NOT affected.',
                          ],
                          confirmLabel: 'Reset dev state',
                          tone: 'warning',
                          icon: 'refresh',
                        });
                        if (ok) resetDev.mutate();
                      }}
                      disabled={resetDev.isPending}
                      className="np-btn-secondary text-[#92400E] w-full justify-center text-xs"
                      style={{ borderColor: '#F59E0B' }}
                    >
                      <AlertTriangle className="w-3.5 h-3.5" />
                      Reset dev state
                      <span
                        className="text-[9px] font-bold px-1.5 py-0.5 rounded ml-1"
                        style={{ background: 'rgb(243 154 9 / 0.10)', color: '#F39A09' }}
                      >
                        DEV
                      </span>
                    </button>
                  )}
                </div>
                <p className="text-[11px] text-[color:var(--np-text-muted)] mt-3 leading-relaxed">
                  "Clear cache" removes the locally stored plan and forces a live re-check on next page load. "Force re-sync" re-fetches directly from the activation server. Use re-sync when the plan badge is wrong after checkout.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Danger Zone — Factory Reset */}
        <div
          className="np-card overflow-hidden"
          style={{ borderColor: 'rgb(226 75 74 / 0.30)' }}
        >
          <div
            className="px-5 py-4 border-b flex items-start gap-3"
            style={{
              borderColor: 'rgb(226 75 74 / 0.20)',
              background: 'linear-gradient(135deg, #FEF2F2 0%, #FFF5F5 100%)',
            }}
          >
            <div
              className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0"
              style={{
                background: '#FFFFFF',
                border: '1px solid rgb(226 75 74 / 0.30)',
              }}
            >
              <ShieldAlert className="w-5 h-5" style={{ color: '#E24B4A' }} strokeWidth={2.2} />
            </div>
            <div className="min-w-0 flex-1">
              <h3 className="text-sm font-bold" style={{ color: '#A32D2D' }}>
                Danger Zone
              </h3>
              <p className="text-xs mt-0.5 leading-snug" style={{ color: '#7F1D1D' }}>
                Irreversible operations that affect the engine's runtime state. Use during
                testing or when you need to walk through the setup wizard again from scratch.
              </p>
            </div>
          </div>

          <div className="p-5">
            <div className="flex items-start justify-between gap-4">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-bold" style={{ color: 'var(--np-text-primary)' }}>
                  Factory reset
                </p>
                <p
                  className="text-xs mt-1 leading-relaxed"
                  style={{ color: 'var(--np-text-muted)' }}
                >
                  Disables SSG, removes the cache drop-in, purges the static mirror, clears the
                  pending queue, resets every Nexora option, and re-arms the setup wizard. After
                  reset, the next admin page load redirects to{' '}
                  <code className="np-mono">admin.php?page=ncx-wizard</code> for a fresh setup.
                </p>
                <p
                  className="text-[11px] mt-2 leading-relaxed"
                  style={{ color: '#A32D2D' }}
                >
                  <strong>Not affected:</strong> Nexora license activation, plugin files, your
                  WordPress posts/pages. <strong>This is irreversible</strong> — once the mirror
                  is purged, it must be rebuilt from scratch (the wizard handles that).
                </p>
              </div>
              <button
                type="button"
                onClick={runFactoryReset}
                disabled={factoryReset.isPending}
                className="np-btn-danger text-xs flex-shrink-0"
                style={{ minWidth: 140 }}
              >
                {factoryReset.isPending ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Power className="w-3.5 h-3.5" />
                )}
                {factoryReset.isPending ? 'Resetting…' : 'Factory reset'}
              </button>
            </div>
          </div>
        </div>
      </div>

      <DiagnosticDrawer
        open={diagOpen}
        data={diagData}
        loading={runDiagnostic.isPending}
        onClose={() => setDiagOpen(false)}
        onRerun={() => {
          setDiagData(null);
          runDiagnostic.mutate();
        }}
      />
    </div>
  );
}
