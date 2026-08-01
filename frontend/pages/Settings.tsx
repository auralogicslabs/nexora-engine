import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Settings as SettingsIcon, Save, Globe2, BarChart2, Database,
  ShieldCheck, Lock, KeyRound, Clock,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import { api } from '../lib/api';
import { useStore } from '../lib/store';

type SettingsMap = Record<string, string | number | boolean>;

function asBool(v: unknown): boolean {
  return v === true || v === 'on' || v === 1 || v === '1';
}

function Section({
  title, icon: Icon, desc, children, pro,
}: {
  title: string;
  icon: React.FC<any>;
  desc?: string;
  children: React.ReactNode;
  pro?: boolean;
}) {
  return (
    <div className="np-card">
      <div className="flex items-start gap-3 px-5 py-4 border-b" style={{ borderColor: 'var(--np-border)' }}>
        <div
          className="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
        >
          <Icon className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5">
            <h3 className="text-sm font-bold text-[color:var(--np-text)]">{title}</h3>
            {pro && <span className="np-badge-pro text-[9px] px-1.5 py-px">PRO</span>}
          </div>
          {desc && <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5">{desc}</p>}
        </div>
      </div>
      <div className="p-5 space-y-4">{children}</div>
    </div>
  );
}

function Toggle({
  label, hint, value, onChange, disabled,
}: {
  label: string;
  hint?: string;
  value: boolean;
  onChange: (v: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex items-start justify-between gap-4">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-semibold text-[color:var(--np-text)] leading-tight">{label}</p>
        {hint && <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5 leading-snug">{hint}</p>}
      </div>
      <button
        type="button"
        onClick={() => !disabled && onChange(!value)}
        disabled={disabled}
        className="relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 mt-0.5"
        style={{
          background: value ? 'var(--np-brand-primary)' : '#CBD5E1',
          opacity: disabled ? 0.5 : 1,
        }}
        role="switch"
        aria-checked={value}
      >
        <span
          className="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
          style={{ transform: value ? 'translateX(20px)' : 'translateX(4px)' }}
        />
      </button>
    </div>
  );
}

function TextField({
  label, hint, value, onChange, type = 'text', placeholder, disabled,
}: {
  label: string;
  hint?: string;
  value: string;
  onChange: (v: string) => void;
  type?: 'text' | 'password' | 'number';
  placeholder?: string;
  disabled?: boolean;
}) {
  return (
    <label className="block">
      <span className="block text-sm font-semibold text-[color:var(--np-text)] mb-1">{label}</span>
      <input
        type={type}
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        className="np-input w-full"
        style={{ opacity: disabled ? 0.5 : 1 }}
      />
      {hint && <span className="block text-xs text-[color:var(--np-text-muted)] mt-1">{hint}</span>}
    </label>
  );
}

export default function Settings() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const isPro = !!window.NexoraEngine?.isPro;

  const { data, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => api.get<SettingsMap>('settings'),
  });

  const [draft, setDraft] = useState<SettingsMap>({});
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    if (data) {
      setDraft(data);
      setDirty(false);
    }
  }, [data]);

  // Guard against silent loss of unsaved changes — common admin frustration
  // when a phone call interrupts settings editing and the tab gets closed.
  useEffect(() => {
    if (!dirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [dirty]);

  const save = useMutation({
    mutationFn: (body: SettingsMap) => api.post('settings', body),
    onSuccess: () => {
      pushToast('success', 'Settings saved');
      setDirty(false);
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['summary'] });
      qc.invalidateQueries({ queryKey: ['ssg-state'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Save failed'),
  });

  function set<K extends string>(key: K, value: string | number | boolean) {
    setDraft((d) => ({ ...d, [key]: value }));
    setDirty(true);
  }

  const b = (k: string) => asBool(draft[k]);
  const s = (k: string) => String(draft[k] ?? '');

  if (isLoading) return <Spinner label="Loading settings…" />;

  return (
    <div>
      <PageHeader
        title="Settings"
        subtitle="Site-wide configuration for Nexora Engine"
        icon={SettingsIcon}
        actions={
          <button
            type="button"
            onClick={() => save.mutate(draft)}
            disabled={!dirty || save.isPending}
            className="np-btn-primary text-xs"
            style={{ opacity: !dirty || save.isPending ? 0.6 : 1 }}
          >
            <Save className="w-3.5 h-3.5" />
            {save.isPending ? 'Saving…' : 'Save changes'}
          </button>
        }
      />

      <div className="p-6 grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* General */}
        <Section title="General" icon={Globe2}>
          <Toggle
            label="Admin bar badge"
            hint="Show a Nexora Engine badge in the admin toolbar."
            value={b('nexeng_admin_bar_badge')}
            onChange={(v) => set('nexeng_admin_bar_badge', v)}
          />
          <Toggle
            label="Auto rebuild on content changes"
            hint="Regenerate static pages whenever a post or page is updated."
            value={b('nexeng_auto_rebuild')}
            onChange={(v) => set('nexeng_auto_rebuild', v)}
          />
          <TextField
            label="Staging HTTP auth user"
            hint="If your staging site is behind HTTP basic auth, enter the loopback credentials so the engine can crawl it."
            value={s('nexeng_http_auth_user')}
            onChange={(v) => set('nexeng_http_auth_user', v)}
          />
          <TextField
            label="Staging HTTP auth password"
            type="password"
            value={s('nexeng_http_auth_pass')}
            onChange={(v) => set('nexeng_http_auth_pass', v)}
          />
        </Section>

        {/* Analytics */}
        <Section title="Analytics" icon={BarChart2}>
          <Toggle
            label="Enable analytics"
            hint="Lightweight, privacy-respecting page-view tracking."
            value={b('nexeng_analytics_enabled')}
            onChange={(v) => set('nexeng_analytics_enabled', v)}
          />
          <Toggle
            label="Anonymize IPs"
            hint="Drop the last octet of visitor IP addresses before storage."
            value={b('nexeng_anonymize_ips')}
            onChange={(v) => set('nexeng_anonymize_ips', v)}
          />
        </Section>

        {/* SEO */}
        <Section title="SEO" icon={Globe2}>
          <Toggle
            label="Sitemap"
            hint="Generate and submit an XML sitemap."
            value={b('nexeng_sitemap_enabled')}
            onChange={(v) => set('nexeng_sitemap_enabled', v)}
          />
          <Toggle
            label="Schema markup"
            hint="Emit JSON-LD structured data for posts, pages, and breadcrumbs."
            value={b('nexeng_schema_enabled')}
            onChange={(v) => set('nexeng_schema_enabled', v)}
          />
        </Section>

        {/* CDN — Pro only, and hidden rather than locked on the free tier.
            class-ncx-cdn__premium_only.php is the only consumer of these options
            and Freemius strips it from the free build, so nothing there could
            ever read them. Showing the fields asked free users to hand over
            Cloudflare and BunnyCDN API tokens for code that is not on their
            site. */}
        {isPro && (
        <Section
          title="CDN / Edge cache"
          icon={Database}
          desc="Save your CDN keys — the auto-purge integration ships in an upcoming release."
        >
          <div
            className="flex items-start gap-2.5 rounded-xl px-4 py-3 mb-2"
            style={{
              background: '#FFFBEB',
              border: '1px solid rgba(243,154,9,0.30)',
            }}
          >
            <Clock className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: '#92400E' }} />
            <div className="text-xs leading-snug" style={{ color: '#78350F' }}>
              <strong>Coming soon —</strong> CDN auto-purge (Cloudflare, BunnyCDN) is on the
              roadmap. You can pre-fill your keys below and they will be used automatically
              once the integration ships. No action is taken with them today.
            </div>
          </div>
          <Toggle
            label="Auto-purge on content update"
            hint="When ready: invalidates cached URLs at the CDN edge whenever a page is updated or rebuilt."
            value={b('nexeng_cdn_auto_purge')}
            onChange={(v) => set('nexeng_cdn_auto_purge', v)}
          />
          <TextField
            label="Cloudflare zone ID"
            placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
            value={s('nexeng_cdn_cf_zone_id')}
            onChange={(v) => set('nexeng_cdn_cf_zone_id', v)}
          />
          <TextField
            label="Cloudflare API token"
            type="password"
            placeholder="Stored securely, used when integration activates"
            value={s('nexeng_cdn_cf_api_token')}
            onChange={(v) => set('nexeng_cdn_cf_api_token', v)}
          />
          <TextField
            label="BunnyCDN zone ID"
            value={s('nexeng_cdn_bunny_zone_id')}
            onChange={(v) => set('nexeng_cdn_bunny_zone_id', v)}
          />
          <TextField
            label="BunnyCDN API key"
            type="password"
            value={s('nexeng_cdn_bunny_api_key')}
            onChange={(v) => set('nexeng_cdn_bunny_api_key', v)}
          />
        </Section>
        )}

        {/* Security hardening lives on its own screen.
            These ten toggles used to be duplicated here, writing the same
            nexeng_secure_* options as the Security page while marked Pro and
            disabled — which locked free users out of the five guards that ship
            and run for them. One screen owns these settings now. */}
        <Section
          title="Security hardening"
          icon={ShieldCheck}
          desc="Enumeration blocking, XML-RPC, login errors and more."
        >
          <a href={`${window.NexoraEngine?.adminUrl ?? ''}admin.php?page=ncx-security`} className="np-btn-secondary text-xs inline-flex">
            <ShieldCheck className="w-3.5 h-3.5" />
            Open Security
          </a>
        </Section>
      </div>
    </div>
  );
}
