import {
    environmentManager,
    InfiniteQueryObserver,
    QueryClient,
    QueryObserver,
    type QueryFunction,
} from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from "vite-plus/test";
import { activitiesQuery, activityQuery, type Activity, type ActivityLog } from "../api/activities";
import { setTransport } from "../api/client";
import { applyEvent, flushTaskRefetches, TASK_REFETCH_DELAY_MS } from "./apply";
import { lastProcessUsageAt, processPollInterval, resetProcessUsage } from "./process-usage";

let client: QueryClient;
const event = (type: string, data: Record<string, unknown>) => ({
    type,
    id: 1,
    at: "2026-01-01T00:00:00+00:00",
    data,
});

beforeEach(() => {
    resetProcessUsage();
    client = new QueryClient();
    client.setQueryData(
        ["processes"],
        [
            { id: 1, name: "horizon", runtime_status: "active" },
            { id: 2, name: "vite", runtime_status: "inactive" },
        ],
    );
});

afterEach(() => {
    flushTaskRefetches();
    setTransport(null);
    vi.useRealTimers();
});

const invalidated = (spy: { mock: { calls: unknown[][] } }) =>
    spy.mock.calls.map(([filters]) => {
        const { queryKey, exact } = filters as { queryKey?: unknown[]; exact?: boolean };
        return exact === undefined ? { queryKey } : { queryKey, exact };
    });

describe("applyEvent", () => {
    it("merges a status event into the row it names", () => {
        applyEvent(client, event("process.status", { id: 2, runtime_status: "active" }));

        expect(client.getQueryData(["processes"])).toEqual([
            { id: 1, name: "horizon", runtime_status: "active" },
            { id: 2, name: "vite", runtime_status: "active" },
        ]);
    });

    it("appends a record it has not seen and removes a deleted one", () => {
        applyEvent(client, event("process.created", { id: 3, name: "queue" }));
        applyEvent(client, event("process.deleted", { id: 1 }));

        expect(
            (client.getQueryData(["processes"]) as { id: number }[]).map((row) => row.id),
        ).toEqual([2, 3]);
    });

    it("leaves a list alone until it has loaded", () => {
        applyEvent(client, event("node.updated", { id: 1, status: "failed" }));

        expect(client.getQueryData(["nodes"])).toBeUndefined();
    });

    it("ignores an event without an id and a family no pane draws", () => {
        const before = client.getQueryData(["processes"]);
        applyEvent(client, event("process.status", { runtime_status: "failed" }));
        applyEvent(client, event("route.updated", { id: 1 }));

        expect(client.getQueryData(["processes"])).toBe(before);
    });

    it("reloads instances for a deploy step and deployment history for a deployment", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, event("deploy_step.created", { id: 1 }));
        applyEvent(client, event("deployment.updated", { id: 1, app_instance_id: 7 }));

        expect(invalidate.mock.calls.map(([filters]) => filters?.queryKey)).toEqual([
            ["instances"],
            ["deployments", 7],
            ["deployment-log", 1],
        ]);
    });
});

