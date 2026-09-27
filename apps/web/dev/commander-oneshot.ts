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

                // Demo mode must not call Commander, even when a token is present in the environment.
                if (process.env.VITE_ORBIT_DEMO) {
                    res.statusCode = 200;
                    res.setHeader("Content-Type", "application/json");
                    res.end(
                        JSON.stringify({
                            ok: false,
                            error: "Demo mode does not call Commander.",
                        }),
                    );
                    return;
                }

                try {
                    await handleOneShot(req, res);
                } catch (error) {
                    res.statusCode = 500;
                    res.setHeader("Content-Type", "application/json");
                    res.end(
                        JSON.stringify({
                            error: error instanceof Error ? error.message : "oneshot failed",
                        }),
                    );
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
        res.statusCode = 422;
        res.setHeader("Content-Type", "application/json");
        res.end(JSON.stringify({ error: "title, creation_key, and kind=one-shot are required" }));
        return;
    }

    const token = process.env.COMMANDER_MCP_TOKEN;
    const base = (process.env.COMMANDER_URL || "https://commander.test").replace(/\/$/, "");

    if (!token) {
        res.statusCode = 200;
        res.setHeader("Content-Type", "application/json");
        res.end(
            JSON.stringify({
                task: {
                    id: null,
                    project_id: projectId,
                    kind: "one-shot",
                    title,
                    creation_key: creationKey,
                },
                dry_run: true,
                warning: "COMMANDER_MCP_TOKEN unset; one-shot not forwarded",
            }),
        );
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
        res.statusCode = mcpResponse.status;
        res.setHeader("Content-Type", "application/json");
        res.end(JSON.stringify({ error: text || `Commander MCP HTTP ${mcpResponse.status}` }));
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
        if (dataLine) {
            payload = JSON.parse(dataLine.slice(5).trim());
        }
    }

    const result = extractTask(payload);
    res.statusCode = 200;
    res.setHeader("Content-Type", "application/json");
    res.end(JSON.stringify({ task: result, raw: payload }));
}

function extractTask(payload: unknown): { id?: number; title?: string } | null {
    if (!payload || typeof payload !== "object") {
        return null;
    }
    const root = payload as Record<string, unknown>;
    const result = root.result;
    if (result && typeof result === "object") {
        const structured = (result as { structuredContent?: { task?: unknown } }).structuredContent;
        if (structured?.task && typeof structured.task === "object") {
            return structured.task as { id?: number; title?: string };
        }
        const content = (result as { content?: Array<{ text?: string }> }).content;
        const text = content?.[0]?.text;
        if (text) {
            try {
                const parsed = JSON.parse(text) as { task?: { id?: number; title?: string } };
                return parsed.task ?? null;
            } catch {
                return null;
            }
        }
    }
    return null;
}
