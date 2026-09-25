import { focusManager, QueryClient, QueryObserver } from "@tanstack/react-query";
import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { fallbackPollMs, setLiveness } from "../../src/realtime/liveness";

// TanStack Query schedules interval timers only in a browser, so this runs in the browser project.
beforeEach(() => {
    vi.useFakeTimers();
    setLiveness("live");
});

afterEach(() => {
    focusManager.setFocused(undefined);
    vi.useRealTimers();
});

describe("a list that realtime keeps current", () => {
    const watch = () => {
        const client = new QueryClient();
        const queryFn = vi.fn(async () => []);
        const observer = new QueryObserver(client, {
            queryKey: ["nodes"],
            queryFn,
            refetchInterval: () => fallbackPollMs(),
        });
        const stop = observer.subscribe(() => {});

        return { observer, queryFn, stop };
    };

    it("fetches once while live, then polls with a growing delay once realtime is lost", async () => {
        const { observer, queryFn, stop } = watch();
        await vi.advanceTimersByTimeAsync(600_000);

        expect(queryFn).toHaveBeenCalledTimes(1);

        setLiveness("reconnecting");
        // A mounted component re-renders on the liveness change; the observer then reads the delay again.
        observer.setOptions({ ...observer.options });

        const times: number[] = [];
        const start = Date.now();
        queryFn.mockImplementation(async () => {
            times.push(Date.now() - start);

            return [];
        });
        await vi.advanceTimersByTimeAsync(1_200_000);
        stop();

        expect(times.slice(0, 5)).toEqual([30_000, 60_000, 120_000, 240_000, 480_000]);
        expect(times.length).toBeLessThanOrEqual(7);
    });

    it("does not poll while the tab is hidden", async () => {
        setLiveness("polling");
        const { queryFn, stop } = watch();
        await vi.advanceTimersByTimeAsync(0);
        focusManager.setFocused(false);
        queryFn.mockClear();

        await vi.advanceTimersByTimeAsync(1_200_000);
        stop();

        expect(queryFn).not.toHaveBeenCalled();
    });
});
