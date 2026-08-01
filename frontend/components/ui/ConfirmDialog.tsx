import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  ShieldAlert, Trash2, RefreshCw, Zap, Power, X,
} from 'lucide-react';
import { useStore, type ConfirmRequest } from '../../lib/store';

/**
 * Global confirmation modal — replaces native window.confirm() throughout the
 * admin app so destructive flows (Purge mirror, Rebuild all, Factory reset,
 * Headless mode switch) get a consistent, brand-aligned dialog with:
 *
 *  • Explicit title + body copy.
 *  • Optional bullet list of consequences ("This will…").
 *  • Optional typed-confirmation gate for irreversible operations.
 *  • Keyboard support: ESC cancels, Enter confirms when allowed.
 *  • Focus trap on the action button so screen readers land correctly.
 *
 * Use via `useStore.getState().askConfirm({ title, message, … })` — returns a
 * Promise<boolean>. Components don't render this directly; it's mounted once
 * in App.tsx and listens to the store.
 */

const TONE = {
  primary: { btn: 'np-btn-primary', icon: 'var(--np-brand-primary)' },
  danger:  { btn: 'np-btn-danger',  icon: 'var(--np-danger)' },
  warning: { btn: 'np-btn-primary', icon: 'var(--np-warning)' },
} as const;

const ICONS: Record<NonNullable<ConfirmRequest['icon']>, React.FC<any>> = {
  'shield-alert': ShieldAlert,
  trash:          Trash2,
  refresh:        RefreshCw,
  zap:            Zap,
  power:          Power,
};

export default function ConfirmDialog() {
  const confirm = useStore((s) => s.confirm);
  const resolve = useStore((s) => s.resolveConfirm);
  const [typed, setTyped] = useState('');
  const confirmBtnRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    // Reset typed text when a new confirm shows up. Without this, an
    // existing typed value from a previous prompt would persist and
    // accidentally pre-satisfy the next one.
    setTyped('');
    // Autofocus the confirm button on mount (unless a typed gate is in
    // play — in which case the input gets focus instead).
    if (confirm && !confirm.requireTyped) {
      confirmBtnRef.current?.focus();
    }
  }, [confirm?.id]);

  useEffect(() => {
    if (!confirm) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        resolve(false);
      } else if (e.key === 'Enter' && !confirm.requireTyped) {
        e.preventDefault();
        resolve(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [confirm, resolve]);

  if (!confirm) return null;

  const tone   = TONE[confirm.tone ?? 'primary'];
  const Icon   = ICONS[confirm.icon ?? 'shield-alert'];
  const gated  = !!confirm.requireTyped;
  const passed = !gated || typed === confirm.requireTyped;

  // Render via portal to document.body so the modal centers to the
  // viewport rather than to <main> (which carries a CSS animation
  // transform that turns it into the containing block for any
  // descendant `position: fixed`).
  return createPortal(
    <div
      className="fixed inset-0 z-[10000] flex items-center justify-center p-4"
      style={{ background: 'rgba(10,15,28,0.55)', backdropFilter: 'blur(2px)' }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="ncx-confirm-title"
      onClick={() => resolve(false)}
    >
      <div
        className="np-card w-full max-w-md relative"
        style={{ background: 'var(--np-bg-card)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <button
          type="button"
          onClick={() => resolve(false)}
          aria-label="Cancel"
          className="absolute top-3 right-3 opacity-50 hover:opacity-100 transition-opacity"
          style={{ color: 'var(--np-text-muted)' }}
        >
          <X className="w-4 h-4" />
        </button>

        <div className="p-5">
          <div className="flex items-start gap-3 mb-3">
            <div
              className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0"
              style={{
                background:
                  confirm.tone === 'danger' ? 'var(--np-danger-bg)'
                  : confirm.tone === 'warning' ? '#FFFBEB'
                  : 'var(--np-bg-subtle)',
                border: `1px solid ${
                  confirm.tone === 'danger' ? 'rgba(226,75,74,0.25)'
                  : confirm.tone === 'warning' ? 'rgba(243,154,9,0.25)'
                  : 'var(--np-border)'
                }`,
              }}
            >
              <Icon className="w-5 h-5" style={{ color: tone.icon }} strokeWidth={2.2} />
            </div>
            <div className="min-w-0 flex-1">
              <h2
                id="ncx-confirm-title"
                className="text-sm font-bold leading-tight"
                style={{ color: 'var(--np-text-primary)' }}
              >
                {confirm.title}
              </h2>
              <p
                className="text-xs mt-1 leading-snug"
                style={{ color: 'var(--np-text-muted)' }}
              >
                {confirm.message}
              </p>
            </div>
          </div>

          {confirm.details && confirm.details.length > 0 && (
            <ul
              className="rounded-lg p-3 mb-3 space-y-1.5 text-[11px] leading-snug"
              style={{
                background: 'var(--np-bg-subtle)',
                border: '1px solid var(--np-border)',
                color: 'var(--np-text-primary)',
              }}
            >
              {confirm.details.map((d) => (
                <li key={d} className="flex gap-2">
                  <span style={{ color: 'var(--np-text-muted)' }}>•</span>
                  <span>{d}</span>
                </li>
              ))}
            </ul>
          )}

          {gated && (
            <label className="block mb-3">
              <span
                className="block text-[11px] font-semibold mb-1"
                style={{ color: 'var(--np-text-primary)' }}
              >
                Type <code className="np-mono">{confirm.requireTyped}</code> to confirm
              </span>
              <input
                type="text"
                value={typed}
                onChange={(e) => setTyped(e.target.value)}
                autoFocus
                className="np-input w-full text-xs"
                placeholder={confirm.requireTyped}
                spellCheck={false}
                autoComplete="off"
              />
            </label>
          )}

          <div className="flex items-center justify-end gap-2">
            <button
              type="button"
              onClick={() => resolve(false)}
              className="np-btn-secondary text-xs"
            >
              Cancel
            </button>
            <button
              ref={confirmBtnRef}
              type="button"
              onClick={() => passed && resolve(true)}
              disabled={!passed}
              className={`${tone.btn} text-xs`}
              style={{ opacity: passed ? 1 : 0.55 }}
            >
              {confirm.confirmLabel ?? 'Confirm'}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
