import type { Target } from "./store";

/**
 * The record the open page shows, for `a` while no pane is focused. A record page sets it while
 * it is mounted; the keyboard reads it on demand.
 */
let current: Target | null = null;

export const pageTarget = (): Target | null => current;

export function setPageTarget(target: Target | null): void {
    current = target;
}
