import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Zap, AlertTriangle, Layers, CheckCircle2, Loader2, Sparkles,
  ChevronRight, ServerCog, ExternalLink, RotateCw, ArrowRight,
  Lock, Power, RefreshCw, Globe, FileText, Shield, Search,
  Gauge, Plus, Hexagon,
} from 'lucide-react';
import { api, SsgState } from '../lib/api';
import { useStore } from '../lib/store';
import Spinner from '../components/ui/Spinner';
import { formatNumber } from '../lib/format';

// ───────────────────────────── Types ─────────────────────────────────

type Preflight = {
  status: 'pass' | 'fail';
  checks: Record<string, { label: string; pass: boolean; current: string; desc: string }>;
};

type ServerInfo = {
  server: string;
  is_nginx: boolean;
  htaccess_ok: boolean;
  config_ok: boolean;
  tier: number;
  tier_label: string;
  tier_desc: string;
  tier_ttfb: string;
};

type Conflict = {
  slug: string;
  name: string;
  category: string;
  severity?: string;
  reason: string;
  fix: string;
  auto_fix: boolean;
};

type WizardState = {
  available: boolean;
  completed: boolean;
  is_pro: boolean;
  is_network: boolean;
  upgrade_url: string;
  preflight: Preflight;
  server: ServerInfo;
  conflicts: Conflict[];
  has_blocking: boolean;
  engine: {
    ssg_on: boolean;
    ghost_on: boolean;
    auto_rebuild: boolean;
    static_files: number;
    archives_captured: number;
    archives_eligible: number;
  };
  dashboard_url: string;
  headless_url: string;
  settings_url: string;
};

type ActivateResult = {
  tier: number;
  tier_label: string;
  tier_ttfb: string;
  dropin_ok: boolean;
  serve_ok: boolean;
  is_nginx: boolean;
  is_apache: boolean;
  is_ls: boolean;
  total: number;
  message: string;
};

const STEPS = [
  { n: 1, label: 'System Check', sub: 'Verify compatibility' },
  { n: 2, label: 'Activating',   sub: 'Configure engine' },
  { n: 3, label: 'Conflicts',    sub: 'Review plugins' },
  { n: 4, label: 'Building',     sub: 'Generate static files' },
  { n: 5, label: 'Live!',        sub: 'Site is ready' },
] as const;

const TIER_HEX: Record<number, string> = {
  1: '#16A34A',  // Full Speed — brand green
  2: '#0252FA',  // Speed Active — brand blue
  3: '#F39A09',  // Pages Built — brand amber
};

// ───────────────────────────── Helpers ───────────────────────────────

// What's shown on the bottom-left "What you're activating" feature list.
// Copy ported from the new design direction — marketing-grade and concise.
const ACTIVATING_FEATURES = [
  {
    icon: Zap,
    label: 'Static Site Generation',
    desc: 'Every page pre-rendered as plain HTML. No PHP, no database per visitor request.',
  },
  {
    icon: Shield,
    label: 'Automatic Serve Rules',
    desc: 'Apache or drop-in cache delivers files at web-server speed — 10–50× faster TTFB.',
  },
  {
    icon: RefreshCw,
    label: 'Smart Invalidation',
    desc: 'Static files update automatically whenever you publish or update a post.',
  },
];

// ───────────────────────────── Stepper ───────────────────────────────

function Stepper({ current, completed }: { current: number; completed: boolean }) {
  return (
    <nav className="flex flex-col gap-1">
      {STEPS.map((s) => {
        const active = current === s.n;
        const done = completed || current > s.n;
        return (
          <div
            key={s.n}
            className="flex items-center gap-3 px-3 py-2.5 rounded-xl transition-colors"
            style={{
              background: active ? 'rgba(255,255,255,0.08)' : 'transparent',
            }}
          >
            <div
              className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 transition-colors"
              style={{
                background: done ? '#16A34A' : active ? 'var(--np-brand-primary)' : 'rgba(255,255,255,0.10)',
                color: done || active ? '#FFFFFF' : 'rgba(255,255,255,0.45)',
                boxShadow: active ? '0 0 0 4px rgba(2,82,250,0.18)' : 'none',
              }}
            >
              {done ? (
                <CheckCircle2 className="w-4 h-4" />
              ) : (
                <span className="text-xs font-bold">{s.n}</span>
              )}
            </div>
            <div className="min-w-0">
              <p
                className="text-[13px] font-bold leading-tight"
                style={{ color: done || active ? '#FFFFFF' : 'rgba(255,255,255,0.55)' }}
              >
                {s.label}
              </p>
              <p
                className="text-[11px] mt-0.5"
                style={{ color: 'rgba(209,223,255,0.55)' }}
              >
                {s.sub}
              </p>
            </div>
          </div>
        );
      })}
    </nav>
  );
}

// ──────────────────────────── Tier Strip ─────────────────────────────

