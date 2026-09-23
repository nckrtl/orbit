import { spawn } from "node:child_process";
import { existsSync, readdirSync } from "node:fs";
import { join, relative, resolve } from "node:path";
import { Type } from "@earendil-works/pi-ai";
import { defineTool } from "@earendil-works/pi-coding-agent";

const DEFAULT_TIMEOUT_MS = 60_000;
const OUTPUT_TAIL = 2_000;
const MCP_PROTOCOL_VERSION = "2025-06-18";

export interface SearchDocsOptions {
    /** The session's working directory. */
    cwd: string;
    /** The PHP binary. Default: `php` from PATH. */
    php?: string;
    /** Stop Boost after this many milliseconds. Default: 60 seconds. */
    timeoutMs?: number;
}

export interface BoostCall {
    appRoot: string;
    queries: string[];
    packages?: string[];
    php?: string;
    timeoutMs?: number;
    signal?: AbortSignal;
}

const parameters = Type.Object({
    queries: Type.Array(Type.String(), {
        minItems: 1,
        description:
            'Search queries. Pass several when unsure of the term, such as "toggle" and "switch".',
    }),
    packages: Type.Optional(
        Type.Array(Type.String(), {
            description:
                "Limit the search to these packages, such as laravel/framework or pestphp/pest.",
        }),
    ),
    project: Type.Optional(
        Type.String({
            description:
                "Laravel app directory relative to the working directory, such as apps/gateway. Default: the working directory, or the only apps/* directory with Laravel Boost.",
        }),
    ),
});

/**
 * The `search_docs` tool. It asks Laravel Boost in the Project's Laravel app for documentation
 * that matches the package versions in that app's lock files.
 */
export function createSearchDocsTool(options: SearchDocsOptions) {
    return defineTool({
        name: "search_docs",
        label: "Search docs",
        description:
            "Search version-specific Laravel ecosystem documentation for the packages this Project uses, such as Laravel, Pest, Livewire, Inertia, and Filament. Results match the installed package versions. Use it before you write framework code you are not sure about.",
        promptSnippet: "Search Laravel ecosystem docs for the Project's installed package versions",
        promptGuidelines: [
            "Use search_docs before you write Laravel ecosystem code you are not sure about, so the code matches the installed package versions.",
        ],
        parameters,
        async execute(_toolCallId, params, signal) {
            const appRoot = resolveLaravelApp(options.cwd, params.project);
            const text = await callBoostSearchDocs({
                appRoot,
                queries: params.queries,
                ...(params.packages === undefined ? {} : { packages: params.packages }),
                ...(options.php === undefined ? {} : { php: options.php }),
                ...(options.timeoutMs === undefined ? {} : { timeoutMs: options.timeoutMs }),
                ...(signal === undefined ? {} : { signal }),
            });

            return { content: [{ type: "text", text }], details: undefined };
        },
    });
}

/**
 * Finds the Laravel app to ask. `project` wins when given. Otherwise the working directory
 * counts when it has `artisan`, else the only `apps/*` directory with Laravel Boost installed.
 */
export function resolveLaravelApp(cwd: string, project?: string): string {
    if (project !== undefined && project !== "") {
        const root = resolve(cwd, project);
        if (!existsSync(join(root, "artisan"))) {
            throw new Error(`${project} is not a Laravel app: it has no artisan file.`);
        }
        requireBoost(root, project);

        return root;
    }
    if (existsSync(join(cwd, "artisan"))) {
        requireBoost(cwd, "The working directory");

        return cwd;
    }

    const apps = join(cwd, "apps");
    const candidates = existsSync(apps)
        ? readdirSync(apps, { withFileTypes: true })
              .filter((entry) => entry.isDirectory())
              .map((entry) => join(apps, entry.name))
              .filter((dir) => existsSync(join(dir, "artisan")) && hasBoost(dir))
              .sort()
        : [];
    if (candidates.length === 1) {
        return candidates[0]!;
    }
    if (candidates.length > 1) {
        const names = candidates.map((dir) => relative(cwd, dir)).join(", ");
        throw new Error(`Several Laravel apps have Laravel Boost: ${names}. Pass project.`);
    }

    throw new Error(
        "No Laravel app with Laravel Boost found. The working directory has no artisan file, and no apps/* directory has artisan and vendor/laravel/boost. Pass project, or run composer install in the app.",
    );
}

function hasBoost(dir: string): boolean {
    return existsSync(join(dir, "vendor", "laravel", "boost"));
}

function requireBoost(dir: string, name: string): void {
    if (!hasBoost(dir)) {
        throw new Error(
            `${name} has no vendor/laravel/boost. Run composer install in it, or require laravel/boost as a dev dependency.`,
        );
    }
}

