import type { QueryClient } from "@tanstack/react-query";
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

/** How long task refetches wait, so the notices of one Gateway flush refetch each query once. */
export const TASK_REFETCH_DELAY_MS = 100;

const pendingRefetches = new Map<string, { queryKey: string[]; exact: boolean }>();
let refetchTimer: ReturnType<typeof setTimeout> | undefined;
let refetchClient: QueryClient | undefined;

/** Runs the waiting task refetches now, one per query key. */
export function flushTaskRefetches(): void {
    clearTimeout(refetchTimer);
    refetchTimer = undefined;
    const client = refetchClient;
    const refetches = [...pendingRefetches.values()];
    pendingRefetches.clear();

    for (const filters of refetches) {
        void client?.invalidateQueries(filters);
    }
}

/**
 * Queues one refetch. One Gateway flush often names a group in `task_group.updated` and in
 * `agent_thread.updated`; both land here, so each tab shows the group once. The refetch replaces a
 * request already in flight, because that request may have started before the change.
 */
function scheduleRefetch(client: QueryClient, queryKey: string[], exact: boolean): void {
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