// The "Speed Active · ~45ms" emphasis strip below the body. Reused on
// Step 1 (preview), Step 2 (preview + activate CTA), Step 5 (verdict).
function TierStrip({
  tier, tierLabel, tierTtfb, tierDesc,
  cta, ctaIcon, ctaBusy, onCta,
}: {
  tier: number;
  tierLabel: string;
  tierTtfb: string;
  tierDesc: string;
  cta?: string;
  ctaIcon?: React.FC<any>;
  ctaBusy?: boolean;
  onCta?: () => void;
}) {
  const TierIcon = tier === 1 ? Zap : tier === 2 ? Gauge : Layers;
  const hex = TIER_HEX[tier];
  const CtaIcon = ctaIcon ?? Sparkles;
  return (
    <div
      className="rounded-xl p-4 flex items-center gap-4 flex-wrap"
      style={{
        background: 'linear-gradient(135deg, #EBF0FF 0%, #DBEAFE 100%)',
        border: '1px solid rgba(2,82,250,0.18)',
      }}
    >
      <div
        className="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
        style={{ background: '#FFFFFF', border: `1px solid ${hex}33` }}
      >
        <TierIcon className="w-5 h-5" style={{ color: hex }} strokeWidth={2.4} />
      </div>
      <div className="min-w-0 flex-1">
        <span
          className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
          style={{ background: `${hex}1A`, color: hex }}
        >
          {tierLabel}
        </span>
        <p
          className="text-xs mt-1 leading-relaxed"
          style={{ color: 'var(--np-text-secondary)' }}
        >
          {tierDesc}
        </p>
      </div>
      <div className="text-right">
        <p
          className="text-2xl font-bold leading-none tabular-nums"
          style={{ color: 'var(--np-brand-primary)' }}
        >
          {tierTtfb}
        </p>
        <p
          className="text-[10px] uppercase font-bold tracking-wider mt-1"
          style={{ color: 'var(--np-text-muted)' }}
        >
          Est. TTFB
        </p>
      </div>
      {cta && (
        <button
          type="button"
          onClick={onCta}
          disabled={ctaBusy}
          className="np-btn-primary"
        >
          {ctaBusy ? <Loader2 className="w-4 h-4 animate-spin" /> : <CtaIcon className="w-4 h-4" />}
          {ctaBusy ? 'Activating…' : cta}
        </button>
      )}
    </div>
  );
}

// ─────────────────────── Signals (right column) ──────────────────────

/**
 * Setup Signals panel — the right rail that frames the operation as an
 * intelligent assistant rather than a static form. Content is dynamic per
 * step so the user always sees what's relevant *right now*.
 *
 *   Step 1 → environment snapshot (delivery mode, static files, conflicts)
 *   Step 2 → activation preview (same snapshot, slightly re-framed)
 *   Step 3 → conflict count + resolution hints
 *   Step 4 → live build progress
 *   Step 5 → tier verdict + what's next
 *
 * The bottom mini-stepper of three feature signposts (Preflight, Activation,
 * Mirror Build) stays the same across steps and advances as the user moves.
 */
