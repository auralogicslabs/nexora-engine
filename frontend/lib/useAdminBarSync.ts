import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, SsgState } from './api';

/**
 * Keeps the WordPress admin bar's build-status node in step with the SPA.
 *
 * NEXENG_Admin::render_admin_bar_build_status() renders that node server-side
 * during page load, so it is a snapshot. Everything else here is a single-page
 * app: publishing a post or finishing a build never reloads the page, so the
 * bar kept advertising "Nexora: 2 updates ready" long after those pages had
 * been rebuilt, and the only way to correct it was a manual refresh.
 *
 * This reads the same ['ssg-state'] query the rail already polls, so React
 * Query serves it from cache and no extra request is made, then writes the
 * result straight into the existing DOM node.
 *
 * Label text comes from window.NexoraEngine.adminBarLabels, which PHP fills
 * from the same __() strings it used to render the node. Hardcoding English
 * here would have made the label snap out of the site's language the first
 * time it updated. It falls back to English if the payload is missing.
 *
 * Everything is defensive: the admin bar is absent for users without
 * manage_options and hidden entirely when Static Delivery is off, so every
 * lookup may legitimately return null. This only ever updates a node that is
 * already there — it never creates one.
 */
type AdminBarLabels = {
  ok: string;
  paused: string;
  building: string;
  buildingOf: string;
  pendingOne: string;
  pendingMany: string;
  refreshOne: string;
  refreshMany: string;
};

const FALLBACK: AdminBarLabels = {
  ok: 'Nexora: Static OK',
  paused: 'Nexora: Build Paused',
  building: 'Nexora: Building',
  buildingOf: 'Nexora: Building %1$d/%2$d',
  pendingOne: 'Nexora: %d update ready',
  pendingMany: 'Nexora: %d updates ready',
  refreshOne: 'Refresh %d changed page',
  refreshMany: 'Refresh %d changed pages',
};

/** Minimal sprintf for the %d / %1$d / %2$d that these strings use. */
function fmt(template: string, ...args: number[]): string {
  return template
    .replace(/%(\d+)\$d/g, (_m, i) => String(args[Number(i) - 1] ?? ''))
    .replace(/%d/g, () => String(args[0] ?? ''));
}
export function useAdminBarSync() {
  const { data: state } = useQuery({
    queryKey: ['ssg-state'],
    queryFn: () => api.get<SsgState>('ssg/state'),
    refetchInterval: (q) => {
      const s = q.state.data;
      if (s?.running) return 1500;
      if ((s?.pending_count ?? 0) > 0) return 2000;
      return 10_000;
    },
    refetchIntervalInBackground: true,
  });

  useEffect(() => {
    if (!state) return;

    const node = document.getElementById('wp-admin-bar-ncx-build-status');
    if (!node) return;

    // ssg/state already reports running as an effective value (the rail relies
    // on the same field); there is no separate done flag to subtract here.
    const running = !!state.running;
    const paused = !!state.paused;
    const pending = state.pending_count ?? 0;

    const L: AdminBarLabels = { ...FALLBACK, ...(window.NexoraEngine?.adminBarLabels ?? {}) };

    let label: string;
    let stateClass: 'is-paused' | 'is-running' | 'has-pending' | '' = '';

    if (paused) {
      label = L.paused;
      stateClass = 'is-paused';
    } else if (running) {
      const processed = state.processed ?? 0;
      const total = state.total ?? 0;
      label = total > 0 ? fmt(L.buildingOf, processed, total) : L.building;
      stateClass = 'is-running';
    } else if (pending > 0) {
      label = fmt(pending === 1 ? L.pendingOne : L.pendingMany, pending);
      stateClass = 'has-pending';
    } else {
      label = L.ok;
    }

    const labelEl = node.querySelector('.ab-label');
    if (labelEl && labelEl.textContent !== label) {
      labelEl.textContent = label;
    }

    // Only ever touch our own state classes. Assigning className outright also
    // removed the classes WordPress puts here itself - notably "menupop", which
    // is what keeps an admin-bar item's submenu collapsed until hover. Without
    // it the dropdown rendered inline, spilling "Refresh N changed pages" and
    // "Open Build Control" across the top of the page.
    node.classList.remove('is-paused', 'is-running', 'has-pending');
    if (stateClass) {
      node.classList.add(stateClass);
    }
    node.classList.add('ncx-adminbar-build');

    // The "Refresh N changed pages" child only exists while there is something
    // to refresh. Drop it once the queue drains rather than leaving a menu item
    // that would rebuild nothing.
    const refresh = document.getElementById('wp-admin-bar-ncx-build-refresh-pending');
    if (refresh) {
      const show = pending > 0 && !running && !paused;
      (refresh as HTMLElement).style.display = show ? '' : 'none';
      if (show) {
        const link = refresh.querySelector('a');
        if (link) {
          link.textContent = fmt(pending === 1 ? L.refreshOne : L.refreshMany, pending);
        }
      }
    }
  }, [state]);
}
