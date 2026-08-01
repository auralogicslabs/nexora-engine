import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, SsgState } from './api';
import { useStore } from './store';

/**
 * Browser-driven batch driver for SSG bulk builds.
 *
 * Once a bulk run is active (state.running === true), this hook polls the
 * /ssg/batch-tick endpoint on a steady cadence. Each tick captures one page
 * server-side and returns the latest status. The HTTP round-trip naturally
 * paces captures ~1–2s apart without burning workers in a tight loop.
 *
 * IMPORTANT (fix 2026-06-26): the loop MUST NOT be torn down and recreated by
 * incidental re-renders. The previous version restarted its effect whenever the
 * `running`/`paused` values changed, and because /ssg/state is polled
 * independently AND the tick itself mutates the cached state, `running` could
 * flicker — tearing down the setTimeout chain after a single tick and freezing
 * the build at the first captured page. We now run ONE long-lived loop driven by
 * refs: it starts when a run begins and stops only when the server reports done.
 * (The PHP cron fallback also detects a dead browser via nexeng_ssg_bulk_last_advance.)
 */
export function useBuildDriver(state: SsgState | undefined) {
  const qc = useQueryClient();
  const pushToast = useStore((s) => s.pushToast);

  // Live refs so the single loop always reads current values without being a
  // dependency of the effect (which would restart the loop).
  const runningRef = useRef(false);
  const pausedRef  = useRef(false);
  const loopActive = useRef(false);
  const wasRunning = useRef(false);
  const timerRef   = useRef<number | null>(null);

  const running = !!state?.running;
  const paused  = !!state?.paused;

  // Bumped by Pause / Resume / Stop. Read through a ref for the same reason as
  // running/paused: the loop must see the current value without restarting.
  const buildControlEpoch = useStore((s) => s.buildControlEpoch);
  const epochRef = useRef(buildControlEpoch);
  epochRef.current = buildControlEpoch;

  // Keep refs in sync every render — cheap, no effect teardown.
  runningRef.current = running;
  pausedRef.current  = paused;

  // Completion toast on the running → done edge (fires exactly once).
  useEffect(() => {
    if (wasRunning.current && !running) {
      pushToast('success', 'Build complete — static mirror is up to date.');
      qc.invalidateQueries({ queryKey: ['ssg-state'] });
      qc.invalidateQueries({ queryKey: ['ssg-pages'] });
      qc.invalidateQueries({ queryKey: ['ssg-mirror'] });
    }
    wasRunning.current = running;
  }, [running, qc, pushToast]);

  // Single long-lived poll loop. The effect runs once on mount and tears down
  // only on unmount — NOT on every state change — so the loop can't be
  // accidentally cancelled mid-build.
  useEffect(() => {
    let unmounted = false;

    const schedule = (delay: number) => {
      if (unmounted) return;
      timerRef.current = window.setTimeout(tick, delay);
    };

    const tick = async () => {
      if (unmounted) return;

      // Idle when not running or paused — poll slowly until a run begins so the
      // loop is ready to drive the moment the user starts a build.
      if (!runningRef.current || pausedRef.current) {
        loopActive.current = false;
        schedule(1500);
        return;
      }

      loopActive.current = true;
      try {
        // Snapshot the control epoch before the request. If the user pauses or
        // stops while this tick is in flight, the response describes a build
        // that is no longer the current intent and must not be written back.
        const epochAtSend = epochRef.current;
        const status = await api.post<any>('ssg/batch-tick');

        if (epochRef.current !== epochAtSend) {
          // A pause/resume/stop landed mid-flight. Discard this tick's view and
          // let the authoritative /ssg/state poll decide what is true now.
          qc.invalidateQueries({ queryKey: ['ssg-state'] });
          schedule(1200);
          return;
        }

        // When the server-side driver is handling the build, this tick DEFERS
        // (it doesn't capture) and returns skipped/throttled. In that case do NOT
        // write an optimistic snapshot — it can be stale and freeze the wizard's
        // progress bar at an old number while the real build advances on the
        // server. Instead invalidate so the independent /ssg/state poll refetches
        // the authoritative bulk_status (processed = total - remaining). Only when
        // THIS tick actually captured do we apply its fresh numbers optimistically.
        const deferred = status?.skipped === 'capture_lock_held'
          || status?.reason === 'lock_held'
          || !!status?.throttled;

        if (deferred) {
          qc.invalidateQueries({ queryKey: ['ssg-state'] });
        } else {
          qc.setQueryData(['ssg-state'], (prev: any) => {
            if (!prev) return prev;
            const nextProcessed = Number(status?.processed ?? prev.processed ?? 0);
            const nextTotal     = Number(status?.total ?? prev.total ?? 0);
            return {
              ...prev,
              running:       !status?.done && !!prev.enabled,
              // Only take the tick's word on paused when it actually reported
              // one. `!!undefined` is false, which silently un-paused a build
              // every time the endpoint omitted the field.
              paused:        status?.paused !== undefined ? !!status.paused : !!prev.paused,
              // Never let the displayed count go BACKWARD — clamp to the max of
              // what we already showed and the new value (belt-and-suspenders
              // against any out-of-order response).
              processed:     Math.max(Number(prev.processed ?? 0), nextProcessed),
              total:         nextTotal,
              pending_count: Number(status?.remaining ?? prev.pending_count ?? 0),
              percent:       nextTotal > 0
                ? Math.min(100, Math.round((Math.max(Number(prev.processed ?? 0), nextProcessed) / nextTotal) * 100))
                : prev.percent ?? 0,
            };
          });
        }

        if (status?.done) {
          qc.invalidateQueries({ queryKey: ['ssg-state'] });
          qc.invalidateQueries({ queryKey: ['ssg-pages'] });
          qc.invalidateQueries({ queryKey: ['ssg-mirror'] });
          if (status.cdn_purge_error) {
            pushToast('error', `CDN purge failed: ${status.cdn_purge_error}`);
          } else if (status.cdn_purged) {
            pushToast('info', 'CDN edge cache purged.');
          }
          loopActive.current = false;
          schedule(2000); // resume idle polling
          return;
        }

        // If the server throttled this tick (server_busy / rate limit), back off
        // a little so we don't hammer a stressed host; otherwise pace normally.
        const busy = status?.reason === 'server_busy' || status?.reason === 'rate_limit';
        schedule(busy ? 3000 : 1200);
      } catch (e: any) {
        // eslint-disable-next-line no-console
        console.warn('[Nexora Engine] batch tick failed:', e?.message ?? e);
        // Transient error — keep the loop alive with a wider gap. The PHP cron
        // fallback will also pick up the build if the browser truly dies.
        schedule(4000);
      }
    };

    schedule(100);

    return () => {
      unmounted = true;
      if (timerRef.current !== null) {
        window.clearTimeout(timerRef.current);
        timerRef.current = null;
      }
    };
    // Intentionally empty deps: ONE loop for the lifetime of the component.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}
