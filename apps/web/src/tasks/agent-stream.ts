export type AgentThreadState = "idle" | "working" | "asking_for_input" | "done" | "failed";
export type AgentThread = {
    id: number;
    task_group_id: number;
    task_id: number | null;
    node_id: number | null;
    role: "reviewer" | "implementer";
    driver: string;
    external_id: string;
    state: AgentThreadState | null;
    observed_at?: string | null;
    observation_error?: string | null;
    error?: string | null;
    model?: string | null;
    effort?: string | null;
    tokens?: number | null;
    lines_added?: number | null;
    lines_deleted?: number | null;
};

export function agentStateLabel(state: unknown): string {
    switch (state) {
        case "idle":
            return "Idle";
        case "working":
            return "Working";
        case "asking_for_input":
            return "Asking for input";
        case "done":
            return "Done";
        case "failed":
            return "Failed";
        default:
            return "Unknown";
    }
}

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
    cursor: string | null;
    status: string;
    entries: Entry[];
    tokens: number | null;
    linesAdded: number | null;
    linesDeleted: number | null;
    error: string | null;
};
export type ConversationGroup =
    | { type: "message"; entry: Entry }
    | { type: "activities"; entries: Entry[] };

/** Group consecutive tool steps. A text message closes the group; the next step starts another. */
export function groupConversation(entries: Entry[]): ConversationGroup[] {
    const groups: ConversationGroup[] = [];
    for (const entry of entries) {
        if (entry.kind === "message") {
            groups.push({ type: "message", entry });
            continue;
        }
        const last = groups.at(-1);
        if (last?.type === "activities") {
            last.entries.push(entry);
        } else {
            groups.push({ type: "activities", entries: [entry] });
        }
    }
    return groups;
}
export const emptyConversation: Conversation = {
    cursor: null,
    status: "Unknown",
    entries: [],
    tokens: null,
    linesAdded: null,
    linesDeleted: null,
    error: null,
};
const object = (value: unknown): Record<string, unknown> =>
    value !== null && typeof value === "object" ? (value as Record<string, unknown>) : {};
function entry(value: unknown): Entry | null {
    const data = object(value);
    return typeof data.id === "string" &&
        (data.kind === "message" || data.kind === "activity") &&
        typeof data.label === "string" &&
        typeof data.text === "string" &&
        typeof data.at === "string"
        ? (data as Entry)
        : null;
}
const count = (value: unknown, previous: number | null): number | null =>
    typeof value === "number" && Number.isFinite(value) && value >= 0 ? value : previous;

export function applyAgentEvent(
    state: Conversation,
    input: unknown,
    threadId: number,
): Conversation {
    const item = object(input);
    if (
        item.thread_id !== threadId ||
        !["snapshot", "entry", "state", "metrics"].includes(String(item.kind))
    )
        return state;
    const cursor = typeof item.cursor === "string" ? item.cursor : null;
    if (cursor !== null && cursor === state.cursor) return state;
    const next = { ...state, cursor };
    if (item.kind === "snapshot") {
        next.entries = (Array.isArray(item.entries) ? item.entries : []).flatMap((value) => {
            const parsed = entry(value);
            return parsed ? [parsed] : [];
        });
    } else if (item.kind === "entry") {
        const parsed = entry(item.entry);
        if (parsed) {
            const previous = next.entries.findIndex(
                (value) => value.id === parsed.id && value.kind === parsed.kind,
            );
            next.entries =
                previous < 0
                    ? [...next.entries, parsed]
                    : next.entries.map((value, index) => (index === previous ? parsed : value));
        }
    }
    if (item.state !== undefined && item.state !== null) next.status = agentStateLabel(item.state);
    if (item.error === null || typeof item.error === "string") next.error = item.error;
    next.tokens = count(item.tokens, next.tokens);
    next.linesAdded = count(item.lines_added, next.linesAdded);
    next.linesDeleted = count(item.lines_deleted, next.linesDeleted);
    return next;
}
