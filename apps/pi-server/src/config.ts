import { readFileSync } from "node:fs";
import { join } from "node:path";
import { parseArgs } from "node:util";
import { getAgentDir } from "@earendil-works/pi-coding-agent";

export interface PiServerConfig {
    host: string;
    port: number;
    token: string;
    agentDir: string;
    sessionDir: string;
    workspaceRoots: string[];
    allowApiKeys: boolean;
    allowedProviders: string[];
    idleUnloadMs: number;
}

/**
 * Reads configuration from command-line flags, then the environment. Orbit's systemd Processes
 * pass only argv, so every setting has a flag. The token has only a file flag: argv is visible
 * to other users and is stored in the Process definition, so the token itself never goes there.
 *
 * The service refuses to start without a bind address and a token, so it is never reachable
 * without authentication.
 */
export function readConfig(
    argv: string[] = process.argv.slice(2),
    env: NodeJS.ProcessEnv = process.env,
): PiServerConfig {
    const { values: flags } = parseArgs({
        args: argv,
        strict: true,
        options: {
            host: { type: "string" },
            port: { type: "string" },
            "token-file": { type: "string" },
            "agent-dir": { type: "string" },
            "session-dir": { type: "string" },
            "workspace-root": { type: "string", multiple: true },
            "allow-api-keys": { type: "boolean" },
            "allow-provider": { type: "string", multiple: true },
            "idle-unload-seconds": { type: "string" },
        },
    });

    const host = flags.host ?? required(env.PI_SERVER_HOST, "--host or PI_SERVER_HOST");
    const tokenFile = flags["token-file"] ?? env.PI_SERVER_TOKEN_FILE;
    const token =
        tokenFile !== undefined
            ? readFileSync(tokenFile, "utf8").trim()
            : required(
                  env.PI_SERVER_TOKEN,
                  "--token-file, PI_SERVER_TOKEN_FILE, or PI_SERVER_TOKEN",
              );
    if (token.length < 32) {
        throw new Error("The Pi server token must be at least 32 characters.");
    }
    const agentDir = flags["agent-dir"] ?? env.PI_SERVER_AGENT_DIR ?? getAgentDir();

    return {
        host,
        port: integer(flags.port ?? env.PI_SERVER_PORT, 3774),
        token,
        agentDir,
        sessionDir:
            flags["session-dir"] ?? env.PI_SERVER_SESSION_DIR ?? join(agentDir, "orbit-sessions"),
        workspaceRoots:
            flags["workspace-root"] ??
            (env.PI_SERVER_WORKSPACE_ROOTS ?? "").split(":").filter((root) => root !== ""),
        allowApiKeys: flags["allow-api-keys"] ?? env.PI_SERVER_ALLOW_API_KEYS === "1",
        allowedProviders:
            flags["allow-provider"] ??
            (env.PI_SERVER_ALLOW_PROVIDERS ?? "").split(",").filter((provider) => provider !== ""),
        idleUnloadMs:
            integer(flags["idle-unload-seconds"] ?? env.PI_SERVER_IDLE_UNLOAD_SECONDS, 900) * 1000,
    };
}

function required(value: string | undefined, name: string): string {
    if (value === undefined || value === "") {
        throw new Error(`${name} is required.`);
    }

    return value;
}

function integer(value: string | undefined, fallback: number): number {
    if (value === undefined || value === "") {
        return fallback;
    }
    const parsed = Number(value);
    if (!Number.isInteger(parsed) || parsed <= 0) {
        throw new Error(`Expected a positive integer, got ${value}.`);
    }

    return parsed;
}
