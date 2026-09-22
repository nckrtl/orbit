import { afterEach, describe, expect, it } from "vite-plus/test";
import { QueryClient } from "@tanstack/react-query";
import recorded from "../../../../packages/php-sdk/fixtures/instances/instance-deployment-show/default.json";
import instanceFixture from "../../fixtures/fleet/instance-list.json";
import type { DeploymentEvent, InstanceWire } from "./types";
import { deploymentLogLines, lists, logLines } from "./queries";
import { setTransport } from "./client";

afterEach(() => setTransport(null));

describe("instance query normalization", () => {
    it("canonicalizes legacy, modern, and conflicting project identities without changing the wire rows", async () => {
        const legacy = { ...instanceFixture.body.data[0] } as InstanceWire;
        delete legacy.project;
        const project = { id: 100, name: "Modern project", slug: "modern" };
        const rows = [
            legacy,
            { ...legacy, id: 2, app: undefined, project },
            { ...legacy, id: 3, project },
        ];
        setTransport(async (_method, path) => {
            expect(path).toBe("/api/v1/instances");
            return { status: 200, payload: { data: rows } };
        });
        const client = new QueryClient();

        const instances = await client.fetchQuery(lists.instances);

        expect(instances.map((instance) => instance.project)).toEqual([
            legacy.app,
            project,
            project,
        ]);
        expect(instances.every((instance) => !("app" in instance))).toBe(true);
        expect(instances[0]).toMatchObject({
            name: legacy.name,
            node: legacy.node,
            deploy_steps: legacy.deploy_steps,
        });
        expect(legacy).toHaveProperty("app");
        expect(legacy).not.toHaveProperty("project");
        expect(client.getQueryData(["instances"])).toEqual(instances);
    });
});

describe("logLines", () => {
    it("splits the string the Gateway sends and drops the trailing newlines", () => {
        expect(logLines("one\ntwo\n\n")).toEqual(["one", "two"]);
        expect(logLines("")).toEqual([]);
        expect(logLines(undefined)).toEqual([]);
    });
});

describe("deploymentLogLines", () => {
    it("prints a recorded deployment as instance:deployment:show does", () => {
        const lines = deploymentLogLines(recorded.body.data.events as DeploymentEvent[]);

        expect(lines[0]).toBe("== source preparation ==");
        expect(lines.some((line) => /^(stdout|stderr): /.test(line))).toBe(true);
    });

    it("marks truncated output", () => {
        expect(deploymentLogLines([{ type: "output_truncated" }])).toEqual(["[output truncated]"]);
    });
});
