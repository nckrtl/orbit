import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createMemoryHistory } from "@tanstack/react-router";
import { expect, it } from "vite-plus/test";
import { createAppRouter } from "./router";

// ADR 0162: the map's paths are the router's paths, except the root layout route.
const TEST_ID = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const root = dirname(fileURLToPath(import.meta.url));

function loadMap(): unknown {
    return JSON.parse(readFileSync(join(root, "../feature-map.json"), "utf8"));
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function mapTestIds(value: unknown): string[] {
    if (!Array.isArray(value)) return [];

    return value.flatMap((entry) => {
        if (!isRecord(entry) || !Array.isArray(entry.controls)) return [];

        return entry.controls.flatMap((control) =>
            isRecord(control) && typeof control.testid === "string" ? [control.testid] : [],
        );
    });
}

function sourceText(): string {
    const files = (dir: string): string[] =>
        readdirSync(dir).flatMap((name) => {
            const path = join(dir, name);
            if (statSync(path).isDirectory()) return files(path);

            return /\.(ts|tsx)$/.test(name) && !name.endsWith(".test.ts") ? [path] : [];
        });

    return files(root)
        .map((path) => readFileSync(path, "utf8"))
        .join("\n");
}

it("follows the feature map shape", () => {
    const value = loadMap();
    expect(Array.isArray(value), "feature map must be a route array").toBe(true);
    if (!Array.isArray(value)) return;

    const paths: string[] = [];
    value.forEach((entry, index) => {
        const where = `routes[${index}]`;
        expect(isRecord(entry), where).toBe(true);
        if (!isRecord(entry)) return;

        for (const field of ["path", "purpose", "reach"] as const) {
            expect(typeof entry[field], `${where}.${field}`).toBe("string");
            expect(entry[field], `${where}.${field}`).not.toBe("");
        }
        expect(Array.isArray(entry.controls), `${where}.controls`).toBe(true);
        if (!Array.isArray(entry.controls)) return;
        expect(entry.controls.length, `${where}.controls`).toBeGreaterThan(0);

        const testids: string[] = [];
        entry.controls.forEach((control, controlIndex) => {
            const at = `${where}.controls[${controlIndex}]`;
            expect(isRecord(control), at).toBe(true);
            if (!isRecord(control)) return;
            expect(control.testid, `${at}.testid`).toEqual(expect.stringMatching(TEST_ID));
            expect(typeof control.purpose, `${at}.purpose`).toBe("string");
            expect(control.purpose, `${at}.purpose`).not.toBe("");
            expect(Object.keys(control).sort(), at).toEqual(["purpose", "testid"]);
            if (typeof control.testid === "string") testids.push(control.testid);
        });
        expect(new Set(testids).size, `${where} repeats a testid`).toBe(testids.length);
        expect(Object.keys(entry).sort(), where).toEqual(["controls", "path", "purpose", "reach"]);
        if (typeof entry.path === "string") paths.push(entry.path);
    });
    expect(new Set(paths).size, "feature map repeats a path").toBe(paths.length);
});

it("puts every mapped testid on a control", () => {
    const missing = [...new Set(mapTestIds(loadMap()))].filter(
        (testid) => !sourceText().includes(`"${testid}"`),
    );
    expect(missing, "data-testid values missing from apps/web/src").toEqual([]);
});

it("lists every router path and no other path", () => {
    const value = loadMap();
    const mapPaths = (Array.isArray(value) ? value : [])
        .map((entry) => (isRecord(entry) && typeof entry.path === "string" ? entry.path : ""))
        .filter((path) => path !== "")
        .sort();
    const router = createAppRouter(createMemoryHistory({ initialEntries: ["/"] }));
    const routes = Object.values(router.routesById);
    expect(routes.map((route) => route.id)).toContain("__root__");
    const routerPaths = routes
        .filter((route) => route.id !== "__root__")
        .map((route) => route.fullPath)
        .sort((left, right) => (left < right ? -1 : left > right ? 1 : 0));
    const missing = routerPaths.filter((path) => !mapPaths.includes(path));
    const extra = mapPaths.filter((path) => !routerPaths.includes(path));
    expect(
        mapPaths,
        `router paths missing from the map: ${missing.join(", ") || "none"}; map paths the router lacks: ${extra.join(", ") || "none"}`,
    ).toEqual(routerPaths);
});
