import { describe, expect, it } from "vite-plus/test";
import { fallbackPollInterval, TASK_POLL_MS, TASK_SAFETY_POLL_MS } from "./polling";

describe("fallbackPollInterval", () => {
    it("reloads task and Activity queries every five minutes while realtime is live, as a safety net", () => {
        expect(fallbackPollInterval("live")).toBe(TASK_SAFETY_POLL_MS);
        expect(TASK_SAFETY_POLL_MS).toBe(300_000);
    });

    it("polls task and Activity queries every 30 seconds while realtime is down or not configured", () => {
        expect(TASK_POLL_MS).toBe(30_000);
        expect(fallbackPollInterval("reconnecting")).toBe(30_000);
        expect(fallbackPollInterval("polling")).toBe(30_000);
    });
});
