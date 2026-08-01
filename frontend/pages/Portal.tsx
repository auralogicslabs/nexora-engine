import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Globe2, CheckCircle2, ExternalLink, Cloud, RefreshCw, Lock,
  Sparkles, LineChart, Network, RotateCw, FileText, Bell,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import { api } from '../lib/api';
import { useStore } from '../lib/store';

type PortalState = {
  connected: boolean;
  connected_at: string | null;
  connected_human: string;
  site_id: string;
  has_key: boolean;
  key_masked: string;
  has_token: boolean;
  token_masked: string;
  portal_url: string;
  connect_url: string;
  is_pro: boolean;
  upgrade_url: string;
};

const CAPABILITIES = [
  { icon: LineChart, label: 'Centralized performance monitoring' },
  { icon: Network,   label: 'Multi-site infrastructure map' },
  { icon: RotateCw,  label: 'Remote cache invalidation' },
  { icon: FileText,  label: 'Aggregated infrastructure reports' },
  { icon: Bell,      label: 'Score regression alerts' },
];

function InfoTile({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="rounded-xl p-3" style={{ background: 'var(--np-bg-subtle)' }}>
      <p className="text-[10px] uppercase tracking-wide text-[color:var(--np-text-muted)] font-bold mb-1">
        {label}
      </p>
      <code className="text-xs text-[color:var(--np-text)] font-mono break-all">{value}</code>
    </div>
  );
}

