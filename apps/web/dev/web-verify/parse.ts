import { isDevice, isEngine, usage, type Device, type Engine, type ToolResult } from "./contract";

const COMMANDS = ["routes", "open", "click", "screenshot", "console-errors"] as const;

type CommandName = (typeof COMMANDS)[number];

export type Parsed =
    | { kind: "routes" }
    | { kind: "open"; route: string; device: Device; engine: Engine }
    | { kind: "screenshot"; route: string; device: Device; engine: Engine }
    | { kind: "click"; selector: string }
    | { kind: "console-errors"; route: string }
    | { kind: "fail"; result: ToolResult };

function isCommand(value: string): value is CommandName {
    return (COMMANDS as readonly string[]).includes(value);
}

function fail(command: string, message: string): Parsed {
    return { kind: "fail", result: usage(command, message) };
}

/**
 * Flags are `--name=value` and may sit before or after the route. `screenshot` requires both
 * flags. `open` defaults to desktop Chromium. `click` and `console-errors` take neither.
 */
export function parseCommand(argv: readonly string[]): Parsed {
    const flags = new Map<string, string>();
    const positionals: string[] = [];

    for (const arg of argv) {
        if (!arg.startsWith("--")) {
            positionals.push(arg);
            continue;
        }
        const eq = arg.indexOf("=");
        if (eq === -1) {
            return fail(
                positionals[0] ?? "",
                `Write flags as --device=iphone-15 and --engine=webkit, not ${arg}.`,
            );
        }
        const name = arg.slice(2, eq);
        const value = arg.slice(eq + 1);
        if (name !== "device" && name !== "engine") {
            return fail(positionals[0] ?? "", `Unknown flag --${name}.`);
        }
        if (flags.has(name)) return fail(positionals[0] ?? "", `Repeated flag --${name}.`);
        flags.set(name, value);
    }

    const [name, ...rest] = positionals;
    if (name === undefined) {
        return fail("", "Choose routes, open, click, screenshot, or console-errors.");
    }
    if (!isCommand(name)) {
        return fail(name, `Unknown command ${name}.`);
    }

    const deviceFlag = flags.get("device");
    const engineFlag = flags.get("engine");
    if (deviceFlag !== undefined && !isDevice(deviceFlag)) {
        return fail(name, `Unknown device ${deviceFlag}.`);
    }
    if (engineFlag !== undefined && !isEngine(engineFlag)) {
        return fail(name, `Unknown engine ${engineFlag}.`);
    }

    if (name === "routes") {
        if (rest.length > 0 || flags.size > 0) {
            return fail(name, "The routes command takes no route and no flags.");
        }

        return { kind: "routes" };
    }

    if (name === "click") {
        const selector = rest[0];
        if (flags.size > 0) return fail(name, "The click command takes no flags.");
        if (rest.length !== 1 || selector === undefined || selector === "") {
            return fail(name, "The click command needs one selector.");
        }

        return { kind: "click", selector };
    }

    const route = rest[0];
    if (rest.length !== 1 || route === undefined || route === "") {
        return fail(name, `The ${name} command needs one route.`);
    }

    if (name === "console-errors") {
        if (flags.size > 0) return fail(name, "The console-errors command takes no flags.");

        return { kind: "console-errors", route };
    }

    if (name === "screenshot" && (deviceFlag === undefined || engineFlag === undefined)) {
        return fail(name, "The screenshot command needs a route, --device, and --engine.");
    }

    return {
        kind: name,
        route,
        device: deviceFlag ?? "desktop",
        engine: engineFlag ?? "chromium",
    };
}
