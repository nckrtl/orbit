import { useLayoutEffect, useRef, useState } from "react";
import { formatCompactCount, formatDurationMs } from "../api/tasks";
import {
    MODEL_KINDS,
    edgeId,
    edges,
    takenEdges,
    type Edge,
    type RunTask,
    type TaskTemplate,
    type TemplateRun,
    type TemplateTask,
} from "./model";

/** Horizontal room for one lane of forward jumps, in pixels. */
const LANE = 16;
/** Room between the cards and the first lane, for the outcome labels. */
const GUTTER = 64;

type Box = { top: number; bottom: number; right: number };

/**
 * A template as a vertical line of task cards. Routes to the next card are the connector between
 * them; routes that jump over cards run in lanes on the right; routes that end the group are chips
 * on the card.
 */
export function FlowDiagram({
    template,
    run,
    selected,
    onSelect,
}: {
    template: TaskTemplate;
    run?: TemplateRun;
    selected?: string;
    onSelect: (key: string) => void;
}) {
    const all = edges(template);
    const taken = run === undefined ? null : takenEdges(template, run);
    const index = new Map(template.tasks.map((task, position) => [task.key, position]));
    const jumps = all.filter((edge) => {
        const from = index.get(edge.from);
        const to = index.get(edge.to);
        return from !== undefined && to !== undefined && to > from + 1;
    });
    const lanes = assignLanes(jumps, index);
    const laneCount = Math.max(0, ...lanes.values()) + (jumps.length > 0 ? 1 : 0);
    const reserved = jumps.length > 0 ? GUTTER + laneCount * LANE : 0;

    const container = useRef<HTMLDivElement>(null);
    const cards = useRef(new Map<string, HTMLElement>());
    const [boxes, setBoxes] = useState<Map<string, Box>>(new Map());
    const [width, setWidth] = useState(0);

    useLayoutEffect(() => {
        const element = container.current;
        if (element === null) return;
        const measure = () => {
            const origin = element.getBoundingClientRect();
            const next = new Map<string, Box>();
            for (const [key, card] of cards.current) {
                const rect = card.getBoundingClientRect();
                next.set(key, {
                    top: rect.top - origin.top,
                    bottom: rect.bottom - origin.top,
                    right: rect.right - origin.left,
                });
            }
            setBoxes(next);
            setWidth(origin.width);
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => observer.disconnect();
    }, [template, run]);

    const runTasks = new Map(run?.tasks.map((task) => [task.key, task]));

    return (
        <div ref={container} className="relative" data-testid="flow-diagram">
            <ol className="flex flex-col" style={{ paddingRight: reserved }}>
                {template.tasks.map((task, position) => {
                    const next = template.tasks[position + 1];
                    const toNext =
                        next === undefined
                            ? []
                            : all.filter((edge) => edge.from === task.key && edge.to === next.key);
                    const ends = all.filter(
                        (edge) =>
                            edge.from === task.key &&
                            (edge.to === "complete" || edge.to === "fail") &&
                            (!edge.implicit ||
                                taken?.has(edgeId(edge)) ||
                                (next === undefined && edge.on === "passed")),
                    );
                    return (
                        <li key={task.key} className="flex flex-col">
                            <TaskCard
                                task={task}
                                runTask={runTasks.get(task.key)}
                                inRun={run !== undefined}
                                selected={selected === task.key}
                                ends={ends}
                                taken={taken}
                                onSelect={() => onSelect(task.key)}
                                cardRef={(element) => {
                                    if (element === null) cards.current.delete(task.key);
                                    else cards.current.set(task.key, element);
                                }}
                            />
                            {next !== undefined && (
                                <Connector edges={toNext} taken={taken} />
                            )}
                        </li>
                    );
                })}
            </ol>
            {jumps.length > 0 && boxes.size > 0 && (
                <svg
                    className="pointer-events-none absolute inset-0 overflow-visible"
                    width={width}
                    height="100%"
                    aria-hidden="true"
                >
                    <defs>
                        <marker
                            id="flow-arrow"
                            viewBox="0 0 8 8"
                            refX="7"
                            refY="4"
                            markerWidth="7"
                            markerHeight="7"
                            orient="auto-start-reverse"
                        >
                            <path d="M0,0 L8,4 L0,8 z" fill="context-stroke" />
                        </marker>
                    </defs>
                    {jumps.map((edge) => {
                        const from = boxes.get(edge.from);
                        const to = boxes.get(edge.to);
                        if (from === undefined || to === undefined) return null;
                        const x = from.right + GUTTER + (lanes.get(edgeId(edge)) ?? 0) * LANE;
                        const y1 = from.top + (from.bottom - from.top) / 2;
                        const y2 = to.top + Math.min(18, (to.bottom - to.top) / 2);
                        const state =
                            taken === null ? "plain" : taken.has(edgeId(edge)) ? "taken" : "idle";
                        return (
                            <g key={edgeId(edge)} className="flow-edge" data-state={state}>
                                <path
                                    d={`M${from.right},${y1} H${x} V${y2} H${to.right + 2}`}
                                    fill="none"
                                    markerEnd="url(#flow-arrow)"
                                />
                                <text x={from.right + 6} y={y1 - 4}>
                                    {edge.on}
                                </text>
                            </g>
                        );
                    })}
                </svg>
            )}
        </div>
    );
}

/** Lanes for the jumps, so no two overlapping jumps share one. Shorter jumps sit closer to the cards. */
function assignLanes(jumps: Edge[], index: Map<string, number>): Map<string, number> {
    const span = (edge: Edge) => [index.get(edge.from) ?? 0, index.get(edge.to) ?? 0] as const;
    const sorted = [...jumps].sort((a, b) => {
        const [a1, a2] = span(a);
        const [b1, b2] = span(b);
        return a2 - a1 - (b2 - b1) || a1 - b1;
    });
    const used: Array<Array<readonly [number, number]>> = [];
    const result = new Map<string, number>();
    for (const edge of sorted) {
        const [start, end] = span(edge);
        let lane = 0;
        while (used[lane]?.some(([s, e]) => start <= e && s <= end)) lane++;
        (used[lane] ??= []).push([start, end]);
        result.set(edgeId(edge), lane);
    }
    return result;
}

function Connector({ edges: toNext, taken }: { edges: Edge[]; taken: Set<string> | null }) {
    const state =
        toNext.length === 0
            ? "none"
            : taken === null
              ? "plain"
              : toNext.some((edge) => taken.has(edgeId(edge)))
                ? "taken"
                : "idle";
    const labels = toNext.map((edge) => edge.on).filter((on) => on !== "passed");
    return (
        <div className="flow-connector" data-state={state}>
            {state !== "none" && <span className="flow-connector-line" />}
            {labels.length > 0 && <span className="flow-connector-label">{labels.join(" · ")}</span>}
        </div>
    );
}

function TaskCard({
    task,
    runTask,
    inRun,
    selected,
    ends,
    taken,
    onSelect,
    cardRef,
}: {
    task: TemplateTask;
    runTask: RunTask | undefined;
    inRun: boolean;
    selected: boolean;
    ends: Edge[];
    taken: Set<string> | null;
    onSelect: () => void;
    cardRef: (element: HTMLButtonElement | null) => void;
}) {
    const state = inRun ? cardState(runTask) : "plain";
    const model = MODEL_KINDS.includes(task.kind);
    return (
        <button
            ref={cardRef}
            type="button"
            onClick={onSelect}
            className="flow-card"
            data-state={state}
            data-selected={selected || undefined}
            data-testid="flow-card"
            aria-label={`${task.kind} task: ${task.title}`}
        >
            <span className="flex items-baseline justify-between gap-[1ch]">
                <span className="flow-kind" data-kind={task.kind}>
                    {task.kind}
                    {model && <span title="Calls a model"> ◆</span>}
                </span>
                <span className="text-dim">{task.key}</span>
            </span>
            <span className="block truncate">{task.title}</span>
            <span className="flow-meta">{inRun ? runMeta(runTask) : templateMeta(task)}</span>
            {ends.length > 0 && (
                <span className="mt-[4px] flex flex-wrap gap-[1ch]">
                    {ends.map((edge) => (
                        <span
                            key={edgeId(edge)}
                            className="flow-end"
                            data-to={edge.to}
                            data-taken={taken?.has(edgeId(edge)) || undefined}
                        >
                            {edge.on} → {edge.to}
                        </span>
                    ))}
                </span>
            )}
        </button>
    );
}

function cardState(task: RunTask | undefined): string {
    if (task === undefined) return "todo";
    if (task.status === "cancelled") return "passed-over";
    if (task.status === "running" || task.status === "reviewing") return "running";
    if (task.outcome === "failed" || task.status === "failed") return "failed";
    if (task.outcome === "skipped") return "skipped";
    if (task.status === "completed") return "done";
    return "todo";
}

function runMeta(task: RunTask | undefined): string {
    if (task === undefined || task.status === "todo") return "waiting";
    if (task.status === "cancelled") return "passed over";
    if (task.status === "running" || task.status === "reviewing") return task.status;
    const parts = [task.outcome ?? task.status];
    if (task.probabilities !== undefined && task.outcome !== null) {
        const probability = task.probabilities[task.outcome];
        if (probability !== undefined) parts.push(`p ${probability.toFixed(2)}`);
    }
    const duration = formatDurationMs(task.duration_ms);
    if (duration !== null && duration !== "—") parts.push(duration);
    const tokens = formatCompactCount(task.tokens);
    if (tokens !== null) parts.push(`${tokens} tok`);
    return parts.join(" · ");
}

function templateMeta(task: TemplateTask): string {
    const detail =
        task.kind === "action"
            ? task.operation
            : task.kind === "check"
              ? task.commands?.[0]
              : task.kind === "decide"
                ? `${task.options?.join(" / ")} · p ≥ ${task.min_probability ?? 0.8}`
                : undefined;
    const stats = task.stats;
    if (stats === undefined) return detail ?? "";
    const parts = [`${stats.runs} runs`];
    const duration = formatDurationMs(stats.avg_duration_ms);
    if (duration !== null && duration !== "—") parts.push(`~${duration}`);
    const tokens = formatCompactCount(stats.avg_tokens);
    if (tokens !== null) parts.push(`~${tokens} tok`);
    return [detail, parts.join(" · ")].filter(Boolean).join(" · ");
}
