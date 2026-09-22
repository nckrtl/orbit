import { getEventListeners } from "node:events";
import { QueryClient } from "@tanstack/react-query";
import Pusher from "pusher-js";
import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { setTransport, type Transport } from "../api/client";
import { connectRealtime } from "./connect";
import { setLiveness } from "./liveness";

vi.mock("pusher-js", () => ({ default: vi.fn() }));
vi.mock("./liveness", () => ({ setLiveness: vi.fn() }));

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
    const connection = dispatcher();

    return {
        channel,
        connection,
        subscribe: vi.fn(() => channel),
        disconnect: vi.fn(() => connection.emit("state_change", { current: "disconnected" })),
    };
}

let client: QueryClient;
let controller: AbortController;
let transport: ReturnType<typeof vi.fn<Transport>>;
let sockets: ReturnType<typeof socket>[];

beforeEach(() => {
    vi.resetAllMocks();
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
            channelAuthorization: {
                endpoint: "/api/v1/broadcasting/auth",
                transport: "ajax",
                headers: { Accept: "application/json" },
            },
        });
    },
);

it("invalidates all queries only after a previously live connection subscribes again", async () => {
    const invalidate = vi.spyOn(client, "invalidateQueries");
    await connectRealtime(client, controller.signal);
    const pusher = sockets[0]!;

    pusher.connection.emit("state_change", { current: "connected" });
    expect(setLiveness).toHaveBeenLastCalledWith("reconnecting");
    pusher.channel.emit("pusher:subscription_succeeded");
    expect(setLiveness).toHaveBeenLastCalledWith("live");
    expect(invalidate).not.toHaveBeenCalled();

    pusher.connection.emit("state_change", { current: "unavailable" });
    expect(setLiveness).toHaveBeenLastCalledWith("reconnecting");
    pusher.channel.emit("pusher:subscription_succeeded");
    expect(setLiveness).toHaveBeenLastCalledWith("live");
    expect(invalidate).toHaveBeenCalledExactlyOnceWith();
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
