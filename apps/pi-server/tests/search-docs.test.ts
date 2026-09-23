import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, describe, expect, it } from "vite-plus/test";
import {
    callBoostSearchDocs,
    createSearchDocsTool,
    resolveLaravelApp,
} from "../src/search-docs.ts";

type Mode = "answer" | "tool-error" | "hang" | "crash" | "disabled";

/**
 * A fake `artisan` that speaks MCP over stdio like `php artisan boost:mcp`. The tests run it
 * with the current JavaScript runtime in place of `php`. It records what it received.
 */
function fakeArtisan(mode: Mode): string {
    return `
const fs = require("node:fs");
const log = (entry) => fs.appendFileSync("received.jsonl", JSON.stringify(entry) + "\\n");
log({ pid: process.pid, argv: process.argv.slice(2), appDebug: process.env.APP_DEBUG, token: process.env.PI_SERVER_TOKEN ?? null });
const mode = ${JSON.stringify(mode)};
if (mode === "crash") { process.stderr.write("PHP Fatal error: boom\\n"); process.exit(1); }
if (mode === "disabled") { process.stdout.write('ERROR  There are no commands defined in the "boost" namespace.\\n'); process.exit(1); }
if (mode === "hang") { process.on("SIGTERM", () => {}); setInterval(() => {}, 1000); }
const reply = (message) => process.stdout.write(JSON.stringify({ jsonrpc: "2.0", ...message }) + "\\n");
let buffer = "";
process.stdin.on("data", (chunk) => {
    buffer += chunk;
    let newline;
    while ((newline = buffer.indexOf("\\n")) >= 0) {
        const message = JSON.parse(buffer.slice(0, newline));
        buffer = buffer.slice(newline + 1);
        log(message);
        if (mode === "hang") continue;
        if (message.method === "initialize") {
            process.stdout.write("PHP Deprecated: noise before the answer\\n");
            reply({ id: message.id, result: { protocolVersion: "2025-06-18", capabilities: { tools: {} } } });
        } else if (message.method === "tools/call") {
            const isError = mode === "tool-error";
            const text = isError ? "HTTP request failed: offline" : "# Search Results\\n" + message.params.arguments.queries.join(", ");
            reply({ id: message.id, result: { content: [{ type: "text", text }], isError } });
        }
    }
});
`;
}

let root: string;

afterEach(() => {
    if (root !== undefined) {
        rmSync(root, { recursive: true, force: true });
    }
});

function laravelApp(dir: string, options: { boost?: boolean; mode?: Mode } = {}): string {
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, "artisan"), fakeArtisan(options.mode ?? "answer"));
    if (options.boost ?? true) {
        mkdirSync(join(dir, "vendor", "laravel", "boost"), { recursive: true });
    }

    return dir;
}

function workspace(): string {
    root = mkdtempSync(join(tmpdir(), "pi-search-docs-"));

    return root;
}

function isRunning(pid: number): boolean {
    try {
        process.kill(pid, 0);
        return true;
    } catch {
        return false;
    }
}

function received(app: string): any[] {
    const file = join(app, "received.jsonl");

    return existsSync(file)
        ? readFileSync(file, "utf8")
              .trim()
              .split("\n")
              .map((line) => JSON.parse(line))
        : [];
}

describe("resolveLaravelApp", () => {
    it("uses the working directory when it is a Laravel app", () => {
        const cwd = laravelApp(workspace());

        expect(resolveLaravelApp(cwd)).toBe(cwd);
    });

    it("uses the only apps/* directory with Laravel Boost", () => {
        const cwd = workspace();
        const gateway = laravelApp(join(cwd, "apps", "gateway"));
        laravelApp(join(cwd, "apps", "docs"), { boost: false });
        mkdirSync(join(cwd, "apps", "web"));

        expect(resolveLaravelApp(cwd)).toBe(gateway);
    });

    it("uses project when it is given", () => {
        const cwd = workspace();
        laravelApp(join(cwd, "apps", "gateway"));
        const e2e = laravelApp(join(cwd, "apps", "e2e"));

        expect(resolveLaravelApp(cwd, "apps/e2e")).toBe(e2e);
    });

    it("asks for project when several apps have Laravel Boost", () => {
        const cwd = workspace();
        laravelApp(join(cwd, "apps", "gateway"));
        laravelApp(join(cwd, "apps", "e2e"));

        expect(() => resolveLaravelApp(cwd)).toThrow(
            "Several Laravel apps have Laravel Boost: apps/e2e, apps/gateway. Pass project.",
        );
    });

    it("fails when no Laravel app is found", () => {
        expect(() => resolveLaravelApp(workspace())).toThrow("No Laravel app with Laravel Boost");
    });

    it("fails when the Laravel app does not have Laravel Boost installed", () => {
        const cwd = laravelApp(workspace(), { boost: false });

        expect(() => resolveLaravelApp(cwd)).toThrow(
            "The working directory has no vendor/laravel/boost.",
        );
    });

    it("fails when project is not a Laravel app", () => {
        const cwd = workspace();
        mkdirSync(join(cwd, "apps", "web"), { recursive: true });

        expect(() => resolveLaravelApp(cwd, "apps/web")).toThrow(
            "apps/web is not a Laravel app: it has no artisan file.",
        );
    });
});