export default function Portal() {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);
  const [keyInput, setKeyInput] = useState('');
  const [showToken, setShowToken] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['portal'],
    queryFn: () => api.get<PortalState>('portal'),
  });

  const connect = useMutation({
    mutationFn: (key: string) => api.post<PortalState & { message?: string }>('portal/connect', { key }),
    onSuccess: (r) => {
      pushToast('success', r?.message ?? 'Site connected to Auralogics Portal');
      setKeyInput('');
      qc.invalidateQueries({ queryKey: ['portal'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Connection failed'),
  });

  const disconnect = useMutation({
    mutationFn: () => api.post<PortalState & { message?: string }>('portal/disconnect'),
    onSuccess: (r) => {
      pushToast('success', r?.message ?? 'Site disconnected from portal');
      qc.invalidateQueries({ queryKey: ['portal'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Disconnect failed'),
  });

  const regen = useMutation({
    mutationFn: () => api.post<PortalState & { message?: string }>('portal/regenerate-token'),
    onSuccess: (r) => {
      pushToast('success', r?.message ?? 'Site token regenerated');
      qc.invalidateQueries({ queryKey: ['portal'] });
    },
    onError: (e: any) => pushToast('error', e?.message ?? 'Failed to regenerate token'),
  });

  if (isLoading || !data) return <Spinner label="Loading portal…" />;

  const isPro = data.is_pro;
  const connected = data.connected;

  return (
    <div>
      <PageHeader
        title="Auralogics Portal"
        subtitle="Connect this site to the Auralogics cloud for centralized infrastructure management."
        icon={Cloud}
        actions={
          <span
            className="text-[11px] font-bold uppercase tracking-wide px-3 py-1.5 rounded-full inline-flex items-center gap-1.5"
            style={
              connected
                ? { background: 'rgb(22 163 74 / 0.10)', color: '#16A34A' }
                : { background: '#F1F5F9', color: 'var(--np-text-secondary)' }
            }
          >
            <span
              className="w-2 h-2 rounded-full"
              style={{ background: connected ? '#16A34A' : '#94A3B8' }}
            />
            {connected ? 'Connected' : 'Not connected'}
          </span>
        }
      />

      <div className="p-6 space-y-5">
        {/* For plugin license activation, see License page note */}
        <p className="text-xs text-[color:var(--np-text-muted)]">
          For plugin license activation, see{' '}
          <a href="#/updates" className="text-[color:var(--np-brand-primary)] font-semibold hover:underline">
            License & Updates →
          </a>
        </p>

        {/* Free-tier upgrade banner */}
        {!isPro && (
          <div
            className="np-card p-5 flex items-center gap-4 flex-wrap"
            style={{ borderLeft: '3px solid var(--np-brand-primary)' }}
          >
            <div
              className="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0"
              style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
            >
              <Cloud className="w-6 h-6" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold text-[color:var(--np-text)]">
                Portal Connectivity requires Nexora Engine Pro
              </p>
              <p className="text-xs text-[color:var(--np-text-muted)] mt-0.5 leading-snug">
                Upgrade to link this site to the Auralogics cloud dashboard and access centralized
                reporting, multi-site intelligence, and remote management.
              </p>
            </div>
            <a
              href={`${data.portal_url}/upgrade`}
              target="_blank"
              rel="noreferrer"
              className="np-btn-primary text-xs flex-shrink-0"
            >
              <Sparkles className="w-3.5 h-3.5" />
              Upgrade to Pro
            </a>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          {/* Connection card */}
          <div className="np-card p-5">
            <div className="flex items-center gap-2.5 mb-4">
              <div
                className="w-9 h-9 rounded-xl flex items-center justify-center"
                style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
              >
                <Network className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
              </div>
              <h3 className="text-sm font-bold text-[color:var(--np-text)]">Site Connection</h3>
            </div>

            {connected ? (
              <div>
                <div
                  className="flex items-center gap-2 text-sm font-bold mb-4"
                  style={{ color: '#16A34A' }}
                >
                  <CheckCircle2 className="w-4 h-4" />
                  Site linked to Auralogics Portal
                </div>

                <div className="space-y-2 mb-4">
                  {data.connected_human && (
                    <InfoTile label="Connected" value={data.connected_human} />
                  )}
                  {data.site_id && <InfoTile label="Site ID" value={data.site_id} />}
                  {data.has_key && <InfoTile label="Portal Key" value={data.key_masked} />}
                </div>

                {data.has_token && (
                  <details className="mb-4 group" open={showToken}>
                    <summary
                      onClick={(e) => { e.preventDefault(); setShowToken((s) => !s); }}
                      className="text-[11px] font-bold uppercase tracking-wide text-[color:var(--np-text-muted)] cursor-pointer flex items-center gap-1.5 list-none"
                    >
                      <Lock className="w-3 h-3" />
                      API Credential
                    </summary>
                    <div
                      className="mt-2 rounded-xl p-3"
                      style={{ background: 'var(--np-bg-subtle)' }}
                    >
                      <p className="text-[10px] uppercase tracking-wide text-[color:var(--np-text-muted)] font-bold mb-1.5">
                        Site Token{' '}
                        <span className="normal-case text-[10px] font-medium text-[color:var(--np-text-muted)] tracking-normal">
                          — used by the portal to authenticate REST API calls back to this site
                        </span>
                      </p>
                      <div className="flex items-center gap-2 flex-wrap">
                        <code className="text-xs font-mono text-[color:var(--np-text)] flex-1 min-w-0 break-all">
                          {data.token_masked}
                        </code>
                        <button
                          type="button"
                          onClick={async () => {
                            const ok = await useStore.getState().askConfirm({
                              title: 'Regenerate portal site token?',
                              message: 'The old token stops working immediately. Any existing portal connection must be re-established with the new token.',
                              confirmLabel: 'Regenerate token',
                              tone: 'warning',
                              icon: 'refresh',
                            });
                            if (ok) regen.mutate();
                          }}
                          disabled={regen.isPending}
                          className="np-btn-secondary text-[11px]"
                        >
                          <RefreshCw className={`w-3 h-3 ${regen.isPending ? 'animate-spin' : ''}`} />
                          Regenerate
                        </button>
                      </div>
                    </div>
                  </details>
                )}

                <div className="flex items-center gap-2">
                  <a
                    href={data.portal_url}
                    target="_blank"
                    rel="noreferrer"
                    className="np-btn-primary text-xs"
                  >
                    <ExternalLink className="w-3.5 h-3.5" />
                    Open Portal Dashboard
                  </a>
                  <button
                    type="button"
                    onClick={async () => {
                      const ok = await useStore.getState().askConfirm({
                        title: 'Disconnect from Auralogics Portal?',
                        message: 'This site stops reporting to the multi-site command center. Reconnect any time with the same key — no data is lost.',
                        confirmLabel: 'Disconnect',
                        tone: 'warning',
                        icon: 'power',
                      });
                      if (ok) disconnect.mutate();
                    }}
                    disabled={disconnect.isPending || !isPro}
                    className="np-btn-secondary text-xs"
                    style={{ opacity: !isPro ? 0.5 : 1 }}
                  >
                    {disconnect.isPending ? 'Disconnecting…' : 'Disconnect'}
                  </button>
                </div>
              </div>
            ) : (
              <div>
                <p className="text-xs text-[color:var(--np-text-muted)] leading-relaxed mb-4">
                  Connect this site to the <strong>Auralogics Portal</strong> to access centralized
                  infrastructure monitoring, cross-site reporting, and remote cache management.
                </p>

                {/* Primary: silent connect via portal URL */}
                <a
                  href={isPro && data.connect_url ? data.connect_url : undefined}
                  target="_blank"
                  rel="noreferrer"
                  onClick={(e) => { if (!isPro) e.preventDefault(); }}
                  className="np-btn-primary w-full justify-center text-xs mb-3"
                  style={{ opacity: !isPro ? 0.5 : 1, cursor: !isPro ? 'not-allowed' : undefined }}
                  aria-disabled={!isPro}
                >
                  <Cloud className="w-3.5 h-3.5" />
                  Connect via Portal
                </a>

                <div className="flex items-center gap-3 my-4">
                  <div className="flex-1 h-px" style={{ background: 'var(--np-border)' }} />
                  <span className="text-[10px] uppercase tracking-wider text-[color:var(--np-text-muted)] font-semibold">
                    or connect manually
                  </span>
                  <div className="flex-1 h-px" style={{ background: 'var(--np-border)' }} />
                </div>

                <label className="block mb-3">
                  <span className="block text-[11px] font-bold uppercase tracking-wide text-[color:var(--np-text-muted)] mb-1.5">
                    Portal API Key
                  </span>
                  <input
                    type="password"
                    value={keyInput}
                    onChange={(e) => setKeyInput(e.target.value)}
                    placeholder="prtl_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                    className="np-input w-full"
                    disabled={!isPro}
                  />
                </label>
                <button
                  type="button"
                  onClick={() => {
                    const key = keyInput.trim();
                    if (!key) {
                      pushToast('error', 'Please enter your Portal API key.');
                      return;
                    }
                    if (!key.startsWith('prtl_')) {
                      pushToast('error', 'Invalid key format. Portal keys begin with prtl_');
                      return;
                    }
                    connect.mutate(key);
                  }}
                  disabled={!isPro || connect.isPending}
                  className="np-btn-secondary w-full justify-center text-xs"
                >
                  <Network className="w-3.5 h-3.5" />
                  {connect.isPending ? 'Connecting…' : 'Connect with Key'}
                </button>
                <p className="text-[11px] text-[color:var(--np-text-muted)] text-center mt-3">
                  Get a portal key at{' '}
                  <a
                    href={data.portal_url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-[color:var(--np-brand-primary)] font-semibold hover:underline"
                  >
                    auralogicslabs.com/portal
                  </a>
                </p>
              </div>
            )}
          </div>

          {/* Capabilities card */}
          <div className="np-card p-5">
            <div className="flex items-center gap-2.5 mb-4">
              <div
                className="w-9 h-9 rounded-xl flex items-center justify-center"
                style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
              >
                <Globe2 className="w-4 h-4" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
              </div>
              <h3 className="text-sm font-bold text-[color:var(--np-text)]">Portal Capabilities</h3>
            </div>

            <ul className="space-y-2.5">
              {CAPABILITIES.map((cap) => {
                const Icon = cap.icon;
                return (
                  <li
                    key={cap.label}
                    className="flex items-center gap-3"
                    style={{ opacity: isPro ? 1 : 0.55 }}
                  >
                    <Icon
                      className="w-4 h-4 flex-shrink-0"
                      style={{ color: 'var(--np-brand-primary)' }}
                    />
                    <span className="text-sm text-[color:var(--np-text)] flex-1">{cap.label}</span>
                    {isPro ? (
                      <CheckCircle2 className="w-4 h-4 flex-shrink-0" style={{ color: '#16A34A' }} />
                    ) : (
                      <span
                        className="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full"
                        style={{ background: '#F1F5F9', color: 'var(--np-text-secondary)' }}
                      >
                        Pro
                      </span>
                    )}
                  </li>
                );
              })}
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}
