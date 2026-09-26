import { InfiniteQueryObserver, QueryClient } from "@tanstack/react-query";
import { afterEach, describe, expect, it, vi } from "vite-plus/test";
import {
    ACTIVITY_PAGE_LIMIT,
    activitiesQuery,
    activityQuery,
    insertActivityCreated,
    mergeActivityFirstPage,
    mergeCachedActivityLists,
    olderActivityBeforeId,
    patchActivityUpdated,
    readActivitySearch,
    rebuildActivityList,
    resetActivityListBookkeeping,
    retainedActivityNoticeCount,
    type Activity,
    type ActivityLog,
} from "./activities";
import { setTransport } from "./client";

afterEach(() => {
    setTransport(null);
    resetActivityListBookkeeping();
});

function activity(id: number, patch: Partial<Activity> = {}): Activity {
    return {
        id,
        request_id: "req",
        command: "node:add",
        caller_node_id: 1,
        target_node_id: 2,
        caller_ip: null,
        status: "succeeded",
        duration_ms: 10,
        exit_code: 0,
        error_code: null,
        subject_type: null,
        subject_id: null,
        properties: {},
        occurred_at: "2026-09-20T12:00:00Z",
        ...patch,
    };
}

/** Newest id first, `count` rows ending at `start - count + 1`. */
function pageOf(start: number, count: number, patch: Partial<Activity> = {}): Activity[] {
    return Array.from({ length: count }, (_, index) => activity(start - index, patch));
}

function idsOf(log: { pages: readonly { id: number }[][] } | undefined): number[] {
    return log?.pages.flat().map((row) => row.id) ?? [];
}

describe("activitiesQuery", () => {
    it("loads the newest page with the limit and no unused filters", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);

            return { status: 200, payload: { data: [activity(50)] } };
        });

        const log = await new QueryClient().fetchInfiniteQuery(activitiesQuery({ command: "" }));

        expect(paths).toEqual(["/api/v1/activities?limit=50"]);
        expect(log.pages).toEqual([[activity(50)]]);
        expect(activitiesQuery().queryKey).toEqual(activitiesQuery({ command: "" }).queryKey);
    });

    it("keeps the filters in the key and leaves the cursor out of it", () => {
        expect(activitiesQuery({ status: "failed" }).queryKey).toEqual([
            "activities",
            "list",
            { status: "failed" },
        ]);
        expect(activitiesQuery({ status: "running" }).queryKey).not.toEqual(
            activitiesQuery({ status: "failed" }).queryKey,
        );
    });

    it("pages by before_id until a short page", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);
            const params = new URL(path, "http://localhost").searchParams;
            const before = params.get("before_id");
            const start = before === null ? 120 : Number(before) - 1;
            const count = start <= 20 ? 20 : ACTIVITY_PAGE_LIMIT;

            return { status: 200, payload: { data: pageOf(start, count) } };
        });
        const client = new QueryClient();
        const observer = new InfiniteQueryObserver(
            client,
            activitiesQuery({ command: "node:add" }),
        );
        const unsubscribe = observer.subscribe(() => {});

        try {
            await vi.waitFor(() => expect(observer.getCurrentResult().isFetched).toBe(true));
            while (observer.getCurrentResult().hasNextPage) {
                await observer.fetchNextPage();
            }

            expect(paths).toEqual([
                "/api/v1/activities?limit=50&command=node%3Aadd",
                "/api/v1/activities?limit=50&before_id=71&command=node%3Aadd",
                "/api/v1/activities?limit=50&before_id=21&command=node%3Aadd",
            ]);
            const ids = idsOf(observer.getCurrentResult().data);
            expect(ids).toEqual(Array.from({ length: 120 }, (_, index) => 120 - index));
            expect(new Set(ids).size).toBe(ids.length);
            expect(observer.getCurrentResult().hasNextPage).toBe(false);
        } finally {
            unsubscribe();
        }
    });

    it("does not ask for another page when the first page is short", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);

            return { status: 200, payload: { data: pageOf(4, 4) } };
        });
        const client = new QueryClient();
        const observer = new InfiniteQueryObserver(client, activitiesQuery());
        const unsubscribe = observer.subscribe(() => {});

        try {
            await vi.waitFor(() => expect(observer.getCurrentResult().isSuccess).toBe(true));

            expect(observer.getCurrentResult().hasNextPage).toBe(false);
            expect(paths).toEqual(["/api/v1/activities?limit=50"]);
        } finally {
            unsubscribe();
        }
    });

    it("offers the next page when a rebuild finds history beyond a short page", async () => {
        const options = activitiesQuery({ status: "failed" });
        const client = new QueryClient();
        setTransport(async () => ({
            status: 200,
            payload: { data: pageOf(200, 10, { status: "failed" }) },
        }));
        await client.fetchInfiniteQuery(options);
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});

        try {
            expect(observer.getCurrentResult().hasNextPage).toBe(false);
            const failed = [
                ...pageOf(200, 10, { status: "failed" }),
                ...pageOf(150, 150, { status: "failed" }),
            ];
            setTransport(async (_method, path) => {
                const before = new URL(path, "http://localhost").searchParams.get("before_id");
                const matches = failed.filter((row) => before === null || row.id < Number(before));

                return { status: 200, payload: { data: matches.slice(0, ACTIVITY_PAGE_LIMIT) } };
            });
            await rebuildActivityList(client, { status: "failed" });

            const ids = idsOf(client.getQueryData(options.queryKey));
            expect(ids).toHaveLength(ACTIVITY_PAGE_LIMIT);
            expect(ids.at(-1)).toBe(111);
            expect(observer.getCurrentResult().hasNextPage).toBe(true);
        } finally {
            unsubscribe();
        }
    });
});

