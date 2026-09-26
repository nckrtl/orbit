import { spawn, type ChildProcess } from "node:child_process";
import { closeSync, mkdirSync, openSync } from "node:fs";
import { createServer } from "node:net";
import { join } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import type { Paths } from "./contract";

const READY_MS = 60_000;

/** The demo dev server. A dead process is replaced; a live one is kept for the checkout. */
export class DemoServer {
    private child: ChildProcess | null = null;
    private port: number | null = null;
    private readonly paths: Paths;

    constructor(paths: Paths) {
        this.paths = paths;
    }

    /** Answers with the origin, starting or replacing the process when it is not answering. */
    async ensure(): Promise<string> {
        const current = await this.originIfHealthy();
        if (current !== null) return current;

        let last: unknown;
        for (let attempt = 0; attempt < 2; attempt++) {
            try {
                return await this.start();
            } catch (error) {
                last = error;
            }
        }

        throw last instanceof Error ? last : new Error("The demo server did not answer.");
    }

    async stop(): Promise<void> {
        const child = this.child;
        this.child = null;
        this.port = null;
        if (child?.pid === undefined) return;

        await signalGroup(child, "SIGTERM");
        const deadline = Date.now() + 2_000;
        while (Date.now() < deadline && child.exitCode === null && child.signalCode === null) {
            await delay(50);
        }
        if (child.exitCode === null && child.signalCode === null) {
            await signalGroup(child, "SIGKILL");
        }
    }

    private async originIfHealthy(): Promise<string | null> {
        if (this.port === null || !this.running()) return null;
        if (!(await ping(this.port))) return null;

        return origin(this.port);
    }

    private running(): boolean {
        return (
            this.child !== null && this.child.exitCode === null && this.child.signalCode === null
        );
    }

    private async start(): Promise<string> {
        await this.stop();
        mkdirSync(this.paths.home, { recursive: true });
        const port = await freePort();
        const log = openSync(this.paths.serverLog, "a");
        const child = spawn(
            join(this.paths.webRoot, "node_modules", ".bin", "vp"),
            [
                "dev",
                "--host",
                "127.0.0.1",
                "--port",
                String(port),
                "--strictPort",
                "--clearScreen",
                "false",
            ],
            {
                cwd: this.paths.webRoot,
                env: { ...process.env, VITE_ORBIT_DEMO: "1" },
                detached: true,
                stdio: ["ignore", log, log],
            },
        );
        closeSync(log);
        child.unref();
        this.child = child;
        this.port = port;

        const deadline = Date.now() + READY_MS;
        while (Date.now() < deadline) {
            if (child.exitCode !== null || child.signalCode !== null) break;
            if (await ping(port)) return origin(port);
            await delay(200);
        }

        await this.stop();
        throw new Error("The demo server did not answer.");
    }
}

function origin(port: number): string {
    return `http://127.0.0.1:${port}`;
}

async function signalGroup(child: ChildProcess, signal: NodeJS.Signals): Promise<void> {
    if (child.pid === undefined) return;

    try {
        process.kill(-child.pid, signal);
    } catch {
        try {
            child.kill(signal);
        } catch {
            // The process is already gone.
        }
    }
}

function freePort(): Promise<number> {
    return new Promise((resolve, reject) => {
        const server = createServer();
        server.once("error", reject);
        server.listen(0, "127.0.0.1", () => {
            const address = server.address();
            if (address === null || typeof address === "string") {
                server.close();
                reject(new Error("Could not bind a local port."));
                return;
            }
            const { port } = address;
            server.close((error) => (error === undefined ? resolve(port) : reject(error)));
        });
    });
}

async function ping(port: number): Promise<boolean> {
    try {
        const response = await fetch(origin(port), { signal: AbortSignal.timeout(1_000) });

        return response.ok;
    } catch {
        return false;
    }
}
