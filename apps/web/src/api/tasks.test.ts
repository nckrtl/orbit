import { expect, it } from "vite-plus/test";
import {
    formatCardDuration,
    formatDurationMs,
    formatLineDiff,
    formatTokens,
    taskColumn,
    type Task,
} from "./tasks";

it.each([
    ["queued", "Todo"],
    ["pending", "Todo"],
    ["reserved", "In progress"],
    ["running", "In progress"],
    ["reviewing", "In progress"],
    ["settling", "In progress"],
    ["completed", "Done"],
    ["failed", "Done"],
    ["cancelled", "Done"],
] as const)("places %s in %s", (status: Task["status"] | "queued" | "settling", column) => {
    expect(taskColumn(status)).toBe(column);
});

it("formats tokens and line diffs with grouping", () => {
    expect(formatTokens(null)).toBeNull();
    expect(formatTokens(1200)).toBe("1,200");
    expect(formatLineDiff(16)).toBe("16");
});

it("formats duration from milliseconds", () => {
    expect(formatDurationMs(null)).toBeNull();
    expect(formatDurationMs(400)).toBe("400ms");
    expect(formatDurationMs(5000)).toBe("5s");
    expect(formatDurationMs(65_000)).toBe("1m 5s");
    expect(formatDurationMs(3_600_000)).toBe("1h");
});

it("formats card durations in minutes and hours without counting unknown time", () => {
    expect(formatCardDuration(null)).toBeNull();
    expect(formatCardDuration(30_000)).toBe("<1m");
    expect(formatCardDuration(32 * 60_000)).toBe("32m");
    expect(formatCardDuration(92 * 60_000)).toBe("1h 32m");
    expect(formatCardDuration(120 * 60_000)).toBe("2h");
});
