import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import {
    fallbackPollMs,
    isLive,
    POLL_MAX_MS,
    POLL_MIN_MS,
    resetPollBackoff,
    setLiveness,
} from "./liveness";

beforeEach(() => {
    vi.useFakeTimers();
    setLiveness("live");
});

afterEach(() => {
    vi.useRealTimers();
});

describe("fallbackPollMs", () => {
    it("does not poll while realtime is live", () => {
        expect(isLive()).toBe(true);
        expect(fallbackPollMs()).toBe(false);
    });

    it("backs off from 30 seconds to 5 minutes while realtime is down", () => {
        const lost = Date.now();
        setLiveness("reconnecting");

        expect(fallbackPollMs(lost)).toBe(POLL_MIN_MS);
        expect(fallbackPollMs(lost + 30_000)).toBe(30_000);
        expect(fallbackPollMs(lost + 60_000)).toBe(60_000);
        expect(fallbackPollMs(lost + 120_000)).toBe(120_000);
        expect(fallbackPollMs(lost + 240_000)).toBe(240_000);
        expect(fallbackPollMs(lost + 3_600_000)).toBe(POLL_MAX_MS);
    });

    it("keeps the backoff across a change from reconnecting to polling", () => {
        const lost = Date.now();
        setLiveness("reconnecting");
        setLiveness("polling", "Realtime is not configured.");

        expect(fallbackPollMs(lost + 120_000)).toBe(120_000);
    });

    it("starts over after a reconnect and after a manual refresh", () => {
        const lost = Date.now();
        setLiveness("polling");
        resetPollBackoff(lost + 600_000);

        expect(fallbackPollMs(lost + 600_000)).toBe(POLL_MIN_MS);

        setLiveness("live");
        vi.advanceTimersByTime(600_000);
        setLiveness("reconnecting");

        expect(fallbackPollMs()).toBe(POLL_MIN_MS);
    });
});