function SignalsPanel({
  step, state, ssg, activate,
}: {
  step: number;
  state: WizardState;
  ssg: SsgState | undefined;
  activate: ActivateResult | null;
}) {
  const tier = activate?.tier ?? state.server.tier;
  const tierLabel = activate?.tier_label ?? state.server.tier_label;
  const conflictCount = state.conflicts?.length ?? 0;
  const filesCount = activate?.total ?? state.engine.static_files ?? 0;
  const pct = ssg?.percent ?? 0;
  const processed = ssg?.processed ?? 0;
  const total = ssg?.total ?? activate?.total ?? 0;

  // Pick three "signal cards" to show at the top of the panel based on step.
  type Signal = {
    icon: React.FC<any>;
    label: string;
    value: React.ReactNode;
    tone?: 'default' | 'ok' | 'warn' | 'err';
  };
  let signals: Signal[];
  let title: string;
  let body: string;

  switch (step) {
    case 4:
      title = 'Building static mirror';
      body  = 'Each page is captured and written to disk on your server. The build runs in the background — you can safely leave this page.';
      signals = [
        { icon: Gauge, label: 'Progress',  value: `${pct}%`,                   tone: ssg?.running ? 'default' : 'ok' },
        { icon: FileText, label: 'Captured', value: formatNumber(processed),    tone: 'ok' },
        { icon: Layers, label: 'Remaining', value: formatNumber(Math.max(0, total - processed)) },
      ];
      break;

    case 5:
      title = 'Engine live';
      body  = 'Static delivery is serving your site. Open the dashboard to see live mirror stats.';
      signals = [
        { icon: Gauge,    label: tierLabel,           value: activate?.tier_ttfb ?? state.server.tier_ttfb, tone: 'ok' },
        { icon: FileText, label: 'Pages captured',    value: formatNumber(filesCount),                     tone: 'ok' },
        { icon: ServerCog, label: 'Drop-in',          value: activate?.dropin_ok ? 'Installed' : 'Pending', tone: activate?.dropin_ok ? 'ok' : 'warn' },
      ];
      break;

    case 3:
      title = 'Plugin review';
      body  = conflictCount === 0
        ? 'No interfering plugins detected. Continue to start the build.'
        : 'Some plugins may affect static delivery. Resolve the blockers before building.';
      signals = [
        { icon: Globe,    label: 'Delivery mode',     value: state.server.tier_label,        tone: 'default' },
        { icon: Shield,   label: 'Conflicts',         value: formatNumber(conflictCount),    tone: conflictCount === 0 ? 'ok' : 'warn' },
        { icon: FileText, label: 'Static files built', value: formatNumber(state.engine.static_files) },
      ];
      break;

    case 2:
      title = 'Live environment status';
      body  = 'Keep these signals visible while the wizard validates the environment and builds the mirror.';
      signals = [
        { icon: Globe,    label: 'Detected delivery mode', value: state.server.tier_label,           tone: 'default' },
        { icon: FileText, label: 'Static files currently built', value: formatNumber(state.engine.static_files) },
        { icon: Shield,   label: conflictCount === 0 ? 'No plugin conflicts detected' : 'Plugin conflicts', value: formatNumber(conflictCount), tone: conflictCount === 0 ? 'ok' : 'warn' },
      ];
      break;

    case 1:
    default:
      title = 'Live environment status';
      body  = 'Keep these signals visible while the wizard validates the environment and builds the mirror.';
      signals = [
        { icon: Globe,    label: 'Detected delivery mode',         value: state.server.tier_label,             tone: 'default' },
        { icon: FileText, label: 'Static files currently built',   value: formatNumber(state.engine.static_files) },
        { icon: Shield,   label: conflictCount === 0 ? 'No plugin conflicts detected' : 'Plugin conflicts', value: formatNumber(conflictCount), tone: conflictCount === 0 ? 'ok' : 'warn' },
      ];
      break;
  }

  // Footer "feature signposts" — Preflight → Activation → Mirror Build.
  // These advance as the user moves through the wizard so the panel always
  // hints at what's next without crowding the active step.
  const footers = [
    { icon: Search,    label: 'Preflight',  desc: 'Check server, filesystem, cache rules, and conflicts.',  done: step >= 2 },
    { icon: Power,     label: 'Activation', desc: 'Enable the safest delivery mode available here.',         done: step >= 4 },
    { icon: RefreshCw, label: 'Mirror Build', desc: 'Build the first static mirror and verify delivery.',   done: step >= 5 },
  ];

  return (
    <aside
      className="flex flex-col gap-4 sticky top-6 self-start"
      style={{ width: 320 }}
    >
      {/* Hero signals card — brand blue background */}
      <div
        className="rounded-2xl p-5 relative overflow-hidden"
        style={{
          background: 'linear-gradient(135deg, var(--np-brand-primary) 0%, var(--np-brand-primary-hover) 100%)',
          boxShadow: '0 12px 32px -8px rgb(2 82 250 / 0.35)',
        }}
      >
        <div
          className="absolute -top-10 -right-10 w-32 h-32 rounded-full"
          style={{ background: 'rgba(86,162,250,0.35)', filter: 'blur(8px)' }}
        />
        <span
          className="relative inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"
          style={{ background: 'rgba(255,255,255,0.12)', color: '#FFFFFF' }}
        >
          Setup signals
        </span>
        <h3 className="relative text-lg font-bold mt-3 text-white tracking-tight leading-tight">
          {title}
        </h3>
        <p className="relative text-[12px] mt-2 leading-relaxed" style={{ color: 'rgba(255,255,255,0.85)' }}>
          {body}
        </p>

        <div className="relative space-y-2 mt-4">
          {signals.map((sig, i) => {
            const Icon = sig.icon;
            const toneFg =
              sig.tone === 'ok'   ? '#86EFAC'
              : sig.tone === 'warn' ? '#FCD34D'
              : sig.tone === 'err'  ? '#FCA5A5'
              : '#FFFFFF';
            return (
              <div
                key={i}
                className="flex items-center gap-3 rounded-xl px-3 py-2.5"
                style={{ background: 'rgba(255,255,255,0.10)' }}
              >
                <div
                  className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ background: 'rgba(255,255,255,0.12)' }}
                >
                  <Icon className="w-4 h-4 text-white" strokeWidth={2.2} />
                </div>
                <div className="min-w-0 flex-1">
                  <p
                    className="text-[18px] font-bold leading-none tabular-nums"
                    style={{ color: toneFg }}
                  >
                    {sig.value}
                  </p>
                  <p
                    className="text-[11px] mt-1 leading-tight"
                    style={{ color: 'rgba(255,255,255,0.78)' }}
                  >
                    {sig.label}
                  </p>
                </div>
              </div>
            );
          })}
        </div>

        {/* Verdict caption — short reassurance line below the signals */}
        <p
          className="relative text-[11px] font-bold mt-4 leading-relaxed"
          style={{ color: '#FFFFFF' }}
        >
          {conflictCount === 0
            ? 'No known plugin conflicts. Continue to activation and your first mirror build.'
            : 'Resolve the listed conflicts before activation — they may interfere with delivery.'}
        </p>
      </div>

      {/* Footer signposts — Preflight → Activation → Mirror Build */}
      <div className="space-y-2">
        {footers.map((f) => {
          const Icon = f.icon;
          return (
            <div
              key={f.label}
              className="np-card p-3 flex items-start gap-3"
              style={{
                borderColor: f.done ? 'rgba(22,163,74,0.30)' : 'var(--np-border)',
                background: f.done ? 'rgba(22,163,74,0.04)' : 'var(--np-bg-card)',
              }}
            >
              <div
                className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                style={{
                  background: f.done ? 'rgba(22,163,74,0.10)' : 'var(--np-bg-subtle)',
                }}
              >
                {f.done ? (
                  <CheckCircle2 className="w-4 h-4" style={{ color: '#16A34A' }} strokeWidth={2.4} />
                ) : (
                  <Icon className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
                )}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-bold leading-tight" style={{ color: 'var(--np-text-primary)' }}>
                  {f.label}
                </p>
                <p className="text-[11px] mt-0.5 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
                  {f.desc}
                </p>
              </div>
            </div>
          );
        })}
      </div>
    </aside>
  );
}

// ─────────────────────────── Step body shell ─────────────────────────

function StepBody({
  title, subtitle, children,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="np-card overflow-hidden flex flex-col">
      <div className="px-7 py-6 border-b flex items-start gap-4" style={{ borderColor: 'var(--np-border)' }}>
        <div
          className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{ background: 'rgba(2,82,250,0.08)' }}
        >
          <Hexagon className="w-5 h-5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-[22px] font-bold tracking-tight leading-tight" style={{ color: 'var(--np-text-primary)' }}>
            {title}
          </h2>
          {subtitle && (
            <p className="text-sm mt-1.5 leading-relaxed" style={{ color: 'var(--np-text-secondary)' }}>
              {subtitle}
            </p>
          )}
        </div>
      </div>
      <div className="px-7 py-6 flex-1">{children}</div>
    </div>
  );
}

