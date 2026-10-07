import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { orbitTransport } from "./orbit-transport";

type Reply = { status?: number; body?: unknown; fail?: boolean };
let replies: Record<string, Reply>;
let requests: { url: string; method: string; body?: unknown }[];
let storage: Map<string, string>;

beforeEach(() => {
    replies = {
        "/api/v1/tasks/status": { body: { data: { enabled: true } } },
        "/annotations": { body: { data: [] } },
    };
    requests = [];
    storage = new Map();
    vi.stubGlobal("window", { location: { href: "https://orbit.test/page" } });
    vi.stubGlobal("sessionStorage", {
        getItem: (key: string) => storage.get(key) ?? null,
        setItem: (key: string, value: string) => storage.set(key, value),
    });
    vi.stubGlobal("fetch", async (input: string | URL, init: RequestInit = {}) => {
        const url = new URL(String(input), "https://orbit.test");
        const method = init.method ?? "GET";
        requests.push({
            url: url.pathname,
            method,
            body: init.body ? JSON.parse(String(init.body)) : undefined,
        });
        const reply =
            method === "POST" && url.pathname === "/annotations"
                ? { body: { data: { ...JSON.parse(String(init.body)), revision: 1 } } }
                : url.pathname.endsWith("/retry")
                  ? { body: { data: { id: "a", revision: 2 } } }
                  : replies[url.pathname];
        if (!reply || reply.fail) throw new TypeError("Failed to fetch");
        return new Response(JSON.stringify(reply.body), { status: reply.status ?? 200 });
    });
});
afterEach(() => vi.unstubAllGlobals());

const annotation = { id: "a", comment: "Smaller", x: 1, y: 2, element: "h1", elementPath: "h1", timestamp: 1 };

it("is available when tasks are enabled and the endpoint answers", async () => {
    const transport = orbitTransport({ serviceUrl: "/annotations" });
    expect(await transport.check!()).toEqual({ state: "available", reason: "Orbit available" });
});

it.each([
    ["disabled", { body: { data: { enabled: false } } }, undefined, "Enable the tasks extension in Orbit."],
    ["invalid", { body: { data: {} } }, undefined, "Invalid Orbit tasks status response."],
    ["offline", { fail: true }, undefined, "Cannot reach Orbit. Check the connection."],
    ["forbidden", undefined, { status: 403, body: {} }, "Cannot access Orbit annotations (HTTP 403)."],
])("explains why Orbit is unavailable: %s", async (_name, status, endpoint, reason) => {
    if (status) replies["/api/v1/tasks/status"] = status as Reply;
    if (endpoint) replies["/annotations"] = endpoint as Reply;
    const transport = orbitTransport({ serviceUrl: "/annotations" });
    expect(await transport.check!()).toEqual({ state: "unavailable", reason });
});

it("uses a custom tasks status endpoint", async () => {
    replies["/custom/status"] = { body: { data: { enabled: true } } };
    const transport = orbitTransport({ serviceUrl: "/annotations", tasksStatusUrl: "/custom/status" });
    await transport.check!();
    expect(requests.map((request) => request.url)).not.toContain("/api/v1/tasks/status");
});

it("sends the selected thread with each annotation and refuses without one", async () => {
    const transport = orbitTransport({ serviceUrl: "/annotations" });
    await expect(transport.submit(annotation)).rejects.toThrow("Enter a T3 thread ID");
    expect(requests).toEqual([]);
    transport.fields![0].save("thread-1");
    expect(storage.get("annotate:t3-thread")).toBe("thread-1");
    expect(await transport.submit(annotation)).toMatchObject({ id: "a", threadId: "thread-1" });
    expect(requests[0]).toMatchObject({ method: "POST", body: { threadId: "thread-1" } });
});

it("prefers a thread saved in the tab over the host's thread", () => {
    storage.set("annotate:t3-thread", "saved");
    const transport = orbitTransport({ serviceUrl: "/annotations", threadId: "host" });
    expect(transport.fields![0].value()).toBe("saved");
    expect(transport.fields![0].status!()).toBe("Saved for this tab");
});

it("retries through the retry endpoint with the annotation's thread", async () => {
    const transport = orbitTransport({ serviceUrl: "/annotations/", threadId: "host" });
    await transport.retry!({ ...annotation, threadId: "original" } as never);
    expect(requests[0]).toEqual({ url: "/annotations/a/retry", method: "POST", body: { threadId: "original" } });
});
