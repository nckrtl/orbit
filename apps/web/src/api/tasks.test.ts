import { expect, it } from "vite-plus/test";
import {
    accumulatedLineChanges,
    checkDurationMs,
    formatRelativeTime,
    taskCommentTypeLabel,
    completedSubtaskProgress,
    formatCardDuration,
    formatCompactCount,
    formatDurationMs,
    formatLineDiff,
    formatSignedLineChanges,
    formatTokens,
    taskColumn,
    type Task,
} from "./tasks";

it.each([
    ["backlog", "Backlog"],
    ["todo", "Todo"],
    ["reserved", "In progress"],
    ["running", "In progress"],
    ["reviewing", "In progress"],
    ["settling", "In progress"],
    ["completed", "Done"],
    ["failed", "Done"],
    ["cancelled", "Done"],
] as const)("places %s in %s", (status: Task["status"] | "backlog" | "settling", column) => {
    expect(taskColumn(status)).toBe(column);
});

it("formats tokens and line diffs with grouping", () => {
    expect(formatTokens(null)).toBeNull();
    expect(formatTokens(1200)).toBe("1,200");
    expect(formatLineDiff(16)).toBe("16");
});

it("formats signed line changes with compact counts", () => {
    expect(formatSignedLineChanges(null, 6)).toBeNull();
    expect(formatSignedLineChanges(16, 6)).toBe("+16 −6");
    expect(formatSignedLineChanges(23_320, 9_322)).toBe("+23.32K −9.322K");
    expect(formatSignedLineChanges(23_320, 9_322, formatLineDiff)).toBe("+23,320 −9,322");
});

it("compacts counts into a 5-character significand plus a unit", () => {
    expect(formatCompactCount(null)).toBeNull();
    expect(formatCompactCount(-1)).toBeNull();
    expect(formatCompactCount(0)).toBe("0");
    expect(formatCompactCount(300)).toBe("300");
    expect(formatCompactCount(999)).toBe("999");
    expect(formatCompactCount(1000)).toBe("1K");
    expect(formatCompactCount(1500)).toBe("1.5K");
    expect(formatCompactCount(10_210)).toBe("10.21K");
    expect(formatCompactCount(10_100)).toBe("10.1K");
    expect(formatCompactCount(99_830)).toBe("99.83K");
    expect(formatCompactCount(100_100)).toBe("100.1K");
    expect(formatCompactCount(303_100)).toBe("303.1K");
    expect(formatCompactCount(3_212_000)).toBe("3.212M");
    expect(formatCompactCount(10_310_000)).toBe("10.31M");
    expect(formatCompactCount(999_950)).toBe("1M");
});

it("formats duration from milliseconds", () => {
    expect(formatDurationMs(null)).toBeNull();
    expect(formatDurationMs(400)).toBe("400ms");
    expect(formatDurationMs(5000)).toBe("5s");
    expect(formatDurationMs(65_000)).toBe("1m 5s");
    expect(formatDurationMs(3_600_000)).toBe("1h");
});

it("counts completed nested tasks against the group total", () => {
    const task = (id: number, status: Task["status"]): Task => ({
        id,
        task_group_id: 1,
        type: "implementation",
        target_thread_id: null,
        completion_summary: null,
        check: null,
        assistance_requested: false,
        assistance_reason: null,
        position: id,
        title: `Step ${id}`,
        brief: "",
        deliverables: [],
        status,
        implementer_agent_thread_id: null,
        tokens: null,
        line_diff: null,
        duration_ms: null,
    });

    expect(
        completedSubtaskProgress([
            task(1, "completed"),
            task(2, "completed"),
            task(3, "running"),
            task(4, "todo"),
            task(5, "failed"),
        ]),
    ).toEqual({ completed: 2, total: 5 });
    expect(completedSubtaskProgress([])).toEqual({ completed: 0, total: 0 });
});

it("accumulates subtask line changes and skips tasks without a split diff", () => {
    expect(
        accumulatedLineChanges([
            { lines_added: 10, lines_deleted: 2 },
            { lines_added: null, lines_deleted: null },
            { lines_added: 4, lines_deleted: 7 },
        ]),
    ).toEqual({ lines_added: 14, lines_deleted: 9 });
    expect(accumulatedLineChanges([{ lines_added: null, lines_deleted: null }])).toBeNull();
});

it("formats card durations in minutes and hours without counting unknown time", () => {
    expect(formatCardDuration(null)).toBeNull();
    expect(formatCardDuration(30_000)).toBe("<1m");
    expect(formatCardDuration(32 * 60_000)).toBe("32m");
    expect(formatCardDuration(92 * 60_000)).toBe("1h 32m");
    expect(formatCardDuration(120 * 60_000)).toBe("2h");
});

it("formats how long ago a comment was posted", () => {
    const now = Date.parse("2026-09-23T12:00:00Z");
    expect(formatRelativeTime(null, now)).toBeNull();
    expect(formatRelativeTime("not a date", now)).toBeNull();
    expect(formatRelativeTime("2026-09-23T11:59:30Z", now)).toBe("just now");
    expect(formatRelativeTime("2026-09-23T12:00:30Z", now)).toBe("just now");
    expect(formatRelativeTime("2026-09-23T11:55:00Z", now)).toBe("5m ago");
    expect(formatRelativeTime("2026-09-23T09:00:00Z", now)).toBe("3h ago");
    expect(formatRelativeTime("2026-09-21T11:00:00Z", now)).toBe("2d ago");
});

it("labels every comment type and keeps an unknown one readable", () => {
    expect(
        [
            "ready_for_review",
            "changes_requested",
            "approved",
            "blocked",
            "assistance_requested",
            "resolution",
            "handed_back",
        ].map(taskCommentTypeLabel),
    ).toEqual([
        "Ready for review",
        "Changes requested",
        "Approved",
        "Blocked",
        "Assistance requested",
        "Resolution",
        "handed back",
    ]);
});

it("measures a check until it finished, or until now while it runs", () => {
    const now = Date.parse("2026-09-23T12:00:00Z");
    const started_at = "2026-09-23T11:58:00Z";
    expect(
        checkDurationMs({ status: "passed", started_at, finished_at: "2026-09-23T11:59:12Z" }, now),
    ).toBe(72_000);
    expect(checkDurationMs({ status: "running", started_at, finished_at: null }, now)).toBe(
        120_000,
    );
    expect(checkDurationMs({ status: "lost", started_at, finished_at: null }, now)).toBeNull();
    expect(checkDurationMs({ status: "passed" }, now)).toBeNull();
});
