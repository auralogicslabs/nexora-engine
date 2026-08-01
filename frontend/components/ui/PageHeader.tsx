import React from 'react';

interface Props {
  title: string;
  subtitle?: string;
  icon?: React.FC<any>;
  actions?: React.ReactNode;
}

export default function PageHeader({ title, subtitle, icon: Icon, actions }: Props) {
  return (
    <div
      className="flex items-start gap-3 px-6 py-5 border-b"
      style={{
        borderColor: 'var(--np-border)',
        background: 'var(--np-bg-card)',
        // Stick to the top of the scroll container so the title strip stays
        // visible as the user scrolls the body. z-index keeps it above
        // sticky table headers and other in-flow elements.
        position: 'sticky',
        top: 0,
        zIndex: 10,
      }}
    >
      {Icon && (
        <div
          className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0"
          style={{
            background: 'var(--np-bg-subtle)',
            border: '1px solid var(--np-border)',
          }}
        >
          <Icon
            className="w-5 h-5"
            style={{ color: 'var(--np-brand-primary)' }}
            strokeWidth={2.2}
          />
        </div>
      )}
      <div className="min-w-0 flex-1">
        <h1
          className="text-lg font-bold tracking-tight leading-tight"
          style={{ color: 'var(--np-text-primary)' }}
        >
          {title}
        </h1>
        {subtitle && (
          <p
            className="text-xs mt-0.5 leading-snug"
            style={{ color: 'var(--np-text-muted)' }}
          >
            {subtitle}
          </p>
        )}
      </div>
      {actions && <div className="flex items-center gap-2 flex-shrink-0">{actions}</div>}
    </div>
  );
}
