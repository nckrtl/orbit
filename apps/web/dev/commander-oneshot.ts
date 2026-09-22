import { readFileSync } from "node:fs";
import type { IncomingMessage, ServerResponse } from "node:http";
import { Agent, fetch as undiciFetch } from "undici";
import type { Plugin } from "vite-plus";

type OneShotBody = {
    project_id?: string;
    title?: string;
    description?: unknown;
    kind?: string;
    creation_key?: string;
};

type OneShotOutcome =
    | { task: { id: number } }
    | { dry_run: true; warning: string }
    | { error: string };

function respond(res: ServerResponse, status: number, outcome: OneShotOutcome): void {
    res.statusCode = status;
    res.setHeader("Content-Type", "application/json");
    res.end(JSON.stringify(outcome));
}

function readBody(req: IncomingMessage): Promise<string> {
    return new Promise((resolve, reject) => {
        const chunks: Buffer[] = [];
        req.on("data", (chunk) => chunks.push(Buffer.from(chunk)));
        req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
        req.on("error", reject);
    });
}

function commanderDispatcher(baseUrl: string): Agent | undefined {
    const caPath = process.env.COMMANDER_CA_PATH;
    if (caPath) {
        return new Agent({ connect: { ca: readFileSync(caPath) } });
    }

    const host = new URL(baseUrl).hostname;
    const insecure =
        process.env.COMMANDER_TLS_INSECURE === "1" ||
        host.endsWith(".test") ||
        host === "localhost" ||
        host === "127.0.0.1";

    if (insecure) {
        return new Agent({ connect: { rejectUnauthorized: false } });
    }

    return undefined;
}

/**
 * Dev adapter: browser POSTs here; we call Commander MCP create-task with
 * kind=one-shot (public surface that mirrors SubmitOneShotTask).
 *
 * Env:
 *   COMMANDER_URL           default https://commander.test
 *   COMMANDER_MCP_TOKEN     Bearer token (required for a live submit)
 *   COMMANDER_CA_PATH       optional PEM for Node trust
 *   COMMANDER_TLS_INSECURE  force insecure TLS (*.test defaults insecure)
 *   VITE_COMMANDER_PROJECT  default project id when body omits project_id
 */
export function commanderOneShot(): Plugin {
    return {
        name: "orbit-commander-oneshot",
        configureServer(server) {
            server.middlewares.use(async (req, res, next) => {
                if (
                    req.url?.split("?")[0] !== "/__orbit/commander/one-shot" ||
                    req.method !== "POST"
                ) {
                    next();
                    return;
                }

                try {
                    await handleOneShot(req, res);
                } catch {
                    respond(res, 500, { error: "Commander one-shot request failed." });
                }
            });
        },
    };
}

async function handleOneShot(req: IncomingMessage, res: ServerResponse): Promise<void> {
    const raw = await readBody(req);
    const body = JSON.parse(raw || "{}") as OneShotBody;
    const projectId =
        (body.project_id || process.env.VITE_COMMANDER_PROJECT || "commander").trim() ||
        "commander";
    const title = String(body.title || "").trim();
    const creationKey = String(body.creation_key || "").trim();
    const kind = body.kind || "one-shot";

    if (!title || !creationKey || kind !== "one-shot") {
        respond(res, 422, { error: "title, creation_key, and kind=one-shot are required" });
        return;
    }

    const token = process.env.COMMANDER_MCP_TOKEN;
    const base = (process.env.COMMANDER_URL || "https://commander.test").replace(/\/$/, "");

    if (!token) {
        respond(res, 200, {
            dry_run: true,
            warning: "COMMANDER_MCP_TOKEN unset; one-shot not forwarded",
        });
        return;
    }

    const description =
        typeof body.description === "string"
            ? body.description
            : JSON.stringify(body.description ?? {});

    const dispatcher = commanderDispatcher(base);
    const mcpResponse = await undiciFetch(`${base}/mcp`, {
        method: "POST",
        dispatcher,
        headers: {
            Accept: "application/json, text/event-stream",
            "Content-Type": "application/json",
            Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
            jsonrpc: "2.0",
            id: 1,
            method: "tools/call",
            params: {
                name: "create-task",
                arguments: {
                    project_id: projectId,
                    title,
                    description,
                    acceptance_criteria: "",
                    kind: "one-shot",
                    creation_key: creationKey,
                },
            },
        }),
    });

    const text = await mcpResponse.text();
    if (!mcpResponse.ok) {
        respond(res, mcpResponse.status, {
            error: `Commander MCP returned HTTP ${mcpResponse.status}.`,
        });
        return;
    }

    let payload: unknown = null;
    try {
        payload = JSON.parse(text);
    } catch {
        const dataLine = text
            .split("\n")
            .map((line) => line.trim())
            .find((line) => line.startsWith("data:"));
        try {
            payload = dataLine ? JSON.parse(dataLine.slice(5).trim()) : null;
        } catch {
            // A malformed event has the same failed outcome as malformed JSON.
        }
    }

    const outcome = taskOutcome(payload);
    respond(res, "error" in outcome ? 502 : 200, outcome);
}

function record(value: unknown): Record<string, unknown> | null {
    return value !== null && typeof value === "object" && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function taskOutcome(payload: unknown): OneShotOutcome {
    const root = record(payload);
    if (root && "error" in root) {
        return { error: "Commander MCP returned a JSON-RPC error." };
    }
    const result = record(root?.result);
    if (result && result.isError !== undefined && result.isError !== false) {
        return { error: "Commander MCP tool call failed." };
    }

    let task = record(record(result?.structuredContent)?.task);
    if (!task && Array.isArray(result?.content)) {
        const text = record(result.content[0])?.text;
        if (typeof text === "string") {
            try {
                task = record(record(JSON.parse(text))?.task);
            } catch {
                // Invalid text content cannot prove that a task was created.
            }
        }
    }

    if (typeof task?.id === "number" && Number.isSafeInteger(task.id) && task.id > 0) {
        return { task: { id: task.id } };
    }

    return { error: "Commander MCP did not return a valid created task." };
}
