import { environmentManager, QueryClient, QueryObserver } from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from "vite-plus/test";
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
