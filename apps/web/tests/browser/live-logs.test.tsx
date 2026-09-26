import { afterEach, expect, it } from "vite-plus/test";
import type { Transport } from "../../src/api/client";
import { setLiveness } from "../../src/realtime/liveness";
import { type RealtimeSocket, setRealtimeSocket } from "../../src/realtime/socket";
import { openApp, pane } from "./app";

const STREAM = "0123456789abcdef0123456789abcdef";
const CHANNEL = `private-log-stream.${STREAM}`;

afterEach(() => {
    setRealtimeSocket(null);
    setLiveness("polling");
});

/** A live socket whose channels the test drives by hand. */
function fakeSocket() {
    const channels = new Map<string, Map<string, (data: unknown) => void>>();
    const socket: RealtimeSocket = {
        socketId: "123.456",
        subscribe: (name) => {
            const handlers = new Map<string, (data: unknown) => void>();
            channels.set(name, handlers);

            return {
                bind: (event, callback) => handlers.set(event, callback),
                unbind_all: () => handlers.clear(),
            };
        },
        unsubscribe: (name) => channels.delete(name),
    };
    const emit = (event: string, data: Record<string, unknown>) =>
        channels.get(CHANNEL)?.get(event)?.({ type: event, id: STREAM, at: "", data });

    return { socket, channels, emit };
}

/** The demo Gateway, plus the log stream routes, with the realtime discovery held so the test owns the liveness. */
const withLogStreams =
    (calls: string[]) =>
    (inner: Transport): Transport =>
    (method, path, body) => {
        calls.push(`${method} ${path}`);

        if (path === "/api/v1/realtime") {
            return new Promise(() => undefined);
        }

        if (method === "POST" && path === "/api/v1/processes/2/log-streams") {
            return Promise.resolve({
                status: 201,
                payload: {
                    data: {
                        id: STREAM,
                        channel: CHANNEL,
                        auth: "key:signature",
                        lines: 100,
                        lease_seconds: 60,
                        renew_seconds: 20,
                    },
                },
            });
        }

        if (path === `/api/v1/processes/2/log-streams/${STREAM}`) {
            return Promise.resolve({ status: 200, payload: { data: { id: STREAM } } });
        }

        return inner(method, path, body);
    };

it("follows a Process log through a live stream without polling, and polls once it ends", async () => {
    const calls: string[] = [];
    const { socket, channels, emit } = fakeSocket();
    setLiveness("live");
    setRealtimeSocket(socket);

    await openApp("/processes/2", { wrapTransport: withLogStreams(calls) });
    await expect.poll(() => channels.has(CHANNEL)).toBe(true);
    const open = "POST /api/v1/processes/2/log-streams";
    const renew = `PUT /api/v1/processes/2/log-streams/${STREAM}`;
    expect(calls).toContain(open);
    // The Gateway starts the stream on its first renewal, which waits for the subscription.
    expect(calls).not.toContain(renew);

    emit("pusher:subscription_succeeded", {});
    expect(calls.filter((call) => call.includes("/log-streams"))).toEqual([open, renew]);
    emit("log.lines", { sequence: 1, lines: ["worker started"], dropped: 0, skipped: 0 });
    emit("log.lines", { sequence: 2, lines: ["job 1 done"], dropped: 3, skipped: 1_572_864 });

    const log = pane("Log");
    await expect.element(log).toHaveTextContent("job 1 done");
    await expect.element(log).toHaveTextContent("[orbit] 3 lines dropped");
    await expect.element(log).toHaveTextContent("[orbit] 1.5 MiB skipped");
    await expect.element(log).toHaveTextContent("live");
    expect(calls.filter((call) => call.endsWith("/logs"))).toEqual([]);

    emit("log.ended", { reason: "agent_left" });

    await expect.element(log).toHaveTextContent("is ready.");
    expect(calls.filter((call) => call.endsWith("/logs"))).toEqual([
        "GET /api/v1/processes/2/logs",
    ]);
    expect(channels.has(CHANNEL)).toBe(false);
});

it("polls when the Gateway refuses the stream", async () => {
    const calls: string[] = [];
    setLiveness("live");
    setRealtimeSocket(fakeSocket().socket);

    await openApp("/processes/2", {
        wrapTransport: (inner) => {
            const wrapped = withLogStreams(calls)(inner);

            return (method, path, body) =>
                method === "POST" && path.endsWith("/log-streams")
                    ? Promise.resolve({
                          status: 409,
                          payload: {
                              error: {
                                  code: "logs.live_unavailable",
                                  message: "The live path is not available.",
                                  details: { reason: "agent_outdated" },
                              },
                          },
                      })
                    : wrapped(method, path, body);
        },
    });

    await expect.element(pane("Log")).toHaveTextContent("is ready.");
    expect(calls).toContain("GET /api/v1/processes/2/logs");
});
