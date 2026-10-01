import { describe, expect, it } from "vite-plus/test";
import type { Definition, Subtask } from "./definition";
import { layout } from "./definition-layout";

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
        status: "todo",
        schedule: null,
        phases: [],
        subtasks,
        ...extra,
    };
}

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
    subtask({ key: "merge", title: "Merge", kind: "merge" }),
    subtask({
        key: "verify",
        title: "Verify",
        kind: "check",
        routes: { passed: "complete", failed: "rollback" },
    }),
    subtask({
        key: "rollback",
        title: "Roll back",
        kind: "action",
        operation: "instance:rollback",
        routes: { passed: "fail" },
    }),
]);

describe("layout", () => {
    it("puts the workspace before the main path and hides default routes to an end", () => {
        const drawn = layout(maintenance);
        const workspace = drawn.nodes.find((node) => node.id === "stage:workspace");
        const update = drawn.nodes.find((node) => node.id === "update");
        expect(workspace).toMatchObject({ type: "stage", rank: 0, column: 0 });
        expect(update).toMatchObject({ type: "subtask", column: 0 });
        expect((update?.rank ?? 0) > (workspace?.rank ?? 0)).toBe(true);
        expect(
            drawn.edges.some((edge) => edge.label === "failed" && edge.target.startsWith("end:")),
        ).toBe(false);
        expect(drawn.edges.some((edge) => edge.label === "skipped")).toBe(false);
    });

    it("keeps the main path in the middle and a failure path in a side column", () => {
        const drawn = layout(maintenance);
        const columnOf = (id: string) => drawn.nodes.find((node) => node.id === id)?.column;
        expect(columnOf("update")).toBe(0);
        expect(columnOf("review")).toBe(0);
        expect(columnOf("browser")).toBe(0);
        expect(columnOf("rollback")).toBeGreaterThan(0);
        const fail = drawn.nodes.find((node) => node.type === "end" && node.end === "fail");
        expect(fail?.column).toBeGreaterThan(0);
        expect(drawn.edges).toContainEqual(
            expect.objectContaining({ source: "rollback", target: expect.stringMatching(/^end:/) }),
        );
        const minor = drawn.edges.find((edge) => edge.label === "minor");
        const size = drawn.nodes.find((node) => node.id === "size");
        const review = drawn.nodes.find((node) => node.id === "review");
        const browser = drawn.nodes.find((node) => node.id === "browser");
        expect(minor).toMatchObject({ source: "size", target: "browser", lane: 0 });
        expect(size?.column).toBe(review?.column);
        expect(browser?.column).toBe(review?.column);
        expect(
            (size?.rank ?? 0) < (review?.rank ?? 0) && (review?.rank ?? 0) < (browser?.rank ?? 0),
        ).toBe(true);
        expect(drawn.edges.find((edge) => edge.label === "major")?.lane).toBeNull();
    });

    it("draws the pull request and cleanup after the last subtask, and a person merges only without a merge subtask", () => {
        const drawn = layout(maintenance);
        expect(drawn.nodes.map((node) => node.id)).toContain("stage:pull-request");
        expect(drawn.nodes.map((node) => node.id)).toContain("stage:cleanup");
        expect(drawn.nodes.map((node) => node.id)).not.toContain("stage:merge");
        const verify = drawn.nodes.find((node) => node.id === "verify");
        const pull = drawn.nodes.find((node) => node.id === "stage:pull-request");
        expect(pull?.column).toBe(0);
        expect((pull?.rank ?? 0) > (verify?.rank ?? 0)).toBe(true);

        const plain = definition([subtask({ key: "docs", title: "Docs", kind: "agent" })]);
        const alone = layout(plain);
        const merge = alone.nodes.find((node) => node.id === "stage:merge");
        expect(merge).toMatchObject({ type: "stage", detail: "A person merges." });
        expect(
            alone.nodes.find((node) => node.type === "end" && node.end === "complete"),
        ).toBeDefined();
    });
});