describe("activityQuery", () => {
    it("loads one row from activity:show, including the fields a notice omits", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);

            return {
                status: 200,
                payload: {
                    data: activity(40, {
                        command: "process:start",
                        properties: { stdout: "bounded" },
                    }),
                },
            };
        });

        const row = await new QueryClient().fetchQuery(activityQuery("40"));

        expect(paths).toEqual(["/api/v1/activities/40"]);
        expect(row.properties).toEqual({ stdout: "bounded" });
        expect(activityQuery(40).queryKey).toEqual(["activities", "40"]);
        expect(activityQuery("40").queryKey).toEqual(activityQuery(40).queryKey);
    });

    it("surfaces a missing row as the Gateway error", async () => {
        setTransport(async () => ({
            status: 404,
            payload: { error: { code: "http.404", message: "Not found." } },
        }));

        await expect(new QueryClient().fetchQuery(activityQuery(9))).rejects.toMatchObject({
            status: 404,
            code: "http.404",
        });
    });
});

describe("readActivitySearch", () => {
    it("keeps a valid filter and drops an empty, invalid, or cursor value", () => {
        expect(
            readActivitySearch({
                status: "failed",
                command: "node:add",
                caller_node_id: "3",
                target_node_id: 8,
                before_id: 40,
            }),
        ).toEqual({
            status: "failed",
            command: "node:add",
            caller_node_id: 3,
            target_node_id: 8,
        });
        expect(
            readActivitySearch({
                status: "pending",
                command: "",
                caller_node_id: 0,
                target_node_id: "nope",
                before_id: -1,
            }),
        ).toEqual({});
        expect(readActivitySearch({ command: "x".repeat(256) }).command).toBeUndefined();
    });
});

describe("olderActivityBeforeId", () => {
    it("uses the smallest id on a full page, not the last row", () => {
        const rows = Array.from({ length: ACTIVITY_PAGE_LIMIT }, (_, index) => ({
            id: index === 3 ? 4 : 100 - index,
        }));

        expect(olderActivityBeforeId(rows)).toBe(4);
        expect(ACTIVITY_PAGE_LIMIT).toBe(50);
    });

    it("has no older page when the Gateway returns fewer rows than the limit", () => {
        expect(olderActivityBeforeId([])).toBeNull();
        expect(olderActivityBeforeId([{ id: 1 }, { id: 2 }])).toBeNull();
        expect(olderActivityBeforeId(Array.from({ length: 49 }, (_, id) => ({ id })))).toBeNull();
    });
});

