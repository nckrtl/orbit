import { useQuery } from "@tanstack/react-query";
import { useParams, useRouter, useSearch } from "@tanstack/react-router";
import { useEffect, useMemo, useRef, useState, useSyncExternalStore, type ReactNode } from "react";
import {
    activitiesQuery,
    activityQuery,
    olderActivityBeforeId,
    type Activity,
    type ActivityListFilters,
    type ActivityStatus,
} from "../api/activities";
import { GatewayError } from "../api/client";
import { useFleet } from "../api/queries";
import { formatDurationMs } from "../api/tasks";
import type { Node } from "../api/types";
import { useTaskPoll } from "../realtime/polling";
import { Frame, Note } from "../ui/Frame";
import { useGo } from "../ui/go";
import { PageHeader } from "../ui/PageHeader";
import { type Column, Pane } from "../ui/Pane";

const STATUSES = ["running", "succeeded", "failed"] as const;

const desktopQuery = "(min-width: 768px)";
const desktop = () => window.matchMedia(desktopQuery).matches;
const subscribeDesktop = (notify: () => void) => {
    const media = window.matchMedia(desktopQuery);
    media.addEventListener("change", notify);

    return () => media.removeEventListener("change", notify);
};

/** The width where the main navigation collapses into the header menu. */
function useDesktop(): boolean {
    return useSyncExternalStore(subscribeDesktop, desktop, () => false);
}

function activityScroller(onDesktop: boolean): HTMLElement | null {
    const found = onDesktop
        ? document.querySelector('[data-pane="activity"] .frame-body')
        : document.querySelector("main.page-content");

    return found instanceof HTMLElement ? found : null;
}

/** UTC, so a row's time reads the same on every machine. */
function formatActivityTime(value: string): string {
    const time = Date.parse(value);
    if (!Number.isFinite(time)) return "—";

    return `${new Date(time).toISOString().slice(0, 19).replace("T", " ")} UTC`;
}

function durationLabel(value: number | null): string {
    return formatDurationMs(value) ?? "—";
}

function nodeLabel(nodes: readonly Node[], id: number | null | undefined): string {
    if (id === null || id === undefined) return "—";

    return nodes.find((node) => node.id === id)?.name ?? `#${id}`;
}

function filterNodeLabel(nodes: readonly Node[], id: number | undefined): string {
    return id === undefined ? "all" : nodeLabel(nodes, id);
}

function nextStatus(current: ActivityStatus | undefined): ActivityStatus | undefined {
    const index = current === undefined ? -1 : STATUSES.indexOf(current);

    return STATUSES[index + 1];
}

function nextNodeId(nodes: readonly Node[], current: number | undefined): number | undefined {
    const ids = nodes.map((node) => node.id);
    const index = current === undefined ? -1 : ids.indexOf(current);

    return ids[index + 1];
}

function withoutCursor(
    filters: ActivityListFilters,
    patch: ActivityListFilters,
): ActivityListFilters {
    const next: ActivityListFilters = { ...filters, ...patch };
    delete next.before_id;
    for (const key of Object.keys(next) as (keyof ActivityListFilters)[]) {
        if (next[key] === undefined) delete next[key];
    }

    return next;
}

function ActivityStatusText({ status }: { status: string }) {
    const colour =
        status === "succeeded"
            ? "text-green"
            : status === "failed"
              ? "text-red"
              : status === "running"
                ? "text-yellow"
                : "";

    return <span className={colour}>{status}</span>;
}

function activityColumns(nodes: readonly Node[]): Column<Activity>[] {
    return [
        {
            header: "Time",
            width: 22,
            value: (row) => formatActivityTime(row.occurred_at),
            sort: (row) => Date.parse(row.occurred_at) || 0,
        },
        { header: "Command", width: 22, value: (row) => row.command },
        {
            header: "Status",
            width: 12,
            value: (row) => row.status,
            cell: (row) => <ActivityStatusText status={row.status} />,
        },
        {
            header: "Caller",
            width: 14,
            value: (row) => nodeLabel(nodes, row.caller_node_id),
        },
        {
            header: "Target",
            width: 14,
            value: (row) => nodeLabel(nodes, row.target_node_id),
        },
        {
            header: "Duration",
            width: 12,
            align: "right",
            value: (row) => durationLabel(row.duration_ms),
            sort: (row) => row.duration_ms ?? -1,
        },
        {
            header: "Error",
            width: 28,
            value: (row) => row.error_code ?? "—",
        },
    ];
}

