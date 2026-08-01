import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Cloud, RefreshCw, AlertCircle, FileText, Search,
  Lock, Sparkles, ShieldCheck, Pencil, CheckCircle2, XCircle,
  ListFilter, ExternalLink, ServerCog, AlertTriangle,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import StatTile from '../components/ui/StatTile';
import Spinner from '../components/ui/Spinner';
import ExclusionsEditor from '../components/ExclusionsEditor';
import { api, can, SsgState } from '../lib/api';
import { useStore } from '../lib/store';
import { formatBytes, formatNumber, formatRelative } from '../lib/format';
import BuildProgressBanner from '../components/BuildProgressBanner';

// ─── Types ───────────────────────────────────────────────────────────

type PageRow = {
  id: number;
  title: string;
  post_type: string;
  post_type_label: string;
  permalink: string;
  relative: string;
  edit_url: string;
  state: 'captured' | 'stale' | 'pending' | 'fatal';
  is_captured: boolean;
  is_stale: boolean;
  is_fatal: boolean;
  fatal_message: string;
  fatal_ts: number;
  generated_at: number;
  generated_iso: string | null;
  hits: number;
  // Mirror-side data joined in by /ssg/pages — bytes is the static-file size
  // on disk; warnings are any non-fatal capture issues (e.g. missing assets).
  bytes?: number;
  warnings?: string[];
};

type PagesPayload = {
  rows: PageRow[];
  manifest_count: number;
  fatal_count: number;
  pending_count: number;
  // Surfaces wp_count_posts() and WP_Query found_posts so the empty state
  // can tell the user *why* the list is empty (genuinely no content vs.
  // a filter intercepting the query).
  _debug?: {
    wp_query_total: number;
    wp_query_count: number;
    eligible_types: string[];
    wp_count_post: number;
    wp_count_page: number;
  };
};

const STATE_BADGE: Record<PageRow['state'], { cls: string; label: string; Icon: React.FC<any> }> = {
  captured: { cls: 'np-badge-ok',       label: 'Captured',      Icon: CheckCircle2 },
  stale:    { cls: 'np-badge-medium',   label: 'Needs refresh', Icon: RefreshCw },
  pending:  { cls: 'np-badge-low',      label: 'Not captured',  Icon: AlertCircle },
  fatal:    { cls: 'np-badge-critical', label: 'Blocked',       Icon: XCircle },
};

// ─── Settings tabs (above the content table) ────────────────────────

type SettingsTab = 'headless' | 'exclusions';

const SETTINGS_TABS: Array<{ key: SettingsTab; label: string; icon: React.FC<any> }> = [
  { key: 'headless',   label: 'Headless CMS', icon: ShieldCheck },
  { key: 'exclusions', label: 'Exclusions',   icon: ListFilter },
];

// ─── Page ─────────────────────────────────────────────────────────────

