import React from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

interface State {
  error: Error | null;
}

/**
 * Top-level safety net. If any page in the SPA throws — usually because of a
 * shape mismatch between a JSON response and the page's expected type — we
 * surface a recoverable error card instead of a white screen. The user gets
 * a Reload button, the original WordPress admin URL, and the error message
 * (helpful when they have to paste it into a support ticket).
 */
export default class ErrorBoundary extends React.Component<
  { children: React.ReactNode },
  State
> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    // eslint-disable-next-line no-console
    console.error('[Nexora Engine] uncaught error in React tree', error, info);
  }

  render() {
    if (!this.state.error) return this.props.children;

    return (
      <div
        className="flex items-center justify-center min-h-screen p-6"
        style={{ background: 'var(--np-bg-page)' }}
      >
        <div
          className="np-card max-w-lg w-full p-6"
          style={{ borderColor: 'rgba(226,75,74,0.30)' }}
        >
          <div className="flex items-start gap-3 mb-4">
            <div
              className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0"
              style={{
                background: 'var(--np-danger-bg)',
                border: '1px solid rgba(226,75,74,0.25)',
              }}
            >
              <AlertTriangle
                className="w-5 h-5"
                style={{ color: 'var(--np-danger)' }}
                strokeWidth={2.2}
              />
            </div>
            <div className="min-w-0 flex-1">
              <h2
                className="text-base font-bold leading-tight"
                style={{ color: 'var(--np-text-primary)' }}
              >
                Something went wrong
              </h2>
              <p
                className="text-xs mt-1 leading-snug"
                style={{ color: 'var(--np-text-muted)' }}
              >
                The Nexora admin app hit an error and stopped rendering. Reloading
                usually clears it. If it keeps happening, copy the message below
                into a support ticket.
              </p>
            </div>
          </div>

          <pre
            className="text-[11px] leading-snug rounded-lg p-3 mb-4 overflow-x-auto np-mono"
            style={{
              background: 'var(--np-bg-subtle)',
              border: '1px solid var(--np-border)',
              color: 'var(--np-text-primary)',
            }}
          >
            {this.state.error.message || String(this.state.error)}
          </pre>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="np-btn-primary text-xs"
            >
              <RefreshCw className="w-3.5 h-3.5" />
              Reload
            </button>
            <a
              href={window.NexoraEngine?.adminUrl ?? '/wp-admin/'}
              className="np-btn-secondary text-xs"
            >
              Back to wp-admin
            </a>
          </div>
        </div>
      </div>
    );
  }
}
