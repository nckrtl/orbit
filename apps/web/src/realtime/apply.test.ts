import { QueryClient } from "@tanstack/react-query";
import { beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { applyEvent } from "./apply";

let client: QueryClient;
const event = (type: string, data: Record<string, unknown>) => ({
    type,
    id: 1,
    at: "2026-01-01T00:00:00+00:00",
    data,
});

beforeEach(() => {
    client = new QueryClient();
    client.setQueryData(
        ["processes"],
        [
            { id: 1, name: "horizon", runtime_status: "active" },
            { id: 2, name: "vite", runtime_status: "inactive" },
        ],
    );
});

describe("applyEvent", () => {
    it("normalizes a new legacy instance and prefers project when both aliases are present", () => {
        const app = { id: 1, name: "Legacy", slug: "legacy" };
        const project = { id: 2, name: "Modern", slug: "modern" };
        client.setQueryData(["instances"], []);

        applyEvent(client, event("instance.created", { id: 1, app, name: "dev" }));
        applyEvent(client, event("instance.created", { id: 2, app, project, name: "production" }));

        expect(client.getQueryData(["instances"])).toEqual([
            { id: 1, project: app, name: "dev" },
            { id: 2, project, name: "production" },
        ]);
        applyEvent(client, event("instance.deleted", { id: 1 }));
        expect(client.getQueryData(["instances"])).toEqual([
            { id: 2, project, name: "production" },
        ]);
    });

    it("preserves canonical identity during partial updates and accepts a later legacy identity change", () => {
        const project = { id: 1, name: "Original", slug: "original" };
        const updated = { id: 2, name: "Updated", slug: "updated" };
        client.setQueryData(["instances"], [{ id: 1, project, name: "dev" }]);

        applyEvent(client, event("instance.status", { id: 1, status: "active" }));
        applyEvent(
            client,
            event("instance.updated", { id: 1, project: undefined, app: undefined }),
        );

        expect(client.getQueryData(["instances"])).toEqual([
            { id: 1, project, name: "dev", status: "active" },
        ]);
        applyEvent(client, event("instance.updated", { id: 1, app: updated }));
        expect(client.getQueryData(["instances"])).toEqual([
            { id: 1, project: updated, name: "dev", status: "active" },
        ]);
        applyEvent(client, event("instance.updated", { id: 1, app: updated, project }));
        expect(client.getQueryData(["instances"])).toEqual([
            { id: 1, project, name: "dev", status: "active" },
        ]);
    });

    it("reloads an unknown partial instance instead of inserting a row without project identity", () => {
        client.setQueryData(["instances"], []);
        const invalidate = vi.spyOn(client, "invalidateQueries");

        applyEvent(client, event("instance.status", { id: 1, status: "active" }));

        expect(client.getQueryData(["instances"])).toEqual([]);
        expect(invalidate).toHaveBeenCalledWith({ queryKey: ["instances"] });
        expect(client.getQueryState(["instances"])?.isInvalidated).toBe(true);
    });

    it("merges a status event into the row it names", () => {
        applyEvent(client, event("process.status", { id: 2, runtime_status: "active" }));

        expect(client.getQueryData(["processes"])).toEqual([
            { id: 1, name: "horizon", runtime_status: "active" },
            { id: 2, name: "vite", runtime_status: "active" },
        ]);
    });

    it("appends a record it has not seen and removes a deleted one", () => {
        applyEvent(client, event("process.created", { id: 3, name: "queue" }));
        applyEvent(client, event("process.deleted", { id: 1 }));

        expect(
            (client.getQueryData(["processes"]) as { id: number }[]).map((row) => row.id),
        ).toEqual([2, 3]);
    });

    it("leaves a list alone until it has loaded", () => {
        applyEvent(client, event("node.updated", { id: 1, status: "failed" }));

        expect(client.getQueryData(["nodes"])).toBeUndefined();
    });

    it("ignores an event without an id and a family no pane draws", () => {
        const before = client.getQueryData(["processes"]);
        applyEvent(client, event("process.status", { runtime_status: "failed" }));
        applyEvent(client, event("route.updated", { id: 1 }));

        expect(client.getQueryData(["processes"])).toBe(before);
    });

    it("reloads instances for a deploy step and deployment history for a deployment", () => {
        const invalidate = vi.spyOn(client, "invalidateQueries");
        applyEvent(client, event("deploy_step.created", { id: 1 }));
        applyEvent(client, event("deployment.updated", { id: 1 }));

        expect(invalidate.mock.calls.map(([filters]) => filters?.queryKey)).toEqual([
            ["instances"],
            ["deployments"],
        ]);
    });
});
