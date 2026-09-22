import { beforeAll, describe, expect, it } from "vite-plus/test";
import type { Fleet } from "../api/queries";
import type { Process, Schedule } from "../api/types";
import { demoFleet } from "../demo/fleet";
import {
    attentionRows,
    counts,
    listRows,
    processCpu,
    processHealthy,
    processMemory,
    runtimeOwner,
    processRuntimeIsActive,
    recordTitle,
    scheduleHealthy,
    schedulesForInstance,
    schedulesForProject,
} from "./fleet";
import { scheduleListColumns } from "../pages/columns";

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
        expect(runtimeOwner(fleet, horizon!)).toBe("charlie-shop/dev");
        expect(runtimeOwner(fleet, valkey!)).toBe("node beast");
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

describe("Node and Instance schedule ownership", () => {
    const withCollision = (): Fleet => ({
        ...fleet,
        schedules: [
            ...fleet.schedules,
            {
                ...fleet.schedules[0]!,
                id: "node-nightly",
                name: "node-nightly",
                target_type: "node",
                target_id: 1,
                desired_timer_state: "disabled",
            },
        ],
    });

    it("includes Node schedules by their own Node without admitting them to a Project", () => {
        const scoped = withCollision();
        const ids = (node?: string, project?: string) =>
            listRows(scoped, "schedules", node, project).map((row) => row.id);

        expect(ids()).toEqual(["backup", "prune", "node-nightly"]);
        expect(ids("gateway")).toEqual(["node-nightly"]);
        expect(ids("beast")).toEqual(["backup", "prune"]);
        expect(ids(undefined, "charlie-shop")).toEqual(["backup", "prune"]);
        expect(ids("gateway", "charlie-shop")).toEqual([]);
        expect(ids("beast", "charlie-shop")).toEqual(["backup", "prune"]);
    });

    it("never confuses a Node ID with an Instance ID in owned sections", () => {
        const scoped = withCollision();
        expect(schedulesForProject(scoped, "charlie-shop").map((row) => row.id)).toEqual([
            "backup",
            "prune",
        ]);
        expect(schedulesForInstance(scoped, 1).map((row) => row.id)).toEqual(["backup"]);
    });

    it("labels Schedule owners and hosting Nodes consistently in lists and attention", () => {
        const scoped = withCollision();
        const node = scoped.schedules[2]!;
        const columns = scheduleListColumns(scoped);
        const value = (header: string, schedule: Schedule) =>
            columns.find((column) => column.header === header)?.value(schedule);

        expect(value("Owner", node)).toBe("node gateway");
        expect(value("Node", node)).toBe("gateway");
        expect(value("Owner", scoped.schedules[0]!)).toBe("charlie-shop/dev");
        expect(value("Node", scoped.schedules[0]!)).toBe("beast");
        expect(attentionRows(scoped).find((row) => row.id === "schedule-node-nightly")?.where).toBe(
            "node gateway",
        );
        expect(value("Node", { ...node, target_id: 999 })).toBe("—");
        expect(value("Owner", { ...node, target_type: "instance", target_id: 999 })).toBe("—");
    });
});