describe("task events", () => {
    it("refetches the task list and that group's own query for a group change", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, event("task_group.created", { id: 7, status: "backlog" }));
        applyEvent(
            client,
            event("task_group.updated", {
                id: 8,
                status: "running",
                lines_added: 3,
                lines_deleted: 1,
                line_diff: 4,
            }),
        );

        flushTaskRefetches();

        expect(invalidated(invalidate)).toEqual([
            { queryKey: ["task-groups"], exact: true },
            { queryKey: ["task-groups", "7"], exact: true },
            { queryKey: ["task-groups", "8"], exact: true },
        ]);
    });

    it("refetches each query once for the notices of one flush, after a short wait", () => {
        vi.useFakeTimers();
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, event("task_group.updated", { id: 8, status: "running" }));
        applyEvent(
            client,
            event("agent_thread.updated", { id: 3, task_group_id: 8, task_id: 1, state: "idle" }),
        );

        expect(invalidate).not.toHaveBeenCalled();
        vi.advanceTimersByTime(TASK_REFETCH_DELAY_MS);

        expect(invalidated(invalidate)).toEqual([
            { queryKey: ["task-groups"], exact: true },
            { queryKey: ["task-groups", "8"], exact: true },
            { queryKey: ["task-groups", "8", "agents"], exact: false },
        ]);
    });

    it("does not refetch a group's agents or comments for a group change", async () => {
        const fetched: string[] = [];
        const query = (queryKey: string[]) =>
            client.prefetchQuery({
                queryKey,
                queryFn: () => {
                    fetched.push(queryKey.join("/"));
                    return [];
                },
            });
        await query(["task-groups"]);
        await query(["task-groups", "8"]);
        await query(["task-groups", "8", "agents"]);
        await query(["task-groups", "8", "tasks", "3", "comments"]);
        fetched.length = 0;
        // invalidateQueries refetches only queries with an observer; observe each one.
        const observers = client
            .getQueryCache()
            .getAll()
            .filter((cached) => cached.queryKey[0] === "task-groups")
            .map((cached) =>
                new QueryObserver(client, {
                    queryKey: cached.queryKey,
                    queryFn: cached.options.queryFn,
                    staleTime: Infinity,
                }).subscribe(() => {}),
            );

        applyEvent(client, event("task_group.updated", { id: 8, status: "running" }));
        flushTaskRefetches();
        await vi.waitFor(() => expect(fetched).toHaveLength(2));
        observers.forEach((unsubscribe) => unsubscribe());

        expect(fetched.sort()).toEqual(["task-groups", "task-groups/8"]);
    });

    it("lets an event's refetch replace a request that started before the change", async () => {
        const key = ["task-groups", "8"];
        let calls = 0;
        let answerOldRequest: (value: unknown) => void = () => {};
        const queryFn = () => {
            calls += 1;
            if (calls === 1) return Promise.resolve({ lines: 0 });
            if (calls === 2) return new Promise((resolve) => (answerOldRequest = resolve));
            return Promise.resolve({ lines: 5 });
        };
        const unsubscribe = new QueryObserver(client, {
            queryKey: key,
            queryFn,
            staleTime: Infinity,
        }).subscribe(() => {});
        await vi.waitFor(() => expect(client.getQueryData(key)).toEqual({ lines: 0 }));
        void client.refetchQueries({ queryKey: key });
        await vi.waitFor(() => expect(calls).toBe(2));

        applyEvent(client, event("task_group.updated", { id: 8, status: "running" }));
        flushTaskRefetches();
        await vi.waitFor(() => expect(client.getQueryData(key)).toEqual({ lines: 5 }));
        answerOldRequest({ lines: 3 });
        await new Promise((resolve) => setTimeout(resolve, 0));
        unsubscribe();

        expect(calls).toBe(3);
        expect(client.getQueryData(key)).toEqual({ lines: 5 });
    });

    it("refetches that subtask's comments for a new comment", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, event("task_comment.created", { id: 40, task_group_id: 8, task_id: 3 }));

        flushTaskRefetches();

        expect(invalidated(invalidate)).toEqual([
            { queryKey: ["task-groups", "8", "tasks", "3", "comments"], exact: false },
        ]);
    });

    it("refetches the group's agent threads and the group for a thread change", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(
            client,
            event("agent_thread.updated", {
                id: 12,
                task_group_id: 8,
                task_id: null,
                state: "working",
            }),
        );

        flushTaskRefetches();

        expect(invalidated(invalidate)).toEqual([
            { queryKey: ["task-groups", "8", "agents"], exact: false },
            { queryKey: ["task-groups", "8"], exact: true },
        ]);
    });

    it("ignores a task notice without the ids it needs", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, event("task_comment.created", { id: 40, task_group_id: 8 }));
        applyEvent(client, event("agent_thread.updated", { id: 12, state: "idle" }));

        flushTaskRefetches();
        expect(invalidate).not.toHaveBeenCalled();
    });

    it("stores the extension status from tasks.updated without a refetch", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        client.setQueryData(["tasks-status"], { enabled: true });
        applyEvent(client, { ...event("tasks.updated", { enabled: false }), id: 0 });

        expect(client.getQueryData(["tasks-status"])).toEqual({ enabled: false });
        flushTaskRefetches();
        expect(invalidate).not.toHaveBeenCalled();
    });

    it("stores the extension status before the status query loaded", () => {
        applyEvent(client, { ...event("tasks.updated", { enabled: true }), id: 0 });

        expect(client.getQueryData(["tasks-status"])).toEqual({ enabled: true });
    });
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
        properties: { name: "spare" },
        occurred_at: "2026-09-20T12:00:00Z",
        ...patch,
    };
}

