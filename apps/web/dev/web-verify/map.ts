import { readFileSync } from "node:fs";
import { pathnameOf } from "./contract";

const TEST_ID = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const ENTRY_FIELDS = ["path", "purpose", "reach", "controls"];
const CONTROL_FIELDS = ["testid", "purpose"];

export type MapFailure = { field: string; problem: string };

export type LoadedMap = { ok: true; routes: unknown[] } | ({ ok: false } & MapFailure);

function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function textProblem(value: unknown): string | null {
    if (typeof value !== "string" || value === "") return "must be a non-empty string";

    return null;
}

/** The first broken field, in file order. The reference schema is the whole check. */
export function validateMap(value: unknown): LoadedMap {
    if (!Array.isArray(value)) {
        return { ok: false, field: "feature-map.json", problem: "must be an array" };
    }

    const seen = new Set<string>();
    for (let index = 0; index < value.length; index++) {
        const entry = value[index];
        const where = `routes[${index}]`;
        if (!isRecord(entry)) return { ok: false, field: where, problem: "must be an object" };

        for (const field of ENTRY_FIELDS) {
            if (!(field in entry)) {
                return { ok: false, field: `${where}.${field}`, problem: "is missing" };
            }
        }
        const extra = Object.keys(entry).find((key) => !ENTRY_FIELDS.includes(key));
        if (extra !== undefined) {
            return { ok: false, field: `${where}.${extra}`, problem: "is not a map field" };
        }

        const pathProblem = textProblem(entry.path);
        if (pathProblem !== null) {
            return { ok: false, field: `${where}.path`, problem: pathProblem };
        }
        const path = entry.path;
        if (typeof path !== "string" || !path.startsWith("/")) {
            return { ok: false, field: `${where}.path`, problem: "must start with /" };
        }
        if (seen.has(path)) {
            return { ok: false, field: `${where}.path`, problem: "duplicates an earlier path" };
        }
        seen.add(path);

        for (const field of ["purpose", "reach"] as const) {
            const problem = textProblem(entry[field]);
            if (problem !== null) return { ok: false, field: `${where}.${field}`, problem };
        }

        if (!Array.isArray(entry.controls)) {
            return { ok: false, field: `${where}.controls`, problem: "must be an array" };
        }
        if (entry.controls.length === 0) {
            return { ok: false, field: `${where}.controls`, problem: "must list a control" };
        }

        for (let controlIndex = 0; controlIndex < entry.controls.length; controlIndex++) {
            const control = entry.controls[controlIndex];
            const at = `${where}.controls[${controlIndex}]`;
            if (!isRecord(control)) return { ok: false, field: at, problem: "must be an object" };
            for (const field of CONTROL_FIELDS) {
                if (!(field in control)) {
                    return { ok: false, field: `${at}.${field}`, problem: "is missing" };
                }
            }
            const extraControl = Object.keys(control).find((key) => !CONTROL_FIELDS.includes(key));
            if (extraControl !== undefined) {
                return { ok: false, field: `${at}.${extraControl}`, problem: "is not a map field" };
            }
            if (typeof control.testid !== "string" || !TEST_ID.test(control.testid)) {
                return {
                    ok: false,
                    field: `${at}.testid`,
                    problem: "must match [a-z0-9]+(-[a-z0-9]+)*",
                };
            }
            const purpose = textProblem(control.purpose);
            if (purpose !== null) return { ok: false, field: `${at}.purpose`, problem: purpose };
        }
    }

    return { ok: true, routes: value };
}

export function loadFeatureMap(file: string): LoadedMap {
    let parsed: unknown;
    try {
        parsed = JSON.parse(readFileSync(file, "utf8")) as unknown;
    } catch (error) {
        const missing = error instanceof Error && "code" in error && error.code === "ENOENT";

        return {
            ok: false,
            field: "feature-map.json",
            problem: missing ? "is missing" : "is not valid JSON",
        };
    }

    return validateMap(parsed);
}

