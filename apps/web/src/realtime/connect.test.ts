import { subscribeAnnotationUpdates } from "./annotations";
import { getEventListeners } from "node:events";
import { QueryClient, QueryObserver } from "@tanstack/react-query";
import { activitiesQuery, activityQuery } from "../api/activities";
import Pusher from "pusher-js";
import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { setTransport, type Transport } from "../api/client";
import { flushTaskRefetches } from "./apply";
import { connectRealtime } from "./connect";
import { downForMs, setLiveness } from "./liveness";
import { authorizeChannel, realtimeSocket, setRealtimeSocket } from "./socket";

vi.mock("pusher-js", () => ({ default: vi.fn() }));
vi.mock("./liveness", () => ({ setLiveness: vi.fn(), downForMs: vi.fn(() => 0) }));

const configured = { url: "wss://reverb.orbit", key: "test-key", channel: "orbit" };
const unavailable = { url: null, key: null, channel: "orbit" };
const response = (data: unknown) => ({ status: 200, payload: { data } });
const event = {
    type: "process.status",
    id: 1,
    at: "2026-09-22T00:00:00+00:00",
    data: { id: 1, runtime_status: "active" },
};

function dispatcher() {
    type Callback = (...args: unknown[]) => void;
    const callbacks = new Map<string, Set<Callback>>();
    const global = new Set<Callback>();

    return {
        bind: vi.fn((name: string, callback: Callback) => {
            if (!callbacks.has(name)) {
                callbacks.set(name, new Set());
            }
            callbacks.get(name)!.add(callback);
        }),
        bind_global: vi.fn((callback: Callback) => global.add(callback)),
        unbind: vi.fn((name: string, callback: Callback) => callbacks.get(name)?.delete(callback)),
        unbind_all: vi.fn(() => {
            callbacks.clear();
            global.clear();
        }),
        emit(name: string, payload?: unknown) {
            callbacks.get(name)?.forEach((callback) => callback(payload));
            global.forEach((callback) => callback(name, payload));
        },
    };
}

function socket() {
    const channel = dispatcher();
    const connection = Object.assign(dispatcher(), { socket_id: "123.456" });

    return {
        channel,
        connection,
        subscribe: vi.fn(() => channel),
        unsubscribe: vi.fn(),
        disconnect: vi.fn(() => connection.emit("state_change", { current: "disconnected" })),
    };
}

let client: QueryClient;
let controller: AbortController;
let transport: ReturnType<typeof vi.fn<Transport>>;
let sockets: ReturnType<typeof socket>[];

beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(downForMs).mockReturnValue(0);
    vi.useFakeTimers();
    vi.stubEnv("DEV", true);
    vi.stubGlobal("window", { location: { origin: "http://localhost:5173" } });
    client = new QueryClient();
    client.setQueryData(["processes"], [{ id: 1, runtime_status: "inactive" }]);
    controller = new AbortController();
    transport = vi.fn<Transport>().mockResolvedValue(response(configured));
    setTransport(transport);
    sockets = [];
    vi.mocked(Pusher).mockImplementation(function () {
        const pusher = socket();
        sockets.push(pusher);

        return pusher as unknown as Pusher;
    });
});

afterEach(() => {
    controller.abort();
    client.clear();
    setTransport(null);
    setRealtimeSocket(null);
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
});

it("does not fetch or register cleanup for an already aborted lifetime", async () => {
    controller.abort();

    await connectRealtime(client, controller.signal);

    expect(transport).not.toHaveBeenCalled();
    expect(getEventListeners(controller.signal, "abort")).toHaveLength(0);
    expect(Pusher).not.toHaveBeenCalled();
});

it.each([configured, unavailable])(
    "ignores configuration delivered after abort: %j",
    async (data) => {
        let resolveConfig!: (value: Awaited<ReturnType<Transport>>) => void;
        transport.mockReturnValue(
            new Promise((resolve) => {
                resolveConfig = resolve;
            }),
        );

        const connecting = connectRealtime(client, controller.signal);
        controller.abort();
        resolveConfig(response(data));
        await connecting;

        expect(Pusher).not.toHaveBeenCalled();
        expect(setLiveness).not.toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
        expect(getEventListeners(controller.signal, "abort")).toHaveLength(0);
    },
);

