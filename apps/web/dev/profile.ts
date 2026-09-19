import { execFile } from "node:child_process";
import { existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import type { Plugin } from "vite-plus";

/** The repository's own CLI, so the page and `orbit profile` can never disagree. */
const REPO_CLI = fileURLToPath(new URL("../../cli/orbit", import.meta.url));

/**
 * Dev adapter for `orbit profile`. The command sends its GET from the operator's machine and never
 * through the Gateway, and a browser cannot make that request: it may not read another origin's
 * timings or its `X-Toolbar-Summary` header. So the page asks this server, which runs the command on
 * this machine and returns what it printed.
 *
 *   GET /__orbit/profile?instance=ID  ->  { ok, output }
 */
export function orbitProfile(): Plugin {
    return {
        name: "orbit-profile",
        configureServer(server) {
            server.middlewares.use((req, res, next) => {
                const url = new URL(req.url ?? "/", "http://localhost");

                if (url.pathname !== "/__orbit/profile" || req.method !== "GET") {
                    next();

                    return;
                }

                const instance = url.searchParams.get("instance") ?? "";
                const send = (status: number, body: { ok: boolean; output: string }) => {
                    res.statusCode = status;
                    res.setHeader("Content-Type", "application/json");
                    res.end(JSON.stringify(body));
                };

                // The only caller input is the numeric ID, and it is passed as one argument, never through a shell.
                if (!/^[1-9][0-9]*$/.test(instance)) {
                    send(422, { ok: false, output: "The instance must be a numeric ID." });

                    return;
                }

                const [command, prefix] = existsSync(REPO_CLI)
                    ? ["php", [REPO_CLI]]
                    : ["orbit", []];

                execFile(
                    command,
                    [
                        ...prefix,
                        "profile",
                        `--instance=${instance}`,
                        "--no-ansi",
                        "--no-interaction",
                    ],
                    { timeout: 120_000, env: { ...process.env, NO_COLOR: "1" } },
                    (error, stdout, stderr) => {
                        const output = `${stdout}${stderr}`
                            .split("\n")
                            .filter((line) => !line.startsWith("Fetching App instance"))
                            .join("\n")
                            .trim();

                        send(200, {
                            ok: error === null,
                            output: output === "" ? (error?.message ?? "No output.") : output,
                        });
                    },
                );
            });
        },
    };
}
