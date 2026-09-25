import { useSyncExternalStore } from "react";

/** `live` while the socket is subscribed, `reconnecting` while it retries, `polling` when the Gateway offers no realtime. */
export type Liveness = "live" | "reconnecting" | "polling";

/** Why the page polls: null when the Gateway simply has no realtime, or the Gateway's own message when it refused to say. */
type State = { liveness: Liveness; reason: string | null };

/** The first fallback poll delay after realtime is lost, and the longest one. */
export const POLL_MIN_MS = 30_000;
export const POLL_MAX_MS = 300_000;

let current: State = { liveness: "polling", reason: null };
// When realtime was last lost. The page starts without it, so the clock starts at load.
let downSince: number | null = Date.now();
const listeners = new Set<() => void>();

export function setLiveness(liveness: Liveness, reason: string | null = null): void {
    if (liveness === "live") {
        downSince = null;
    } else if (downSince === null) {
        downSince = Date.now();
    }

    if (liveness !== current.liveness || reason !== current.reason) {
        current = { liveness, reason };
        listeners.forEach((listener) => listener());
    }
}

/** Starts the fallback backoff again, for example when the operator asks for fresh data. */
export function resetPollBackoff(now = Date.now()): void {
    if (current.liveness !== "live") {
        downSince = now;
    }
}

/** Whether record-change events reach the page right now. */
export const isLive = (): boolean => current.liveness === "live";

/**
 * The delay before the next fallback poll, given when the query last fetched. There is none while
 * realtime is live. Without it, the delay is how long realtime had been down at that fetch, from
 * 30 seconds up to 5 minutes, so it doubles with each poll: 30 s, 30 s, 1 min, 2 min, 4 min, then
 * every 5 min. It depends on the last fetch, not on the clock, so it stays the same between
 * fetches and a re-render does not restart the countdown.
 */
export function fallbackPollMs(lastFetchedAt: number): number | false {
    if (current.liveness === "live") {
        return false;
    }

    return Math.min(
        POLL_MAX_MS,
        Math.max(POLL_MIN_MS, lastFetchedAt - (downSince ?? lastFetchedAt)),
    );
}

const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => listeners.delete(listener);
};

export const useLiveness = (): Liveness => useSyncExternalStore(subscribe, () => current.liveness);
export const usePollingReason = (): string | null =>
    useSyncExternalStore(subscribe, () => current.reason);

type Fetched = { state: { dataUpdatedAt: number; errorUpdatedAt: number } };

/**
 * The `refetchInterval` for a query that realtime events keep current. The component re-renders
 * when liveness changes, so the query starts or stops polling at once; TanStack Query reads the
 * delay again after every fetch, which is what makes it back off.
 */
export function useFallbackPoll(): typeof fallbackPoll {
    useLiveness();

    return fallbackPoll;
}

/** The interval function itself, for a query outside React. */
export const fallbackPoll = (query: Fetched): number | false =>
    fallbackPollMs(Math.max(query.state.dataUpdatedAt, query.state.errorUpdatedAt));