export function mapInvalidMessage(failure: MapFailure): string {
    if (failure.field === "feature-map.json") return `The feature map ${failure.problem}.`;

    return `The feature map field ${failure.field} ${failure.problem}.`;
}

type Pattern = { path: string; score: number; segments: readonly string[] };

function patternSegments(path: string): string[] {
    return path === "/" ? [] : path.split("/").slice(1);
}

function concreteSegments(pathname: string): string[] | null {
    if (pathname === "/") return [];

    try {
        return pathname
            .split("/")
            .slice(1)
            .map((segment) => decodeURIComponent(segment));
    } catch {
        return null;
    }
}

function fits(pattern: Pattern, concrete: readonly string[]): boolean {
    if (pattern.segments.length !== concrete.length) return false;

    return pattern.segments.every((segment, index) => {
        const value = concrete[index];
        if (value === undefined || value === "") return false;
        if (segment.startsWith("$")) return true;

        return segment === value;
    });
}

/**
 * More static segments win, so `/tasks/12` is `/tasks/$id` and not `/$section/$id`. Two patterns
 * with the same number of static segments are still tied.
 */
export function matchRoute(
    routes: readonly unknown[],
    pathname: string,
): { ok: true; pattern: string } | { ok: false; error: "unknown-route" | "ambiguous-route" } {
    const concrete = concreteSegments(pathname);
    if (concrete === null) return { ok: false, error: "unknown-route" };

    const patterns: Pattern[] = [];
    for (const route of routes) {
        if (route === null || typeof route !== "object" || !("path" in route)) continue;
        const path = route.path;
        if (typeof path !== "string") continue;
        const segments = patternSegments(path);
        patterns.push({
            path,
            segments,
            score: segments.filter((segment) => !segment.startsWith("$")).length,
        });
    }

    const hits = patterns.filter((pattern) => fits(pattern, concrete));
    const best = hits.reduce((score, pattern) => Math.max(score, pattern.score), -1);
    const tied = hits.filter((pattern) => pattern.score === best);
    const winner = tied[0];
    if (winner === undefined) return { ok: false, error: "unknown-route" };
    if (tied.length > 1) return { ok: false, error: "ambiguous-route" };

    return { ok: true, pattern: winner.path };
}

/** A `$` segment is a map pattern, not a page to open. */
export function unresolvedParameter(pathname: string): boolean {
    return pathname.split("/").some((segment) => segment.includes("$"));
}

/** A concrete path is one origin-relative URL. Anything else is a usage error. */
export function concreteRoute(route: string): boolean {
    if (!route.startsWith("/") || route.startsWith("//") || route.includes("\\")) return false;

    const pathname = pathnameOf(route);

    return pathname.startsWith("/") && !pathname.includes("//");
}

const ROUTE_BASE = "http://127.0.0.1";

/**
 * The route after WHATWG canonicalization, or null when the browser would leave the demo origin.
 * A tab, newline, or carriage return is removed by the URL parser, so `/\t/gateway.orbit` becomes
 * `http://gateway.orbit/`.
 */
export function canonicalRoute(route: string): string | null {
    if (!concreteRoute(route)) return null;

    let url: URL;
    try {
        url = new URL(route, ROUTE_BASE);
    } catch {
        return null;
    }
    if (url.origin !== ROUTE_BASE || url.username !== "" || url.password !== "") return null;
    const canonical = `${url.pathname}${url.search}${url.hash}`;
    if (canonical !== route) return null;

    return canonical;
}

/** The absolute demo URL for a route, or null when it would not stay on `origin`. */
export function targetUrl(origin: string, route: string): URL | null {
    if (canonicalRoute(route) === null) return null;

    let url: URL;
    let allowed: URL;
    try {
        allowed = new URL(origin);
        url = new URL(route, allowed);
    } catch {
        return null;
    }
    if (url.origin !== allowed.origin) return null;
    if (`${url.pathname}${url.search}${url.hash}` !== route) return null;

    return url;
}
