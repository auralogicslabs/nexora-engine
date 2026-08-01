import React from 'react';

interface Props {
  icon: React.FC<any>;
  label: string;
  value: React.ReactNode;
  hint?: string;
  tone?: 'default' | 'success' | 'warning' | 'danger';
}

// Tones map to functional palettes. DEFAULT is now warm neutral (not blue) —
// blue is reserved for the primary action, not stat tiles. Functional tones
// keep their semantic colors: green for success, amber for warning, red
// for danger.
const TONE = {
  default: { bg: 'var(--np-bg-subtle)',                                          border: 'var(--np-neutral-200)', fg: 'var(--np-neutral-600)' },
  success: { bg: 'linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%)',           border: 'rgba(22,163,74,0.20)',   fg: '#15803D' },
  warning: { bg: 'linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%)',           border: 'rgba(243,154,9,0.25)',   fg: '#92400E' },
  danger:  { bg: 'linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%)',           border: 'rgba(226,75,74,0.25)',   fg: '#A32D2D' },
} as const;

export default function StatTile({ icon: Icon, label, value, hint, tone = 'default' }: Props) {
  const t = TONE[tone];
  return (
    <div className="np-card p-4 flex items-start gap-3">
      <div
        className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
        style={{ background: t.bg, border: `1px solid ${t.border}` }}
      >
        <Icon className="w-4 h-4" style={{ color: t.fg }} strokeWidth={2.2} />
      </div>
      <div className="min-w-0 flex-1">
        <p
          className="text-[11px] uppercase tracking-wide font-semibold"
          style={{ color: 'var(--np-text-muted)' }}
        >
          {label}
        </p>
        <p
          className="text-xl font-bold tabular-nums leading-tight mt-0.5"
          style={{ color: 'var(--np-text-primary)' }}
        >
          {value}
        </p>
        {hint && (
          <p
            className="text-[11px] mt-0.5 leading-snug"
            style={{ color: 'var(--np-text-muted)' }}
          >
            {hint}
          </p>
        )}
      </div>
    </div>
  );
}
