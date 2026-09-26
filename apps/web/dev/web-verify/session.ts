import {
    chromium,
    devices,
    webkit,
    type Browser,
    type BrowserContext,
    type APIResponse,
    type BrowserContextOptions,
    type Page,
    type Route,
    type WebSocketRoute,
} from "playwright";
import { mkdirSync } from "node:fs";
import { join } from "node:path";
import {
    applyInsets,
    commandFailed,
    DESKTOP_VIEWPORT,
    displayPath,
    INSETS,
    INSTALL_BROWSERS,
    pathnameOf,
    PLAYWRIGHT_DEVICE,
    screenshotFilename,
    USAGE_NEXT,
    type ConsoleError,
    type Device,
    type Engine,
    type Paths,
    type SessionCommand,
    type ToolResult,
} from "./contract";
import {
    canonicalRoute,
    concreteRoute,
    loadFeatureMap,
    mapInvalidMessage,
    matchRoute,
    targetUrl,
    unresolvedParameter,
} from "./map";
import { DemoServer } from "./server";

type Held = { context: BrowserContext; page: Page; device: Device; origin: string };

/**
 * Rejects a map pattern, an unknown path, or a broken feature map before a browser starts.
 * `command` is a placeholder; the caller sets the subcommand that was actually run.
 */
export function assessRoute(
    mapFile: string,
    route: string,
): { ok: true; pattern: string } | { ok: false; result: ToolResult } {
    if (!concreteRoute(route)) {
        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                error: "usage",
                message: `The route ${route} is not a concrete path.`,
                next: USAGE_NEXT,
            },
        };
    }
    const pathname = pathnameOf(route);
    if (unresolvedParameter(pathname)) {
        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                route,
                error: "unresolved-parameter",
                message: `The path ${route} still has a $ segment.`,
                next: "Replace each $name with one segment, such as open /tasks/12 instead of open /tasks/$id.",
            },
        };
    }
    if (canonicalRoute(route) === null) return { ok: false, result: offOrigin(route) };

    const loaded = loadFeatureMap(mapFile);
    if (!loaded.ok) {
        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                error: "map-invalid",
                message: mapInvalidMessage(loaded),
                next: `Fix ${loaded.field} in apps/web/feature-map.json.`,
            },
        };
    }
    const matched = matchRoute(loaded.routes, pathname);
    if (!matched.ok) {
        const ambiguous = matched.error === "ambiguous-route";

        return {
            ok: false,
            result: {
                ok: false,
                command: "open",
                route,
                error: matched.error,
                message: ambiguous
                    ? `The path ${route} matches more than one feature map entry.`
                    : `The path ${route} matches no feature map entry.`,
                next: "Run bin/web-verify routes and pass a concrete path.",
            },
        };
    }

    return matched;
}

/**
 * One checkout's demo server and its browser pages. Each engine keeps the page last used for it.
 * The active page is the latest `open`, or the latest `screenshot` that had to load a route.
 */
export class Verifier {
    private readonly paths: Paths;
    private readonly server: DemoServer;
    private readonly browsers = new Map<Engine, Browser>();
    private readonly pages = new Map<Engine, Held>();
    private active: Engine | null = null;

    constructor(paths: Paths) {
        this.paths = paths;
        this.server = new DemoServer(paths);
    }

    async run(command: SessionCommand): Promise<ToolResult> {
        try {
            if (command.command === "click") return await this.click(command.selector);

            const resolved = assessRoute(this.paths.mapFile, command.route);
            if (resolved.ok === false) return { ...resolved.result, command: command.command };
            if (command.command === "console-errors") {
                return await this.consoleErrors(command.route, resolved.pattern);
            }
            if (command.command === "open") return await this.open(command, resolved.pattern);

            return await this.screenshot(command, resolved.pattern);
        } catch (error) {
            if (error instanceof BrowserMissingError)
                return browserMissing(command.command, error.engine);
            if (isServerFailure(error)) return serverFailed(command.command);
            logFailure(error);

            return commandFailed(command.command, error);
        }
    }

    async close(): Promise<void> {
        for (const held of this.pages.values()) {
            await held.context.close().catch(() => undefined);
        }
        this.pages.clear();
        for (const browser of this.browsers.values()) {
            await browser.close().catch(() => undefined);
        }
        this.browsers.clear();
        this.active = null;
        await this.server.stop();
    }

