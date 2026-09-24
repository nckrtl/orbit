import { useSyncExternalStore } from "react";

const subscribe = (onChange: () => void) => {
    document.addEventListener("visibilitychange", onChange);

    return () => document.removeEventListener("visibilitychange", onChange);
};

/** Whether the page is in the foreground. Long-lived connections close while it is hidden. */
export const usePageVisible = (): boolean =>
    useSyncExternalStore(
        subscribe,
        () => document.visibilityState === "visible",
        () => true,
    );
