import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { setTransport, type Transport } from "../api/client";
import {
    initialLogTailState,
    LOG_TAIL_MAX_LINES,
    LogTail,
    type LogTailState,
    logMarkers,
    logTailPolls,
    pickLogLines,
} from "./log-stream";
import { authorizeChannel, type RealtimeSocket } from "./socket";

type Answer = { status: number; payload: unknown };
type Callback = (data: unknown) => void;

const PATH = "/api/v1/instances/7/log-streams";
const streamId = (n: number) => String(n).padStart(32, "0");

function opened(n: number) {
    return {
        status: 201,
        payload: {
            data: {
                id: streamId(n),
                channel: `private-log-stream.${streamId(n)}`,
                auth: `key:signature-${n}`,
                lines: 500,
                lease_seconds: 60,
                renew_seconds: 20,
            },
        },
    };
}

const failure = (status: number, code: string, details: unknown = null): Answer => ({
    status,
    payload: { error: { code, message: code, details } },
});

function channel() {
    const callbacks = new Map<string, Set<Callback>>();

    return {
        bind: vi.fn((name: string, callback: Callback) => {
            if (!callbacks.has(name)) callbacks.set(name, new Set());
            callbacks.get(name)!.add(callback);
        }),
        unbind_all: vi.fn(() => callbacks.clear()),
        emit(name: string, payload?: unknown) {
            callbacks.get(name)?.forEach((callback) => callback(payload));
        },
    };
}

function socket(socketId = "123.456") {
    const channels = new Map<string, ReturnType<typeof channel>>();
    const signatures: unknown[] = [];

    return {
        socketId,
        channels,
        signatures,
        subscribe: vi.fn((name: string) => {
            // pusher-js may authorize at once, so the signature must already be stored.
            authorizeChannel({ socketId, channelName: name }, (error, data) =>
                signatures.push(error ?? data),
            );
            const bound = channel();
            channels.set(name, bound);

            return bound;
        }),
        unsubscribe: vi.fn(),
    } satisfies RealtimeSocket & Record<string, unknown>;
}

const lines = (id: number, sequence: number, extra: Record<string, unknown> = {}) => ({
    type: "log.lines",
    id: streamId(id),
    at: "2026-09-25T10:00:00+00:00",
    data: { sequence, lines: [], dropped: 0, skipped: 0, ...extra },
});
const ended = (id: number, reason: string) => ({
    type: "log.ended",
    id: streamId(id),
    at: "2026-09-25T10:00:00+00:00",
    data: { reason },
});

let transport: ReturnType<typeof vi.fn<Transport>>;
let answers: Record<string, Answer[]>;
let states: LogTailState[];
let tail: LogTail;

const calls = (method: string) =>
    transport.mock.calls
        .filter(([called]) => called === method)
        .map(([, path, body, options]) => ({
            path,
            body,
            options,
        }));
const state = () => states.at(-1) ?? initialLogTailState;

beforeEach(() => {
    vi.useFakeTimers();
    answers = { POST: [], PUT: [], DELETE: [] };
    transport = vi.fn<Transport>(async (method, path) => {
        const queued = answers[method]?.shift();

        if (queued !== undefined) return queued;
        if (method === "PUT")
            return { status: 200, payload: { data: { id: "x", lease_seconds: 60 } } };
        if (method === "DELETE")
            return { status: 200, payload: { data: { id: "x", closed: true } } };

        throw new Error(`Unexpected ${method} ${path}`);
    });
    setTransport(transport);
    states = [];
    tail = new LogTail({ kind: "instances", id: 7, lines: 500 }, (next) => states.push(next));
});

afterEach(() => {
    tail.dispose();
    setTransport(null);
    vi.useRealTimers();
});

