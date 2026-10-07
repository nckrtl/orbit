import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync } from "node:fs";
import { createServer } from "node:http";
import type { AddressInfo } from "node:net";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { fileURLToPath } from "node:url";
import { chromium, webkit, type Page } from "playwright";
import { afterAll, expect, it } from "vite-plus/test";
import { applyInsets, INSETS, type ToolResult } from "./contract";
import { confineToOrigin } from "./session";

const repo = fileURLToPath(new URL("../../../../", import.meta.url));
const bin = join(repo, "bin/web-verify");
const fastHome = mkdtempSync(join(tmpdir(), "orbit-web-verify-fast-"));
const browserHome = mkdtempSync(join(tmpdir(), "orbit-web-verify-browser-"));

function run(
    home: string,
    args: readonly string[],
    env: NodeJS.ProcessEnv = {},
): {
    status: number;
    json: ToolResult;
    stderr: string;
} {
    const result = spawnSync(bin, args, {
        cwd: repo,
        encoding: "utf8",
        env: { ...process.env, ...env, ORBIT_WEB_VERIFY_HOME: home },
        timeout: 120_000,
    });
    const stdout = result.stdout ?? "";
    if (result.error !== undefined || stdout === "") {
        throw new Error(
            `${result.error?.message ?? "empty stdout"} (status ${result.status ?? "none"})\n${result.stderr ?? ""}`,
        );
    }

    return {
        status: result.status ?? -1,
        json: JSON.parse(stdout) as ToolResult,
        stderr: result.stderr ?? "",
    };
}

function sessionPid(home: string): number | null {
    const sessionFile = join(home, "session.json");
    if (!existsSync(sessionFile)) return null;
    try {
        const session = JSON.parse(readFileSync(sessionFile, "utf8")) as { pid?: unknown };

        return typeof session.pid === "number" ? session.pid : null;
    } catch {
        return null;
    }
}

function processState(pid: number): string | null {
    try {
        const stat = readFileSync(`/proc/${pid}/stat`, "utf8");
        const end = stat.lastIndexOf(")");
        const state = stat.slice(end + 2).split(" ")[0];

        return state === undefined || state === "" ? null : state;
    } catch {
        return null;
    }
}

function processAlive(pid: number): boolean {
    const state = processState(pid);

    return state !== null && state !== "Z";
}

function childPids(parent: number): number[] {
    const children: number[] = [];
    let entries: string[];
    try {
        entries = readdirSync("/proc");
    } catch {
        return children;
    }
    for (const entry of entries) {
        if (!/^[0-9]+$/.test(entry)) continue;
        const state = readFileSyncOrNull(`/proc/${entry}/stat`);
        if (state === null) continue;
        const end = state.lastIndexOf(")");
        const ppid = state.slice(end + 2).split(" ")[1];
        if (ppid === String(parent)) children.push(Number(entry));
    }

    return children;
}

function descendantPids(root: number): number[] {
    const out: number[] = [];
    const walk = (pid: number): void => {
        for (const child of childPids(pid)) {
            walk(child);
            out.push(child);
        }
    };
    walk(root);

    return out;
}

function cmdline(pid: number): string {
    try {
        return readFileSync(`/proc/${pid}/cmdline`).toString("utf8").replaceAll("\0", " ");
    } catch {
        return "";
    }
}

function readFileSyncOrNull(path: string): string | null {
    try {
        return readFileSync(path, "utf8");
    } catch {
        return null;
    }
}

async function waitUntilGone(pids: readonly number[]): Promise<void> {
    const deadline = Date.now() + 10_000;
    for (;;) {
        const left = pids.filter(processAlive);
        if (left.length === 0) return;
        if (Date.now() > deadline) {
            throw new Error(
                `processes still running: ${left.map((pid) => `${pid} ${cmdline(pid)}`).join("; ")}`,
            );
        }
        await delay(50);
    }
}

async function stop(home: string): Promise<void> {
    const pid = sessionPid(home);
    if (pid === null) return;
    const children = descendantPids(pid);
    try {
        process.kill(pid, "SIGTERM");
    } catch {
        // The daemon already exited.
    }
    const deadline = Date.now() + 10_000;
    while (Date.now() < deadline && processAlive(pid)) await delay(100);
    if (processAlive(pid)) {
        try {
            process.kill(pid, "SIGKILL");
        } catch {
            // The daemon already exited.
        }
    }
    for (const child of children) {
        if (!processAlive(child)) continue;
        try {
            process.kill(child, "SIGKILL");
        } catch {
            // The child already exited.
        }
    }
}

