import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HashRouter } from 'react-router-dom';
import App from './App';
import ErrorBoundary from './components/ErrorBoundary';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      staleTime: 10_000,
      retry: 1,
    },
  },
});

function mount() {
  const root = document.getElementById('nexora-engine-root');
  if (!root) return;

  // The PHP layer writes the current view as a data attribute. We use this to
  // sync the React router's initial route to what WordPress thinks we're on,
  // so opening admin.php?page=ncx-settings lands us on /settings inside the SPA.
  const initialView = root.getAttribute('data-view') ?? 'dashboard';
  if (!window.location.hash) {
    window.location.hash = `#/${initialView}`;
  }

  ReactDOM.createRoot(root).render(
    <React.StrictMode>
      <ErrorBoundary>
        <QueryClientProvider client={queryClient}>
          <HashRouter>
            <App />
          </HashRouter>
        </QueryClientProvider>
      </ErrorBoundary>
    </React.StrictMode>,
  );
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount);
} else {
  mount();
}