it("opens, shows the first lines, appends new ones, renews, and closes", async () => {
    const live = socket();
    answers.POST!.push(opened(1));

    tail.connect(live);
    expect(state().status).toBe("opening");
    expect(logTailPolls(state().status)).toBe(false);
    await vi.advanceTimersByTimeAsync(0);

    expect(calls("POST")).toEqual([
        { path: PATH, body: { socket_id: "123.456", lines: 500 }, options: undefined },
    ]);
    expect(live.subscribe).toHaveBeenCalledExactlyOnceWith(`private-log-stream.${streamId(1)}`);
    expect(live.signatures).toEqual([{ auth: "key:signature-1" }]);
    expect(state()).toMatchObject({ status: "streaming", subscribed: false, lines: null });
    expect(logTailPolls(state().status)).toBe(false);

    // The stream stays inactive, and unrenewed, until its channel is subscribed.
    await vi.advanceTimersByTimeAsync(30_000);
    expect(calls("PUT")).toEqual([]);

    const bound = live.channels.get(`private-log-stream.${streamId(1)}`)!;
    bound.emit("pusher:subscription_succeeded");
    expect(state().subscribed).toBe(true);
    const renewal = { path: `${PATH}/${streamId(1)}`, body: undefined, options: undefined };
    expect(calls("PUT")).toEqual([renewal]);
    // Order: open, subscribe, then the first renewal that starts the agent's reading.
    const order = transport.mock.calls.map(([method]) => method);
    expect(order).toEqual(["POST", "PUT"]);
    expect(live.subscribe.mock.invocationCallOrder[0]!).toBeLessThan(
        transport.mock.invocationCallOrder[1]!,
    );
    // A repeated subscription does not renew again.
    bound.emit("pusher:subscription_succeeded");
    expect(calls("PUT")).toHaveLength(1);

    bound.emit("log.lines", lines(1, 1, { lines: ["one", "two"] }));
    expect(state().lines).toEqual(["one", "two"]);

    // A JSON string payload, a repeated sequence, and another stream's event.
    bound.emit(
        "log.lines",
        JSON.stringify(lines(1, 2, { lines: ["three"], dropped: 120, skipped: 5 * 1024 * 1024 })),
    );
    bound.emit("log.lines", lines(1, 2, { lines: ["again"] }));
    bound.emit("log.lines", lines(2, 3, { lines: ["foreign"] }));
    expect(state().lines).toEqual([
        "one",
        "two",
        "[orbit] 120 lines dropped",
        "[orbit] 5.0 MiB skipped",
        "three",
    ]);

    await vi.advanceTimersByTimeAsync(19_999);
    expect(calls("PUT")).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(calls("PUT")).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(20_000);
    expect(calls("PUT")).toEqual([renewal, renewal, renewal]);
    expect(calls("POST")).toHaveLength(1);

    tail.dispose();
    await vi.advanceTimersByTimeAsync(60_000);

    expect(calls("DELETE")).toEqual([
        { path: `${PATH}/${streamId(1)}`, body: undefined, options: undefined },
    ]);
    expect(calls("PUT")).toHaveLength(3);
    expect(bound.unbind_all).toHaveBeenCalled();
    expect(live.unsubscribe).toHaveBeenCalledWith(`private-log-stream.${streamId(1)}`);

    // The signature is gone with the stream.
    const after = await new Promise((resolve) =>
        authorizeChannel(
            { socketId: "123.456", channelName: `private-log-stream.${streamId(1)}` },
            (error) => resolve(error),
        ),
    );
    expect(after).toBeInstanceOf(Error);
});

it("keeps at most 2,000 lines", async () => {
    const live = socket();
    answers.POST!.push(opened(1));
    tail.connect(live);
    await vi.advanceTimersByTimeAsync(0);
    const bound = live.channels.get(`private-log-stream.${streamId(1)}`)!;

    const batch = (from: number) => Array.from({ length: 900 }, (_, i) => `line ${from + i}`);
    bound.emit("log.lines", lines(1, 1, { lines: batch(0) }));
    bound.emit("log.lines", lines(1, 2, { lines: batch(900) }));
    bound.emit("log.lines", lines(1, 3, { lines: batch(1800) }));

    expect(state().lines).toHaveLength(LOG_TAIL_MAX_LINES);
    expect(state().lines![0]).toBe("line 700");
    expect(state().lines!.at(-1)).toBe("line 2699");
});

it.each([
    [failure(409, "logs.live_unavailable", { reason: "agent_outdated" }), "agent_outdated"],
    [failure(429, "logs.stream_limit"), "logs.stream_limit"],
    [failure(403, "node_access.required"), "node_access.required"],
    [{ status: 500, payload: null }, "http_500"],
])("falls back to polling when the Gateway refuses the stream: %j", async (answer, reason) => {
    const live = socket();
    answers.POST!.push(answer as Answer);

    tail.connect(live);
    await vi.advanceTimersByTimeAsync(0);

    expect(state()).toMatchObject({ status: "fallback", reason });
    expect(logTailPolls(state().status)).toBe(true);
    expect(live.subscribe).not.toHaveBeenCalled();

    // The same socket does not ask again; the pane keeps polling.
    tail.connect(live);
    await vi.advanceTimersByTimeAsync(60_000);
    expect(calls("POST")).toHaveLength(1);
    expect(calls("PUT")).toEqual([]);
});

