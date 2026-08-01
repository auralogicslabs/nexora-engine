import { create } from 'zustand';
import { persist } from 'zustand/middleware';

type Toast = { id: number; kind: 'success' | 'error' | 'info'; message: string };

/**
 * Confirmation request shape. The store holds at most one pending request at
 * a time; calling confirm() resolves the previous one as cancelled before
 * showing the new dialog so we never get two prompts on screen at once.
 */
export type ConfirmRequest = {
  title: string;
  message: string;
  /** Optional list of bullet points shown below the message, for explaining
   *  scope (e.g. "Purges all static files"). */
  details?: string[];
  /** Label on the action button. Default: "Confirm". */
  confirmLabel?: string;
  /** Visual tone of the action — "danger" colors the button red, "primary"
   *  brand-blue, "warning" amber. */
  tone?: 'primary' | 'danger' | 'warning';
  /** If set, user must type this string before the confirm button enables.
   *  Use for irreversible destructive actions (factory reset). */
  requireTyped?: string;
  /** Optional icon-only override — when omitted the modal picks one from
   *  the tone. */
  icon?: 'shield-alert' | 'trash' | 'refresh' | 'zap' | 'power';
};

type State = {
  installId: string | null;
  onboardingComplete: boolean;
  toasts: Toast[];
  confirm: (ConfirmRequest & { id: number; resolve: (ok: boolean) => void }) | null;
  /**
   * Bumped when the rail's "View all" is clicked. The pending queue lives in
   * the rail but the full list is the Pages & Posts table on Static Delivery,
   * and its filter is local state inside that table — there is no prop path
   * between them. A counter rather than a boolean so clicking twice works:
   * the table reacts to the value changing, not to it being true.
   */
  showPendingSignal: number;
  /**
   * Bumped whenever the user issues a build control (pause / resume / stop).
   * useBuildDriver captures it before each batch-tick and discards that tick's
   * optimistic snapshot if it changed while the request was in flight —
   * otherwise an older tick lands after the pause and reinstates running:true,
   * which made the buttons look inert until the page was reloaded.
   */
  buildControlEpoch: number;
};

type Actions = {
  syncInstall: (installId: string, onboardingComplete: boolean) => void;
  setOnboardingComplete: (v: boolean) => void;
  pushToast: (kind: Toast['kind'], message: string) => void;
  dismissToast: (id: number) => void;
  /** Promise-based confirmation dialog. Resolves true on confirm, false on
   *  cancel / dismiss / escape. Use this instead of window.confirm() so
   *  the experience matches the rest of the admin UI. */
  askConfirm: (req: ConfirmRequest) => Promise<boolean>;
  resolveConfirm: (ok: boolean) => void;
  /** Ask the Static Delivery table to filter itself to the pending queue. */
  requestShowPending: () => void;
  /** Invalidate any build-tick response that is already in flight. */
  bumpBuildControl: () => void;
};

let toastSeq = 1;
let confirmSeq = 1;

export const useStore = create<State & Actions>()(
  persist(
    (set, get) => ({
      installId: null,
      onboardingComplete: false,
      toasts: [],
      confirm: null,
      showPendingSignal: 0,
      buildControlEpoch: 0,

      // If the WP install_id changed since the last visit, the previous local
      // state belongs to a different site or a wiped reinstall — reset it.
      syncInstall: (installId, onboardingComplete) => {
        const prev = get().installId;
        if (prev !== installId) {
          set({ installId, onboardingComplete, toasts: [] });
        } else {
          set({ onboardingComplete });
        }
      },

      setOnboardingComplete: (v) => set({ onboardingComplete: v }),

      pushToast: (kind, message) => {
        const id = toastSeq++;
        set({ toasts: [...get().toasts, { id, kind, message }] });
        setTimeout(() => get().dismissToast(id), 4500);
      },

      dismissToast: (id) =>
        set({ toasts: get().toasts.filter((t) => t.id !== id) }),

      askConfirm: (req) => new Promise<boolean>((resolve) => {
        // If something is already pending, cancel it before showing the new
        // dialog. Two prompts on screen at once would race for the same
        // keyboard focus and create a confusing UX.
        const existing = get().confirm;
        if (existing) existing.resolve(false);
        set({ confirm: { ...req, id: confirmSeq++, resolve } });
      }),

      requestShowPending: () => set({ showPendingSignal: get().showPendingSignal + 1 }),

      bumpBuildControl: () => set({ buildControlEpoch: get().buildControlEpoch + 1 }),

      resolveConfirm: (ok) => {
        const current = get().confirm;
        if (!current) return;
        current.resolve(ok);
        set({ confirm: null });
      },
    }),
    {
      name: 'nexora-engine-ui',
      partialize: (state) => ({
        installId: state.installId,
        onboardingComplete: state.onboardingComplete,
      }),
    },
  ),
);