    private async open(
        command: Extract<SessionCommand, { command: "open" }>,
        pattern: string,
    ): Promise<ToolResult> {
        const loaded = await this.loadRoute(command.engine, command.device, command.route);
        if (!loaded.ok) return { ...loaded.result, command: "open", route: command.route, pattern };
        this.active = command.engine;

        return pageResult("open", {
            route: command.route,
            pattern,
            url: loaded.url,
            device: command.device,
            engine: command.engine,
        });
    }

    private async screenshot(
        command: Extract<SessionCommand, { command: "screenshot" }>,
        pattern: string,
    ): Promise<ToolResult> {
        const origin = await this.server.ensure();
        const held = this.pages.get(command.engine);
        const reused =
            held !== undefined &&
            held.device === command.device &&
            shownRoute(held.page.url(), origin) === command.route;
        let url: string;
        let page: Page;
        if (reused && held !== undefined) {
            page = held.page;
            url = held.page.url();
        } else {
            const loaded = await this.loadRoute(
                command.engine,
                command.device,
                command.route,
                origin,
            );
            if (!loaded.ok) {
                return { ...loaded.result, command: "screenshot", route: command.route, pattern };
            }
            page = loaded.page;
            url = loaded.url;
            this.active = command.engine;
        }

        const absolute = join(
            this.paths.home,
            screenshotFilename(command.route, command.device, command.engine),
        );
        mkdirSync(this.paths.home, { recursive: true });
        try {
            await page.screenshot({ path: absolute, type: "png" });
        } catch (error) {
            logFailure(error);
            return commandFailed("screenshot", error, {
                route: command.route,
                pattern,
                url,
                device: command.device,
                engine: command.engine,
            });
        }
        const viewport = page.viewportSize() ?? DESKTOP_VIEWPORT;

        return pageResult("screenshot", {
            route: command.route,
            pattern,
            url,
            device: command.device,
            engine: command.engine,
            file: displayPath(this.paths.repoRoot, absolute),
            viewport: { width: viewport.width, height: viewport.height },
            reused,
        });
    }

    private async click(selector: string): Promise<ToolResult> {
        const engine = this.active;
        const held = engine === null ? undefined : this.pages.get(engine);
        if (engine === null || held === undefined) {
            return {
                ok: false,
                command: "click",
                error: "no-page",
                message: "There is no active page to click.",
                next: "Run bin/web-verify open <route> first.",
            };
        }

        const origin = await this.server.ensure();
        try {
            await held.page.locator(selector).click({ timeout: 5_000 });
        } catch (error) {
            const strict =
                error instanceof Error && error.message.includes("strict mode violation");

            return {
                ok: false,
                command: "click",
                route: shownRoute(held.page.url(), origin) ?? undefined,
                pattern: this.patternFor(shownRoute(held.page.url(), origin)),
                url: held.page.url(),
                device: held.device,
                engine,
                selector,
                error: "selector-missing",
                message: strict
                    ? `The selector ${selector} matched more than one element.`
                    : `The selector ${selector} matched nothing visible.`,
                next: "Use a testid from the feature map, or add the missing data-testid on the control and in the map.",
            };
        }

        const route = shownRoute(held.page.url(), origin) ?? "";

        return pageResult("click", {
            route,
            pattern: this.patternFor(route) ?? "",
            url: held.page.url(),
            device: held.device,
            engine,
            selector,
        });
    }

    /** A second desktop Chromium page. It closes before returning and does not change the active page. */
    private async consoleErrors(route: string, pattern: string): Promise<ToolResult> {
        const origin = await this.server.ensure();
        const browser = await this.browser("chromium");
        const context = await browser.newContext(contextOptions("desktop"));
        await context.addInitScript(applyInsets, INSETS.desktop);
        await confineToOrigin(context, origin);
        const page = await context.newPage();
        const errors: ConsoleError[] = [];
        page.on("console", (message) => {
            if (message.type() === "error")
                errors.push({ source: "console", message: message.text() });
        });
        page.on("pageerror", (error) => {
            errors.push({ source: "pageerror", message: error.message });
        });
        const target = targetUrl(origin, route);
        if (target === null) {
            await context.close();
            return { ...offOrigin(route), command: "console-errors" };
        }
        const loaded = await load(page, target.href);
        const url = loaded ?? target.href;
        await context.close();
        const shared = {
            route,
            pattern,
            url,
            device: "desktop" as const,
            engine: "chromium" as const,
            errors,
        };
        if (loaded === null) {
            return {
                ok: false,
                command: "console-errors",
                ...shared,
                error: "navigation-failed",
                message: `The route ${route} did not load.`,
                next: "Run the command again. If it still fails, read .orbit-artifacts/web/server.log.",
            };
        }
        if (errors.length > 0) {
            return {
                ok: false,
                command: "console-errors",
                ...shared,
                error: "console-errors",
                message: `The page reported ${errors.length} console ${errors.length === 1 ? "error" : "errors"}.`,
                next: "Read errors, fix the page, and run bin/web-verify console-errors again.",
            };
        }

        return pageResult("console-errors", shared);
    }

