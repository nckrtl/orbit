import { type Liveness, useLiveness } from "./liveness";

/** How often the task queries reload while realtime is down or not configured. */
export const TASK_POLL_MS = 30_000;

/** How often the task queries reload while realtime is live: a safety net for a lost notice. */
export const TASK_SAFETY_POLL_MS = 300_000;

/** Task events keep the task queries current while realtime is live, so they reload only rarely then. */
export function fallbackPollInterval(liveness: Liveness): number {
    return liveness === "live" ? TASK_SAFETY_POLL_MS : TASK_POLL_MS;
}

/** The `refetchInterval` for a task query. It changes, and restarts the query's timer, with the liveness. */
export function useTaskPoll(): number {
    return fallbackPollInterval(useLiveness());
}
