import "@xyflow/react/dist/base.css";
import {
    Background,
    BackgroundVariant,
    Controls,
    Handle,
    MarkerType,
    Position,
    ReactFlow,
    type Edge,
    type Node,
    type NodeProps,
} from "@xyflow/react";
import { useMemo, useRef } from "react";
import { formatCompactCount, formatDurationMs } from "../api/tasks";
import { layout } from "./layout";
import { modelsOf, type RunTask, type TaskTemplate, type TemplateRun, type TemplateTask } from "./model";

/** Card size and the space between cards, in canvas pixels. */
const WIDTH = 300;
const HEIGHT = 102;
const GAP_X = 72;
const GAP_Y = 56;
const END_WIDTH = 120;
const END_HEIGHT = 28;
/** Space around the flow when it opens, and the smallest zoom it opens at. */
const MARGIN = 24;
const MIN_START_ZOOM = 0.8;

type TaskData = {
    task: TemplateTask;
    runTask: RunTask | undefined;
    inRun: boolean;
    selected: boolean;
};
type EndData = { end: "complete" | "fail"; state: "plain" | "taken" | "idle" };

const nodeTypes = { task: TaskNode, end: EndNode };

/**
 * A template on a canvas. The main path runs down the middle column; detours and failure paths
 * branch to the side. Pan by dragging, zoom with the wheel or a pinch.
 */
export function FlowCanvas({
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
    const wrapper = useRef<HTMLDivElement>(null);
    const { nodes, edges, width } = useMemo(() => {
        const result = layout(template, run);
        const columns = Math.max(...result.nodes.map((node) => node.column)) + 1;
        const runTasks = new Map(run?.tasks.map((task) => [task.key, task]));
        const endState = new Map(result.edges.map((edge) => [edge.target, edge.state]));
        const nodes: Node[] = result.nodes.map((node) => {
            const x = node.column * (WIDTH + GAP_X);
            const y = node.rank * (HEIGHT + GAP_Y);
            return node.type === "task"
                ? {
                      id: node.id,
                      type: "task",
                      position: { x, y },
                      data: {
                          task: node.task,
                          runTask: runTasks.get(node.task.key),
                          inRun: run !== undefined,
                          selected: node.task.key === selected,
                      } satisfies TaskData,
                  }
                : {
                      id: node.id,
                      type: "end",
                      position: { x: x + (WIDTH - END_WIDTH) / 2, y: y + (HEIGHT - END_HEIGHT) / 2 },
                      data: { end: node.end, state: endState.get(node.id) ?? "plain" } satisfies EndData,
                  };
        });
        const edges: Edge[] = result.edges.map((edge) => ({
            id: edge.id,
            source: edge.source,
            target: edge.target,
            type: "smoothstep",
            label: edge.label ?? undefined,
            className: "flow-edge",
            data: { state: edge.state },
            markerEnd: { type: MarkerType.ArrowClosed, width: 14, height: 14 },
            labelBgPadding: [4, 2] as [number, number],
            labelBgBorderRadius: 2,
            ...(edge.state === "taken" ? { zIndex: 1 } : {}),
            ariaLabel: `${edge.label ?? "passed"} route`,
        })).map((edge) => ({ ...edge, className: `flow-edge flow-edge-${edge.data.state}` }));
        return { nodes, edges, width: columns * WIDTH + (columns - 1) * GAP_X };
    }, [template, run, selected]);

    return (
        <div ref={wrapper} className="flow-canvas h-[70vh] w-full lg:h-full" data-testid="flow-canvas">
            <ReactFlow
                key={template.name}
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                onNodeClick={(_, node) => {
                    if (node.type === "task") onSelect(node.id);
                }}
                nodesDraggable={false}
                nodesConnectable={false}
                elementsSelectable={false}
                onInit={(instance) => {
                    // Start at the first task. Shrink only while the cards stay readable; a
                    // flow wider than that starts at the main path, and the branches pan in.
                    const available = (wrapper.current?.clientWidth ?? width) - 2 * MARGIN;
                    const zoom = Math.min(1, Math.max(MIN_START_ZOOM, available / width));
                    const x = Math.max(MARGIN, (available + 2 * MARGIN - width * zoom) / 2);
                    void instance.setViewport({ x, y: MARGIN, zoom });
                }}
                minZoom={0.3}
                maxZoom={1.5}
                proOptions={{ hideAttribution: true }}
                colorMode="dark"
            >
                <Background variant={BackgroundVariant.Dots} gap={24} size={1} />
                <Controls showInteractive={false} position="bottom-right" />
            </ReactFlow>
        </div>
    );
}

function TaskNode({ data }: NodeProps<Node<TaskData>>) {
    const { task, runTask, inRun, selected } = data;
    const models = modelLine(task, runTask);
    return (
        <div
            className="flow-card"
            style={{ width: WIDTH, height: HEIGHT }}
            data-state={inRun ? cardState(runTask) : "plain"}
            data-selected={selected || undefined}
            data-testid="flow-card"
            aria-label={`${task.kind} task: ${task.title}`}
        >
            <Handle type="target" position={Position.Top} className="flow-handle" />
            <span className="flex items-baseline justify-between gap-[1ch]">
                <span className="flow-kind" data-kind={task.kind}>
                    {task.kind}
                </span>
                <span className="text-dim">{task.key}</span>
            </span>
            <span className="block truncate">{task.title}</span>
            <span className="flow-models" data-empty={models === null || undefined}>
                {models ?? "no model"}
            </span>
            <span className="flow-meta">{inRun ? runMeta(runTask) : templateMeta(task)}</span>
            <Handle type="source" position={Position.Bottom} className="flow-handle" />
        </div>
    );
}

function EndNode({ data }: NodeProps<Node<EndData>>) {
    return (
        <div
            className="flow-end-node"
            style={{ width: END_WIDTH, height: END_HEIGHT }}
            data-end={data.end}
            data-state={data.state}
        >
            <Handle type="target" position={Position.Top} className="flow-handle" />
            {data.end}
        </div>
    );
}

/** The models a card names: the ones a run used, else the template's. */
function modelLine(task: TemplateTask, runTask: RunTask | undefined): string | null {
    const [implementer, reviewer] =
        runTask?.implementer_model !== undefined
            ? [runTask.implementer_model, runTask.reviewer_model]
            : modelsOf(task);
    if (implementer === undefined) return null;
    return reviewer === undefined ? implementer : `${implementer} → ${reviewer}`;
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
    if (duration !== null) parts.push(duration);
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
    if (duration !== null) parts.push(`~${duration}`);
    const tokens = formatCompactCount(stats.avg_tokens);
    if (tokens !== null) parts.push(`~${tokens} tok`);
    return [detail, parts.join(" · ")].filter(Boolean).join(" · ");
}
