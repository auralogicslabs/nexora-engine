import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Activity, Play, Pause, Square, RefreshCw, Trash2,
  AlertCircle, CheckCircle2, Loader2, Zap, FileWarning,
  Sparkles, Pencil, Lock, Eye,
} from 'lucide-react';
import { api, can, SsgState, SsgPendingItem, SsgActivityItem } from '../lib/api';
import { useStore } from '../lib/store';
import { formatBytes, formatNumber, formatRelative } from '../lib/format';

function ActionButton({
  icon: Icon,
  label,
  onClick,
  busy,
  disabled,
  tone = 'default',
}: {
  icon: React.FC<any>;
  label: string;
  onClick: () => void;
  busy?: boolean;
  disabled?: boolean;
  tone?: 'default' | 'primary' | 'success' | 'danger';
}) {
  const className =
    tone === 'primary' ? 'np-btn-primary'
    : tone === 'success' ? 'np-btn-success'
    : tone === 'danger' ? 'np-btn-danger'
    : 'np-btn-secondary';
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={busy || disabled}
      className={`${className} w-full justify-center gap-1.5`}
      style={{ opacity: busy || disabled ? 0.6 : 1 }}
    >
      {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Icon className="w-3.5 h-3.5" />}
      <span>{label}</span>
    </button>
  );
}

/**
 * Three big numeric KPIs at the top of the rail (Captured / Total / Pending).
 * These replace the per-row "StatLine" pairs that used to dominate the panel —
 * they read as instrument cluster digits so the user can scan engine health
 * in one glance.
 */
/**
 * KPI tile inside the brand-blue hero strip. White text for contrast;
 * a small colored dot below the number signals the semantic tone.
 * Made taller and the number larger so the key metrics are immediately
 * readable — the rail has plenty of vertical space.
 */
function HeroKpi({ label, value, hint, tone = 'default' }: {
  label: string;
  value: React.ReactNode;
  hint?: string;
  tone?: 'default' | 'success' | 'warning';
}) {
  const dotColor =
    tone === 'success' ? '#86EFAC'
    : tone === 'warning' ? '#FCD34D'
    : 'rgba(255,255,255,0.50)';
  return (
    <div
      className="flex flex-col items-center justify-center rounded-xl py-3 px-1"
      style={{ background: 'rgba(255,255,255,0.12)' }}
    >
      <span
        className="text-2xl font-bold tabular-nums leading-none text-white"
        style={{ letterSpacing: '-0.02em' }}
      >
        {value}
      </span>
      {hint && (
        <span
          className="text-[10px] tabular-nums mt-0.5 font-medium"
          style={{ color: 'rgba(255,255,255,0.65)' }}
        >
          {hint}
        </span>
      )}
      <div className="flex items-center gap-1 mt-2">
        <span
          className="w-1.5 h-1.5 rounded-full inline-block"
          style={{ background: dotColor }}
        />
        <span
          className="text-[9px] uppercase font-bold"
          style={{ color: 'rgba(255,255,255,0.80)', letterSpacing: '0.07em' }}
        >
          {label}
        </span>
      </div>
    </div>
  );
}

// Friendly labels for the raw queue-reason slugs so the rail never shows a
// bare token like "never_captured".
function reasonLabel(reason: string): string {
  const map: Record<string, string> = {
    never_captured: 'Not captured yet',
    global_change: 'Site-wide change',
    content: 'Content edited',
    manual: 'Manual',
  };
  return map[reason] ?? reason.replace(/_/g, ' ');
}

function PendingRow({ item }: { item: SsgPendingItem }) {
  return (
    <a
      href={item.edit_url || item.permalink}
      target={item.edit_url ? undefined : '_blank'}
      rel="noreferrer"
      className="flex items-start gap-2 px-2 py-1.5 rounded-lg group transition-colors"
      style={{ background: 'transparent' }}
      onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(2,82,250,0.06)')}
      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
    >
      <span
        className="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0"
        style={{ background: '#F39A09' }}
      />
      <div className="min-w-0 flex-1">
        <p
          className="text-[11px] font-semibold leading-tight truncate"
          style={{ color: 'var(--np-text-primary)' }}
          title={item.title}
        >
          {item.title}
        </p>
        <p
          className="text-[10px] mt-0.5 truncate"
          style={{ color: 'var(--np-text-muted)' }}
        >
          {item.queued_iso ? `Queued ${formatRelative(item.queued_iso)}` : 'Queued'}
          {item.reason ? ` · ${reasonLabel(item.reason)}` : ''}
        </p>
      </div>
      <Pencil
        className="w-3 h-3 flex-shrink-0 mt-1 opacity-0 group-hover:opacity-60 transition-opacity"
        style={{ color: 'var(--np-text-muted)' }}
      />
    </a>
  );
}

