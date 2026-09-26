import {
    infiniteQueryOptions,
    type InfiniteData,
    type QueryClient,
    queryOptions,
} from "@tanstack/react-query";
import type { components } from "./schema";
import { get } from "./client";

/** One stored Activity row, as `activity:list` and `activity:show` return it. */
export type Activity = Required<components["schemas"]["Activity"]>;

export type ActivityStatus = "running" | "succeeded" | "failed";

/**
 * Filters the Activity page sends. An omitted field is not sent.
 * `before_id` is the older-page cursor, never one of these filters and never a URL field.
 */
export type ActivityListFilters = {
    status?: ActivityStatus;
    command?: string;
    caller_node_id?: number;
    target_node_id?: number;
};

/** The page asks for this many rows. Fewer means there is no older page. */
export const ACTIVITY_PAGE_LIMIT = 50;

/** Prefix of every Activity list query. Notices write into these cached pages. */
export const activityListQueryKey = ["activities", "list"] as const;

/** Cached pages of one filtered log, newest page first. */
export type ActivityLog = InfiniteData<Activity[], number | undefined>;

/** Query key of one Activity row. `activity:show` is the only source for that row. */
export function activityDetailQueryKey(id: string | number): readonly [string, string] {
    return ["activities", String(id)];
}

/** The list queries are `["activities", "list", filters]`. A detail query is `["activities", id]`. */
export function isActivityListQueryKey(queryKey: readonly unknown[]): boolean {
    return queryKey[0] === "activities" && queryKey[1] === "list";
}

/** Drops unused filters so an empty command is the same query as no command. */
function activityFilters(filters: ActivityListFilters): ActivityListFilters {
    const query: ActivityListFilters = {};
    if (filters.status !== undefined) query.status = filters.status;
    if (filters.command !== undefined && filters.command !== "") query.command = filters.command;
    if (filters.caller_node_id !== undefined) query.caller_node_id = filters.caller_node_id;
    if (filters.target_node_id !== undefined) query.target_node_id = filters.target_node_id;

    return query;
}

