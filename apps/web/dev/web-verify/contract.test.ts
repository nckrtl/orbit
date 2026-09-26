import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, expect, it } from "vite-plus/test";
import { pathsFrom, screenshotFilename, screenshotSlug, USAGE_NEXT } from "./contract";
import {
    canonicalRoute,
    concreteRoute,
    loadFeatureMap,
    matchRoute,
    unresolvedParameter,
    validateMap,
} from "./map";
import { parseCommand } from "./parse";
import { assessRoute } from "./session";

const mapFile = join(fileURLToPath(new URL("../../feature-map.json", import.meta.url)));
const loaded = loadFeatureMap(mapFile);
const routes = loaded.ok ? loaded.routes : [];

const tempDirs: string[] = [];

afterEach(() => {
    for (const dir of tempDirs.splice(0)) rmSync(dir, { recursive: true, force: true });
});

it("names screenshot files from the path, device, and engine", () => {
    expect(screenshotSlug("/")).toBe("home");
    expect(screenshotSlug("/activity")).toBe("activity");
    expect(screenshotSlug("/tasks/12/subtasks/31")).toBe("tasks-12-subtasks-31");
    expect(screenshotSlug("/activity?status=failed")).toBe("activity");
    expect(screenshotFilename("/activity", "iphone-15", "webkit")).toBe(
        "activity__iphone-15__webkit.png",
    );
    expect(screenshotFilename("/", "desktop", "chromium")).toBe("home__desktop__chromium.png");
});

it("prefers static map segments and rejects a tie", () => {
    expect(matchRoute(routes, "/tasks/12")).toEqual({ ok: true, pattern: "/tasks/$id" });
    expect(matchRoute(routes, "/tasks")).toEqual({ ok: true, pattern: "/tasks" });
    expect(matchRoute(routes, "/tasks/12/subtasks/31")).toEqual({
        ok: true,
        pattern: "/tasks/$id/subtasks/$subtaskId",
    });
    expect(matchRoute(routes, "/nodes/1")).toEqual({ ok: true, pattern: "/$section/$id" });
    expect(matchRoute(routes, "/nodes/create")).toEqual({ ok: true, pattern: "/nodes/create" });
    expect(matchRoute(routes, "/activity/150")).toEqual({ ok: true, pattern: "/activity/$id" });
    expect(matchRoute(routes, "/nodes")).toEqual({ ok: true, pattern: "/$section" });
    expect(matchRoute(routes, "/no/such/page")).toEqual({ ok: false, error: "unknown-route" });
    expect(matchRoute([{ path: "/$section/foo" }, { path: "/bar/$id" }], "/bar/foo")).toEqual({
        ok: false,
        error: "ambiguous-route",
    });
});

it("rejects a map pattern and a path that is not one route", () => {
    expect(unresolvedParameter("/tasks/$id")).toBe(true);
    expect(unresolvedParameter("/$section")).toBe(true);
    expect(unresolvedParameter("/tasks/12")).toBe(false);
    expect(concreteRoute("/tasks/12")).toBe(true);
    expect(concreteRoute("/activity?status=failed")).toBe(true);
    expect(concreteRoute("tasks")).toBe(false);
    expect(concreteRoute("//tasks")).toBe(false);
    expect(canonicalRoute("/activity?status=failed")).toBe("/activity?status=failed");
    expect(canonicalRoute("/\t/gateway.orbit")).toBeNull();
    expect(canonicalRoute("/\n/gateway.orbit")).toBeNull();
    expect(canonicalRoute("/\r/gateway.orbit")).toBeNull();

    const unresolved = assessRoute(mapFile, "/tasks/$id");
    expect(unresolved.ok).toBe(false);
    if (!unresolved.ok) {
        expect(unresolved.result.error).toBe("unresolved-parameter");
        expect(unresolved.result.next).toContain("open /tasks/12");
        expect(unresolved.result.route).toBe("/tasks/$id");
    }
    const unknown = assessRoute(mapFile, "/no/such/page");
    expect(unknown.ok).toBe(false);
    if (!unknown.ok) {
        expect(unknown.result.error).toBe("unknown-route");
        expect(unknown.result.next).toContain("bin/web-verify routes");
    }
    const foreign = assessRoute(mapFile, "/\t/gateway.orbit");
    expect(foreign.ok).toBe(false);
    if (!foreign.ok) {
        expect(foreign.result.error).toBe("usage");
        expect(foreign.result.message).toContain("demo server");
    }
});

it("names the first broken feature map field", () => {
    const broken = validateMap([
        {
            path: "/tasks",
            purpose: "Tasks.",
            reach: "Open Tasks.",
            controls: [{ testid: "Nav", purpose: "The nav." }],
        },
    ]);
    expect(broken.ok).toBe(false);
    if (!broken.ok) expect(broken.field).toBe("routes[0].controls[0].testid");

    const extra = validateMap([
        {
            path: "/",
            purpose: "Home.",
            reach: "Open it.",
            note: "nope",
            controls: [{ testid: "nav-menu", purpose: "The menu." }],
        },
    ]);
    expect(extra.ok).toBe(false);
    if (!extra.ok) expect(extra.field).toBe("routes[0].note");
});

it("parses subcommands, defaults, and required screenshot flags", () => {
    expect(parseCommand(["routes"])).toEqual({ kind: "routes" });
    expect(parseCommand(["open", "/activity"])).toEqual({
        kind: "open",
        route: "/activity",
        device: "desktop",
        engine: "chromium",
    });
    expect(
        parseCommand(["screenshot", "--engine=webkit", "/activity", "--device=iphone-15"]),
    ).toEqual({
        kind: "screenshot",
        route: "/activity",
        device: "iphone-15",
        engine: "webkit",
    });
    expect(parseCommand(["click", "[data-testid=nav-menu]"])).toEqual({
        kind: "click",
        selector: "[data-testid=nav-menu]",
    });

    const usage = parseCommand(["screenshot", "/activity"]);
    expect(usage.kind).toBe("fail");
    if (usage.kind === "fail") {
        expect(usage.result.error).toBe("usage");
        expect(usage.result.next).toBe(USAGE_NEXT);
        expect(usage.result.command).toBe("screenshot");
    }

    const flagged = parseCommand(["click", "[data-testid=nav-menu]", "--engine=webkit"]);
    expect(flagged.kind).toBe("fail");
    if (flagged.kind === "fail") expect(flagged.result.error).toBe("usage");

    const missing = parseCommand([]);
    expect(missing.kind).toBe("fail");
    if (missing.kind === "fail") expect(missing.result.error).toBe("usage");
});

it("keeps a test session out of the shared artifact directory", () => {
    const home = mkdtempSync(join(tmpdir(), "orbit-web-verify-paths-"));
    tempDirs.push(home);
    const script = fileURLToPath(new URL("../web-verify.ts", import.meta.url));
    const isolated = pathsFrom(script, { ORBIT_WEB_VERIFY_HOME: home });
    expect(isolated.home).toBe(home);
    expect(isolated.serverLog).toBe(join(home, "server.log"));

    const shared = pathsFrom(script, {});
    const repo = join(script, "../../../..");
    expect(shared.repoRoot).toBe(repo);
    expect(shared.webRoot).toBe(join(repo, "apps/web"));
    expect(shared.home).toBe(join(repo, ".orbit-artifacts/web"));
    writeFileSync(join(home, "keep"), "");
});