it("keeps one abort owner across repeated retries and cancels the pending retry", async () => {
    transport.mockResolvedValue(response(unavailable));
    await connectRealtime(client, controller.signal);

    for (let attempt = 1; attempt <= 20; attempt++) {
        expect(transport).toHaveBeenCalledTimes(attempt);
        expect(transport).toHaveBeenLastCalledWith("GET", "/api/v1/realtime", undefined);
        expect(getEventListeners(controller.signal, "abort")).toHaveLength(1);
        expect(vi.getTimerCount()).toBe(1);
        await vi.advanceTimersByTimeAsync(30_000);
    }
    expect(setLiveness).toHaveBeenLastCalledWith("polling", null);

    controller.abort();
    await vi.advanceTimersByTimeAsync(60_000);

    expect(transport).toHaveBeenCalledTimes(21);
    expect(getEventListeners(controller.signal, "abort")).toHaveLength(0);
    expect(vi.getTimerCount()).toBe(0);
    expect(Pusher).not.toHaveBeenCalled();
});

it("keeps the polling reason and connects when a later retry is configured", async () => {
    transport.mockResolvedValueOnce({
        status: 403,
        payload: { error: { message: "Node access is required." } },
    });
    await connectRealtime(client, controller.signal);
    expect(setLiveness).toHaveBeenLastCalledWith("polling", "Node access is required.");

    await vi.advanceTimersByTimeAsync(29_999);
    expect(Pusher).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);

    expect(Pusher).toHaveBeenCalledOnce();
    expect(sockets[0]!.subscribe).toHaveBeenCalledWith("private-orbit");
    expect(setLiveness).toHaveBeenLastCalledWith("reconnecting");
    expect(vi.getTimerCount()).toBe(0);
    expect(getEventListeners(controller.signal, "abort")).toHaveLength(1);

    controller.abort();
    expect(sockets[0]!.disconnect).toHaveBeenCalledOnce();
});

it("does not connect when aborted during a retry's configuration fetch", async () => {
    let resolveConfig!: (value: Awaited<ReturnType<Transport>>) => void;
    transport.mockResolvedValueOnce(response(unavailable)).mockReturnValueOnce(
        new Promise((resolve) => {
            resolveConfig = resolve;
        }),
    );
    await connectRealtime(client, controller.signal);
    await vi.advanceTimersByTimeAsync(30_000);
    expect(transport).toHaveBeenCalledTimes(2);

    controller.abort();
    resolveConfig(response(configured));
    await vi.advanceTimersByTimeAsync(60_000);

    expect(Pusher).not.toHaveBeenCalled();
    expect(setLiveness).toHaveBeenCalledExactlyOnceWith("polling", null);
    expect(transport).toHaveBeenCalledTimes(2);
    expect(vi.getTimerCount()).toBe(0);
});

it.each([
    [true, "http://localhost:5173", "wss://reverb.orbit", "localhost", 5173, false],
    [true, "https://orbit.test", "wss://reverb.orbit", "orbit.test", 443, true],
    [false, "http://localhost:5173", "wss://reverb.orbit:8443", "reverb.orbit", 8443, true],
    [false, "http://localhost:5173", "ws://reverb.orbit", "reverb.orbit", 80, false],
] as const)(
    "chooses the socket endpoint (development=%s, origin=%s, url=%s)",
    async (dev, origin, url, host, port, tls) => {
        vi.stubEnv("DEV", dev);
        vi.stubGlobal("window", { location: { origin } });
        transport.mockResolvedValue(response({ ...configured, url }));

        await connectRealtime(client, controller.signal);

        expect(Pusher).toHaveBeenCalledWith("test-key", {
            cluster: "orbit",
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: tls,
            enabledTransports: ["ws", "wss"],
            channelAuthorization: { customHandler: authorizeChannel },
        });
    },
);

it("shares the socket with log panes while it is live, and withdraws it when it drops", async () => {
    await connectRealtime(client, controller.signal);
    const pusher = sockets[0]!;
    expect(realtimeSocket()).toBeNull();

    pusher.channel.emit("pusher:subscription_succeeded");
    const live = realtimeSocket();
    expect(live?.socketId).toBe("123.456");
    live!.subscribe("private-log-stream.abc");
    live!.unsubscribe("private-log-stream.abc");
    expect(pusher.subscribe).toHaveBeenLastCalledWith("private-log-stream.abc");
    expect(pusher.unsubscribe).toHaveBeenCalledWith("private-log-stream.abc");

    pusher.connection.emit("state_change", { current: "unavailable" });
    expect(realtimeSocket()).toBeNull();

    // A reconnect brings a new socket ID, which invalidates every stream signature.
    pusher.connection.socket_id = "789.012";
    pusher.channel.emit("pusher:subscription_succeeded");
    expect(realtimeSocket()?.socketId).toBe("789.012");
    expect(realtimeSocket()).not.toBe(live);

    controller.abort();
    expect(realtimeSocket()).toBeNull();
});

