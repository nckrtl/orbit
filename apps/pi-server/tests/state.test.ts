import { describe, expect, it } from "vite-plus/test";
import { deriveState } from "../src/state.ts";

const settled = (stopReason: string, errorMessage?: string) => ({
    working: false,
    interruptedByRestart: false,
    lastAssistant: { stopReason, errorMessage },
});

describe("deriveState", () => {
    it("reports idle before the first completed turn", () => {
        expect(
            deriveState({ working: false, interruptedByRestart: false, lastAssistant: undefined }),
        ).toEqual({
            state: "idle",
            error: null,
        });
    });

    it("reports working while a turn is active, even after an earlier failure", () => {
        expect(deriveState({ ...settled("error", "boom"), working: true })).toEqual({
            state: "working",
            error: null,
        });
    });

    it("reports done only for a normal stop", () => {
        expect(deriveState(settled("stop"))).toEqual({ state: "done", error: null });
    });

    it.each([
        ["error", "provider exploded", "provider exploded"],
        ["aborted", undefined, "The turn was interrupted."],
        ["length", undefined, "The model reached its output limit."],
        ["toolUse", undefined, 'The turn ended with stop reason "toolUse".'],
    ])("reports failed for stop reason %s", (reason, message, error) => {
        expect(deriveState(settled(reason, message))).toEqual({ state: "failed", error });
    });

    it("reports a restart during the turn as failed, not as the earlier outcome", () => {
        expect(deriveState({ ...settled("stop"), interruptedByRestart: true })).toEqual({
            state: "failed",
            error: "The Pi server restarted during the turn.",
        });
    });
});
