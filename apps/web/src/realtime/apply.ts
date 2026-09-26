import type { QueryClient } from "@tanstack/react-query";
import { activityDetailQueryKey, activityListQueryKey } from "../api/activities";
import { markProcessUsage } from "./process-usage";

export type RealtimeEvent = { type: string; id: number; at: string; data: Record<string, unknown> };

const COLLECTIONS: Record<string, string> = {
    node: "nodes",
    app: "projects",
    project: "projects",
    instance: "instances",
    process: "processes",
    schedule: "schedules",
    database: "databases",
    firewall: "firewall",
};

type Row = Record<string, unknown>;

/** Merges a row into a cached list by id, or removes it. Actions use it too, ahead of their event. */
export function applyRow(client: QueryClient, collection: string, verb: string, data: Row): void {
    if (data.id === undefined || data.id === null) {
        return;
    }

    client.setQueryData<Row[]>([collection], (rows) => {
        if (rows === undefined) {
            return rows;
        }

        if (verb === "deleted") {
            return rows.filter((row) => row.id !== data.id);
        }

        return rows.some((row) => row.id === data.id)
            ? rows.map((row) => (row.id === data.id ? { ...row, ...data } : row))
            : [...rows, data];
    });
}

/** The id an event names in its data, as the string a task query key holds, or null. */
function keyId(value: unknown): string | null {
    return typeof value === "number" || (typeof value === "string" && value !== "")
        ? String(value)
        : null;
}

/** Writes one `process.usage` part's CPU and memory into the cached Process list. */
export function applyProcessUsage(client: QueryClient, data: Row): void {
    if (!Array.isArray(data.processes)) {
        return;
    }

    markProcessUsage();

    const value = (sample: unknown) => (typeof sample === "number" ? sample : null);
    const usage = new Map<unknown, { cpu: number | null; memory_bytes: number | null }>();
    for (const sample of data.processes as unknown[]) {
        if (Array.isArray(sample) && typeof sample[0] === "number") {
            usage.set(sample[0], { cpu: value(sample[1]), memory_bytes: value(sample[2]) });
        }
    }

    client.setQueryData<Row[]>(["processes"], (rows) =>
        rows?.map((row) => {
            const sample = usage.get(row.id);

            return sample === undefined ? row : { ...row, ...sample };
        }),
    );
}

/** How long task and Activity refetches wait, so the notices of one flush refetch each query once. */
export const TASK_REFETCH_DELAY_MS = 100;

const pendingRefetches = new Map<string, { queryKey: readonly string[]; exact: boolean }>();
let refetchTimer: ReturnType<typeof setTimeout> | undefined;
let refetchClient: QueryClient | undefined;

type RefetchFilters = { queryKey?: readonly unknown[]; exact?: boolean };

/**
 * Invalidates the matching active queries. TanStack Query keeps a first fetch that has not stored
 * data yet, and that response then overwrites this refetch and clears the invalidation. Cancel that
 * empty fetch first so the new read replaces it. A fetch that already has data is left to
 * `invalidateQueries`, which cancels it itself.
 */
export function refetchReplacingInitial(client: QueryClient, filters?: RefetchFilters): void {
    void client.cancelQueries(
        {
            ...filters,
            type: "active",
            predicate: (query) =>
                query.state.data === undefined && query.state.fetchStatus !== "idle",
        },
        { silent: true, revert: false },
    );

    if (filters === undefined) {
        void client.invalidateQueries();
        return;
    }

    void client.invalidateQueries(filters);
}

/** Runs the waiting task and Activity refetches now, one per query key. */
export function flushTaskRefetches(): void {
    clearTimeout(refetchTimer);
    refetchTimer = undefined;
    const client = refetchClient;
    const refetches = [...pendingRefetches.values()];
    pendingRefetches.clear();

    if (client === undefined) {
        return;
    }

    for (const filters of refetches) {
        refetchReplacingInitial(client, filters);
    }
}

/**
 * Queues one refetch. Notices from one Gateway flush share this queue, so each query runs once:
 * a task group named twice, or a sweep that ends many Activity rows. The refetch replaces a
 * request already in flight, including a first fetch that has not stored data yet.
 */
function scheduleRefetch(client: QueryClient, queryKey: readonly string[], exact: boolean): void {
    refetchClient = client;
    const key = JSON.stringify([queryKey, exact]);
    pendingRefetches.set(key, { queryKey, exact });
    refetchTimer ??= setTimeout(flushTaskRefetches, TASK_REFETCH_DELAY_MS);
}

/**
 * Task events are notices: they name what changed, and the matching queries refetch it. A group
 * refetches its own show query exactly, so its agents and comments reload only for their own events.
 */
function applyTaskEvent(client: QueryClient, type: string, data: Row): boolean {
    const refetch = (queryKey: string[], exact = false) => scheduleRefetch(client, queryKey, exact);

    switch (type) {
        case "task_group.created":
        case "task_group.updated": {
            const group = keyId(data.id);
            refetch(["task-groups"], true);
            if (group !== null) refetch(["task-groups", group], true);

            return true;
        }
        case "task_comment.created": {
            const group = keyId(data.task_group_id);
            const task = keyId(data.task_id);
            if (group !== null && task !== null) {
                refetch(["task-groups", group, "tasks", task, "comments"]);
            }

            return true;
        }
        case "agent_thread.updated": {
            const group = keyId(data.task_group_id);
            if (group !== null) {
                refetch(["task-groups", group, "agents"]);
                refetch(["task-groups", group], true);
            }

            return true;
        }
        case "tasks.updated":
            if (typeof data.enabled === "boolean") {
                const enabled = data.enabled;
                client.setQueryData<Row>(["tasks-status"], (old) => ({ ...old, enabled }));
            }

            return true;
        default:
            return false;
    }
}

/**
 * Activity events are notices. They name the row, and the page refetches the list it is showing.
 * `updated` also refetches that row when it is open. The list key keeps the filters and `before_id`.
 */
function applyActivityEvent(client: QueryClient, event: RealtimeEvent): boolean {
    if (event.type !== "activity.created" && event.type !== "activity.updated") {
        return false;
    }

    scheduleRefetch(client, activityListQueryKey, false);

    if (event.type === "activity.updated") {
        const id = keyId(event.data.id) ?? keyId(event.id);
        if (id !== null) {
            scheduleRefetch(client, activityDetailQueryKey(id), true);
        }
    }

    return true;
}

/** Applies one realtime event on the `orbit` channel to the query cache. */
export function applyEvent(client: QueryClient, event: RealtimeEvent): void {
    // A usage sample patches rows the Process list already has; it is not a Process record.
    if (event.type === "process.usage") {
        applyProcessUsage(client, event.data);

        return;
    }

    if (applyTaskEvent(client, event.type, event.data)) {
        return;
    }

    if (applyActivityEvent(client, event)) {
        return;
    }

    const [family = "", verb = ""] = event.type.split(".", 2);
    const collection = COLLECTIONS[family];

    if (collection !== undefined) {
        applyRow(client, collection, verb, event.data);

        return;
    }

    // Deploy steps live inside their Instance; a deployment changes that instance's history.
    if (family === "deploy_step") {
        void client.invalidateQueries({ queryKey: ["instances"] });
    }

    // The event carries the row; the history and the open log reload from the Gateway.
    if (family === "deployment") {
        const instanceId = event.data.app_instance_id;
        void client.invalidateQueries({
            queryKey:
                typeof instanceId === "number" ? ["deployments", instanceId] : ["deployments"],
        });
        void client.invalidateQueries({ queryKey: ["deployment-log", event.id] });
    }
}
