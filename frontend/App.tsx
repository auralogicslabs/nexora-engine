import { useEffect } from 'react';
import { Route, Routes, Navigate } from 'react-router-dom';
import Layout from './components/Layout';
import Dashboard from './pages/Dashboard';
import Settings from './pages/Settings';
import Headless from './pages/Headless';
import Security from './pages/Security';
import Redirects from './pages/Redirects';
import Tools from './pages/Tools';
import Addons from './pages/Addons';
import License from './pages/License';
import SeoReport from './pages/SeoReport';
// Portal is hidden for now — it's a separate cloud feature to be revisited
// later with a proper design. The page (pages/Portal.tsx) is kept on disk but
// not imported/routed, so it's unreachable from the UI. Re-add the import,
// route, and nav entry when Portal returns.
import Wizard from './pages/Wizard';
import ToastHost from './components/ui/ToastHost';
import ConfirmDialog from './components/ui/ConfirmDialog';
import BuildDriver from './components/BuildDriver';
import { useStore } from './lib/store';

// All routes are eagerly imported. Vite is configured with
// `inlineDynamicImports: true` so the SPA ships as one self-contained file
// the WordPress enqueue layer can serve without juggling dynamic chunks —
// React.lazy would defeat that config and add Suspense overhead for no
// download-size win.

export default function App() {
  const syncInstall = useStore((s) => s.syncInstall);

  useEffect(() => {
    const ctx = window.NexoraEngine;
    if (ctx) {
      syncInstall(ctx.installId, ctx.onboardingComplete);
    }
  }, [syncInstall]);

  return (
    <>
      <BuildDriver />
      <Layout>
        <Routes>
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/settings" element={<Settings />} />
          <Route path="/headless" element={<Headless />} />
          <Route path="/security" element={<Security />} />
          <Route path="/redirects" element={<Redirects />} />
          <Route path="/tools" element={<Tools />} />
          <Route path="/addons" element={<Addons />} />
          <Route path="/updates" element={<License />} />
          <Route path="/seo-report" element={<SeoReport />} />
          {/* /portal route hidden for now — any old link falls through to the
              catch-all below and redirects to the dashboard. */}
          <Route path="/wizard" element={<Wizard />} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </Layout>
      <ToastHost />
      <ConfirmDialog />
    </>
  );
}
