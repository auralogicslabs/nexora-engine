import { Loader2 } from 'lucide-react';

export default function Spinner({ label }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-10">
      <Loader2 className="w-4 h-4 animate-spin" style={{ color: 'var(--np-brand-primary)' }} />
      {label && (
        <span className="text-xs font-medium" style={{ color: 'var(--np-text-muted)' }}>
          {label}
        </span>
      )}
    </div>
  );
}
