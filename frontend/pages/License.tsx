import { useQuery } from '@tanstack/react-query';
import {
  KeyRound, CheckCircle2, ExternalLink, Sparkles, Globe2,
  Calendar, Lock, Server, Mail, User as UserIcon, AlertTriangle, RefreshCw, Download,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import { api } from '../lib/api';

type LicenseInfo = {
  tier?: string;
  status?: string;
  is_active?: boolean;
  dev_override?: boolean;
  provider?: string;
  user_name?: string;
  user_email?: string;
  plan_title?: string;
  expiry?: string;
  expiry_ts?: number;
  site_count?: number;
  quota?: number;
  account_url: string;
  upgrade_url: string;
  days_left?: number | null;
  is_lifetime?: boolean;
  validity: 'valid' | 'warning' | 'expired' | 'lifetime' | 'free' | 'none';
  just_activated?: boolean;
};

const VALIDITY_TONE = {
  valid:    { bg: 'rgb(22 163 74 / 0.10)', fg: '#16A34A', label: 'Active' },
  warning:  { bg: 'rgb(243 154 9 / 0.10)', fg: '#F39A09', label: 'Expires soon' },
  expired:  { bg: 'rgb(226 75 74 / 0.10)', fg: '#E24B4A', label: 'Expired' },
  lifetime: { bg: 'rgb(2 82 250 / 0.10)', fg: 'var(--np-brand-primary)', label: 'Lifetime' },
  // Free installs hold no license at all. "No expiry" implied a perpetual
  // entitlement, so the free tier gets its own, unambiguous label.
  free:     { bg: 'var(--np-bg-subtle)', fg: 'var(--np-text-muted)', label: 'No license required' },
  none:     { bg: 'var(--np-bg-subtle)', fg: 'var(--np-text-muted)', label: 'No expiry' },
} as const;

function Row({ icon: Icon, label, value }: { icon: React.FC<any>; label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-3 py-3 border-b last:border-0" style={{ borderColor: 'var(--np-border)' }}>
      <span className="text-xs text-[color:var(--np-text-muted)] inline-flex items-center gap-2">
        <Icon className="w-3.5 h-3.5" />
        {label}
      </span>
      <span className="text-sm font-semibold text-[color:var(--np-text)] text-right">{value}</span>
    </div>
  );
}

export default function License() {
  const { data, isLoading } = useQuery({
    queryKey: ['license'],
    queryFn: () => api.get<LicenseInfo>('license'),
  });

  if (isLoading || !data) return <Spinner label="Loading license…" />;

  const tier = (data.tier ?? 'free').toLowerCase();
  const isPaid = ['pro', 'agency', 'enterprise', 'cloud'].includes(tier);
  const tierLabel = isPaid ? 'Pro' : 'Free';
  const tone = VALIDITY_TONE[data.validity] ?? VALIDITY_TONE.none;

  return (
    <div>
      <PageHeader
        title="License & Updates"
        subtitle="Your active infrastructure tier and plugin release details."
        icon={KeyRound}
        actions={
          <span
            className="text-[11px] font-bold px-2.5 py-1 rounded-full"
            style={{ background: 'var(--np-bg-subtle)', color: 'var(--np-text-secondary)' }}
          >
            v{window.NexoraEngine?.version ?? ''} Stable
          </span>
        }
      />

      <div className="p-6 space-y-5">
        {/* Just-activated banner */}
        {data.just_activated && (
          <div
            className="rounded-2xl p-4 flex items-start gap-3"
            style={{ background: 'var(--np-bg-card)', border: '1px solid #86EFAC' }}
          >
            <CheckCircle2 className="w-5 h-5 flex-shrink-0 mt-0.5" style={{ color: '#16A34A' }} />
            <div className="text-sm" style={{ color: '#16A34A' }}>
              <strong>Nexora Engine Pro activated!</strong> Your Pro features are now unlocked. Welcome aboard.
            </div>
          </div>
        )}

        {/* Overview band */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div className="np-card p-4">
            <p className="text-[11px] uppercase tracking-wide text-[color:var(--np-text-muted)] font-semibold">Plan</p>
            <div className="flex items-center gap-2 mt-1">
              <span
                className="text-xs font-bold px-2 py-0.5 rounded-full"
                style={{
                  background: isPaid ? 'var(--np-brand-primary)' : '#E5E7EB',
                  color: isPaid ? '#FFFFFF' : '#374151',
                }}
              >
                {tierLabel.toUpperCase()}
              </span>
              {data.dev_override && (
                <span
                  className="text-[10px] font-bold px-1.5 py-0.5 rounded"
                  style={{ background: 'rgb(243 154 9 / 0.10)', color: '#F39A09' }}
                >
                  DEV
                </span>
              )}
            </div>
          </div>

          <div className="np-card p-4">
            <p className="text-[11px] uppercase tracking-wide text-[color:var(--np-text-muted)] font-semibold">License Status</p>
            <span
              className="text-xs font-bold px-2 py-0.5 rounded-full inline-block mt-1"
              style={{ background: tone.bg, color: tone.fg }}
            >
              {tone.label}
            </span>
          </div>

          <div className="np-card p-4">
            <p className="text-[11px] uppercase tracking-wide text-[color:var(--np-text-muted)] font-semibold">Sites</p>
            <p className="text-sm font-bold text-[color:var(--np-text)] mt-1">
              {data.quota && data.quota > 0
                ? `${data.site_count ?? 0} / ${data.quota}`
                : (isPaid ? 'Unlimited' : 'Single site')}
            </p>
          </div>

          <div className="np-card p-4">
            <p className="text-[11px] uppercase tracking-wide text-[color:var(--np-text-muted)] font-semibold">Update Channel</p>
            <p className="text-sm font-bold text-[color:var(--np-text)] mt-1">{isPaid ? tierLabel : 'Free'}</p>
          </div>
        </div>

        {/* License card */}
        <div className="np-card p-5">
          <div className="flex items-start gap-3 mb-4">
            <div
              className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0"
              style={{
                background: isPaid
                  ? 'var(--np-bg-subtle)'
                  : 'linear-gradient(135deg, #94A3B8 0%, #475569 100%)',
              }}
            >
              {isPaid
                ? <CheckCircle2 className="w-5 h-5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
                : <KeyRound className="w-5 h-5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />}
            </div>
            <div className="min-w-0 flex-1">
              <h3 className="text-sm font-bold text-[color:var(--np-text)]">Nexora Engine License</h3>
              <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5">
                {isPaid
                  ? `Your Nexora Engine ${tierLabel} subscription details and account.`
                  : 'You are running the free tier. Upgrade to unlock SEO Reports, the Redirect Manager, and Security hardening.'}
              </p>
            </div>
            {isPaid ? (
              <a href={data.account_url} className="np-btn-secondary text-xs flex-shrink-0">
                <ExternalLink className="w-3.5 h-3.5" />
                Manage plan
              </a>
            ) : (
              <a href={data.upgrade_url} target="_blank" rel="noopener noreferrer" className="np-btn-primary text-xs flex-shrink-0">
                <Sparkles className="w-3.5 h-3.5" />
                Upgrade to Pro
              </a>
            )}
          </div>

          {isPaid ? (
            <div className="space-y-0">
              {data.user_name && <Row icon={UserIcon} label="Account" value={data.user_name} />}
              {data.user_email && (
                <Row
                  icon={Mail}
                  label="Email"
                  value={
                    <a href={`mailto:${data.user_email}`} className="text-[color:var(--np-brand-primary)] hover:underline">
                      {data.user_email}
                    </a>
                  }
                />
              )}
              {data.plan_title && <Row icon={Sparkles} label="Plan" value={data.plan_title} />}
              <Row
                icon={Calendar}
                label={data.is_lifetime || data.validity === 'free' ? 'License' : 'Expires'}
                value={
                  data.validity === 'free'
                    ? 'Not required on the free tier'
                    : data.is_lifetime
                      ? 'Lifetime'
                      : data.expiry
                        ? `${data.expiry}${data.days_left != null ? ` · ${data.days_left} days` : ''}`
                        : '—'
                }
              />
              <Row icon={Globe2} label="Sites" value={`${data.site_count ?? 0}${data.quota && data.quota > 0 ? ` / ${data.quota}` : ''}`} />
              {data.provider && <Row icon={Server} label="Provider" value={data.provider} />}

              {/* Manual-download fallback for locked hosts where the automatic
                  Freemius updater can't write to the plugins directory. */}
              <div
                className="mt-3 rounded-xl p-3 flex gap-2 items-start"
                style={{ background: 'var(--np-bg-subtle)' }}
              >
                <Download className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: 'var(--np-brand-primary)' }} />
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-bold text-[color:var(--np-text)]">Download Pro / manual install</p>
                  <p className="text-[11px] text-[color:var(--np-text-muted)] mt-0.5 leading-snug">
                    If the automatic update doesn&apos;t work on your server, download the Pro
                    build from your account and upload it via FTP.
                  </p>
                </div>
                <a href={data.account_url} className="np-btn-secondary text-xs flex-shrink-0">
                  <ExternalLink className="w-3.5 h-3.5" />
                  Download
                </a>
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mt-2">
              {[
                { icon: CheckCircle2, label: 'AI SEO Report', desc: 'Full audit + recommendations per page.' },
                { icon: Lock, label: 'Security Hardening', desc: 'Login rename, REST tightening, rate limit.' },
                { icon: Globe2, label: 'Redirect Manager', desc: '301/302 rules with chain detection.' },
              ].map((f) => (
                <div
                  key={f.label}
                  className="rounded-xl p-3 flex gap-2 items-start"
                  style={{ background: 'var(--np-bg-subtle)' }}
                >
                  <f.icon className="w-4 h-4 flex-shrink-0 mt-0.5" style={{ color: '#0252FA' }} />
                  <div>
                    <p className="text-xs font-bold text-[color:var(--np-text)]">{f.label}</p>
                    <p className="text-[11px] text-[color:var(--np-text-muted)] mt-0.5 leading-snug">{f.desc}</p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Expiry warning */}
        {data.validity === 'warning' && (
          <div
            className="rounded-xl p-4 flex gap-3 items-start"
            style={{ background: 'rgb(243 154 9 / 0.10)', border: '1px solid #FCD34D' }}
          >
            <AlertTriangle className="w-5 h-5 flex-shrink-0 mt-0.5" style={{ color: '#F39A09' }} />
            <div className="flex-1">
              <p className="text-sm font-bold" style={{ color: '#F39A09' }}>License expires in {data.days_left} days</p>
              <p className="text-xs mt-0.5" style={{ color: '#F39A09' }}>Renew now to avoid losing Pro feature access.</p>
            </div>
            <a href={data.account_url} className="np-btn-primary text-xs flex-shrink-0">
              <RefreshCw className="w-3.5 h-3.5" />
              Renew
            </a>
          </div>
        )}

        {data.validity === 'expired' && (
          <div
            className="rounded-xl p-4 flex gap-3 items-start"
            style={{ background: 'rgb(226 75 74 / 0.10)', border: '1px solid #FCA5A5' }}
          >
            <AlertTriangle className="w-5 h-5 flex-shrink-0 mt-0.5" style={{ color: '#E24B4A' }} />
            <div className="flex-1">
              <p className="text-sm font-bold" style={{ color: '#E24B4A' }}>License expired</p>
              <p className="text-xs mt-0.5" style={{ color: '#E24B4A' }}>Pro features remain enabled for a grace period — renew to keep them.</p>
            </div>
            <a href={data.account_url} className="np-btn-primary text-xs flex-shrink-0">
              <RefreshCw className="w-3.5 h-3.5" />
              Renew now
            </a>
          </div>
        )}
      </div>
    </div>
  );
}
