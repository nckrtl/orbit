import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { useLocation, useParams, useRouter, useSearch } from "@tanstack/react-router";
import { useVirtualizer, type VirtualItem } from "@tanstack/react-virtual";
import {
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
    type CSSProperties,
    type ReactNode,
} from "react";
import {
    activitiesQuery,
    activityQuery,
    rebuildActivityList,
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
import { type Column } from "../ui/Pane";
import { panes, selectionKey, ui, useUi } from "../ui/store";

const STATUSES = ["running", "succeeded", "failed"] as const;
const ROW = 20; // --lh. Desktop rows are one line, so the virtualizer can trust the estimate.
const CARD = 104; // A phone card is four lines plus its padding, until it is measured.
const NEAR_END_ROWS = 5;
const TOP = 1;

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

function withFilters(
    filters: ActivityListFilters,
    patch: ActivityListFilters,
): ActivityListFilters {
    const next: ActivityListFilters = { ...filters, ...patch };
    for (const key of Object.keys(next) as (keyof ActivityListFilters)[]) {
        if (next[key] === undefined) delete next[key];
    }

    return next;
}

function filtersKey(filters: ActivityListFilters): string {
    return JSON.stringify(filters);
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
    onChange,
}: {
    search: ActivityListFilters;
    nodes: readonly Node[];
    stacked: boolean;
    onChange: (patch: ActivityListFilters) => void;
}) {
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

type ScrollPlace = {
    atTop: boolean;
    anchorId: number | null;
    /** The log's scroll offset while this anchor was the first visible row. */
    scrollTop: number;
    /** Pixels from the top of the anchor row down to the first visible line. */
    offset: number;
    pendingIds: number[];
    knownIds: number[];
    /** Measured rows. A remount passes these back so variable cards keep their positions. */
    measurements: VirtualItem[];
};

const EMPTY_MEASUREMENTS: VirtualItem[] = [];

/**
 * Scroll places survive the detail page, so Back returns to the same row.
 * Bumping the generation makes a reset ignore a log that unmounts afterwards.
 */
const scrollPlaces = new Map<string, ScrollPlace>();
let scrollGeneration = 0;

export function resetActivityScroll(): void {
    scrollGeneration += 1;
    scrollPlaces.clear();
}

function sameIds(left: readonly { id: number }[], right: readonly { id: number }[]): boolean {
    if (left.length !== right.length) return false;

    return left.every((row, index) => row.id === right[index]?.id);
}

/**
 * The row that should sit where the anchor sat. The anchor itself when it is still loaded,
 * otherwise the next older row, and the next newer row when nothing older remains.
 */
function anchorIndex(rows: readonly { id: number }[], anchorId: number): number {
    const exact = rows.findIndex((row) => row.id === anchorId);
    if (exact !== -1) return exact;

    const older = rows.findIndex((row) => row.id < anchorId);
    if (older !== -1) return older;

    return Math.max(0, rows.length - 1);
}

/**
 * Scroll offset that puts the anchor the same distance from the visible line.
 * Sizes come from the saved snapshot, then the estimate, so the row can be
 * sought before it is mounted.
 */
function restoredScroll(
    rows: readonly { id: number }[],
    anchorId: number,
    offset: number,
    measurements: readonly { key: string | number | bigint; size: number }[],
    estimate: number,
): number {
    const sizes = new Map<string | number | bigint, number>();
    for (const item of measurements) sizes.set(item.key, item.size);
    const index = anchorIndex(rows, anchorId);
    let start = 0;
    for (let cursor = 0; cursor < index; cursor += 1) {
        const id = rows[cursor]?.id;
        const measured = id === undefined ? undefined : sizes.get(id);
        start += typeof measured === "number" ? measured : estimate;
    }

    return Math.max(0, start - offset);
}

function headHeight(element: HTMLElement): number {
    const head = element.querySelector("[data-head]");

    return head instanceof HTMLElement ? head.offsetHeight : 0;
}

/** The first line a reader can see: below the sticky head on a desktop, the top of the log on a phone. */
function visibleLine(element: HTMLElement): number {
    return element.getBoundingClientRect().top + headHeight(element);
}

/** The first row that still shows below that line, and how far its top sits from the line. */
function anchorOnScreen(element: HTMLElement): { id: number; offset: number } | null {
    const line = visibleLine(element);
    const row = [...element.querySelectorAll<HTMLElement>("[data-activity-id]")].find(
        (candidate) => candidate.getBoundingClientRect().bottom > line + 1,
    );
    if (row === undefined) return null;
    const id = Number(row.dataset.activityId);
    if (!Number.isInteger(id)) return null;

    return { id, offset: row.getBoundingClientRect().top - line };
}

/** Scroll so row `id` sits the same distance from the visible line as it did when it was anchored. */
function scrollKeepingRow(element: HTMLElement, id: number, offset: number): number | null {
    const row = element.querySelector<HTMLElement>(`[data-activity-id="${id}"]`);
    if (row === null) return null;

    return element.scrollTop + row.getBoundingClientRect().top - (visibleLine(element) + offset);
}

/** Rows added above the anchor while the reader is away from the top, plus any still pending. */
function pendingAfter(
    rows: readonly { id: number }[],
    anchorAt: number,
    known: ReadonlySet<number>,
    pending: ReadonlySet<number>,
): Set<number> {
    const present = new Set(rows.map((row) => row.id));
    const next = new Set<number>();
    for (const id of pending) {
        if (present.has(id)) next.add(id);
    }
    for (let index = 0; index < anchorAt; index += 1) {
        const id = rows[index]?.id;
        if (id !== undefined && !known.has(id)) next.add(id);
    }

    return next;
}

function columnTemplate(columns: readonly Column<Activity>[]): string {
    return columns.map((column) => `minmax(0, ${column.width}fr)`).join(" ");
}

function cellClass(column: Column<Activity> | undefined, index: number, last: number): string {
    const alignRight = column?.align === "right" || index === last;

    return `min-w-0 ${alignRight ? "text-right" : ""}`;
}

/**
 * The continuous log. TanStack Virtual draws the rows that are on screen. New rows keep the
 * anchor row where it is, and `N new` counts the ones that landed above it.
 */
function ActivityLog({
    rows,
    columns,
    nodes,
    onDesktop,
    placeKey,
    restoreOnMount,
    hasNextPage,
    isFetchingNextPage,
    isFetchNextPageError,
    onNearEnd,
    onRetry,
    onOpen,
    onReady,
}: {
    rows: Activity[];
    columns: Column<Activity>[];
    nodes: readonly Node[];
    onDesktop: boolean;
    placeKey: string;
    restoreOnMount: boolean;
    hasNextPage: boolean;
    isFetchingNextPage: boolean;
    isFetchNextPageError: boolean;
    onNearEnd: () => void;
    onRetry: () => void;
    onOpen: (row: Activity) => void;
    onReady: () => void;
}) {
    const scroller = useRef<HTMLDivElement | null>(null);
    const rowsRef = useRef(rows);
    rowsRef.current = rows;
    const pendingRef = useRef<Set<number>>(new Set());
    const adjustingRef = useRef(false);
    const previousRows = useRef<Activity[]>([]);
    const placeRef = useRef<ScrollPlace>({
        atTop: true,
        anchorId: null,
        scrollTop: 0,
        offset: 0,
        pendingIds: [],
        knownIds: [],
        measurements: EMPTY_MEASUREMENTS,
    });
    const pinned = useRef<ScrollPlace | null>(null);
    const generation = useRef(scrollGeneration);
    const [saved] = useState(() => (restoreOnMount ? (scrollPlaces.get(placeKey) ?? null) : null));
    const savedRef = useRef(saved);
    const pendingRestore = useRef<{ anchorId: number; offset: number } | null>(
        saved !== null && !saved.atTop && saved.anchorId !== null
            ? { anchorId: saved.anchorId, offset: saved.offset }
            : null,
    );
    const [fresh, setFresh] = useState(saved?.pendingIds.length ?? 0);
    const pathname = useLocation().pathname;
    const selection = selectionKey(pathname, "activity");
    const focused = useUi((state) => state.focus === "activity");
    const hovered = useUi((state) => state.focus === null && state.hover === "activity");
    const selectedIndex = useUi((state) => state.selected[selection] ?? 0);
    const selected = Math.min(selectedIndex, Math.max(0, rows.length - 1));
    const header = onDesktop ? ROW : 0;
    const estimate = onDesktop ? ROW : CARD;
    const virtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => scroller.current,
        estimateSize: () => estimate,
        overscan: 8,
        scrollMargin: header,
        scrollPaddingStart: header,
        initialRect: { width: 1280, height: 480 },
        initialOffset:
            saved !== null && !saved.atTop && saved.anchorId !== null
                ? restoredScroll(rows, saved.anchorId, saved.offset, saved.measurements, estimate)
                : 0,
        initialMeasurementsCache: saved?.measurements ?? EMPTY_MEASUREMENTS,
        getItemKey: (index) => rows[index]?.id ?? index,
        measureElement: (element) => (onDesktop ? ROW : element.getBoundingClientRect().height),
    });
    const virtualRef = useRef(virtualizer);
    virtualRef.current = virtualizer;
    const pageRef = useRef({ hasNextPage, isFetchingNextPage, isFetchNextPageError, onNearEnd });
    pageRef.current = { hasNextPage, isFetchingNextPage, isFetchNextPageError, onNearEnd };
    const items = virtualizer.getVirtualItems();
    const margin = virtualizer.options.scrollMargin;
    const paddingTop = items.length > 0 ? Math.max(0, items[0]!.start - margin) : 0;
    const paddingBottom =
        items.length > 0
            ? Math.max(0, virtualizer.getTotalSize() - (items[items.length - 1]!.end - margin))
            : 0;
    const template = columnTemplate(columns);
    const grid = { gridTemplateColumns: template, columnGap: "3ch" } as CSSProperties;
    const endIndex = virtualizer.range?.endIndex ?? -1;

    const placeFor = (
        current: readonly Activity[],
        atTop: boolean,
        anchorId: number | null,
        scrollTop: number,
        offset: number,
        pending: ReadonlySet<number>,
    ): ScrollPlace => ({
        atTop,
        anchorId,
        scrollTop,
        offset,
        pendingIds: [...pending],
        knownIds: current.map((row) => row.id),
        measurements: EMPTY_MEASUREMENTS,
    });
    const keptScroll = (element: HTMLElement, anchorId: number, offset: number): number | null => {
        const index = anchorIndex(rowsRef.current, anchorId);
        const id = rowsRef.current[index]?.id;
        if (id === undefined) return null;

        return scrollKeepingRow(element, id, offset);
    };
    const sizeOf = (id: number): number => {
        const measured = virtualizer.itemSizeCache.get(id);

        return typeof measured === "number" ? measured : estimate;
    };
    // How far the anchor's top moved because rows above it were added or removed.
    // A removed anchor contributes nothing: the next older row slides up into its place.
    const anchorShift = (previousIds: readonly number[], anchorId: number): number => {
        const oldIndex = previousIds.indexOf(anchorId);
        if (oldIndex < 0) return 0;
        const nextIds = rows.map((row) => row.id);
        const newIndex = anchorIndex(rows, anchorId);
        let oldStart = 0;
        for (let index = 0; index < oldIndex; index += 1) {
            const id = previousIds[index];
            if (id !== undefined) oldStart += sizeOf(id);
        }
        let newStart = 0;
        for (let index = 0; index < newIndex; index += 1) {
            const id = nextIds[index];
            if (id !== undefined) newStart += sizeOf(id);
        }

        return newStart - oldStart;
    };
    // After layout, so the virtualizer's scroll handler does not re-render the log in the middle of it.
    const applyScroll = (top: number) => {
        queueMicrotask(() => {
            const element = scroller.current;
            if (element === null || Math.abs(element.scrollTop - top) <= 0.5) return;

            adjustingRef.current = true;
            element.scrollTop = top;
            adjustingRef.current = false;
        });
    };

    useLayoutEffect(() => {
        scroller.current?.setAttribute("data-activity-log", "");
        onReady();
    }, [onReady]);

    useLayoutEffect(() => {
        const seen = generation.current;

        return () => {
            if (seen !== scrollGeneration) return;

            const pinnedPlace = pinned.current;
            scrollPlaces.set(placeKey, {
                ...(pinnedPlace ?? placeRef.current),
                pendingIds: [...(pinnedPlace?.pendingIds ?? pendingRef.current)],
                knownIds: pinnedPlace?.knownIds ?? rowsRef.current.map((row) => row.id),
                measurements:
                    pinnedPlace !== null && pinnedPlace.measurements.length > 0
                        ? pinnedPlace.measurements
                        : virtualRef.current.takeSnapshot(),
            });
        };
    }, [placeKey]);

    useLayoutEffect(() => {
        const element = scroller.current;
        if (element === null) return;

        const previous = previousRows.current;
        const savedPlace = savedRef.current;
        if (savedPlace !== null) {
            savedRef.current = null;
            previousRows.current = rows;
            if (savedPlace.atTop || savedPlace.anchorId === null || rows.length === 0) {
                applyScroll(0);
                pendingRef.current = new Set();
                setFresh(0);
                placeRef.current = placeFor(
                    rows,
                    true,
                    rows[0]?.id ?? null,
                    0,
                    0,
                    pendingRef.current,
                );

                return;
            }

            const index = anchorIndex(rows, savedPlace.anchorId);
            const pending = pendingAfter(
                rows,
                index,
                new Set(savedPlace.knownIds),
                new Set(savedPlace.pendingIds),
            );
            const sought = restoredScroll(
                rows,
                savedPlace.anchorId,
                savedPlace.offset,
                savedPlace.measurements,
                estimate,
            );
            const restored = keptScroll(element, savedPlace.anchorId, savedPlace.offset) ?? sought;
            applyScroll(restored);
            pendingRef.current = pending;
            setFresh(pending.size);
            placeRef.current = placeFor(
                rows,
                false,
                rows[index]?.id ?? null,
                restored,
                savedPlace.offset,
                pending,
            );

            return;
        }

        if (sameIds(previous, rows)) return;

        previousRows.current = rows;
        if (previous.length === 0 || rows.length === 0) {
            placeRef.current = placeFor(
                rows,
                element.scrollTop <= TOP,
                rows[0]?.id ?? null,
                element.scrollTop,
                0,
                pendingRef.current,
            );

            return;
        }

        if (element.scrollTop <= TOP) {
            pendingRef.current = new Set();
            setFresh(0);
            placeRef.current = placeFor(rows, true, rows[0]?.id ?? null, 0, 0, pendingRef.current);

            return;
        }

        const anchorId = placeRef.current.anchorId;
        if (anchorId === null) return;

        const index = anchorIndex(rows, anchorId);
        const pending = pendingAfter(
            rows,
            index,
            new Set(previous.map((row) => row.id)),
            pendingRef.current,
        );
        const shift = anchorShift(
            previous.map((row) => row.id),
            anchorId,
        );
        // A removed tail clamps scroll on its own. Don't apply that part twice.
        const already = element.scrollTop - placeRef.current.scrollTop;
        const clamp = already < 0 && already >= shift ? already : 0;
        const kept = element.scrollTop + shift - clamp;
        applyScroll(kept);
        pendingRef.current = pending;
        setFresh(pending.size);
        placeRef.current = placeFor(
            rows,
            false,
            rows[index]?.id ?? null,
            kept ?? element.scrollTop,
            placeRef.current.offset,
            pending,
        );
    }, [rows, onDesktop]);

    // The anchor may sit outside the first window, and new cards above it are
    // measured a frame later. Keep seeking until that row is on its line.
    useLayoutEffect(() => {
        if (pendingRestore.current === null) return;

        let frames = 0;
        let stable = 0;
        let pending = 0;
        const align = () => {
            frames += 1;
            const element = scroller.current;
            const goal = pendingRestore.current;
            if (element === null || goal === null) return;
            const index = anchorIndex(rowsRef.current, goal.anchorId);
            const id = rowsRef.current[index]?.id;
            if (id === undefined) {
                pendingRestore.current = null;

                return;
            }
            const fromDom = scrollKeepingRow(element, id, goal.offset);
            const aligned = virtualRef.current.getOffsetForIndex(index, "start");
            const fromIndex = aligned === undefined ? null : Math.max(0, aligned[0] - goal.offset);
            // A zero from the virtualizer means it has not measured the scrollport yet.
            const target = fromDom ?? (fromIndex !== null && fromIndex > 1 ? fromIndex : null);
            if (target !== null) {
                applyScroll(target);
                placeRef.current = {
                    ...placeRef.current,
                    atTop: false,
                    anchorId: id,
                    scrollTop: target,
                    offset: goal.offset,
                };
            }
            const row = element.querySelector<HTMLElement>(`[data-activity-id="${id}"]`);
            const drift =
                row === null
                    ? 999
                    : Math.abs(
                          row.getBoundingClientRect().top - (visibleLine(element) + goal.offset),
                      );
            stable = drift < 2 ? stable + 1 : 0;
            if (stable >= 2 || frames >= 10) {
                pendingRestore.current = null;

                return;
            }
            pending = requestAnimationFrame(align);
        };
        pending = requestAnimationFrame(align);

        return () => cancelAnimationFrame(pending);
    }, [rows]);

    useEffect(() => {
        const element = scroller.current;
        if (element === null) return;

        const nearEnd = () => {
            const page = pageRef.current;
            if (!page.hasNextPage || page.isFetchingNextPage || page.isFetchNextPageError) return;

            const rangeEnd = virtualRef.current.range?.endIndex ?? -1;
            const count = rowsRef.current.length;
            const distance = element.scrollHeight - element.scrollTop - element.clientHeight;
            const close =
                (count > 0 && rangeEnd >= count - NEAR_END_ROWS) ||
                distance <= NEAR_END_ROWS * estimate;
            if (close) page.onNearEnd();
        };
        let anchorFrame = 0;
        const rememberAnchor = () => {
            if (adjustingRef.current) return;
            if (element.scrollTop <= TOP) {
                pendingRef.current = new Set();
                setFresh(0);
                placeRef.current = placeFor(
                    rowsRef.current,
                    true,
                    rowsRef.current[0]?.id ?? null,
                    0,
                    0,
                    pendingRef.current,
                );

                return;
            }
            // The rows for this offset are painted after the scroll event.
            const anchor = anchorOnScreen(element);
            if (anchor === null) return;
            placeRef.current = placeFor(
                rowsRef.current,
                false,
                anchor.id,
                element.scrollTop,
                anchor.offset,
                pendingRef.current,
            );
        };
        const onScroll = () => {
            if (adjustingRef.current) return;
            nearEnd();
            if (element.scrollTop <= TOP) {
                rememberAnchor();

                return;
            }
            placeRef.current = {
                ...placeRef.current,
                atTop: false,
                scrollTop: element.scrollTop,
            };
            cancelAnimationFrame(anchorFrame);
            anchorFrame = requestAnimationFrame(rememberAnchor);
        };
        element.addEventListener("scroll", onScroll);
        nearEnd();

        return () => {
            cancelAnimationFrame(anchorFrame);
            element.removeEventListener("scroll", onScroll);
        };
    }, [
        endIndex,
        estimate,
        header,
        rows.length,
        hasNextPage,
        isFetchingNextPage,
        isFetchNextPageError,
    ]);

    const skipSelectionScroll = useRef(true);
    useEffect(() => {
        if (skipSelectionScroll.current) {
            skipSelectionScroll.current = false;

            return;
        }
        if (!focused) return;

        virtualRef.current.scrollToIndex(selected, { align: "auto" });
    }, [focused, selected]);

    useEffect(() => {
        panes.set("activity", {
            order: 1,
            count: rows.length,
            leaf: true,
            target: () => null,
            activate: (index) => {
                const row = rowsRef.current[index];
                if (row !== undefined) openAt(index, row);
            },
        });

        return () => void panes.delete("activity");
    });

    const openAt = (index: number, row: Activity) => {
        const element = scroller.current;
        const top = element?.scrollTop ?? placeRef.current.scrollTop;
        const atTop = top <= TOP;
        const anchor = element === null || atTop ? null : anchorOnScreen(element);
        pinned.current = {
            atTop,
            anchorId: anchor?.id ?? null,
            scrollTop: top,
            offset: anchor?.offset ?? 0,
            pendingIds: [...pendingRef.current],
            knownIds: rowsRef.current.map((candidate) => candidate.id),
            measurements: virtualizer.takeSnapshot(),
        };
        ui.set({ hover: "activity", focus: "activity" });
        ui.select(selection, index);
        onOpen(row);
    };
    const showNewest = () => {
        applyScroll(0);
        pendingRef.current = new Set();
        setFresh(0);
        placeRef.current = placeFor(rows, true, rows[0]?.id ?? null, 0, 0, pendingRef.current);
    };
    const windowStyle: CSSProperties = {
        paddingTop,
        paddingBottom,
        gridColumn: "1 / -1",
    };
    const rendered = items.map((item) => {
        const row = rows[item.index];
        if (row === undefined) return null;

        if (!onDesktop) {
            return (
                <button
                    key={row.id}
                    type="button"
                    data-index={item.index}
                    data-activity-id={row.id}
                    ref={virtualizer.measureElement}
                    aria-label={`Open activity ${row.id}`}
                    className="flex w-full flex-col items-start gap-y-[2px] border-b border-line py-[8px] text-left font-[inherit]"
                    onClick={() => openAt(item.index, row)}
                >
                    <span className="text-dim">{formatActivityTime(row.occurred_at)}</span>
                    <span className="font-bold">{row.command}</span>
                    <span className="flex flex-wrap gap-x-[1ch]">
                        <ActivityStatusText status={row.status} />
                        <span>{nodeLabel(nodes, row.caller_node_id)}</span>
                        <span aria-hidden="true">→</span>
                        <span>{nodeLabel(nodes, row.target_node_id)}</span>
                    </span>
                    <span className="text-dim">
                        {durationLabel(row.duration_ms)}
                        {row.error_code !== null && ` · ${row.error_code}`}
                    </span>
                </button>
            );
        }

        return (
            <div
                key={row.id}
                data-index={item.index}
                data-activity-id={row.id}
                ref={virtualizer.measureElement}
                className="row"
                role="row"
                aria-selected={item.index === selected}
                data-link=""
                data-selected={item.index === selected ? "" : undefined}
                data-focused={focused ? "" : undefined}
                data-warn={row.status === "failed" ? "" : undefined}
                style={grid}
                onMouseDown={(event) => {
                    event.stopPropagation();
                    if (event.button === 0) openAt(item.index, row);
                }}
            >
                {columns.map((column, cell) => (
                    <span
                        key={column.header}
                        role="cell"
                        className={cellClass(column, cell, columns.length - 1)}
                    >
                        {column.cell?.(row) ?? column.value(row)}
                    </span>
                ))}
            </div>
        );
    });

    return (
        <div className="relative flex min-h-0 min-w-0 flex-1 flex-col">
            <Frame
                title="Activity"
                className="min-h-0 w-full flex-1"
                pane="activity"
                state={focused ? "focused" : hovered ? "hovered" : undefined}
                bodyRef={scroller}
                onMouseDown={() => ui.set({ hover: "activity", focus: "activity" })}
            >
                {onDesktop ? (
                    <div role="table" aria-label="Activity" className="table-grid" style={grid}>
                        <div className="row" role="row" data-head style={grid}>
                            {columns.map((column, index) => (
                                <span
                                    key={column.header}
                                    role="columnheader"
                                    className={cellClass(column, index, columns.length - 1)}
                                >
                                    {column.header}
                                </span>
                            ))}
                        </div>
                        <div className="col-span-full" style={windowStyle}>
                            {rendered}
                        </div>
                    </div>
                ) : (
                    <div style={windowStyle}>{rendered}</div>
                )}
                {!isFetchNextPageError && !hasNextPage && (
                    <div data-activity-end="" className="py-[8px] text-dim">
                        End of the log.
                    </div>
                )}
            </Frame>
            {isFetchNextPageError && (
                <button
                    type="button"
                    className="absolute bottom-[12px] left-1/2 z-10 max-w-[90%] -translate-x-1/2 cursor-pointer border border-line bg-bg px-[1ch] text-left"
                    onClick={onRetry}
                >
                    Could not load older activity. Try again
                </button>
            )}
            {fresh > 0 && (
                <button
                    type="button"
                    data-activity-new=""
                    className="absolute top-[12px] left-1/2 z-10 -translate-x-1/2 cursor-pointer border border-line bg-bg px-[1ch] text-cyan"
                    onClick={showNewest}
                >
                    {fresh} new
                </button>
            )}
        </div>
    );
}

