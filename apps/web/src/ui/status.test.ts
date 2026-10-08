import { describe, expect, it } from "vite-plus/test";
import { statusColour, statusLabel, statusText } from "./Status";

const rollout = {
    kind: "fleet_rollout",
    since: "2026-01-02T09:58:00+00:00",
    rollout: 7,
    release: null,
} as const;
const release = {
    kind: "gateway_release",
    since: "2026-01-02T09:58:00+00:00",
    rollout: null,
    release: 31,
} as const;

describe("node status", () => {
    it("reads updating over every other state", () => {
        expect(statusText({ value: "active", reach: true, updating: rollout })).toBe("updating");
        expect(statusText({ value: "active", reach: false, updating: rollout })).toBe("updating");
        expect(statusText({ value: "offline", updating: release })).toBe("updating");
        expect(statusText({ value: "failed", updating: release })).toBe("updating");
    });

    it("reads the other states as before when nothing updates the node", () => {
        expect(statusText({ value: "active", reach: true, updating: null })).toBe("online");
        expect(statusText({ value: "active", reach: false })).toBe("offline");
        expect(statusText({ value: "active", reach: null })).toBe("active");
        expect(statusText({ value: "provisioning" })).toBe("provisioning");
    });

    it("colours updating blue and keeps the other colours", () => {
        expect(statusColour({ value: "active", reach: false, updating: rollout })).toBe(
            "text-blue",
        );
        expect(statusColour({ value: "active", reach: true })).toBe("text-green");
        expect(statusColour({ value: "active", reach: false })).toBe("text-red");
        expect(statusColour({ value: "failed" })).toBe("text-red");
        expect(statusColour({ value: "provisioning" })).toBe("text-yellow");
        expect(statusColour({ value: "active", reach: null })).toBe("text-yellow");
        expect(statusColour({ value: "active", reach: null }, true)).toBe("");
        // A record whose own status is the word "updating" keeps the in-between colour.
        expect(statusColour({ value: "updating" })).toBe("text-yellow");
    });

    it("names what updates the node in its label", () => {
        expect(statusLabel({ value: "active", updating: rollout })).toBe(
            "updating — fleet rollout",
        );
        expect(statusLabel({ value: "active", updating: release })).toBe(
            "updating — Gateway release",
        );
        expect(statusLabel({ value: "active", reach: true })).toBe("online");
    });
});