    private async loadRoute(
        engine: Engine,
        device: Device,
        route: string,
        knownOrigin?: string,
    ): Promise<{ ok: true; page: Page; url: string } | { ok: false; result: ToolResult }> {
        const origin = knownOrigin ?? (await this.server.ensure());
        const target = targetUrl(origin, route);
        if (target === null) return { ok: false, result: offOrigin(route) };
        const page = await this.pageFor(engine, device, origin);
        const url = await load(page, target.href);
        if (url === null) return { ok: false, result: navigationFailed(route) };

        return { ok: true, page, url };
    }

    private async pageFor(engine: Engine, device: Device, origin: string): Promise<Page> {
        const current = this.pages.get(engine);
        if (current !== undefined && current.device === device && current.origin === origin) {
            return current.page;
        }

        await current?.context.close().catch(() => undefined);
        const browser = await this.browser(engine);
        const context = await browser.newContext(contextOptions(device));
        await context.addInitScript(applyInsets, INSETS[device]);
        await confineToOrigin(context, origin);
        const page = await context.newPage();
        this.pages.set(engine, { context, page, device, origin });

        return page;
    }

    private async browser(engine: Engine): Promise<Browser> {
        const existing = this.browsers.get(engine);
        if (existing?.isConnected()) return existing;

        try {
            const browser = await (engine === "webkit" ? webkit : chromium).launch({
                headless: true,
            });
            this.browsers.set(engine, browser);

            return browser;
        } catch (error) {
            if (missingBrowser(error)) throw new BrowserMissingError(engine);

            throw error;
        }
    }

    private patternFor(route: string | null): string | undefined {
        if (route === null) return undefined;
        const loaded = loadFeatureMap(this.paths.mapFile);
        if (!loaded.ok) return undefined;
        const matched = matchRoute(loaded.routes, pathnameOf(route));

        return matched.ok ? matched.pattern : undefined;
    }
}

class BrowserMissingError extends Error {
    readonly engine: Engine;

    constructor(engine: Engine) {
        super(`The Playwright ${engine} browser is not installed.`);
        this.engine = engine;
    }
}

function contextOptions(device: Device): BrowserContextOptions {
    if (device === "desktop") {
        return {
            viewport: { ...DESKTOP_VIEWPORT },
            deviceScaleFactor: 1,
            isMobile: false,
            hasTouch: false,
        };
    }

    const preset = devices[PLAYWRIGHT_DEVICE[device]];
    if (preset?.viewport === undefined) {
        throw new Error(`Playwright has no ${device} descriptor.`);
    }

    return preset;
}

/** The shell is up, and a loading line has left, before a picture or a click. */
async function load(page: Page, url: string): Promise<string | null> {
    try {
        const response = await page.goto(url, { waitUntil: "domcontentloaded", timeout: 30_000 });
        if (response !== null && response.status() >= 500) return null;
        await page.locator("[data-app-shell]").waitFor({ state: "visible", timeout: 30_000 });
        await page
            .locator("[role=status]", { hasText: "Loading" })
            .waitFor({ state: "hidden", timeout: 15_000 })
            .catch(() => undefined);

        return page.url();
    } catch {
        return null;
    }
}

function shownRoute(pageUrl: string, origin: string): string | null {
    try {
        const url = new URL(pageUrl);
        if (url.origin !== origin) return null;

        return `${url.pathname}${url.search}${url.hash}`;
    } catch {
        return null;
    }
}

function pageResult(
    command: string,
    fields: {
        route: string;
        pattern: string;
        url: string;
        device: Device;
        engine: Engine;
        selector?: string;
        file?: string;
        viewport?: { width: number; height: number };
        reused?: boolean;
        errors?: readonly ConsoleError[];
    },
): ToolResult {
    return { ok: true, command, ...fields };
}

