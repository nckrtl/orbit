export type AgentSession = {
    id: number;
    task_group_id: number;
    task_id: number | null;
    node_id: number | null;
    role: "reviewer" | "implementer";
    thread_id: string;
    model?: string | null;
    effort?: string | null;
};

/** The provider a model id belongs to, with the theme color that marks it. */
export function agentProvider(
    model: string | null | undefined,
): { name: string; color: string } | null {
    if (!model) return null;
    const id = model.toLowerCase();
    if (id.includes("claude")) return { name: "Claude", color: "text-yellow" };
    if (id.includes("codex") || id.includes("gpt")) return { name: "Codex", color: "text-green" };
    if (id.includes("grok")) return { name: "Grok", color: "text-cyan" };
    if (id.includes("kimi")) return { name: "Kimi", color: "text-cyan" };
    return { name: model, color: "text-dim" };
}
export type Entry = {
    id: string;
    label: string;
    text: string;
    at: string;
    kind: "message" | "activity";
};
export type Conversation = {
    sequence: number;
    status: string;
    entries: Entry[];
    tokens: number | null;
    lineDiff: number | null;
};
export const emptyConversation: Conversation = {
    sequence: -1,
    status: "Unknown",
    entries: [],
    tokens: null,
    lineDiff: null,
};
const object = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === "object" ? (value as Record<string, unknown>) : {};
const text = (value: unknown): string => (typeof value === "string" ? value : "");
const statusLabels: Record<string, string> = {
    idle: "Idle",
    starting: "Starting",
    running: "Working",
    ready: "Idle",
    interrupted: "Interrupted",
    stopped: "Stopped",
    error: "Error",
};
function entry(value: unknown, kind: Entry["kind"]): Entry | null {
    const data = object(value);
    const id = text(data.id ?? data.messageId);
    if (!id) return null;
    return {
        id,
        kind,
        label: text(kind === "message" ? data.role : data.kind) || "Activity",
        text: text(kind === "message" ? data.text : data.summary),
        at: text(data.createdAt),
    };
}
function nonNegative(value: unknown): number | null {
    return typeof value === "number" && Number.isFinite(value) && value >= 0 ? value : null;
}

function walkUsage(node: unknown, tokens: number | null): number | null {
    if (node === null || typeof node !== "object") return tokens;
    if (Array.isArray(node)) {
        return node.reduce<number | null>((current, child) => walkUsage(child, current), tokens);
    }
    const data = node as Record<string, unknown>;
    let current = tokens;
    const used = nonNegative(data.usedTokens);
    if (used !== null) {
        const processed = nonNegative(data.totalProcessedTokens);
        current = Math.max(current ?? 0, processed !== null && processed > 0 ? processed : used);
    }
    return Object.values(data).reduce<number | null>(
        (next, child) => walkUsage(child, next),
        current,
    );
}

function checkpointLines(thread: Record<string, unknown>): number | null {
    if (!Array.isArray(thread.checkpoints)) return null;
    let total = 0;
    let found = false;
    for (const checkpoint of thread.checkpoints) {
        const files = object(checkpoint).files;
        if (!Array.isArray(files)) continue;
        for (const file of files) {
            found = true;
            const row = object(file);
            total += nonNegative(row.additions) ?? 0;
            total += nonNegative(row.deletions) ?? 0;
        }
    }
    return found ? total : null;
}

function upsert(entries: Entry[], value: Entry | null): Entry[] {
    if (!value) return entries;
    const previous = entries.findIndex((item) => item.id === value.id && item.kind === value.kind);
    if (previous < 0) return [...entries, value];
    return entries.map((item, index) => (index === previous ? value : item));
}
export function applyAgentEvent(
    state: Conversation,
    input: unknown,
    threadId: string,
): Conversation {
    const item = object(input);
    if (item.kind === "snapshot") {
        const snapshot = object(item.snapshot);
        const thread = object(snapshot.thread);
        if (thread.id !== threadId) return state;
        const session = object(thread.session);
        const entries: Entry[] = [];
        for (const [key, kind] of [
            ["messages", "message"],
            ["activities", "activity"],
        ] as const) {
            for (const value of Array.isArray(thread[key]) ? thread[key] : []) {
                const parsed = entry(value, kind);
                if (parsed) entries.push(parsed);
            }
        }
        return {
            sequence:
                typeof snapshot.snapshotSequence === "number" ? snapshot.snapshotSequence : -1,
            status: thread.deletedAt
                ? "Deleted"
                : (statusLabels[text(session.status)] ?? "Not started"),
            entries: entries.sort((a, b) => a.at.localeCompare(b.at)),
            tokens: walkUsage(thread, null),
            lineDiff: checkpointLines(thread),
        };
    }
    if (item.kind !== "event") return state;
    const event = object(item.event);
    if (
        event.aggregateId !== threadId ||
        typeof event.sequence !== "number" ||
        event.sequence <= state.sequence
    )
        return state;
    const payload = object(event.payload);
    const next = { ...state, sequence: event.sequence };
    switch (event.type) {
        case "thread.message-sent":
            next.entries = upsert(state.entries, entry(payload, "message"));
            break;
        case "thread.activity-appended":
            next.entries = upsert(state.entries, entry(payload.activity, "activity"));
            next.tokens = walkUsage(payload.activity, state.tokens);
            break;
        case "thread.session-set":
            next.status = statusLabels[text(object(payload.session).status)] ?? "Unknown";
            break;
        case "thread.turn-start-requested":
            next.status = "Starting";
            break;
        case "thread.turn-interrupt-requested":
            next.status = "Interrupt requested";
            break;
        case "thread.session-stop-requested":
            next.status = "Stop requested";
            break;
        case "thread.deleted":
            next.status = "Deleted";
            break;
    }
    return next;
}
