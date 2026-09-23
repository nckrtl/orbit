import { queryOptions } from "@tanstack/react-query";
import type { components } from "./schema";
import { get } from "./client";
import { POLL_SECONDS } from "./queries";

type LineChanges = { lines_added?: number | null; lines_deleted?: number | null };
export type Task = Omit<Required<components["schemas"]["Task"]>, keyof LineChanges> & LineChanges;
export type TaskGroup = Omit<
    Required<components["schemas"]["TaskGroup"]>,
    "tasks" | "project_code" | keyof LineChanges
> &
    LineChanges & {
        tasks: Task[];
        project_code?: string;
    };
export type TaskColumn = "Backlog" | "Todo" | "In progress" | "Done";

export function taskIdentity(id: number, projectCode?: string): string {
    return projectCode ? `${projectCode}-${id}` : `#${id}`;
}

export function taskColumn(status: TaskGroup["status"] | Task["status"]): TaskColumn {
    if (status === "backlog") return "Backlog";
    if (status === "todo") return "Todo";
    if (["completed", "failed", "cancelled"].includes(status)) return "Done";
    return "In progress";
}

/** Added and deleted lines across subtasks. Subtasks without a split diff are skipped. */
export function accumulatedLineChanges(tasks: readonly LineChanges[]): {
    lines_added: number;
    lines_deleted: number;
} | null {
    let added = 0;
    let deleted = 0;
    let found = false;
    for (const task of tasks) {
        if (typeof task.lines_added !== "number" || typeof task.lines_deleted !== "number")
            continue;
        found = true;
        added += task.lines_added;
        deleted += task.lines_deleted;
    }
    return found ? { lines_added: added, lines_deleted: deleted } : null;
}

/** How many nested tasks have finished successfully, of the group's total. */
export function completedSubtaskProgress(tasks: readonly Task[]): {
    completed: number;
    total: number;
} {
    return {
        completed: tasks.filter((task) => task.status === "completed").length,
        total: tasks.length,
    };
}

export function formatTokens(value: number | null | undefined): string | null {
    if (value === null || value === undefined) return null;

    return value.toLocaleString("en-US");
}

const compactUnits = ["", "K", "M", "B", "T"] as const;

/** Compact count for card chrome: significand at most 5 characters, then K/M/B. */
export function formatCompactCount(value: number | null | undefined): string | null {
    if (value == null || !Number.isFinite(value) || value < 0) return null;

    let scaled = value;
    let unit = 0;
    while (scaled >= 1000 && unit < compactUnits.length - 1) {
        scaled /= 1000;
        unit += 1;
    }

    if (unit === 0) return String(Math.round(value));

    const decimalsFor = (amount: number) => (amount < 10 ? 3 : amount < 100 ? 2 : 1);
    let decimals = decimalsFor(scaled);
    let rounded = Number(scaled.toFixed(decimals));
    if (rounded >= 1000 && unit < compactUnits.length - 1) {
        unit += 1;
        rounded /= 1000;
        decimals = decimalsFor(rounded);
        rounded = Number(rounded.toFixed(decimals));
    }

    const significand = rounded
        .toFixed(decimals)
        .replace(/(\.\d*?)0+$/, "$1")
        .replace(/\.$/, "");

    return `${significand}${compactUnits[unit]}`;
}

export function formatLineDiff(value: number | null | undefined): string | null {
    if (value === null || value === undefined) return null;

    return value.toLocaleString("en-US");
}

/** Signed added/deleted line changes, compact by default. */
export function formatSignedLineChanges(
    added: number | null | undefined,
    deleted: number | null | undefined,
    format: (value: number | null | undefined) => string | null = formatCompactCount,
): string | null {
    const plus = format(added);
    const minus = format(deleted);
    if (plus === null || minus === null) return null;

    return `+${plus} −${minus}`;
}

export function formatDurationMs(value: number | null | undefined): string | null {
    if (value === null || value === undefined) return null;
    if (value < 1000) return `${value}ms`;

    const totalSeconds = Math.floor(value / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const parts = [
        hours > 0 ? `${hours}h` : null,
        minutes > 0 ? `${minutes}m` : null,
        hours === 0 && (seconds > 0 || minutes === 0) ? `${seconds}s` : null,
    ].filter((part): part is string => part !== null);

    return parts.join(" ");
}

export const taskStatusLabels: Record<TaskGroup["status"], string> = {
    backlog: "Being prepared",
    todo: "Waiting for capacity",
    reserved: "Preparing workspace",
    running: "Running",
    reviewing: "In review",
    settling: "Awaiting completion",
    completed: "Completed",
    failed: "Failed",
    cancelled: "Cancelled",
};

/** Task groups shown on an Instance's board and counted in its navigation. */
export function tasksForInstance(groups: readonly TaskGroup[], instanceId: number): TaskGroup[] {
    return groups.filter(
        (group) => group.taskable_type === "instance" && group.taskable_id === instanceId,
    );
}

export const taskGroupsQuery = queryOptions({
    queryKey: ["task-groups"],
    queryFn: () => get<TaskGroup[]>("/api/v1/task-groups"),
    refetchInterval: POLL_SECONDS * 1000,
    retry: false,
});

export const taskGroupQuery = (id: string) =>
    queryOptions({
        queryKey: ["task-groups", id],
        queryFn: () => get<TaskGroup>(`/api/v1/task-groups/${encodeURIComponent(id)}`),
        refetchInterval: POLL_SECONDS * 1000,
        retry: false,
    });

export function formatCardDuration(value: number | null | undefined): string | null {
    if (value == null || !Number.isFinite(value) || value < 0) return null;
    const minutes = Math.floor(value / 60_000);
    if (minutes < 1) return "<1m";
    const hours = Math.floor(minutes / 60);
    return hours ? `${hours}h${minutes % 60 ? ` ${minutes % 60}m` : ""}` : `${minutes}m`;
}
