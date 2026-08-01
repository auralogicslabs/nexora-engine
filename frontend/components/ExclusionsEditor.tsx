import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Filter, Save, ShieldOff } from 'lucide-react';
import { api } from '../lib/api';
import { useStore } from '../lib/store';

type Payload = {
  excluded_types: string[];
  script_hosts: string;
  available_types: { slug: string; label: string }[];
};

export default function ExclusionsEditor() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);

  const { data, isLoading } = useQuery({
    queryKey: ['ssg-exclusions'],
    queryFn: () => api.get<Payload>('ssg/exclusions'),
    staleTime: 60_000,
  });

  const [types, setTypes] = useState<string[]>([]);
  const [hosts, setHosts] = useState('');
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    if (data) {
      setTypes(data.excluded_types ?? []);
      setHosts(data.script_hosts ?? '');
      setDirty(false);
    }
  }, [data]);

  const save = useMutation({
    mutationFn: () => api.post('ssg/exclusions', { types, hosts }),
    onSuccess: () => {
      pushToast('success', 'Exclusions saved.');
      setDirty(false);
      qc.invalidateQueries({ queryKey: ['ssg-exclusions'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Save failed'),
  });

  function toggleType(slug: string) {
    setTypes((prev) => {
      const next = prev.includes(slug) ? prev.filter((s) => s !== slug) : [...prev, slug];
      return next;
    });
    setDirty(true);
  }

  return (
    <div className="np-card p-5">
      <div className="flex items-start justify-between gap-3 mb-4">
        <div className="flex items-center gap-2.5">
          <div
            className="w-9 h-9 rounded-xl flex items-center justify-center"
            style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
          >
            <ShieldOff className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
          </div>
          <div>
            <h3 className="text-sm font-bold text-[color:var(--np-text)]">Build exclusions</h3>
            <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5">
              Skip content types and trust extra script hosts when capturing pages.
            </p>
          </div>
        </div>
        <button
          type="button"
          onClick={() => save.mutate()}
          disabled={!dirty || save.isPending || isLoading}
          className="np-btn-primary text-xs"
        >
          <Save className="w-3.5 h-3.5" />
          {save.isPending ? 'Saving…' : 'Save'}
        </button>
      </div>

      {isLoading ? (
        <p className="text-xs text-[color:var(--np-text-muted)] py-6 text-center">Loading exclusions…</p>
      ) : (
        <div className="space-y-5">
          {/* Excluded post types */}
          <div>
            <p className="np-section-label mb-2 inline-flex items-center gap-1.5">
              <Filter className="w-3 h-3" />
              Excluded post types
            </p>
            <p className="text-[11px] text-[color:var(--np-text-muted)] leading-snug mb-2">
              These content types will never be captured into the mirror.
              Useful for ephemeral CPTs like form submissions or AJAX endpoints.
            </p>
            <div
              className="flex items-start gap-2 px-3 py-2 rounded-lg mb-2 text-xs leading-snug"
              style={{ background: 'var(--np-info-bg)', border: '1px solid rgba(2,82,250,0.15)', color: 'var(--np-info-text)' }}
            >
              <span className="flex-shrink-0 mt-0.5">ⓘ</span>
              <span>
                <strong>What happens when you save:</strong> excluded types are removed from future builds only.
                Any pages already captured stay in the mirror until you run <em>Rebuild all</em> or <em>Purge mirror</em>.
                Newly excluded types skip the queue immediately.
              </span>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
              {(data?.available_types ?? []).map((t) => {
                const checked = types.includes(t.slug);
                return (
                  <label
                    key={t.slug}
                    className="flex items-center gap-2 px-3 py-2 rounded-xl cursor-pointer"
                    style={{
                      background: checked ? '#EFF6FF' : 'var(--np-bg-subtle)',
                      border: `1px solid ${checked ? '#BFDBFE' : 'transparent'}`,
                    }}
                  >
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggleType(t.slug)}
                      className="rounded"
                    />
                    <span className="text-xs font-semibold text-[color:var(--np-text)] truncate">{t.label}</span>
                    <code className="text-[10px] text-[color:var(--np-text-muted)] font-mono ml-auto">{t.slug}</code>
                  </label>
                );
              })}
            </div>
          </div>

          {/* Script hosts allowlist */}
          <div>
            <p className="np-section-label mb-2">Trusted script hosts</p>
            <p className="text-[11px] text-[color:var(--np-text-muted)] leading-snug mb-2">
              External hosts whose <code className="font-mono">&lt;script&gt;</code> tags should be preserved
              in captured HTML (one per line). Use only the hostname — no protocol, no path.
            </p>
            {/* The placeholder uses example.com hostnames on purpose. Naming
                real CDNs here caused the wp.org scanner to read the plugin as
                loading remote assets, which it never does — this field is just
                a list the user types in. */}
            <textarea
              value={hosts}
              onChange={(e) => { setHosts(e.target.value); setDirty(true); }}
              rows={5}
              className="np-input w-full font-mono text-xs"
              placeholder="cdn.example.com&#10;assets.example.net&#10;widgets.example.org"
              spellCheck={false}
            />
            <p className="text-[10px] text-[color:var(--np-text-muted)] mt-1.5">
              Invalid lines are silently dropped on save (must match{' '}
              <code className="font-mono">[a-z0-9.-]+</code>).
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