function activityListPath(filters: ActivityListFilters, beforeId?: number): string {
    const params = new URLSearchParams({ limit: String(ACTIVITY_PAGE_LIMIT) });
    if (beforeId !== undefined) params.set("before_id", String(beforeId));
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

// The list has no refetch interval of its own. While realtime is down the page rebuilds the loaded
// range every 30 seconds, and every 5 minutes while it is live. A hard poll would record another
// Activity for every tick. Query v5 recomputes each refetch cursor from the page it just fetched; it
// does not reuse the previous page's `before_id`. It still stops after the number of pages already
// loaded, so a new head drops the tail. The rebuild continues until the previous oldest id is covered.

/**
 * The continuous Activity log. The key is the filters, not the cursor.
 * The next page sets `before_id` to the oldest id of the page just returned.
 * A page shorter than `ACTIVITY_PAGE_LIMIT` is the end.
 */
export function activitiesQuery(filters: ActivityListFilters = {}) {
    const query = activityFilters(filters);
    const queryKey = [...activityListQueryKey, query] as const;

    return infiniteQueryOptions({
        queryKey,
        initialPageParam: undefined as number | undefined,
        queryFn: async ({ pageParam, signal, client }) => {
            watchActivityClient(client);
            const request = trackRequest(queryKey, "page", cachedActivityIds(client, queryKey));
            const cancel = () => {
                request.cancelled = true;
                releaseRequest(queryKey, request);
            };
            signal.addEventListener("abort", cancel);
            try {
                const rows = await get<Activity[]>(activityListPath(query, pageParam));
                if (request.cancelled || signal.aborted) {
                    releaseRequest(queryKey, request);
                    throw new DOMException(
                        "The activity list request was cancelled.",
                        "AbortError",
                    );
                }
                const latest = client.getQueryData(queryKey);
                if (pageParam === undefined) {
                    noteHeadPage(
                        queryKey,
                        rows,
                        request.generation,
                        isActivityLog(latest) ? latest : undefined,
                    );
                } else {
                    noteOlderPage(queryKey, rows, request.generation);
                }
                request.rows = rows;
                pageCommits.set(rows, request);

                return rows;
            } catch (error) {
                releaseRequest(queryKey, request);
                throw error;
            } finally {
                signal.removeEventListener("abort", cancel);
            }
        },
        getNextPageParam: (_lastPage, allPages) => nextActivityBeforeId(queryKey, allPages),
        // Query recomputes refetch cursors, then stops at the page count already loaded. That drops
        // the tail when new rows shift the head. The page rebuilds that range, and a reconnect merges
        // the newest page, so this query does not refetch on mount, focus, or browser reconnect.
        structuralSharing: (previous, next) =>
            isActivityLog(next) ? reconcileServerWrite(queryKey, previous, next) : next,
        refetchOnMount: false,
        refetchOnWindowFocus: false,
        refetchOnReconnect: false,
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

/** Reads the Activity page's URL search. An invalid or unused filter is omitted, as the list request omits it. */
export function readActivitySearch(search: Record<string, unknown>): ActivityListFilters {
    const filters: ActivityListFilters = {};
    const status = search.status;
    const command = search.command;
    if (status === "running" || status === "succeeded" || status === "failed") {
        filters.status = status;
    }
    if (typeof command === "string" && command.length >= 1 && command.length <= 255) {
        filters.command = command;
    }
    const caller = positiveId(search.caller_node_id);
    const target = positiveId(search.target_node_id);
    if (caller !== undefined) filters.caller_node_id = caller;
    if (target !== undefined) filters.target_node_id = target;

    return filters;
}

/** A positive integer id from a URL search value. Anything else is an unused filter. */
function positiveId(value: unknown): number | undefined {
    if (typeof value === "number" && Number.isSafeInteger(value) && value >= 1) {
        return value;
    }
    if (typeof value === "string" && /^[1-9]\d*$/.test(value)) {
        const parsed = Number(value);
        if (Number.isSafeInteger(parsed)) return parsed;
    }

    return undefined;
}

function oldestId(rows: readonly { id: number }[]): number | null {
    let oldest: number | null = null;
    for (const row of rows) {
        if (oldest === null || row.id < oldest) oldest = row.id;
    }

    return oldest;
}

function newestId(rows: readonly { id: number }[]): number | null {
    let newest: number | null = null;
    for (const row of rows) {
        if (newest === null || row.id > newest) newest = row.id;
    }

    return newest;
}

/**
 * The `before_id` of the next older page: the smallest id on a full page.
 * Fewer than `ACTIVITY_PAGE_LIMIT` rows means there is no older page.
 */
export function olderActivityBeforeId(rows: readonly { id: number }[]): number | null {
    if (rows.length < ACTIVITY_PAGE_LIMIT) {
        return null;
    }

    return oldestId(rows);
}

function filtersFromKey(key: readonly unknown[]): ActivityListFilters {
    const value = key[2];
    if (value === null || typeof value !== "object") return {};

    return activityFilters(value as ActivityListFilters);
}

function isActivityLog(value: unknown): value is ActivityLog {
    if (value === null || typeof value !== "object" || !("pages" in value)) return false;

    const pages = (value as { pages?: unknown }).pages;

    return Array.isArray(pages) && pages.every((page) => Array.isArray(page));
}

function nullableId(value: unknown): number | null {
    return positiveId(value) ?? null;
}

function nullableInteger(value: unknown): number | null {
    return typeof value === "number" && Number.isSafeInteger(value) ? value : null;
}

/** The list columns a notice may carry. Absent fields are left as they are on a patch. */
function noticePatch(
    data: Record<string, unknown>,
): { id: number; fields: Partial<Activity> } | null {
    const id = positiveId(data.id);
    if (id === undefined) return null;

    const fields: Partial<Activity> = {};
    if (typeof data.request_id === "string") fields.request_id = data.request_id;
    if (typeof data.command === "string") fields.command = data.command;
    if (typeof data.status === "string") fields.status = data.status;
    if ("caller_node_id" in data) fields.caller_node_id = nullableId(data.caller_node_id);
    if ("target_node_id" in data) fields.target_node_id = nullableId(data.target_node_id);
    if ("error_code" in data) {
        fields.error_code = typeof data.error_code === "string" ? data.error_code : null;
    }
    if ("duration_ms" in data) fields.duration_ms = nullableInteger(data.duration_ms);
    if (typeof data.occurred_at === "string") fields.occurred_at = data.occurred_at;

    return { id, fields };
}

/** A notice has every list column and none of the show-only fields. Those stay empty until `activity:show`. */
function activityFromNotice(data: Record<string, unknown>): Activity | null {
    const patch = noticePatch(data);
    if (patch === null) return null;

    return {
        id: patch.id,
        request_id: "",
        command: "",
        caller_node_id: null,
        target_node_id: null,
        caller_ip: null,
        status: "",
        duration_ms: null,
        exit_code: null,
        error_code: null,
        subject_type: null,
        subject_id: null,
        properties: {},
        occurred_at: "",
        ...patch.fields,
    };
}

type CreatedNotice = { seq: number; kind: "created"; row: Activity };
type UpdatedNotice = { seq: number; kind: "updated"; id: number; fields: Partial<Activity> };
type ActivityNotice = CreatedNotice | UpdatedNotice;
type ListRequestKind = "page" | "rebuild" | "merge";
type InflightRequest = {
    seq: number;
    /** Higher means the request started later, so its server fields are newer. */
    generation: number;
    kind: ListRequestKind;
    cancelled: boolean;
    released: boolean;
    rows?: Activity[];
    /** Ids already cached when this request started. A refresh may drop those the server omits. */
    idsAtStart: ReadonlySet<number>;
};
type Exhaustion = { exhausted: boolean; generation: number };

let noticeSeq = 0;
let listGeneration = 0;
const noticeLog: ActivityNotice[] = [];
const inflight = new Map<string, InflightRequest[]>();
/** The page array returned by a list `queryFn`, until that response is committed or dropped. */
const pageCommits = new WeakMap<Activity[], InflightRequest>();
/** Whether a server page has shown that the filtered log has no older row, and which request said so. */
const serverExhausted = new Map<string, Exhaustion>();
/** The newest request generation that wrote each cached id's server fields. */
const fieldGeneration = new Map<string, Map<number, number>>();
/** Whether a request decided the id belongs in this filtered log, and how new that decision is. */
const membership = new Map<string, Map<number, { present: boolean; generation: number }>>();
const watchedClients = new WeakSet<QueryClient>();

/** Drops bookkeeping for list requests. Tests start from an empty log. */
export function resetActivityListBookkeeping(): void {
    noticeSeq = 0;
    listGeneration = 0;
    noticeLog.length = 0;
    inflight.clear();
    serverExhausted.clear();
    fieldGeneration.clear();
    membership.clear();
}

/** Notices kept only because a list request still has to replay them. */
export function retainedActivityNoticeCount(): number {
    return noticeLog.length;
}

function requestKey(queryKey: readonly unknown[]): string {
    return JSON.stringify(queryKey);
}

function cachedActivityIds(client: QueryClient, queryKey: readonly unknown[]): Set<number> {
    const cached = client.getQueryData(queryKey);

    return new Set(isActivityLog(cached) ? cached.pages.flat().map((row) => row.id) : []);
}

/** Notices that arrive after a list request starts must survive that request's response. */
function trackRequest(
    queryKey: readonly unknown[],
    kind: ListRequestKind,
    idsAtStart: ReadonlySet<number>,
): InflightRequest {
    const key = requestKey(queryKey);
    listGeneration += 1;
    const request: InflightRequest = {
        seq: noticeSeq,
        generation: listGeneration,
        kind,
        cancelled: false,
        released: false,
        idsAtStart,
    };
    const list = inflight.get(key) ?? [];
    list.push(request);
    inflight.set(key, list);

    return request;
}

function releaseRequest(queryKey: readonly unknown[], request: InflightRequest): void {
    if (request.released) return;
    request.released = true;
    const key = requestKey(queryKey);
    const list = (inflight.get(key) ?? []).filter((entry) => entry !== request);
    if (list.length === 0) inflight.delete(key);
    else inflight.set(key, list);
    pruneNotices();
}

/** Drops a removed log's requests so its notices do not stay after the page closes. */
function cancelRequests(queryKey: readonly unknown[]): void {
    const key = requestKey(queryKey);
    for (const request of inflight.get(key) ?? []) {
        request.cancelled = true;
        request.released = true;
    }
    inflight.delete(key);
    serverExhausted.delete(key);
    fieldGeneration.delete(key);
    membership.delete(key);
    pruneNotices();
}

function watchActivityClient(client: QueryClient): void {
    if (watchedClients.has(client)) return;
    watchedClients.add(client);
    client.getQueryCache().subscribe((event) => {
        if (event.type !== "removed" || !isActivityListQueryKey(event.query.queryKey)) return;
        cancelRequests(event.query.queryKey);
    });
}

function inflightFloor(queryKey: readonly unknown[]): number | null {
    const list = inflight.get(requestKey(queryKey));
    if (list === undefined || list.length === 0) return null;

    return list.reduce((floor, entry) => Math.min(floor, entry.seq), Number.POSITIVE_INFINITY);
}

function pruneNotices(): void {
    if (inflight.size === 0) {
        noticeLog.length = 0;

        return;
    }

    let floor = Number.POSITIVE_INFINITY;
    for (const list of inflight.values()) {
        for (const entry of list) floor = Math.min(floor, entry.seq);
    }
    while (noticeLog.length > 0 && noticeLog[0]!.seq <= floor) noticeLog.shift();
}

function pushNotice(notice: Omit<CreatedNotice, "seq"> | Omit<UpdatedNotice, "seq">): void {
    // A notice is applied to the cache immediately. Replay is only needed while a response is open.
    if (inflight.size === 0) return;
    noticeSeq += 1;
    noticeLog.push({ ...notice, seq: noticeSeq } as ActivityNotice);
}

/**
 * A short newest page ends the filtered log.
 * A full newest page does not clear an end that still has an older cached tail.
 * A response older than the recorded decision does not change it.
 */
function noteHeadPage(
    queryKey: readonly unknown[],
    rows: readonly { id: number }[],
    generation: number,
    current: ActivityLog | undefined,
): void {
    const key = requestKey(queryKey);
    const marked = serverExhausted.get(key);
    if (marked !== undefined && marked.generation > generation) return;
    if (rows.length < ACTIVITY_PAGE_LIMIT) {
        serverExhausted.set(key, { exhausted: true, generation });

        return;
    }
    const oldest = oldestId(rows);
    const olderTail =
        current !== undefined &&
        oldest !== null &&
        current.pages.some((page) => page.some((row) => row.id < oldest));
    if (marked?.exhausted === true && olderTail) return;
    serverExhausted.set(key, { exhausted: false, generation });
}

/** The older page is the tail, so its length is whether the log continues. */
function noteOlderPage(
    queryKey: readonly unknown[],
    rows: readonly unknown[],
    generation: number,
): void {
    const key = requestKey(queryKey);
    const marked = serverExhausted.get(key);
    if (marked !== undefined && marked.generation > generation) return;
    serverExhausted.set(key, {
        exhausted: rows.length < ACTIVITY_PAGE_LIMIT,
        generation,
    });
}

/**
 * The rebuilt range is a fresh read. Its last page decides whether older rows exist, unless a
 * later response already recorded that decision.
 */
function noteRebuildRange(
    queryKey: readonly unknown[],
    lastRows: readonly unknown[],
    generation: number,
): void {
    const key = requestKey(queryKey);
    const marked = serverExhausted.get(key);
    if (marked !== undefined && marked.generation > generation) return;
    serverExhausted.set(key, {
        exhausted: lastRows.length < ACTIVITY_PAGE_LIMIT,
        generation,
    });
}

function rememberMembership(
    queryKey: readonly unknown[],
    id: number,
    present: boolean,
    generation: number,
): void {
    const key = requestKey(queryKey);
    const decisions =
        membership.get(key) ?? new Map<number, { present: boolean; generation: number }>();
    if (!membership.has(key)) membership.set(key, decisions);
    const known = decisions.get(id);
    if (known !== undefined && known.generation > generation) return;
    decisions.set(id, { present, generation });
}

/** A later rebuild removed this id, so an older response must not put it back. */
function removedByNewerRequest(
    queryKey: readonly unknown[],
    id: number,
    generation: number,
): boolean {
    const known = membership.get(requestKey(queryKey))?.get(id);

    return known !== undefined && !known.present && known.generation > generation;
}

function nextActivityBeforeId(
    queryKey: readonly unknown[],
    allPages: readonly { id: number }[][],
): number | undefined {
    const marked = serverExhausted.get(requestKey(queryKey));
    if (marked?.exhausted === true) return undefined;
    if (marked === undefined) {
        return olderActivityBeforeId(allPages.at(-1) ?? []) ?? undefined;
    }

    return oldestId(allPages.flat()) ?? undefined;
}

function withFreshFields(
    queryKey: readonly unknown[],
    generation: number,
    previous: Activity | undefined,
    incoming: Activity,
): Activity {
    const key = requestKey(queryKey);
    const generations = fieldGeneration.get(key) ?? new Map<number, number>();
    if (!fieldGeneration.has(key)) fieldGeneration.set(key, generations);
    const known = generations.get(incoming.id) ?? 0;
    if (previous !== undefined && known > generation) return previous;
    generations.set(incoming.id, generation);

    return previous === undefined ? incoming : { ...previous, ...incoming };
}

/**
 * Reapplies notices that arrived after the oldest in-flight request started.
 * A finished request is dropped once its response has been reconciled.
 */
function liveWrite(page: Activity[] | undefined): InflightRequest | undefined {
    if (page === undefined) return undefined;
    const request = pageCommits.get(page);
    if (request === undefined || request.released) return undefined;

    return request;
}

/**
 * A Query page write carries the page arrays from `queryFn`. A fetch of the next page also carries
 * the stale snapshot of earlier pages, so those rows come from the cache instead.
 */
function reconcileServerWrite(
    queryKey: readonly unknown[],
    previous: unknown,
    next: ActivityLog,
): ActivityLog {
    const live = next.pages
        .map((page) => liveWrite(page))
        .filter((request): request is InflightRequest => request !== undefined);
    if (live.length > 0) {
        const last = liveWrite(next.pages.at(-1));
        const allFresh = live.length === next.pages.length;
        let merged = next;
        if (last !== undefined && isActivityLog(previous)) {
            const fetched = last.rows ?? next.pages.at(-1) ?? [];
            if (allFresh) {
                const earliest = live.reduce((left, right) =>
                    left.generation <= right.generation ? left : right,
                );
                merged = reconcileRebuild(
                    queryKey,
                    earliest.generation,
                    previous,
                    next.pages.flat(),
                    fetched.length < ACTIVITY_PAGE_LIMIT,
                    earliest.idsAtStart,
                );
            } else {
                merged = appendFetchedPage(queryKey, last.generation, previous, fetched);
            }
        }
        const floor = inflightFloor(queryKey);
        const reconciled =
            floor === null ? merged : applyNotices(merged, floor, filtersFromKey(queryKey));
        for (const request of live) releaseRequest(queryKey, request);

        return reconciled;
    }

    const floor = inflightFloor(queryKey);
    if (floor === null) return next;

    return applyNotices(next, floor, filtersFromKey(queryKey));
}

/** Keeps every cached row, and lets this request's fields replace them only when it is newer. */
function appendFetchedPage(
    queryKey: readonly unknown[],
    generation: number,
    current: ActivityLog,
    fetched: readonly Activity[],
): ActivityLog {
    const byId = new Map<number, Activity>();
    for (const row of current.pages.flat()) {
        if (removedByNewerRequest(queryKey, row.id, generation)) continue;
        byId.set(row.id, row);
    }
    for (const row of fetched) {
        if (removedByNewerRequest(queryKey, row.id, generation)) continue;
        byId.set(row.id, withFreshFields(queryKey, generation, byId.get(row.id), row));
        rememberMembership(queryKey, row.id, true, generation);
    }

    return chunkActivityLog([...byId.values()].sort((left, right) => right.id - left.id));
}

/**
 * The rebuilt rows replace the range they cover.
 * A row above that range, or any row when the response is empty, stays only when it was added
 * after the rebuild started. Older rows stay when the rebuild did not reach the end.
 * A later response's fields and removals win.
 */
function reconcileRebuild(
    queryKey: readonly unknown[],
    generation: number,
    current: ActivityLog,
    rebuiltRows: readonly Activity[],
    ended: boolean,
    idsAtStart: ReadonlySet<number>,
): ActivityLog {
    const oldest = oldestId(rebuiltRows);
    const newest = newestId(rebuiltRows);
    const rebuiltIds = new Set(rebuiltRows.map((row) => row.id));
    const currentById = new Map(current.pages.flat().map((row) => [row.id, row]));
    const byId = new Map<number, Activity>();
    const empty = rebuiltRows.length === 0;
    for (const row of current.pages.flat()) {
        if (removedByNewerRequest(queryKey, row.id, generation)) continue;
        if (rebuiltIds.has(row.id)) continue;
        if (empty || newest === null) {
            if (!idsAtStart.has(row.id)) byId.set(row.id, row);
            continue;
        }
        if (row.id > newest) {
            if (!idsAtStart.has(row.id)) byId.set(row.id, row);
            continue;
        }
        if (!ended && oldest !== null && row.id < oldest) byId.set(row.id, row);
    }
    for (const row of rebuiltRows) {
        if (removedByNewerRequest(queryKey, row.id, generation)) continue;
        byId.set(row.id, withFreshFields(queryKey, generation, currentById.get(row.id), row));
        rememberMembership(queryKey, row.id, true, generation);
    }
    for (const id of idsAtStart) {
        if (rebuiltIds.has(id)) continue;
        const belowUnfetchedTail = !ended && oldest !== null && id < oldest;
        if (!belowUnfetchedTail) rememberMembership(queryKey, id, false, generation);
    }

    return chunkActivityLog([...byId.values()].sort((left, right) => right.id - left.id));
}

function applyNotices(
    log: ActivityLog,
    afterSeq: number,
    filters: ActivityListFilters,
): ActivityLog {
    const pending = noticeLog.filter((notice) => notice.seq > afterSeq);
    if (pending.length === 0) return log;

    let pages = log.pages.map((page) => page.slice());
    for (const notice of pending) {
        if (notice.kind === "updated") {
            pages = pages.map((page) =>
                page.map((row) => (row.id === notice.id ? { ...row, ...notice.fields } : row)),
            );
            continue;
        }
        if (!activityMatches(notice.row, filters)) continue;
        if (pages.some((page) => page.some((row) => row.id === notice.row.id))) continue;
        const [first = [], ...rest] = pages;
        pages = [[notice.row, ...first], ...rest];
    }

    return { ...log, pages };
}

/** Newest id first, in chunks of `ACTIVITY_PAGE_LIMIT`, so a later page cannot jump ahead. */
function chunkActivityLog(rows: readonly Activity[]): ActivityLog {
    if (rows.length === 0) return { pages: [[]], pageParams: [undefined] };

    const pages: Activity[][] = [];
    const pageParams: Array<number | undefined> = [];
    for (let index = 0; index < rows.length; index += ACTIVITY_PAGE_LIMIT) {
        pages.push(rows.slice(index, index + ACTIVITY_PAGE_LIMIT));
        const previous = pages[pages.length - 2];
        pageParams.push(previous === undefined ? undefined : (oldestId(previous) ?? undefined));
    }

    return { pages, pageParams };
}

/** A null caller does not match a caller filter, and a null target does not match a target filter. */
function activityMatches(row: Activity, filters: ActivityListFilters): boolean {
    if (filters.status !== undefined && row.status !== filters.status) return false;
    if (filters.command !== undefined && row.command !== filters.command) return false;
    if (filters.caller_node_id !== undefined && row.caller_node_id !== filters.caller_node_id) {
        return false;
    }
    if (filters.target_node_id !== undefined && row.target_node_id !== filters.target_node_id) {
        return false;
    }

    return true;
}

/**
 * Inserts a matching `activity.created` at the top of each cached log.
 * An id that is already loaded is left where it is. A log that has not loaded is left unloaded.
 */
export function insertActivityCreated(client: QueryClient, data: Record<string, unknown>): void {
    const created = activityFromNotice(data);
    if (created === null) return;
    pushNotice({ kind: "created", row: created });

    for (const [key, log] of client.getQueriesData<unknown>({ queryKey: activityListQueryKey })) {
        if (!isActivityLog(log) || !activityMatches(created, filtersFromKey(key))) continue;
        if (log.pages.some((page) => page.some((row) => row.id === created.id))) continue;

        const [first = [], ...rest] = log.pages;
        client.setQueryData<ActivityLog>(key, {
            pages: [[created, ...first], ...rest],
            pageParams: log.pageParams.length === 0 ? [undefined] : log.pageParams,
        });
    }
}

/**
 * Patches a loaded row in place. The row stays when its new fields do not match the active filters.
 * A row that is not loaded is not added.
 */
export function patchActivityUpdated(client: QueryClient, data: Record<string, unknown>): void {
    const patch = noticePatch(data);
    if (patch === null) return;
    pushNotice({ kind: "updated", id: patch.id, fields: patch.fields });

    for (const [key, log] of client.getQueriesData<unknown>({ queryKey: activityListQueryKey })) {
        if (!isActivityLog(log)) continue;

        let found = false;
        const pages = log.pages.map((page) => {
            if (!page.some((row) => row.id === patch.id)) return page;

            found = true;

            return page.map((row) => (row.id === patch.id ? { ...row, ...patch.fields } : row));
        });
        if (!found) continue;

        client.setQueryData<ActivityLog>(key, { ...log, pages });
    }
}

/**
 * Unions every cached row with the newest fetched page, newest id first.
 * Fetched fields replace cached fields. A cached row the page omits stays.
 * The same id is stored once, including when the fetched page overlaps an older cached page.
 */
export function mergeActivityFirstPage(
    log: ActivityLog,
    fetched: readonly Activity[],
): ActivityLog {
    const byId = new Map<number, Activity>();
    for (const page of log.pages) {
        for (const row of page) byId.set(row.id, row);
    }
    for (const row of fetched) {
        const previous = byId.get(row.id);
        byId.set(row.id, previous === undefined ? row : { ...previous, ...row });
    }

    return chunkActivityLog([...byId.values()].sort((left, right) => right.id - left.id));
}

/**
 * Fetches the newest page of each cached log and merges it by id.
 * One request per filter set, with no `before_id`, so a reconnect does not refetch every loaded page.
 */
export async function mergeCachedActivityLists(client: QueryClient): Promise<void> {
    const cached = client.getQueriesData<unknown>({ queryKey: activityListQueryKey });
    await Promise.all(
        cached.map(async ([key, log]) => {
            if (!isActivityLog(log)) return;

            watchActivityClient(client);
            const request = trackRequest(key, "merge", cachedActivityIds(client, key));
            try {
                const fetched = await get<Activity[]>(activityListPath(filtersFromKey(key)));
                if (request.cancelled) return;
                const latest = client.getQueryData(key);
                noteHeadPage(
                    key,
                    fetched,
                    request.generation,
                    isActivityLog(latest) ? latest : undefined,
                );
                client.setQueryData<ActivityLog>(key, (current) => {
                    if (!isActivityLog(current) || request.cancelled) return current;

                    return applyNotices(
                        appendFetchedPage(key, request.generation, current, fetched),
                        request.seq,
                        filtersFromKey(key),
                    );
                });
            } catch (error) {
                if (request.cancelled) return;
                throw error;
            } finally {
                releaseRequest(key, request);
            }
        }),
    );
}

/**
 * Rebuilds the loaded range from the newest page. Each cursor is the oldest id of the page just
 * returned, never a cursor saved before this refresh. The requests stop when a short page ends the
 * log or the oldest returned id is the oldest id that was loaded, or an older one. Rows loaded
 * outside that range while the rebuild was open stay, and a short page still ends the log.
 */
export async function rebuildActivityList(
    client: QueryClient,
    filters: ActivityListFilters,
): Promise<void> {
    const options = activitiesQuery(filters);
    const existing = client.getQueryData<unknown>(options.queryKey);
    if (!isActivityLog(existing)) return;

    watchActivityClient(client);
    const query = filtersFromKey(options.queryKey);
    const oldestLoaded = oldestId(existing.pages.flat());
    const request = trackRequest(
        options.queryKey,
        "rebuild",
        cachedActivityIds(client, options.queryKey),
    );
    const pages: Activity[][] = [];
    const pageParams: Array<number | undefined> = [];
    const seen = new Set<number>();
    let before: number | undefined;
    let lastRows: Activity[] = [];
    try {
        for (;;) {
            if (request.cancelled) return;
            const rows = await get<Activity[]>(activityListPath(query, before));
            if (request.cancelled) return;
            lastRows = rows;
            const page = rows.filter((row) => {
                if (seen.has(row.id)) return false;
                seen.add(row.id);

                return true;
            });
            pages.push(page);
            pageParams.push(before);

            const next = olderActivityBeforeId(rows);
            const oldest = oldestId(rows);
            if (next === null || oldest === null) break;
            if (before !== undefined && oldest >= before) break;
            if (oldestLoaded !== null && oldest <= oldestLoaded) break;
            before = next;
        }

        if (request.cancelled) return;
        const ended = lastRows.length < ACTIVITY_PAGE_LIMIT;
        client.setQueryData<ActivityLog>(options.queryKey, (current) => {
            if (request.cancelled) return isActivityLog(current) ? current : undefined;
            const rebuiltRows = pages.flat();
            noteRebuildRange(options.queryKey, lastRows, request.generation);
            if (!isActivityLog(current)) {
                return applyNotices({ pages, pageParams }, request.seq, query);
            }
            const merged = reconcileRebuild(
                options.queryKey,
                request.generation,
                current,
                rebuiltRows,
                ended,
                request.idsAtStart,
            );

            return applyNotices(merged, request.seq, query);
        });
    } catch (error) {
        if (request.cancelled) return;
        throw error;
    } finally {
        releaseRequest(options.queryKey, request);
    }
}
