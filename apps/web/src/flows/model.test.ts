import { describe, expect, it } from "vite-plus/test";
import { runs, templates } from "../demo/flows";
import { edges, findings, takenEdges, type TaskTemplate } from "./model";

const maintenance = templates.find((template) => template.name === "maintenance")!;

describe("edges", () => {
    it("fills in the default routes", () => {
        const update = edges(maintenance).filter((edge) => edge.from === "update");
        expect(update).toEqual([
            { from: "update", to: "size", on: "passed", implicit: true },
            { from: "update", to: "complete", on: "skipped", implicit: true },
            { from: "update", to: "fail", on: "failed", implicit: true },
        ]);
    });

    it("gives a decide task only its named routes", () => {
        const size = edges(maintenance).filter((edge) => edge.from === "size");
        expect(size.map((edge) => [edge.on, edge.to])).toEqual([
            ["major", "review"],
            ["minor", "browser"],
        ]);
    });

    it("gives an action task no skipped route", () => {
        const deploy = edges(maintenance).filter((edge) => edge.from === "deploy");
        expect(deploy.map((edge) => edge.on)).toEqual(["passed", "failed"]);
    });
});

describe("takenEdges", () => {
    it("follows each ended task's outcome", () => {
        const run = runs.find((candidate) => candidate.id === 412)!;
        expect([...takenEdges(maintenance, run)]).toEqual(["update:passed", "size:minor"]);
    });
});

describe("findings", () => {
    it("reports nothing for the maintenance template", () => {
        expect(findings(maintenance)).toEqual([]);
    });

    it("reports a task that no path reaches", () => {
        const template: TaskTemplate = {
            ...maintenance,
            tasks: [
                { key: "a", title: "A", kind: "check", routes: { passed: "c" } },
                { key: "b", title: "B", kind: "check" },
                { key: "c", title: "C", kind: "check" },
            ],
        };
        expect(findings(template)).toEqual([{ key: "b", message: "No path reaches this task." }]);
    });

    it("reports a decide task whose options lead to one task", () => {
        const template: TaskTemplate = {
            ...maintenance,
            tasks: [
                {
                    key: "a",
                    title: "A",
                    kind: "decide",
                    options: ["x", "y"],
                    routes: { x: "b", y: "b" },
                },
                { key: "b", title: "B", kind: "check" },
            ],
        };
        expect(findings(template)).toEqual([
            { key: "a", message: "Every option leads to the same task." },
        ]);
    });
});
