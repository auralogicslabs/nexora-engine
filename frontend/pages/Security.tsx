import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ShieldCheck, Save, IdCard, EyeOff, Network, Eye, AlertTriangle,
  Lock, ShieldAlert, FileLock, FileEdit, Shield, KeyRound, Sparkles,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import StealthScore from '../components/StealthScore';
import { api, can } from '../lib/api';
import { useStore } from '../lib/store';

type SettingsMap = Record<string, string | number | boolean>;

function asBool(v: unknown): boolean {
  return v === true || v === 'on' || v === 1 || v === '1';
}

type Guard = {
  id: string;
  label: string;
  desc: React.ReactNode;
  icon: React.FC<any>;
  pro: boolean;
  hasInput?: { key: string; placeholder: string };
};

const GUARDS: Guard[] = [
  // ── Free ─────────────────────────────────────────────────────────
  {
    id: 'nexeng_secure_users_api',
    label: 'Block User Enumeration (REST)',
    desc: 'Restricts /wp-json/wp/v2/users to authenticated requests only, preventing automated username harvesting.',
    icon: IdCard,
    pro: false,
  },
  {
    id: 'nexeng_secure_author_enum',
    label: 'Block Author Enumeration (URL)',
    desc: 'Returns 404 for ?author=N requests used to map valid usernames via author archive redirects.',
    icon: EyeOff,
    pro: false,
  },
  {
    id: 'nexeng_secure_xmlrpc',
    label: 'Disable XML-RPC',
    desc: (
      <>
        Turns off the legacy XML-RPC protocol — a common amplification vector for brute-force attacks.{' '}
        <strong style={{ color: '#F39A09' }}>Note:</strong> the Jetpack plugin and the WordPress mobile app rely on XML-RPC; disable only if you don't use them.
      </>
    ),
    icon: Network,
    pro: false,
  },
  {
    id: 'nexeng_secure_remove_version',
    label: 'Remove WordPress Version',
    desc: (
      <>
        Strips the WP version from the <code>&lt;meta name=generator&gt;</code> tag and RSS / Atom feeds. (Safe for frontend caching — does not touch <code>?ver=</code> cache-busters.)
      </>
    ),
    icon: Eye,
    pro: false,
  },
  {
    id: 'nexeng_secure_login_errors',
    label: 'Mask Login Error Messages',
    desc: 'Replaces specific "Invalid username" / "Incorrect password" messages with a single generic response so attackers can\'t tell which field was wrong.',
    icon: AlertTriangle,
    pro: false,
  },
  // ── Pro ──────────────────────────────────────────────────────────
  {
    id: 'nexeng_secure_rest_tighten',
    label: 'Tighten REST API Access',
    desc: (
      <>
        Requires authentication for the comments and media REST endpoints.{' '}
        <strong style={{ color: '#F39A09' }}>Advanced:</strong> test before enabling — public comment forms or front-end image galleries that fetch these endpoints will break.
      </>
    ),
    icon: Lock,
    pro: true,
  },
  {
    id: 'nexeng_secure_rate_limit',
    label: 'Login Rate Limiting',
    desc: 'Locks out an IP address for 15 minutes after 5 consecutive failed login attempts.',
    icon: ShieldAlert,
    pro: true,
  },
  {
    id: 'nexeng_secure_strong_pass',
    label: 'Force Strong Passwords',
    desc: 'Enforces 12+ characters with uppercase, number, and symbol on profile updates, password resets, and registration.',
    icon: KeyRound,
    pro: true,
  },
  {
    id: 'nexeng_secure_login_rename',
    label: 'Rename Login URL',
    desc: (
      <>
        Moves <code>wp-login.php</code> to a custom path. Direct access to <code>wp-login.php</code> returns 404.{' '}
        <strong style={{ color: '#F39A09' }}>Important:</strong> save your new URL before logging out — losing the slug means database-level recovery.
      </>
    ),
    icon: FileLock,
    pro: true,
    hasInput: { key: 'nexeng_secure_login_slug', placeholder: 'e.g. my-secure-login' },
  },
  {
    id: 'nexeng_secure_disable_file_edit',
    label: 'Disable Theme/Plugin Editor',
    desc: 'Removes the Appearance → Theme File Editor and Plugins → Plugin File Editor from wp-admin. Prevents code injection through a compromised admin account.',
    icon: FileEdit,
    pro: true,
  },
  {
    id: 'nexeng_secure_headers',
    label: 'Security Response Headers',
    desc: (
      <>
        Sends <code>X-Frame-Options</code>, <code>X-Content-Type-Options</code>, and <code>Referrer-Policy</code> on every PHP-rendered response. (These headers are <em>always</em> sent on SSG cached pages — this toggle extends them to PHP fallback responses.)
      </>
    ),
    icon: Shield,
    pro: true,
  },
];