describe("activity polling", () => {
    it("does not poll on its own clock", () => {
        expect(activitiesQuery()).not.toHaveProperty("refetchInterval");
        expect(activityQuery(1)).not.toHaveProperty("refetchInterval");
    });
});

describe("mergeActivityFirstPage", () => {
    it("merges the newest page by id and keeps older pages without a duplicate", () => {
        const merged = mergeActivityFirstPage(
            { pages: [pageOf(100, 50), pageOf(50, 50)], pageParams: [undefined, 51] },
            pageOf(110, 50),
        );
        const ids = idsOf(merged);

        expect(ids).toEqual(Array.from({ length: 110 }, (_, index) => 110 - index));
        expect(new Set(ids).size).toBe(ids.length);
        expect(merged.pages.map((page) => page.length)).toEqual([50, 50, 10]);
        for (let index = 1; index < ids.length; index += 1) {
            expect(ids[index - 1]).toBeGreaterThan(ids[index]!);
        }
    });

    it("keeps a cached row the first page omits and lets the fetched fields replace the cached ones", () => {
        const merged = mergeActivityFirstPage(
            {
                pages: [
                    [activity(100), activity(90, { status: "running" }), activity(80)],
                    [activity(70)],
                ],
                pageParams: [undefined, 80],
            },
            [activity(110), activity(100, { status: "failed" }), activity(80)],
        );

        expect(idsOf(merged)).toEqual([110, 100, 90, 80, 70]);
        expect(merged.pages.flat().find((row) => row.id === 100)?.status).toBe("failed");
        expect(merged.pages.flat().find((row) => row.id === 90)?.status).toBe("running");
        expect(idsOf(merged).filter((id) => id === 100)).toEqual([100]);
    });

    it("keeps newest-first order when the fetched page overlaps an older page", () => {
        const merged = mergeActivityFirstPage(
            {
                pages: [
                    pageOf(200, 50, { status: "running" }),
                    pageOf(150, 50, { status: "running" }),
                ],
                pageParams: [undefined, 151],
            },
            [...pageOf(200, 25, { status: "running" }), ...pageOf(140, 25, { status: "running" })],
        );
        const ids = idsOf(merged);

        expect(ids).toEqual(Array.from({ length: 100 }, (_, index) => 200 - index));
        expect(ids).toContain(175);
        expect(ids).toContain(141);
        expect(merged.pages.at(-1)?.length).toBe(ACTIVITY_PAGE_LIMIT);
    });
});

describe("mergeCachedActivityLists", () => {
    it("fetches the newest page once and does not repeat an id", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);

            return { status: 200, payload: { data: pageOf(110, 50) } };
        });
        const client = new QueryClient();
        const key = activitiesQuery({ status: "failed" }).queryKey;
        client.setQueryData<ActivityLog>(key, {
            pages: [pageOf(100, 50), pageOf(50, 50)],
            pageParams: [undefined, 51],
        });

        await mergeCachedActivityLists(client);

        expect(paths).toEqual(["/api/v1/activities?limit=50&status=failed"]);
        const ids = idsOf(client.getQueryData<ActivityLog>(key));
        expect(ids).toEqual(Array.from({ length: 110 }, (_, index) => 110 - index));
        expect(new Set(ids).size).toBe(ids.length);
    });
});

