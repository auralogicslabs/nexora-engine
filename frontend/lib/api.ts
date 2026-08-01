/**
 * Pro modules that ship as separate `__premium_only` files. Freemius removes
 * them from the WordPress.org build, so their presence is a fact about the
 * installed files — not about the licence.
 */
export type Capability =
  | 'autoRebuild'
  | 'seoPerPost'
  | 'hardeningPro'
  | 'stealthProxy'
  | 'scheduler'
  | 'redirects'
  | 'portal'
  | 'cdn'
  | 'whiteLabel'
  | 'multisite'
  | 'vitals'
  | 'pdfReport';

/**
 * True when the Pro module backing `name` is present in this build.
 *
 * Absent flag means absent module: a build that predates the capability map,
 * or a module that was stripped. Both are correctly treated as "cannot".
 */
export function can(name: Capability): boolean {
  return window.NexoraEngine?.can?.[name] === true;
}

declare global {
  interface Window {
    NexoraEngine?: {
      apiUrl: string;
      nonce: string;
      adminUrl: string;
      siteUrl: string;
      pluginUrl: string;
      version: string;
      installId: string;
      onboardingComplete: boolean;
      currentView: string;
      plan: string;
      /**
       * The licence tier. Use this ONLY for upgrade copy and pricing links.
       * Never gate a control on it — see `can` below.
       */
      isPro: boolean;
      /**
       * What this build can actually do. The Pro modules are stripped from the
       * WordPress.org download, so on a free build the code behind these flags
       * is absent, not merely locked. A control whose flag is false must render
       * as description, never as a disabled switch.
       */
      can?: Partial<Record<Capability, boolean>>;
      upgradeUrl: string;
      siblings: string[];
      user?: { id: number; name: string; email: string };
      /** Translated admin-bar build-status strings; see useAdminBarSync. */
      adminBarLabels?: {
        ok: string;
        paused: string;
        building: string;
        buildingOf: string;
        pendingOne: string;
        pendingMany: string;
        refreshOne: string;
        refreshMany: string;
      };
    };
  }
}

const ctx = () => window.NexoraEngine!;

function buildUrl(path: string): string {
  const base = ctx().apiUrl.replace(/\/$/, '');
  const suffix = path.startsWith('/') ? path : `/${path}`;
  return `${base}${suffix}`;
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(buildUrl(path), {
    credentials: 'same-origin',
    ...init,
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': ctx().nonce,
      ...(init.headers ?? {}),
    },
  });

  if (!res.ok) {
    let message = `Request failed (${res.status})`;
    try {
      const body = await res.json();
      if (body?.message) message = body.message;
    } catch {
      /* swallow */
    }
    throw new Error(message);
  }

  if (res.status === 204) return undefined as T;
  const json = await res.json();

  // NEXENG_REST consistently wraps payloads as `{ success: true, data: ... }`.
  // Unwrap here so call sites work with the payload directly.
  if (json && typeof json === 'object' && 'success' in json && 'data' in json) {
    return (json as { data: T }).data;
  }
  return json as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined }),
  del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};

export type SsgPendingItem = {
  id: number;
  title: string;
  post_type: string;
  permalink: string;
  edit_url: string;
  reason: string;
  queued_iso: string | null;
};

export type SsgActivityItem = {
  id: number;
  title: string;
  permalink: string;
  generated_iso: string;
};

export type SsgErrorItem = {
  post_id: number;
  title: string;
  url: string;
  code: string;
  message: string;
  stage: string;
  ts_iso: string | null;
};

export type SsgState = {
  enabled: boolean;
  running: boolean;
  paused: boolean;
  pending_count: number;
  processed: number;
  total: number;
  percent: number;
  last_write: string | null;
  static_files: number;
  static_bytes: number;
  auto_rebuild: boolean;
  auto_rebuild_effective?: boolean;
  is_pro?: boolean;
  archives_missing: boolean;
  archives_missing_count?: number;
  pending_preview?: SsgPendingItem[];
  activity?: SsgActivityItem[];
  // Engine health — populated when captures hit transient server errors.
  recent_errors?: SsgErrorItem[];
  failed_count?: number;
  degraded?: boolean;
  degraded_reason?: 'transient_http_streak' | 'fpm_worker_exhausted' | 'recovered' | '';
  curl28_count?: number;
  // Server-protection signals — emitted by the auto-rebuild driver so the
  // rail can explain *why* a build hasn't auto-started or has slowed down.
  auto_cap?: number;
  auto_cap_exceeded?: boolean;
  throttled?: 'rate_limit' | 'host_stressed' | '';
  skipped?: 'capture_lock_held' | '';
};

export type DashboardSummary = {
  plan: string;
  is_pro: boolean;
  install_id: string;
  onboarding_complete: boolean;
  ssg: SsgState;
  pages_indexed?: number;
  issues_open?: number;
  redirects?: number;
};

export type SettingsPayload = Record<string, unknown>;

// Mirrors NEXENG_Stealth_Audit::run() — the measurable Ghost Protocol score.
export type StealthCheck = {
  id: string;
  label: string;
  hidden: boolean;
  detail: string;
  weight: number;
  severity: 'high' | 'medium' | 'low';
  /** True when only Pro's Advanced Ghost Protocol can mask this signal. */
  pro_only?: boolean;
};

export type StealthAudit = {
  score: number;
  grade: string;
  exposed: number;
  hidden: number;
  total: number;
  checks: StealthCheck[];
  verdict: string;
  generated: number;
};

// Mirrors NEXENG_Dashboard::get_stats() — the legacy class is preserved and the
// REST controller proxies it as-is, so adding/removing fields here just
// requires keeping the PHP shape stable.
export type DashboardStats = {
  hit_ratio: number;
  traffic_total_24h: number;
  last_hit_at: number | null;
  static_files_count: number;
  static_total_bytes: number;
  last_regen: string;
  pending_count: number;
  build_running: boolean;
  build_processed: number;
  build_total: number;
  ttfb_p50: number;
  ttfb_p95: number;
  ttfb_samples: number;
  vitals: { LCP: number; INP: number; CLS: number };
  vitals_samples: { LCP: number; INP: number; CLS: number };
  vitals_method: string;
  hardening_active: number;
  hardening_total: number;
  security_score: number;
  stuck_warning: string;
};