function FilterButton({
    name,
    value,
    active,
    stacked,
    onClick,
}: {
    name: string;
    value: string;
    active: boolean;
    stacked: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className={`m-0 cursor-pointer border-0 bg-transparent p-0 text-left font-[inherit] ${
                stacked ? "min-h-[44px] w-full" : ""
            } ${active ? "text-cyan" : "text-dim hover:text-fg"}`}
            onClick={onClick}
        >
            {name}: {value} ▾
        </button>
    );
}

function CommandFilter({
    value,
    stacked,
    onCommit,
}: {
    value: string;
    stacked: boolean;
    onCommit: (command: string | undefined) => void;
}) {
    const [draft, setDraft] = useState(value);
    useEffect(() => setDraft(value), [value]);
    const commit = () => {
        const command = draft.trim();
        onCommit(command === "" ? undefined : command.slice(0, 255));
    };

    return (
        <label className={`flex items-center gap-[1ch] ${stacked ? "min-h-[44px] w-full" : ""}`}>
            <span className={value === "" ? "text-dim" : "text-cyan"}>command</span>
            <input
                aria-label="Command"
                value={draft}
                maxLength={255}
                placeholder="all"
                className={`border border-line bg-transparent px-[1ch] text-fg outline-none placeholder:text-dim focus:border-cyan ${
                    stacked ? "min-w-0 flex-1" : "w-[24ch]"
                }`}
                onChange={(event) => setDraft(event.target.value)}
                onBlur={commit}
                onKeyDown={(event) => {
                    if (event.key !== "Enter") return;
                    event.preventDefault();
                    event.stopPropagation();
                    commit();
                }}
            />
        </label>
    );
}

function ActivityFilters({
    search,
    nodes,
    stacked,
    olderBeforeId,
    onChange,
    onNewest,
    onOlder,
}: {
    search: ActivityListFilters;
    nodes: readonly Node[];
    stacked: boolean;
    olderBeforeId: number | null;
    onChange: (patch: ActivityListFilters) => void;
    onNewest: () => void;
    onOlder: () => void;
}) {
    const controlClass = stacked
        ? "min-h-[44px] w-full cursor-pointer border border-line bg-transparent font-[inherit]"
        : "m-0 cursor-pointer border-0 bg-transparent p-0 font-[inherit]";

    return (
        <span
            data-activity-filters=""
            className={`flex gap-[1ch] ${stacked ? "w-full flex-col" : "flex-wrap items-center"}`}
        >
            <FilterButton
                name="status"
                value={search.status ?? "all"}
                active={search.status !== undefined}
                stacked={stacked}
                onClick={() => onChange({ status: nextStatus(search.status) })}
            />
            <CommandFilter
                value={search.command ?? ""}
                stacked={stacked}
                onCommit={(command) => onChange({ command })}
            />
            <FilterButton
                name="caller"
                value={filterNodeLabel(nodes, search.caller_node_id)}
                active={search.caller_node_id !== undefined}
                stacked={stacked}
                onClick={() =>
                    onChange({ caller_node_id: nextNodeId(nodes, search.caller_node_id) })
                }
            />
            <FilterButton
                name="target"
                value={filterNodeLabel(nodes, search.target_node_id)}
                active={search.target_node_id !== undefined}
                stacked={stacked}
                onClick={() =>
                    onChange({ target_node_id: nextNodeId(nodes, search.target_node_id) })
                }
            />
            {search.before_id !== undefined && (
                <button type="button" className={controlClass} onClick={onNewest}>
                    Newest
                </button>
            )}
            {olderBeforeId !== null && (
                <button
                    type="button"
                    aria-label="Older rows"
                    className={`${controlClass} text-cyan`}
                    onClick={onOlder}
                >
                    Older
                </button>
            )}
        </span>
    );
}

function ActivityError({ error, retry }: { error: Error; retry: () => void }) {
    const text = error instanceof GatewayError ? error.message : "Could not load activity.";

    return (
        <div role="alert" className="px-[1ch] py-[8px]">
            <p>{text}</p>
            <button type="button" className="link mt-[8px]" onClick={retry}>
                Try again
            </button>
        </div>
    );
}