afterAll(async () => {
    await stop(browserHome);
    await stop(fastHome);
    rmSync(fastHome, { recursive: true, force: true });
    rmSync(browserHome, { recursive: true, force: true });
});

it("sets phone safe-area insets and clears them on desktop", async () => {
    const browser = await webkit.launch({ headless: true });
    try {
        const blank = "data:text/html,<!doctype html><html><body></body></html>";
        const phone = await browser.newContext();
        await phone.addInitScript(applyInsets, INSETS["iphone-15"]);
        const phonePage = await phone.newPage();
        await phonePage.goto(blank);
        expect(await inset(phonePage, "top")).toBe("0px");
        expect(await inset(phonePage, "bottom")).toBe("34px");
        expect(await inset(phonePage, "left")).toBe("0px");

        const pixel = await browser.newContext();
        await pixel.addInitScript(applyInsets, INSETS["pixel-8"]);
        const pixelPage = await pixel.newPage();
        await pixelPage.goto(blank);
        expect(await inset(pixelPage, "bottom")).toBe("24px");

        const desktop = await browser.newContext();
        await desktop.addInitScript(applyInsets, INSETS.desktop);
        const desktopPage = await desktop.newPage();
        await desktopPage.goto(blank);
        expect(await inset(desktopPage, "bottom")).toBe("");
        expect(await inset(desktopPage, "top")).toBe("");
    } finally {
        await browser.close();
    }
}, 60_000);

it("prints the feature map without starting the app", () => {
    const map = JSON.parse(
        readFileSync(join(repo, "apps/web/feature-map.json"), "utf8"),
    ) as unknown;
    const result = run(fastHome, ["routes"]);
    expect(result.status).toBe(0);
    expect(result.stderr).toBe("");
    expect(result.json).toEqual({ ok: true, command: "routes", routes: map });
    expect(existsSync(join(fastHome, "server.log"))).toBe(false);
});

it("rejects a wrong command line, a map pattern, and an unknown path", () => {
    const bare = run(fastHome, []);
    expect(bare.status).toBe(2);
    expect(bare.json.error).toBe("usage");
    expect(bare.json.next).toContain("iphone-15");
    expect(bare.json.next).toContain("webkit");

    const screenshot = run(fastHome, ["screenshot", "/activity", "--device=iphone-15"]);
    expect(screenshot.status).toBe(2);
    expect(screenshot.json.error).toBe("usage");
    expect(screenshot.json.command).toBe("screenshot");

    const pattern = run(fastHome, ["open", "/tasks/$id"]);
    expect(pattern.status).toBe(1);
    expect(pattern.json.error).toBe("unresolved-parameter");
    expect(pattern.json.route).toBe("/tasks/$id");
    expect(pattern.json.next).toContain("open /tasks/12");

    const unknown = run(fastHome, [
        "screenshot",
        "/no/such/page",
        "--device=desktop",
        "--engine=chromium",
    ]);
    expect(unknown.status).toBe(1);
    expect(unknown.json.error).toBe("unknown-route");
    expect(unknown.json.next).toContain("bin/web-verify routes");

    const foreign = run(fastHome, ["console-errors", "/\t/gateway.orbit"]);
    expect(foreign.status).toBe(2);
    expect(foreign.json).toMatchObject({
        ok: false,
        command: "console-errors",
        error: "usage",
        route: "/\t/gateway.orbit",
    });
    expect(foreign.json.message).toContain("demo server");
    expect(existsSync(join(fastHome, "server.log"))).toBe(false);
}, 30_000);

it("clicks nothing when the checkout has no active page", () => {
    const result = run(fastHome, ["click", "[data-testid=nav-menu]"]);
    expect(result.status).toBe(1);
    expect(result.json).toMatchObject({
        ok: false,
        command: "click",
        error: "no-page",
    });
    expect(result.json.next).toContain("open");
    expect(existsSync(join(fastHome, "session.json"))).toBe(false);
});

