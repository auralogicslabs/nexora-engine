import { CheckCircle2, AlertCircle, Info, ChevronRight, AlertTriangle, ExternalLink } from 'lucide-react';

type RowStatus = 'ok' | 'warn' | 'err' | 'info' | 'off';

type Row = {
  label: string;
  status: RowStatus;
  value: string;
  hint?: string | null;
  code?: boolean;
};

type Section = {
  label: string;
  rows: Row[];
};

type WarningPage = {
  id: number;
  title: string;
  permalink: string;
  warnings: string[];
};

export type DiagnosticPayload = {
  verdict: { status: RowStatus; label: string; msg: string };
  sections: Section[];
  warning_pages: WarningPage[];
  generated_at: string;
};

const STATUS = {
  ok:   { fg: '#16A34A',  glow: 'rgb(22 163 74 / 0.10)',  panel: 'np-panel-glow-lime'  as const, Icon: CheckCircle2 },
  warn: { fg: '#F39A09', glow: 'rgb(243 154 9 / 0.10)',  panel: 'np-panel-glow-amber' as const, Icon: AlertTriangle },
  err:  { fg: '#E24B4A',   glow: 'rgb(226 75 74 / 0.10)', panel: 'np-panel-glow-red'   as const, Icon: AlertCircle },
  info: { fg: 'var(--np-brand-primary)',  glow: 'rgb(2 82 250 / 0.10)',  panel: 'np-panel-glow-cyan'  as const, Icon: Info },
  off:  { fg: 'var(--np-text-muted)',   glow: 'transparent',             panel: '' as const,                    Icon: Info },
} as const;

function StatusDot({ status }: { status: RowStatus }) {
  const dotClass =
    status === 'ok'   ? 'np-status-dot-on'
    : status === 'warn' ? 'np-status-dot-warn'
    : status === 'err'  ? 'np-status-dot-err'
    : status === 'info' ? 'np-status-dot-info'
    : 'np-status-dot-off';
  return <span className={`np-status-dot ${dotClass}`} />;
}

function RowItem({ row }: { row: Row }) {
  const s = STATUS[row.status];
  return (
    <div
      className="flex items-start gap-3 px-4 py-2.5 border-b last:border-0"
      style={{ borderColor: 'var(--np-border-soft)' }}
    >
      <StatusDot status={row.status} />
      <span
        className="text-xs font-semibold flex-shrink-0 w-36"
        style={{ color: 'var(--np-text-muted)' }}
      >
        {row.label}
      </span>
      <div className="min-w-0 flex-1">
        {row.code ? (
          <code
            className="np-mono text-[11px] px-2 py-0.5 rounded inline-block"
            style={{
              background: 'var(--np-bg-subtle)',
              color: s.fg,
              border: '1px solid var(--np-border)',
              wordBreak: 'break-all',
            }}
          >
            {row.value}
          </code>
        ) : (
          <span
            className="text-sm font-bold"
            style={{ color: s.fg }}
          >
            {row.value}
          </span>
        )}
        {row.hint && (
          <p
            className="text-xs mt-1 leading-snug"
            style={{ color: 'var(--np-text-muted)' }}
          >
            {row.hint}
          </p>
        )}
      </div>
    </div>
  );
}

function SectionPanel({ section }: { section: Section }) {
  return (
    <div className="np-panel overflow-hidden">
      <div
        className="px-4 py-2.5 border-b flex items-center justify-between"
        style={{
          background: 'var(--np-bg-subtle)',
          borderColor: 'var(--np-border)',
        }}
      >
        <span
          className="text-xs font-bold"
          style={{ color: 'var(--np-text-primary)' }}
        >
          {section.label}
        </span>
        <ChevronRight className="w-3 h-3" style={{ color: 'var(--np-text-muted)' }} />
      </div>
      <div>
        {section.rows.map((r, i) => (
          <RowItem key={`${section.label}-${i}`} row={r} />
        ))}
      </div>
    </div>
  );
}

export default function DiagnosticReport({ data }: { data: DiagnosticPayload }) {
  const v = data.verdict;
  const verdictStyle = STATUS[v.status];
  const VerdictIcon = verdictStyle.Icon;

  return (
    <div className="space-y-4">
      {/* Verdict panel — soft accent strip + clear hierarchy. Types
          mirror the rest of the admin (text-sm headline, text-xs body,
          Plus Jakarta Sans). The mono terminal-look used to feel
          disconnected from the SaaS chrome. */}
      <div className={`np-panel ${verdictStyle.panel} p-4 flex items-start gap-3 relative overflow-hidden`}>
        <span
          className="absolute top-0 left-0 right-0 h-px"
          style={{ background: verdictStyle.fg, boxShadow: `0 0 12px 0 ${verdictStyle.glow}` }}
        />
        <div
          className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{
            background: `${verdictStyle.fg}1A`,
            border: `1px solid ${verdictStyle.fg}55`,
          }}
        >
          <VerdictIcon className="w-5 h-5" style={{ color: verdictStyle.fg }} strokeWidth={2.4} />
        </div>
        <div className="min-w-0 flex-1">
          <p
            className="text-[11px] font-bold uppercase tracking-wider"
            style={{ color: verdictStyle.fg }}
          >
            Verdict · {v.label}
          </p>
          <p
            className="text-sm mt-1 leading-snug font-medium"
            style={{ color: 'var(--np-text-primary)' }}
          >
            {v.msg}
          </p>
        </div>
      </div>

      {/* Sections */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {data.sections.map((s) => (
          <SectionPanel key={s.label} section={s} />
        ))}
      </div>

      {/* Pages with warnings */}
      {data.warning_pages.length > 0 && (
        <div className="np-panel np-panel-glow-amber overflow-hidden">
          <div
            className="px-4 py-2.5 border-b flex items-center justify-between"
            style={{
              background: 'var(--np-bg-subtle)',
              borderColor: 'var(--np-border)',
            }}
          >
            <span
              className="text-xs font-bold"
              style={{ color: '#92400E' }}
            >
              Pages with capture warnings ({data.warning_pages.length})
            </span>
          </div>
          <div>
            {data.warning_pages.map((p) => (
              <div
                key={p.id}
                className="px-4 py-2.5 border-b last:border-0"
                style={{ borderColor: 'var(--np-border-soft)' }}
              >
                <div className="flex items-center gap-2 mb-1.5">
                  <span className="np-status-dot np-status-dot-warn flex-shrink-0" />
                  <a
                    href={p.permalink}
                    target="_blank"
                    rel="noreferrer"
                    className="text-sm font-bold flex items-center gap-1 hover:underline"
                    style={{ color: 'var(--np-text-primary)' }}
                  >
                    {p.title}
                    <ExternalLink className="w-3 h-3" style={{ color: 'var(--np-text-muted)' }} />
                  </a>
                </div>
                <ul className="ml-4 space-y-1">
                  {p.warnings.map((w, i) => (
                    <li
                      key={i}
                      className="text-xs leading-snug"
                      style={{ color: 'var(--np-text-muted)' }}
                    >
                      · {w}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Footer timestamp — small, muted, but using the app's sans font
          like the rest of the report. */}
      <p
        className="text-xs text-center"
        style={{ color: 'var(--np-text-muted)' }}
      >
        Generated · {data.generated_at}
      </p>
    </div>
  );
}
