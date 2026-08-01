import { useQuery } from '@tanstack/react-query';
import { api, SsgState } from '../lib/api';
import { useBuildDriver } from '../lib/useBuildDriver';

/**
 * Renderless component. Lives at App level (above route switches) so the
 * browser-driven batch loop keeps ticking when the user navigates between
 * pages — including /settings, where Mirror Build Control is hidden.
 *
 * Without this, a build started on /headless would orphan the moment the
 * user clicked Settings, and React would never call /ssg/batch-tick again.
 * Cron's 5-minute fallback would eventually pick it up, but the UX would
 * feel broken (progress bar frozen, no completion toast).
 */
export default function BuildDriver() {
  const { data } = useQuery({
    queryKey: ['ssg-state'],
    queryFn: () => api.get<SsgState>('ssg/state'),
    // Same cadence as MirrorBuildControl — fast when running or pending,
    // slow otherwise. Keeps the queue draining visibly when auto-rebuild
    // is processing a post in the background.
    refetchInterval: (q) => {
      const s = q.state.data;
      if (s?.running) return 1500;
      if ((s?.pending_count ?? 0) > 0) return 2000;
      return 10_000;
    },
    refetchIntervalInBackground: true,
  });

  useBuildDriver(data);
  return null;
}
