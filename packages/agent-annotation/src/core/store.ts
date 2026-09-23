import { useSyncExternalStore } from "react";

export type Store<T> = {
    getSnapshot: () => T;
    setState: (updater: T | ((prev: T) => T)) => void;
    subscribe: (listener: () => void) => () => void;
};

export function createStore<T>(initial: T): Store<T> {
    let state = initial;
    const listeners = new Set<() => void>();

    return {
        getSnapshot: () => state,
        setState: (updater) => {
            const next =
                typeof updater === "function" ? (updater as (prev: T) => T)(state) : updater;
            if (Object.is(next, state)) {
                return;
            }
            state = next;
            listeners.forEach((listener) => listener());
        },
        subscribe: (listener) => {
            listeners.add(listener);
            return () => {
                listeners.delete(listener);
            };
        },
    };
}

export function useStore<T>(store: Store<T>): T {
    return useSyncExternalStore(store.subscribe, store.getSnapshot, store.getSnapshot);
}
