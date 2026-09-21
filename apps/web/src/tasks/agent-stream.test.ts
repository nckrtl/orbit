import { expect, it } from "vite-plus/test";
import { applyAgentEvent, emptyConversation, groupConversation, type Entry } from "./agent-stream";

it("keeps a created thread distinct from a running agent", () => {
    const state = applyAgentEvent(
        emptyConversation,
        { kind: "snapshot", thread_id: 1, cursor: "a", state: "idle", entries: [] },
        1,
    );
    expect(state.status).toBe("Idle");
    expect(state.tokens).toBeNull();
    expect(state.linesAdded).toBeNull();
    expect(state.linesDeleted).toBeNull();
    expect(
        applyAgentEvent(state, { kind: "state", thread_id: 1, cursor: "b", state: "working" }, 1)
            .status,
    ).toBe("Working");
});

it("upserts streamed text and ignores duplicate or foreign events", () => {
    const event = (cursor: string, text: string, thread_id = 1) => ({
        kind: "entry",
        cursor,
        thread_id,
        entry: { id: "m1", kind: "message", label: "assistant", text, at: "" },
    });
    const first = applyAgentEvent(emptyConversation, event("one", "Hello"), 1);
    const second = applyAgentEvent(first, event("two", "Hello world"), 1);
    expect(second.entries).toHaveLength(1);
    expect(second.entries[0]?.text).toBe("Hello world");
    expect(applyAgentEvent(second, event("two", "Duplicate"), 1)).toBe(second);
    expect(applyAgentEvent(second, event("three", "Foreign", 2), 1)).toBe(second);
    expect(
        applyAgentEvent(
            second,
            { kind: "snapshot", thread_id: 1, cursor: "resumed", entries: [] },
            1,
        ).entries,
    ).toEqual([]);
});

it("reads normalized session tokens and line counts from a snapshot", () => {
    const state = applyAgentEvent(
        emptyConversation,
        {
            kind: "snapshot",
            thread_id: 1,
            cursor: "4",
            tokens: 1200,
            lines_added: 4,
            lines_deleted: 1,
            entries: [],
            state: "done",
        },
        1,
    );
    expect(state.tokens).toBe(1200);
    expect(state.linesAdded).toBe(4);
    expect(state.linesDeleted).toBe(1);
    expect(
        applyAgentEvent(
            state,
            { kind: "snapshot", thread_id: 1, cursor: "5", tokens: null, entries: [] },
            1,
        ).tokens,
    ).toBe(1200);
});

it.each([
    ["idle", "Idle"],
    ["working", "Working"],
    ["asking_for_input", "Asking for input"],
    ["done", "Done"],
    ["failed", "Failed"],
])("renders the generic %s state", (state, label) => {
    expect(
        applyAgentEvent(emptyConversation, { kind: "state", thread_id: 1, state }, 1).status,
    ).toBe(label);
});

it("retains failure details and replaces them on a successful retry", () => {
    const failed = applyAgentEvent(
        emptyConversation,
        { kind: "state", thread_id: 1, state: "failed", error: "Turn failed" },
        1,
    );
    expect(failed.error).toBe("Turn failed");
    const retry = applyAgentEvent(
        failed,
        { kind: "state", thread_id: 1, state: "working", error: null },
        1,
    );
    expect(retry.status).toBe("Working");
    expect(retry.error).toBeNull();
});

it("groups tool steps until a text message, then starts a new group", () => {
    const entry = (id: string, kind: Entry["kind"], label: string, text: string): Entry => ({
        id,
        kind,
        label,
        text,
        at: "",
    });
    const user = entry("u", "message", "user", "go");
    const tests = entry("a1", "activity", "command", "ran tests");
    const edit = entry("a2", "activity", "command", "edited file");
    const done = entry("m", "message", "assistant", "Done");
    const commit = entry("a3", "activity", "command", "committed");
    expect(groupConversation([user, tests, edit, done, commit])).toEqual([
        { type: "message", entry: user },
        { type: "activities", entries: [tests, edit] },
        { type: "message", entry: done },
        { type: "activities", entries: [commit] },
    ]);
});