it.each(["agent_left", "source_unavailable", "relay_behind", "closed"])(
    "falls back to polling when the stream ends with %s",
    async (reason) => {
        const live = socket();
        answers.POST!.push(opened(1));
        tail.connect(live);
        await vi.advanceTimersByTimeAsync(0);
        const bound = live.channels.get(`private-log-stream.${streamId(1)}`)!;
        bound.emit("log.lines", lines(1, 1, { lines: ["one"] }));

        bound.emit("log.ended", ended(1, reason));

        expect(state()).toMatchObject({ status: "fallback", reason, lines: ["one"] });
        expect(logTailPolls(state().status)).toBe(true);
        expect(live.unsubscribe).toHaveBeenCalledWith(`private-log-stream.${streamId(1)}`);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(calls("PUT")).toEqual([]);
        expect(calls("DELETE")).toEqual([]);
        expect(calls("POST")).toHaveLength(1);
    },
);

it("opens a new stream when the lease expired, and its first lines replace the old ones", async () => {
    const live = socket();
    answers.POST!.push(opened(1), opened(2));
    tail.connect(live);
    await vi.advanceTimersByTimeAsync(0);
    const first = live.channels.get(`private-log-stream.${streamId(1)}`)!;
    first.emit("log.lines", lines(1, 1, { lines: ["old"] }));

    first.emit("log.ended", ended(1, "expired"));
    expect(state()).toMatchObject({ status: "opening", lines: ["old"] });
    await vi.advanceTimersByTimeAsync(0);

    expect(calls("POST")).toHaveLength(2);
    expect(state().status).toBe("streaming");
    const second = live.channels.get(`private-log-stream.${streamId(2)}`)!;
    second.emit("log.lines", lines(2, 1, { lines: ["new"] }));
    expect(state().lines).toEqual(["new"]);
    // The expired stream's events no longer count.
    first.emit("log.lines", lines(1, 2, { lines: ["late"] }));
    expect(state().lines).toEqual(["new"]);
});

it("stops, and does not reopen, when the stream is revoked", async () => {
    const live = socket();
    answers.POST!.push(opened(1));
    tail.connect(live);
    await vi.advanceTimersByTimeAsync(0);

    tail.connect(socket()); // Same socket ID: nothing changes.
    expect(calls("POST")).toHaveLength(1);

    const bound = live.channels.get(`private-log-stream.${streamId(1)}`)!;
    bound.emit("log.ended", ended(1, "revoked"));
    expect(state()).toMatchObject({ status: "revoked", reason: "revoked" });
    expect(logTailPolls(state().status)).toBe(false);

    tail.connect(null);
    tail.connect(socket("789.012"));
    await vi.advanceTimersByTimeAsync(60_000);
    expect(state().status).toBe("revoked");
    expect(calls("POST")).toHaveLength(1);
});

it("reopens when a renewal finds no stream, and stops when a renewal is refused access", async () => {
    const live = socket();
    answers.POST!.push(opened(1), opened(2));
    answers.PUT!.push(failure(404, "logs.stream_not_found"), failure(403, "node_access.required"));
    tail.connect(live);
    await vi.advanceTimersByTimeAsync(0);

    live.channels.get(`private-log-stream.${streamId(1)}`)!.emit("pusher:subscription_succeeded");
    await vi.advanceTimersByTimeAsync(0);
    expect(calls("POST")).toHaveLength(2);
    expect(live.subscribe).toHaveBeenLastCalledWith(`private-log-stream.${streamId(2)}`);
    expect(state().status).toBe("streaming");
    expect(calls("PUT")).toHaveLength(1);

    live.channels.get(`private-log-stream.${streamId(2)}`)!.emit("pusher:subscription_succeeded");
    await vi.advanceTimersByTimeAsync(0);
    expect(state()).toMatchObject({ status: "revoked" });
    expect(live.unsubscribe).toHaveBeenCalledWith(`private-log-stream.${streamId(2)}`);
});

it("keeps renewing after a renewal fails for another reason", async () => {
    answers.POST!.push(opened(1));
    answers.PUT!.push({ status: 502, payload: null });
    const live = socket();
    tail.connect(live);
    await vi.advanceTimersByTimeAsync(0);
    live.channels.get(`private-log-stream.${streamId(1)}`)!.emit("pusher:subscription_succeeded");

    await vi.advanceTimersByTimeAsync(40_000);

    expect(calls("PUT")).toHaveLength(3);
    expect(state().status).toBe("streaming");
});