describe("rebuildActivityList", () => {
    it("rebuilds from the newest page with recomputed cursors and leaves no hole", async () => {
        const paths: string[] = [];
        setTransport(async (_method, path) => {
            paths.push(path);
            const before = new URL(path, "http://localhost").searchParams.get("before_id");
            if (before === null) return { status: 200, payload: { data: pageOf(110, 50) } };
            if (before === "61") return { status: 200, payload: { data: pageOf(60, 50) } };
            if (before === "11") return { status: 200, payload: { data: pageOf(10, 10) } };

            throw new Error(path);
        });
        const client = new QueryClient();
        const key = activitiesQuery().queryKey;
        client.setQueryData<ActivityLog>(key, {
            pages: [pageOf(100, 50), pageOf(50, 50)],
            pageParams: [undefined, 51],
        });

        await rebuildActivityList(client, {});

        expect(paths).toEqual([
            "/api/v1/activities?limit=50",
            "/api/v1/activities?limit=50&before_id=61",
            "/api/v1/activities?limit=50&before_id=11",
        ]);
        const ids = idsOf(client.getQueryData<ActivityLog>(key));
        expect(ids).toEqual(Array.from({ length: 110 }, (_, index) => 110 - index));
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids[0]! - ids.at(-1)!).toBe(ids.length - 1);
    });

    it("keeps a notice that arrives while an older rebuild page is in flight", async () => {
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        setTransport((_method, path) => {
            if (path.includes("before_id")) {
                return new Promise((resolve) => {
                    release = resolve;
                });
            }

            return Promise.resolve({ status: 200, payload: { data: pageOf(100, 50) } });
        });
        const client = new QueryClient();
        const key = activitiesQuery().queryKey;
        client.setQueryData<ActivityLog>(key, {
            pages: [pageOf(100, 50), pageOf(50, 50)],
            pageParams: [undefined, 51],
        });

        const pending = rebuildActivityList(client, {});
        await vi.waitFor(() => expect(release).toBeTypeOf("function"));
        insertActivityCreated(client, activity(101));
        patchActivityUpdated(client, { id: 100, status: "failed" });
        release?.({ status: 200, payload: { data: pageOf(50, 50) } });
        await pending;

        const log = client.getQueryData<ActivityLog>(key);
        expect(idsOf(log)).toContain(101);
        expect(log?.pages.flat().find((row) => row.id === 100)?.status).toBe("failed");
        expect(idsOf(log).slice(0, 2)).toEqual([101, 100]);
    });

    it("does not let an older rebuild replace fields a newer reconnect wrote", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        const key = options.queryKey;
        client.setQueryData<ActivityLog>(key, {
            pages: [pageOf(200, 50)],
            pageParams: [undefined],
        });
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        let calls = 0;
        setTransport(
            () =>
                new Promise((resolve) => {
                    calls += 1;
                    if (calls === 1) {
                        release = resolve;

                        return;
                    }
                    resolve({
                        status: 200,
                        payload: { data: pageOf(200, 50, { status: "failed" }) },
                    });
                }),
        );

        const rebuild = rebuildActivityList(client, {});
        await mergeCachedActivityLists(client);
        expect(client.getQueryData<ActivityLog>(key)?.pages[0]?.[0]?.status).toBe("failed");
        release?.({ status: 200, payload: { data: pageOf(200, 50) } });
        await rebuild;

        expect(client.getQueryData<ActivityLog>(key)?.pages[0]?.[0]?.status).toBe("failed");
    });

    it("lets a later reconnect replace fields an earlier rebuild wrote", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        const key = options.queryKey;
        client.setQueryData<ActivityLog>(key, {
            pages: [pageOf(200, 50)],
            pageParams: [undefined],
        });
        setTransport(async () => ({ status: 200, payload: { data: pageOf(200, 50) } }));
        await rebuildActivityList(client, {});
        expect(client.getQueryData<ActivityLog>(key)?.pages[0]?.[0]?.status).toBe("succeeded");

        setTransport(async () => ({
            status: 200,
            payload: { data: pageOf(200, 50, { status: "failed" }) },
        }));
        await mergeCachedActivityLists(client);

        expect(client.getQueryData<ActivityLog>(key)?.pages[0]?.[0]?.status).toBe("failed");
    });

    it("drops head rows the refreshed filter omits and keeps one added during the rebuild", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        const key = options.queryKey;
        client.setQueryData<ActivityLog>(key, {
            pages: [pageOf(200, 50, { status: "running" })],
            pageParams: [undefined],
        });
        for (let id = 200; id >= 191; id -= 1) {
            patchActivityUpdated(client, { id, status: "succeeded" });
        }
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        setTransport(
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );

        const pending = rebuildActivityList(client, { status: "running" });
        await vi.waitFor(() => expect(release).toBeTypeOf("function"));
        insertActivityCreated(client, activity(210, { status: "running" }));
        release?.({
            status: 200,
            payload: { data: pageOf(190, 50, { status: "running" }) },
        });
        await pending;

        const rows = client.getQueryData<ActivityLog>(key)?.pages.flat() ?? [];
        expect(rows[0]?.id).toBe(210);
        expect(rows.map((row) => row.id)).not.toContain(200);
        expect(rows.map((row) => row.id)).not.toContain(191);
        expect(rows.filter((row) => row.status !== "running")).toEqual([]);
        expect(rows[1]?.id).toBe(190);
    });
});

