import type { Liveness } from "./liveness";

/** How often the Process list reloads while realtime is down or not configured. */
export const PROCESS_POLL_MS = 15_000;

/** How long the Process list may go without a `process.usage` event while realtime is live. */
export const PROCESS_USAGE_STALE_MS = 60_000;

let lastUsageAt: number | null = null;

/** Records that a `process.usage` event arrived, so the Process list does not reload for its CPU and memory. */
export function markProcessUsage(at: number = Date.now()): void {
    lastUsageAt = at;
}

/** When the last `process.usage` event arrived, or null when none arrived since the page loaded. */
export function lastProcessUsageAt(): number | null {
    return lastUsageAt;
}

/** Forgets the last `process.usage` event. Tests use it. */
export function resetProcessUsage(): void {
    lastUsageAt = null;
}

/**
 * The delay until the Process list reloads. While realtime is down it polls every 15 seconds. While
 * realtime is live it reloads only when no `process.usage` event arrived for 60 seconds, and then
 * every 60 seconds until events come back. TanStack Query asks again whenever the list changes, so
 * each usage event pushes the reload back.
 */
export function processPollInterval(
    liveness: Liveness,
    usageAt: number | null,
    now: number,
): number {
    if (liveness !== "live") {
        return PROCESS_POLL_MS;
    }

    const age = usageAt === null ? PROCESS_USAGE_STALE_MS : Math.max(0, now - usageAt);

    return age >= PROCESS_USAGE_STALE_MS ? PROCESS_USAGE_STALE_MS : PROCESS_USAGE_STALE_MS - age;
}
