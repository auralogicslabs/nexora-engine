import React, { useState, useRef, useEffect } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import {
  LayoutDashboard, Cloud, Search, ShieldCheck, Shuffle,
  Globe2, Wrench, Settings as SettingsIcon, Puzzle, KeyRound,
  ChevronRight, Lock, BookOpen, LifeBuoy, ExternalLink, Sparkles,
  HelpCircle,
} from 'lucide-react';
import MirrorBuildControl from './MirrorBuildControl';
import { useAdminBarSync } from '../lib/useAdminBarSync';
import { can, Capability } from '../lib/api';

type NavLeaf = {
  label: string;
  slug: string;
  icon: React.FC<any>;
  /**
   * The Pro module this screen needs in order to show anything at all.
   * When the module is absent the screen has no content to render, so the
   * item is omitted from the nav rather than shown with a padlock — a lock
   * on a link that still works reads as broken, and a lock on a link to an
   * empty page is worse.
   *
   * Screens that own free functionality (Security, SEO Report) carry no cap:
   * they are always reachable, and point out their Pro parts in place.
   */
  cap?: Capability;
  /**
   * Internal: the route is handled inside the React SPA via hash routing.
   * External: the route is a legacy PHP page — clicking causes a full reload to admin.php?page=ncx-<slug>.
   */
  mode: 'internal' | 'external';
  /** Hash route used when mode === 'internal'. */
  to?: string;
};

const NAV_GROUPS: { label: string; items: NavLeaf[] }[] = [
  {
    label: 'Operate',
    items: [
      { label: 'Dashboard',      slug: 'dashboard', icon: LayoutDashboard, mode: 'internal', to: '/dashboard' },
      { label: 'Static Delivery', slug: 'headless',  icon: Cloud,           mode: 'internal', to: '/headless' },
    ],
  },
  {
    label: 'Validate',
    items: [
      { label: 'SEO Report', slug: 'seo-report', icon: Search, mode: 'internal', to: '/seo-report' },
    ],
  },
  {
    label: 'Protect',
    items: [
      { label: 'Security',         slug: 'security',  icon: ShieldCheck, mode: 'internal', to: '/security'  },
      { label: 'Redirect Manager', slug: 'redirects', icon: Shuffle,     mode: 'internal', to: '/redirects', cap: 'redirects' },
    ],
  },
  {
    label: 'Manage',
    items: [
      // Portal hidden for now — separate cloud feature, to be revisited later.
      { label: 'Tools',    slug: 'tools',    icon: Wrench,       mode: 'internal', to: '/tools' },
      { label: 'Settings', slug: 'settings', icon: SettingsIcon, mode: 'internal', to: '/settings' },
      { label: 'Addons',   slug: 'addons',   icon: Puzzle,       mode: 'internal', to: '/addons' },
      { label: 'License',  slug: 'updates',  icon: KeyRound,     mode: 'internal', to: '/updates' },
    ],
  },
];

function legacyHref(slug: string): string {
  const base = window.NexoraEngine?.adminUrl ?? '';
  return `${base}admin.php?page=ncx-${slug}`;
}

const RESOURCE_LINKS = [
  { icon: BookOpen, label: 'Documentation',     href: 'https://www.auralogicslabs.com/nexora-engine/docs' },
  { icon: LifeBuoy, label: 'Support',           href: 'https://www.auralogicslabs.com/nexora-engine/support' },
  { icon: Globe2,   label: 'auralogicslabs.com', href: 'https://auralogicslabs.com/products/nexora-engine' },
];

/**
 * A single muted link row inside the Resources popover. Brightens on hover so
 * it reads as interactive without competing with the primary nav.
 */
function ResourceLink({
  icon: Icon, label, href,
}: {
  icon: React.FC<any>;
  label: string;
  href: string;
}) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noreferrer"
      className="flex items-center gap-2.5 px-3 py-2 rounded-lg text-[12px] font-medium transition-colors group"
      style={{ color: 'var(--np-text-on-dark-muted)' }}
      onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(255,255,255,0.06)')}
      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
    >
      <Icon className="w-3.5 h-3.5 flex-shrink-0" />
      <span className="flex-1 truncate">{label}</span>
      <ExternalLink className="w-3 h-3 opacity-0 group-hover:opacity-60 transition-opacity flex-shrink-0" />
    </a>
  );
}

/**
 * Collapsed Resources control — a single compact "Help & Resources" row that
 * pops the three external links upward on click. Keeps the footer to one row so
 * the primary nav above never needs to scroll on laptop-height screens.
 */
