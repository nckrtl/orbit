import { spawn } from "node:child_process";
import { closeSync, mkdirSync, openSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { mkdir, rm, writeFile } from "node:fs/promises";
import { createConnection, createServer, type Socket } from "node:net";
import { join } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { fileURLToPath } from "node:url";
import { commandFailed, type Paths, type SessionCommand, type ToolResult } from "./contract";
import { descendantPids, idleTimeoutMs, killProcessTree, pidAlive } from "./processes";
import { Verifier } from "./session";

const COMMAND_MS = 120_000;

/** Runs a browser command in the checkout's long-lived daemon, starting one when the last died. */
export async function dispatch(paths: Paths, command: SessionCommand): Promise<ToolResult> {
    try {
        const socket = await connectOrStart(paths);
        socket.write(`${JSON.stringify(command)}\n`);
        const line = await readLine(socket, COMMAND_MS);
        socket.end();

        return JSON.parse(line) as ToolResult;
    } catch {
        return {
            ok: false,
            command: command.command,
            error: "server-failed",
            message: "The demo server did not answer.",
            next: "Read .orbit-artifacts/web/server.log and run the command again.",
        };
    }
}

export function daemonAlive(paths: Paths): boolean {
    const pid = readSessionPid(paths);

    return pid !== null && pidAlive(pid);
}

/** Stops this checkout's daemon. When none is running, the command still succeeds. */
export async function stopDaemon(paths: Paths): Promise<ToolResult> {
    const pid = readSessionPid(paths);
    if (pid === null || !pidAlive(pid)) {
        clearSession(paths);

        return { ok: true, command: "stop" };
    }

    const tracked = descendantPids(pid);
    try {
        process.kill(pid, "SIGTERM");
    } catch {
        // The process exited as we signaled it.
    }
    await waitDead(pid, 10_000);
    if (pidAlive(pid)) {
        try {
            process.kill(-pid, "SIGKILL");
        } catch {
            // Not a process group leader, or already gone.
        }
        try {
            process.kill(pid, "SIGKILL");
        } catch {
            // Already gone.
        }
        await waitDead(pid, 1_000);
    }
    await killTracked(tracked);

    if (pidAlive(pid) || tracked.some((child) => pidAlive(child))) {
        return commandFailed(
            "stop",
            new Error("The web-verify daemon or one of its processes did not exit."),
        );
    }
    clearSession(paths);

    return { ok: true, command: "stop" };
}

/**
 * Listens on the session socket until the process is idle or signaled. One command runs at a
 * time. The idle wait does not run during a command.
 */
export async function runDaemon(paths: Paths): Promise<void> {
    mkdirSync(paths.home, { recursive: true });
    rmSync(paths.socketPath, { force: true });
    const verifier = new Verifier(paths);
    const idleMs = idleTimeoutMs();
    let chain = Promise.resolve();
    let inFlight = 0;
    let stopping = false;
    let idleTimer: ReturnType<typeof setTimeout> | undefined;

    const clearIdle = (): void => {
        if (idleTimer === undefined) return;
        clearTimeout(idleTimer);
        idleTimer = undefined;
    };
    const scheduleIdle = (): void => {
        clearIdle();
        idleTimer = setTimeout(() => {
            if (stopping || inFlight > 0) return;
            shutdown("idle");
        }, idleMs);
    };
    const shutdown = (reason: string): void => {
        if (stopping) return;
        stopping = true;
        clearIdle();
        process.stderr.write(`web-verify daemon stopping (${reason}).\n`);
        void chain
            .catch(() => undefined)
            .then(() => verifier.close())
            .catch(() => undefined)
            .then(() => killProcessTree(process.pid, { includeRoot: false }))
            .catch(() => undefined)
            .finally(() => {
                server.close();
                clearSession(paths);
                process.exit(0);
            });
    };

    const server = createServer((socket) => {
        if (stopping) {
            socket.destroy();

            return;
        }
        inFlight += 1;
        clearIdle();
        chain = chain
            .then(async () => {
                const line = await readLine(socket, 30_000);
                let commandName = "";
                try {
                    const command = JSON.parse(line) as SessionCommand;
                    commandName = typeof command.command === "string" ? command.command : "";
                    if (commandName === "") {
                        throw new Error("The web-verify daemon received an invalid command.");
                    }
                    const result = await verifier.run(command);
                    socket.write(`${JSON.stringify(result)}\n`);
                } catch (error) {
                    socket.write(
                        `${JSON.stringify(commandFailed(commandName === "" ? "verify" : commandName, error))}\n`,
                    );
                    const detail =
                        error instanceof Error ? (error.stack ?? error.message) : String(error);
                    process.stderr.write(`${detail}\n`);
                }
            })
            .catch((error: unknown) => {
                const message = error instanceof Error ? error.message : String(error);
                socket.write(`${JSON.stringify(commandFailed("verify", error))}\n`);
                process.stderr.write(`${message}\n`);
            })
            .finally(() => {
                inFlight -= 1;
                socket.end();
                if (!stopping && inFlight === 0) scheduleIdle();
            });
    });

    await new Promise<void>((resolve, reject) => {
        server.once("error", reject);
        server.listen(paths.socketPath, () => resolve());
    });
    writeFileSync(paths.sessionFile, JSON.stringify({ pid: process.pid }));

    process.on("SIGTERM", () => shutdown("signal"));
    process.on("SIGINT", () => shutdown("signal"));
    if (inFlight === 0) scheduleIdle();

    await new Promise<void>(() => undefined);
}

function readSessionPid(paths: Paths): number | null {
    try {
        const parsed = JSON.parse(readFileSync(paths.sessionFile, "utf8")) as { pid?: unknown };

        return typeof parsed.pid === "number" && Number.isInteger(parsed.pid) && parsed.pid > 0
            ? parsed.pid
            : null;
    } catch {
        return null;
    }
}

function clearSession(paths: Paths): void {
    rmSync(paths.socketPath, { force: true });
    rmSync(paths.sessionFile, { force: true });
}

async function waitDead(pid: number, timeoutMs: number): Promise<void> {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline && pidAlive(pid)) await delay(50);
}