// ─────────────────────────── Step 1 + 2 body ─────────────────────────

/**
 * Step 1 (System Check) and Step 2 (Activating) share the same two-column
 * layout from the screenshot:
 *
 *   LEFT  — "What you're activating" → the 3 feature tiles
 *   RIGHT — "System verification"    → the preflight check rows
 *   BELOW — Tier strip (estimated TTFB + Activate CTA on step 2)
 */
function StepIntro({
  state, step, activating, onNext, onActivate,
}: {
  state: WizardState;
  step: 1 | 2;
  activating?: boolean;
  onNext?: () => void;
  onActivate?: () => void;
}) {
  const checks = Object.values(state.preflight?.checks ?? {});
  const allPass = state.preflight?.status === 'pass';

  return (
    <StepBody
      title={step === 1
        ? 'Launch the static delivery pipeline.'
        : 'Activate the engine.'}
      subtitle={step === 1
        ? 'Nexora checks the environment, prepares the mirror path, and serves visitors from static files while WordPress stays available for editing.'
        : 'Turn on static delivery, install the cache drop-in, and start the first build. You can change any of these later.'}
    >
      <div className="grid grid-cols-1 md:grid-cols-2 gap-7">
        {/* Left column — What you're activating */}
        <div>
          <p className="np-section-label mb-3">What you're activating</p>
          <div className="space-y-3">
            {ACTIVATING_FEATURES.map((f) => {
              const Icon = f.icon;
              return (
                <div
                  key={f.label}
                  className="flex items-start gap-3 p-3 rounded-xl"
                  style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border-soft)' }}
                >
                  <div
                    className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                    style={{ background: '#FFFFFF', border: '1px solid var(--np-border)' }}
                  >
                    <Icon className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-bold leading-tight" style={{ color: 'var(--np-text-primary)' }}>
                      {f.label}
                    </p>
                    <p className="text-[12px] mt-1 leading-snug" style={{ color: 'var(--np-text-muted)' }}>
                      {f.desc}
                    </p>
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Right column — System verification */}
        <div>
          <p className="np-section-label mb-3">System verification</p>
          <div className="space-y-2">
            {checks.map((c) => (
              <div
                key={c.label}
                className="flex items-center gap-3 px-3 py-2.5 rounded-xl"
                style={{
                  background: c.pass ? 'var(--np-bg-card)' : 'rgba(226,75,74,0.05)',
                  border: `1px solid ${c.pass ? 'var(--np-border)' : 'rgba(226,75,74,0.20)'}`,
                }}
              >
                {c.pass ? (
                  <CheckCircle2 className="w-4 h-4 flex-shrink-0" style={{ color: '#16A34A' }} strokeWidth={2.4} />
                ) : (
                  <Plus
                    className="w-4 h-4 flex-shrink-0 rotate-45"
                    style={{ color: '#E24B4A' }}
                    strokeWidth={2.4}
                  />
                )}
                <span className="text-[13px] font-semibold flex-1" style={{ color: 'var(--np-text-primary)' }}>
                  {c.label}
                </span>
                <code
                  className="text-[11px] font-mono px-2 py-0.5 rounded font-semibold"
                  style={{ background: 'var(--np-bg-subtle)', color: 'var(--np-text-secondary)' }}
                >
                  {c.current}
                </code>
              </div>
            ))}

            {/* Web server row. Informational, not pass/fail — every server type
                is supported, we just report which one was detected. It must not
                use Plus: the failure icon above is that same glyph rotated 45deg,
                so at this size the two were near-indistinguishable and a detected
                server read as a failed check. */}
            <div
              className="flex items-center gap-3 px-3 py-2.5 rounded-xl"
              style={{ background: 'var(--np-bg-card)', border: '1px solid var(--np-border)' }}
            >
              <ServerCog
                className="w-4 h-4 flex-shrink-0"
                style={{ color: 'var(--np-text-muted)' }}
                strokeWidth={2.2}
              />
              <span className="text-[13px] font-semibold flex-1" style={{ color: 'var(--np-text-primary)' }}>
                Web Server
              </span>
              <code
                className="text-[11px] font-mono px-2 py-0.5 rounded font-semibold"
                style={{ background: 'var(--np-bg-subtle)', color: 'var(--np-text-secondary)' }}
              >
                {state.server.server}
              </code>
            </div>
          </div>
        </div>
      </div>

      {/* Tier strip (always present on Steps 1 + 2) */}
      <div className="mt-6">
        <TierStrip
          tier={state.server.tier}
          tierLabel={state.server.tier_label}
          tierTtfb={state.server.tier_ttfb}
          tierDesc={state.server.tier_desc}
          cta={step === 2 ? 'Activate Nexora Engine' : undefined}
          ctaIcon={step === 2 ? Hexagon : undefined}
          ctaBusy={activating}
          onCta={step === 2 ? onActivate : undefined}
        />
      </div>

      {/* Continue button on Step 1 (Step 2's CTA lives inside the tier strip) */}
      {step === 1 && (
        <div className="flex justify-end mt-6">
          <button
            type="button"
            onClick={onNext}
            disabled={!allPass}
            className="np-btn-primary"
            style={{ opacity: !allPass ? 0.5 : 1 }}
          >
            Continue
            <ChevronRight className="w-4 h-4" />
          </button>
        </div>
      )}
    </StepBody>
  );
}

// ───────────────────────────── Step 3 ────────────────────────────────

function StepConflicts({
  state, disabling, onResolve, onNext,
}: {
  state: WizardState;
  disabling: boolean;
  onResolve: (slug: string) => void;
  onNext: () => void;
}) {
  const conflicts = state.conflicts ?? [];

  return (
    <StepBody
      title="Review interfering plugins."
      subtitle={
        conflicts.length === 0
          ? 'Nothing detected. You can move on to the build.'
          : 'Other plugins may interfere with static delivery. Resolve the high-severity ones before continuing.'
      }
    >
      {conflicts.length === 0 ? (
        <div
          className="rounded-xl p-10 text-center"
          style={{
            background: 'linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%)',
            border: '1px solid rgba(22,163,74,0.30)',
          }}
        >
          <div
            className="w-12 h-12 mx-auto mb-4 rounded-xl flex items-center justify-center"
            style={{ background: '#FFFFFF', border: '1px solid rgba(22,163,74,0.30)' }}
          >
            <CheckCircle2 className="w-6 h-6" style={{ color: '#16A34A' }} strokeWidth={2.4} />
          </div>
          <p className="text-base font-bold" style={{ color: '#0F2A1F' }}>
            No conflicts detected
          </p>
          <p className="text-sm mt-1" style={{ color: '#166534' }}>
            Continue to start building the mirror.
          </p>
        </div>
      ) : (
        <div className="space-y-2.5">
          {conflicts.map((c) => {
            const blocking = c.slug === 'foreign-dropin';
            return (
              <div
                key={c.slug}
                className="rounded-xl p-4 flex items-start gap-3"
                style={{
                  background: blocking ? 'rgba(226,75,74,0.05)' : 'rgba(243,154,9,0.05)',
                  border: `1px solid ${blocking ? 'rgba(226,75,74,0.25)' : 'rgba(243,154,9,0.25)'}`,
                }}
              >
                <AlertTriangle
                  className="w-5 h-5 flex-shrink-0 mt-0.5"
                  style={{ color: blocking ? '#E24B4A' : '#F39A09' }}
                />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 mb-1 flex-wrap">
                    <p className="text-sm font-bold" style={{ color: 'var(--np-text-primary)' }}>
                      {c.name}
                    </p>
                    <span className={blocking ? 'np-badge-critical' : 'np-badge-medium'}>
                      {blocking ? 'Blocking' : (c.severity ?? 'Warning')}
                    </span>
                  </div>
                  <p className="text-xs leading-relaxed mb-1" style={{ color: 'var(--np-text-secondary)' }}>
                    <strong style={{ color: 'var(--np-text-primary)' }}>Why it's a problem:</strong> {c.reason}
                  </p>
                  <p className="text-xs leading-relaxed" style={{ color: 'var(--np-text-muted)' }}>
                    <strong style={{ color: 'var(--np-text-secondary)' }}>How to fix:</strong> {c.fix}
                  </p>
                </div>
                {c.auto_fix && (
                  <button
                    type="button"
                    onClick={() => onResolve(c.slug)}
                    disabled={disabling}
                    className="np-btn-secondary flex-shrink-0"
                  >
                    {disabling ? <Loader2 className="w-3 h-3 animate-spin" /> : null}
                    Resolve
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}

      <div className="flex justify-end mt-6">
        <button
          type="button"
          onClick={onNext}
          disabled={state.has_blocking}
          className="np-btn-primary"
          style={{ opacity: state.has_blocking ? 0.5 : 1 }}
          title={state.has_blocking ? 'A blocking conflict needs to be resolved first.' : undefined}
        >
          {state.has_blocking ? 'Resolve blockers' : 'Continue'}
          <ChevronRight className="w-4 h-4" />
        </button>
      </div>
    </StepBody>
  );
}

// ───────────────────────────── Step 4 ────────────────────────────────

function StepBuilding({
  ssg, activate, onSkip,
}: {
  ssg: SsgState | undefined;
  activate: ActivateResult | null;
  onSkip: () => void;
}) {
  const pct = ssg?.percent ?? 0;
  const processed = ssg?.processed ?? 0;
  const total = ssg?.total ?? activate?.total ?? 0;
  const running = !!ssg?.running;
  // Done = not running AND (hit 100% OR idle with files captured & nothing
  // pending). The second branch covers the case where the total counter reads 0
  // after a fast finish / purge but the mirror is actually built — otherwise the
  // step shows a permanent "0 / 0 · 0%".
  const hasFiles = (ssg?.static_files ?? 0) > 0;
  const nothingPending = (ssg?.pending_count ?? 0) === 0;
  const done = !running && ( ( total > 0 && pct >= 100 ) || ( hasFiles && nothingPending ) );

  return (
    <StepBody
      title={done ? 'Build complete.' : 'Building your static mirror.'}
      subtitle={
        done
          ? 'All eligible pages have been captured. You can finish setup.'
          : 'The engine is capturing each page in your library and writing it to disk. This runs in the background.'
      }
    >
      <div
        className="rounded-xl p-5 flex items-center gap-4 mb-4"
        style={{
          background: done
            ? 'linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%)'
            : 'linear-gradient(135deg, #EBF0FF 0%, #DBEAFE 100%)',
          border: `1px solid ${done ? 'rgba(22,163,74,0.20)' : 'rgba(2,82,250,0.18)'}`,
        }}
      >
        <div
          className="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{ background: '#FFFFFF' }}
        >
          {done ? (
            <CheckCircle2 className="w-6 h-6" style={{ color: '#16A34A' }} strokeWidth={2.4} />
          ) : (
            <Loader2 className="w-6 h-6 animate-spin" style={{ color: 'var(--np-brand-primary)' }} />
          )}
        </div>
        <div className="min-w-0 flex-1">
          <p
            className="text-[10px] uppercase font-bold tracking-wider"
            style={{ color: done ? '#16A34A' : 'var(--np-brand-primary)' }}
          >
            {done ? 'Build complete' : 'Build in progress'}
          </p>
          <p className="text-base font-bold tabular-nums mt-1" style={{ color: 'var(--np-text-primary)' }}>
            {formatNumber(processed)} <span style={{ color: 'var(--np-text-muted)' }}>/</span> {formatNumber(total)}
            <span className="text-xs uppercase tracking-wider ml-2 font-semibold" style={{ color: 'var(--np-text-muted)' }}>
              pages
            </span>
          </p>
        </div>
        <div
          className="text-3xl font-bold tabular-nums"
          style={{ color: done ? '#16A34A' : 'var(--np-brand-primary)' }}
        >
          {pct}%
        </div>
      </div>

      <div className="np-progress" style={{ height: 5 }}>
        <div
          className="np-progress-bar"
          style={{
            width: `${Math.max(3, pct)}%`,
            background: done
              ? 'linear-gradient(90deg, #16A34A, #22C55E)'
              : 'linear-gradient(90deg, var(--np-brand-primary), var(--np-blue-accent))',
          }}
        />
      </div>

      <p className="text-xs mt-4 leading-relaxed" style={{ color: 'var(--np-text-muted)' }}>
        The build runs on your server — you can close this tab or leave this page and it keeps going. This
        screen just shows live progress; capturing continues in the background until every page is mirrored.
      </p>

      <div className="flex justify-end mt-6">
        <button
          type="button"
          onClick={onSkip}
          className="np-btn-primary"
          disabled={!done && running}
        >
          {done ? 'Continue' : running ? 'Building…' : 'Continue'}
          <ChevronRight className="w-4 h-4" />
        </button>
      </div>
    </StepBody>
  );
}

// ───────────────────────────── Step 5 ────────────────────────────────

function StepLive({
  state, activate, finishing, onFinish,
}: {
  state: WizardState;
  activate: ActivateResult | null;
  finishing: boolean;
  onFinish: () => void;
}) {
  const tier = activate?.tier ?? state.server.tier;
  const tierLabel = activate?.tier_label ?? state.server.tier_label;
  const tierTtfb = activate?.tier_ttfb ?? state.server.tier_ttfb;
  const tierDesc = state.server.tier_desc;

  return (
    <StepBody
      title="You're live."
      subtitle="Nexora Engine is delivering your site. Finish setup to open the dashboard."
    >
      <TierStrip
        tier={tier}
        tierLabel={tierLabel}
        tierTtfb={tierTtfb}
        tierDesc={tierDesc}
      />

      {activate?.is_nginx && tier !== 1 && (
        <div
          className="rounded-xl p-4 flex items-start gap-3 mt-4"
          style={{
            background: 'rgba(2,82,250,0.05)',
            border: '1px solid rgba(2,82,250,0.18)',
          }}
        >
          <ServerCog className="w-5 h-5 flex-shrink-0 mt-0.5" style={{ color: 'var(--np-brand-primary)' }} />
          <div className="text-xs leading-relaxed" style={{ color: 'var(--np-text-primary)' }}>
            <strong style={{ color: 'var(--np-brand-primary)' }}>One more step for Tier 1 on Nginx:</strong> open Tools → "Nginx config" to copy the
            server-block snippet, paste it into your site's Nginx config, then reload. After that, static
            pages serve straight from disk at ~15ms.
          </div>
        </div>
      )}

      <div className="grid grid-cols-3 gap-3 mt-4">
        <Stat label="Drop-in" value={activate?.dropin_ok ? 'Installed' : 'Pending'} ok={activate?.dropin_ok} />
        <Stat label="Server rule" value={activate?.serve_ok ? 'Installed' : 'Manual'} ok={activate?.serve_ok} />
        <Stat label="Pages built" value={formatNumber(activate?.total ?? state.engine.static_files)} />
      </div>

      <div className="flex justify-end mt-6">
        <button
          type="button"
          onClick={onFinish}
          disabled={finishing}
          className="np-btn-primary"
        >
          {finishing ? <Loader2 className="w-4 h-4 animate-spin" /> : <Sparkles className="w-4 h-4" />}
          {finishing ? 'Finalizing…' : 'Open the Dashboard'}
        </button>
      </div>
    </StepBody>
  );
}

function Stat({ label, value, ok }: { label: string; value: React.ReactNode; ok?: boolean }) {
  return (
    <div className="np-card p-4">
      <p className="np-section-label">{label}</p>
      <p
        className="text-sm font-bold mt-2 flex items-center gap-1.5"
        style={{ color: 'var(--np-text-primary)' }}
      >
        {ok !== undefined && (
          <span
            className="w-2 h-2 rounded-full inline-block"
            style={{ background: ok ? '#16A34A' : '#F39A09' }}
          />
        )}
        {value}
      </p>
    </div>
  );
}

// ────────────────────────── Completed screen ─────────────────────────

function CompletedScreen({ state, onRerun }: { state: WizardState; onRerun: () => void }) {
  const e = state.engine;
  const archivesOk = e.archives_eligible > 0
    ? e.archives_captured >= e.archives_eligible
    : true;

  return (
    <StepBody
      title="Setup complete."
      subtitle="Nexora Engine is configured and running. Re-run the wizard at any time to reconfigure."
    >
      <div
        className="rounded-xl p-5 flex items-start gap-4 mb-4"
        style={{
          background: 'linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%)',
          border: '1px solid rgba(22,163,74,0.30)',
        }}
      >
        <div
          className="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{ background: '#FFFFFF' }}
        >
          <CheckCircle2 className="w-5 h-5" style={{ color: '#16A34A' }} strokeWidth={2.4} />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[10px] uppercase font-bold tracking-wider" style={{ color: '#16A34A' }}>
            Engine online
          </p>
          <p className="text-base font-bold mt-1" style={{ color: 'var(--np-text-primary)' }}>
            {formatNumber(e.static_files)} files in mirror
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
        <div className="np-card p-4">
          <p className="np-section-label mb-3">Engine state</p>
          <div className="space-y-2.5">
            <Row label="Static Delivery" ok={e.ssg_on} />
            {/* WP masking is free — it ships and runs on every install, so it
                carries no Pro note. Only the asset-path proxy is Pro. */}
            <Row label="Ghost Protocol (WP masking)" ok={e.ghost_on} />
            <Row
              label={state.is_pro ? 'Auto-rebuild on content changes' : 'Rebuild on content changes'}
              ok={e.auto_rebuild}
              note={!state.is_pro ? 'Manual · Pro automates' : undefined}
            />
            <Row label="Archive pages captured" ok={archivesOk} note={`${e.archives_captured} / ${e.archives_eligible}`} />
          </div>
        </div>

        <div className="np-card p-4">
          <p className="np-section-label mb-3">What's next</p>
          <div className="space-y-2">
            <a href={state.dashboard_url} className="np-btn-primary w-full justify-center">
              <ArrowRight className="w-4 h-4" />
              Open Dashboard
            </a>
            <a href={state.headless_url} className="np-btn-secondary w-full justify-center">
              <Layers className="w-4 h-4" />
              Static Delivery
            </a>
            <a href={state.settings_url} className="np-btn-secondary w-full justify-center">
              <ServerCog className="w-4 h-4" />
              Settings
            </a>
            <button type="button" onClick={onRerun} className="np-btn-secondary w-full justify-center">
              <RotateCw className="w-4 h-4" />
              Re-run wizard
            </button>
          </div>
        </div>
      </div>
    </StepBody>
  );
}

function Row({ label, ok, note }: { label: string; ok: boolean; note?: string }) {
  return (
    <div
      className="flex items-center justify-between py-1.5 border-b last:border-0"
      style={{ borderColor: 'var(--np-border-soft)' }}
    >
      <span className="text-[13px]" style={{ color: 'var(--np-text-primary)' }}>{label}</span>
      <div className="flex items-center gap-2">
        {note && (
          <span className="text-[11px]" style={{ color: 'var(--np-text-muted)' }}>
            {note}
          </span>
        )}
        <span
          className="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider"
          style={{ color: ok ? '#16A34A' : 'var(--np-text-muted)' }}
        >
          <span
            className="w-1.5 h-1.5 rounded-full inline-block"
            style={{ background: ok ? '#16A34A' : '#94A3B8' }}
          />
          {ok ? 'On' : 'Off'}
        </span>
      </div>
    </div>
  );
}

// ─────────────────────────── Wizard shell ────────────────────────────

function WizardShell({
  currentStep, completed = false, state, ssg, activate, children,
}: {
  currentStep: number;
  completed?: boolean;
  state: WizardState | null;
  ssg: SsgState | undefined;
  activate: ActivateResult | null;
  children: React.ReactNode;
}) {
  const pluginUrl = window.NexoraEngine?.pluginUrl ?? '';
  const stepNow = completed ? STEPS.length : currentStep;
  const pct = Math.round((stepNow / STEPS.length) * 100);
  return (
    <div className="min-h-screen flex flex-col" style={{ background: '#F7F8FA' }}>
      {/* Brand strip — deep brand-blue header */}
      <header
        className="px-7 py-4 flex items-center justify-between"
        style={{
          background: 'linear-gradient(135deg, var(--np-brand-primary) 0%, var(--np-brand-primary-hover) 100%)',
        }}
      >
        <div className="flex items-center gap-3">
          {/* Real brand mark on a white tile — matches the admin sidebar,
              the WordPress menu icon, and Nexora Pulse. */}
          <div
            className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden"
            style={{ background: '#FFFFFF', boxShadow: '0 2px 10px rgb(1 30 128 / 0.35)' }}
          >
            <img
              src={`${pluginUrl}assets/img/nexora-icon.png`}
              alt="Nexora"
              width={28}
              height={28}
              className="w-[28px] h-[28px] object-contain"
            />
          </div>
          <div>
            <p className="text-base font-bold tracking-tight text-white">Nexora Engine</p>
            <p className="text-[11px]" style={{ color: 'rgba(255,255,255,0.78)' }}>
              Static delivery setup
            </p>
          </div>
        </div>
        <span
          className="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-[12px] font-semibold"
          style={{ background: 'rgba(255,255,255,0.16)', color: '#FFFFFF' }}
        >
          Setup Wizard · Step
          <span className="font-bold tabular-nums" style={{ color: '#FFFFFF' }}>
            {stepNow}
          </span>
          of {STEPS.length}
        </span>
      </header>

      {/* Thin gradient progress bar under the header — Pulse-style, gives an
          at-a-glance sense of how far through setup the user is. */}
      <div className="h-1.5 w-full" style={{ background: 'rgba(2,82,250,0.10)' }}>
        <div
          className="h-full transition-all duration-500 ease-out"
          style={{
            width: `${pct}%`,
            background: 'linear-gradient(90deg, var(--np-brand-primary), #56A2FA)',
          }}
        />
      </div>

      <div className="flex-1 flex gap-6 px-7 py-6 max-w-[1440px] w-full mx-auto">
        {/* Left dark sidebar — stepper */}
        <aside
          className="flex flex-col flex-shrink-0 rounded-2xl p-4"
          style={{
            width: 232,
            background: '#011E80',
            boxShadow: '0 8px 24px -8px rgb(1 30 128 / 0.40)',
          }}
        >
          <Stepper current={currentStep} completed={completed} />
        </aside>

        {/* Middle column — step body */}
        <main className="flex-1 min-w-0">{children}</main>

        {/* Right column — Setup Signals panel */}
        {state && (
          <SignalsPanel
            step={completed ? STEPS.length : currentStep}
            state={state}
            ssg={ssg}
            activate={activate}
          />
        )}
      </div>
    </div>
  );
}

// ───────────────────────────── Main ──────────────────────────────────

export default function Wizard() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const [step, setStep] = useState(1);
  const [activateResult, setActivateResult] = useState<ActivateResult | null>(null);

  const { data: state, isLoading } = useQuery({
    queryKey: ['wizard-state'],
    queryFn: () => api.get<WizardState>('wizard/state'),
  });

  // Live SSG state for the "Building" step
  const { data: ssg } = useQuery<SsgState>({
    queryKey: ['ssg-state'],
    queryFn: () => api.get<SsgState>('ssg/state'),
    refetchInterval: (q) => (q.state.data?.running ? 1500 : 5000),
    enabled: step >= 4,
  });

  const activate = useMutation({
    mutationFn: () => api.post<ActivateResult>('wizard/activate'),
    onSuccess: (r) => {
      setActivateResult(r);
      pushToast('success', r.message ?? 'Engine activated');
      qc.invalidateQueries({ queryKey: ['wizard-state'] });
      qc.invalidateQueries({ queryKey: ['ssg-state'] });
      // Skip to step 4 directly — no conflicts means we're building already.
      setStep(state?.conflicts?.length ? 3 : 4);
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Activation failed'),
  });

  const disableConflict = useMutation({
    mutationFn: (slug: string) => api.post('wizard/disable-conflict', { slug }),
    onSuccess: (r: any) => {
      pushToast('success', r?.message ?? 'Conflict resolved');
      qc.invalidateQueries({ queryKey: ['wizard-state'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Failed to resolve conflict'),
  });

  const finish = useMutation({
    mutationFn: () => api.post<{ url: string }>('wizard/finish'),
    onSuccess: (r) => {
      pushToast('success', 'Setup complete.');
      qc.invalidateQueries({ queryKey: ['wizard-state'] });
      if (r?.url) window.location.href = r.url;
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Failed to finalize'),
  });

  const reset = useMutation({
    mutationFn: () => api.post('wizard/reset'),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['wizard-state'] });
      setStep(1);
      setActivateResult(null);
      pushToast('info', 'Wizard reset. You can now run setup again.');
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Reset failed'),
  });

  // Auto-advance from Building → Live when the build completes.
  //
  // Robust completion test: a build is DONE when it's no longer running AND
  // there's nothing left to capture. We deliberately do NOT require
  // `total > 0 && percent >= 100` — after a Purge+rebuild the total transient
  // can read 0 by the time we poll (e.g. the server driver finished the build
  // before the wizard's first /ssg/state poll, or Purge cleared the counters),
  // which left the wizard stuck on "0 / 0 · 0%" forever even though every page
  // was on disk. Using "not running + no pending + files exist" reflects the
  // engine's real state. We also give the build a moment to actually start
  // (mountedAtStep4) so we don't skip straight past the build screen.
  const step4StartRef = useRef<number | null>(null);
  useEffect(() => {
    if (step === 4 && step4StartRef.current === null) {
      step4StartRef.current = Date.now();
    }
    if (step !== 4) {
      step4StartRef.current = null;
    }
  }, [step]);

  useEffect(() => {
    if (step !== 4 || !ssg) return;
    const sinceStart = step4StartRef.current ? Date.now() - step4StartRef.current : 0;
    const notRunning = !ssg.running;
    const nothingPending = (ssg.pending_count ?? 0) === 0;
    const hasFiles = (ssg.static_files ?? 0) > 0;
    const reachedFull = (ssg.total ?? 0) > 0 && (ssg.percent ?? 0) >= 100;
    // Advance when the build has settled (>1.2s on step 4 so it actually began)
    // and either reported 100% OR is simply idle with files on disk + nothing
    // queued.
    if (sinceStart > 1200 && notRunning && (reachedFull || (hasFiles && nothingPending))) {
      setStep(5);
    }
  }, [step, ssg]);

  if (isLoading || !state) return <Spinner label="Loading setup wizard…" />;

  // Completed state — show a finished panel instead of forcing the user
  // through the wizard again.
  if (state.completed && step === 1 && !activateResult) {
    return (
      <WizardShell currentStep={STEPS.length} completed state={state} ssg={ssg} activate={activateResult}>
        <CompletedScreen state={state} onRerun={() => reset.mutate()} />
      </WizardShell>
    );
  }

  return (
    <WizardShell currentStep={step} state={state} ssg={ssg} activate={activateResult}>
      {step === 1 && (
        <StepIntro
          state={state}
          step={1}
          onNext={() => setStep(2)}
        />
      )}
      {step === 2 && (
        <StepIntro
          state={state}
          step={2}
          activating={activate.isPending}
          onActivate={() => activate.mutate()}
        />
      )}
      {step === 3 && (
        <StepConflicts
          state={state}
          disabling={disableConflict.isPending}
          onResolve={(slug) => disableConflict.mutate(slug)}
          onNext={() => setStep(4)}
        />
      )}
      {step === 4 && (
        <StepBuilding ssg={ssg} activate={activateResult} onSkip={() => setStep(5)} />
      )}
      {step === 5 && (
        <StepLive
          state={state}
          activate={activateResult}
          finishing={finish.isPending}
          onFinish={() => finish.mutate()}
        />
      )}
    </WizardShell>
  );
}
