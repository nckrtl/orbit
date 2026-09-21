import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { get } from "../api/client";
import { formatLineDiff, formatTokens } from "../api/tasks";
import { Frame } from "../ui/Frame";
import { taskIdentity } from "../api/tasks";
import {
    agentProvider,
    applyAgentEvent,
    emptyConversation,
    type AgentSession,
    type Conversation,
} from "./agent-stream";

const sessionMetaClassName = "text-[11px] font-medium uppercase tracking-[0.08em]";

function ProviderMark({ model }: { model?: string | null }) {
    const provider = agentProvider(model);
    if (!provider) return null;
    return (
        <span className="text-dim">
            <span className={provider.color} aria-hidden>
                ●
            </span>{" "}
            {provider.name}
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
        queryFn: () => get<AgentSession[]>(`/api/v1/task-groups/${groupId}/agents`),
        refetchInterval: 10000,
        retry: false,
    });
    const [selectedId, setSelectedId] = useState<number | null>(null);
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
            className="shrink-0 md:min-h-0 md:flex-1"
            topRight={sessions.length}
            bodyClassName={selected ? "agent-sessions-body" : undefined}
        >
            {query.isPending && <p role="status">Loading agent sessions…</p>}
            {query.error && (
                <p role="alert">
                    Could not load agent sessions.{" "}
                    <button className="text-cyan underline" onClick={() => void query.refetch()}>
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
                        {sessions.map((session, index) => (
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
                                        document.getElementById(`agent-tab-${target.id}`)?.focus();
                                    }
                                }}
                            >
                                <span className={`block text-dim ${sessionMetaClassName}`}>
                                    {taskIdentity(session.task_id ?? groupId, projectCode)}
                                </span>
                                <strong className="block">
                                    {session.role === "reviewer" ? "Reviewer" : "Implementer"}
                                </strong>
                                {session.model != null && (
                                    <span className="block">
                                        <ProviderMark model={session.model} />
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                    <SessionViewer key={selected.id} session={selected} />
                </div>
            )}
        </Frame>
    );
}

function SessionViewer({ session }: { session: AgentSession }) {
    const [conversation, setConversation] = useState<Conversation>(emptyConversation);
    const [connection, setConnection] = useState("Connecting…");
    const [following, setFollowing] = useState(true);
    const viewport = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (session.node_id === null) return;
        const source = new EventSource(
            `/api/v1/task-groups/${session.task_group_id}/agents/${session.id}/stream`,
        );
        source.addEventListener("agent", (event: MessageEvent<string>) => {
            try {
                const data: unknown = JSON.parse(event.data);
                setConversation((state) => applyAgentEvent(state, data, session.thread_id));
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
    }, [session.id, session.node_id, session.task_group_id, session.thread_id]);
    useEffect(() => {
        if (following && viewport.current)
            viewport.current.scrollTop = viewport.current.scrollHeight;
    }, [conversation.entries, following]);
    return (
        <div
            role="tabpanel"
            id={`agent-panel-${session.id}`}
            aria-labelledby={`agent-tab-${session.id}`}
            className="flex min-h-0 min-w-0 flex-col p-[var(--panel-padding)] md:h-full"
        >
            <div className="mb-[12px] flex flex-col gap-[10px] border-b border-line pb-[12px]">
                <div className="flex flex-wrap items-center gap-x-[2ch] gap-y-[8px]">
                    <strong>{conversation.status}</strong>
                    <span role="status" className="text-dim">
                        {session.node_id === null ? "Original node unavailable" : connection}
                    </span>
                    <button
                        className="ml-auto text-cyan underline"
                        onClick={() => setFollowing(!following)}
                    >
                        {following ? "Pause scrolling" : "Follow latest"}
                    </button>
                </div>
                <p className="break-all text-dim">
                    <ProviderMark model={session.model} /> T3
                    {session.model ? ` · ${session.model}` : ""}
                    {session.effort ? ` · ${session.effort} effort` : ""} · {session.thread_id}
                </p>
                {(conversation.tokens !== null || conversation.lineDiff !== null) && (
                    <p className="text-dim">
                        {[
                            conversation.tokens === null
                                ? null
                                : `${formatTokens(conversation.tokens)} tokens`,
                            conversation.lineDiff === null
                                ? null
                                : `${formatLineDiff(conversation.lineDiff)} lines`,
                        ]
                            .filter((part): part is string => part !== null)
                            .join(" · ")}
                    </p>
                )}
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
                        {conversation.status === "Not started"
                            ? "Thread created. No agent activity yet."
                            : "No conversation output yet."}
                    </p>
                )}
                {conversation.entries.map((entry) => (
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
                ))}
            </div>
        </div>
    );
}