async function killTracked(pids: readonly number[]): Promise<void> {
    for (const pid of pids) {
        if (!pidAlive(pid)) continue;
        try {
            process.kill(pid, "SIGTERM");
        } catch {
            // Already gone.
        }
    }
    const deadline = Date.now() + 2_000;
    while (Date.now() < deadline && pids.some((pid) => pidAlive(pid))) await delay(50);
    for (const pid of pids) {
        if (!pidAlive(pid)) continue;
        try {
            process.kill(-pid, "SIGKILL");
        } catch {
            // Not a process group leader.
        }
        try {
            process.kill(pid, "SIGKILL");
        } catch {
            // Already gone.
        }
    }
    const killDeadline = Date.now() + 1_000;
    while (Date.now() < killDeadline && pids.some((pid) => pidAlive(pid))) await delay(50);
}

async function connectOrStart(paths: Paths): Promise<Socket> {
    await mkdir(paths.home, { recursive: true });
    const open = await tryConnect(paths.socketPath);
    if (open !== null) return open;

    const release = await acquireLock(paths.lockDir);
    try {
        const again = await tryConnect(paths.socketPath);
        if (again !== null) return again;
        if (daemonAlive(paths)) {
            const waited = await waitForSocket(paths.socketPath, 15_000);
            if (waited !== null) return waited;
        }
        await startDaemon(paths);
        const started = await waitForSocket(paths.socketPath, 15_000);
        if (started === null) throw new Error("The web-verify daemon did not listen.");

        return started;
    } finally {
        await release();
    }
}

async function startDaemon(paths: Paths): Promise<void> {
    await mkdir(paths.home, { recursive: true });
    await rm(paths.socketPath, { force: true });
    const log = openSync(paths.daemonLog, "a");
    const child = spawn(
        process.execPath,
        [fileURLToPath(new URL("../web-verify.ts", import.meta.url))],
        {
            cwd: paths.webRoot,
            env: {
                ...process.env,
                ORBIT_WEB_VERIFY_DAEMON: "1",
                ORBIT_WEB_VERIFY_HOME: paths.home,
            },
            detached: true,
            stdio: ["ignore", log, log],
        },
    );
    closeSync(log);
    child.unref();
    if (child.pid === undefined) throw new Error("The web-verify daemon did not start.");
}

function tryConnect(socketPath: string): Promise<Socket | null> {
    return new Promise((resolve) => {
        const socket = createConnection(socketPath);
        const done = (value: Socket | null) => {
            socket.off("connect", onConnect);
            socket.off("error", onError);
            resolve(value);
        };
        const onConnect = () => done(socket);
        const onError = () => {
            socket.destroy();
            done(null);
        };
        socket.once("connect", onConnect);
        socket.once("error", onError);
    });
}

async function waitForSocket(socketPath: string, timeoutMs: number): Promise<Socket | null> {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const socket = await tryConnect(socketPath);
        if (socket !== null) return socket;
        await delay(50);
    }

    return null;
}

function readLine(socket: Socket, timeoutMs: number): Promise<string> {
    return new Promise((resolve, reject) => {
        let data = "";
        const timer = setTimeout(() => {
            cleanup();
            socket.destroy();
            reject(new Error("The web-verify daemon did not answer."));
        }, timeoutMs);
        const onData = (chunk: Buffer) => {
            data += chunk.toString("utf8");
            const end = data.indexOf("\n");
            if (end === -1) return;
            cleanup();
            resolve(data.slice(0, end));
        };
        const onError = (error: Error) => {
            cleanup();
            reject(error);
        };
        const cleanup = () => {
            clearTimeout(timer);
            socket.off("data", onData);
            socket.off("error", onError);
        };
        socket.on("data", onData);
        socket.on("error", onError);
    });
}

async function acquireLock(dir: string): Promise<() => Promise<void>> {
    const deadline = Date.now() + 60_000;
    for (;;) {
        try {
            await mkdir(dir);
            await writeFile(join(dir, "pid"), String(process.pid));

            return async () => {
                await rm(dir, { recursive: true, force: true });
            };
        } catch (error) {
            if (!isEexist(error)) throw error;
            if (await lockHolderDead(dir)) {
                await rm(dir, { recursive: true, force: true });
                continue;
            }
            if (Date.now() > deadline)
                throw new Error("Timed out waiting for the web-verify lock.");
            await delay(50);
        }
    }
}

async function lockHolderDead(dir: string): Promise<boolean> {
    try {
        const pid = Number(readFileSync(join(dir, "pid"), "utf8"));

        return !Number.isInteger(pid) || !pidAlive(pid);
    } catch {
        return true;
    }
}

function isEexist(error: unknown): boolean {
    return (
        error !== null && typeof error === "object" && "code" in error && error.code === "EEXIST"
    );
}