/**
 * Runs `php artisan boost:mcp` in the app, calls Boost's `search-docs` tool over MCP stdio, and
 * returns the text result. The process ends after the answer, on timeout, or on abort.
 */
export function callBoostSearchDocs(call: BoostCall): Promise<string> {
    const timeoutMs = call.timeoutMs ?? DEFAULT_TIMEOUT_MS;

    return new Promise((resolvePromise, rejectPromise) => {
        const child = spawn(call.php ?? "php", ["artisan", "boost:mcp"], {
            cwd: call.appRoot,
            env: childEnv(),
            stdio: ["pipe", "pipe", "pipe"],
        });
        let settled = false;
        let stdout = "";
        let output = "";

        const finish = (error: Error | undefined, text?: string) => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timer);
            call.signal?.removeEventListener("abort", onAbort);
            child.stdin.end();
            if (child.exitCode === null && child.signalCode === null) {
                child.kill("SIGKILL");
            }
            if (error === undefined) {
                resolvePromise(text ?? "");
            } else {
                rejectPromise(error);
            }
        };
        const onAbort = () => finish(new Error("The documentation search was cancelled."));
        const timer = setTimeout(
            () =>
                finish(
                    new Error(`Laravel Boost did not answer within ${timeoutMs / 1000} seconds.`),
                ),
            timeoutMs,
        );
        if (call.signal?.aborted) {
            onAbort();
            return;
        }
        call.signal?.addEventListener("abort", onAbort, { once: true });

        const send = (message: object) => {
            child.stdin.write(`${JSON.stringify({ jsonrpc: "2.0", ...message })}\n`);
        };
        const handle = (message: any) => {
            if (message.id === 1) {
                if (message.error !== undefined) {
                    finish(new Error(`Laravel Boost refused to start: ${message.error.message}`));
                    return;
                }
                send({ method: "notifications/initialized" });
                send({
                    id: 2,
                    method: "tools/call",
                    params: {
                        name: "search-docs",
                        arguments: {
                            queries: call.queries,
                            ...(call.packages === undefined ? {} : { packages: call.packages }),
                        },
                    },
                });
            } else if (message.id === 2) {
                if (message.error !== undefined) {
                    finish(new Error(`Laravel Boost search-docs failed: ${message.error.message}`));
                    return;
                }
                const text = (message.result?.content ?? [])
                    .filter((part: any) => part?.type === "text")
                    .map((part: any) => String(part.text))
                    .join("\n");
                if (message.result?.isError === true) {
                    finish(new Error(`Laravel Boost search-docs failed: ${text}`));
                    return;
                }
                finish(undefined, text);
            }
        };

        child.stdout.setEncoding("utf8");
        child.stdout.on("data", (chunk: string) => {
            stdout += chunk;
            let newline = stdout.indexOf("\n");
            while (newline >= 0) {
                const line = stdout.slice(0, newline).trim();
                stdout = stdout.slice(newline + 1);
                newline = stdout.indexOf("\n");
                let message: unknown;
                try {
                    message = JSON.parse(line);
                } catch {
                    output = (output + line + "\n").slice(-OUTPUT_TAIL);
                    continue;
                }
                handle(message);
            }
        });
        child.stderr.setEncoding("utf8");
        child.stderr.on("data", (chunk: string) => {
            output = (output + chunk).slice(-OUTPUT_TAIL);
        });
        child.stdin.on("error", () => undefined);
        child.on("error", (error) =>
            finish(new Error(`Could not run ${call.php ?? "php"}: ${error.message}`)),
        );
        child.on("close", (code) => {
            const detail = (output + stdout).trim();
            if (detail.includes('no commands defined in the "boost" namespace')) {
                finish(
                    new Error(
                        "Laravel Boost is installed but not enabled. Boost runs only when APP_ENV is local or APP_DEBUG is true, and BOOST_ENABLED is not false.",
                    ),
                );
                return;
            }
            finish(
                new Error(
                    `php artisan boost:mcp exited with code ${code} before it answered.${detail === "" ? "" : `\n${detail}`}`,
                ),
            );
        });

        send({
            id: 1,
            method: "initialize",
            params: {
                protocolVersion: MCP_PROTOCOL_VERSION,
                capabilities: {},
                clientInfo: { name: "orbit-pi-server", version: "1" },
            },
        });
    });
}

/**
 * The environment for Boost. Boost runs only in a local or debug app, so a worktree without an
 * `.env` still works. The Pi server's own settings, such as its token, stay out.
 */
function childEnv(): NodeJS.ProcessEnv {
    const env = Object.fromEntries(
        Object.entries(process.env).filter(([key]) => !key.startsWith("PI_SERVER_")),
    );

    return { ...env, APP_DEBUG: env.APP_DEBUG ?? "true" };
}