function logFailure(error: unknown): void {
    const detail = error instanceof Error ? (error.stack ?? error.message) : String(error);
    process.stderr.write(`${detail}\n`);
}

function offOrigin(route: string): ToolResult {
    return {
        ok: false,
        command: "open",
        route,
        error: "usage",
        message: "The route does not stay on the demo server.",
        next: USAGE_NEXT,
    };
}

/** Abort any request or socket whose host is not the demo server, and answer with the checked response. */
export async function confineToOrigin(context: BrowserContext, origin: string): Promise<void> {
    const allow = (raw: string): boolean => {
        try {
            return sameDemoHost(new URL(raw), origin);
        } catch {
            return false;
        }
    };
    // Fulfilling a response makes Chromium treat the page as non-local, which blocks the demo server's WebSocket.
    if (context.browser()?.browserType().name() === "chromium") {
        await context.grantPermissions(["local-network-access"], { origin });
    }
    await context.route("**/*", async (route) => {
        try {
            await settleOnOrigin(route, allow);
        } catch {
            await route.abort("blockedbyclient").catch(() => undefined);
        }
    });
    await context.routeWebSocket(/.*/, (socket) => connectOrClose(socket, allow));
}

const REDIRECT_STATUS = new Set([301, 302, 303, 307, 308]);

/**
 * Fetch the response once and fulfill that exact result. `route.continue()` would send a second request,
 * and Playwright would follow a redirect on that second request without asking this handler again.
 * A 3xx is never fulfilled: a disallowed hop aborts, and an allowed hop is fetched before the browser sees it.
 */
async function settleOnOrigin(route: Route, allow: (raw: string) => boolean): Promise<void> {
    if (!allow(route.request().url())) {
        await route.abort("blockedbyclient");
        return;
    }
    const response = await responseOnOrigin(route, allow);
    if (response === null) {
        await route.abort("blockedbyclient");
        return;
    }
    await route.fulfill({ response });
}

/** The checked response, or null when a hop would leave the demo host. */
async function responseOnOrigin(
    route: Route,
    allow: (raw: string) => boolean,
): Promise<APIResponse | null> {
    let url = route.request().url();
    let response = await route.fetch({ maxRedirects: 0 });
    for (let hop = 0; hop < 5; hop++) {
        if (!REDIRECT_STATUS.has(response.status())) return response;
        const location = response.headers()["location"];
        if (location === undefined || location === "") return null;
        let next: URL;
        try {
            next = new URL(location, url);
        } catch {
            return null;
        }
        if (!allow(next.href)) return null;
        url = next.href;
        response = await route.fetch({ url, maxRedirects: 0 });
    }

    return null;
}

function connectOrClose(socket: WebSocketRoute, allow: (raw: string) => boolean): void {
    if (!allow(socket.url())) {
        void socket.close();
        return;
    }
    socket.connectToServer();
}

function sameDemoHost(target: URL, origin: string): boolean {
    const allowed = new URL(origin);
    if (target.hostname !== allowed.hostname || target.port !== allowed.port) return false;

    return target.protocol === "http:" || target.protocol === "ws:";
}

function navigationFailed(route: string): ToolResult {
    return {
        ok: false,
        command: "open",
        route,
        error: "navigation-failed",
        message: `The route ${route} did not load.`,
        next: "Run the command again. If it still fails, read .orbit-artifacts/web/server.log.",
    };
}

function browserMissing(command: string, engine: Engine): ToolResult {
    return {
        ok: false,
        command,
        error: "browser-missing",
        message: `The Playwright ${engine} browser is not installed.`,
        next: `From apps/web, run ${INSTALL_BROWSERS}.`,
    };
}

function serverFailed(command: string): ToolResult {
    return {
        ok: false,
        command,
        error: "server-failed",
        message: "The demo server did not answer.",
        next: "Read .orbit-artifacts/web/server.log and run the command again.",
    };
}

function isServerFailure(error: unknown): boolean {
    return error instanceof Error && error.message === "The demo server did not answer.";
}

function missingBrowser(error: unknown): boolean {
    const message = error instanceof Error ? error.message : String(error);

    return (
        message.includes("Executable doesn't exist") ||
        message.includes("Looks like Playwright was just installed") ||
        message.includes("Host system is missing dependencies")
    );
}