it("reloads what stops polling on the first subscription and everything after a reconnect", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await connectRealtime(client, controller.signal);
    const pusher = sockets[0]!;

    pusher.connection.emit("state_change", { current: "connected" });
    expect(setLiveness).toHaveBeenLastCalledWith("reconnecting");
    pusher.channel.emit("pusher:subscription_succeeded");
    expect(setLiveness).toHaveBeenLastCalledWith("live");
    expect(invalidate.mock.calls.map(([filters]) => filters)).toEqual([
        { queryKey: ["task-groups"] },
        { queryKey: ["tasks-status"] },
        { queryKey: ["processes"] },
        { queryKey: ["activities"] },
    ]);
    invalidate.mockClear();

    pusher.connection.emit("state_change", { current: "unavailable" });
    expect(setLiveness).toHaveBeenLastCalledWith("reconnecting");
    pusher.channel.emit("pusher:subscription_succeeded");
    expect(setLiveness).toHaveBeenLastCalledWith("live");
    expect(invalidate).toHaveBeenCalledExactlyOnceWith();
});

it("reloads every list on a first subscription that follows a polling period", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await connectRealtime(client, controller.signal);
    const pusher = sockets[0]!;

    // The socket failed at load and connects minutes later, while the lists polled with a backoff.
    vi.mocked(downForMs).mockReturnValue(160_000);
    pusher.channel.emit("pusher:subscription_succeeded");

    expect(setLiveness).toHaveBeenLastCalledWith("live");
    expect(invalidate).toHaveBeenCalledExactlyOnceWith();
});

it("reloads every list when a retried realtime discovery finally connects", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    transport.mockResolvedValueOnce(response(unavailable));
    await connectRealtime(client, controller.signal);
    await vi.advanceTimersByTimeAsync(30_000);
    const pusher = sockets[0]!;

    vi.mocked(downForMs).mockReturnValue(30_000);
    pusher.channel.emit("pusher:subscription_succeeded");

    expect(invalidate).toHaveBeenCalledExactlyOnceWith();
});

it("reloads the task, Process, and Activity queries on a first subscription right after page load", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await connectRealtime(client, controller.signal);

    vi.mocked(downForMs).mockReturnValue(1_000);
    sockets[0]!.channel.emit("pusher:subscription_succeeded");

    expect(invalidate.mock.calls.map(([filters]) => filters)).toEqual([
        { queryKey: ["task-groups"] },
        { queryKey: ["tasks-status"] },
        { queryKey: ["processes"] },
        { queryKey: ["activities"] },
    ]);
});

it.each([event, JSON.stringify(event)])(
    "applies object and JSON events while ignoring protocol events: %j",
    async (payload) => {
        await connectRealtime(client, controller.signal);
        const channel = sockets[0]!.channel;

        channel.emit("pusher:internal", payload);
        channel.emit("pusher_internal:internal", payload);
        channel.emit(event.type, null);
        channel.emit(event.type, { type: "process.status", data: null });
        expect(client.getQueryData(["processes"])).toEqual([{ id: 1, runtime_status: "inactive" }]);

        channel.emit(event.type, payload);

        expect(client.getQueryData(["processes"])).toEqual([{ id: 1, runtime_status: "active" }]);
    },
);

it("unbinds callbacks before disconnect and ignores callbacks already queued by an old socket", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await connectRealtime(client, controller.signal);
    const pusher = sockets[0]!;
    pusher.channel.emit("pusher:subscription_succeeded");
    invalidate.mockClear();
    const subscribed = pusher.channel.bind.mock.calls[0]![1];
    const received = pusher.channel.bind_global.mock.calls[0]![0];
    const changed = pusher.connection.bind.mock.calls[0]![1];
    vi.mocked(setLiveness).mockClear();

    controller.abort();

    expect(pusher.channel.unbind_all).toHaveBeenCalledOnce();
    expect(pusher.connection.unbind).toHaveBeenCalledWith("state_change", changed);
    expect(pusher.channel.unbind_all.mock.invocationCallOrder[0]).toBeLessThan(
        pusher.disconnect.mock.invocationCallOrder[0]!,
    );
    expect(pusher.connection.unbind.mock.invocationCallOrder[0]).toBeLessThan(
        pusher.disconnect.mock.invocationCallOrder[0]!,
    );
    pusher.channel.emit(event.type, event);
    subscribed();
    received(event.type, event);
    changed({ current: "disconnected" });

    expect(setLiveness).not.toHaveBeenCalled();
    expect(invalidate).not.toHaveBeenCalled();
    expect(client.getQueryData(["processes"])).toEqual([{ id: 1, runtime_status: "inactive" }]);
    expect(pusher.disconnect).toHaveBeenCalledOnce();
    expect(getEventListeners(controller.signal, "abort")).toHaveLength(0);
});

