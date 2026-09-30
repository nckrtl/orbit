import "@xyflow/react/dist/base.css";
import {
    Background,
    BackgroundVariant,
    BaseEdge,
    Controls,
    EdgeLabelRenderer,
    Handle,
    MarkerType,
    Position,
    ReactFlow,
    getSmoothStepPath,
    type Edge,
    type EdgeProps,
    type Node,
    type NodeProps,
} from "@xyflow/react";
import { useMemo, useRef, type RefObject } from "react";
import type { Definition, Subtask } from "./definition";
import { modelsOf } from "./definition";
import { layout, type EngineStage, type LayoutNode } from "./definition-layout";
import { phaseKey } from "./definition-phases";

/** Card size and the space between cards, in canvas pixels. */
const WIDTH = 300;
const HEIGHT = 108;
const GAP_X = 72;
const GAP_Y = 56;
const END_WIDTH = 120;
const END_HEIGHT = 28;
const MARGIN = 24;
/** Low enough that a side column still fits a phone, high enough that a card stays readable. */
const MIN_START_ZOOM = 0.4;
/** How far a skipping route sits to the right of the card it leaves, and the step to the next lane. */
const LANE_OFFSET = 36;
const LANE_STEP = 20;

const FRAME_PAD = 16;
const FRAME_TITLE = 22;

type SubtaskData = { subtask: Subtask; selected: boolean; onActivate: () => void };
type StageData = {
    stage: EngineStage;
    title: string;
    detail: string;
    selected: boolean;
    onActivate: () => void;
};
type EndData = { end: "complete" | "fail" };
type FrameData = { title: string; repeat: boolean; onCollapse: () => void };
type LaneData = { offset: number };

const nodeTypes = {
    subtask: SubtaskNode,
    stage: StageNode,
    end: EndNode,
    frame: PhaseFrame,
};

const edgeTypes = {
    lane: LaneEdge,
};

const laneOffset = (lane: number) => LANE_OFFSET + lane * LANE_STEP;

/**
 * One definition on a canvas. The main path runs down the middle. Detours and failure paths sit to
 * the side. Pan by dragging. Pinch or the controls zoom, and the page still scrolls.
 */
export function DefinitionCanvas({
    definition,
    selected,
    open,
    onSelect,
    onTogglePhase,
}: {
    definition: Definition;
    selected?: string;
    open: ReadonlySet<string>;
    onSelect: (key: string) => void;
    onTogglePhase: (phase: string) => void;
}) {
    const wrapper = useRef<HTMLDivElement>(null);
    const onSelectRef = useRef(onSelect);
    const onToggleRef = useRef(onTogglePhase);
    onSelectRef.current = onSelect;
    onToggleRef.current = onTogglePhase;
    const graph = useMemo(() => {
        const result = layout(definition, open);
        const columns = Math.max(1, ...result.nodes.map((node) => node.column + 1));
        const nodes: Node[] = result.nodes.map((node) =>
            toFlowNode(node, selected, onSelectRef, onToggleRef),
        );
        for (const frame of result.frames) {
            const cells = result.nodes.filter(
                (node) => node.type === "subtask" && frame.keys.includes(node.id),
            );
            if (cells.length === 0) continue;
            const minColumn = Math.min(...cells.map((cell) => cell.column));
            const maxColumn = Math.max(...cells.map((cell) => cell.column));
            const minRank = Math.min(...cells.map((cell) => cell.rank));
            const maxRank = Math.max(...cells.map((cell) => cell.rank));
            const x = minColumn * (WIDTH + GAP_X) - FRAME_PAD;
            const y = minRank * (HEIGHT + GAP_Y) - FRAME_PAD - FRAME_TITLE;
            const right = (maxColumn + 1) * (WIDTH + GAP_X) - GAP_X + FRAME_PAD;
            const bottom = (maxRank + 1) * (HEIGHT + GAP_Y) - GAP_Y + FRAME_PAD;
            nodes.unshift({
                id: phaseKey(frame.phase.key),
                type: "frame",
                position: { x, y },
                style: { width: right - x, height: bottom - y },
                zIndex: -1,
                selectable: false,
                data: {
                    title: frame.phase.title,
                    repeat: frame.phase.repeat,
                    onCollapse: () => onTogglePhase(frame.phase.key),
                } satisfies FrameData,
            });
        }
        const edges: Edge[] = result.edges.map((edge) => ({
            id: edge.id,
            source: edge.source,
            target: edge.target,
            label: edge.label ?? undefined,
            className: "definition-edge",
            sourceHandle: edge.lane === null ? "out" : "side-out",
            targetHandle: edge.lane === null ? "in" : "side-in",
            type: edge.lane === null ? "smoothstep" : "lane",
            data: edge.lane === null ? undefined : { offset: laneOffset(edge.lane) },
            markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 },
            labelBgPadding: [4, 2] as [number, number],
            labelBgBorderRadius: 2,
        }));
        const rightmost = columns - 1;
        const outerLane = result.edges.reduce((furthest, edge) => {
            if (edge.lane === null) return furthest;
            const source = result.nodes.find((node) => node.id === edge.source);
            return source?.column === rightmost ? Math.max(furthest, edge.lane) : furthest;
        }, -1);
        return {
            nodes,
            edges,
            width:
                columns * WIDTH +
                (columns - 1) * GAP_X +
                (outerLane >= 0 ? laneOffset(outerLane) + 28 : 0),
        };
    }, [definition, open, selected]);

    return (
        <div
            ref={wrapper}
            className="definition-canvas h-[360px] w-full lg:h-full"
            data-testid="definition-canvas"
        >
            <ReactFlow
                key={`${definition.project_id}/${definition.name}:${[...open].sort().join(",")}`}
                nodes={graph.nodes}
                edges={graph.edges}
                nodeTypes={nodeTypes}
                edgeTypes={edgeTypes}
                nodesDraggable={false}
                nodesConnectable={false}
                elementsSelectable={false}
                nodesFocusable={false}
                edgesFocusable={false}
                zoomOnScroll={false}
                preventScrolling={false}
                onInit={(instance) => {
                    const available = (wrapper.current?.clientWidth ?? graph.width) - 2 * MARGIN;
                    const zoom = Math.min(1, Math.max(MIN_START_ZOOM, available / graph.width));
                    const x = Math.max(MARGIN, (available + 2 * MARGIN - graph.width * zoom) / 2);
                    void instance.setViewport({ x, y: MARGIN, zoom });
                }}
                minZoom={0.25}
                maxZoom={1.5}
                attributionPosition="bottom-left"
                colorMode="dark"
            >
                <Background variant={BackgroundVariant.Dots} gap={24} size={1} />
                <Controls showInteractive={false} position="bottom-right" />
            </ReactFlow>
        </div>
    );
}