describe("overlapping rebuild and reconnect", () => {
    function watch(options: ReturnType<typeof activitiesQuery>, client: QueryClient) {
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});

        return {
            rows: () => idsOf(client.getQueryData(options.queryKey)).length,
            hasNextPage: () => observer.getCurrentResult().hasNextPage,
            unsubscribe,
        };
    }

    it("keeps rows a newer reconnect recovered when an empty rebuild finishes later", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        client.setQueryData(options.queryKey, { pages: [[]], pageParams: [undefined] });
        const view = watch(options, client);
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        let calls = 0;
        setTransport(
            () =>
                new Promise((resolve) => {
                    calls += 1;
                    if (calls === 1) {
                        release = resolve;

                        return;
                    }
                    resolve({
                        status: 200,
                        payload: { data: pageOf(200, 50, { status: "running" }) },
                    });
                }),
        );

        try {
            const rebuild = rebuildActivityList(client, { status: "running" });
            await mergeCachedActivityLists(client);
            expect(view.rows()).toBe(50);
            expect(view.hasNextPage()).toBe(true);
            release?.({ status: 200, payload: { data: [] } });
            await rebuild;
            expect(view.rows()).toBe(50);
            expect(view.hasNextPage()).toBe(true);
        } finally {
            view.unsubscribe();
        }
    });

    it("lets a reconnect fill the log after an empty rebuild has completed", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        client.setQueryData(options.queryKey, { pages: [[]], pageParams: [undefined] });
        const view = watch(options, client);
        setTransport(async () => ({ status: 200, payload: { data: [] } }));

        try {
            await rebuildActivityList(client, { status: "running" });
            expect(view.rows()).toBe(0);
            expect(view.hasNextPage()).toBe(false);
            setTransport(async () => ({
                status: 200,
                payload: { data: pageOf(200, 50, { status: "running" }) },
            }));
            await mergeCachedActivityLists(client);
            expect(view.rows()).toBe(50);
            expect(view.hasNextPage()).toBe(true);
        } finally {
            view.unsubscribe();
        }
    });

    it("does not let an empty reconnect clear a newer rebuild's next page", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(200, 50, { status: "running" })],
            pageParams: [undefined],
        });
        const view = watch(options, client);
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        let calls = 0;
        setTransport(
            () =>
                new Promise((resolve) => {
                    calls += 1;
                    if (calls === 1) {
                        release = resolve;

                        return;
                    }
                    resolve({
                        status: 200,
                        payload: { data: pageOf(200, 50, { status: "running" }) },
                    });
                }),
        );

        try {
            const merge = mergeCachedActivityLists(client);
            await rebuildActivityList(client, { status: "running" });
            expect(view.rows()).toBe(50);
            expect(view.hasNextPage()).toBe(true);
            release?.({ status: 200, payload: { data: [] } });
            await merge;
            expect(view.rows()).toBe(50);
            expect(view.hasNextPage()).toBe(true);
        } finally {
            view.unsubscribe();
        }
    });

    it("lets a full rebuild restore the next page after an empty reconnect", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(200, 50, { status: "running" })],
            pageParams: [undefined],
        });
        const view = watch(options, client);
        setTransport(async () => ({ status: 200, payload: { data: [] } }));

        try {
            await mergeCachedActivityLists(client);
            expect(view.hasNextPage()).toBe(false);
            setTransport(async () => ({
                status: 200,
                payload: { data: pageOf(200, 50, { status: "running" }) },
            }));
            await rebuildActivityList(client, { status: "running" });
            expect(view.rows()).toBe(50);
            expect(view.hasNextPage()).toBe(true);
        } finally {
            view.unsubscribe();
        }
    });

    it("does not let an older reconnect restore rows a newer rebuild removed", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(200, 50, { status: "running" })],
            pageParams: [undefined],
        });
        const view = watch(options, client);
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        let calls = 0;
        setTransport(
            () =>
                new Promise((resolve) => {
                    calls += 1;
                    if (calls === 1) {
                        release = resolve;

                        return;
                    }
                    resolve({ status: 200, payload: { data: [] } });
                }),
        );

        try {
            const merge = mergeCachedActivityLists(client);
            await rebuildActivityList(client, { status: "running" });
            expect(view.rows()).toBe(0);
            expect(view.hasNextPage()).toBe(false);
            release?.({
                status: 200,
                payload: { data: pageOf(200, 50, { status: "running" }) },
            });
            await merge;
            expect(view.rows()).toBe(0);
            expect(view.hasNextPage()).toBe(false);
        } finally {
            view.unsubscribe();
        }
    });

    it("lets a newer rebuild remove rows an earlier reconnect already merged", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(200, 50, { status: "running" })],
            pageParams: [undefined],
        });
        const view = watch(options, client);
        setTransport(async () => ({
            status: 200,
            payload: { data: pageOf(200, 50, { status: "running" }) },
        }));

        try {
            await mergeCachedActivityLists(client);
            expect(view.rows()).toBe(50);
            setTransport(async () => ({ status: 200, payload: { data: [] } }));
            await rebuildActivityList(client, { status: "running" });
            expect(view.rows()).toBe(0);
            expect(view.hasNextPage()).toBe(false);
        } finally {
            view.unsubscribe();
        }
    });
});

