import { afterEach, describe, expect, it } from "vite-plus/test";
import type { Instance, Process } from "../api/types";
import { agentMemberAdded, acceptAgentEvent, clearAllAgentPresence } from "./agent-presence";
import {
    applyAgentProcessEvent,
    clearAgentProcesses,
    withAgentProcessStatuses,
} from "./agent-processes";

afterEach(() => {
    clearAllAgentPresence();
    clearAgentProcesses();
});

const process = (values: Partial<Process> = {}): Process =>
    ({
        id: 42,
        name: "web",
        target_type: "node",
        target_id: 7,
        runtime: "systemd",
        runtime_status: "active",
        ...values,
    }) as Process;
const unit = (name: string, runtime = "systemd", runtime_status = "failed") => ({
    name,
    runtime,
    runtime_status,
});
const online = () => {
    agentMemberAdded(7, "agent.7");
    expect(acceptAgentEvent(7, "agent.7", { sequence: 1 })).toBe(true);
};

describe("agent Process state", () => {
    it("matches only the exact Process unit name and runtime on its Node", () => {
        online();
        applyAgentProcessEvent(7, "client-snapshot", { sequence: 2, part: 1, parts: 1, units: [] });
        applyAgentProcessEvent(7, "client-process", {
            sequence: 3,
            unit: unit("orbit-process-42-web", "systemd", "inactive"),
        });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("inactive");
        expect(
            withAgentProcessStatuses([
                process({ id: 43 }),
                process({ runtime: "docker" }),
                process({ target_id: 8 }),
            ]).map((p) => p.runtime_status),
        ).toEqual(["inactive", "exited", "active"]);
        applyAgentProcessEvent(7, "client-process", {
            sequence: 4,
            unit: unit("orbit-process-42-web-candidate", "systemd", "failed"),
        });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("inactive");
    });

    it("matches an Instance Process to the Node running its Instance", () => {
        online();
        applyAgentProcessEvent(7, "client-snapshot", {
            sequence: 2,
            part: 1,
            parts: 1,
            units: [unit("orbit-process-42-web", "systemd", "failed")],
        });
        const instance = { id: 3, node: { id: 7 } } as Instance;
        const instanceProcess = process({ target_type: "instance", target_id: 3 });
        expect(withAgentProcessStatuses([instanceProcess], [instance])[0]?.runtime_status).toBe(
            "failed",
        );
    });

    it("assembles consecutive snapshot parts and replaces the previous unit set", () => {
        online();
        applyAgentProcessEvent(7, "client-snapshot", {
            sequence: 2,
            part: 1,
            parts: 2,
            units: [unit("orbit-process-99-db", "docker", "running")],
        });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("active");
        applyAgentProcessEvent(7, "client-snapshot", {
            sequence: 3,
            part: 2,
            parts: 2,
            units: [unit("orbit-process-42-web", "systemd", "active")],
        });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("active");
        applyAgentProcessEvent(7, "client-snapshot", { sequence: 4, part: 1, parts: 1, units: [] });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("inactive");
    });

    it("keeps polled state until a complete snapshot arrives", () => {
        online();
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("active");
        applyAgentProcessEvent(7, "client-process", {
            sequence: 2,
            unit: unit("orbit-process-42-web", "systemd", "failed"),
        });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("active");
    });

    it("uses runtime-specific missing-unit states only while the agent is online", () => {
        online();
        applyAgentProcessEvent(7, "client-snapshot", { sequence: 2, part: 1, parts: 1, units: [] });
        expect(
            withAgentProcessStatuses([process(), process({ id: 11, runtime: "docker" })]).map(
                (p) => p.runtime_status,
            ),
        ).toEqual(["inactive", "exited"]);
        clearAllAgentPresence();
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("active");
    });

    it("rebases a restarted agent when its sequence restarts", () => {
        online();
        applyAgentProcessEvent(7, "client-snapshot", {
            sequence: 50,
            part: 1,
            parts: 1,
            units: [unit("orbit-process-42-web", "systemd", "failed")],
        });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("failed");
        applyAgentProcessEvent(7, "client-snapshot", {
            sequence: 1,
            part: 1,
            parts: 1,
            units: [unit("orbit-process-42-web", "systemd", "active")],
        });
        expect(withAgentProcessStatuses([process()])[0]?.runtime_status).toBe("active");
    });

    it("keeps agent state over subsequent polled Process values", () => {
        online();
        applyAgentProcessEvent(7, "client-snapshot", { sequence: 2, part: 1, parts: 1, units: [] });
        applyAgentProcessEvent(7, "client-process", {
            sequence: 3,
            unit: unit("orbit-process-42-web", "systemd", "failed"),
        });
        const polled = process({ runtime_status: "active" });
        expect(withAgentProcessStatuses([polled])[0]?.runtime_status).toBe("failed");
        expect(polled.runtime_status).toBe("active");
    });
});