function toFlowNode(
    node: LayoutNode,
    selected: string | undefined,
    onSelect: RefObject<(key: string) => void>,
    onToggle: RefObject<(phase: string) => void>,
): Node {
    const x = node.column * (WIDTH + GAP_X);
    const y = node.rank * (HEIGHT + GAP_Y);
    if (node.type === "end") {
        return {
            id: node.id,
            type: "end",
            position: { x: x + (WIDTH - END_WIDTH) / 2, y: y + (HEIGHT - END_HEIGHT) / 2 },
            data: { end: node.end } satisfies EndData,
        };
    }
    const onActivate = () => {
        if (node.type === "subtask" && node.subtask.kind === "phase" && node.subtask.phase) {
            onToggle.current(node.subtask.phase);
            return;
        }
        onSelect.current(node.id);
    };
    if (node.type === "stage") {
        return {
            id: node.id,
            type: "stage",
            position: { x, y },
            data: {
                stage: node.stage,
                title: node.title,
                detail: node.detail,
                selected: node.id === selected,
                onActivate,
            } satisfies StageData,
        };
    }
    return {
        id: node.id,
        type: "subtask",
        position: { x, y },
        data: {
            subtask: node.subtask,
            selected: node.id === selected,
            onActivate,
        } satisfies SubtaskData,
    };
}

/** A route that skips cards in its column, drawn through the gap on their right with the outcome beside it. */
function LaneEdge({
    sourceX,
    sourceY,
    targetX,
    targetY,
    label,
    markerEnd,
    data,
}: EdgeProps<Edge<LaneData>>) {
    const offset = data?.offset ?? LANE_OFFSET;
    const [path, labelX, labelY] = getSmoothStepPath({
        sourceX,
        sourceY,
        sourcePosition: Position.Right,
        targetX,
        targetY,
        targetPosition: Position.Right,
        offset,
        borderRadius: 4,
    });
    return (
        <>
            <BaseEdge path={path} markerEnd={markerEnd} />
            {label !== undefined && label !== null && (
                <EdgeLabelRenderer>
                    <span
                        className="definition-lane-label nodrag nopan"
                        data-testid="definition-edge-label"
                        style={{
                            transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
                        }}
                    >
                        {label}
                    </span>
                </EdgeLabelRenderer>
            )}
        </>
    );
}

function CardHandles() {
    return (
        <>
            <Handle id="in" type="target" position={Position.Top} className="definition-handle" />
            <Handle
                id="out"
                type="source"
                position={Position.Bottom}
                className="definition-handle"
            />
            <Handle
                id="side-in"
                type="target"
                position={Position.Right}
                className="definition-handle"
            />
            <Handle
                id="side-out"
                type="source"
                position={Position.Right}
                className="definition-handle"
            />
        </>
    );
}

