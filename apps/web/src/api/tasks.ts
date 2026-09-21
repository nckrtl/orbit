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
export type TaskColumn = "Todo" | "In progress" | "Done";

export function taskColumn(status: TaskGroup["status"] | Task["status"]): TaskColumn {
    if (status === "queued" || status === "pending") return "Todo";
    if (["completed", "failed", "cancelled"].includes(status)) return "Done";
    return "In progress";
}

export function formatTokens(value: number | null | undefined): string | null {
    if (value === null || value === undefined) return null;

    return value.toLocaleString("en-US");
}

export function formatLineDiff(value: number | null | undefined): string | null {
    if (value === null || value === undefined) return null;

    return value.toLocaleString("en-US");
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

export const taskStatusLabels: Record<TaskGroup["status"] | "pending", string> = {
    queued: "Waiting for capacity",
    pending: "Pending",
    reserved: "Preparing workspace",
    running: "Running",
    reviewing: "In review",
    settling: "Awaiting completion",
    completed: "Completed",
    failed: "Failed",
    cancelled: "Cancelled",
};

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
