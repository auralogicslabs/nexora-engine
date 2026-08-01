import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Search, ExternalLink, Pencil, Activity, Image as ImageIcon,
  FileCode2, TrendingUp, CheckCircle2, AlertCircle, XCircle, Sparkles,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import { api, can } from '../lib/api';
import { formatNumber } from '../lib/format';

type Row = {
  id: number;
  title: string;
  post_type: string;
  permalink: string;
  edit_url: string;
  relative: string;
  has_desc: boolean;
  has_og: boolean;
  schema_type: string;
  hits: number;
};

type SeoPayload = {
  sitemap_url: string;
  totals: {
    urls: number;
    missing_meta: number;
    missing_og: number;
    social_ready_pct: number;
    schema_types_count: number;
    traffic_total_hits: number;
    traffic_tracked: number;
  };
  schema_types: Record<string, number>;
  rows: Row[];
};

function StatCard({
  icon: Icon, label, value, hint, tone = 'default',
}: {
  icon: React.FC<any>;
  label: string;
  value: React.ReactNode;
  hint?: string;
  tone?: 'default' | 'success' | 'warning' | 'danger';
}) {
  const TONE = {
    default: { bg: 'rgb(2 82 250 / 0.10)',  border: 'rgb(2 82 250 / 0.10)',  fg: 'var(--np-brand-primary)'  },
    success: { bg: 'rgb(22 163 74 / 0.10)',  border: 'rgb(22 163 74 / 0.10)',  fg: '#16A34A'  },
    warning: { bg: 'rgb(243 154 9 / 0.10)',  border: 'rgb(243 154 9 / 0.10)',  fg: '#F39A09' },
    danger:  { bg: 'rgb(226 75 74 / 0.10)', border: 'rgb(226 75 74 / 0.10)', fg: '#E24B4A'   },
  } as const;
  const t = TONE[tone];
  return (
    <div className="np-card p-4">
      <div className="flex items-center gap-2.5 mb-1">
        <div
          className="w-8 h-8 rounded-md flex items-center justify-center flex-shrink-0"
          style={{ background: t.bg, border: `1px solid ${t.border}` }}
        >
          <Icon className="w-4 h-4" style={{ color: t.fg }} strokeWidth={2.2} />
        </div>
        <p className="text-[11px] uppercase tracking-wide text-[color:var(--np-text-muted)] font-semibold">{label}</p>
      </div>
      <p className="text-2xl font-bold text-[color:var(--np-text)] tabular-nums leading-tight mt-1">{value}</p>
      {hint && <p className="text-[11px] text-[color:var(--np-text-muted)] mt-0.5 leading-snug">{hint}</p>}
    </div>
  );
}

function StatusPill({
  tone, children,
}: { tone: 'success' | 'warning' | 'error'; children: React.ReactNode }) {
  const map = {
    success: { bg: 'rgb(22 163 74 / 0.10)', fg: '#16A34A', Icon: CheckCircle2 },
    warning: { bg: 'rgb(243 154 9 / 0.10)', fg: '#F39A09', Icon: AlertCircle },
    error:   { bg: 'rgb(226 75 74 / 0.10)', fg: '#E24B4A', Icon: XCircle },
  } as const;
  const m = map[tone];
  const Icon = m.Icon;
  return (
    <span
      className="text-[10px] font-bold px-2 py-0.5 rounded-full inline-flex items-center gap-1"
      style={{ background: m.bg, color: m.fg }}
    >
      <Icon className="w-3 h-3" />
      {children}
    </span>
  );
}

