import { timingSafeEqual } from "node:crypto";
import { createServer, type IncomingMessage, type Server, type ServerResponse } from "node:http";
import { PiServerError, type SessionRegistry, type StreamEvent } from "./registry.ts";

const MAX_BODY_BYTES = 1024 * 1024;

export interface HttpOptions {
    registry: SessionRegistry;
    token: string;
    /** Returns the installed Pi version and the models this Node can run. */
    capabilities: () => Promise<{ piVersion: string; models: string[] }>;
    heartbeatMs?: number;
}

/**
 * Serves the Pi server API. Every route requires `Authorization: Bearer <token>`.
 * Errors use `{ "error": { "code", "message" } }`.
 */
export function createPiServer(options: HttpOptions): Server {
    const heartbeatMs = options.heartbeatMs ?? 15_000;

    return createServer((request, response) => {
        handle(request, response).catch((error: unknown) => {
            if (response.headersSent) {
                response.destroy();
                return;
            }
            if (error instanceof PiServerError) {
                sendJson(response, error.status, {
                    error: { code: error.code, message: error.message },
                });
                return;
            }
            sendJson(response, 500, {
                error: {
                    code: "internal_error",
                    message: "The Pi server failed to handle the request.",
                },
            });
        });
    });

    async function handle(request: IncomingMessage, response: ServerResponse): Promise<void> {
        if (!authorized(request.headers.authorization, options.token)) {
            throw new PiServerError(401, "unauthorized", "A valid bearer token is required.");
        }
        const url = new URL(request.url ?? "/", "http://pi-server");
        const parts = url.pathname
            .split("/")
            .filter((part) => part !== "")
            .map(decodeURIComponent);
        const method = request.method ?? "GET";
        const { registry } = options;

        if (method === "GET" && parts.length === 1 && parts[0] === "capabilities") {
            sendJson(response, 200, await options.capabilities());
            return;
        }
        if (method === "POST" && parts.length === 1 && parts[0] === "sessions") {
            const body = await readJson(request);
            const result = await registry.create({
                id: string(body, "id"),
                cwd: string(body, "cwd"),
                model: string(body, "model"),
                thinkingLevel: string(body, "thinkingLevel"),
                appendSystemPrompt: optionalString(body, "appendSystemPrompt"),
            });
            sendJson(response, result.created ? 201 : 200, { id: result.id });
            return;
        }
        if (parts[0] !== "sessions" || parts.length < 2) {
            throw new PiServerError(404, "not_found", "No route matches this request.");
        }

        const id = parts[1] ?? "";
        const action = parts.slice(2).join("/");
        if (method === "GET" && action === "") {
            sendJson(response, 200, await registry.snapshot(id));
            return;
        }
        if (method === "POST" && action === "messages") {
            const body = await readJson(request);
            const result = await registry.send(id, string(body, "key"), string(body, "text"));
            sendJson(response, result.duplicate ? 200 : 202, {
                key: body.key,
                duplicate: result.duplicate,
            });
            return;
        }
        if (method === "POST" && action === "interrupt") {
            await registry.interrupt(id);
            sendJson(response, 202, { id });
            return;
        }
        if (method === "GET" && action === "stream") {
            await stream(id, request, response);
            return;
        }

        throw new PiServerError(404, "not_found", "No route matches this request.");
    }

    async function stream(
        id: string,
        request: IncomingMessage,
        response: ServerResponse,
    ): Promise<void> {
        const write = (event: StreamEvent | { kind: "heartbeat" }) =>
            response.write(`${JSON.stringify(event)}\n`);
        // Subscribe before sending headers so an unknown session still returns a JSON 404.
        const buffered: StreamEvent[] = [];
        let open = false;
        const unsubscribe = await options.registry.subscribe(id, (event) =>
            open ? write(event) : buffered.push(event),
        );

        response.writeHead(200, {
            "Content-Type": "application/x-ndjson",
            "Cache-Control": "no-store",
        });
        open = true;
        buffered.forEach(write);
        const heartbeat = setInterval(() => write({ kind: "heartbeat" }), heartbeatMs);
        request.on("close", () => {
            clearInterval(heartbeat);
            unsubscribe();
        });
    }
}

function authorized(header: string | undefined, token: string): boolean {
    const expected = Buffer.from(`Bearer ${token}`);
    const actual = Buffer.from(header ?? "");

    return actual.length === expected.length && timingSafeEqual(actual, expected);
}

async function readJson(request: IncomingMessage): Promise<Record<string, unknown>> {
    let size = 0;
    const chunks: Buffer[] = [];
    for await (const chunk of request) {
        size += (chunk as Buffer).length;
        if (size > MAX_BODY_BYTES) {
            throw new PiServerError(
                413,
                "request_too_large",
                "The request body is larger than 1 MiB.",
            );
        }
        chunks.push(chunk as Buffer);
    }
    try {
        const value: unknown = JSON.parse(Buffer.concat(chunks).toString("utf8"));
        if (typeof value === "object" && value !== null && !Array.isArray(value)) {
            return value as Record<string, unknown>;
        }
    } catch {
        // Fall through to the shared error.
    }

    throw new PiServerError(422, "invalid_request", "The request body must be a JSON object.");
}

function string(body: Record<string, unknown>, field: string): string {
    const value = body[field];
    if (typeof value !== "string") {
        throw new PiServerError(422, "invalid_request", `The ${field} field must be a string.`);
    }

    return value;
}

function optionalString(body: Record<string, unknown>, field: string): string | null {
    return body[field] === undefined || body[field] === null ? null : string(body, field);
}

function sendJson(response: ServerResponse, status: number, body: unknown): void {
    response.writeHead(status, { "Content-Type": "application/json" });
    response.end(JSON.stringify(body));
}