function SubtaskNode({ data }: NodeProps<Node<SubtaskData>>) {
    const { subtask, selected, onActivate } = data;
    if (subtask.kind === "phase") return <PhaseCard subtask={subtask} onActivate={onActivate} />;
    const models = modelsOf(subtask);
    return (
        <button
            type="button"
            className="definition-card"
            style={{ width: WIDTH, height: HEIGHT }}
            data-selected={selected || undefined}
            data-testid="definition-card"
            aria-label={`${subtask.kind} subtask: ${subtask.title}`}
            onClick={(event) => {
                event.stopPropagation();
                onActivate();
            }}
        >
            <CardHandles />
            <span className="flex items-baseline justify-between gap-[1ch]">
                <span className="definition-kind" data-kind={subtask.kind}>
                    {subtask.kind}
                </span>
                <span className="text-dim">{subtask.key}</span>
            </span>
            <span className="block truncate">{subtask.title}</span>
            {models.length > 0 && <span className="definition-models">{models.join(" → ")}</span>}
            <span className="definition-meta">{subtaskMeta(subtask)}</span>
        </button>
    );
}

function PhaseCard({ subtask, onActivate }: { subtask: Subtask; onActivate: () => void }) {
    const inner = subtask.inner ?? [];
    const models = modelsOf(subtask);
    const kinds = [...new Set(inner.map((step) => step.kind))].join(" · ");
    return (
        <button
            type="button"
            className="definition-card definition-phase-card"
            style={{ width: WIDTH, height: HEIGHT }}
            data-testid="definition-phase"
            aria-label={`Phase: ${subtask.title}, ${inner.length} subtasks. Open it.`}
            title="Open this phase"
            onClick={(event) => {
                event.stopPropagation();
                onActivate();
            }}
        >
            <CardHandles />
            <span className="flex items-baseline justify-between gap-[1ch]">
                <span className="definition-kind" data-kind="phase">
                    phase
                </span>
                <span className="text-dim">{inner.length} subtasks</span>
            </span>
            <span className="block truncate">{subtask.title}</span>
            <span className="definition-models" data-empty={models.length === 0 || undefined}>
                {models.length > 0 ? models.join(" → ") : kinds || "phase"}
            </span>
            <span className="definition-meta">
                {subtask.phase !== undefined ? "Open to see the subtasks" : ""}
            </span>
        </button>
    );
}

function StageNode({ data }: NodeProps<Node<StageData>>) {
    return (
        <button
            type="button"
            className="definition-card"
            style={{ width: WIDTH, height: HEIGHT }}
            data-selected={data.selected || undefined}
            data-testid="definition-stage"
            data-stage={data.stage}
            aria-label={`Engine stage: ${data.title}`}
            onClick={(event) => {
                event.stopPropagation();
                data.onActivate();
            }}
        >
            <CardHandles />
            <span className="definition-kind" data-kind="stage">
                engine
            </span>
            <span className="block truncate">{data.title}</span>
            <span className="definition-meta">{data.detail}</span>
        </button>
    );
}

function PhaseFrame({ data }: NodeProps<Node<FrameData>>) {
    return (
        <div className="definition-phase-frame" data-testid="definition-phase-frame">
            <button
                type="button"
                className="definition-phase-title nodrag nopan"
                onClick={(event) => {
                    event.stopPropagation();
                    data.onCollapse();
                }}
                aria-label={`Collapse phase ${data.title}`}
            >
                {data.title}
                {data.repeat ? <span className="text-dim"> · repeats</span> : null}
            </button>
        </div>
    );
}

function EndNode({ data }: NodeProps<Node<EndData>>) {
    return (
        <div
            className="definition-end"
            style={{ width: END_WIDTH, height: END_HEIGHT }}
            data-end={data.end}
        >
            <Handle id="in" type="target" position={Position.Top} className="definition-handle" />
            <Handle
                id="side-in"
                type="target"
                position={Position.Right}
                className="definition-handle"
            />
            {data.end}
        </div>
    );
}

/** The three stages the engine runs inside every agent subtask. */
export const AGENT_STAGES = "Implementer · handoff check · reviewer";

function subtaskMeta(subtask: Subtask): string {
    if (subtask.kind === "agent") return AGENT_STAGES;
    if (subtask.kind === "decide") {
        return `${subtask.options?.join(" / ") ?? ""} · p ≥ ${subtask.min_probability ?? 0.8}`;
    }
    if (subtask.kind === "check") return commandsOfFirst(subtask) ?? "";
    if (subtask.kind === "action") return subtask.operation ?? "";
    if (subtask.kind === "merge") return "Merges the pull request";
    return "";
}

function commandsOfFirst(subtask: Subtask): string | undefined {
    return subtask.deliverables?.find((deliverable) => deliverable.type === "command")?.command;
}