/** The Activity log: one continuous list, 50 rows at a time, filtered. The cursor is the next request. */
export function ActivityPage() {
    const search = useSearch({ from: "/activity" });
    const router = useRouter();
    const client = useQueryClient();
    const onDesktop = useDesktop();
    const fleet = useFleet();
    const poll = useTaskPoll();
    const list = useInfiniteQuery(activitiesQuery(search));
    const listRef = useRef(list);
    listRef.current = list;
    const rows = useMemo(() => list.data?.pages.flat() ?? [], [list.data]);
    const placeKey = filtersKey(search);
    const logMounted = useRef(false);
    const restoreOnMount = !logMounted.current;
    const markLog = useRef(() => {
        logMounted.current = true;
    });
    const columns = useMemo(() => activityColumns(fleet.nodes), [fleet.nodes]);

    const loadingOlder = useRef(false);
    const loadOlder = (manual = false) => {
        const current = listRef.current;
        if (loadingOlder.current || !current.hasNextPage || current.isFetchingNextPage) return;
        // A failed page stays failed until the reader asks again. Scrolling must not retry it.
        if (!manual && current.isFetchNextPageError) return;
        loadingOlder.current = true;
        void current.fetchNextPage().finally(() => {
            loadingOlder.current = false;
        });
    };
    const open = (row: Activity) => {
        void router.navigate({
            to: "/activity/$id",
            params: { id: String(row.id) },
            search,
        });
    };
    const applyFilters = (patch: ActivityListFilters) => {
        const next = withFilters(search, patch);
        if (JSON.stringify(next) === JSON.stringify(withFilters(search, {}))) return;
        void router.navigate({ to: "/activity", search: next });
    };

    useEffect(() => {
        const timer = setInterval(() => {
            if (document.hidden) return;
            void rebuildActivityList(client, search);
        }, poll);

        return () => clearInterval(timer);
    }, [client, poll, search]);

    return (
        <div className="flex h-full min-h-0 min-w-0 flex-col gap-y-[var(--panel-gap)]">
            <PageHeader trail={[{ label: "Activity" }]}>
                {onDesktop ? (
                    <ActivityFilters
                        search={search}
                        nodes={fleet.nodes}
                        stacked={false}
                        onChange={applyFilters}
                    />
                ) : undefined}
            </PageHeader>
            {list.error !== null && list.data === undefined ? (
                <ActivityError error={list.error} retry={() => void list.refetch()} />
            ) : list.data === undefined ? (
                <p role="status">Loading activity…</p>
            ) : (
                <>
                    {!onDesktop && (
                        <div className="px-[1ch]">
                            <ActivityFilters
                                search={search}
                                nodes={fleet.nodes}
                                stacked
                                onChange={applyFilters}
                            />
                        </div>
                    )}
                    {rows.length === 0 ? (
                        <Frame title="Activity" className="w-full" pane="activity">
                            <Note>No activity.</Note>
                        </Frame>
                    ) : (
                        <ActivityLog
                            key={placeKey}
                            rows={rows}
                            columns={columns}
                            nodes={fleet.nodes}
                            onDesktop={onDesktop}
                            placeKey={placeKey}
                            restoreOnMount={restoreOnMount}
                            hasNextPage={list.hasNextPage}
                            isFetchingNextPage={list.isFetchingNextPage}
                            isFetchNextPageError={list.isFetchNextPageError}
                            onNearEnd={() => loadOlder(false)}
                            onRetry={() => loadOlder(true)}
                            onOpen={open}
                            onReady={markLog.current}
                        />
                    )}
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