describe("notices during a page load", () => {
    it("keeps an insert and a patch when the next page resolves late", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(100, 50)],
            pageParams: [undefined],
        });
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        setTransport(
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});

        try {
            const pending = observer.fetchNextPage();
            await vi.waitFor(() => expect(release).toBeTypeOf("function"));
            insertActivityCreated(client, activity(101));
            patchActivityUpdated(client, { id: 100, status: "failed" });
            release?.({ status: 200, payload: { data: pageOf(50, 50) } });
            await pending;

            const log = client.getQueryData<ActivityLog>(options.queryKey);
            expect(idsOf(log).slice(0, 2)).toEqual([101, 100]);
            expect(log?.pages.flat().find((row) => row.id === 100)?.status).toBe("failed");
            expect(idsOf(log).at(-1)).toBe(1);
        } finally {
            unsubscribe();
        }
    });
});

describe("reconnect pagination", () => {
    it("keeps a patch that arrives after the newest page was requested", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        const key = options.queryKey;
        client.setQueryData<ActivityLog>(key, {
            pages: [pageOf(100, 50)],
            pageParams: [undefined],
        });
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        setTransport(
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );

        const pending = mergeCachedActivityLists(client);
        await vi.waitFor(() => expect(release).toBeTypeOf("function"));
        patchActivityUpdated(client, { id: 100, status: "failed" });
        release?.({ status: 200, payload: { data: pageOf(100, 50) } });
        await pending;

        expect(
            client
                .getQueryData<ActivityLog>(key)
                ?.pages.flat()
                .find((row) => row.id === 100)?.status,
        ).toBe("failed");
    });

    it("still has a next page when a full server page overlaps an older cached page", async () => {
        const options = activitiesQuery({ status: "running" });
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(200, 50, { status: "running" }), pageOf(150, 10, { status: "running" })],
            pageParams: [undefined, 151],
        });
        setTransport(async () => ({
            status: 200,
            payload: { data: pageOf(210, 50, { status: "running" }) },
        }));

        await mergeCachedActivityLists(client);
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});

        try {
            const ids = idsOf(client.getQueryData(options.queryKey));
            expect(ids).toEqual(Array.from({ length: 70 }, (_, index) => 210 - index));
            expect(observer.getCurrentResult().hasNextPage).toBe(true);
        } finally {
            unsubscribe();
        }
    });

    it("does not offer another page when the server page is short, even if cached rows fill one", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        client.setQueryData<ActivityLog>(options.queryKey, {
            pages: [pageOf(100, 50), pageOf(50, 50)],
            pageParams: [undefined, 51],
        });
        setTransport(async () => ({
            status: 200,
            payload: { data: pageOf(100, 10) },
        }));

        await mergeCachedActivityLists(client);
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});

        try {
            expect(idsOf(client.getQueryData(options.queryKey))).toHaveLength(100);
            expect(observer.getCurrentResult().hasNextPage).toBe(false);
        } finally {
            unsubscribe();
        }
    });

    it("keeps an older page that finishes while a rebuild is open", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(200, 50)],
            pageParams: [undefined],
        });
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        setTransport(async (_method, path) => {
            if (path.includes("before_id")) {
                return { status: 200, payload: { data: pageOf(150, 50) } };
            }

            return new Promise((resolve) => {
                release = resolve;
            });
        });
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});

        try {
            const pending = rebuildActivityList(client, {});
            await observer.fetchNextPage();
            expect(idsOf(client.getQueryData(options.queryKey))).toHaveLength(100);
            release?.({ status: 200, payload: { data: pageOf(200, 50) } });
            await pending;

            const ids = idsOf(client.getQueryData(options.queryKey));
            expect(ids).toHaveLength(100);
            expect(ids[0]).toBe(200);
            expect(ids.at(-1)).toBe(101);
        } finally {
            unsubscribe();
        }
    });

    it("keeps a reconnect head when the older page it overlapped resolves later", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(200, 50)],
            pageParams: [undefined],
        });
        let releaseHead:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        let releaseOlder:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        setTransport(
            (_method, path) =>
                new Promise((resolve) => {
                    if (path.includes("before_id")) releaseOlder = resolve;
                    else releaseHead = resolve;
                }),
        );
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});

        try {
            const merge = mergeCachedActivityLists(client);
            await vi.waitFor(() => expect(releaseHead).toBeTypeOf("function"));
            const next = observer.fetchNextPage();
            await vi.waitFor(() => expect(releaseOlder).toBeTypeOf("function"));
            releaseHead?.({ status: 200, payload: { data: pageOf(210, 50) } });
            await merge;
            expect(idsOf(client.getQueryData(options.queryKey))[0]).toBe(210);
            releaseOlder?.({ status: 200, payload: { data: pageOf(150, 50) } });
            await next;

            const ids = idsOf(client.getQueryData(options.queryKey));
            expect(ids[0]).toBe(210);
            expect(ids.at(-1)).toBe(101);
            expect(new Set(ids).size).toBe(ids.length);
        } finally {
            unsubscribe();
        }
    });
});

