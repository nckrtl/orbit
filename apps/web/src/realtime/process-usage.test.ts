import { afterEach, describe, expect, it } from "vite-plus/test";
import {
    lastProcessUsageAt,
    markProcessUsage,
    PROCESS_POLL_MS,
    PROCESS_USAGE_STALE_MS,
    processPollInterval,
    resetProcessUsage,
} from "./process-usage";

afterEach(() => resetProcessUsage());

describe("processPollInterval", () => {
    const now = 10_000_000;

    it("polls every 15 seconds while realtime is down, whatever usage arrived", () => {
        expect(PROCESS_POLL_MS).toBe(15_000);
        expect(processPollInterval("reconnecting", now - 1_000, now)).toBe(15_000);
        expect(processPollInterval("polling", null, now)).toBe(15_000);
    });

    it("waits until 60 seconds after the last usage event while realtime is live", () => {
        expect(PROCESS_USAGE_STALE_MS).toBe(60_000);
        expect(processPollInterval("live", now, now)).toBe(60_000);
        expect(processPollInterval("live", now - 15_000, now)).toBe(45_000);
        expect(processPollInterval("live", now - 59_000, now)).toBe(1_000);
    });

    it("reloads every 60 seconds while live when usage events stopped or never came", () => {
        expect(processPollInterval("live", now - 60_000, now)).toBe(60_000);
        expect(processPollInterval("live", now - 600_000, now)).toBe(60_000);
        expect(processPollInterval("live", null, now)).toBe(60_000);
    });

    it("treats a usage time in the future as fresh", () => {
        expect(processPollInterval("live", now + 5_000, now)).toBe(60_000);
    });
});

describe("process usage time", () => {
    it("records when the last usage event arrived", () => {
        expect(lastProcessUsageAt()).toBeNull();
        markProcessUsage(1_234);
        expect(lastProcessUsageAt()).toBe(1_234);
        resetProcessUsage();
        expect(lastProcessUsageAt()).toBeNull();
    });
});