export default function SeoReport() {
  const ctx = window.NexoraEngine!;
  // Presence of the per-post metadata editor, not the licence tier.
  const hasSeoPro = can('seoPerPost');
  const [query, setQuery] = useState('');

  // Every hook below MUST be called unconditionally — the Pro gate is rendered
  // after this block, never with an early `return` that would skip the rest of
  // the hooks. (Rules of Hooks — see MirrorBuildControl.tsx for the same fix.)
  // The report itself is free: /seo-report has no licence check and reads data
  // the plugin already computes for every install. What Pro adds is the per-post
  // editor (class-ncx-seo-pro__premium_only.php) that lets you act on the rows.
  const { data, isLoading } = useQuery({
    queryKey: ['seo-report'],
    queryFn: () => api.get<SeoPayload>('seo-report'),
    staleTime: 60_000,
  });

  const rows = data?.rows ?? [];
  const filtered = useMemo(() => {
    if (!query.trim()) return rows;
    const q = query.toLowerCase();
    return rows.filter(
      (r) =>
        r.title.toLowerCase().includes(q) ||
        r.relative.toLowerCase().includes(q) ||
        r.post_type.toLowerCase().includes(q),
    );
  }, [rows, query]);

  // No gate here any more. The report is free; the upsell is a banner further
  // down, rendered alongside the data rather than in place of it.

  if (isLoading || !data) return <Spinner label="Loading SEO report…" />;

  const t = data.totals;

  return (
    <div>
      <PageHeader
        title="SEO & Metadata Report"
        subtitle="Validate sitemap coverage, social metadata, and schema output before static delivery."
        icon={Activity}
        actions={
          <a href={data.sitemap_url} target="_blank" rel="noreferrer" className="np-btn-secondary text-xs">
            <ExternalLink className="w-3.5 h-3.5" />
            View sitemap.xml
          </a>
        }
      />

      <div className="p-6 space-y-5">
        {/* Pro pointer. Deliberately a banner beside the data, not a wall in
            front of it: the report is free, and what Pro adds is the per-post
            editor for acting on these rows. */}
        {!hasSeoPro && (
          <div
            className="np-card p-4 flex items-start justify-between gap-4"
            style={{ background: 'var(--np-bg-subtle)' }}
          >
            <div className="flex gap-3 min-w-0">
              <Sparkles className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
              <div className="min-w-0">
                <p className="text-sm font-semibold text-[color:var(--np-text)]">Editing metadata per page is a Pro feature</p>
                <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5 leading-relaxed">
                  This report, and the XML sitemap it validates, are part of the free version.
                  Pro adds a metadata panel to every page and post so you can set descriptions,
                  social cards, and JSON-LD schema without leaving the editor.
                </p>
              </div>
            </div>
            <a href={ctx.upgradeUrl} target="_blank" rel="noreferrer" className="np-btn-secondary text-xs flex-shrink-0">
              See plans
            </a>
          </div>
        )}

        {/* Top metrics */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <StatCard
            icon={CheckCircle2}
            label="Sitemap status"
            value="Live"
            hint={`${formatNumber(t.urls)} URL${t.urls === 1 ? '' : 's'} indexed`}
            tone="success"
          />
          <StatCard
            icon={ImageIcon}
            label="Social readiness"
            value={`${t.social_ready_pct}%`}
            hint={`${formatNumber(t.missing_og)} page${t.missing_og === 1 ? '' : 's'} missing OG images`}
            tone={t.missing_og === 0 ? 'success' : t.missing_og < 10 ? 'warning' : 'danger'}
          />
          <StatCard
            icon={FileCode2}
            label="Schema saturation"
            value={t.schema_types_count}
            hint="Active JSON-LD schema types"
          />
          <StatCard
            icon={TrendingUp}
            label="Traffic (7 days)"
            value={formatNumber(t.traffic_total_hits)}
            hint={`across ${formatNumber(t.traffic_tracked)} tracked page${t.traffic_tracked === 1 ? '' : 's'}`}
            tone={t.traffic_total_hits > 0 ? 'success' : 'default'}
          />
        </div>

        {/* Schema breakdown */}
        {Object.keys(data.schema_types).length > 0 && (
          <div className="np-card p-4">
            <p className="np-section-label mb-2">Schema breakdown</p>
            <div className="flex flex-wrap gap-2">
              {Object.entries(data.schema_types).map(([type, count]) => (
                <span
                  key={type}
                  className="text-xs px-2.5 py-1 rounded-full inline-flex items-center gap-1.5 font-semibold"
                  style={{ background: 'var(--np-bg-subtle)', color: 'var(--np-text-secondary)' }}
                >
                  <code className="font-mono">{type}</code>
                  <span className="tabular-nums">{count}</span>
                </span>
              ))}
            </div>
          </div>
        )}

        {/* Content SEO health */}
        <div className="np-card">
          <div className="flex items-center justify-between gap-3 px-5 py-4 border-b" style={{ borderColor: 'var(--np-border)' }}>
            <div className="min-w-0 flex-1">
              <h3 className="text-sm font-bold text-[color:var(--np-text)]">Content SEO Health</h3>
              <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5">
                Review the metadata Nexora preserves during mirror generation.
              </p>
            </div>
            <div className="flex items-center gap-2 flex-shrink-0">
              <div className="np-search-wrap">
                <Search />
                <input
                  type="text"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder="Search title or URL…"
                  className="np-search-input"
                />
              </div>
              <span
                className="text-[11px] font-bold px-2.5 py-1 rounded-full"
                style={{ background: 'rgb(2 82 250 / 0.10)', color: 'var(--np-brand-primary)', border: '1px solid rgb(2 82 250 / 0.10)' }}
              >
                {formatNumber(filtered.length)} URL{filtered.length === 1 ? '' : 's'}
              </span>
            </div>
          </div>

          {filtered.length === 0 ? (
            <div className="p-10 text-center text-xs text-[color:var(--np-text-muted)]">
              {rows.length === 0 ? 'No published content found.' : 'No matches for that search.'}
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="np-table-head">
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Page / Post</th>
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Meta Description</th>
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Social Image</th>
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Schema</th>
                    <th className="text-right px-5 py-2.5 font-semibold text-xs">Traffic (7D)</th>
                    <th className="px-5 py-2.5" />
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((r) => (
                    <tr key={r.id} className="border-t" style={{ borderColor: 'var(--np-border)' }}>
                      <td className="px-5 py-3 align-middle min-w-0">
                        <a
                          href={r.permalink}
                          target="_blank"
                          rel="noreferrer"
                          className="text-sm font-semibold text-[color:var(--np-text)] hover:text-[color:var(--np-brand-primary)] block truncate max-w-[320px]"
                          title={r.title}
                        >
                          {r.title || `#${r.id}`}
                        </a>
                        <p className="text-[11px] text-[color:var(--np-text-muted)] mt-0.5 font-mono truncate max-w-[320px]">
                          {r.post_type} · {r.relative}
                        </p>
                      </td>
                      <td className="px-5 py-3 align-middle">
                        {r.has_desc
                          ? <StatusPill tone="success">Optimized</StatusPill>
                          : <StatusPill tone="warning">Missing</StatusPill>}
                      </td>
                      <td className="px-5 py-3 align-middle">
                        {r.has_og
                          ? <StatusPill tone="success">Ready</StatusPill>
                          : <StatusPill tone="error">Missing</StatusPill>}
                      </td>
                      <td className="px-5 py-3 align-middle">
                        <code
                          className="text-[11px] font-mono px-2 py-0.5 rounded"
                          style={{ background: 'var(--np-bg-subtle)', color: 'var(--np-text-secondary)' }}
                        >
                          {r.schema_type}
                        </code>
                      </td>
                      <td className="px-5 py-3 align-middle text-right">
                        {r.hits > 0 ? (
                          <span className="text-sm font-bold text-[color:var(--np-text)] tabular-nums">
                            {formatNumber(r.hits)}
                            <span className="text-[10px] uppercase text-[color:var(--np-text-muted)] ml-1 font-semibold">hits</span>
                          </span>
                        ) : (
                          <span className="text-[color:var(--np-text-muted)] text-sm">—</span>
                        )}
                      </td>
                      <td className="px-5 py-3 align-middle text-right">
                        <a
                          href={r.edit_url}
                          className="np-btn-secondary text-[11px]"
                          title="Edit SEO"
                        >
                          <Pencil className="w-3 h-3" />
                          Optimize
                        </a>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