it("shares the existing socket with annotation subscribers and refreshes on reconnect", async () => {
    const refresh = vi.fn();
    const unsubscribe = subscribeAnnotationUpdates(refresh);
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await connectRealtime(client, controller.signal);
    const channel = sockets[0]!.channel;
    channel.emit("pusher:subscription_succeeded");
    channel.emit("annotation.updated", {
        type: "annotation.updated",
        id: "note",
        data: { instanceId: 107, revision: 2 },
    });
    channel.emit("pusher:subscription_succeeded");
    expect(refresh).toHaveBeenCalledTimes(3);
    expect(Pusher).toHaveBeenCalledTimes(1);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ["instance-annotations"] });
    unsubscribe();
    channel.emit("annotation.updated", { type: "annotation.updated", data: {} });
    expect(refresh).toHaveBeenCalledTimes(3);
});

it("forwards task notices and Process usage from the orbit channel to the query cache", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    client.setQueryData(["tasks-status"], { enabled: true });
    await connectRealtime(client, controller.signal);
    const channel = sockets[0]!.channel;
    channel.emit("pusher:subscription_succeeded");
    invalidate.mockClear();

    channel.emit(
        "task_group.updated",
        JSON.stringify({
            type: "task_group.updated",
            id: 5,
            at: "",
            data: { id: 5, status: "running" },
        }),
    );
    channel.emit("tasks.updated", {
        type: "tasks.updated",
        id: 0,
        at: "",
        data: { enabled: false },
    });
    channel.emit("process.usage", {
        type: "process.usage",
        id: 1_790_000_000,
        at: "",
        data: { part: 1, parts: 1, processes: [[1, 7.5, 2_048]] },
    });

    flushTaskRefetches();
    expect(invalidate.mock.calls.map(([filters]) => filters)).toEqual([
        { queryKey: ["task-groups"], exact: true },
        { queryKey: ["task-groups", "5"], exact: true },
    ]);
    expect(client.getQueryData(["tasks-status"])).toEqual({ enabled: false });
    expect(client.getQueryData(["processes"])).toEqual([
        { id: 1, runtime_status: "inactive", cpu: 7.5, memory_bytes: 2_048 },
    ]);
});

it("forwards activity notices into one batched refetch of the list and the open row", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await connectRealtime(client, controller.signal);
    const channel = sockets[0]!.channel;
    channel.emit("pusher:subscription_succeeded");
    invalidate.mockClear();

    channel.emit("activity.created", {
        type: "activity.created",
        id: 11,
        at: "",
        data: { id: 11, command: "node:add", status: "running" },
    });
    channel.emit(
        "activity.updated",
        JSON.stringify({
            type: "activity.updated",
            id: 11,
            at: "",
            data: { id: 11, command: "node:add", status: "succeeded" },
        }),
    );

    expect(invalidate).not.toHaveBeenCalled();
    flushTaskRefetches();

    expect(invalidate.mock.calls.map(([filters]) => filters)).toEqual([
        { queryKey: ["activities", "list"], exact: false },
        { queryKey: ["activities", "11"], exact: true },
    ]);
});