it("polls while the socket is down and opens a new stream on the new socket", async () => {
    answers.POST!.push(opened(1), opened(2));
    const first = socket("123.456");
    tail.connect(first);
    await vi.advanceTimersByTimeAsync(0);

    tail.connect(null);
    expect(state().status).toBe("off");
    expect(logTailPolls(state().status)).toBe(true);
    expect(first.unsubscribe).toHaveBeenCalledWith(`private-log-stream.${streamId(1)}`);
    expect(calls("DELETE")).toEqual([
        { path: `${PATH}/${streamId(1)}`, body: undefined, options: undefined },
    ]);

    const second = socket("789.012");
    tail.connect(second);
    await vi.advanceTimersByTimeAsync(0);

    expect(calls("POST").at(-1)).toEqual({
        path: PATH,
        body: { socket_id: "789.012", lines: 500 },
        options: undefined,
    });
    expect(second.signatures).toEqual([{ auth: "key:signature-2" }]);
    expect(state().status).toBe("streaming");
});

it("reopens on a new socket after a fallback", async () => {
    answers.POST!.push(failure(409, "logs.live_unavailable", { reason: "subscriber_down" }));
    answers.POST!.push(opened(1));
    tail.connect(socket("123.456"));
    await vi.advanceTimersByTimeAsync(0);
    expect(state().status).toBe("fallback");

    tail.connect(null);
    tail.connect(socket("789.012"));
    await vi.advanceTimersByTimeAsync(0);

    expect(state().status).toBe("streaming");
});

it("falls back when the channel subscription fails", async () => {
    const live = socket();
    answers.POST!.push(opened(1));
    tail.connect(live);
    await vi.advanceTimersByTimeAsync(0);

    live.channels.get(`private-log-stream.${streamId(1)}`)!.emit("pusher:subscription_error", {});

    expect(state()).toMatchObject({ status: "fallback", reason: "subscription_error" });
    expect(calls("DELETE")).toHaveLength(1);
});

it("closes a stream that opens after the pane closed", async () => {
    let answer!: (value: Answer) => void;
    const live = socket();
    transport.mockImplementationOnce(() => new Promise((resolve) => (answer = resolve)));
    tail.connect(live);

    tail.dispose();
    answer(opened(1));
    await vi.advanceTimersByTimeAsync(60_000);

    expect(live.subscribe).not.toHaveBeenCalled();
    expect(calls("DELETE")).toEqual([
        { path: `${PATH}/${streamId(1)}`, body: undefined, options: undefined },
    ]);
    expect(calls("PUT")).toEqual([]);
});

it("closes the stream with a keepalive request when the page unloads", async () => {
    answers.POST!.push(opened(1));
    tail.connect(socket());
    await vi.advanceTimersByTimeAsync(0);

    tail.unload();

    expect(calls("DELETE")).toEqual([
        { path: `${PATH}/${streamId(1)}`, body: undefined, options: { keepalive: true } },
    ]);
});

it("uses the Process path for a Process log", async () => {
    const process = new LogTail({ kind: "processes", id: 3, lines: 100 }, () => undefined);
    answers.POST!.push(opened(1));
    process.connect(socket());
    await vi.advanceTimersByTimeAsync(0);
    process.dispose();

    expect(calls("POST")[0]).toMatchObject({
        path: "/api/v1/processes/3/log-streams",
        body: { socket_id: "123.456", lines: 100 },
    });
    expect(calls("DELETE")[0]!.path).toBe(`/api/v1/processes/3/log-streams/${streamId(1)}`);
});

it("writes markers for dropped lines and skipped bytes", () => {
    expect(logMarkers(0, 0)).toEqual([]);
    expect(logMarkers(1, 0)).toEqual(["[orbit] 1 line dropped"]);
    expect(logMarkers(120, 5 * 1024 * 1024)).toEqual([
        "[orbit] 120 lines dropped",
        "[orbit] 5.0 MiB skipped",
    ]);
    expect(logMarkers(0, 1_572_864)).toEqual(["[orbit] 1.5 MiB skipped"]);
});

it("polls only while no stream runs or opens", () => {
    expect(logTailPolls("off")).toBe(true);
    expect(logTailPolls("fallback")).toBe(true);
    expect(logTailPolls("opening")).toBe(false);
    expect(logTailPolls("streaming")).toBe(false);
    expect(logTailPolls("revoked")).toBe(false);
});

it("shows the newer of the streamed and the polled lines", () => {
    const streamed = { ...initialLogTailState, lines: ["streamed"], updatedAt: 2_000 };

    expect(pickLogLines(initialLogTailState, ["polled"], 1_000)).toEqual(["polled"]);
    expect(pickLogLines(initialLogTailState, undefined, 0)).toBeUndefined();
    expect(pickLogLines(streamed, undefined, 0)).toEqual(["streamed"]);
    expect(pickLogLines(streamed, ["polled"], 1_000)).toEqual(["streamed"]);
    expect(pickLogLines(streamed, ["polled"], 3_000)).toEqual(["polled"]);
});
