import { fileURLToPath } from "node:url";
import { exitCode, pathsFrom, type ToolResult } from "./web-verify/contract";
import { daemonAlive, dispatch, runDaemon } from "./web-verify/daemon";
import { loadFeatureMap, mapInvalidMessage } from "./web-verify/map";
import { parseCommand } from "./web-verify/parse";
import { assessRoute } from "./web-verify/session";

const paths = pathsFrom(fileURLToPath(import.meta.url));

if (process.env.ORBIT_WEB_VERIFY_DAEMON === "1") {
    await runDaemon(paths);
} else {
    const result = await execute(process.argv.slice(2));
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`, () => {
        process.exit(exitCode(result));
    });
}

/** Prints one JSON result. Browser commands go to the shared daemon; the map commands stay here. */
async function execute(argv: readonly string[]): Promise<ToolResult> {
    const parsed = parseCommand(argv);
    if (parsed.kind === "fail") return parsed.result;
    if (parsed.kind === "routes") return routesResult();
    if (parsed.kind === "click") {
        if (!daemonAlive(paths)) {
            return {
                ok: false,
                command: "click",
                error: "no-page",
                message: "There is no active page to click.",
                next: "Run bin/web-verify open <route> first.",
            };
        }

        return dispatch(paths, { command: "click", selector: parsed.selector });
    }

    const assessed = assessRoute(paths.mapFile, parsed.route);
    if (!assessed.ok) return { ...assessed.result, command: parsed.kind };
    if (parsed.kind === "console-errors") {
        return dispatch(paths, {
            command: "console-errors",
            route: parsed.route,
            device: "desktop",
            engine: "chromium",
        });
    }

    return dispatch(paths, {
        command: parsed.kind,
        route: parsed.route,
        device: parsed.device,
        engine: parsed.engine,
    });
}

function routesResult(): ToolResult {
    const loaded = loadFeatureMap(paths.mapFile);
    if (!loaded.ok) {
        return {
            ok: false,
            command: "routes",
            error: "map-invalid",
            message: mapInvalidMessage(loaded),
            next: `Fix ${loaded.field} in apps/web/feature-map.json.`,
        };
    }

    return { ok: true, command: "routes", routes: loaded.routes };
}
