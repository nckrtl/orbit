import { QueryClient } from "@tanstack/react-query";
import { describe, expect, it } from "vite-plus/test";
import recorded from "../../../../packages/php-sdk/fixtures/instances/instance-deployment-show/default.json";
import { createDemoGateway } from "../demo/gateway";
import { setTransport } from "./client";
import type { DeploymentEvent } from "./types";
import { deploymentLogLines, lists, logLines } from "./queries";

describe("the fleet firewall list", () => {
    it("reads every Node's rules in one request", async () => {
        const gateway = createDemoGateway();
        setTransport(gateway.transport, "demo fleet");

        const rules = await new QueryClient().fetchQuery(lists.firewall);

        expect(gateway.requests.map((request) => request.path)).toEqual(["/api/v1/firewall-rules"]);
        expect(new Set(rules.map((rule) => rule.node_id)).size).toBeGreaterThan(1);
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