function logOf(rows: Activity[]): ActivityLog {
    return { pages: [rows], pageParams: [undefined] };
}

describe("activity events", () => {
    const notice = (type: string, data: Record<string, unknown>, id = 1) => ({
        ...event(type, data),
        id,
    });

    it("waits 100ms, then refetches each updated row and does not refetch the list", () => {
        vi.useFakeTimers();
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, notice("activity.updated", { id: 4, status: "failed" }, 4));
        applyEvent(client, notice("activity.updated", { id: 5, status: "failed" }, 5));
        applyEvent(client, notice("activity.created", { id: 6, status: "succeeded" }, 6));

        expect(invalidate).not.toHaveBeenCalled();
        vi.advanceTimersByTime(TASK_REFETCH_DELAY_MS - 1);
        expect(invalidate).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);

        expect(invalidated(invalidate)).toEqual([
            { queryKey: ["activities", "4"], exact: true },
            { queryKey: ["activities", "5"], exact: true },
        ]);
    });

    it("does not refetch an open row for activity.created", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, notice("activity.created", { id: 4, status: "succeeded" }, 4));

        flushTaskRefetches();

        expect(invalidate).not.toHaveBeenCalled();
    });

    it("uses the envelope id when an update notice has none of its own", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, notice("activity.updated", { status: "failed" }, 9));

        flushTaskRefetches();

        expect(invalidated(invalidate)).toEqual([{ queryKey: ["activities", "9"], exact: true }]);
    });

    it("inserts a matching created row at the top and skips one that does not match", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        const failed = activitiesQuery({ status: "failed", command: "node:add" });
        const running = activitiesQuery({ status: "running" });
        client.setQueryData(failed.queryKey, logOf([activity(4, { status: "failed" })]));
        client.setQueryData(
            running.queryKey,
            logOf([activity(3, { status: "running", command: "process:start" })]),
        );

        applyEvent(
            client,
            notice(
                "activity.created",
                {
                    id: 12,
                    command: "node:add",
                    status: "failed",
                    caller_node_id: null,
                    target_node_id: null,
                },
                12,
            ),
        );
        applyEvent(
            client,
            notice(
                "activity.created",
                { id: 11, command: "node:add", status: "running", caller_node_id: 1 },
                11,
            ),
        );
        applyEvent(
            client,
            notice("activity.created", { id: 4, command: "node:add", status: "failed" }, 4),
        );
        flushTaskRefetches();

        expect(invalidate).not.toHaveBeenCalled();
        expect(
            client
                .getQueryData<ActivityLog>(failed.queryKey)
                ?.pages.flat()
                .map((row) => row.id),
        ).toEqual([12, 4]);
        expect(
            client
                .getQueryData<ActivityLog>(running.queryKey)
                ?.pages.flat()
                .map((row) => row.id),
        ).toEqual([11, 3]);
        expect(
            client
                .getQueryData<ActivityLog>(failed.queryKey)
                ?.pages.flat()
                .filter((row) => row.id === 4),
        ).toHaveLength(1);
    });

    it("does not insert a row whose caller or target is null into that node filter", () => {
        const callers = activitiesQuery({ caller_node_id: 2 });
        client.setQueryData(callers.queryKey, logOf([activity(2, { caller_node_id: 2 })]));

        applyEvent(
            client,
            notice(
                "activity.created",
                { id: 9, command: "app:list", status: "succeeded", caller_node_id: null },
                9,
            ),
        );

        expect(
            client
                .getQueryData<ActivityLog>(callers.queryKey)
                ?.pages.flat()
                .map((row) => row.id),
        ).toEqual([2]);
    });

    it("patches a loaded row in place and leaves it when the new status leaves the filter", () => {
        const running = activitiesQuery({ status: "running" });
        client.setQueryData(
            running.queryKey,
            logOf([
                activity(8, {
                    status: "running",
                    command: "process:start",
                    properties: { name: "horizon" },
                }),
                activity(7, { status: "running" }),
            ]),
        );

        applyEvent(
            client,
            notice(
                "activity.updated",
                {
                    id: 8,
                    command: "process:start",
                    status: "failed",
                    error_code: "activity.interrupted",
                    duration_ms: null,
                },
                8,
            ),
        );
        applyEvent(client, notice("activity.updated", { id: 99, status: "failed" }, 99));

        const rows = client.getQueryData<ActivityLog>(running.queryKey)?.pages.flat();
        expect(rows?.map((row) => row.id)).toEqual([8, 7]);
        expect(rows?.[0]).toMatchObject({
            status: "failed",
            error_code: "activity.interrupted",
            duration_ms: null,
            properties: { name: "horizon" },
        });
    });

    it("refetches the open row and not the list", async () => {
        const fetched: string[] = [];
        const filters = { status: "failed" as const, command: "node:add", caller_node_id: 2 };
        const list = activitiesQuery(filters);
        const listObserver = new InfiniteQueryObserver(client, {
            ...list,
            queryFn: () => {
                fetched.push("list");

                return [activity(4, { status: "failed" })];
            },
            staleTime: Infinity,
        });
        const observe = (queryKey: readonly unknown[], name: string) =>
            new QueryObserver(client, {
                queryKey,
                queryFn: () => {
                    fetched.push(name);

                    return name;
                },
                staleTime: Infinity,
            }).subscribe(() => {});
        const open = activityQuery(40);
        const closed = activityQuery(7);
        const unsubscribeList = listObserver.subscribe(() => {});
        const observers = [observe(open.queryKey, "open"), observe(closed.queryKey, "closed")];
        await vi.waitFor(() => {
            expect(listObserver.getCurrentResult().data?.pages[0]?.[0]?.id).toBe(4);
            expect(client.getQueryData(open.queryKey)).toBe("open");
            expect(client.getQueryData(closed.queryKey)).toBe("closed");
        });
        fetched.length = 0;

        applyEvent(client, notice("activity.created", { id: 41, status: "succeeded" }, 41));
        applyEvent(client, notice("activity.updated", { id: 40, status: "failed" }, 40));
        applyEvent(client, notice("activity.updated", { id: 41, status: "succeeded" }, 41));
        applyEvent(client, notice("activity.updated", { id: 40, status: "failed" }, 40));
        flushTaskRefetches();
        await vi.waitFor(() => expect(fetched).toEqual(["open"]));
        observers.forEach((unsubscribe) => unsubscribe());
        unsubscribeList();

        expect(fetched).toEqual(["open"]);
        expect(list.queryKey).toEqual([
            "activities",
            "list",
            { status: "failed", command: "node:add", caller_node_id: 2 },
        ]);
    });

    it("lets an event's refetch replace a show that started before the change", async () => {
        const key = ["activities", "8"];
        let calls = 0;
        let answerOldRequest: (value: unknown) => void = () => {};
        const queryFn = () => {
            calls += 1;
            if (calls === 1) return Promise.resolve({ id: 8, status: "running" });
            if (calls === 2) return new Promise((resolve) => (answerOldRequest = resolve));

            return Promise.resolve({ id: 8, status: "failed" });
        };
        const unsubscribe = new QueryObserver(client, {
            queryKey: key,
            queryFn,
            staleTime: Infinity,
        }).subscribe(() => {});
        await vi.waitFor(() =>
            expect(client.getQueryData(key)).toEqual({ id: 8, status: "running" }),
        );
        void client.refetchQueries({ queryKey: key });
        await vi.waitFor(() => expect(calls).toBe(2));

        applyEvent(client, notice("activity.updated", { id: 8, status: "failed" }, 8));
        flushTaskRefetches();
        await vi.waitFor(() =>
            expect(client.getQueryData(key)).toEqual({ id: 8, status: "failed" }),
        );
        answerOldRequest({ id: 8, status: "running" });
        await new Promise((resolve) => setTimeout(resolve, 0));
        unsubscribe();

        expect(calls).toBe(3);
        expect(client.getQueryData(key)).toEqual({ id: 8, status: "failed" });
    });

    it.each([
        {
            name: "detail",
            options: () => activityQuery(8),
            path: "/api/v1/activities/8",
            stale: { id: 8, status: "running", command: "node:rename" },
            fresh: { id: 8, status: "succeeded", command: "node:rename" },
        },
    ])(
        "replaces a pending first $name load that has not cached a row yet",
        async ({ options, path, stale, fresh }) => {
            const paths: string[] = [];
            let calls = 0;
            let releaseFirst: (data: unknown) => void = () => {};
            setTransport(async (_method, requestPath) => {
                paths.push(requestPath);
                calls += 1;
                if (calls === 1) {
                    return await new Promise((resolve) => {
                        releaseFirst = (data) => resolve({ status: 200, payload: { data } });
                    });
                }

                return { status: 200, payload: { data: fresh } };
            });
            const query = options();
            const unsubscribe = new QueryObserver(client, {
                queryKey: query.queryKey,
                queryFn: query.queryFn as QueryFunction<unknown>,
                staleTime: Infinity,
                retry: false,
            }).subscribe(() => {});

            try {
                await vi.waitFor(() => expect(calls).toBe(1));
                expect(client.getQueryData(query.queryKey)).toBeUndefined();

                applyEvent(client, notice("activity.updated", { id: 8, status: "succeeded" }, 8));
                flushTaskRefetches();

                expect(calls).toBe(2);
                releaseFirst(stale);
                await vi.waitFor(() => expect(client.getQueryData(query.queryKey)).toEqual(fresh));
                await new Promise((resolve) => setTimeout(resolve, 0));

                expect(client.getQueryData(query.queryKey)).toEqual(fresh);
                expect(client.getQueryState(query.queryKey)?.isInvalidated).toBe(false);
                expect(calls).toBe(2);
                expect(paths).toEqual([path, path]);
            } finally {
                releaseFirst(stale);
                unsubscribe();
            }
        },
    );
});