/**
 * One guard.
 *
 * `available` means the code behind this guard is present in the build. When it
 * is false the module was stripped from this download, so the row describes the
 * guard and stops there — it deliberately renders no switch at all. A disabled
 * switch would imply the feature shipped and was withheld, which is both untrue
 * here and against WordPress.org Guideline 5.
 */
function GuardRow({
  guard, value, onToggle, slugValue, onSlugChange, available, homeUrl,
}: {
  guard: Guard;
  value: boolean;
  onToggle: (v: boolean) => void;
  slugValue?: string;
  onSlugChange?: (v: string) => void;
  available: boolean;
  homeUrl: string;
}) {
  const Icon = guard.icon;
  return (
    <div
      className="flex items-start justify-between gap-4 p-4 rounded-2xl border"
      style={{
        borderColor: 'var(--np-border)',
        background: available ? 'var(--np-bg-card)' : 'var(--np-bg-subtle)',
      }}
    >
      <div className="flex gap-3 min-w-0 flex-1">
        <div
          className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
        >
          <Icon className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-[color:var(--np-text)] leading-tight">{guard.label}</p>
          <p className="text-xs text-[color:var(--np-text-muted)] mt-1 leading-relaxed">{guard.desc}</p>

          {guard.hasInput && value && available && onSlugChange && (
            <div className="mt-3 flex items-center gap-2 flex-wrap">
              <span
                className="text-xs font-mono text-[color:var(--np-text-muted)] px-2 py-1.5 rounded-lg"
                style={{ background: 'var(--np-bg-subtle)' }}
              >
                {homeUrl}
              </span>
              <input
                type="text"
                value={slugValue ?? ''}
                placeholder={guard.hasInput.placeholder}
                onChange={(e) => onSlugChange(e.target.value)}
                className="np-input text-xs flex-1 min-w-[160px]"
              />
              <p className="w-full text-[11px] text-[color:#92400E] mt-1">
                ⚠ Note your new URL before saving. Incorrect slug can lock you out.
              </p>
            </div>
          )}
        </div>
      </div>
      {available ? (
        <button
          type="button"
          onClick={() => onToggle(!value)}
          className="relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 mt-1"
          style={{ background: value ? 'var(--np-brand-primary)' : '#CBD5E1' }}
          role="switch"
          aria-checked={value}
        >
          <span
            className="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
            style={{ transform: value ? 'translateX(20px)' : 'translateX(4px)' }}
          />
        </button>
      ) : (
        <span className="np-badge-pro text-[9px] px-1.5 py-px flex-shrink-0 mt-1">PRO</span>
      )}
    </div>
  );
}

