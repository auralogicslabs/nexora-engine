import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowLeftRight, Plus, Download, Trash2, Link2,
  Sparkles, Lock, AlertTriangle, ExternalLink, Power, X,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import StatTile from '../components/ui/StatTile';
import Spinner from '../components/ui/Spinner';
import { api } from '../lib/api';
import { useStore } from '../lib/store';
import { formatNumber } from '../lib/format';

type Redirect = {
  id: number;
  source_url: string;
  target_url: string;
  redirect_type: number;
  is_active: number | boolean;
  hit_count: number;
  notes?: string;
  created_at?: string;
};

type RedirectsPayload = {
  rows: Redirect[];
  stats: { total: number; active: number; hits: number; top?: { source_url: string; hit_count: number } | null };
  chain_ids: number[];
  paged: number;
  per_page: number;
  is_pro: boolean;
};

function ProGate() {
  const ctx = window.NexoraEngine!;
  return (
    <div>
      <PageHeader title="Redirect Manager" icon={ArrowLeftRight} />
      <div className="p-10">
        <div
          className="max-w-xl mx-auto np-card p-10 text-center"
        >
          <div
            className="w-16 h-16 mx-auto mb-5 rounded-2xl flex items-center justify-center"
            style={{ background: 'var(--np-bg-subtle)', border: '1px solid rgb(2 82 250 / 0.10)', boxShadow: 'inset 0 0 0 1px rgb(2 82 250 / 0.10)' }}
          >
            <ArrowLeftRight className="w-7 h-7" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
          </div>
          <h2 className="text-xl font-bold text-[color:var(--np-text)]">Redirect Manager is a Pro Feature</h2>
          <p className="text-sm text-[color:var(--np-text-muted)] mt-2 leading-relaxed">
            Intelligent 301/302 redirect management with wildcard rules, instant toggle, hit tracking, chain detection, and CSV export.
          </p>
          <p className="text-xs text-[color:var(--np-text-muted)] mt-2 leading-relaxed">
            Every redirect fires before WordPress boots — zero performance overhead.
          </p>
          <a
            href={ctx.upgradeUrl}
            target="_blank"
            rel="noreferrer"
            className="np-btn-primary mt-6 text-sm"
          >
            <Sparkles className="w-4 h-4" />
            Upgrade to Pro
          </a>
        </div>
      </div>
    </div>
  );
}

function AddForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const pushToast = useStore((s) => s.pushToast);
  const [source, setSource] = useState('');
  const [target, setTarget] = useState('');
  const [type, setType]     = useState<301 | 302>(301);
  const [active, setActive] = useState(true);
  const [notes, setNotes]   = useState('');

  const create = useMutation({
    mutationFn: () =>
      api.post('redirects', {
        source: source.trim(),
        target: target.trim(),
        type,
        is_active: active,
        notes,
      }),
    onSuccess: () => {
      pushToast('success', 'Redirect saved');
      onSaved();
      onClose();
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Failed to add redirect'),
  });

  return (
    <div className="np-card p-5 mb-4">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-bold text-[color:var(--np-text)]">Add redirect</h3>
        <button type="button" onClick={onClose} className="text-[color:var(--np-text-muted)] hover:text-[color:var(--np-text)]">
          <X className="w-4 h-4" />
        </button>
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (!source.trim() || !target.trim()) {
            pushToast('error', 'Source path and target URL are required');
            return;
          }
          create.mutate();
        }}
        className="grid grid-cols-1 md:grid-cols-2 gap-3"
      >
        <label className="block">
          <span className="block text-xs font-semibold text-[color:var(--np-text)] mb-1">Source path</span>
          <input
            type="text"
            value={source}
            onChange={(e) => setSource(e.target.value)}
            placeholder="/old-page"
            className="np-input w-full"
            required
          />
          <span className="block text-[11px] text-[color:var(--np-text-muted)] mt-1">
            Relative path on this site. Wildcards: <code>/blog/*</code>
          </span>
        </label>

        <label className="block">
          <span className="block text-xs font-semibold text-[color:var(--np-text)] mb-1">Target URL</span>
          <input
            type="url"
            value={target}
            onChange={(e) => setTarget(e.target.value)}
            placeholder="https://example.com/new-page"
            className="np-input w-full"
            required
          />
          <span className="block text-[11px] text-[color:var(--np-text-muted)] mt-1">
            Full URL — can be on this site or external.
          </span>
        </label>

        <label className="block">
          <span className="block text-xs font-semibold text-[color:var(--np-text)] mb-1">Type</span>
          <select
            value={type}
            onChange={(e) => setType(Number(e.target.value) as 301 | 302)}
            className="np-input w-full"
          >
            <option value={301}>301 — Permanent</option>
            <option value={302}>302 — Temporary</option>
          </select>
        </label>

        <label className="block">
          <span className="block text-xs font-semibold text-[color:var(--np-text)] mb-1">Notes (optional)</span>
          <input
            type="text"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Why this redirect exists"
            className="np-input w-full"
          />
        </label>

        <label className="flex items-center gap-2 md:col-span-2">
          <input
            type="checkbox"
            checked={active}
            onChange={(e) => setActive(e.target.checked)}
            className="rounded"
          />
          <span className="text-xs text-[color:var(--np-text)]">Active immediately</span>
        </label>

        <div className="md:col-span-2 flex items-center justify-end gap-2 pt-2">
          <button type="button" onClick={onClose} className="np-btn-secondary text-xs">
            Cancel
          </button>
          <button type="submit" disabled={create.isPending} className="np-btn-primary text-xs">
            {create.isPending ? 'Saving…' : 'Save redirect'}
          </button>
        </div>
      </form>
    </div>
  );
}

function RedirectRow({
  row, isChain, onChanged,
}: {
  row: Redirect;
  isChain: boolean;
  onChanged: () => void;
}) {
  const pushToast = useStore((s) => s.pushToast);

  const toggle = useMutation({
    mutationFn: () => api.post(`redirects/${row.id}/toggle`, { is_active: !row.is_active }),
    onSuccess: () => {
      pushToast('success', 'Status updated');
      onChanged();
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Toggle failed'),
  });

  const del = useMutation({
    mutationFn: () => api.del(`redirects/${row.id}`),
    onSuccess: () => {
      pushToast('success', 'Redirect deleted');
      onChanged();
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Delete failed'),
  });

  const active = !!Number(row.is_active);

  return (
    <tr className="border-t" style={{ borderColor: 'var(--np-border)' }}>
      <td className="px-5 py-3 align-top">
        <div className="flex items-center gap-1.5 min-w-0">
          <span className="text-sm font-mono text-[color:var(--np-text)] truncate" title={row.source_url}>
            {row.source_url}
          </span>
          {isChain && (
            <span
              title="This redirect chains: its source is the target of another rule. Consider consolidating."
              className="np-badge text-[10px]"
              style={{ background: 'rgb(243 154 9 / 0.10)', color: '#F39A09' }}
            >
              <AlertTriangle className="w-3 h-3" />
              Chain
            </span>
          )}
        </div>
        {row.notes && (
          <p className="text-[11px] text-[color:var(--np-text-muted)] mt-1 truncate" title={row.notes}>
            {row.notes}
          </p>
        )}
      </td>
      <td className="px-5 py-3 align-top">
        <a
          href={row.target_url}
          target="_blank"
          rel="noreferrer"
          className="text-sm text-[color:var(--np-brand-primary)] hover:underline inline-flex items-center gap-1 min-w-0"
        >
          <span className="truncate max-w-[280px]">{row.target_url}</span>
          <ExternalLink className="w-3 h-3 flex-shrink-0" />
        </a>
      </td>
      <td className="px-5 py-3 align-top text-xs font-semibold text-[color:var(--np-text)] tabular-nums">
        {row.redirect_type}
      </td>
      <td className="px-5 py-3 align-top text-xs tabular-nums text-[color:var(--np-text-muted)]">
        {formatNumber(Number(row.hit_count) || 0)}
      </td>
      <td className="px-5 py-3 align-top">
        <button
          type="button"
          onClick={() => toggle.mutate()}
          disabled={toggle.isPending}
          className="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
          style={{ background: active ? 'var(--np-brand-primary)' : '#CBD5E1' }}
          title={active ? 'Active' : 'Inactive'}
        >
          <span
            className="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
            style={{ transform: active ? 'translateX(20px)' : 'translateX(4px)' }}
          />
        </button>
      </td>
      <td className="px-5 py-3 align-top text-right">
        <button
          type="button"
          onClick={async () => {
            const ok = await useStore.getState().askConfirm({
              title: 'Delete this redirect?',
              message: `${row.source_url} → ${row.target_url} will be removed. Visitors hitting the source URL will start getting 404 / fall-through instead.`,
              confirmLabel: 'Delete',
              tone: 'danger',
              icon: 'trash',
            });
            if (ok) del.mutate();
          }}
          disabled={del.isPending}
          className="np-btn-secondary text-[#B91C1C] text-xs"
        >
          <Trash2 className="w-3 h-3" />
          Delete
        </button>
      </td>
    </tr>
  );
}

export default function Redirects() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const [paged, setPaged] = useState(1);
  const [showAdd, setShowAdd] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['redirects', paged],
    queryFn: () => api.get<RedirectsPayload>(`redirects?paged=${paged}&per_page=50`),
  });

  const exportCsv = useMutation({
    mutationFn: () => api.get<{ filename: string; csv: string }>('redirects/export'),
    onSuccess: (res) => {
      if (!res?.csv) return;
      const blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = res.filename || 'redirects.csv';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
      pushToast('success', 'CSV downloaded');
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Export failed'),
  });

  const refresh = () => qc.invalidateQueries({ queryKey: ['redirects'] });

  const chainSet = useMemo(() => new Set(data?.chain_ids ?? []), [data]);

  if (isLoading) return <Spinner label="Loading redirects…" />;
  if (data && !data.is_pro) return <ProGate />;

  const stats            = data?.stats ?? { total: 0, active: 0, hits: 0 };
  const rows             = data?.rows ?? [];
  const totalPages       = Math.max(1, Math.ceil((stats.total || 0) / (data?.per_page ?? 50)));
  const redirectConflicts = (data as any)?.redirect_conflicts as string[] ?? [];

  return (
    <div>
      <PageHeader
        title="Redirect Manager"
        subtitle="Smart 301/302 rules. Fires before PHP boots — zero latency overhead. Supports wildcards."
        icon={ArrowLeftRight}
        actions={
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => exportCsv.mutate()}
              disabled={exportCsv.isPending || rows.length === 0}
              className="np-btn-secondary text-xs"
            >
              <Download className="w-3.5 h-3.5" />
              Export CSV
            </button>
            <button
              type="button"
              onClick={() => setShowAdd((s) => !s)}
              className="np-btn-primary text-xs"
            >
              <Plus className="w-3.5 h-3.5" />
              Add redirect
            </button>
          </div>
        }
      />

      <div className="p-6 space-y-4">
        {/* Conflict notice — shown when other redirect plugins are active */}
        {redirectConflicts.length > 0 && (
          <div
            className="rounded-xl p-4 flex gap-3 items-start"
            style={{ background: '#FFFBEB', border: '1px solid rgba(243,154,9,0.35)' }}
          >
            <AlertTriangle className="w-5 h-5 flex-shrink-0 mt-0.5" style={{ color: '#F39A09' }} />
            <div className="text-sm leading-snug flex-1" style={{ color: '#78350F' }}>
              <strong>Other redirect plugins are active:</strong>{' '}
              {redirectConflicts.join(', ')}.
              <p className="mt-1 text-xs" style={{ color: '#92400E' }}>
                Running two redirect managers can cause conflicts — a rule in one may override or
                loop with a rule in the other. Review whether rules overlap and consolidate into
                one plugin to avoid duplicate redirects or unexpected behaviour.
              </p>
            </div>
          </div>
        )}

        {/* Stats */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <StatTile
            icon={Link2}
            label="Total rules"
            value={formatNumber(stats.total)}
          />
          <StatTile
            icon={Power}
            label="Active rules"
            value={formatNumber(stats.active)}
            tone={stats.active > 0 ? 'success' : 'default'}
          />
          <StatTile
            icon={ArrowLeftRight}
            label="Total hits"
            value={formatNumber(stats.hits)}
            hint={stats.top?.source_url ? `Top: ${stats.top.source_url}` : undefined}
            tone="warning"
          />
        </div>

        {/* Add form */}
        {showAdd && (
          <AddForm onClose={() => setShowAdd(false)} onSaved={refresh} />
        )}

        {/* Chain warning */}
        {chainSet.size > 0 && (
          <div
            className="rounded-xl p-3 flex gap-2 items-start"
            style={{ background: 'rgb(243 154 9 / 0.10)', border: '1px solid #FCD34D' }}
          >
            <AlertTriangle className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: '#F39A09' }} />
            <div className="text-xs leading-snug" style={{ color: '#F39A09' }}>
              {chainSet.size} redirect{chainSet.size === 1 ? '' : 's'} chain through another rule.
              Consolidate them so visitors hit a single hop.
            </div>
          </div>
        )}

        {/* Table */}
        <div className="np-card">
          {rows.length === 0 ? (
            <div className="p-10 text-center">
              <Link2 className="w-8 h-8 mx-auto text-[color:var(--np-text-muted)] mb-2" />
              <p className="text-sm font-semibold text-[color:var(--np-text)]">No redirects yet</p>
              <p className="text-xs text-[color:var(--np-text-muted)] mt-1">
                Add your first 301/302 rule to start managing traffic.
              </p>
              <button
                type="button"
                onClick={() => setShowAdd(true)}
                className="np-btn-primary text-xs mt-4"
              >
                <Plus className="w-3.5 h-3.5" />
                Add redirect
              </button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="np-table-head">
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Source</th>
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Target</th>
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Type</th>
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Hits</th>
                    <th className="text-left px-5 py-2.5 font-semibold text-xs">Active</th>
                    <th className="px-5 py-2.5" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <RedirectRow
                      key={r.id}
                      row={r}
                      isChain={chainSet.has(r.id)}
                      onChanged={refresh}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between">
            <p className="text-xs text-[color:var(--np-text-muted)]">
              Page {paged} of {totalPages}
            </p>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setPaged((p) => Math.max(1, p - 1))}
                disabled={paged <= 1}
                className="np-btn-secondary text-xs"
              >
                Previous
              </button>
              <button
                type="button"
                onClick={() => setPaged((p) => Math.min(totalPages, p + 1))}
                disabled={paged >= totalPages}
                className="np-btn-secondary text-xs"
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