describe("process usage", () => {
    const usage = (processes: unknown[], id = 1_790_000_000) => ({
        ...event("process.usage", { part: 1, parts: 1, processes }),
        id,
    });

    it("writes CPU and memory into the rows it names, nulls included, and skips unknown ids", () => {
        applyEvent(
            client,
            usage([
                [1, 12.5, 104_857_600],
                [2, null, null],
                [99, 3, 1_024],
            ]),
        );

        expect(client.getQueryData(["processes"])).toEqual([
            {
                id: 1,
                name: "horizon",
                runtime_status: "active",
                cpu: 12.5,
                memory_bytes: 104_857_600,
            },
            { id: 2, name: "vite", runtime_status: "inactive", cpu: null, memory_bytes: null },
        ]);
    });

    it("leaves rows without a sample unchanged and never adds a row", () => {
        client.setQueryData(
            ["processes"],
            [
                { id: 1, name: "horizon", cpu: 1, memory_bytes: 2 },
                { id: 2, name: "vite", cpu: 3, memory_bytes: 4 },
            ],
        );
        applyEvent(client, usage([[2, 5, 6]]));

        expect(client.getQueryData(["processes"])).toEqual([
            { id: 1, name: "horizon", cpu: 1, memory_bytes: 2 },
            { id: 2, name: "vite", cpu: 5, memory_bytes: 6 },
        ]);
    });

    it("records when the sample arrived, even before the Process list loaded", () => {
        vi.useFakeTimers({ now: 5_000 });
        client.removeQueries({ queryKey: ["processes"] });
        applyEvent(client, usage([[1, 2, 3]]));

        expect(client.getQueryData(["processes"])).toBeUndefined();
        expect(lastProcessUsageAt()).toBe(5_000);
    });

    it("keeps a live Process list from reloading while samples arrive, and reloads after 60 s without one", async () => {
        vi.useFakeTimers({ now: 1_000_000 });
        // TanStack Query schedules no refetch timer on a server, and Node looks like one.
        environmentManager.setIsServer(() => false);
        onTestFinished(() => environmentManager.setIsServer(() => typeof window === "undefined"));
        const queryFn = vi.fn(async () => [{ id: 1, name: "horizon" }]);
        const unsubscribe = new QueryObserver(client, {
            queryKey: ["processes"],
            queryFn,
            staleTime: Infinity,
            refetchInterval: () => processPollInterval("live", lastProcessUsageAt(), Date.now()),
        }).subscribe(() => {});

        for (let sample = 0; sample < 8; sample++) {
            applyEvent(client, usage([[1, sample, 1]]));
            await vi.advanceTimersByTimeAsync(15_000);
        }
        expect(queryFn).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(45_000);
        expect(queryFn).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(queryFn).toHaveBeenCalledTimes(2);
        unsubscribe();
    });
});
