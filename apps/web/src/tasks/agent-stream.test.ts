import { expect, it } from "vite-plus/test";
import { applyAgentEvent, emptyConversation } from "./agent-stream";

it("keeps a created thread distinct from a running agent", () => {
    const state = applyAgentEvent(
        emptyConversation,
        {
            kind: "snapshot",
            snapshot: {
                snapshotSequence: 8,
                thread: { id: "one", messages: [], activities: [], session: null },
            },
        },
        "one",
    );
    expect(state.status).toBe("Not started");
    expect(state.tokens).toBeNull();
    expect(state.lineDiff).toBeNull();
    expect(
        applyAgentEvent(
            state,
            {
                kind: "event",
                event: {
                    sequence: 9,
                    aggregateId: "one",
                    type: "thread.session-set",
                    payload: { session: { status: "running" } },
                },
            },
            "one",
        ).status,
    ).toBe("Working");
});
it("upserts streamed text and ignores duplicate or foreign events", () => {
    const event = (sequence: number, text: string, aggregateId = "one") => ({
        kind: "event",
        event: {
            sequence,
            aggregateId,
            type: "thread.message-sent",
            payload: { messageId: "m1", role: "assistant", text },
        },
    });
    const first = applyAgentEvent(emptyConversation, event(1, "Hello"), "one");
    const second = applyAgentEvent(first, event(2, "Hello world"), "one");
    expect(second.entries).toHaveLength(1);
    expect(second.entries[0]?.text).toBe("Hello world");
    expect(applyAgentEvent(second, event(1, "Old"), "one")).toBe(second);
    expect(applyAgentEvent(second, event(3, "Foreign", "other"), "one")).toBe(second);
    expect(
        applyAgentEvent(
            second,
            {
                kind: "snapshot",
                snapshot: { snapshotSequence: 3, thread: { id: "one", messages: [] } },
            },
            "one",
        ).entries,
    ).toEqual([]);
});

it("reads session tokens and checkpoint line diff from a snapshot", () => {
    const state = applyAgentEvent(
        emptyConversation,
        {
            kind: "snapshot",
            snapshot: {
                snapshotSequence: 4,
                thread: {
                    id: "one",
                    activities: [
                        {
                            id: "a1",
                            kind: "token-usage",
                            payload: { usage: { usedTokens: 200, totalProcessedTokens: 1200 } },
                        },
                    ],
                    checkpoints: [
                        {
                            files: [
                                { path: "a.php", kind: "modified", additions: 4, deletions: 1 },
                            ],
                        },
                    ],
                    session: { status: "idle" },
                },
            },
        },
        "one",
    );
    expect(state.tokens).toBe(1200);
    expect(state.lineDiff).toBe(5);
});
