import { readFileSync } from "node:fs";
import { join } from "node:path";
import { getAgentDir } from "@earendil-works/pi-coding-agent";

export interface PiServerConfig {
    host: string;
    port: number;
    token: string;
    agentDir: string;
    sessionDir: string;
    workspaceRoots: string[];
    allowApiKeys: boolean;
    idleUnloadMs: number;
}

/**
 * Reads configuration from the environment. The service refuses to start without a bind address
 * and a token, so it is never reachable without authentication.
 */
export function readConfig(env: NodeJS.ProcessEnv = process.env): PiServerConfig {
    const host = required(env, "PI_SERVER_HOST");
    const token =
        env.PI_SERVER_TOKEN_FILE !== undefined
            ? readFileSync(env.PI_SERVER_TOKEN_FILE, "utf8").trim()
            : required(env, "PI_SERVER_TOKEN");
    if (token.length < 32) {
        throw new Error("The Pi server token must be at least 32 characters.");
    }
    const agentDir = env.PI_SERVER_AGENT_DIR ?? getAgentDir();

    return {
        host,
        port: integer(env.PI_SERVER_PORT, 3774),
        token,
        agentDir,
        sessionDir: env.PI_SERVER_SESSION_DIR ?? join(agentDir, "orbit-sessions"),
        workspaceRoots: (env.PI_SERVER_WORKSPACE_ROOTS ?? "")
            .split(":")
            .filter((root) => root !== ""),
        allowApiKeys: env.PI_SERVER_ALLOW_API_KEYS === "1",
        idleUnloadMs: integer(env.PI_SERVER_IDLE_UNLOAD_SECONDS, 900) * 1000,
    };
}

function required(env: NodeJS.ProcessEnv, name: string): string {
    const value = env[name];
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
