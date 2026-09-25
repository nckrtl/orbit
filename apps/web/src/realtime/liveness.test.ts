import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import {
    downForMs,
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
        expect(fallbackPollMs(Date.now())).toBe(false);
    });

    it("backs off from 30 seconds to 5 minutes with the time realtime was down at the last fetch", () => {
        const lost = Date.now();
        setLiveness("reconnecting");

        expect(fallbackPollMs(lost)).toBe(POLL_MIN_MS);
        expect(fallbackPollMs(lost + 30_000)).toBe(30_000);
        expect(fallbackPollMs(lost + 60_000)).toBe(60_000);
        expect(fallbackPollMs(lost + 120_000)).toBe(120_000);
        expect(fallbackPollMs(lost + 240_000)).toBe(240_000);
        expect(fallbackPollMs(lost + 3_600_000)).toBe(POLL_MAX_MS);
    });

    it("waits 30 seconds after a fetch made while realtime was still live", () => {
        const fetched = Date.now();
        vi.advanceTimersByTime(600_000);
        setLiveness("reconnecting");

        expect(fallbackPollMs(fetched)).toBe(POLL_MIN_MS);
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

        expect(fallbackPollMs(Date.now())).toBe(POLL_MIN_MS);
    });
});

describe("downForMs", () => {
    it("counts from the loss of realtime and is 0 while live", () => {
        expect(downForMs()).toBe(0);

        setLiveness("reconnecting");
        vi.advanceTimersByTime(160_000);
        setLiveness("polling");

        expect(downForMs()).toBe(160_000);

        setLiveness("live");

        expect(downForMs()).toBe(0);
    });
});