function ActivityRow({ item }: { item: SsgActivityItem }) {
  return (
    <a
      href={item.permalink}
      target="_blank"
      rel="noreferrer"
      className="flex items-start gap-2 px-2 py-1.5 rounded-lg group transition-colors"
      onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(22,163,74,0.06)')}
      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
    >
      <CheckCircle2
        className="w-3 h-3 mt-1 flex-shrink-0"
        style={{ color: '#16A34A' }}
      />
      <div className="min-w-0 flex-1">
        <p
          className="text-[11px] font-semibold leading-tight truncate"
          style={{ color: 'var(--np-text-primary)' }}
          title={item.title}
        >
          {item.title}
        </p>
        <p className="text-[10px] mt-0.5" style={{ color: 'var(--np-text-muted)' }}>
          Captured {formatRelative(item.generated_iso)}
        </p>
      </div>
    </a>
  );
}

export default function MirrorBuildControl({ currentPath }: { currentPath: string }) {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const requestShowPending = useStore((s) => s.requestShowPending);
  const bumpBuildControl  = useStore((s) => s.bumpBuildControl);
  // Toggles the inline error list inside the "degraded" banner. Kept at the
  // top of the component so every render goes through the same hook order
  // even when the Settings short-circuit fires further down (Rules of Hooks).
  const [showErrors, setShowErrors] = useState(false);

  // ── Hooks — all called unconditionally to satisfy the Rules of Hooks. ──
  const stateQ = useQuery({
    queryKey: ['ssg-state'],
    queryFn: () => api.get<SsgState>('ssg/state'),
    // Poll fast when something's actively happening:
    //   • a bulk build is running (running=true)
    //   • or posts are pending — auto-rebuild should pick them up any moment,
    //     and we want the queue to *visibly* drain without the user touching
    //     the tab. 8 s feels broken when a user just published a post and
    //     watches the rail to confirm the capture finished.
    refetchInterval: (q) => {
      const s = q.state.data;
      if (s?.running) return 1500;
      if ((s?.pending_count ?? 0) > 0) return 2000;
      return 10_000;
    },
    refetchIntervalInBackground: true,
  });

  const state = stateQ.data;

  function run(action: string, path: string, body?: unknown) {
    return async () => {
      try {
        // Announce the control BEFORE the request. A batch-tick is usually
        // already in flight; without this its response lands afterwards and
        // rewrites the cache with running:true, so Pause and Stop appeared to
        // do nothing until the page was reloaded.
        bumpBuildControl();
        await api.post(path, body);
        await qc.invalidateQueries({ queryKey: ['ssg-state'] });
        pushToast('success', `${action} complete`);
      } catch (e: any) {
        pushToast('error', e?.message ?? `${action} failed`);
      }
    };
  }

  const toggle = useMutation({
    mutationFn: (enabled: boolean) => api.post<SsgState>('ssg/toggle', { enabled }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['ssg-state'] }),
  });

  // Hide the panel on Settings (it would duplicate the controls the user is
  // configuring on the page). This early return MUST come after every hook
  // call above — see commit history for the "Rendered fewer hooks" bug.
  if (currentPath.startsWith('/settings')) return null;

  const enabled  = !!state?.enabled;
  const running  = !!state?.running;
  const paused   = !!state?.paused;
  const pct      = Math.max(0, Math.min(100, state?.percent ?? 0));
  const pending  = state?.pending_count ?? 0;
  const pendingPreview = state?.pending_preview ?? [];
  const activity = state?.activity ?? [];
  const isPro    = !!state?.is_pro;
  // Whether automatic rebuild is installed at all. Free builds ship without
  // class-ncx-ssg-auto, so manual is not a restriction there — it is the mode.
  const hasAutoRebuild = can('autoRebuild');
  const autoRebuildEffective = !!state?.auto_rebuild_effective;
  const degraded      = !!state?.degraded;
  const degradedReason = state?.degraded_reason ?? '';
  const recentErrors  = state?.recent_errors ?? [];
  const failedCount   = state?.failed_count ?? 0;
  const curl28Count   = state?.curl28_count ?? 0;
  // cURL 28 = PHP-FPM pool exhausted (LocalWP 2-worker limit). It's not a
  // broken page — it's the capture loopback waiting for a free worker that
  // the admin tab is already holding. Resume is safe; captures will pace
  // slower but eventually complete.
  const isFpmExhausted = degradedReason === 'fpm_worker_exhausted' || curl28Count >= 2;
  const autoCap         = state?.auto_cap ?? 100;
  const autoCapExceeded = !!state?.auto_cap_exceeded;
  const throttled       = state?.throttled ?? '';

  // High-level status descriptor — drives the hero header pill.
  const statusKind: 'building' | 'paused' | 'pending' | 'idle' | 'off' =
    !enabled ? 'off'
    : running ? 'building'
    : paused  ? 'paused'
    : pending > 0 ? 'pending'
    : 'idle';

  // Status pill displayed in the blue hero. Pills are white-on-blue with a
  // small colored dot for the actual status signal — that way we get high
  // contrast (premium SaaS feel) instead of the previous green/amber/red
  // text fighting the blue gradient.
  const statusMeta = {
    building: { label: 'Building', dot: '#86EFAC' },   // soft lime
    paused:   { label: 'Paused',   dot: '#FCD34D' },   // soft amber
    pending:  { label: 'Pending',  dot: '#FCD34D' },
    idle:     { label: 'Online',   dot: '#86EFAC' },
    off:      { label: 'Off',      dot: 'rgba(255,255,255,0.45)' },
  }[statusKind];

  return (
    <div
      className="p-4 np-scrollbar"
      // The rail's parent <aside> is sticky + scrollable in Layout.tsx,
      // so this inner panel just needs to fill it. No extra sticky or
      // overflow rules here — duplicating them would create a nested
      // scroll container and lose the smooth single-axis scroll feel.
      style={{
        background: 'var(--np-bg-page)',
      }}
    >
      {/* ── Engine ON/OFF — topmost, most prominent control ──────────
          Matches the reference design: Static Delivery label + big
          toggle at the very top so the user's first action is clear.
          Auto-rebuild status lives directly below the toggle row. */}
      <div
        className="np-card p-4 mb-3"
        style={{
          borderColor: enabled ? 'rgba(22,163,74,0.30)' : 'var(--np-border)',
          background: enabled ? 'rgba(22,163,74,0.04)' : 'var(--np-bg-card)',
        }}
      >
        <div className="flex items-center justify-between mb-3">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-bold leading-tight" style={{ color: 'var(--np-text-primary)' }}>
              Static Delivery
            </p>
            <p className="text-xs mt-0.5 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
              {enabled
                ? 'Serving static HTML to anonymous visitors.'
                : 'PHP fallback mode — enable to activate the mirror.'}
            </p>
          </div>
          <button
            type="button"
            onClick={() => toggle.mutate(!enabled)}
            disabled={toggle.isPending}
            className="relative inline-flex h-7 w-12 items-center rounded-full transition-colors flex-shrink-0 ml-3"
            style={{ background: enabled ? '#16A34A' : '#CBD5E1' }}
            aria-label={enabled ? 'Disable static delivery' : 'Enable static delivery'}
          >
            <span
              className="inline-block h-5 w-5 transform rounded-full bg-white transition-transform shadow"
              style={{ transform: enabled ? 'translateX(24px)' : 'translateX(4px)' }}
            />
          </button>
        </div>

        {/* Auto-rebuild chip */}
        <div
          className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg"
          style={{
            background: autoRebuildEffective ? 'rgba(22,163,74,0.08)' : 'rgba(243,154,9,0.10)',
            border: `1px solid ${autoRebuildEffective ? 'rgba(22,163,74,0.20)' : 'rgba(243,154,9,0.25)'}`,
          }}
        >
          {autoRebuildEffective ? (
            <>
              <Sparkles className="w-3 h-3 flex-shrink-0" style={{ color: '#15803D' }} />
              <div className="min-w-0 flex-1">
                <p className="text-[11px] font-bold leading-tight" style={{ color: '#15803D' }}>
                  Auto-rebuild on
                </p>
                <p className="text-[10px]" style={{ color: '#166534' }}>
                  Pages rebuild automatically when edited.
                </p>
              </div>
            </>
          ) : (
            <>
              {/* No padlock here. Manual rebuild is how the free tier works,
                  not a withheld feature — a lock icon framed the normal,
                  fully-working mode as something broken. */}
              <RefreshCw className="w-3 h-3 flex-shrink-0" style={{ color: '#92400E' }} />
              <div className="min-w-0 flex-1">
                <p className="text-[11px] font-bold leading-tight" style={{ color: '#92400E' }}>
                  Manual rebuild{hasAutoRebuild ? ' (off in Settings)' : ''}
                </p>
                <p className="text-[10px]" style={{ color: '#78350F' }}>
                  {hasAutoRebuild
                    ? 'Enable auto-rebuild in Settings.'
                    : 'Changed pages queue up — rebuild when you are ready.'}
                </p>
              </div>
            </>
          )}
        </div>
      </div>

      {/* ── Hero KPI card — brand-blue gradient strip ─────────────── */}
      <div
        className="rounded-2xl p-4 mb-3 relative overflow-hidden"
        style={{
          background: 'linear-gradient(135deg, var(--np-brand-primary) 0%, var(--np-brand-primary-hover) 100%)',
          boxShadow: '0 6px 20px rgb(2 82 250 / 0.22)',
        }}
      >
        {/* Soft glow blob in the corner — subtle premium texture */}
        <div
          className="absolute -top-8 -right-8 w-28 h-28 rounded-full"
          style={{ background: 'rgba(255,255,255,0.18)', filter: 'blur(6px)' }}
        />
        <div className="relative flex items-center gap-2.5 mb-3">
          <div
            className="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
            style={{
              background: 'rgba(255,255,255,0.18)',
              border: '1px solid rgba(255,255,255,0.10)',
            }}
          >
            <Activity className="w-4 h-4 text-white" strokeWidth={2.4} />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[13px] font-bold leading-tight text-white">
              Mirror Build Control
            </p>
            <p
              className="text-[10px] leading-tight uppercase font-semibold"
              style={{ color: 'rgba(255,255,255,0.72)', letterSpacing: '0.06em' }}
            >
              Static delivery engine
            </p>
          </div>
          <span className="np-chip-on-dark" title={`Engine status: ${statusMeta.label}`}>
            <span
              className="w-1.5 h-1.5 rounded-full inline-block"
              style={{
                background: statusMeta.dot,
                boxShadow: running
                  ? `0 0 0 3px rgba(255,255,255,0.20), 0 0 6px 0 ${statusMeta.dot}`
                  : 'none',
              }}
            />
            {statusMeta.label}
          </span>
        </div>

        {/* Hero KPI row — 4 tiles: Captured / Pending / Mirror Size / Last write */}
        <div className="relative grid grid-cols-2 gap-2">
          <HeroKpi
            label="Captured"
            value={formatNumber(state?.static_files ?? 0)}
            hint={formatBytes(state?.static_bytes ?? 0)}
            tone="success"
          />
          <HeroKpi
            label="Pending"
            value={formatNumber(pending)}
            hint={pending > 0 ? 'in queue' : 'all current'}
            tone={pending > 0 ? 'warning' : 'default'}
          />
          <HeroKpi
            label="Progress"
            value={`${state?.percent ?? 0}%`}
            hint={running ? `${formatNumber(state?.processed ?? 0)} done` : 'overall'}
            tone={running ? 'success' : 'default'}
          />
          <HeroKpi
            label="Last write"
            value={formatRelative(state?.last_write ?? null)}
            tone="default"
          />
        </div>

        {/* Progress bar */}
        {(running || pct > 0) && (
          <div className="relative mt-3">
            <div
              className="h-1.5 rounded-full overflow-hidden"
              style={{ background: 'rgba(255,255,255,0.20)' }}
            >
              <div
                className="h-full rounded-full transition-all duration-700 ease-out"
                style={{
                  width: `${Math.max(3, pct)}%`,
                  background: 'linear-gradient(90deg, #FFFFFF 0%, #DBEAFE 100%)',
                }}
              />
            </div>
            <div className="flex items-center justify-between mt-1">
              <span className="text-[10px] tabular-nums" style={{ color: 'rgba(255,255,255,0.78)' }}>
                {formatNumber(state?.processed ?? 0)} / {formatNumber(state?.total ?? 0)}
              </span>
              <span className="text-[10px] font-bold tabular-nums text-white">{pct}%</span>
            </div>
          </div>
        )}
      </div>

      {/* ── Pending queue (inline preview) ───────────────────────── */}
      {pending > 0 && (
        <div className="np-card p-3 mb-3">
          <div className="flex items-center justify-between mb-2">
            <span
              className="text-xs font-bold uppercase tracking-wider flex items-center gap-1.5"
              style={{ color: '#92400E' }}
            >
              <AlertCircle className="w-3.5 h-3.5" />
              Pending queue · {formatNumber(pending)}
            </span>
            {pendingPreview.length < pending && (
              <button
                type="button"
                onClick={() => {
                  // Navigating to #/headless alone did nothing when the user was
                  // already on Static Delivery — which is where the rail is most
                  // used, so the button looked dead. Go there if needed, then
                  // signal the table to filter itself to the pending rows.
                  if (!currentPath.startsWith('/headless')) {
                    window.location.hash = '#/headless';
                  }
                  requestShowPending();
                }}
                className="text-[10px] font-bold uppercase tracking-wider hover:underline"
                style={{ color: 'var(--np-brand-primary)', background: 'none', border: 0, cursor: 'pointer' }}
              >
                View all
              </button>
            )}
          </div>
          <div className="space-y-0.5">
            {pendingPreview.length === 0 ? (
              <p className="text-[11px] py-2 text-center" style={{ color: 'var(--np-text-muted)' }}>
                Loading queue…
              </p>
            ) : (
              pendingPreview.map((p) => <PendingRow key={p.id} item={p} />)
            )}
          </div>
        </div>
      )}

      {/* ── Recent activity (when nothing pending) ───────────────── */}
      {pending === 0 && activity.length > 0 && (
        <div className="np-card p-3 mb-3">
          <span
            className="text-xs font-bold uppercase tracking-wider flex items-center gap-1.5 mb-2"
            style={{ color: '#15803D' }}
          >
            <Eye className="w-3.5 h-3.5" />
            Recent captures
          </span>
          <div className="space-y-0.5">
            {activity.map((a) => <ActivityRow key={a.id} item={a} />)}
          </div>
        </div>
      )}

      {/* ── Auto-rebuild safety notices ─────────────────────────────
          These are softer than `degraded` (the engine isn't failing) but
          we still want the user to know *why* their queue isn't draining
          on its own. Two cases:
            1. Big-batch confirmation gate — pending > auto_cap. We refuse
               to auto-start a large bulk silently; user clicks Build
               pending to opt in.
            2. Live throttle — the tick loop hit the per-minute ceiling
               or detected slow TTFB and is widening gaps. Build is still
               running, just deliberately paced. */}
      {autoCapExceeded && !running && (
        <div
          className="rounded-xl p-3 mb-3"
          style={{ background: '#EFF6FF', border: '1px solid rgba(2,82,250,0.20)' }}
        >
          <div className="flex gap-2 items-start">
            <AlertCircle
              className="w-3.5 h-3.5 flex-shrink-0 mt-0.5"
              style={{ color: 'var(--np-brand-primary)' }}
            />
            <div className="min-w-0 flex-1">
              <p
                className="text-[11px] font-bold leading-tight"
                style={{ color: 'var(--np-text-primary)' }}
              >
                {formatNumber(pending)} pages pending — review before rebuilding
              </p>
              <p
                className="text-[10px] mt-0.5 leading-snug"
                style={{ color: 'var(--np-text-muted)' }}
              >
                Auto-rebuild only fires for small batches (≤ {autoCap}) so we
                never silently load a shared host. Click <b>Build pending</b>
                below to start the run yourself, or it will process gradually
                as you publish more posts.
              </p>
            </div>
          </div>
        </div>
      )}

      {throttled && running && (
        <div
          className="rounded-xl p-3 mb-3"
          style={{ background: '#FFFBEB', border: '1px solid #FDE68A' }}
        >
          <div className="flex gap-2 items-start">
            <AlertCircle
              className="w-3.5 h-3.5 flex-shrink-0 mt-0.5"
              style={{ color: '#92400E' }}
            />
            <div className="min-w-0 flex-1">
              <p
                className="text-[11px] font-bold leading-tight"
                style={{ color: '#78350F' }}
              >
                {throttled === 'rate_limit'
                  ? 'Pacing build — safe rate limit reached'
                  : 'Pacing build — server response slowing'}
              </p>
              <p
                className="text-[10px] mt-0.5 leading-snug"
                style={{ color: '#92400E' }}
              >
                {throttled === 'rate_limit'
                  ? 'Capping captures to keep host load predictable. The queue will continue draining.'
                  : 'Captures are taking longer than usual. We are widening gaps so we never overload your host.'}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* ── Engine health: degraded notice ──────────────────────────
          When the engine detects a streak of transient HTTP failures
          (shared-host worker starvation, upstream throttling, network
          flap), it auto-pauses the bulk build and shows this banner.
          The "Resume" action lets the user retry once the host calms
          down — we deliberately don't auto-resume so we never silently
          add to a struggling server's load. */}
      {degraded && (
        <div
          className="rounded-xl p-3 mb-3"
          style={{
            background: isFpmExhausted ? '#EFF6FF' : '#FEF3C7',
            border: `1px solid ${isFpmExhausted ? 'rgba(2,82,250,0.25)' : '#FCD34D'}`,
          }}
        >
          <div className="flex gap-2 items-start mb-2">
            <AlertCircle
              className="w-3.5 h-3.5 flex-shrink-0 mt-0.5"
              style={{ color: isFpmExhausted ? 'var(--np-brand-primary)' : '#92400E' }}
            />
            <div className="min-w-0 flex-1">
              <p
                className="text-[11px] font-bold leading-tight"
                style={{ color: isFpmExhausted ? 'var(--np-text-primary)' : '#78350F' }}
              >
                {isFpmExhausted
                  ? 'Worker pool busy — build paused (LocalWP)'
                  : 'Server appears slow — build paused'}
              </p>
              <p
                className="text-[10px] mt-0.5 leading-snug"
                style={{ color: isFpmExhausted ? 'var(--np-text-muted)' : '#92400E' }}
              >
                {isFpmExhausted
                  ? 'Captures timed out (cURL error 28) — your PHP-FPM pool has 2 workers and both are busy. This is a LocalWP development environment limit, not a broken page. The build paused to avoid hammering the pool. Click Resume — captures will succeed when a worker is free.'
                  : `We saw ${failedCount > 0 ? `${failedCount} recent error${failedCount === 1 ? '' : 's'} — ` : ''}captures kept hitting transient HTTP failures. Auto-build is paused so we don't add to your host's load. Resume when it recovers.`}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={run('Resume', 'ssg/resume')}
              className="text-[10px] font-bold uppercase tracking-wider rounded-lg px-2.5 py-1"
              style={{
                background: '#FFFFFF',
                color: '#78350F',
                border: '1px solid #FCD34D',
              }}
            >
              <Play className="w-3 h-3 inline-block mr-1 -mt-0.5" />
              Resume
            </button>
            {recentErrors.length > 0 && (
              <button
                type="button"
                onClick={() => setShowErrors((s) => !s)}
                className="text-[10px] font-semibold underline"
                style={{ color: '#92400E' }}
              >
                {showErrors ? 'Hide errors' : `View ${recentErrors.length} error${recentErrors.length === 1 ? '' : 's'}`}
              </button>
            )}
          </div>

          {showErrors && recentErrors.length > 0 && (
            <div
              className="mt-2 rounded-lg overflow-hidden"
              style={{ background: '#FFFFFF', border: '1px solid #FDE68A' }}
            >
              {recentErrors.map((e, i) => (
                <div
                  key={`${e.post_id}-${i}`}
                  className="px-2 py-1.5 border-b last:border-0"
                  style={{ borderColor: '#FEF3C7' }}
                >
                  <p
                    className="text-[10px] font-semibold leading-tight truncate"
                    style={{ color: 'var(--np-text-primary)' }}
                    title={e.title}
                  >
                    {e.title || `Post #${e.post_id}`}
                  </p>
                  <p
                    className="text-[10px] mt-0.5 leading-snug truncate"
                    style={{ color: '#92400E' }}
                    title={e.message}
                  >
                    <span className="font-mono">{e.code}</span>
                    {e.message ? ` · ${e.message}` : ''}
                  </p>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Archives missing warning */}
      {state?.archives_missing && (
        <div
          className="rounded-xl p-3 mb-3 flex gap-2 items-start"
          style={{ background: '#FFFBEB', border: '1px solid #FDE68A' }}
        >
          <FileWarning className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" style={{ color: '#92400E' }} />
          <div className="text-[11px] leading-snug" style={{ color: '#78350F' }}>
            Archive pages haven't been built yet. Run "Rebuild all" to seed them.
          </div>
        </div>
      )}

      {/* Primary actions */}
      <div className="space-y-2 mb-3">
        {!running && !paused && (
          <ActionButton
            icon={Zap}
            label={pending > 0 ? `Build pending (${formatNumber(pending)})` : 'Rebuild all'}
            // Green, not blue — this button sits inside the brand-blue rail and a
            // blue primary button visually merged with the panel behind it.
            tone="success"
            onClick={async () => {
              // "Build pending" is the focused happy-path action — small batch,
              // no confirm. "Rebuild all" can be hundreds of pages, so we ask
              // first so it's never a misclick on a shared host.
              if (pending > 0) {
                run('Build', 'ssg/regen-pending')();
                return;
              }
              const ok = await useStore.getState().askConfirm({
                title: 'Rebuild every static page?',
                message: 'Captures every published post, page, archive, and taxonomy from scratch. On large sites this may take several minutes — visitors keep getting the existing mirror until each page is replaced.',
                details: [
                  'Existing static files are kept until the new version is ready.',
                  'Visitors are never served a broken or empty page.',
                  'Rate-limited to keep your host healthy.',
                ],
                confirmLabel: 'Rebuild all',
                tone: 'primary',
                icon: 'zap',
              });
              if (ok) run('Build', 'ssg/regen-all')();
            }}
            disabled={!enabled}
          />
        )}
        {running && (
          <ActionButton
            icon={Pause}
            label="Pause"
            onClick={run('Pause', 'ssg/pause')}
          />
        )}
        {paused && (
          <ActionButton
            icon={Play}
            label="Resume"
            tone="primary"
            onClick={run('Resume', 'ssg/resume')}
          />
        )}
        {(running || paused) && (
          <ActionButton
            icon={Square}
            label="Stop"
            tone="danger"
            onClick={async () => {
              const ok = await useStore.getState().askConfirm({
                title: 'Stop the build?',
                message: 'In-progress captures finish and any remaining pages return to the pending queue. Already-captured pages are kept.',
                confirmLabel: 'Stop build',
                tone: 'danger',
                icon: 'power',
              });
              if (ok) run('Stop', 'ssg/stop')();
            }}
          />
        )}
      </div>

      {/* Maintenance */}
      <div>
        <p
          className="text-[10px] uppercase font-bold tracking-wider mb-1.5 px-1"
          style={{ color: 'var(--np-text-muted)' }}
        >
          Maintenance
        </p>
        <div className="space-y-2">
          {/* "Run queue now" used to sit here. It fired due nexeng_* WP-Cron
              events, which is only meaningful when auto-rebuild schedules them
              — and that module is stripped from this build. With no build event
              ever in cron it dispatched an empty list and reported success,
              looking broken next to "Build pending", which does the real work.
              Removed rather than hidden: one build button is the whole story
              here. */}
          {/* Retry errors — only shown when there are actually blocked pages.
              Smart: the label tells the user exactly how many will be retried. */}
          {failedCount > 0 && (
            <ActionButton
              icon={RefreshCw}
              label={`Retry ${failedCount} blocked page${failedCount === 1 ? '' : 's'}`}
              onClick={run('Retry', 'ssg/retry-errors')}
              disabled={!enabled}
            />
          )}
          <ActionButton
            icon={Trash2}
            label="Purge mirror"
            tone="danger"
            onClick={async () => {
              const ok = await useStore.getState().askConfirm({
                title: 'Purge static mirror?',
                message: 'Every captured HTML file on disk will be deleted. Your WordPress posts, pages, and uploads are NOT touched — only the static copies.',
                details: [
                  'Visitors are served from PHP fallback until the next build.',
                  'Next "Rebuild all" recreates every page from scratch.',
                  'Pending queue is cleared too.',
                ],
                confirmLabel: 'Purge mirror',
                tone: 'danger',
                icon: 'trash',
              });
              if (ok) run('Purge', 'ssg/purge')();
            }}
          />
        </div>

        {/* Why is this stalled? — show the real, human-readable reason inline
            instead of only dumping JSON to the console. Only visible while posts
            are pending so it doesn't clutter the idle state. */}
        {pending > 0 && (
          <details className="mt-3">
            <summary
              className="text-[10px] uppercase font-bold tracking-wider cursor-pointer"
              style={{ color: 'var(--np-text-muted)' }}
            >
              Why is this stalled?
            </summary>
            <div className="mt-2 space-y-2">
              {recentErrors.length === 0 ? (
                <p className="text-[11px] leading-snug" style={{ color: 'var(--np-text-muted)' }}>
                  {hasAutoRebuild
                    ? 'Nothing is blocking these pages — they are queued and will rebuild automatically on the next cron tick, or immediately when you click “Build pending” above.'
                    : 'Nothing is blocking these pages. The mirror rebuilds on demand — click “Build pending” above and these pages will be captured.'}
                </p>
              ) : (
                <>
                  <p className="text-[11px] leading-snug" style={{ color: 'var(--np-text-muted)' }}>
                    These pages hit a temporary error during the last capture. It’s
                    usually a slow response or a busy server, not a broken page —
                    retrying almost always succeeds.
                  </p>
                  <ul className="space-y-1">
                    {recentErrors.slice(0, 5).map((er, i) => {
                      const isTimeout =
                        /timed out|curl error 28|http_5xx|429|408/i.test(
                          `${er.code ?? ''} ${er.message ?? ''}`,
                        );
                      return (
                        <li
                          key={`${er.post_id ?? i}-${i}`}
                          className="text-[10px] leading-snug rounded-md px-2 py-1"
                          style={{ background: 'var(--np-bg-subtle)', color: 'var(--np-text-muted)' }}
                        >
                          <span className="font-semibold" style={{ color: 'var(--np-text-primary)' }}>
                            {er.title || er.url || `Post ${er.post_id ?? ''}`}
                          </span>
                          {' — '}
                          {isTimeout
                            ? 'the page took too long to respond (timeout). Common on local/low-worker hosts; retry when the server is free.'
                            : (er.message || 'capture error').slice(0, 120)}
                        </li>
                      );
                    })}
                  </ul>
                  {failedCount > 0 && (
                    <p className="text-[10px]" style={{ color: 'var(--np-text-muted)' }}>
                      Click “Retry {failedCount} blocked page{failedCount === 1 ? '' : 's'}” above to try again.
                    </p>
                  )}
                </>
              )}
              <button
                type="button"
                onClick={async () => {
                  try {
                    const r = await api.get<any>('tools/ssg-debug');
                    // eslint-disable-next-line no-console
                    console.log('[Nexora] SSG debug', r);
                    pushToast('info', 'Full debug logged to console — copy & paste for support.');
                  } catch (e: any) {
                    pushToast('error', e?.message ?? 'Debug fetch failed');
                  }
                }}
                className="text-[10px] underline"
                style={{ color: 'var(--np-brand-primary)' }}
              >
                Copy technical details for support
              </button>
            </div>
          </details>
        )}
      </div>
    </div>
  );
}
