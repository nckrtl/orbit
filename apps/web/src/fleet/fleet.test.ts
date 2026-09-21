import { beforeAll, describe, expect, it } from "vite-plus/test";
import type { Fleet } from "../api/queries";
import type { Process } from "../api/types";
import { demoFleet } from "../demo/fleet";
import {
    attentionRows,
    counts,
    listRows,
    processCpu,
    processHealthy,
    processMemory,
    processOwner,
    processRuntimeIsActive,
    recordTitle,
    scheduleHealthy,
} from "./fleet";

let fleet: Fleet;

beforeAll(async () => {
    fleet = await demoFleet();
});

const process = (overrides: Partial<Process>): Process => ({
    ...(fleet.processes[0] as Process),
    ...overrides,
});

describe("process health", () => {
    it("reads a systemd process in systemd's vocabulary", () => {
        expect(
            processHealthy(
                process({ runtime: "systemd", desired_state: "running", runtime_status: "active" }),
            ),
        ).toBe(true);
        expect(
            processHealthy(
                process({
                    runtime: "systemd",
                    desired_state: "running",
                    runtime_status: "running",
                }),
            ),
        ).toBe(false);
        expect(
            processHealthy(
                process({
                    runtime: "systemd",
                    desired_state: "stopped",
                    runtime_status: "inactive",
                }),
            ),
        ).toBe(true);
    });

    it("reads a Docker process in Docker's vocabulary", () => {
        expect(
            processHealthy(
                process({ runtime: "docker", desired_state: "running", runtime_status: "running" }),
            ),
        ).toBe(true);
        expect(
            processHealthy(
                process({ runtime: "docker", desired_state: "running", runtime_status: "active" }),
            ),
        ).toBe(false);
        expect(
            processHealthy(
                process({ runtime: "docker", desired_state: "stopped", runtime_status: "exited" }),
            ),
        ).toBe(true);
        expect(
            processRuntimeIsActive(process({ runtime: "docker", runtime_status: "running" })),
        ).toBe(true);
    });

    it("treats an unknown desired state as needing a look", () => {
        expect(processHealthy(process({ desired_state: "paused", runtime_status: "active" }))).toBe(
            false,
        );
    });
});

describe("schedule health", () => {
    it("needs an enabled timer and a provisioning status that did not fail", () => {
        const [backup, prune] = fleet.schedules;
        expect(scheduleHealthy(backup!)).toBe(true);
        expect(scheduleHealthy(prune!)).toBe(false);
        expect(scheduleHealthy({ ...backup!, status: "failed" })).toBe(false);
    });
});

describe("the fixture fleet", () => {
    it("counts each section and what needs a look in it", () => {
        expect(counts(fleet)).toEqual({
            nodes: [3, 1],
            projects: [3, 0],
            instances: [4, 1],
            processes: [4, 1],
            schedules: [2, 1],
            databases: [2, 0],
            firewall: [3, 1],
        });
    });

    it("gathers everything that needs attention, family by family", () => {
        expect(
            attentionRows(fleet).map((row) => [row.label, row.name, row.where, row.state]),
        ).toEqual([
            ["Node", "app-prod", "—", "failed"],
            ["Instance", "acme/production", "app-prod", "reserved"],
            ["Process", "vite", "charlie-shop/dev", "inactive, wanted running"],
            ["Schedule", "prune", "charlie-shop/staging", "disabled"],
            ["Firewall", "22/tcp allow 10.44.0.0/16", "app-prod", "failed"],
        ]);
    });

    it("narrows a list by node and by project", () => {
        const names = (node?: string, project?: string) =>
            listRows(fleet, "processes", node, project).map((row) => (row as Process).name);

        expect(names()).toEqual(["horizon", "vite", "queue", "valkey"]);
        expect(names("beast", "charlie-shop")).toEqual(["horizon", "vite", "queue"]);
        // A Node process belongs to no Project, so a project filter leaves it out.
        expect(names(undefined, "acme")).toEqual([]);
        expect(names("app-prod")).toEqual([]);
    });

    it("names a process owner and formats its CPU and memory", () => {
        const [horizon, vite, , valkey] = fleet.processes;
        expect(processOwner(fleet, horizon!)).toBe("charlie-shop/dev");
        expect(processOwner(fleet, valkey!)).toBe("node beast");
        expect(processCpu(horizon!)).toBe("20%");
        expect(processMemory(horizon!)).toBe("1.2G");
        expect(processCpu(vite!)).toBe("—");
        expect(processMemory(vite!)).toBe("—");
    });

    it("titles a record the way its page does", () => {
        expect(recordTitle("instances", fleet.instances[0]!)).toBe("charlie-shop/dev");
        expect(recordTitle("firewall", fleet.firewall[0]!)).toBe("22/tcp allow 10.44.0.0/16");
    });
});
