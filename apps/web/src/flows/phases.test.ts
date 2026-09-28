import { describe, expect, it } from "vite-plus/test";
import { templates } from "../demo/flows";
import { flowView, phaseKey } from "./phases";

const today = templates.find((template) => template.name === "today")!;

describe("flowView", () => {
    it("draws each collapsed phase as one card", () => {
        const view = flowView(today, new Set());
        expect(view.template.tasks.map((task) => task.key)).toEqual([
            phaseKey("prepare"),
            phaseKey("start"),
            phaseKey("task"),
            phaseKey("settle"),
            phaseKey("review"),
            "cleanup",
        ]);
        expect(view.frames).toEqual([]);
    });

    it("keeps only the routes that cross a collapsed phase", () => {
        const view = flowView(today, new Set());
        expect(view.edges.map((edge) => `${edge.from} ${edge.on} ${edge.to}`)).toEqual([
            "phase:prepare passed phase:start",
            "phase:start passed phase:task",
            "phase:start failed fail",
            "phase:task last Task phase:settle",
            "phase:settle healthy phase:review",
            "phase:settle appended phase:task",
            "phase:review findings phase:task",
            "phase:review merged cleanup",
            "phase:review closed without merge fail",
            "cleanup passed complete",
            "cleanup failed fail",
        ]);
    });

    it("orders a card's outcomes with its forward route first", () => {
        const settle = flowView(today, new Set()).template.tasks.find(
            (task) => task.key === phaseKey("settle"),
        )!;
        expect(settle.options).toEqual(["healthy", "appended"]);
    });

    it("draws the steps of an open phase and frames them", () => {
        const view = flowView(today, new Set(["task"]));
        expect(view.template.tasks.map((task) => task.key)).toContain("implement");
        expect(view.frames).toEqual([
            {
                phase: today.phases!.find((phase) => phase.key === "task")!,
                keys: ["implement", "handoff", "review", "coverage", "publish", "next"],
            },
        ]);
        expect(view.edges).toContainEqual({
            from: phaseKey("settle"),
            to: "implement",
            on: "appended",
            implicit: false,
        });
    });
});