it("stops a checkout that has no daemon", () => {
    const extra = run(fastHome, ["stop", "/activity"]);
    expect(extra.status).toBe(2);
    expect(extra.json.error).toBe("usage");
    expect(extra.json.command).toBe("stop");

    const stopped = run(fastHome, ["stop"]);
    expect(stopped.status).toBe(0);
    expect(stopped.stderr).toBe("");
    expect(stopped.json).toEqual({ ok: true, command: "stop" });
    expect(existsSync(join(fastHome, "session.json"))).toBe(false);
    expect(existsSync(join(fastHome, "server.log"))).toBe(false);
});

it("screenshots phone and desktop pages, clicks the active page, and reports console errors", async () => {
    const phone = run(browserHome, [
        "screenshot",
        "/activity",
        "--device=iphone-15",
        "--engine=webkit",
    ]);
    expect(phone.status).toBe(0);
    expect(phone.stderr).toBe("");
    expect(phone.json).toMatchObject({
        ok: true,
        command: "screenshot",
        route: "/activity",
        pattern: "/activity",
        device: "iphone-15",
        engine: "webkit",
        viewport: { width: 393, height: 659 },
        reused: false,
    });
    expect(phone.json.url).toMatch(/^http:\/\/127\.0\.0\.1:\d+\/activity$/);
    expect(phone.json.file?.endsWith("activity__iphone-15__webkit.png")).toBe(true);
    expectPng(repo, phone.json.file ?? "");

    const phoneAgain = run(browserHome, [
        "screenshot",
        "/activity",
        "--device=iphone-15",
        "--engine=webkit",
    ]);
    expect(phoneAgain.json).toMatchObject({
        reused: true,
        engine: "webkit",
        device: "iphone-15",
    });

    const desktop = run(browserHome, [
        "screenshot",
        "/activity",
        "--device=desktop",
        "--engine=chromium",
    ]);
    expect(desktop.status).toBe(0);
    expect(desktop.json).toMatchObject({
        ok: true,
        command: "screenshot",
        route: "/activity",
        pattern: "/activity",
        device: "desktop",
        engine: "chromium",
        viewport: { width: 1280, height: 800 },
        reused: false,
    });
    expect(desktop.json.file?.endsWith("activity__desktop__chromium.png")).toBe(true);
    expectPng(repo, desktop.json.file ?? "");

    const desktopAgain = run(browserHome, [
        "screenshot",
        "/activity",
        "--device=desktop",
        "--engine=chromium",
    ]);
    expect(desktopAgain.json).toMatchObject({
        reused: true,
        engine: "chromium",
        device: "desktop",
    });

    const desktopClick = run(browserHome, ["click", "[data-testid=activity-filter-status]"]);
    expect(desktopClick.status).toBe(0);
    expect(desktopClick.json).toMatchObject({
        ok: true,
        command: "click",
        pattern: "/activity",
        device: "desktop",
        engine: "chromium",
        selector: "[data-testid=activity-filter-status]",
    });
    expect(
        desktopClick.json.route === "/activity" ||
            desktopClick.json.route?.startsWith("/activity?"),
    ).toBe(true);

    const phoneChromium = run(browserHome, [
        "screenshot",
        "/activity",
        "--device=iphone-15",
        "--engine=chromium",
    ]);
    expect(phoneChromium.status).toBe(0);
    expect(phoneChromium.json).toMatchObject({
        device: "iphone-15",
        engine: "chromium",
        viewport: { width: 393, height: 659 },
        reused: false,
    });
    expectPng(repo, phoneChromium.json.file ?? "");

    const desktopWebkit = run(browserHome, [
        "screenshot",
        "/activity",
        "--device=desktop",
        "--engine=webkit",
    ]);
    expect(desktopWebkit.status).toBe(0);
    expect(desktopWebkit.json).toMatchObject({
        device: "desktop",
        engine: "webkit",
        viewport: { width: 1280, height: 800 },
        reused: false,
    });
    expectPng(repo, desktopWebkit.json.file ?? "");

    const task = run(browserHome, ["open", "/tasks/12"]);
    expect(task.status).toBe(0);
    expect(task.json).toMatchObject({
        ok: true,
        command: "open",
        route: "/tasks/12",
        pattern: "/tasks/$id",
        device: "desktop",
        engine: "chromium",
    });
    expect(task.json.url).toMatch(/\/tasks\/12$/);

    const phoneOpen = run(browserHome, [
        "open",
        "/activity",
        "--device=iphone-15",
        "--engine=webkit",
    ]);
    expect(phoneOpen.status).toBe(0);
    expect(phoneOpen.json).toMatchObject({
        device: "iphone-15",
        engine: "webkit",
        pattern: "/activity",
    });

    const menu = run(browserHome, ["click", "[data-testid=nav-menu]"]);
    expect(menu.status).toBe(0);
    expect(menu.json).toMatchObject({
        ok: true,
        command: "click",
        route: "/activity",
        pattern: "/activity",
        device: "iphone-15",
        engine: "webkit",
        selector: "[data-testid=nav-menu]",
    });

    const errors = run(browserHome, ["console-errors", "/activity"]);
    expect(errors.status).toBe(0);
    expect(errors.json).toMatchObject({
        ok: true,
        command: "console-errors",
        route: "/activity",
        pattern: "/activity",
        device: "desktop",
        engine: "chromium",
        errors: [],
    });

    const stillPhone = run(browserHome, ["click", "[data-testid=nav-close]"]);
    expect(stillPhone.status).toBe(0);
    expect(stillPhone.json).toMatchObject({
        route: "/activity",
        device: "iphone-15",
        engine: "webkit",
        selector: "[data-testid=nav-close]",
    });

    const missing = run(browserHome, ["click", "[data-testid=not-a-control]"]);
    expect(missing.status).toBe(1);
    expect(missing.json).toMatchObject({
        ok: false,
        command: "click",
        error: "selector-missing",
        selector: "[data-testid=not-a-control]",
        device: "iphone-15",
        engine: "webkit",
    });
    expect(missing.json.next).toContain("data-testid");

    const log = readFileSync(join(browserHome, "server.log"), "utf8");
    expect(log).not.toContain("Gateway");

    const check = await webkit.launch({ headless: true });
    try {
        const context = await check.newContext();
        await context.addInitScript(applyInsets, INSETS["iphone-15"]);
        const page = await context.newPage();
        await page.goto(phone.json.url ?? "");
        await page.locator("[data-app-shell]").waitFor();
        const padding = await page.evaluate(() => {
            const shell = document.querySelector("[data-app-shell]");
            if (!(shell instanceof HTMLElement)) return "";

            return getComputedStyle(shell).paddingBottom;
        });
        expect(padding).toBe("34px");
    } finally {
        await check.close();
    }
}, 180_000);

