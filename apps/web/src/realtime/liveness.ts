import { useSyncExternalStore } from "react";

/** `live` while the socket is subscribed, `reconnecting` while it retries, `polling` when the Gateway offers no realtime. */
export type Liveness = "live" | "reconnecting" | "polling";

/** Why the page polls: null when the Gateway simply has no realtime, or the Gateway's own message when it refused to say. */
type State = { liveness: Liveness; reason: string | null };

let current: State = { liveness: "polling", reason: null };
const listeners = new Set<() => void>();

export function setLiveness(liveness: Liveness, reason: string | null = null): void {
    if (liveness !== current.liveness || reason !== current.reason) {
        current = { liveness, reason };
        listeners.forEach((listener) => listener());
    }
}

const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => listeners.delete(listener);
};

export const useLiveness = (): Liveness => useSyncExternalStore(subscribe, () => current.liveness);
export const usePollingReason = (): string | null =>
    useSyncExternalStore(subscribe, () => current.reason);