describe("activity notice retention", () => {
    it("does not retain notices while no list request is open", () => {
        const client = new QueryClient();
        client.setQueryData(activitiesQuery().queryKey, {
            pages: [pageOf(10, 1)],
            pageParams: [undefined],
        });

        for (let id = 1; id <= 1000; id += 1) {
            insertActivityCreated(client, activity(id));
            patchActivityUpdated(client, { id, status: "failed" });
        }

        expect(retainedActivityNoticeCount()).toBe(0);
    });

    it("drops notices when the Activity query is removed", async () => {
        const options = activitiesQuery();
        const client = new QueryClient();
        client.setQueryData(options.queryKey, {
            pages: [pageOf(50, 50)],
            pageParams: [undefined],
        });
        let release:
            | ((value: { status: number; payload: { data: Activity[] } }) => void)
            | undefined;
        setTransport(
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );
        const observer = new InfiniteQueryObserver(client, options);
        const unsubscribe = observer.subscribe(() => {});
        const pending = observer.fetchNextPage();
        await vi.waitFor(() => expect(release).toBeTypeOf("function"));
        insertActivityCreated(client, activity(51));
        expect(retainedActivityNoticeCount()).toBeGreaterThan(0);

        unsubscribe();
        client.removeQueries({ queryKey: options.queryKey });
        expect(retainedActivityNoticeCount()).toBe(0);

        release?.({ status: 200, payload: { data: pageOf(1, 1) } });
        await pending.catch(() => undefined);
        for (let id = 1; id <= 1000; id += 1) insertActivityCreated(client, activity(id));
        expect(retainedActivityNoticeCount()).toBe(0);
    });
});
