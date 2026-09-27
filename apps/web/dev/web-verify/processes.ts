import { readFileSync, readdirSync } from "node:fs";
import { setTimeout as delay } from "node:timers/promises";

/** How long the daemon waits with no command before it exits. */
export const IDLE_MS = 5 * 60 * 1000;

const MAX_TIMEOUT_MS = 2_147_483_647;

/**
 * `ORBIT_WEB_VERIFY_IDLE_MS` overrides the idle wait for tests. An empty or invalid value keeps
 * the default. Values above the timer limit are capped so the timer does not fire immediately.
 */
export function idleTimeoutMs(env: NodeJS.ProcessEnv = process.env): number {
    const raw = env.ORBIT_WEB_VERIFY_IDLE_MS;
    if (raw === undefined || raw === "") return IDLE_MS;
    if (!/^\d+$/.test(raw)) return IDLE_MS;
    const value = Number(raw);
    if (!Number.isSafeInteger(value)) return IDLE_MS;

    return Math.min(value, MAX_TIMEOUT_MS);
}

/** A zombie counts as gone: the parent has not reaped it, and it cannot run. */
export function pidAlive(pid: number): boolean {
    const state = processState(pid);
    if (state !== null) return state !== "Z";

    try {
        process.kill(pid, 0);

        return true;
    } catch {
        return false;
    }
}

/**
 * Descendants of `root`, children before their parent. A process that has left the parent's
 * process group is still included while its parent chain leads back to `root`.
 */
export function descendantPids(root: number): number[] {
    const children = childrenByParent();
    const out: number[] = [];
    const walk = (pid: number): void => {
        for (const child of children.get(pid) ?? []) {
            walk(child);
            out.push(child);
        }
    };
    walk(root);

    return out;
}

/**
 * Stops `pid` and every descendant, not only the parent. The process group is signaled too, so a
 * child that detached into its own group still dies. Pass `includeRoot: false` to spare `pid`.
 */
export async function killProcessTree(
    pid: number,
    options: { includeRoot?: boolean } = {},
): Promise<void> {
    const includeRoot = options.includeRoot ?? true;
    signalTree(pid, "SIGTERM", includeRoot);
    if (await waitUntilGone(pid, includeRoot, 2_000)) return;
    signalTree(pid, "SIGKILL", includeRoot);
    await waitUntilGone(pid, includeRoot, 1_000);
}

function signalTree(root: number, signal: NodeJS.Signals, includeRoot: boolean): void {
    const pids = descendantPids(root);
    if (includeRoot) pids.push(root);
    for (const pid of pids) signalGroup(pid, signal);
    for (const pid of pids) signalPid(pid, signal);
}

async function waitUntilGone(
    root: number,
    includeRoot: boolean,
    timeoutMs: number,
): Promise<boolean> {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        const pids = descendantPids(root);
        if (includeRoot) pids.push(root);
        if (pids.every((pid) => !pidAlive(pid))) return true;
        if (Date.now() >= deadline) return false;
        await delay(50);
    }
}

function signalGroup(pid: number, signal: NodeJS.Signals): void {
    if (!Number.isInteger(pid) || pid <= 1) return;

    try {
        process.kill(-pid, signal);
    } catch {
        // Not a process group leader, or already gone.
    }
}

function signalPid(pid: number, signal: NodeJS.Signals): void {
    if (!Number.isInteger(pid) || pid <= 1) return;

    try {
        process.kill(pid, signal);
    } catch {
        // Already gone.
    }
}

function childrenByParent(): Map<number, number[]> {
    const children = new Map<number, number[]>();
    let entries: string[];
    try {
        entries = readdirSync("/proc");
    } catch {
        return children;
    }

    for (const entry of entries) {
        if (!/^[0-9]+$/.test(entry)) continue;
        const pid = Number(entry);
        const ppid = parentPid(pid);
        if (ppid === null) continue;
        const list = children.get(ppid);
        if (list === undefined) children.set(ppid, [pid]);
        else list.push(pid);
    }

    return children;
}

function parentPid(pid: number): number | null {
    const stat = readStat(pid);
    if (stat === null) return null;
    const end = stat.lastIndexOf(")");
    if (end === -1) return null;
    const ppid = stat.slice(end + 2).split(" ")[1];
    if (ppid === undefined || !/^\d+$/.test(ppid)) return null;

    return Number(ppid);
}

function processState(pid: number): string | null {
    const stat = readStat(pid);
    if (stat === null) return null;
    const end = stat.lastIndexOf(")");
    if (end === -1) return null;
    const state = stat.slice(end + 2).split(" ")[0];
    if (state === undefined || state === "") return null;

    return state;
}

function readStat(pid: number): string | null {
    try {
        return readFileSync(`/proc/${pid}/stat`, "utf8");
    } catch {
        return null;
    }
}
