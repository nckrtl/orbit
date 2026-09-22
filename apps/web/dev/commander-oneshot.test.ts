import type { IncomingMessage, ServerResponse } from "node:http";
import { Readable } from "node:stream";
import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { commanderOneShot } from "./commander-oneshot";

const mocks = vi.hoisted(() => ({
    fetch: vi.fn(),
    Agent: vi.fn(
        class {
            close = vi.fn(async () => undefined);
        },
    ),
}));
vi.mock("undici", () => ({ fetch: mocks.fetch, Agent: mocks.Agent }));

const requestBody = {
    project_id: "orbit",
    title: "Fix this button",
    description: { comment: "Fix this button" },
    kind: "one-shot",
    creation_key: "annotation:test-only",
};

beforeEach(() => {
    vi.clearAllMocks();
    vi.stubEnv("COMMANDER_MCP_TOKEN", "test-only-secret");
    vi.stubEnv("COMMANDER_URL", "https://commander.example");
    vi.stubEnv("COMMANDER_CA_PATH", "");
    vi.stubEnv("COMMANDER_TLS_INSECURE", "0");
});

afterEach(() => vi.unstubAllEnvs());

async function request(body: unknown = requestBody, raw?: string) {
    const use = vi.fn();
    const hook = commanderOneShot().configureServer;
    if (typeof hook !== "function") throw new Error("Missing configureServer hook");
    Reflect.apply(hook, {}, [{ middlewares: { use } }]);
    const middleware = use.mock.calls[0]![0] as (
        req: IncomingMessage,
        res: ServerResponse,
        next: () => void,
    ) => Promise<void>;
    const req = Readable.from([raw ?? JSON.stringify(body)]) as IncomingMessage;
    req.method = "POST";
    req.url = "/__orbit/commander/one-shot";
    const res = { statusCode: 200, setHeader: vi.fn(), end: vi.fn() };
    await middleware(req, res as unknown as ServerResponse, vi.fn());
    expect(res.setHeader).toHaveBeenCalledWith("Content-Type", "application/json");
    const response = String(res.end.mock.calls[0]![0]);
    expect(response).not.toContain("test-only-secret");
    return { status: res.statusCode, body: JSON.parse(response) as unknown };
}

function upstream(payload: unknown, status = 200, raw?: string) {
    mocks.fetch.mockResolvedValue({
        ok: status >= 200 && status < 300,
        status,
        text: vi.fn(async () => raw ?? JSON.stringify(payload)),
    });
}

describe("Commander one-shot adapter outcomes", () => {
    it.each(["structured", "text", "sse"])(
        "validates a %s task and forwards the request fields",
        async (format) => {
            const payload = {
                result:
                    format === "text"
                        ? {
                              content: [
                                  { type: "text", text: JSON.stringify({ task: { id: 42 } }) },
                              ],
                          }
                        : { structuredContent: { task: { id: 42, private: "test-only-secret" } } },
            };
            upstream(
                payload,
                200,
                format === "sse"
                    ? `event: message\ndata: ${JSON.stringify(payload)}\n\n`
                    : undefined,
            );
            expect(await request()).toEqual({ status: 200, body: { task: { id: 42 } } });
            expect(mocks.fetch).toHaveBeenCalledOnce();
            const [url, init] = mocks.fetch.mock.calls[0]!;
            expect(url).toBe("https://commander.example/mcp");
            expect(JSON.parse(init.body)).toMatchObject({
                jsonrpc: "2.0",
                method: "tools/call",
                params: {
                    name: "create-task",
                    arguments: {
                        ...requestBody,
                        description: JSON.stringify(requestBody.description),
                        acceptance_criteria: "",
                    },
                },
            });
        },
    );

    it.each([
        {
            error: { message: "test-only-secret" },
            result: { structuredContent: { task: { id: 42 } } },
        },
        { result: { isError: true, structuredContent: { task: { id: 42 } } } },
        { result: { isError: "invalid", structuredContent: { task: { id: 42 } } } },
        null,
        {},
        { result: {} },
        { result: { structuredContent: { task: null } } },
        ...[null, 0, -1, 1.5, "42", Number.MAX_SAFE_INTEGER + 1].map((id) => ({
            result: { structuredContent: { task: { id } } },
        })),
        { result: { content: [{ text: "not JSON" }] } },
    ])("rejects remote errors or invalid task data: %j", async (payload) => {
        upstream(payload);
        expect(await request()).toMatchObject({ status: 502, body: { error: expect.any(String) } });
    });

    it.each(["not JSON", "data: not JSON\n", "event: ping\n"])(
        "rejects malformed response data: %s",
        async (raw) => {
            upstream(null, 200, raw);
            expect(await request()).toMatchObject({
                status: 502,
                body: { error: expect.any(String) },
            });
        },
    );

    it("returns a safe HTTP failure without the upstream body", async () => {
        upstream(null, 503, "test-only-secret".repeat(1000));
        expect(await request()).toEqual({
            status: 503,
            body: { error: "Commander MCP returned HTTP 503." },
        });
    });

    it.each(["fetch", "read"])("returns a safe error on %s failure", async (failure) => {
        if (failure === "fetch") mocks.fetch.mockRejectedValue(new Error("test-only-secret"));
        else
            mocks.fetch.mockResolvedValue({
                text: vi.fn(async () => {
                    throw new Error("test-only-secret");
                }),
            });
        expect(await request()).toEqual({
            status: 500,
            body: { error: "Commander one-shot request failed." },
        });
    });

    it("rejects malformed browser JSON without forwarding", async () => {
        expect(await request(null, "invalid JSON")).toMatchObject({ status: 500 });
        expect(mocks.fetch).not.toHaveBeenCalled();
    });

    it("retains request validation", async () => {
        expect(await request({ ...requestBody, title: "" })).toMatchObject({ status: 422 });
        expect(mocks.fetch).not.toHaveBeenCalled();
    });

    it("returns an explicit token-free dry run without allocating or forwarding", async () => {
        vi.stubEnv("COMMANDER_MCP_TOKEN", "");
        expect(await request()).toEqual({
            status: 200,
            body: { dry_run: true, warning: "COMMANDER_MCP_TOKEN unset; one-shot not forwarded" },
        });
        expect(mocks.fetch).not.toHaveBeenCalled();
        expect(mocks.Agent).not.toHaveBeenCalled();
    });
});
