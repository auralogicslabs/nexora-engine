import { useQuery } from '@tanstack/react-query';
import {
  Puzzle, CheckCircle2, Download, Plus, ExternalLink,
  Sparkles, Box, Gauge, Image as ImageIcon,
} from 'lucide-react';
import PageHeader from '../components/ui/PageHeader';
import Spinner from '../components/ui/Spinner';
import { api } from '../lib/api';

type Addon = {
  slug: string;
  file: string;
  name: string;
  tagline: string;
  description: string;
  benefit: string;
  status: 'active' | 'installed' | 'not-installed' | 'coming-soon';
  badge?: 'recommended' | 'coming-soon' | '';
  icon_dashicon?: string;
  version?: string;
  wp_org_slug?: string;
  settings_slug?: string;
  activate_url?: string;
  install_url?: string;
  settings_url?: string;
};

const STATUS_TONE: Record<string, { bg: string; fg: string; border: string }> = {
  active:          { bg: 'rgb(22 163 74 / 0.10)', fg: '#16A34A', border: 'rgb(22 163 74 / 0.10)' },
  installed:       { bg: 'rgb(2 82 250 / 0.10)', fg: 'var(--np-brand-primary)', border: 'rgb(2 82 250 / 0.10)' },
  'not-installed': { bg: 'var(--np-bg-subtle)', fg: 'var(--np-text-muted)', border: 'var(--np-border)' },
  'coming-soon':   { bg: 'rgb(243 154 9 / 0.10)', fg: '#F39A09', border: 'rgb(243 154 9 / 0.10)' },
};

function pickIcon(addon: Addon): React.FC<any> {
  // Map dashicons-ish hints to lucide; fallback to a generic box.
  const d = (addon.icon_dashicon ?? '').toLowerCase();
  if (d.includes('image') || addon.slug.includes('media')) return ImageIcon;
  if (d.includes('chart') || d.includes('performance') || addon.slug.includes('pulse')) return Gauge;
  return Box;
}