function ResourcesPopover() {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    const onEsc = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onEsc);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onEsc);
    };
  }, [open]);

  return (
    <div ref={wrapRef} className="relative">
      {open && (
        <div
          className="absolute bottom-full left-0 right-0 mb-2 rounded-xl p-1.5 np-animate-fade-in"
          style={{
            background: 'var(--np-sidebar-dark-bg)',
            boxShadow: '0 -8px 24px rgb(0 0 0 / 0.35)',
            border: '1px solid var(--np-border-dark)',
          }}
        >
          {RESOURCE_LINKS.map((r) => (
            <ResourceLink key={r.label} {...r} />
          ))}
        </div>
      )}
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex items-center gap-2.5 w-full px-3 py-2 rounded-lg text-[12px] font-medium transition-colors"
        style={{ color: 'var(--np-text-on-dark-muted)' }}
        onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(255,255,255,0.06)')}
        onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
        aria-expanded={open}
      >
        <HelpCircle className="w-3.5 h-3.5 flex-shrink-0" />
        <span className="flex-1 text-left">Help &amp; Resources</span>
        <ChevronRight
          className="w-3 h-3 flex-shrink-0 transition-transform"
          style={{ transform: open ? 'rotate(-90deg)' : 'rotate(90deg)' }}
        />
      </button>
    </div>
  );
}

function NavRow({ item, active }: { item: NavLeaf; active: boolean }) {
  const Icon = item.icon;
  return (
    <span className={`np-nav-item ${active ? 'np-nav-item-active' : 'np-nav-item-inactive'} px-3`}>
      <Icon
        className={`flex-shrink-0 w-[17px] h-[17px] ${active ? 'scale-110' : ''}`}
        strokeWidth={active ? 2.5 : 2}
      />
      <span className="flex-1 truncate">{item.label}</span>
    </span>
  );
}

// Nav for THIS build: screens whose backing module was stripped are dropped
// entirely, and any group left empty by that goes with them so no orphan
// heading is rendered. Computed once at module scope — the capability map is
// baked into the page by PHP and cannot change while the SPA is mounted.
const navGroups = NAV_GROUPS
  .map((group) => ({
    ...group,
    items: group.items.filter((item) => !item.cap || can(item.cap)),
  }))
  .filter((group) => group.items.length > 0);

