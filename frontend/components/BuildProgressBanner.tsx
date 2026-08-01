import { useQuery } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import { api, SsgState } from '../lib/api';
import { formatNumber } from '../lib/format';

/**
 * Build Progress Banner — the prominent, full-width "Building static pages…"
 * strip shown while a mirror build / regeneration is running.
 *
 * Extracted from the Static Delivery page so the Dashboard (and any other page)
 * shows the exact same live progress without having to watch the smaller
 * right-rail Mirror Build Control widget. It polls ssg/state on its own so it
 * stays in lockstep wherever it is mounted.
 *
 * Renders nothing unless a build is actually running with a known total, so it
 * is safe to drop onto any page unconditionally.
 */
export default function BuildProgressBanner() {
  const { data: state } = useQuery({
    queryKey: ['ssg-state'],
    queryFn: () => api.get<SsgState>('ssg/state'),
    // Match Mirror Build Control's cadence: fast while building, idle otherwise.
    refetchInterval: (q) => {
      const s = q.state.data as SsgState | undefined;
      if (s?.running) return 1500;
      if ((s?.pending_count ?? 0) > 0) return 2000;
      return 10_000;
    },
    refetchIntervalInBackground: true,
  });

  const enabled = !!state?.enabled;
  const running = !!state?.running;
  const total   = state?.total ?? 0;

  if (!enabled || !running || total <= 0) return null;

  const percent = Math.max(0, Math.min(100, state?.percent ?? 0));

  return (
    <div
      className="rounded-xl p-4"
      style={{ background: 'var(--np-accent-bg, #EFF6FF)', border: '1px solid rgba(59,130,246,0.30)' }}
    >
      <div className="flex items-center justify-between mb-2">
        <div className="flex items-center gap-2">
          <RefreshCw className="w-4 h-4 animate-spin" style={{ color: 'var(--np-accent, #3B82F6)' }} />
          <span className="text-sm font-semibold" style={{ color: 'var(--np-text, #1E293B)' }}>
            {state?.paused ? 'Build paused' : 'Building static pages…'}
          </span>
        </div>
        <span className="text-sm font-bold tabular-nums" style={{ color: 'var(--np-accent, #3B82F6)' }}>
          {percent}%
        </span>
      </div>
      <div className="h-2 rounded-full overflow-hidden" style={{ background: 'rgba(59,130,246,0.18)' }}>
        <div
          className="h-full rounded-full transition-all duration-700 ease-out"
          style={{
            width: `${Math.max(3, percent)}%`,
            background: 'linear-gradient(90deg, #3B82F6 0%, #60A5FA 100%)',
          }}
        />
      </div>
      <div className="mt-1.5 text-xs tabular-nums" style={{ color: 'var(--np-text-muted, #64748B)' }}>
        {formatNumber(state?.processed ?? 0)} of {formatNumber(total)} pages captured
        {' · building on the server — safe to leave this page'}
      </div>
    </div>
  );
}
