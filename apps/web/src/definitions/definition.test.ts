import { describe, expect, it } from "vite-plus/test";
import {
    describeCron,
    driverCanRun,
    edges,
    findings,
    parseDefinition,
    type Definition,
    type Subtask,
} from "./definition";

function subtask(partial: Pick<Subtask, "key" | "title" | "kind"> & Partial<Subtask>): Subtask {
    return partial;
}

function definition(subtasks: Subtask[], extra: Partial<Definition> = {}): Definition {
    return {
        project_id: 1,
        name: "example",
        title: "Example",
        brief: "Example.",
        parameters: [],
        status: "backlog",
        schedule: null,
        phases: [],
        subtasks,
        ...extra,
    };
}

describe("edges", () => {
    const maintenance = definition([
        subtask({ key: "update", title: "Update", kind: "agent" }),
        subtask({
            key: "size",
            title: "Size",
            kind: "decide",
            options: ["major", "minor"],
            routes: { major: "review", minor: "browser" },
        }),
        subtask({ key: "review", title: "Review", kind: "agent" }),
        subtask({ key: "browser", title: "Browser", kind: "check" }),
        subtask({
            key: "deploy",
            title: "Deploy",
            kind: "action",
            operation: "instance:deploy",
        }),
    ]);

    it("fills in the default routes", () => {
        expect(edges(maintenance).filter((edge) => edge.from === "update")).toEqual([
            { from: "update", to: "size", on: "passed", implicit: true },
            { from: "update", to: "complete", on: "skipped", implicit: true },
            { from: "update", to: "fail", on: "failed", implicit: true },
        ]);
    });

    it("gives a decide subtask only its named routes", () => {
        expect(
            edges(maintenance)
                .filter((edge) => edge.from === "size")
                .map((edge) => [edge.on, edge.to]),
        ).toEqual([
            ["major", "review"],
            ["minor", "browser"],
        ]);
    });

    it("gives an action subtask no skipped route", () => {
        expect(
            edges(maintenance)
                .filter((edge) => edge.from === "deploy")
                .map((edge) => edge.on),
        ).toEqual(["passed", "failed"]);
    });
});

describe("findings", () => {
    it("reports a subtask that no path reaches", () => {
        const example = definition([
            subtask({ key: "a", title: "A", kind: "check", routes: { passed: "c" } }),
            subtask({ key: "b", title: "B", kind: "check" }),
            subtask({ key: "c", title: "C", kind: "check" }),
        ]);
        expect(findings(example)).toEqual([{ key: "b", message: "No path reaches this subtask." }]);
    });

    it("reports a decide subtask whose options lead to one subtask", () => {
        const example = definition([
            subtask({
                key: "a",
                title: "A",
                kind: "decide",
                options: ["x", "y"],
                routes: { x: "b", y: "b" },
            }),
            subtask({ key: "b", title: "B", kind: "check" }),
        ]);
        expect(findings(example)).toEqual([
            { key: "a", message: "Every option leads to the same subtask." },
        ]);
    });

    it("reports Claude and other models Pi cannot run, and accepts a listed Pi model", () => {
        const example = definition([
            subtask({
                key: "docs",
                title: "Docs",
                kind: "agent",
                implementer_model: "gpt-missing",
                reviewer_model: "claude-opus-5",
            }),
            subtask({
                key: "search",
                title: "Search",
                kind: "agent",
                implementer_model: "gemini-ultra",
                reviewer_model: "gpt-5.6-luna",
            }),
        ]);
        const models = [
            { id: "gpt-5.6-luna", provider: "codex" },
            { id: "gemini-ultra", provider: "google" },
        ];
        expect(findings(example, models)).toEqual([
            { key: "docs", message: "No driver can run gpt-missing." },
            { key: "docs", message: "No driver can run claude-opus-5." },
            { key: "search", message: "No driver can run gemini-ultra." },
        ]);
        expect(driverCanRun("claude-opus-5", [])).toBe(false);
        expect(findings(example)).toEqual([]);
    });
});

describe("Pi-only task models", () => {
    it.each([
        ["claude-opus-5", "codex"],
        ["Claude-opus-5", "codex"],
        ["claude", "codex"],
        ["claudeNext", "codex"],
        ["proxy/claude-opus-5", "codex"],
        ["anthropic/custom", "codex"],
        ["custom", "anthropic"],
        ["custom", "claude"],
        ["gemini-ultra", "google"],
        ["custom", "meta"],
    ])("refuses %s even when listed through %s", (id, provider) => {
        expect(driverCanRun(id, [{ id, provider }])).toBe(false);
    });

    it("requires a ProxyCli offer through a provider Pi runs", () => {
        expect(driverCanRun("gpt-5.6-luna", [{ id: "gpt-5.6-luna", provider: "codex" }])).toBe(
            true,
        );
        expect(driverCanRun("grok-4", [{ id: "grok-4", provider: "xai" }])).toBe(true);
        expect(driverCanRun("gpt-5.6-luna", [])).toBe(false);
    });
});

describe("describeCron", () => {
    it.each([
        [null, "On demand"],
        ["*/15 * * * *", "Every 15 minutes"],
        ["0 * * * *", "Hourly"],
        ["30 * * * *", "Hourly at :30"],
        ["0 3 * * *", "Daily at 03:00 UTC"],
        ["0 9 * * 1-5", "Weekdays at 09:00 UTC"],
        ["0 3 * * 1", "Weekly on Monday at 03:00 UTC"],
        ["0 3 * * 0", "Weekly on Sunday at 03:00 UTC"],
        ["15 4 1 * *", "Monthly on the 1st at 04:15 UTC"],
        ["0 0 1 1 *", "0 0 1 1 *"],
        ["0 3 * * 1,3", "0 3 * * 1,3"],
    ])("describes %s", (expression, words) => {
        expect(describeCron(expression)).toBe(words);
    });
});

describe("parseDefinition", () => {
    it("reads the stored shape and drops a row without subtasks", () => {
        expect(
            parseDefinition({
                project_id: 3,
                name: "maintenance",
                title: "Weekly maintenance",
                brief: "Update the App.",
                status: "todo",
                schedule: { cron: "0 3 * * 1", values: { app: "charlie-shop" } },
                phases: [],
                subtasks: [{ key: "update", title: "Update", kind: "agent" }],
            }),
        ).toMatchObject({ name: "maintenance", schedule: { cron: "0 3 * * 1" } });
        expect(
            parseDefinition({
                project_id: 3,
                name: "empty",
                title: "Empty",
                brief: "Brief",
                status: "todo",
                subtasks: [],
            }),
        ).toBeNull();
    });
});