it("refetches the activity list and the open row when the socket reconnects", async () => {
    const fetched: string[] = [];
    const observe = (queryKey: readonly unknown[], name: string) =>
        new QueryObserver(client, {
            queryKey,
            queryFn: () => {
                fetched.push(name);

                return name;
            },
            staleTime: Infinity,
        }).subscribe(() => {});
    const list = activitiesQuery({ status: "running", before_id: 9 });
    const row = activityQuery(9);
    const unsubscribe = [observe(list.queryKey, "list"), observe(row.queryKey, "row")];
    await vi.waitFor(() => {
        expect(client.getQueryData(list.queryKey)).toBe("list");
        expect(client.getQueryData(row.queryKey)).toBe("row");
    });
    fetched.length = 0;

    await connectRealtime(client, controller.signal);
    const pusher = sockets[0]!;
    pusher.channel.emit("pusher:subscription_succeeded");
    await vi.waitFor(() => expect(fetched.sort()).toEqual(["list", "row"]));
    fetched.length = 0;

    pusher.connection.emit("state_change", { current: "unavailable" });
    pusher.channel.emit("pusher:subscription_succeeded");
    await vi.waitFor(() => expect(fetched.sort()).toEqual(["list", "row"]));
    unsubscribe.forEach((stop) => stop());
});

/** An active Activity query whose first response is held, so the cache stays empty. */
function holdFirstActivity(queryKey: readonly unknown[], fresh: unknown, stale: unknown) {
    let release: (value: unknown) => void = () => {};
    let calls = 0;
    const unsubscribe = new QueryObserver(client, {
        queryKey,
        staleTime: Infinity,
        retry: false,
        queryFn: () => {
            calls += 1;
            if (calls === 1) {
                return new Promise((resolve) => {
                    release = resolve;
                });
            }

            return fresh;
        },
    }).subscribe(() => {});

    return {
        calls: () => calls,
        release: () => release(stale),
        unsubscribe,
    };
}

it("replaces a pending first activity load when the socket subscribes", async () => {
    const list = activitiesQuery({ before_id: 20, status: "running" });
    const detail = activityQuery(8);
    const listLoad = holdFirstActivity(
        list.queryKey,
        [{ id: 8, status: "succeeded" }],
        [{ id: 8, status: "running" }],
    );
    const detailLoad = holdFirstActivity(
        detail.queryKey,
        { id: 8, status: "succeeded" },
        { id: 8, status: "running" },
    );
    expect(listLoad.calls()).toBe(1);
    expect(detailLoad.calls()).toBe(1);
    expect(client.getQueryData(list.queryKey)).toBeUndefined();

    await connectRealtime(client, controller.signal);
    sockets[0]!.channel.emit("pusher:subscription_succeeded");

    expect(listLoad.calls()).toBe(2);
    expect(detailLoad.calls()).toBe(2);
    listLoad.release();
    detailLoad.release();
    await vi.waitFor(() => {
        expect(client.getQueryData(list.queryKey)).toEqual([{ id: 8, status: "succeeded" }]);
        expect(client.getQueryData(detail.queryKey)).toEqual({ id: 8, status: "succeeded" });
    });

    expect(listLoad.calls()).toBe(2);
    expect(detailLoad.calls()).toBe(2);
    expect(client.getQueryState(list.queryKey)?.isInvalidated).toBe(false);
    expect(client.getQueryState(detail.queryKey)?.isInvalidated).toBe(false);
    listLoad.unsubscribe();
    detailLoad.unsubscribe();
});

it("replaces a pending first activity load when the socket reconnects", async () => {
    await connectRealtime(client, controller.signal);
    const pusher = sockets[0]!;
    pusher.channel.emit("pusher:subscription_succeeded");

    const list = activitiesQuery({ before_id: 20, status: "running" });
    const detail = activityQuery(8);
    const listLoad = holdFirstActivity(
        list.queryKey,
        [{ id: 8, status: "succeeded" }],
        [{ id: 8, status: "running" }],
    );
    const detailLoad = holdFirstActivity(
        detail.queryKey,
        { id: 8, status: "succeeded" },
        { id: 8, status: "running" },
    );
    expect(listLoad.calls()).toBe(1);
    expect(detailLoad.calls()).toBe(1);

    pusher.connection.emit("state_change", { current: "unavailable" });
    pusher.channel.emit("pusher:subscription_succeeded");

    expect(listLoad.calls()).toBe(2);
    expect(detailLoad.calls()).toBe(2);
    listLoad.release();
    detailLoad.release();
    await vi.waitFor(() => {
        expect(client.getQueryData(list.queryKey)).toEqual([{ id: 8, status: "succeeded" }]);
        expect(client.getQueryData(detail.queryKey)).toEqual({ id: 8, status: "succeeded" });
    });

    expect(listLoad.calls()).toBe(2);
    expect(detailLoad.calls()).toBe(2);
    listLoad.unsubscribe();
    detailLoad.unsubscribe();
});
