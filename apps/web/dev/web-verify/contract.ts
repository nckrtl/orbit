import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { dirname, join, relative, resolve, sep } from "node:path";

/** Devices and engines `bin/web-verify` accepts. Portrait only. */
export const DEVICES = ["iphone-15", "pixel-8", "desktop"] as const;
export const ENGINES = ["webkit", "chromium"] as const;

export type Device = (typeof DEVICES)[number];
export type Engine = (typeof ENGINES)[number];

export type ErrorCode =
    | "usage"
    | "unknown-route"
    | "ambiguous-route"
    | "no-page"
    | "unresolved-parameter"
    | "selector-missing"
    | "console-errors"
    | "navigation-failed"
    | "server-failed"
    | "browser-missing"
    | "map-invalid"
    | "command-failed";

export type ConsoleError = { source: "console" | "pageerror"; message: string };

/** One stdout object. Absent fields are omitted, not null. */
export type ToolResult = {
    ok: boolean;
    command: string;
    route?: string;
    pattern?: string;
    url?: string;
    device?: Device;
    engine?: Engine;
    routes?: readonly unknown[];
    selector?: string;
    file?: string;
    viewport?: { width: number; height: number };
    reused?: boolean;
    errors?: readonly ConsoleError[];
    error?: ErrorCode;
    message?: string;
    next?: string;
};

export type RouteCommand =
    | { command: "open"; route: string; device: Device; engine: Engine }
    | { command: "screenshot"; route: string; device: Device; engine: Engine }
    | { command: "console-errors"; route: string; device: Device; engine: Engine };

export type ClickCommand = { command: "click"; selector: string };

export type SessionCommand = RouteCommand | ClickCommand;

/** Safe-area padding for a phone screenshot. Desktop leaves the properties unset. */
export type Insets = { top: string; right: string; bottom: string; left: string };

export const INSETS: Record<Device, Insets | null> = {
    "iphone-15": { top: "0px", right: "0px", bottom: "34px", left: "0px" },
    "pixel-8": { top: "0px", right: "0px", bottom: "24px", left: "0px" },
    desktop: null,
};

/** Playwright device descriptor names. Desktop is not a preset. */
export const PLAYWRIGHT_DEVICE: Record<Exclude<Device, "desktop">, string> = {
    "iphone-15": "iPhone 15",
    "pixel-8": "Pixel 8",
};

export const DESKTOP_VIEWPORT = { width: 1280, height: 800 } as const;

export const INSTALL_BROWSERS = "bunx playwright install webkit chromium";

export const USAGE_NEXT =
    "Devices are iphone-15, pixel-8, or desktop. Engines are webkit or chromium.";

export type Paths = {
    repoRoot: string;
    webRoot: string;
    mapFile: string;
    home: string;
    serverLog: string;
    daemonLog: string;
    socketPath: string;
    sessionFile: string;
    lockDir: string;
};

/** Repo paths for the tool. `ORBIT_WEB_VERIFY_HOME` isolates a test from the shared session. */
export function pathsFrom(scriptFile: string, env: NodeJS.ProcessEnv = process.env): Paths {
    const webRoot = dirname(dirname(scriptFile));
    // apps/web/dev/web-verify.ts -> apps/web -> apps -> repository root.
    const repoRoot = dirname(dirname(webRoot));
    const home =
        env.ORBIT_WEB_VERIFY_HOME !== undefined && env.ORBIT_WEB_VERIFY_HOME !== ""
            ? resolve(repoRoot, env.ORBIT_WEB_VERIFY_HOME)
            : join(repoRoot, ".orbit-artifacts", "web");

    return {
        repoRoot,
        webRoot,
        mapFile: join(webRoot, "feature-map.json"),
        home,
        serverLog: join(home, "server.log"),
        daemonLog: join(home, "daemon.log"),
        socketPath: socketFor(home),
        sessionFile: join(home, "session.json"),
        lockDir: join(home, "lock"),
    };
}

/**
 * Unix socket paths fail past about 108 bytes. A long home directory gets a short path in the
 * temp dir, derived from the home path so the client and the daemon pick the same file.
 */
function socketFor(home: string): string {
    const preferred = join(home, "session.sock");
    if (Buffer.byteLength(preferred) <= 100) return preferred;

    const hash = createHash("sha256").update(preferred).digest("hex").slice(0, 16);

    return join(tmpdir(), `orbit-web-verify-${hash}.sock`);
}

export function isDevice(value: string): value is Device {
    return (DEVICES as readonly string[]).includes(value);
}

export function isEngine(value: string): value is Engine {
    return (ENGINES as readonly string[]).includes(value);
}

/** The PNG slug. The dashboard is `home`. A query string is not part of the file name. */
export function screenshotSlug(route: string): string {
    const pathname = pathnameOf(route);
    if (pathname === "/") return "home";

    return pathname.replace(/^\//, "").replaceAll("/", "-");
}

export function screenshotFilename(route: string, device: Device, engine: Engine): string {
    return `${screenshotSlug(route)}__${device}__${engine}.png`;
}

/** Path printed in JSON, relative to the repository root. */
export function displayPath(repoRoot: string, absolute: string): string {
    return relative(repoRoot, absolute).split(sep).join("/");
}

export function pathnameOf(route: string): string {
    const hash = route.indexOf("#");
    const beforeHash = hash === -1 ? route : route.slice(0, hash);
    const query = beforeHash.indexOf("?");

    return query === -1 ? beforeHash : beforeHash.slice(0, query);
}

export function usage(command: string, message: string): ToolResult {
    return { ok: false, command, error: "usage", message, next: USAGE_NEXT };
}

export function exitCode(result: ToolResult): number {
    if (result.ok) return 0;

    return result.error === "usage" ? 2 : 1;
}

/** An unexpected failure of a command that did run. `message` is the underlying error. */
export function commandFailed(
    command: string,
    error: unknown,
    extra: Partial<ToolResult> = {},
): ToolResult {
    const detail =
        (error instanceof Error ? error.message : String(error)).split("\n")[0] ??
        "The command failed.";
    const message = detail.endsWith(".") ? detail : `${detail}.`;

    return {
        ok: false,
        ...extra,
        command,
        error: "command-failed",
        message,
        next: `Read .orbit-artifacts/web/daemon.log for the cause, then run bin/web-verify ${command} again.`,
    };
}

/**
 * Sets or clears `--safe-area-inset-*` on the document element. Passed to Playwright as an init
 * script, so it runs before the page's own scripts and the shell's first paint.
 */
export function applyInsets(insets: Insets | null): void {
    const apply = (): void => {
        const root = document.documentElement;
        if (root === null) return;
        for (const edge of ["top", "right", "bottom", "left"] as const) {
            const property = `--safe-area-inset-${edge}`;
            if (insets === null) root.style.removeProperty(property);
            else root.style.setProperty(property, insets[edge]);
        }
    };
    // Playwright can evaluate this before the document element exists. Applying again as the
    // document loads still beats the shell's first paint, which waits on the app's scripts.
    if (document.readyState === "loading") document.addEventListener("readystatechange", apply);
    apply();
}
