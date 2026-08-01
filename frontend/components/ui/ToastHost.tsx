import { CheckCircle2, AlertCircle, Info, X } from 'lucide-react';
import { useStore } from '../../lib/store';

const TONE = {
  success: { bg: '#ECFDF5', border: '#86EFAC', text: '#15803D', Icon: CheckCircle2 },
  error:   { bg: '#FEF2F2', border: '#FCA5A5', text: '#A32D2D', Icon: AlertCircle },
  info:    { bg: '#EBF0FF', border: '#BFD3FE', text: '#063CE6', Icon: Info },
} as const;

export default function ToastHost() {
  const toasts    = useStore((s) => s.toasts);
  const dismiss   = useStore((s) => s.dismissToast);

  if (!toasts.length) return null;

  return (
    <div className="fixed bottom-6 right-6 z-[10000] flex flex-col gap-2 pointer-events-none">
      {toasts.map((t) => {
        const tone = TONE[t.kind];
        const Icon = tone.Icon;
        return (
          <div
            key={t.id}
            className="pointer-events-auto flex items-start gap-2 rounded-xl px-3 py-2.5 min-w-[260px] max-w-[360px]"
            style={{
              background: tone.bg,
              border: `1px solid ${tone.border}`,
              color: tone.text,
              boxShadow: '0 8px 24px rgb(15 23 42 / 0.10)',
            }}
          >
            <Icon className="w-4 h-4 flex-shrink-0 mt-0.5" />
            <span className="text-xs font-semibold leading-snug flex-1">{t.message}</span>
            <button
              type="button"
              onClick={() => dismiss(t.id)}
              className="opacity-70 hover:opacity-100"
              aria-label="Dismiss"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        );
      })}
    </div>
  );
}
