import { afterEach, describe, expect, it, vi } from "vite-plus/test";
import {
    acceptAgentEvent,
    agentMemberAdded,
    agentMemberRemoved,
    agentState,
    agentSubscriptionSucceeded,
    clearAllAgentPresence,
} from "./agent-presence";

afterEach(() => {
    vi.useRealTimers();
    clearAllAgentPresence();
});

describe("agent presence", () => {
    it("accepts events only from the Node's own agent member", () => {
        vi.useFakeTimers();
        vi.setSystemTime(100);
        agentSubscriptionSucceeded(12, {
            members: { "agent.12": { kind: "agent" } },
            count: 1,
            myID: "viewer.1",
            me: null,
        });
        const data = { sequence: 1, at: "2025-01-01T00:00:00Z" };
        expect(acceptAgentEvent(12, "agent.13", data)).toBe(false);
        expect(agentState(12)).toBe("not_seen");
        expect(acceptAgentEvent(12, "agent.12", data)).toBe(true);
        expect(agentState(12)).toBe("online");
    });

    it("marks a member lost after 15 seconds without an event", () => {
        vi.useFakeTimers();
        vi.setSystemTime(1000);
        agentMemberAdded(4, "agent.4");
        acceptAgentEvent(4, "agent.4", { sequence: 1 });
        expect(agentState(4)).toBe("online");
        vi.advanceTimersByTime(15_000);
        expect(agentState(4)).toBe("lost");
    });

    it("marks the agent lost immediately when its member is removed", () => {
        vi.useFakeTimers();
        vi.setSystemTime(10);
        agentSubscriptionSucceeded(5, { members: { "agent.5": {} }, count: 1 });
        acceptAgentEvent(5, "agent.5", { sequence: 1 });
        agentMemberRemoved(5, "agent.5");
        expect(agentState(5)).toBe("lost");
    });

    it("leaves an unseen Node eligible for Prometheus fallback", () => {
        expect(agentState(6)).toBe("not_seen");
    });
});
