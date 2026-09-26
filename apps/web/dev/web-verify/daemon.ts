import { spawn } from "node:child_process";
import { closeSync, mkdirSync, openSync, readFileSync, rmSync } from "node:fs";
import { mkdir, rm, writeFile } from "node:fs/promises";
import { createConnection, createServer, type Socket } from "node:net";
import { join } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { fileURLToPath } from "node:url";
import { commandFailed, type Paths, type SessionCommand, type ToolResult } from "./contract";
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
    try {
        const parsed = JSON.parse(readFileSync(paths.sessionFile, "utf8")) as { pid?: unknown };

        return typeof parsed.pid === "number" && pidAlive(parsed.pid);
    } catch {
        return false;
    }
}

/** Listens on the session socket until the process is signaled. One command runs at a time. */
export async function runDaemon(paths: Paths): Promise<void> {
    mkdirSync(paths.home, { recursive: true });
    rmSync(paths.socketPath, { force: true });
    const verifier = new Verifier(paths);
    let chain = Promise.resolve();
    let stopping = false;
    const server = createServer((socket) => {
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
                socket.end();
            });
    });

    await new Promise<void>((resolve, reject) => {
        server.once("error", reject);
        server.listen(paths.socketPath, () => resolve());
    });
    await writeFile(paths.sessionFile, JSON.stringify({ pid: process.pid }));

    const shutdown = (): void => {
        if (stopping) return;
        stopping = true;
        void verifier
            .close()
            .catch(() => undefined)
            .finally(() => {
                server.close();
                rmSync(paths.socketPath, { force: true });
                process.exit(0);
            });
    };
    process.on("SIGTERM", shutdown);
    process.on("SIGINT", shutdown);

    await new Promise<void>(() => undefined);
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

function pidAlive(pid: number): boolean {
    try {
        process.kill(pid, 0);

        return true;
    } catch {
        return false;
    }
}

function isEexist(error: unknown): boolean {
    return (
        error !== null && typeof error === "object" && "code" in error && error.code === "EEXIST"
    );
}