describe("callBoostSearchDocs", () => {
    const call = (app: string, extra: { timeoutMs?: number; signal?: AbortSignal } = {}) =>
        callBoostSearchDocs({
            appRoot: app,
            php: process.execPath,
            queries: ["queue middleware", "rate limit"],
            packages: ["laravel/framework"],
            ...extra,
        });

    it("initializes MCP, calls search-docs, and returns the text", async () => {
        const app = laravelApp(workspace());
        process.env.PI_SERVER_TOKEN = "secret-token-that-must-not-reach-php";
        try {
            expect(await call(app)).toBe("# Search Results\nqueue middleware, rate limit");
        } finally {
            delete process.env.PI_SERVER_TOKEN;
        }

        const [start, ...messages] = received(app);
        expect(start).toMatchObject({ argv: ["boost:mcp"], appDebug: "true", token: null });
        expect(messages.map((m) => m.method)).toEqual([
            "initialize",
            "notifications/initialized",
            "tools/call",
        ]);
        expect(messages[2].params).toEqual({
            name: "search-docs",
            arguments: {
                queries: ["queue middleware", "rate limit"],
                packages: ["laravel/framework"],
            },
        });
    });

    it("reports a tool error from Boost", async () => {
        const app = laravelApp(workspace(), { mode: "tool-error" });

        await expect(call(app)).rejects.toThrow(
            "Laravel Boost search-docs failed: HTTP request failed: offline",
        );
    });

    it("stops Boost and fails after the timeout", async () => {
        const app = laravelApp(workspace(), { mode: "hang" });
        const started = Date.now();

        await expect(call(app, { timeoutMs: 500 })).rejects.toThrow(
            "Laravel Boost did not answer within 0.5 seconds.",
        );
        expect(Date.now() - started).toBeLessThan(5_000);
        const pid = received(app)[0].pid;
        await new Promise((resolve) => setTimeout(resolve, 100));
        expect(isRunning(pid)).toBe(false);
    });

    it("stops Boost when the turn is aborted", async () => {
        const app = laravelApp(workspace(), { mode: "hang" });
        const controller = new AbortController();
        setTimeout(() => controller.abort(), 200);

        await expect(call(app, { signal: controller.signal })).rejects.toThrow(
            "The documentation search was cancelled.",
        );
    });

    it("reports the output when Boost exits before it answers", async () => {
        const app = laravelApp(workspace(), { mode: "crash" });

        await expect(call(app)).rejects.toThrow(
            "php artisan boost:mcp exited with code 1 before it answered.\nPHP Fatal error: boom",
        );
    });

    it("explains when Boost is installed but not enabled", async () => {
        const app = laravelApp(workspace(), { mode: "disabled" });

        await expect(call(app)).rejects.toThrow("Laravel Boost is installed but not enabled.");
    });

    it("reports a missing PHP binary", async () => {
        const app = laravelApp(workspace());

        await expect(
            callBoostSearchDocs({ appRoot: app, php: join(app, "no-php"), queries: ["x"] }),
        ).rejects.toThrow("Could not run");
    });
});

describe("search_docs tool", () => {
    it("finds the app from the session directory and returns the docs as text", async () => {
        const cwd = workspace();
        laravelApp(join(cwd, "apps", "gateway"));
        const tool = createSearchDocsTool({ cwd, php: process.execPath });

        const result = await tool.execute(
            "call-1",
            { queries: ["validation"] },
            undefined,
            undefined,
            {} as never,
        );

        expect(tool.name).toBe("search_docs");
        expect(result.content).toEqual([{ type: "text", text: "# Search Results\nvalidation" }]);
    });
});