export default function Headless() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const hasStealthProxy = can('stealthProxy');

  const stateQ = useQuery({
    queryKey: ['ssg-state'],
    queryFn: () => api.get<SsgState>('ssg/state'),
    refetchInterval: (q) => {
      const s = q.state.data;
      if (s?.running) return 1500;
      if ((s?.pending_count ?? 0) > 0) return 2000;
      return 10_000;
    },
    refetchIntervalInBackground: true,
  });

  const [forceFresh, setForceFresh] = useState(false);
  const pagesQ = useQuery({
    queryKey: ['ssg-pages', forceFresh],
    queryFn: async () => {
      // After a manual refresh, append ?fresh=1 once to bypass the
      // server-side response cache. Reset on success so subsequent polls
      // hit the warm cache again.
      const path = forceFresh ? 'ssg/pages?fresh=1' : 'ssg/pages';
      const r = await api.get<PagesPayload>(path);
      if (forceFresh) setForceFresh(false);
      return r;
    },
    refetchInterval: 60_000,
    staleTime: 30_000,
  });

  const settingsQ = useQuery({
    queryKey: ['settings'],
    queryFn: () => api.get<Record<string, any>>('settings'),
    staleTime: 30_000,
  });
  const wpMaskingOn =
    settingsQ.data?.nexeng_headless_mode === true ||
    settingsQ.data?.nexeng_headless_mode === 'on';
  const assetMode: 'direct' | 'proxy' =
    String(settingsQ.data?.nexeng_asset_mode ?? 'direct') === 'proxy' ? 'proxy' : 'direct';

  const toggleMasking = useMutation({
    mutationFn: (next: boolean) =>
      api.post<Record<string, any>>('settings', { nexeng_headless_mode: next }),
    onSuccess: (_d, next) => {
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['ssg-state'] });
      pushToast('success', `WP Masking ${next ? 'enabled' : 'disabled'}`);
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Failed to toggle WP Masking'),
  });

  const regenOne = useMutation({
    mutationFn: (post_id: number) => api.post('ssg/regen-one', { post_id }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['ssg-state'] });
      qc.invalidateQueries({ queryKey: ['ssg-pages'] });
      pushToast('success', 'Page queued for regen');
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Regen failed'),
  });

  const setAssetModeMut = useMutation({
    mutationFn: (mode: 'direct' | 'proxy') =>
      api.post<any>('ssg/asset-mode', { mode, purge_confirmed: true }),
    onSuccess: (r: any) => {
      pushToast('success', r?.message ?? 'Asset mode updated.');
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['ssg-state'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Failed to switch asset mode'),
  });

  // Default to "headless" because asset mode + WP Masking are the two settings
  // users tweak most after the engine on/off toggle — surface them first.
  const [settingsTab, setSettingsTab] = useState<SettingsTab>('headless');
  const [settingsOpen, setSettingsOpen] = useState(true);

  const state   = stateQ.data;
  const enabled = !!state?.enabled;
  const rows    = pagesQ.data?.rows ?? [];
  const fatalCount   = pagesQ.data?.fatal_count ?? 0;
  // Prefer the live ssg/state value. /ssg/pages caches its whole payload in a
  // transient with pending_count baked in, so preferring it meant this card
  // could sit on a stale count while the rail beside it showed the true one —
  // two different numbers for the same thing on the same screen.
  const pendingCount = state?.pending_count ?? pagesQ.data?.pending_count ?? 0;

  if (stateQ.isLoading) return <Spinner label="Loading static delivery…" />;

  // Headline chip on the collapsed settings header — communicates the most
  // important pieces of the current configuration in one line.
  const headlineChips: string[] = [];
  if (assetMode === 'proxy') headlineChips.push('Stealth Proxy');
  else headlineChips.push('Direct delivery');
  if (wpMaskingOn) headlineChips.push('WP Masking on');

  return (
    <div>
      {/* No on/off control here on purpose. The rail's Static Delivery toggle
          (MirrorBuildControl) is present on every page including this one, so a
          second control was two switches for one setting on the same screen —
          and they could disagree for a moment while either request was in
          flight. The "Engine" tile below reports the state instead. */}
      <PageHeader
        title="Static Delivery"
        subtitle="Serve a pre-rendered HTML mirror of your site to anonymous visitors."
        icon={Cloud}
      />

      <div className="p-6 space-y-5">
        {/* ── Hero stat strip ───────────────────────────────────────── */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <StatTile
            icon={Cloud}
            label="Engine"
            value={enabled ? 'Active' : 'Off'}
            hint={!enabled ? 'Disabled' : state?.running ? 'Building…' : state?.paused ? 'Paused' : 'Idle'}
            tone={enabled ? 'success' : 'default'}
          />
          <StatTile
            icon={FileText}
            label="Static files"
            value={formatNumber(state?.static_files ?? 0)}
            hint={formatBytes(state?.static_bytes ?? 0)}
          />
          <StatTile
            icon={AlertCircle}
            label="Pending"
            value={formatNumber(pendingCount)}
            tone={pendingCount > 0 ? 'warning' : 'default'}
          />
          <StatTile
            icon={RefreshCw}
            label="Last write"
            value={formatRelative(state?.last_write ?? null)}
            hint={state?.auto_rebuild ? 'Auto-rebuild on' : 'Manual'}
          />
        </div>

        {/* ── Build progress banner ─────────────────────────────────── */}
        {/* Shared component so the Dashboard shows the identical live progress.
            Renders nothing unless a build is actually running. */}
        <BuildProgressBanner />

        {/* ── Blocked-page notice ───────────────────────────────────── */}
        {fatalCount > 0 && (
          <div
            className="rounded-xl p-4 flex gap-3 items-start"
            style={{ background: 'var(--np-danger-bg)', border: '1px solid rgba(226,75,74,0.30)' }}
          >
            <XCircle className="w-5 h-5 flex-shrink-0 mt-0.5" style={{ color: 'var(--np-danger)' }} />
            <div className="text-xs leading-snug flex-1" style={{ color: 'var(--np-danger-text)' }}>
              <strong>
                {fatalCount} page{fatalCount === 1 ? ' is' : 's are'} blocked — PHP fatal error on last capture attempt.
              </strong>
              <p className="mt-0.5">
                Common cause: PHP memory exhausted. Add{' '}
                <code className="font-mono">define('WP_MEMORY_LIMIT','512M')</code> to wp-config.php,
                then click the ↻ icon on the blocked rows below.
              </p>
            </div>
          </div>
        )}

        {/* ── Settings (Headless CMS first, then Exclusions) ────────── */}
        <div className="np-card overflow-hidden">
          <button
            type="button"
            onClick={() => setSettingsOpen((v) => !v)}
            className="w-full px-5 py-3 flex items-center justify-between gap-3 border-b transition-colors"
            style={{
              borderColor: settingsOpen ? 'var(--np-border)' : 'transparent',
              background: 'var(--np-bg-card)',
            }}
            aria-expanded={settingsOpen}
          >
            <div className="flex items-center gap-2.5 min-w-0">
              <ServerCog className="w-4 h-4 flex-shrink-0" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
              <span className="text-sm font-bold" style={{ color: 'var(--np-text-primary)' }}>
                Delivery settings
              </span>
              <span className="np-chip text-[10px] truncate">{headlineChips.join(' · ')}</span>
            </div>
            <span className="text-[11px] font-bold uppercase tracking-wider flex-shrink-0" style={{ color: 'var(--np-text-muted)' }}>
              {settingsOpen ? 'Hide' : 'Show'}
            </span>
          </button>

          {settingsOpen && (
            <>
              <div
                className="flex items-center gap-1 px-3 py-2 overflow-x-auto"
                style={{ borderBottom: '1px solid var(--np-border)', background: 'var(--np-bg-subtle)' }}
              >
                {SETTINGS_TABS.map(({ key, label, icon: Icon }) => {
                  const active = settingsTab === key;
                  return (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setSettingsTab(key)}
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all whitespace-nowrap"
                      style={{
                        background: active ? '#FFFFFF' : 'transparent',
                        color: active ? 'var(--np-text-primary)' : 'var(--np-text-secondary)',
                        boxShadow: active
                          ? '0 1px 3px 0 rgb(16 24 40 / 0.10), 0 0 0 1px var(--np-neutral-200)'
                          : 'none',
                      }}
                    >
                      <Icon className="w-3.5 h-3.5" />
                      {label}
                    </button>
                  );
                })}
              </div>

              <div className="p-5">
                {settingsTab === 'headless' && (
                  <HeadlessCmsCard
                    hasStealthProxy={hasStealthProxy}
                    wpMaskingOn={wpMaskingOn}
                    onToggleMasking={(v) => toggleMasking.mutate(v)}
                    toggleBusy={toggleMasking.isPending}
                    assetMode={assetMode}
                    onSwitchMode={(m) => setAssetModeMut.mutate(m)}
                    switchBusy={setAssetModeMut.isPending}
                  />
                )}

                {settingsTab === 'exclusions' && <ExclusionsEditor />}
              </div>
            </>
          )}
        </div>

        {/* ── Unified Pages & Mirror table ──────────────────────────── */}
        <PagesTableCard
          rows={rows}
          debug={pagesQ.data?._debug}
          error={pagesQ.error as Error | null}
          isLoading={pagesQ.isLoading}
          isFetching={pagesQ.isFetching}
          fatalCount={fatalCount}
          enabled={enabled}
          onRegen={(id) => regenOne.mutate(id)}
          regenBusy={regenOne.isPending}
          onRefresh={() => {
            setForceFresh(true);
            qc.invalidateQueries({ queryKey: ['ssg-pages'] });
            pushToast('info', 'Refreshing…');
          }}
        />
      </div>
    </div>
  );
}

// ─── Headless CMS card — single simplified control ───────────────────
//
// Both Stealth Proxy and WP Masking serve the same goal: make the site
// look like it's NOT running WordPress. Clubbing them into one card
// with a simple "how hidden" framing is clearer for everyday users
// (no Step 1 / Step 2) while still surfacing the technical distinction
// for developers who need it.

function HeadlessCmsCard({
  hasStealthProxy, wpMaskingOn, onToggleMasking, toggleBusy,
  assetMode, onSwitchMode, switchBusy,
}: {
  hasStealthProxy: boolean;
  wpMaskingOn: boolean;
  onToggleMasking: (next: boolean) => void;
  toggleBusy: boolean;
  assetMode: 'direct' | 'proxy';
  onSwitchMode: (m: 'direct' | 'proxy') => void;
  switchBusy: boolean;
}) {
  const pushToast = useStore((s) => s.pushToast);
  const stealthOn = assetMode === 'proxy' && hasStealthProxy;
  const fullyHidden = stealthOn && wpMaskingOn;

  // Current protection level — what the user sees at a glance
  const level = fullyHidden ? 'full'
    : stealthOn ? 'stealth'
    : wpMaskingOn ? 'masked'
    : 'standard';

  const LEVEL_META = {
    standard: {
      label: 'Standard delivery',
      desc: 'Your site is fast. WordPress asset paths (/wp-content/…) are visible in the page source — harmless for most sites.',
      color: 'var(--np-text-muted)',
      dot: 'var(--np-neutral-400)',
    },
    masked: {
      label: 'WP fingerprints hidden',
      desc: 'Generator tags, REST discovery links, and admin signals are stripped from every response.',
      color: 'var(--np-brand-primary)',
      dot: 'var(--np-brand-primary)',
    },
    stealth: {
      label: 'Stealth paths',
      desc: 'WordPress asset paths are rewritten to neutral /_ncx_v12/ endpoints — the platform is hidden from source view.',
      color: '#15803D',
      dot: 'var(--np-success)',
    },
    full: {
      label: 'Full headless mode',
      desc: 'Asset paths cloaked + fingerprints stripped. The site looks like a custom-built Next.js or Jamstack product to the outside world.',
      color: '#15803D',
      dot: 'var(--np-success)',
    },
  };
  const meta = LEVEL_META[level];

  return (
    <div>
      {/* Current level indicator */}
      <div
        className="rounded-xl p-4 mb-4 flex items-start gap-3"
        style={{
          background: fullyHidden || stealthOn ? 'var(--np-success-bg)' : 'var(--np-bg-subtle)',
          border: `1px solid ${fullyHidden || stealthOn ? 'rgba(22,163,74,0.25)' : 'var(--np-border)'}`,
        }}
      >
        <span
          className="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-1"
          style={{ background: meta.dot }}
        />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-bold leading-tight" style={{ color: 'var(--np-text-primary)' }}>
            {meta.label}
          </p>
          <p className="text-xs mt-1 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
            {meta.desc}
          </p>
        </div>
      </div>

      {/* Two controls in one card */}
      <div
        className="rounded-xl overflow-hidden"
        style={{ border: '1px solid var(--np-border)' }}
      >
        {/* Stealth Proxy row */}
        <div
          className="flex items-start gap-3 p-4 border-b"
          style={{ borderColor: 'var(--np-border)' }}
        >
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <p className="text-sm font-bold" style={{ color: 'var(--np-text-primary)' }}>
                Stealth Proxy
              </p>
              {!hasStealthProxy && <span className="np-badge-pro text-[9px] px-1.5 py-px">PRO</span>}
            </div>
            <p className="text-xs mt-1 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
              Rewrites all <code className="np-mono">wp-content/</code> and <code className="np-mono">wp-includes/</code> URLs
              to anonymous paths in your HTML. No WordPress fingerprint in the page source.
              {assetMode === 'proxy' && (
                <span style={{ color: 'var(--np-success-text)' }}> Rebuild kicks off automatically when you switch.</span>
              )}
            </p>
          </div>
          {hasStealthProxy ? (
            <button
              type="button"
              disabled={switchBusy}
              onClick={async () => {
                const next = assetMode === 'direct' ? 'proxy' : 'direct';
                const ok = await useStore.getState().askConfirm({
                  title: next === 'proxy' ? 'Enable Stealth Proxy?' : 'Disable Stealth Proxy?',
                  message: next === 'proxy'
                    ? 'WordPress asset paths get rewritten to neutral endpoints. Your mirror will rebuild — visitors keep getting fast HTML throughout.'
                    : 'Asset paths go back to standard /wp-content/ URLs. Mirror rebuilds automatically.',
                  details: [
                    'Mirror is purged and rebuilt from scratch.',
                    'PHP fallback serves visitors during the rebuild.',
                  ],
                  confirmLabel: next === 'proxy' ? 'Enable Stealth' : 'Disable Stealth',
                  tone: next === 'proxy' ? 'primary' : 'warning',
                  icon: 'refresh',
                });
                if (ok) onSwitchMode(next);
              }}
              className="relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0"
              style={{
                background: assetMode === 'proxy' ? 'var(--np-brand-primary)' : 'var(--np-neutral-300)',
                opacity: switchBusy ? 0.5 : 1,
              }}
            >
              <span
                className="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                style={{ transform: assetMode === 'proxy' ? 'translateX(24px)' : 'translateX(4px)' }}
              />
            </button>
          ) : (
            <a href={window.NexoraEngine?.upgradeUrl} target="_blank" rel="noopener noreferrer" className="np-btn-primary text-xs flex-shrink-0">
              <Sparkles className="w-3.5 h-3.5" /> Upgrade
            </a>
          )}
        </div>

        {/* WP Masking row */}
        <div className="flex items-start gap-3 p-4">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <p className="text-sm font-bold" style={{ color: 'var(--np-text-primary)' }}>
                Hide WordPress signals
              </p>

            </div>
            <p className="text-xs mt-1 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
              Strips generator meta tags, REST-API discovery links, and other headers that
              reveal WordPress to bots and security scanners. Works on every page — even
              before the mirror is built.
              {wpMaskingOn && (
                <span style={{ color: 'var(--np-success-text)' }}> Pages quietly re-queue to reflect the change.</span>
              )}
            </p>
          </div>
          {/* Free feature: masking ships and runs on every install. */}
            <button
              type="button"
              disabled={toggleBusy}
              onClick={async () => {
                const turningOn = !wpMaskingOn;
                const ok = await useStore.getState().askConfirm({
                  title: turningOn ? 'Hide WordPress signals?' : 'Show WordPress signals?',
                  message: turningOn
                    ? 'Generator tags and REST discovery links are stripped from every response. Pages re-queue quietly — no visible interruption to visitors.'
                    : 'WordPress meta tags will become visible in responses again. Pages re-queue to reflect the change.',
                  details: [
                    'Mirror is NOT purged — visitors never see a blank page.',
                    'Changed pages go into the pending queue and rebuild in the background.',
                  ],
                  confirmLabel: turningOn ? 'Hide signals' : 'Show signals',
                  tone: turningOn ? 'primary' : 'warning',
                  icon: 'shield-alert',
                });
                if (ok) onToggleMasking(turningOn);
              }}
              className="relative inline-flex h-6 w-11 items-center rounded-full transition-colors flex-shrink-0"
              style={{
                background: wpMaskingOn ? 'var(--np-success)' : 'var(--np-neutral-300)',
                opacity: toggleBusy ? 0.5 : 1,
              }}
            >
              <span
                className="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                style={{ transform: wpMaskingOn ? 'translateX(24px)' : 'translateX(4px)' }}
              />
            </button>
        </div>
      </div>
    </div>
  );
}

// ─── Unified Pages & Posts table (mirror data merged inline) ────────

function PagesTableCard({
  rows, debug, error, isLoading, isFetching, fatalCount, enabled, onRegen, regenBusy, onRefresh,
}: {
  rows: PageRow[];
  debug?: PagesPayload['_debug'];
  error?: Error | null;
  isLoading: boolean;
  isFetching: boolean;
  fatalCount: number;
  enabled: boolean;
  onRegen: (id: number) => void;
  regenBusy: boolean;
  onRefresh: () => void;
}) {
  const [typeFilter, setTypeFilter]   = useState<string>('all');
  // 'queued' is not a row state — it is the union of the two states that make a
  // page part of the build queue. pending_count() counts marked-pending plus
  // never-captured, but a page that was captured and then edited reports its
  // state as 'stale', so filtering on 'pending' alone matched none of them.
  const [stateFilter, setStateFilter] = useState<'all' | PageRow['state'] | 'queued'>('all');
  const [query, setQuery]             = useState('');
  const tableRef = useRef<HTMLDivElement>(null);

  // The rail's "View all" bumps this counter. Filter to pending, clear any
  // search that would hide rows, and scroll here — the table sits below the
  // fold on Static Delivery, so filtering alone looked like nothing happened.
  const showPendingSignal = useStore((s) => s.showPendingSignal);
  useEffect(() => {
    if (showPendingSignal === 0) return;
    setStateFilter('queued');
    setTypeFilter('all');
    setQuery('');
    tableRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, [showPendingSignal]);

  const typesPresent = useMemo(() => {
    const set = new Set<string>();
    rows.forEach((r) => set.add(r.post_type));
    return Array.from(set).sort();
  }, [rows]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return rows.filter((r) => {
      if (typeFilter !== 'all' && r.post_type !== typeFilter) return false;
      if (stateFilter === 'queued') {
        if (r.state !== 'pending' && r.state !== 'stale') return false;
      } else if (stateFilter !== 'all' && r.state !== stateFilter) {
        return false;
      }
      if (q && !(r.title.toLowerCase().includes(q) || r.relative.toLowerCase().includes(q))) return false;
      return true;
    });
  }, [rows, typeFilter, stateFilter, query]);

  const totalBytes = useMemo(
    () => rows.reduce((s, r) => s + (r.bytes ?? 0), 0),
    [rows],
  );

  return (
    <div className="np-card" ref={tableRef}>
      <div className="px-5 py-4 border-b" style={{ borderColor: 'var(--np-border)' }}>
        <div className="flex items-start justify-between gap-3 flex-wrap">
          <div className="min-w-0">
            <h3 className="text-sm font-bold" style={{ color: 'var(--np-text-primary)' }}>
              Pages &amp; Posts
            </h3>
            <p className="text-xs mt-0.5 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
              Every published page with its capture status, mirror size, and traffic.
              Click <RefreshCw className="w-3 h-3 inline mx-0.5 -mt-0.5" /> on a row to rebuild that page,
              or <Pencil className="w-3 h-3 inline mx-0.5 -mt-0.5" /> to edit it in WordPress.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-shrink-0">
            <span className="np-chip text-[10px]">
              {formatNumber(rows.length)} pages · {formatBytes(totalBytes)}
            </span>
            {/* Refresh button — clear hover + active state, spinner when in-flight.
                If a build is running, the fetch can take several seconds because
                it contends with the capture loopback on shared FPM workers.
                The spinning icon and disabled state communicate "working" clearly. */}
            <button
              type="button"
              onClick={onRefresh}
              disabled={isFetching}
              className="np-btn-secondary text-xs"
              title={isFetching ? 'Fetching from server — this may take a moment while a build is running' : 'Refresh pages list'}
            >
              <RefreshCw className={`w-3.5 h-3.5 ${isFetching ? 'animate-spin' : ''}`} />
              {isFetching ? 'Loading…' : 'Refresh'}
            </button>
          </div>
        </div>
      </div>

      {/* Filter bar — single row, always.
          Layout: [tabs scrollable] [status dropdown] [search] [count]
          The tab group scrolls horizontally if there are many CPTs; the
          dropdown, search and count chip are flex-shrink-0 so they never
          move off-screen or wrap below. */}
      <div
        className="px-4 py-2.5 flex items-center gap-2 border-b"
        style={{ background: 'var(--np-bg-page)', borderColor: 'var(--np-border)', minHeight: '52px' }}
      >
        {/* Type tabs — horizontal scroll when overflow, no wrap */}
        <div
          className="np-segment overflow-x-auto flex-shrink min-w-0"
          style={{ scrollbarWidth: 'none' }}
        >
          <button
            type="button"
            onClick={() => setTypeFilter('all')}
            className={`np-segment-btn whitespace-nowrap ${typeFilter === 'all' ? 'np-segment-btn-active' : ''}`}
          >
            All
          </button>
          {typesPresent.map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setTypeFilter(t)}
              className={`np-segment-btn whitespace-nowrap ${typeFilter === t ? 'np-segment-btn-active' : ''}`}
            >
              {t.charAt(0).toUpperCase() + t.slice(1)}
            </button>
          ))}
        </div>

        {/* Status dropdown — fixed narrow width */}
        <select
          value={stateFilter}
          onChange={(e) => setStateFilter(e.target.value as any)}
          className="np-input text-sm py-1.5 px-2.5 flex-shrink-0"
          style={{ width: 148 }}
        >
          <option value="all">All statuses</option>
          <option value="queued">In build queue</option>
          <option value="captured">Captured</option>
          <option value="stale">Needs refresh</option>
          <option value="pending">Not captured</option>
          {fatalCount > 0 && <option value="fatal">Blocked</option>}
        </select>

        {/* Search — medium fixed width, never expands to push count chip off */}
        <div className="np-search-wrap flex-shrink-0" style={{ width: 200 }}>
          <Search />
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search…"
            className="np-search-input"
            style={{ width: '100%' }}
          />
        </div>

        {/* Count — always visible */}
        <span className="np-chip text-xs flex-shrink-0 ml-auto">
          {formatNumber(filtered.length)}&thinsp;/&thinsp;{formatNumber(rows.length)}
        </span>
      </div>

      {isLoading ? (
        <Spinner />
      ) : filtered.length === 0 ? (
        <EmptyState rows={rows.length} debug={debug} error={error} onRefresh={onRefresh} />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead>
              <tr className="np-table-head">
                <th className="text-left px-5 py-2.5 font-semibold text-xs">Page / URL</th>
                <th className="text-left px-5 py-2.5 font-semibold text-xs">Type</th>
                <th className="text-left px-5 py-2.5 font-semibold text-xs">Capture</th>
                <th className="text-right px-5 py-2.5 font-semibold text-xs">Size</th>
                <th className="text-right px-5 py-2.5 font-semibold text-xs">7D Traffic</th>
                <th className="text-left px-5 py-2.5 font-semibold text-xs">Last Captured</th>
                <th className="px-5 py-2.5" />
              </tr>
            </thead>
            <tbody>
              {filtered.map((r) => {
                const badge = STATE_BADGE[r.state];
                const Icon  = badge.Icon;
                const hasWarning = (r.warnings ?? []).length > 0;
                return (
                  <tr key={r.id} className="border-t" style={{ borderColor: 'var(--np-border)' }}>
                    <td className="px-5 py-3 align-middle min-w-0">
                      <div className="flex items-center gap-1.5 max-w-[320px]">
                        <p
                          className="text-sm font-semibold truncate"
                          style={{ color: 'var(--np-text-primary)' }}
                          title={r.title}
                        >
                          {r.title || `#${r.id}`}
                        </p>
                        {hasWarning && (
                          <span title={(r.warnings ?? []).join('\n')} className="flex-shrink-0">
                            <AlertTriangle
                              className="w-3 h-3"
                              style={{ color: 'var(--np-warning)' }}
                              aria-label="Capture warnings"
                            />
                          </span>
                        )}
                      </div>
                      <a
                        href={r.permalink}
                        target="_blank"
                        rel="noreferrer"
                        className="text-[11px] font-mono truncate block max-w-[320px] hover:underline inline-flex items-center gap-1"
                        style={{ color: 'var(--np-text-muted)' }}
                      >
                        <ExternalLink className="w-2.5 h-2.5" />
                        {r.relative}
                      </a>
                    </td>
                    <td className="px-5 py-3 align-middle">
                      <span className="np-chip">{r.post_type_label}</span>
                    </td>
                    <td className="px-5 py-3 align-middle">
                      <span
                        className={badge.cls}
                        title={
                          r.is_fatal && r.fatal_message
                            ? r.fatal_message
                            : hasWarning
                              ? (r.warnings ?? []).join('\n')
                              : undefined
                        }
                      >
                        <Icon className="w-3 h-3" />
                        {badge.label}
                      </span>
                    </td>
                    <td className="px-5 py-3 align-middle text-right text-xs tabular-nums" style={{ color: 'var(--np-text-primary)' }}>
                      {r.bytes && r.bytes > 0 ? formatBytes(r.bytes) : <span style={{ color: 'var(--np-text-muted)' }}>—</span>}
                    </td>
                    <td className="px-5 py-3 align-middle text-right">
                      {r.hits > 0 ? (
                        <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--np-text-primary)' }}>
                          {formatNumber(r.hits)}
                          <span className="text-[10px] uppercase ml-1 font-semibold" style={{ color: 'var(--np-text-muted)' }}>hits</span>
                        </span>
                      ) : (
                        <span className="text-xs" style={{ color: 'var(--np-text-muted)' }}>—</span>
                      )}
                    </td>
                    <td className="px-5 py-3 align-middle text-xs" style={{ color: 'var(--np-text-muted)' }}>
                      {r.generated_iso ? formatRelative(r.generated_iso) : 'Never'}
                    </td>
                    <td className="px-5 py-3 align-middle text-right">
                      <div className="inline-flex items-center gap-1">
                        <button
                          type="button"
                          onClick={() => onRegen(r.id)}
                          disabled={regenBusy || !enabled}
                          className="np-btn-ghost text-[11px]"
                          title={r.is_fatal
                            ? "Retry capture — clears the block and attempts again."
                            : 'Rebuild this page'}
                          style={r.is_fatal ? { color: 'var(--np-warning)' } : undefined}
                        >
                          <RefreshCw className="w-3 h-3" />
                        </button>
                        <a
                          href={r.edit_url}
                          className="np-btn-ghost text-[11px]"
                          title="Edit in WordPress"
                        >
                          <Pencil className="w-3 h-3" />
                        </a>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

/**
 * Empty-state renderer for the Pages table. Uses the server-side `_debug`
 * payload to distinguish three cases:
 *
 *   1. The site genuinely has no published content (wp_count_post +
 *      wp_count_page === 0) — show "publish your first post" CTA.
 *   2. The site has published posts but the query came back empty
 *      (wp_count_post > 0 but wp_query_total === 0) — almost always
 *      a third-party filter (cache plugin, headless theme, pre_get_posts)
 *      intercepting REST queries. Surface this to the user with a
 *      "Diagnose" affordance instead of pretending nothing exists.
 *   3. The query returned posts but the React layer filtered them all
 *      out — generic "no matches" message.
 */
function EmptyState({
  rows, debug, error, onRefresh,
}: {
  rows: number;
  debug?: PagesPayload['_debug'];
  error?: Error | null;
  onRefresh: () => void;
}) {
  if (rows > 0) {
    return (
      <div className="p-10 text-center text-xs" style={{ color: 'var(--np-text-muted)' }}>
        No pages match those filters.
      </div>
    );
  }

  // REST call failed — surface the actual error to the user instead of the
  // generic empty state. A silent error masquerading as "no content" is the
  // worst possible UX: the user thinks they have to create posts when
  // really the API is throwing a 500.
  if (error) {
    return (
      <div className="p-8 text-center" style={{ color: 'var(--np-text-muted)' }}>
        <p className="text-sm font-semibold" style={{ color: 'var(--np-danger-text)' }}>
          Could not load the content list
        </p>
        <p className="text-xs mt-2 leading-snug max-w-md mx-auto">
          The REST endpoint returned an error. Reload, then check your browser
          console and PHP error log for details.
        </p>
        <pre
          className="text-[11px] mt-3 inline-block text-left px-3 py-2 rounded-lg np-mono"
          style={{
            background: 'var(--np-danger-bg)',
            border: '1px solid rgba(226,75,74,0.25)',
            color: 'var(--np-danger-text)',
            maxWidth: '40rem',
            whiteSpace: 'pre-wrap',
          }}
        >
          {error.message}
        </pre>
        <div className="mt-4">
          <button type="button" onClick={onRefresh} className="np-btn-primary text-xs">
            <RefreshCw className="w-3.5 h-3.5" />
            Retry
          </button>
        </div>
      </div>
    );
  }

  const totalInDb = (debug?.wp_count_post ?? 0) + (debug?.wp_count_page ?? 0);
  const queryReturned = debug?.wp_query_total ?? 0;
  const intercepted = totalInDb > 0 && queryReturned === 0;

  if (intercepted) {
    return (
      <div className="p-8 text-center" style={{ color: 'var(--np-text-muted)' }}>
        <p className="text-sm font-semibold" style={{ color: 'var(--np-warning-text)' }}>
          Found {formatNumber(totalInDb)} published item{totalInDb === 1 ? '' : 's'} in WordPress, but the query returned none.
        </p>
        <p className="text-xs mt-2 leading-snug max-w-md mx-auto">
          A plugin or theme is filtering out our content query. Common causes: an
          aggressive cache plugin intercepting REST requests, a headless theme
          calling <code className="np-mono">pre_get_posts</code>, or an
          object-cache mismatch. Try the actions below — if the issue persists,
          temporarily deactivate other plugins to find the culprit.
        </p>
        <div className="flex items-center justify-center gap-2 mt-4">
          <button type="button" onClick={onRefresh} className="np-btn-primary text-xs">
            <RefreshCw className="w-3.5 h-3.5" />
            Retry
          </button>
          <a
            href={`${window.NexoraEngine?.adminUrl ?? ''}plugins.php`}
            className="np-btn-secondary text-xs"
          >
            Manage plugins
          </a>
        </div>
        {debug && (
          <details className="mt-4 inline-block text-left">
            <summary
              className="text-[11px] cursor-pointer"
              style={{ color: 'var(--np-text-muted)' }}
            >
              Diagnostic detail
            </summary>
            <pre
              className="text-[10px] mt-2 p-3 rounded-lg np-mono text-left"
              style={{
                background: 'var(--np-bg-subtle)',
                border: '1px solid var(--np-border)',
                color: 'var(--np-text-primary)',
              }}
            >
{`Posts in DB:        ${debug.wp_count_post}
Pages in DB:        ${debug.wp_count_page}
WP_Query returned:  ${debug.wp_query_total}
Eligible types:     ${debug.eligible_types.join(', ')}`}
            </pre>
          </details>
        )}
      </div>
    );
  }

  return (
    <div className="p-10 text-center" style={{ color: 'var(--np-text-muted)' }}>
      <p className="text-sm font-semibold" style={{ color: 'var(--np-text-primary)' }}>
        Nothing to capture yet
      </p>
      <p className="text-xs mt-1 leading-snug max-w-md mx-auto">
        Publish a post or page in WordPress and it will appear here automatically with capture status. Static delivery starts the moment the first eligible page exists.
      </p>
      <a
        href={`${window.NexoraEngine?.adminUrl ?? ''}post-new.php`}
        className="np-btn-primary text-xs mt-4 inline-flex"
      >
        Add new post
      </a>
    </div>
  );
}
