import { useQuery } from '@tanstack/react-query';
import {
  ShieldCheck, ShieldAlert, Eye, EyeOff, RefreshCw, Sparkles, Lock,
} from 'lucide-react';
import { api, StealthAudit } from '../lib/api';

/**
 * Stealth Score — the measurable, demoable face of Ghost Protocol.
 *
 * Probes the public site and shows, as a single 0–100 score + per-signal
 * breakdown, how well WordPress is hidden from fingerprinting tools and
 * vulnerability scanners. This is the screenshot that sells the plugin:
 * "your WordPress site is invisible — here's the proof."
 *
 * `compact` renders a tight summary tile (for the dashboard); the full form
 * lists every check (for the Security page / trophy report).
 */
export default function StealthScore({ compact = false }: { compact?: boolean }) {
  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['stealth-audit'],
    queryFn: () => api.get<StealthAudit>('stealth-audit'),
    staleTime: 5 * 60 * 1000,
  });

  if (isLoading || !data) {
    return (
      <div className="np-card p-5">
        <div className="flex items-center gap-3">
          <div className="np-skeleton w-16 h-16 rounded-full" />
          <div className="flex-1 space-y-2">
            <div className="np-skeleton h-4 w-40 rounded" />
            <div className="np-skeleton h-3 w-56 rounded" />
          </div>
        </div>
      </div>
    );
  }

  const score = data.score;
  // Score → colour: green (strong) / amber (partial) / red (exposed).
  const tone =
    score >= 85 ? { fg: '#16A34A', bg: 'rgba(22,163,74,0.10)', ring: '#16A34A' }
    : score >= 50 ? { fg: '#F39A09', bg: 'rgba(243,154,9,0.10)', ring: '#F39A09' }
    : { fg: '#E24B4A', bg: 'rgba(226,75,74,0.10)', ring: '#E24B4A' };

  // SVG ring geometry.
  const R = 30;
  const C = 2 * Math.PI * R;
  const dash = (score / 100) * C;

  const Gauge = (
    <div className="relative flex-shrink-0" style={{ width: 72, height: 72 }}>
      <svg width={72} height={72} viewBox="0 0 72 72">
        <circle cx={36} cy={36} r={R} fill="none" stroke="var(--np-border-soft)" strokeWidth={6} />
        <circle
          cx={36} cy={36} r={R} fill="none"
          stroke={tone.ring} strokeWidth={6} strokeLinecap="round"
          strokeDasharray={`${dash} ${C}`}
          transform="rotate(-90 36 36)"
          style={{ transition: 'stroke-dasharray 0.7s ease' }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="text-lg font-bold tabular-nums leading-none" style={{ color: tone.fg }}>{score}</span>
        <span className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--np-text-muted)' }}>{data.grade}</span>
      </div>
    </div>
  );

  if (compact) {
    return (
      <div className="np-card p-5">
        <div className="flex items-center gap-4">
          {Gauge}
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              {score >= 85
                ? <ShieldCheck className="w-4 h-4 flex-shrink-0" style={{ color: tone.fg }} />
                : <ShieldAlert className="w-4 h-4 flex-shrink-0" style={{ color: tone.fg }} />}
              <h3 className="text-sm font-bold" style={{ color: 'var(--np-text-primary)' }}>Stealth Score</h3>
            </div>
            <p className="text-[12px] mt-1 leading-snug" style={{ color: 'var(--np-text-muted)' }}>{data.verdict}</p>
            <div className="flex items-center gap-3 mt-2 text-[11px] font-semibold">
              <span className="inline-flex items-center gap-1" style={{ color: '#16A34A' }}>
                <EyeOff className="w-3 h-3" /> {data.hidden} hidden
              </span>
              {data.exposed > 0 && (
                <span className="inline-flex items-center gap-1" style={{ color: tone.fg }}>
                  <Eye className="w-3 h-3" /> {data.exposed} exposed
                </span>
              )}
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="np-card overflow-hidden">
      {/* Header */}
      <div className="flex items-center gap-4 p-5 border-b" style={{ borderColor: 'var(--np-border)' }}>
        {Gauge}
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <Sparkles className="w-4 h-4 flex-shrink-0" style={{ color: 'var(--np-brand-primary)' }} />
            <h3 className="text-base font-bold" style={{ color: 'var(--np-text-primary)' }}>Stealth Score</h3>
            <span className="text-[10px] font-bold px-2 py-0.5 rounded-full" style={{ background: tone.bg, color: tone.fg }}>
              {data.grade}
            </span>
          </div>
          <p className="text-[13px] mt-1 leading-snug" style={{ color: 'var(--np-text-secondary)' }}>{data.verdict}</p>
        </div>
        <button
          type="button"
          onClick={() => refetch()}
          disabled={isFetching}
          className="np-btn-secondary text-xs flex-shrink-0"
          title="Re-scan the public site"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${isFetching ? 'animate-spin' : ''}`} />
          Re-scan
        </button>
      </div>

      {/* Per-signal breakdown */}
      <ul>
        {data.checks.map((c) => {
          // An exposed signal that only Pro's Advanced Ghost Protocol can mask
          // is shown as an upgrade opportunity (amber "Pro unlocks this"),
          // not a plain red failure — it's honest and it drives upgrades.
          const proGap = !c.hidden && !!c.pro_only;
          return (
            <li
              key={c.id}
              className="flex items-start gap-3 px-5 py-2.5 border-b last:border-0"
              style={{ borderColor: 'var(--np-border-soft)' }}
            >
              {c.hidden ? (
                <EyeOff className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: '#16A34A' }} />
              ) : proGap ? (
                <Lock className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: '#F39A09' }} />
              ) : (
                <Eye className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: '#E24B4A' }} />
              )}
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold" style={{ color: 'var(--np-text-primary)' }}>{c.label}</p>
                <p className="text-[11px] mt-0.5 font-mono leading-snug" style={{ color: 'var(--np-text-muted)' }}>{c.detail}</p>
              </div>
              <span
                className="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full flex-shrink-0"
                style={c.hidden
                  ? { background: 'rgba(22,163,74,0.10)', color: '#16A34A' }
                  : proGap
                    ? { background: 'rgba(243,154,9,0.12)', color: '#F39A09' }
                    : { background: 'rgba(226,75,74,0.10)', color: '#E24B4A' }}
              >
                {c.hidden ? 'Hidden' : proGap ? 'Pro unlocks' : 'Exposed'}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