function AddonCard({ addon }: { addon: Addon }) {
  const Icon = pickIcon(addon);
  const tone = STATUS_TONE[addon.status] ?? STATUS_TONE['not-installed'];

  return (
    <div className="np-card overflow-hidden flex flex-col">
      {/* Accent bar */}
      <div
        className="h-1 w-full"
        style={{ background: addon.status === 'active' ? 'var(--np-brand-primary)' : tone.border }}
      />

      <div className="p-5 flex-1 flex flex-col">
        <div className="flex items-start gap-3 mb-3">
          <div
            className="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0"
            style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
          >
            <Icon className="w-5 h-5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5 flex-wrap">
              <h3 className="text-sm font-bold text-[color:var(--np-text)] leading-tight">{addon.name}</h3>
              {addon.badge === 'recommended' && (
                <span className="np-badge text-[10px]" style={{ background: '#DBEAFE', color: 'var(--np-brand-primary)' }}>
                  Recommended
                </span>
              )}
              {addon.badge === 'coming-soon' && (
                <span className="np-badge text-[10px]" style={{ background: 'rgb(243 154 9 / 0.10)', color: '#F39A09' }}>
                  Coming Soon
                </span>
              )}
            </div>
            {addon.tagline && (
              <p className="text-[11px] text-[color:var(--np-text-muted)] mt-0.5">{addon.tagline}</p>
            )}
          </div>
        </div>

        <p className="text-xs text-[color:var(--np-text-muted)] leading-relaxed flex-1">{addon.description}</p>

        {addon.benefit && (
          <div
            className="flex gap-2 items-start rounded-xl p-3 mt-3 text-[11px] leading-snug"
            style={{ background: 'var(--np-bg-subtle)', color: 'var(--np-text-secondary)' }}
          >
            <Sparkles className="w-3 h-3 flex-shrink-0 mt-0.5" style={{ color: '#0252FA' }} />
            <span>{addon.benefit}</span>
          </div>
        )}

        {/* Footer */}
        <div className="flex items-center justify-between gap-2 mt-4 pt-3 border-t" style={{ borderColor: 'var(--np-border)' }}>
          <div className="flex items-center gap-2 min-w-0">
            {addon.status === 'active' && (
              <span
                className="text-[10px] font-bold px-2 py-0.5 rounded-full inline-flex items-center gap-1"
                style={{ background: tone.bg, color: tone.fg }}
              >
                <CheckCircle2 className="w-3 h-3" />
                Active
              </span>
            )}
            {addon.status === 'installed' && (
              <span
                className="text-[10px] font-bold px-2 py-0.5 rounded-full inline-flex items-center gap-1"
                style={{ background: tone.bg, color: tone.fg }}
              >
                <Download className="w-3 h-3" />
                Installed
              </span>
            )}
            {addon.version && addon.status !== 'coming-soon' && (
              <span className="text-[10px] text-[color:var(--np-text-muted)] font-mono">v{addon.version}</span>
            )}
          </div>

          <div className="flex-shrink-0">
            {addon.status === 'active' && addon.settings_url && (
              <a href={addon.settings_url} className="np-btn-secondary text-[11px]">
                Open settings
                <ExternalLink className="w-3 h-3" />
              </a>
            )}
            {addon.status === 'installed' && addon.activate_url && (
              <a href={addon.activate_url} className="np-btn-primary text-[11px]">
                <CheckCircle2 className="w-3 h-3" />
                Activate
              </a>
            )}
            {addon.status === 'not-installed' && addon.install_url && (
              <a href={addon.install_url} className="np-btn-primary text-[11px]">
                <Download className="w-3 h-3" />
                Install
              </a>
            )}
            {addon.status === 'not-installed' && !addon.install_url && (
              <button
                type="button"
                disabled
                className="np-btn-secondary text-[11px] opacity-60 cursor-not-allowed"
                title="Will be available on WordPress.org soon"
              >
                Coming to WP.org
              </button>
            )}
            {addon.status === 'coming-soon' && (
              <button type="button" disabled className="np-btn-secondary text-[11px] opacity-60 cursor-not-allowed">
                Coming Soon
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default function Addons() {
  const { data, isLoading } = useQuery({
    queryKey: ['addons'],
    queryFn: () => api.get<{ addons: Addon[] }>('addons'),
  });

  if (isLoading) return <Spinner label="Loading ecosystem…" />;
  const addons = data?.addons ?? [];
  const activeCount = addons.filter((a) => a.status === 'active').length;

  return (
    <div>
      <PageHeader
        title="Nexora Ecosystem"
        subtitle="Official addons that extend your static infrastructure. Each addon is built by Auralogics Labs and integrates directly with Nexora Engine."
        icon={Puzzle}
        actions={
          activeCount > 0 ? (
            <span
              className="text-xs font-bold px-3 py-1.5 rounded-full inline-flex items-center gap-1.5"
              style={{ background: 'rgb(22 163 74 / 0.10)', color: '#16A34A' }}
            >
              <CheckCircle2 className="w-3.5 h-3.5" />
              {activeCount} addon{activeCount === 1 ? '' : 's'} connected
            </span>
          ) : undefined
        }
      />

      <div className="p-6 space-y-5">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {addons.map((a) => (
            <AddonCard key={a.slug} addon={a} />
          ))}

          {/* "More coming" placeholder */}
          <div
            className="np-card p-6 flex flex-col items-center justify-center text-center"
            style={{
              background: 'var(--np-bg-subtle)',
              borderStyle: 'dashed',
            }}
          >
            <Plus className="w-6 h-6 text-[color:var(--np-text-muted)] mb-2" />
            <p className="text-sm font-bold text-[color:var(--np-text)]">More addons coming</p>
            <p className="text-xs text-[color:var(--np-text-muted)] mt-1 leading-relaxed">
              More official Nexora addons are in development. Check back soon.
            </p>
          </div>
        </div>

        {/* About */}
        <div
          className="np-card p-5 flex gap-4 items-start"
          style={{ background: 'var(--np-bg-subtle)', border: '1px solid var(--np-border)' }}
        >
          <div
            className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0"
            style={{ background: 'var(--np-bg-subtle)', border: '1px solid rgb(2 82 250 / 0.10)', boxShadow: 'inset 0 0 0 1px rgb(2 82 250 / 0.10)' }}
          >
            <Sparkles className="w-5 h-5" style={{ color: 'var(--np-brand-primary)' }} strokeWidth={2.2} />
          </div>
          <div className="min-w-0">
            <p className="text-sm font-bold text-[color:var(--np-text)]">Built to work together</p>
            <p className="text-xs text-[color:var(--np-text-muted)] mt-1 leading-relaxed">
              Each Nexora addon is independently useful but designed to unlock deeper integration when paired with Nexora Engine.
              They share a common intelligence layer — so image optimisation, analytics, and static delivery are aware of each other without extra configuration.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