export default function Security() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const ctx = window.NexoraEngine!;
  const isPro = !!ctx?.isPro;
  // Whether the advanced guards are installed at all, which is not the same
  // question as whether the licence is Pro: the WordPress.org build ships
  // without class-ncx-hardening-pro, so there is nothing to switch on.
  const hasHardeningPro = can('hardeningPro');
  const homeUrl = `${ctx?.siteUrl?.replace(/\/$/, '') ?? ''}/`;

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

  const save = useMutation({
    mutationFn: (body: SettingsMap) => api.post('settings', body),
    onSuccess: () => {
      pushToast('success', 'Security rules saved');
      setDirty(false);
      qc.invalidateQueries({ queryKey: ['settings'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Save failed'),
  });

  function set(key: string, value: string | boolean) {
    setDraft((d) => ({ ...d, [key]: value }));
    setDirty(true);
  }

  if (isLoading) return <Spinner label="Loading security…" />;

  const freeGuards = GUARDS.filter((g) => !g.pro);
  const proGuards  = GUARDS.filter((g) => g.pro);

  return (
    <div>
      <PageHeader
        title="Security Hardening"
        subtitle="PHP-only guards — active on Apache, Nginx, LiteSpeed, and every other server without .htaccess changes."
        icon={ShieldCheck}
        actions={
          <button
            type="button"
            onClick={() => save.mutate(draft)}
            disabled={!dirty || save.isPending}
            className="np-btn-primary text-xs"
            style={{ opacity: !dirty || save.isPending ? 0.6 : 1 }}
          >
            <Save className="w-3.5 h-3.5" />
            {save.isPending ? 'Saving…' : 'Save Rules'}
          </button>
        }
      />

      <div className="p-6 space-y-6">
        {/* Stealth Score — full breakdown. The proof that the hardening +
            Ghost Protocol actually made WordPress invisible to scanners.
            This is the trophy users screenshot and share. */}
        <StealthScore />

        {/* Status banner */}
        <div
          className="np-card p-4 flex items-center justify-between gap-3"
          style={{ background: isPro
            ? 'linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%)'
            : 'var(--np-bg-card)' }}
        >
          <div className="flex items-center gap-3">
            <div
              className="w-10 h-10 rounded-2xl flex items-center justify-center"
              style={{ background: 'var(--np-bg-subtle)', border: '1px solid rgb(2 82 250 / 0.10)', boxShadow: 'inset 0 0 0 1px rgb(2 82 250 / 0.10)' }}
            >
              <ShieldCheck className="w-5 h-5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
            </div>
            <div>
              <p className="text-sm font-bold text-[color:var(--np-text)]">Active Protection Modules</p>
              <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5">
                {hasHardeningPro ? 'Pro — All modules available' : 'Free — Essential guards active'}
              </p>
            </div>
          </div>
          {!isPro && (
            <a href={ctx.upgradeUrl} target="_blank" rel="noopener noreferrer" className="np-btn-primary text-xs">
              <Sparkles className="w-3.5 h-3.5" />
              Upgrade
            </a>
          )}
        </div>

        {/* Essential Guards */}
        <section>
          <h2 className="text-xs font-bold uppercase tracking-wide text-[color:var(--np-text-muted)] mb-3">
            Essential Guards
          </h2>
          <div className="grid grid-cols-1 gap-3">
            {freeGuards.map((g) => (
              <GuardRow
                key={g.id}
                guard={g}
                value={asBool(draft[g.id])}
                onToggle={(v) => set(g.id, v)}
                available
                homeUrl={homeUrl}
              />
            ))}
          </div>
        </section>

        {/* Advanced Guards */}
        <section>
          <div className="flex items-center gap-2 mb-3">
            <h2 className="text-xs font-bold uppercase tracking-wide text-[color:var(--np-text-muted)]">
              Advanced Guards
            </h2>
            <span className="np-badge-pro text-[9px] px-1.5 py-px">PRO</span>
            {!hasHardeningPro && (
              <a
                href={ctx.upgradeUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="text-xs font-bold text-[color:var(--np-brand-primary)] hover:underline"
              >
                Available in Pro →
              </a>
            )}
          </div>
          <div className="grid grid-cols-1 gap-3">
            {proGuards.map((g) => (
              <GuardRow
                key={g.id}
                guard={g}
                value={asBool(draft[g.id])}
                onToggle={(v) => set(g.id, v)}
                slugValue={g.hasInput ? String(draft[g.hasInput.key] ?? '') : undefined}
                onSlugChange={g.hasInput ? (v) => set(g.hasInput!.key, v) : undefined}
                available={hasHardeningPro}
                homeUrl={homeUrl}
              />
            ))}
          </div>
        </section>
      </div>
    </div>
  );
}
