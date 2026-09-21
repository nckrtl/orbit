import { EllipsisHorizontalIcon } from "@heroicons/react/24/outline";
import { useCallback, useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { get } from "../api/client";
import { useFleet } from "../api/queries";
import { formatCompactCount, formatTokens } from "../api/tasks";
import { Frame } from "../ui/Frame";
import { taskIdentity } from "../api/tasks";
import {
    agentProvider,
    applyAgentEvent,
    emptyConversation,
    type AgentThread,
    agentStateLabel,
    type Conversation,
    type Entry,
    groupConversation,
} from "./agent-stream";

const sessionMetaClassName = "text-[11px] font-medium uppercase tracking-[0.08em]";

function statusColor(status: string): string {
    return status === "Failed"
        ? "text-red"
        : status === "Asking for input"
          ? "text-yellow"
          : status === "Working" || status === "Done"
            ? "text-green"
            : "text-dim";
}

function ProviderMark({ model, status }: { model?: string | null; status: string }) {
    const provider = agentProvider(model);
    return (
        <span className="text-dim">
            <span role="status" aria-label={status} className={statusColor(status)}>
                ● {status}
            </span>
            {provider ? ` · ${provider.name}` : ""}
        </span>
    );
}

export function AgentSessions({
    groupId,
    subtaskId,
    projectCode,
}: {
    groupId: number;
    subtaskId?: string;
    projectCode?: string;
}) {
    const query = useQuery({
        queryKey: ["task-agents", groupId],
        queryFn: () => get<AgentThread[]>(`/api/v1/task-groups/${groupId}/agents`),
        refetchInterval: 10000,
        retry: false,
    });
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [activity, setActivity] = useState<{ id: number; status: string } | null>(null);
    const onActivity = useCallback((id: number, status: string) => {
        setActivity((previous) =>
            previous?.id === id && previous.status === status ? previous : { id, status },
        );
    }, []);
    const sessions = (query.data ?? []).filter(
        (session) =>
            subtaskId === undefined ||
            session.role === "reviewer" ||
            String(session.task_id) === subtaskId,
    );
    const selected =
        sessions.find((session) => session.id === selectedId) ??
        sessions.findLast((session) => session.role === "implementer") ??
        sessions.at(-1);
    return (
        <Frame
            title="Agents"
            className="agent-sessions-frame shrink-0 md:min-h-0 md:flex-1"
            topRight={sessions.length}
            bodyClassName={selected ? "agent-sessions-body" : undefined}
        >
            {query.isPending && <p role="status">Loading agent sessions…</p>}
            {query.error && (
                <p role="alert">
                    Could not load agent sessions.{" "}
                    <button className="link" onClick={() => void query.refetch()}>
                        Retry agents
                    </button>
                </p>
            )}
            {query.isSuccess && sessions.length === 0 && (
                <p className="text-dim">No agent sessions are linked to this task.</p>
            )}
            {selected && (
                <div className="grid min-w-0 grid-cols-1 md:h-full md:min-h-0 md:grid-cols-[26ch_minmax(0,1fr)]">
                    <div
                        role="tablist"
                        aria-label="Agent sessions"
                        aria-orientation="vertical"
                        className="agent-session-list flex flex-col gap-[var(--panel-padding)] md:min-h-0 md:overflow-y-auto"
                    >
                        {sessions.map((session, index) => {
                            const provider = agentProvider(session.model);
                            return (
                                <button
                                    key={session.id}
                                    role="tab"
                                    id={`agent-tab-${session.id}`}
                                    aria-controls={`agent-panel-${session.id}`}
                                    aria-selected={selected.id === session.id}
                                    tabIndex={selected.id === session.id ? 0 : -1}
                                    className="agent-session-tab kanban-card rounded text-left focus-visible:outline-2 focus-visible:outline-cyan"
                                    onClick={() => setSelectedId(session.id)}
                                    onKeyDown={(event) => {
                                        const offset =
                                            event.key === "ArrowDown"
                                                ? 1
                                                : event.key === "ArrowUp"
                                                  ? -1
                                                  : 0;
                                        const target =
                                            event.key === "Home"
                                                ? sessions[0]
                                                : event.key === "End"
                                                  ? sessions.at(-1)
                                                  : offset
                                                    ? sessions[
                                                          (index + offset + sessions.length) %
                                                              sessions.length
                                                      ]
                                                    : undefined;
                                        if (target) {
                                            event.preventDefault();
                                            event.stopPropagation();
                                            setSelectedId(target.id);
                                            document
                                                .getElementById(`agent-tab-${target.id}`)
                                                ?.focus();
                                        }
                                    }}
                                >
                                    <span
                                        className={`flex items-baseline justify-between gap-[1ch] text-dim ${sessionMetaClassName}`}
                                    >
                                        <span>
                                            {taskIdentity(session.task_id ?? groupId, projectCode)}
                                        </span>
                                        {provider?.name}
                                    </span>
                                    <strong className="block">
                                        <span
                                            className={statusColor(
                                                activity?.id === session.id
                                                    ? activity.status
                                                    : agentStateLabel(session.state),
                                            )}
                                            aria-hidden
                                        >
                                            ●{" "}
                                        </span>
                                        {session.role === "reviewer" ? "Reviewer" : "Implementer"}
                                    </strong>
                                </button>
                            );
                        })}
                    </div>
                    <SessionViewer key={selected.id} session={selected} onActivity={onActivity} />
                </div>
            )}
        </Frame>
    );
}

function SessionMetrics({
    tokens,
    added,
    deleted,
}: {
    tokens: number | null;
    added: number | null;
    deleted: number | null;
}) {
    const compact = formatCompactCount(tokens);
    const plus = formatCompactCount(added);
    const minus = formatCompactCount(deleted);
    if (compact === null && (plus === null || minus === null)) return null;
    const fullTokens = formatTokens(tokens);
    return (
        <p className={`mt-[10px] text-dim ${sessionMetaClassName}`}>
            {plus !== null && minus !== null && (
                <span aria-label="Line changes">
                    <span className="text-green">+{plus}</span>{" "}
                    <span className="text-red">−{minus}</span>
                </span>
            )}
            {plus !== null && minus !== null && compact !== null && " / "}
            {compact !== null && (
                <span title={fullTokens ?? undefined} aria-label={`${fullTokens} tokens`}>
                    {compact}
                </span>
            )}
        </p>
    );
}

function ActivityGroup({ entries, active }: { entries: Entry[]; active: boolean }) {
    const last = entries.at(-1);
    if (last === undefined) return null;
    const summary = last.text || last.label;
    const countLabel = `${entries.length} ${entries.length === 1 ? "step" : "steps"}`;
    if (active) {
        return (
            <article className="mb-[16px] last:mb-0" data-activity-group="active">
                {entries.length > 1 && <p className="mb-[4px] text-dim">Worked for {countLabel}</p>}
                <p className="shimmer-text">{summary}</p>
            </article>
        );
    }
    return (
        <article className="mb-[16px] last:mb-0" data-activity-group="complete">
            <details>
                <summary className="cursor-pointer text-dim">
                    {countLabel}
                    {" · "}
                    {summary}
                </summary>
                <ul className="mt-[4px] border-l border-line pl-[1ch] text-dim">
                    {entries.map((entry) => (
                        <li key={entry.id} className="truncate">
                            {entry.text || entry.label}
                        </li>
                    ))}
                </ul>
            </details>
        </article>
    );
}

function SessionViewer({
    session,
    onActivity,
}: {
    session: AgentThread;
    onActivity: (id: number, status: string) => void;
}) {
    const fleet = useFleet();
    const nodeSlug =
        session.node_id === null
            ? null
            : (fleet.nodes.find((node) => node.id === session.node_id)?.name ?? null);
    const [conversation, setConversation] = useState<Conversation>({
        ...emptyConversation,
        status: agentStateLabel(session.state),
        tokens: session.tokens ?? null,
        linesAdded: session.lines_added ?? null,
        linesDeleted: session.lines_deleted ?? null,
        error: session.error ?? null,
    });
    const [connection, setConnection] = useState("Connecting…");
    const [following, setFollowing] = useState(true);
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const viewport = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (session.node_id === null) return;
        const source = new EventSource(
            `/api/v1/task-groups/${session.task_group_id}/agents/${session.id}/stream`,
        );
        source.addEventListener("agent", (event: MessageEvent<string>) => {
            try {
                const data: unknown = JSON.parse(event.data);
                setConversation((state) => applyAgentEvent(state, data, session.id));
                setConnection("Live");
            } catch {
                setConnection("Unreadable update. Reconnecting…");
            }
        });
        source.addEventListener("unavailable", () =>
            setConnection("Agent stream unavailable. Reconnecting…"),
        );
        source.onerror = () =>
            setConnection(
                source.readyState === EventSource.CLOSED
                    ? "Agent stream unavailable. Reload to retry."
                    : "Reconnecting…",
            );
        return () => source.close();
    }, [session.id, session.node_id, session.task_group_id]);
    useEffect(() => {
        onActivity(session.id, conversation.status);
    }, [session.id, conversation.status, onActivity]);
    useEffect(() => {
        if (following && viewport.current)
            viewport.current.scrollTop = viewport.current.scrollHeight;
    }, [conversation.entries, following]);
    useEffect(() => {
        if (!menuOpen) return;
        const close = (event: MouseEvent) => {
            if (!menuRef.current?.contains(event.target as Node)) setMenuOpen(false);
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === "Escape") setMenuOpen(false);
        };
        document.addEventListener("mousedown", close);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", close);
            document.removeEventListener("keydown", onKey);
        };
    }, [menuOpen]);
    return (
        <div
            role="tabpanel"
            id={`agent-panel-${session.id}`}
            aria-labelledby={`agent-tab-${session.id}`}
            className="flex min-h-0 min-w-0 flex-col p-[var(--panel-padding)] md:h-full"
        >
            <div className="-mx-[var(--panel-padding)] mb-[12px] flex items-start gap-[2ch] border-b border-line px-[var(--panel-padding)] pb-[12px]">
                <div className="min-w-0 flex-1">
                    {session.node_id === null && (
                        <p className="text-dim">Original node unavailable</p>
                    )}
                    <p className="break-all text-dim">
                        <ProviderMark model={session.model} status={conversation.status} />{" "}
                        {session.driver}
                        {session.model ? ` · ${session.model}` : ""}
                        {session.effort ? ` · ${session.effort} effort` : ""}
                        {nodeSlug === null ? "" : ` - ${nodeSlug}`}
                    </p>
                    {connection !== "Live" && (
                        <p className="text-dim" aria-label="Agent connection">
                            {connection}
                        </p>
                    )}
                    {conversation.error && (
                        <p role="alert" className="text-red">
                            {conversation.error}
                        </p>
                    )}
                    <SessionMetrics
                        tokens={conversation.tokens}
                        added={conversation.linesAdded}
                        deleted={conversation.linesDeleted}
                    />
                </div>
                <div className="relative shrink-0" ref={menuRef}>
                    <button
                        type="button"
                        className="flex cursor-pointer items-center justify-center text-dim hover:text-fg"
                        aria-label="Session actions"
                        title="Session actions"
                        aria-haspopup="menu"
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        <EllipsisHorizontalIcon className="size-[20px]" aria-hidden="true" />
                    </button>
                    {menuOpen && (
                        <div
                            role="menu"
                            aria-label="Session actions"
                            className="absolute right-0 top-full z-10 mt-[4px] min-w-[24ch] border border-line bg-bg"
                        >
                            <button
                                type="button"
                                role="menuitem"
                                className="block w-full cursor-pointer px-[1ch] py-[2px] text-left hover:bg-fg/10"
                                onClick={() => {
                                    setFollowing((value) => !value);
                                    setMenuOpen(false);
                                }}
                            >
                                {following ? "Pause scrolling" : "Follow latest"}
                            </button>
                            <button
                                type="button"
                                role="menuitem"
                                className="block w-full cursor-pointer px-[1ch] py-[2px] text-left hover:bg-fg/10"
                                onClick={() => {
                                    void navigator.clipboard?.writeText(session.external_id);
                                    setMenuOpen(false);
                                }}
                            >
                                Copy external thread ID
                            </button>
                        </div>
                    )}
                </div>
            </div>
            <div
                ref={viewport}
                className="selectable max-h-[560px] min-h-[180px] overflow-auto md:max-h-none md:min-h-0 md:flex-1"
                onScroll={() => {
                    const el = viewport.current;
                    if (el && el.scrollHeight - el.scrollTop - el.clientHeight > 60)
                        setFollowing(false);
                }}
            >
                {conversation.entries.length === 0 && (
                    <p className="text-dim">
                        {conversation.status === "Idle"
                            ? "Thread created. No agent activity yet."
                            : "No conversation output yet."}
                    </p>
                )}
                {groupConversation(conversation.entries).map((group, index, groups) => {
                    if (group.type === "activities") {
                        const running = conversation.status === "Working";
                        return (
                            <ActivityGroup
                                key={group.entries[0]?.id ?? index}
                                entries={group.entries}
                                active={running && index === groups.length - 1}
                            />
                        );
                    }
                    const entry = group.entry;
                    return (
                        <article key={`${entry.kind}:${entry.id}`} className="mb-[16px] last:mb-0">
                            <div className="mb-[4px] flex gap-[2ch] text-dim">
                                <strong className="capitalize">{entry.label}</strong>
                                {entry.at && (
                                    <time dateTime={entry.at}>
                                        {new Date(entry.at).toLocaleTimeString()}
                                    </time>
                                )}
                            </div>
                            <p className="whitespace-pre-wrap break-words">{entry.text}</p>
                        </article>
                    );
                })}
            </div>
        </div>
    );
}