export default function Layout({ children }: { children: React.ReactNode }) {
  const ctx   = window.NexoraEngine!;
  const isPro = !!ctx?.isPro;
  const loc   = useLocation();

  // Must be called before the bare-mode return below — hooks cannot sit behind
  // a conditional. Running it during the wizard is wanted anyway: that is where
  // the first build happens and the admin bar counts down alongside it.
  useAdminBarSync();

  // Bare mode for the setup wizard — no sidebar, no rail, no brand chrome.
  // The wizard is a focused full-bleed experience and the regular navigation
  // would distract from the linear step flow.
  const isBare = loc.pathname.startsWith('/wizard');

  if (isBare) {
    return (
      <div
        style={{
          background: 'var(--np-bg-page)',
          minHeight: 'var(--ncx-panel-h)',
        }}
      >
        {children}
      </div>
    );
  }

  return (
    <div
      className="flex"
      style={{ background: 'var(--np-bg-page)', minHeight: 'var(--ncx-panel-h)' }}
    >
      {/* ── Sidebar (deep brand blue) ─────────────────────────── */}
      <aside
        className="flex-shrink-0 flex flex-col np-scrollbar-dark"
        style={{
          width: 'var(--np-sidebar-w)',
          height: 'var(--ncx-panel-h)',
          position: 'sticky',
          top: 0,
          overflowY: 'auto',
          background: 'var(--np-sidebar-dark-bg)',
          color: 'var(--np-text-on-dark)',
        }}
      >
        {/* Brand — deep-blue identity strip with white wordmark */}
        <div
          className="flex items-center gap-3 px-5 py-5 flex-shrink-0"
          style={{ borderBottom: '1px solid var(--np-border-dark)' }}
        >
          {/* Nexora brand icon — the actual brand mark (blue N) on a white
              rounded tile, so it matches the WordPress admin-menu icon and
              Nexora Pulse exactly. Using the real asset avoids the clipped,
              hand-traced SVG that looked cramped on the dark sidebar. */}
          <div
            className="w-9 h-9 flex items-center justify-center flex-shrink-0 overflow-hidden"
            style={{
              background: '#FFFFFF',
              boxShadow: '0 2px 10px rgb(2 82 250 / 0.45)',
              borderRadius: '8px',
            }}
          >
            <img
              src={`${ctx?.pluginUrl ?? ''}assets/img/nexora-icon.png`}
              alt="Nexora"
              width={26}
              height={26}
              className="w-[26px] h-[26px] object-contain"
            />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-baseline gap-1.5">
              <span className="text-base font-bold tracking-tight text-white">Nexora</span>
              <span className="text-base font-bold np-text-gradient tracking-tight">Engine</span>
            </div>
            <div className="flex items-center gap-1.5 mt-0.5">
              <span className="text-[10px] font-medium" style={{ color: 'var(--np-text-on-dark-muted)' }}>
                by Auralogics
              </span>
              {isPro ? (
                <span className="np-badge-pro text-[9px] px-1.5 py-px">PRO</span>
              ) : (
                <span
                  className="np-badge text-[9px] px-1.5 py-px"
                  style={{
                    background: 'rgba(255,255,255,0.08)',
                    color: 'var(--np-text-on-dark-muted)',
                    boxShadow: 'inset 0 0 0 1px rgba(255,255,255,0.10)',
                  }}
                >
                  FREE
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Nav — flex-1 + min-h-0 lets it absorb available height and scroll
            internally only if the screen is genuinely too short, while the
            Go Pro bar + Resources row below keep a fixed, minimal footprint. */}
        <nav className="flex-1 min-h-0 px-3 py-3 space-y-3 overflow-y-auto np-scrollbar-dark">
          {navGroups.map((group) => (
            <div key={group.label}>
              <p className="np-section-label-dark px-3 mb-1">{group.label}</p>
              <div className="space-y-0.5">
                {group.items.map((item) =>
                  item.mode === 'internal' ? (
                    <NavLink key={item.slug} to={item.to!} className="block">
                      {({ isActive }) => <NavRow item={item} active={isActive} />}
                    </NavLink>
                  ) : (
                    <a key={item.slug} href={legacyHref(item.slug)} className="block">
                      <NavRow item={item} active={false} />
                    </a>
                  ),
                )}
              </div>
            </div>
          ))}
        </nav>

        {/* ── Footer: slim Go Pro bar + collapsed Resources ──────────
            Both kept to a single row each so the nav above never has to
            scroll on laptop-height screens (the previous tall Go Pro card +
            5-row Resources block pushed the menu off-screen). */}
        <div
          className="flex-shrink-0 px-3 pt-2.5 pb-3 space-y-1.5"
          style={{ borderTop: '1px solid var(--np-border-dark)' }}
        >
          {/* Slim upgrade bar — free only */}
          {!isPro && (
            <a
              href={ctx.upgradeUrl}
              // Pricing lives on auralogicslabs.com, so this leaves WordPress.
              // Opening in place would drop an admin mid-task with only the back
              // button to return; the Dashboard and Redirects upgrade links
              // already opened a new tab, this just makes the rest agree.
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-2 w-full px-3 py-2 rounded-xl text-xs font-bold transition-transform hover:-translate-y-px"
              style={{
                background: 'linear-gradient(135deg, var(--np-brand-primary) 0%, var(--np-brand-primary-hover) 100%)',
                color: '#FFFFFF',
                boxShadow: '0 2px 8px rgb(2 82 250 / 0.35)',
              }}
            >
              <Sparkles className="w-3.5 h-3.5 flex-shrink-0" />
              <span className="flex-1 text-left">Go Pro</span>
              <ChevronRight className="w-3.5 h-3.5 flex-shrink-0" />
            </a>
          )}

          {/* Collapsed Resources — single row, pops links upward on click */}
          <ResourcesPopover />
        </div>
      </aside>

      {/* ── Main + persistent right rail ─────────────────────────
          Layout for "only inner content scrolls":
            – <main> is a horizontal flex of (content, right-rail).
            – The content column is a vertical scroll container fixed
              to the panel height. PageHeader inside it uses
              `position: sticky; top: 0` so the page title strip
              stays pinned while the body below scrolls.
            – The right rail (MirrorBuildControl) is its own sticky
              column at the parent level, so it never scrolls
              with the content body. */}
      <main
        className="flex-1 flex min-w-0 np-animate-fade-in"
        style={{ height: 'var(--ncx-panel-h)' }}
      >
        <div
          className="flex-1 min-w-0 np-scrollbar"
          style={{ height: 'var(--ncx-panel-h)', overflowY: 'auto', overflowX: 'hidden' }}
        >
          {children}
        </div>
        <aside
          className="flex-shrink-0 border-l"
          style={{
            width: 'var(--np-right-rail-w)',
            borderColor: 'var(--np-border)',
            background: 'var(--np-bg-page)',
            height: 'var(--ncx-panel-h)',
            position: 'sticky',
            top: 0,
            overflowY: 'auto',
          }}
        >
          <MirrorBuildControl currentPath={loc.pathname} />
        </aside>
      </main>
    </div>
  );
}