function inset(page: Page, edge: string): Promise<string> {
    return page.evaluate(
        (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim(),
        `--safe-area-inset-${edge}`,
    );
}

function expectPng(root: string, file: string): void {
    const bytes = readFileSync(resolve(root, file));
    expect(bytes.subarray(0, 8).toString("hex")).toBe("89504e470d0a1a0a");
}

it("does not let demo adapters call the CLI or an upstream service", async () => {
    const hits = { count: 0 };
    const upstream = createServer((request, response) => {
        hits.count += 1;
        response.writeHead(200, { "Content-Type": "application/json" });
        response.end(JSON.stringify({ seen: request.url }));
    });
    await new Promise<void>((ready) => {
        upstream.listen(0, "127.0.0.1", () => ready());
    });
    const port = (upstream.address() as AddressInfo).port;
    const home = mkdtempSync(join(tmpdir(), "orbit-web-verify-demo-"));
    try {
        const opened = run(home, ["open", "/activity"], {
            ANNOTATION_TRANSCRIPTION_TARGET: `http://127.0.0.1:${port}`,
        });
        expect(opened.status).toBe(0);
        const origin = new URL(opened.json.url ?? "").origin;

        const profile = await fetch(`${origin}/__orbit/profile?instance=1`);
        expect(await profile.json()).toEqual({
            ok: false,
            output: "Demo mode does not run orbit profile.",
        });

        const thread = await fetch(`${origin}/__annotate/thread`);
        expect(await thread.json()).toEqual({ status: "unavailable", reason: "demo" });

        const speech = await fetch(`${origin}/__annotate/speech`);
        expect(speech.ok).toBe(true);
        expect(await speech.text()).not.toBe("upstream");

        const annotation = await fetch(`${origin}/__annotate/local/${port}/annotations`);
        expect(await annotation.json()).toEqual({
            error: "Demo mode does not call the annotation server.",
        });
        expect(hits.count).toBe(0);
    } finally {
        await stop(home);
        upstream.close();
        rmSync(home, { recursive: true, force: true });
    }
}, 120_000);

it("reports a screenshot write failure as that command", () => {
    const file = "activity__desktop__chromium.png";
    const blocked = join(browserHome, file);
    rmSync(blocked, { force: true });
    mkdirSync(blocked);
    try {
        const failed = run(browserHome, [
            "screenshot",
            "/activity",
            "--device=desktop",
            "--engine=chromium",
        ]);
        expect(failed.status).toBe(1);
        expect(failed.json.command).toBe("screenshot");
        expect(failed.json.error).toBe("command-failed");
        expect(failed.json.message).toMatch(/EISDIR|directory|not a file|screenshot/i);
        expect(failed.json.next).toContain("daemon.log");
        expect(failed.json.next).not.toContain("server.log");
        expect(failed.json.message).not.toBe("The demo server did not answer.");
    } finally {
        rmSync(blocked, { recursive: true, force: true });
    }
}, 120_000);

it("blocks a direct request and each redirect hop before another host is contacted", async () => {
    const sinkHits: string[] = [];
    const sink = createServer((request, response) => {
        sinkHits.push(request.url ?? "/");
        response.writeHead(200, { "Content-Type": "text/html" });
        response.end("<html><body>sink</body></html>");
    });
    await new Promise<void>((ready) => {
        sink.listen(0, "127.0.0.1", () => ready());
    });
    const sinkPort = (sink.address() as AddressInfo).port;
    const sinkOrigin = `http://127.0.0.1:${sinkPort}`;
    const allowedHits: string[] = [];
    const allowed = createServer((request, response) => {
        const url = request.url ?? "/";
        allowedHits.push(url);
        const times = allowedHits.filter((hit) => hit === url).length;
        if (url === "/flip") {
            if (times === 1) {
                response.writeHead(200, { "Content-Type": "text/html" });
                response.end("<html><body>checked</body></html>");
                return;
            }
            response.writeHead(302, { Location: `${sinkOrigin}/from-second` });
            response.end();
            return;
        }
        if (url === "/bounce") {
            response.writeHead(302, { Location: `${sinkOrigin}/secret` });
            response.end();
            return;
        }
        if (url === "/chain") {
            response.writeHead(302, { Location: "/hop" });
            response.end();
            return;
        }
        if (url === "/hop") {
            response.writeHead(302, { Location: `${sinkOrigin}/chained` });
            response.end();
            return;
        }
        if (url === "/stay") {
            response.writeHead(302, { Location: "/landed" });
            response.end();
            return;
        }
        response.writeHead(200, { "Content-Type": "text/html" });
        response.end(`<html><body>${url}</body></html>`);
    });
    await new Promise<void>((ready) => {
        allowed.listen(0, "127.0.0.1", () => ready());
    });
    const origin = `http://127.0.0.1:${(allowed.address() as AddressInfo).port}`;
    try {
        for (const engine of [chromium, webkit]) {
            allowedHits.length = 0;
            sinkHits.length = 0;
            const browser = await engine.launch({ headless: true });
            try {
                const context = await browser.newContext();
                await confineToOrigin(context, origin);
                const page = await context.newPage();
                const homeAt = allowedHits.length;
                await page.goto(`${origin}/`, { timeout: 5_000 });
                expect(allowedHits.slice(homeAt).filter((hit) => hit === "/")).toEqual(["/"]);
                expect(await page.locator("body").innerText()).toContain("/");

                const stayAt = allowedHits.length;
                await page.goto(`${origin}/stay`, { timeout: 5_000 });
                const stayHits = allowedHits.slice(stayAt);
                expect(stayHits.filter((hit) => hit === "/stay")).toEqual(["/stay"]);
                expect(stayHits.filter((hit) => hit === "/landed")).toEqual(["/landed"]);
                expect(await page.locator("body").innerText()).toContain("/landed");

                const flipAt = allowedHits.length;
                const sinkAt = sinkHits.length;
                await page.goto(`${origin}/flip`, { timeout: 5_000 });
                expect(allowedHits.slice(flipAt).filter((hit) => hit === "/flip")).toEqual([
                    "/flip",
                ]);
                expect(sinkHits.slice(sinkAt)).toEqual([]);
                expect(await page.locator("body").innerText()).toContain("checked");
                expect(new URL(page.url()).origin).toBe(origin);
                expect(sinkHits).toEqual([]);

                const socket = await page.evaluate(
                    (target) =>
                        new Promise((resolve) => {
                            const ws = new WebSocket(target);
                            const finish = (value: string) => resolve(value);
                            ws.onopen = () => finish("open");
                            ws.onerror = () => finish("error");
                            setTimeout(() => finish("timeout"), 2_000);
                        }),
                    `ws://127.0.0.1:${sinkPort}/socket`,
                );
                expect(socket).not.toBe("open");
                expect(sinkHits).toEqual([]);

                const direct = await context.newPage();
                await expect(
                    direct.goto(`${sinkOrigin}/direct`, { timeout: 5_000 }),
                ).rejects.toThrow();
                expect(sinkHits).toEqual([]);

                const redirect = await context.newPage();
                await expect(
                    redirect.goto(`${origin}/bounce`, { timeout: 5_000 }),
                ).rejects.toThrow();
                expect(sinkHits).toEqual([]);

                const chain = await context.newPage();
                await expect(chain.goto(`${origin}/chain`, { timeout: 5_000 })).rejects.toThrow();
                expect(sinkHits).toEqual([]);
            } finally {
                await browser.close();
            }
        }
    } finally {
        sink.close();
        allowed.close();
    }
}, 60_000);

it("stops the daemon and leaves no child processes", async () => {
    const home = mkdtempSync(join(tmpdir(), "orbit-web-verify-stop-"));
    try {
        const opened = run(home, ["open", "/activity", "--engine=chromium"], {
            ORBIT_WEB_VERIFY_IDLE_MS: "600000",
        });
        expect(opened.status).toBe(0);
        const webkit = run(home, ["open", "/activity", "--engine=webkit"], {
            ORBIT_WEB_VERIFY_IDLE_MS: "600000",
        });
        expect(webkit.status).toBe(0);
        const pid = sessionPid(home);
        expect(pid).not.toBeNull();
        const children = descendantPids(pid ?? 0);
        const tree = children.map(cmdline).join("\n");
        expect(tree).toMatch(/vp/);
        expect(tree).toMatch(/chrom|headless_shell|chrome/i);
        expect(tree).toMatch(/webkit/i);

        const stopped = run(home, ["stop"]);
        expect(stopped.status).toBe(0);
        expect(stopped.stderr).toBe("");
        expect(stopped.json).toEqual({ ok: true, command: "stop" });
        expect(processAlive(pid ?? 0)).toBe(false);
        await waitUntilGone(children);

        const again = run(home, ["stop"]);
        expect(again.status).toBe(0);
        expect(again.json).toEqual({ ok: true, command: "stop" });
        const click = run(home, ["click", "[data-testid=nav-menu]"]);
        expect(click.status).toBe(1);
        expect(click.json.error).toBe("no-page");
        expect(sessionPid(home)).toBeNull();
    } finally {
        await stop(home);
        rmSync(home, { recursive: true, force: true });
    }
}, 180_000);

it("exits after the idle timeout and leaves no child processes", async () => {
    const home = mkdtempSync(join(tmpdir(), "orbit-web-verify-idle-"));
    try {
        const opened = run(home, ["open", "/activity"], { ORBIT_WEB_VERIFY_IDLE_MS: "3000" });
        expect(opened.status).toBe(0);
        const pid = sessionPid(home);
        expect(pid).not.toBeNull();
        const children = descendantPids(pid ?? 0);
        const tree = children.map(cmdline).join("\n");
        expect(tree).toMatch(/vp/);
        expect(children.length).toBeGreaterThan(0);

        const deadline = Date.now() + 20_000;
        while (pid !== null && processAlive(pid) && Date.now() < deadline) await delay(100);
        expect(processAlive(pid ?? 0)).toBe(false);
        await waitUntilGone(children);

        const click = run(home, ["click", "[data-testid=nav-menu]"]);
        expect(click.status).toBe(1);
        expect(click.json.error).toBe("no-page");
    } finally {
        await stop(home);
        rmSync(home, { recursive: true, force: true });
    }
}, 180_000);
