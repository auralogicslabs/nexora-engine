import { useQuery } from '@tanstack/react-query';
import {
  LayoutDashboard, Files,
  ArrowLeftRight, ShieldCheck, Search,
  ChevronRight, Activity, Gauge, BarChart3, RefreshCw,
  AlertTriangle, Sparkles, Zap, Server,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import StealthScore from '../components/StealthScore';
import BuildProgressBanner from '../components/BuildProgressBanner';
import { api, can, DashboardSummary, DashboardStats } from '../lib/api';
import { formatBytes, formatNumber, formatRelative } from '../lib/format';

/**
 * Card shell — single source of truth for every dashboard tile. Keeps spacing,
 * borders, and the small label/value/footer triplet identical across
 * Cache Hit Ratio, TTFB, CWV, Mirror Freshness, Security, etc. This mirrors
 * the previous PHP dashboard's `.ncx-metric-card` so existing customers see
 * the same information density they're used to.
 */
function MetricCard({
  icon: Icon,
  title,
  children,
  tone = 'default',
}: {
  icon: React.FC<any>;
  title: string;
  children: React.ReactNode;
  tone?: 'default' | 'warning' | 'pro';
}) {
  const palette = {
    default: { bg: 'var(--np-bg-subtle)', border: 'var(--np-border)',          fg: 'var(--np-brand-primary)' },
    warning: { bg: '#FFFBEB',              border: 'rgba(243,154,9,0.25)',     fg: '#92400E' },
    pro:     { bg: 'rgba(2,82,250,0.06)',  border: 'rgba(2,82,250,0.18)',      fg: 'var(--np-brand-primary)' },
  }[tone];
  return (
    <div className="np-card p-4 flex flex-col">
      <div className="flex items-center gap-2.5 mb-3">
        <div
          className="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{ background: palette.bg, border: `1px solid ${palette.border}` }}
        >
          <Icon className="w-4 h-4" style={{ color: palette.fg }} strokeWidth={2.2} />
        </div>
        <h3
          className="text-[11px] uppercase font-bold tracking-wider"
          style={{ color: 'var(--np-text-muted)', letterSpacing: '0.06em' }}
        >
          {title}
        </h3>
      </div>
      {children}
    </div>
  );
}

function BigValue({ value, hint, tone = 'default' }: {
  value: React.ReactNode;
  hint?: React.ReactNode;
  tone?: 'default' | 'good' | 'warn' | 'muted';
}) {
  const color =
    tone === 'good'  ? 'var(--np-success-text)'
    : tone === 'warn'  ? 'var(--np-warning-text)'
    : tone === 'muted' ? 'var(--np-text-muted)'
    : 'var(--np-text-primary)';
  return (
    <>
      <p
        className="text-[26px] font-bold tabular-nums leading-none"
        style={{ color, letterSpacing: '-0.02em' }}
      >
        {value}
      </p>
      {hint && (
        <p className="text-[11px] mt-1.5 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
          {hint}
        </p>
      )}
    </>
  );
}

/**
 * Placeholder shown while /dashboard/stats is still in flight.
 *
 * Without this the cards fall back to 0 and render their empty state, so a
 * loading dashboard actively asserted "No traffic recorded yet" — a claim about
 * the data rather than an admission that it had not arrived. A shimmer says
 * "fetching" and cannot be misread as a measurement.
 */
function ValueSkeleton({ lines = 1 }: { lines?: number }) {
  return (
    <div className="animate-pulse" aria-busy="true" aria-live="polite">
      <span className="sr-only">Loading metrics…</span>
      <div className="rounded h-[26px] w-24" style={{ background: 'var(--np-bg-subtle)' }} />
      {Array.from({ length: lines }).map((_, i) => (
        <div
          key={i}
          className="rounded h-2.5 mt-2"
          style={{ background: 'var(--np-bg-subtle)', width: i === 0 ? '80%' : '55%' }}
        />
      ))}
    </div>
  );
}

function ShortcutCard({
  icon: Icon, label, desc, to, pro, tone = 'info',
}: {
  icon: React.FC<any>;
  label: string;
  desc: string;
  to: string;
  pro?: boolean;
  tone?: 'info' | 'success' | 'warning' | 'violet';
}) {
  const palette = {
    info:    { bg: '#EFF6FF', border: 'rgba(2,82,250,0.15)',  fg: 'var(--np-brand-primary)' },
    success: { bg: '#ECFDF5', border: 'rgba(22,163,74,0.20)', fg: 'var(--np-success)' },
    warning: { bg: '#FFFBEB', border: 'rgba(243,154,9,0.25)', fg: 'var(--np-warning)' },
    violet:  { bg: 'var(--np-violet-bg)', border: 'rgba(127,119,221,0.25)', fg: 'var(--np-violet)' },
  }[tone];
  return (
    <a
      href={`#${to}`}
      className="np-card p-4 flex gap-3 items-start hover:shadow-md transition-shadow group"
    >
      <div
        className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
        style={{ background: palette.bg, border: `1px solid ${palette.border}` }}
      >
        <Icon className="w-4 h-4" style={{ color: palette.fg }} strokeWidth={2.2} />
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-1.5">
          <p className="text-sm font-semibold leading-tight" style={{ color: 'var(--np-text-primary)' }}>{label}</p>
          {pro && <span className="np-badge-pro text-[9px] px-1.5 py-px">PRO</span>}
        </div>
        <p className="text-xs mt-0.5 leading-snug" style={{ color: 'var(--np-text-muted)' }}>{desc}</p>
      </div>
      <ChevronRight className="w-3.5 h-3.5 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity" style={{ color: 'var(--np-text-muted)' }} />
    </a>
  );
}

/**
 * CWV row — LCP / INP / CLS rating chip. Thresholds match Google Search Console
 * Web Vitals report exactly so users see the same Good/Needs Improvement/Poor
 * verdict on both surfaces.
 */
function VitalRow({ label, value, samples, threshold }: {
  label: 'LCP' | 'INP' | 'CLS';
  value: number;
  samples: number;
  threshold: { good: number; meh: number };
}) {
  const has = samples > 0 && value > 0;
  const rating =
    !has ? 'empty'
    : value < threshold.good ? 'good'
    : value < threshold.meh ? 'meh'
    : 'poor';
  const color = {
    good:  '#15803D',
    meh:   '#92400E',
    poor:  '#B91C1C',
    empty: 'var(--np-text-muted)',
  }[rating];
  const display = !has
    ? '—'
    : label === 'CLS'
      ? value.toFixed(2)
      : `${formatNumber(Math.round(value))}ms`;
  return (
    <div className="flex items-center justify-between py-1.5 text-[12px]">
      <span className="font-semibold" style={{ color: 'var(--np-text-muted)', letterSpacing: '0.04em' }}>{label}</span>
      <span className="font-bold tabular-nums" style={{ color }}>{display}</span>
    </div>
  );
}

export default function Dashboard() {
  const { data: summary, isLoading: sumLoading } = useQuery({
    queryKey: ['summary'],
    queryFn: () => api.get<DashboardSummary>('summary'),
    refetchInterval: (q) => (q.state.data?.ssg?.running ? 2000 : 12000),
  });

  const buildRunningHint = !!summary?.ssg?.running;
  const { data: stats, isPending: statsPending } = useQuery({
    queryKey: ['dashboard-stats', buildRunningHint],
    // Bypass the 15s server-side cache during a build so the numbers track
    // capture progress in near-real-time. Idle polls stay cached.
    queryFn: () => api.get<DashboardStats>(buildRunningHint ? 'dashboard/stats?fresh=1' : 'dashboard/stats'),
    refetchInterval: () => (buildRunningHint ? 3000 : 30000),
    staleTime: 5000,
  });

  if (sumLoading || !summary) return <Spinner label="Loading dashboard…" />;

  const ssg   = summary.ssg;
  const isPro = !!summary.is_pro;
  const upgradeUrl = window.NexoraEngine?.upgradeUrl ?? '#';

  // Fall back to summary.ssg shape when /dashboard/stats hasn't loaded yet so
  // the cards render numbers immediately instead of "—" → animated-in values.
  const hitRatio       = stats?.hit_ratio ?? 0;
  const traffic24h     = stats?.traffic_total_24h ?? 0;
  const lastHitAt      = stats?.last_hit_at ?? null;
  const ttfbP50        = stats?.ttfb_p50 ?? 0;
  const ttfbP95        = stats?.ttfb_p95 ?? 0;
  const ttfbSamples    = stats?.ttfb_samples ?? 0;
  const vitals         = stats?.vitals ?? { LCP: 0, INP: 0, CLS: 0 };
  const vitalsSamples  = stats?.vitals_samples ?? { LCP: 0, INP: 0, CLS: 0 };
  const vitalsMethod   = (stats?.vitals_method ?? 'P75').toUpperCase();
  const staticFiles    = stats?.static_files_count ?? ssg.static_files ?? 0;
  const pendingCount   = stats?.pending_count ?? ssg.pending_count ?? 0;
  const buildRunning   = stats?.build_running ?? ssg.running;
  const buildProcessed = stats?.build_processed ?? ssg.processed ?? 0;
  const buildTotal     = stats?.build_total ?? ssg.total ?? 0;
  const hardeningActive = stats?.hardening_active ?? 0;
  const hardeningTotal  = stats?.hardening_total ?? 0;
  const securityScore  = stats?.security_score ?? 0;
  const stuckWarning   = stats?.stuck_warning ?? '';
  const totalVitals    = vitalsSamples.LCP + vitalsSamples.INP + vitalsSamples.CLS;

  return (
    <div>
      <PageHeader
        title="Dashboard"
        subtitle={`Plan: ${(summary.plan ?? 'free').toUpperCase()}${summary.install_id ? ` · Install ${summary.install_id.slice(0, 8)}` : ''}`}
        icon={LayoutDashboard}
      />

      <div className="p-6 space-y-6">

        {/* Prominent live build progress — same banner as Static Delivery, so a
            build kicked off from anywhere is clearly visible here too. Renders
            nothing when no build is running. */}
        <BuildProgressBanner />

        {/* ── Performance grid (replaces previous PHP .ncx-dashboard-grid) ── */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-wide mb-3" style={{ color: 'var(--np-text-muted)' }}>
            Performance overview
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">

            {/* Cache Hit Ratio */}
            <MetricCard icon={Activity} title="Cache Hit Ratio">
              {statsPending ? (
                <ValueSkeleton />
              ) : traffic24h > 0 ? (
                <>
                  <BigValue
                    value={`${hitRatio}%`}
                    hint={`${formatNumber(traffic24h)} requests · last 24h`}
                    tone={hitRatio >= 80 ? 'good' : hitRatio >= 50 ? 'warn' : 'default'}
                  />
                  <div className="np-progress mt-3" style={{ height: 5 }}>
                    <div
                      className="np-progress-bar"
                      style={{
                        width: `${hitRatio}%`,
                        background: hitRatio >= 80 ? 'var(--np-success)' : hitRatio >= 50 ? 'var(--np-warning)' : 'var(--np-brand-primary)',
                      }}
                    />
                  </div>
                </>
              ) : lastHitAt ? (
                <BigValue value="—" tone="muted" hint={<>No anonymous traffic today<br/>Last data: {formatRelative(new Date(lastHitAt * 1000).toISOString())}</>} />
              ) : (
                <BigValue value="—" tone="muted" hint="No traffic recorded yet. Logged-in browsing is excluded from metrics." />
              )}
            </MetricCard>

            {/* TTFB Performance */}
            <MetricCard icon={Gauge} title="TTFB Performance">
              {statsPending ? (
                <ValueSkeleton lines={2} />
              ) : ttfbSamples > 0 && ttfbP50 >= 1 ? (
                <>
                  <BigValue
                    value={ttfbP50 <= 1 ? '<1ms' : `${ttfbP50}ms`}
                    tone={ttfbP50 < 200 ? 'good' : ttfbP50 < 600 ? 'warn' : 'default'}
                  />
                  <p className="text-[11px] mt-1.5" style={{ color: 'var(--np-text-muted)' }}>
                    P50: {ttfbP50 <= 1 ? '<1' : ttfbP50}ms · P95: {ttfbP95 <= 1 ? '<1' : ttfbP95}ms
                  </p>
                  {staticFiles === 0 ? (
                    <p className="text-[10px] mt-1 leading-snug" style={{ color: 'var(--np-warning-text)' }}>
                      From prior build · rebuild mirror to refresh
                    </p>
                  ) : (
                    <p className="text-[10px] mt-1" style={{ color: 'var(--np-text-muted)' }}>
                      {formatNumber(ttfbSamples)} cache-hit samples · 24h
                    </p>
                  )}
                </>
              ) : (
                <BigValue value="—" tone="muted" hint="Open the site as an anonymous visitor after building static pages." />
              )}
            </MetricCard>

            {/* Real-User Perf (CWV) */}
            <MetricCard icon={BarChart3} title="Real-User Perf (CWV)">
              {statsPending ? (
                <ValueSkeleton lines={3} />
              ) : (
                <>
                  <div className="space-y-0 mt-1">
                    <VitalRow label="LCP" value={vitals.LCP} samples={vitalsSamples.LCP} threshold={{ good: 2500, meh: 4000 }} />
                    <VitalRow label="INP" value={vitals.INP} samples={vitalsSamples.INP} threshold={{ good: 200,  meh: 500  }} />
                    <VitalRow label="CLS" value={vitals.CLS} samples={vitalsSamples.CLS} threshold={{ good: 0.1,  meh: 0.25 }} />
                  </div>
                  <p className="text-[10px] mt-2" style={{ color: 'var(--np-text-muted)' }}>
                    {totalVitals > 0 ? `${vitalsMethod} field samples · 7d` : 'Collecting real-user samples'}
                  </p>
                </>
              )}
            </MetricCard>

            {/* Static Files */}
            <MetricCard icon={Files} title="Static Files">
              <BigValue
                value={formatNumber(staticFiles)}
                hint={`${formatBytes(stats?.static_total_bytes ?? ssg.static_bytes ?? 0)} total · Last regen ${stats?.last_regen ?? 'Never'}`}
              />
            </MetricCard>

            {/* Mirror Freshness */}
            <MetricCard
              icon={RefreshCw}
              title="Mirror Freshness"
              tone={buildRunning ? 'default' : pendingCount > 0 ? 'warning' : 'default'}
            >
              {buildRunning ? (
                <>
                  <BigValue value="Building" tone="default" />
                  <p className="text-[11px] mt-1.5" style={{ color: 'var(--np-text-muted)' }}>
                    {buildProcessed} / {buildTotal > 0 ? buildTotal : '—'} items processed
                  </p>
                </>
              ) : pendingCount > 0 ? (
                <>
                  <BigValue value={formatNumber(pendingCount)} tone="warn" />
                  <p className="text-[11px] mt-1.5" style={{ color: 'var(--np-text-muted)' }}>
                    Changed pages waiting to be refreshed.
                  </p>
                </>
              ) : (
                <>
                  <BigValue value="Current" tone="good" />
                  <p className="text-[11px] mt-1.5" style={{ color: 'var(--np-text-muted)' }}>
                    Public mirror is aligned with tracked edits.
                  </p>
                </>
              )}
              {stuckWarning && (
                <div
                  className="mt-3 flex items-start gap-2 p-2 rounded-lg"
                  style={{ background: '#FFFBEB', border: '1px solid #FDE68A' }}
                >
                  <AlertTriangle className="w-3 h-3 flex-shrink-0 mt-0.5" style={{ color: '#92400E' }} />
                  <p className="text-[10px] leading-snug" style={{ color: '#78350F' }}>{stuckWarning}</p>
                </div>
              )}
            </MetricCard>

            {/* Security Hardening */}
            <MetricCard icon={ShieldCheck} title="Security Hardening">
              <BigValue
                value={`${securityScore}%`}
                hint={`${hardeningActive} of ${hardeningTotal} rules active`}
                tone={securityScore >= 75 ? 'good' : securityScore >= 40 ? 'warn' : 'muted'}
              />
              <a
                href="#/security"
                className="text-[11px] font-bold mt-2 inline-flex items-center gap-0.5"
                style={{ color: 'var(--np-brand-primary)' }}
              >
                Hardening Panel <ChevronRight className="w-3 h-3" />
              </a>
            </MetricCard>

          </div>
        </section>

        {/* ── Stealth Score — the measurable face of Ghost Protocol ──
            This is the plugin's signature differentiator: proof that the
            site no longer looks like WordPress to scanners. Surfaced right
            under the performance grid so it's the first thing users show off. */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-wide mb-3" style={{ color: 'var(--np-text-muted)' }}>
            WordPress stealth
          </h2>
          <StealthScore compact />
        </section>

        {/* ── Wide CTA: "Control how your site is served" ──
            Pro: invites user to deeper Pages & Posts report.
            Free: blurred preview with upgrade CTA — same pattern as the
            previous PHP dashboard. */}
        {isPro ? (
          <a
            href="#/headless"
            className="np-card flex items-center gap-4 p-5 hover:shadow-md transition-shadow group"
            style={{
              background: 'linear-gradient(135deg, var(--np-brand-primary) 0%, var(--np-brand-primary-hover) 100%)',
              borderColor: 'transparent',
              boxShadow: '0 6px 20px rgb(2 82 250 / 0.18)',
            }}
          >
            <div
              className="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0"
              style={{ background: 'rgba(255,255,255,0.18)' }}
            >
              <Server className="w-6 h-6 text-white" strokeWidth={2.2} />
            </div>
            <div className="min-w-0 flex-1">
              <p
                className="text-[10px] uppercase font-bold tracking-wider"
                style={{ color: 'rgba(255,255,255,0.82)', letterSpacing: '0.08em' }}
              >
                Static Delivery
              </p>
              <p className="text-base font-bold text-white mt-0.5 leading-tight">
                Control how your site is served to visitors
              </p>
              <p className="text-[12px] mt-1 leading-snug" style={{ color: 'rgba(255,255,255,0.78)' }}>
                Auto-rebuild on publish, edge caching, Stealth Proxy, and real-time mirror status —
                all in one command center.
              </p>
            </div>
            <ChevronRight className="w-5 h-5 text-white flex-shrink-0 group-hover:translate-x-1 transition-transform" />
          </a>
        ) : (
          <div
            className="np-card p-5 relative overflow-hidden"
            style={{ background: 'var(--np-bg-card)' }}
          >
            <div className="flex items-center gap-4">
              <div
                className="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0"
                style={{
                  background: 'linear-gradient(135deg, var(--np-brand-primary) 0%, var(--np-brand-primary-hover) 100%)',
                }}
              >
                <Sparkles className="w-6 h-6 text-white" strokeWidth={2.2} />
              </div>
              <div className="min-w-0 flex-1">
                <p
                  className="text-[10px] uppercase font-bold tracking-wider"
                  style={{ color: 'var(--np-brand-primary)', letterSpacing: '0.08em' }}
                >
                  Pro Tier
                </p>
                <p className="text-base font-bold mt-0.5 leading-tight" style={{ color: 'var(--np-text-primary)' }}>
                  Infrastructure features available on Pro
                </p>
                <p className="text-[12px] mt-1 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
                  Auto-rebuild on publish, Stealth Proxy, SEO Intelligence, CWV tracking,
                  smart redirect management, and per-page traffic insights.
                </p>
              </div>
              <a
                href={upgradeUrl}
                target="_blank"
                rel="noreferrer"
                className="np-btn-primary text-xs flex-shrink-0"
              >
                <Zap className="w-3.5 h-3.5" />
                Upgrade to Pro
              </a>
            </div>
          </div>
        )}

        {/* ── Quick links to other modules ───────────────────────────── */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-wide mb-3" style={{ color: 'var(--np-text-muted)' }}>
            Tools
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <ShortcutCard
              icon={Search}
              label="SEO Report"
              desc="Sitemaps, schema, indexability — everything search engines see."
              to="/seo-report"
              tone="info"
            />
            <ShortcutCard
              icon={ShieldCheck}
              label="Security"
              desc="Hardening toggles, login renaming, REST/XML-RPC lockdown."
              to="/security"
              tone="success"
            />
            {/* Only when the module is installed — otherwise the card links to
                a screen that has nothing on it. */}
            {can('redirects') && (
              <ShortcutCard
                icon={ArrowLeftRight}
                label="Redirect Manager"
                desc="301/302 rules with regex and bulk import."
                to="/redirects"
                tone="warning"
              />
            )}
            {/* Portal shortcut hidden for now — feature deferred. */}
          </div>
        </section>

      </div>
    </div>
  );
}
