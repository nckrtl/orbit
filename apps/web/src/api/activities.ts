import { queryOptions } from "@tanstack/react-query";
import type { components } from "./schema";
import { get } from "./client";

/** One stored Activity row, as `activity:list` and `activity:show` return it. */
export type Activity = Required<components["schemas"]["Activity"]>;

export type ActivityStatus = "running" | "succeeded" | "failed";

/** Filters the Activity page sends. An omitted field is not sent. `before_id` is the older-page cursor. */
export type ActivityListFilters = {
    before_id?: number;
    status?: ActivityStatus;
    command?: string;
    caller_node_id?: number;
    target_node_id?: number;
};

/** The page asks for this many rows. Fewer means there is no older page. */
export const ACTIVITY_PAGE_LIMIT = 25;

/** Prefix of every Activity list query. A notice refetches this prefix, so the open filters and cursor stay. */
export const activityListQueryKey = ["activities", "list"] as const;

/** Query key of one Activity row. `activity:show` is the only source for that row. */
export function activityDetailQueryKey(id: string | number): readonly [string, string] {
    return ["activities", String(id)];
}

/** Drops unused filters so an empty command is the same query as no command. */
function activityListFilters(filters: ActivityListFilters): ActivityListFilters {
    const query: ActivityListFilters = {};
    if (filters.before_id !== undefined) query.before_id = filters.before_id;
    if (filters.status !== undefined) query.status = filters.status;
    if (filters.command !== undefined && filters.command !== "") query.command = filters.command;
    if (filters.caller_node_id !== undefined) query.caller_node_id = filters.caller_node_id;
    if (filters.target_node_id !== undefined) query.target_node_id = filters.target_node_id;

    return query;
}

function activityListPath(filters: ActivityListFilters): string {
    const params = new URLSearchParams({ limit: String(ACTIVITY_PAGE_LIMIT) });
    if (filters.before_id !== undefined) params.set("before_id", String(filters.before_id));
    if (filters.status !== undefined) params.set("status", filters.status);
    if (filters.command !== undefined) params.set("command", filters.command);
    if (filters.caller_node_id !== undefined) {
        params.set("caller_node_id", String(filters.caller_node_id));
    }
    if (filters.target_node_id !== undefined) {
        params.set("target_node_id", String(filters.target_node_id));
    }

    return `/api/v1/activities?${params.toString()}`;
}

// Activity notices refetch these while realtime is live, so they carry no interval of their own.
// The page passes `useTaskPoll()`: every 30 seconds while realtime is down, and every 5 minutes
// while it is live. A hard poll would record another Activity for every tick.

/** The Activity page the operator is reading. The key keeps the filters and, on an older page, `before_id`. */
export function activitiesQuery(filters: ActivityListFilters = {}) {
    const query = activityListFilters(filters);

    return queryOptions({
        queryKey: [...activityListQueryKey, query],
        queryFn: () => get<Activity[]>(activityListPath(query)),
        retry: false,
    });
}

/**
 * One Activity row from `activity:show`, including `properties` and the other fields a notice omits.
 * The list payload is not this query.
 */
export function activityQuery(id: string | number) {
    const key = activityDetailQueryKey(id);

    return queryOptions({
        queryKey: [...key],
        queryFn: () => get<Activity>(`/api/v1/activities/${encodeURIComponent(key[1])}`),
        retry: false,
    });
}

/**
 * The `before_id` of the next older page: the smallest id on a full page.
 * Fewer than `ACTIVITY_PAGE_LIMIT` rows means there is no older page.
 */
export function olderActivityBeforeId(rows: readonly { id: number }[]): number | null {
    if (rows.length < ACTIVITY_PAGE_LIMIT) {
        return null;
    }

    let smallest = rows[0]?.id ?? null;
    for (const row of rows) {
        if (smallest === null || row.id < smallest) {
            smallest = row.id;
        }
    }

    return smallest;
}