/** The Activity log: one page of 25 rows, filtered, with older rows on the cursor. */
export function ActivityPage() {
    const search = useSearch({ from: "/activity" });
    const router = useRouter();
    const onDesktop = useDesktop();
    const fleet = useFleet();
    const list = useQuery({ ...activitiesQuery(search), refetchInterval: useTaskPoll() });
    const olderBeforeId = list.data === undefined ? null : olderActivityBeforeId(list.data);
    const paging = useRef(false);
    const olderRef = useRef<number | null>(null);
    const searchRef = useRef(search);
    olderRef.current = olderBeforeId;
    searchRef.current = search;

    const loadOlder = () => {
        const beforeId = olderRef.current;
        if (beforeId === null || paging.current) return;
        paging.current = true;
        void router.navigate({
            to: "/activity",
            search: { ...searchRef.current, before_id: beforeId },
        });
    };
    const loadOlderRef = useRef(loadOlder);
    loadOlderRef.current = loadOlder;

    useEffect(() => {
        paging.current = false;
    }, [search]);

    useEffect(() => {
        if (list.data === undefined) return;
        const scroller = activityScroller(onDesktop);
        if (scroller === null) return;
        const onScroll = () => {
            const atEnd = scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 8;
            // A short page keeps the control on screen, so only a real scroll loads the next page.
            if (scroller.scrollTop > 0 && atEnd) loadOlderRef.current();
        };
        scroller.addEventListener("scroll", onScroll);

        return () => scroller.removeEventListener("scroll", onScroll);
    }, [list.data, onDesktop, search.before_id]);

    const open = (row: Activity) => {
        void router.navigate({
            to: "/activity/$id",
            params: { id: String(row.id) },
            search,
        });
    };
    const applyFilters = (patch: ActivityListFilters) => {
        const next = withoutCursor(search, patch);
        if (JSON.stringify(next) === JSON.stringify(withoutCursor(search, {}))) return;
        void router.navigate({ to: "/activity", search: next });
    };
    const showNewest = () => {
        void router.navigate({ to: "/activity", search: withoutCursor(search, {}) });
    };
    const columns = useMemo(() => activityColumns(fleet.nodes), [fleet.nodes]);
    const rows = list.data ?? [];

    return (
        <div className="flex min-w-0 flex-col gap-y-[var(--panel-gap)] md:h-full md:min-h-0">
            <PageHeader trail={[{ label: "Activity" }]}>
                {onDesktop ? (
                    <ActivityFilters
                        search={search}
                        nodes={fleet.nodes}
                        stacked={false}
                        olderBeforeId={olderBeforeId}
                        onChange={applyFilters}
                        onNewest={showNewest}
                        onOlder={loadOlder}
                    />
                ) : undefined}
            </PageHeader>
            {list.error !== null && list.data === undefined ? (
                <ActivityError error={list.error} retry={() => void list.refetch()} />
            ) : list.data === undefined ? (
                <p role="status">Loading activity…</p>
            ) : onDesktop ? (
                <Pane
                    name="activity"
                    order={1}
                    title="Activity"
                    className="w-full min-h-0 flex-1"
                    columns={columns}
                    rows={rows}
                    rowId={(row) => String(row.id)}
                    warn={(row) => row.status === "failed"}
                    onRowClick={open}
                    empty="No activity."
                />
            ) : (
                <>
                    <div className="px-[1ch]">
                        <ActivityFilters
                            search={search}
                            nodes={fleet.nodes}
                            stacked
                            olderBeforeId={olderBeforeId}
                            onChange={applyFilters}
                            onNewest={showNewest}
                            onOlder={loadOlder}
                        />
                    </div>
                    <Frame title="Activity" className="w-full">
                        {rows.length === 0 ? (
                            <Note>No activity.</Note>
                        ) : (
                            <div className="flex flex-col">
                                {rows.map((row) => (
                                    <button
                                        key={row.id}
                                        type="button"
                                        aria-label={`Open activity ${row.id}`}
                                        className="flex w-full flex-col items-start gap-y-[2px] border-b border-line py-[8px] text-left font-[inherit]"
                                        onClick={() => open(row)}
                                    >
                                        <span className="text-dim">
                                            {formatActivityTime(row.occurred_at)}
                                        </span>
                                        <span className="font-bold">{row.command}</span>
                                        <span className="flex flex-wrap gap-x-[1ch]">
                                            <ActivityStatusText status={row.status} />
                                            <span>
                                                {nodeLabel(fleet.nodes, row.caller_node_id)}
                                            </span>
                                            <span aria-hidden="true">→</span>
                                            <span>
                                                {nodeLabel(fleet.nodes, row.target_node_id)}
                                            </span>
                                        </span>
                                        <span className="text-dim">
                                            {durationLabel(row.duration_ms)}
                                            {row.error_code !== null && ` · ${row.error_code}`}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </Frame>
                </>
            )}
        </div>
    );
}

function subjectLabel(row: Activity): string {
    if (row.subject_type === null || row.subject_id === null) return "—";

    return `${row.subject_type} #${row.subject_id}`;
}

type DetailRow = { name: string; value: ReactNode; warn?: boolean };

/**
 * Stored properties, one row per leaf. An empty array or object is itself a leaf (`[]` or `{}`),
 * so a key whose value is an empty collection is not dropped.
 */
function storedProperties(value: unknown, prefix = ""): DetailRow[] {
    if (Array.isArray(value)) {
        if (value.length === 0) {
            return prefix === "" ? [] : [{ name: prefix, value: "[]" }];
        }

        return value.flatMap((item, index) =>
            storedProperties(item, prefix === "" ? `[${index}]` : `${prefix}[${index}]`),
        );
    }
    if (value !== null && typeof value === "object") {
        const entries = Object.entries(value);
        if (entries.length === 0) {
            return prefix === "" ? [] : [{ name: prefix, value: "{}" }];
        }

        return entries.flatMap(([key, child]) =>
            storedProperties(child, prefix === "" ? key : `${prefix}.${key}`),
        );
    }
    if (prefix === "") return [];
    if (value === null) return [{ name: prefix, value: "—" }];
    if (typeof value === "boolean") return [{ name: prefix, value: value ? "yes" : "no" }];
    if (typeof value === "string" || typeof value === "number") {
        return [{ name: prefix, value: String(value) }];
    }

    return [{ name: prefix, value: "—" }];
}

/** Name and value wrap, so a long path or request id can be read on a phone without a hover title. */
function DetailList({ rows }: { rows: readonly DetailRow[] }) {
    return (
        <div className="activity-detail">
            {rows.map((row) => (
                <div key={row.name} className="activity-detail-row">
                    <span className="activity-detail-key text-dim">{row.name}</span>
                    <span
                        data-activity-value={row.name}
                        className={`activity-detail-value selectable${row.warn ? " text-yellow" : ""}`}
                    >
                        {row.value}
                    </span>
                </div>
            ))}
        </div>
    );
}

function openNode(name: string, node: Node | undefined, go: ReturnType<typeof useGo>): ReactNode {
    if (node === undefined) return name;

    return (
        <span className="link" onClick={() => go.record("nodes", node)}>
            {name}
        </span>
    );
}

function activityProperties(
    row: Activity,
    nodes: readonly Node[],
    go: ReturnType<typeof useGo>,
): DetailRow[] {
    const caller = nodes.find((node) => node.id === row.caller_node_id);
    const target = nodes.find((node) => node.id === row.target_node_id);

    return [
        { name: "Id", value: row.id },
        { name: "Command", value: row.command },
        { name: "Status", value: <ActivityStatusText status={row.status} /> },
        { name: "Time", value: formatActivityTime(row.occurred_at) },
        { name: "Duration", value: durationLabel(row.duration_ms) },
        {
            name: "Caller",
            value: openNode(nodeLabel(nodes, row.caller_node_id), caller, go),
        },
        { name: "Caller address", value: row.caller_ip ?? "—" },
        {
            name: "Target",
            value: openNode(nodeLabel(nodes, row.target_node_id), target, go),
        },
        { name: "Error", value: row.error_code ?? "—", warn: row.error_code !== null },
        { name: "Exit code", value: row.exit_code ?? "—" },
        { name: "Subject", value: subjectLabel(row) },
        { name: "Request", value: row.request_id },
    ];
}

/** One Activity from `activity:show`. The list payload is not this view, so `properties` come from the show. */
export function ActivityDetail() {
    const { id } = useParams({ from: "/activity/$id" });
    const search = useSearch({ from: "/activity/$id" });
    const router = useRouter();
    const go = useGo();
    const fleet = useFleet();
    const detail = useQuery({ ...activityQuery(id), refetchInterval: useTaskPoll() });
    const row = detail.data;
    const properties = storedProperties(row?.properties ?? {});
    const back = () => {
        void router.navigate({ to: "/activity", search });
    };

    return (
        <div className="flex min-w-0 flex-col gap-y-[var(--panel-gap)]">
            <PageHeader
                trail={[
                    { label: "Activity", open: back },
                    { label: row?.command ?? `Activity ${id}` },
                ]}
            />
            {detail.error !== null && row === undefined ? (
                <ActivityError error={detail.error} retry={() => void detail.refetch()} />
            ) : row === undefined ? (
                <p role="status">Loading activity…</p>
            ) : (
                <div className="grid grid-cols-1 gap-[var(--panel-gap)] md:grid-cols-2">
                    <Frame title="Activity" className="w-full">
                        <DetailList rows={activityProperties(row, fleet.nodes, go)} />
                    </Frame>
                    <Frame title="Properties" className="w-full">
                        {properties.length === 0 ? (
                            <Note>None.</Note>
                        ) : (
                            <DetailList rows={properties} />
                        )}
                    </Frame>
                </div>
            )}
        </div>
    );
}
