import { describe, expect, it } from "vite-plus/test";
import type { Definition, Subtask } from "./definition";
import { definitionDrawing, phaseKey } from "./definition-phases";

const publish: Definition = {
    project_id: 1,
    name: "publish",
    title: "Publish a page",
    brief: "Draft and publish.",
    parameters: [],
    status: "todo",
    schedule: null,
    phases: [
        { key: "draft", title: "Draft", brief: "Write the page.", repeat: false },
        { key: "release", title: "Release", brief: "Publish it.", repeat: true },
    ],
    subtasks: [
        { key: "write", title: "Write", kind: "agent", phase: "draft" },
        { key: "edit", title: "Edit", kind: "agent", phase: "draft" },
        {
            key: "links",
            title: "Links",
            kind: "check",
            phase: "release",
            routes: { failed: "rollback" },
        },
        {
            key: "ship",
            title: "Publish",
            kind: "action",
            phase: "release",
            operation: "instance:deploy",
            routes: { passed: "complete" },
        },
        {
            key: "rollback",
            title: "Roll back",
            kind: "action",
            operation: "instance:rollback",
            routes: { passed: "fail" },
        },
    ],
};

const keys = (definition: Definition) => definition.subtasks.map((subtask: Subtask) => subtask.key);

describe("definitionDrawing", () => {
    it("draws each collapsed phase as one card", () => {
        const view = definitionDrawing(publish, new Set());
        expect(keys(view.definition)).toEqual([phaseKey("draft"), phaseKey("release"), "rollback"]);
        expect(view.frames).toEqual([]);
    });

    it("keeps only the routes that cross a collapsed phase", () => {
        const view = definitionDrawing(publish, new Set());
        expect(view.edges.map((edge) => `${edge.from} ${edge.on} ${edge.to}`)).toEqual([
            "phase:draft passed phase:release",
            "phase:release failed rollback",
            "phase:release passed complete",
            "rollback passed fail",
            "rollback failed fail",
        ]);
    });

    it("draws the subtasks of an open phase and frames them", () => {
        const view = definitionDrawing(publish, new Set(["draft"]));
        expect(keys(view.definition)).toEqual(["write", "edit", phaseKey("release"), "rollback"]);
        expect(view.frames).toEqual([
            {
                phase: publish.phases[0],
                keys: ["write", "edit"],
            },
        ]);
        expect(view.edges).toContainEqual({
            from: "edit",
            to: phaseKey("release"),
            on: "passed",
            implicit: true,
        });
    });
});
