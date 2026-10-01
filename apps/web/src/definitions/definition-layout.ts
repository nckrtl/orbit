import type { Definition, Edge, Subtask } from "./definition";
import { definitionDrawing, type DefinitionDrawing } from "./definition-phases";

/** A place on the canvas grid. `rank` counts rows down from the workspace. `column` counts right from the main path. */
export type Cell = { rank: number; column: number };

export type EngineStage = "workspace" | "pull-request" | "merge" | "cleanup";

export type LayoutNode =
    | ({ type: "subtask"; id: string; subtask: Subtask } & Cell)
    | ({
          type: "stage";
          id: string;
          stage: EngineStage;
          title: string;
          detail: string;
      } & Cell)
    | ({ type: "end"; id: string; end: "complete" | "fail" } & Cell);

export type LayoutEdge = {
    id: string;
    source: string;
    target: string;
    /** The outcome or option. Null for `passed`, and for a stage the engine always runs. */
    label: string | null;
    /**
     * Set when this route skips a card in its own column. The canvas draws that edge in the gap
     * to the right, and the number picks which lane when more than one route skips the same column.
     */
    lane: number | null;
};

export type Layout = {
    nodes: LayoutNode[];
    edges: LayoutEdge[];
    frames: DefinitionDrawing["frames"];
};

const STAGE_COPY: Record<EngineStage, { title: string; detail: string }> = {
    workspace: {
        title: "Start workspace",
        detail: "Orbit provisions the task workspace before the first subtask.",
    },
    "pull-request": {
        title: "Open pull request",
        detail: "After the last subtask, Orbit opens the pull request.",
    },
    merge: {
        title: "Merge",
        detail: "A person merges.",
    },
    cleanup: {
        title: "Clean up",
        detail: "Orbit removes the workspace.",
    },
};

/**
 * Lays a definition out top to bottom. The main path runs down column 0. Detours and failure paths
 * take the columns to its right, and each of those paths ends in its own complete or fail node.
 * Default routes to an end stay hidden. A route that skips a card in its own column is marked
 * with a lane so the canvas can draw it beside those cards. The engine's fixed stages sit around
 * the definition: the workspace before the first subtask, and the pull request, the merge, and the
 * cleanup after the last one. A person merges unless the definition has a merge subtask.
 */
export function layout(source: Definition, open: ReadonlySet<string> = new Set()): Layout {
    const view = definitionDrawing(source, open);
    const definition = view.definition;
    const all = view.edges;
    const subtasks = new Map(definition.subtasks.map((subtask) => [subtask.key, subtask]));
    const main = mainPath(definition, all);
    const lastMain = main.at(-1);
    const forward = (edge: Edge) => {
        const from = indexOf(definition.subtasks, edge.from);
        const to = indexOf(definition.subtasks, edge.to);
        return to === -1 || to > from;
    };

    const shown = all.filter((edge) => {
        if (!forward(edge)) return false;
        if (subtasks.has(edge.to)) return true;
        if (edge.implicit) return false;
        // The engine's own stages replace the main path's success end.
        if (
            edge.from === lastMain &&
            edge.to === "complete" &&
            isMainEdge(edge, subtasks.get(edge.from)!, all)
        ) {
            return false;
        }
        return true;
    });

    const rank = new Map<string, number>();
    definition.subtasks.forEach((subtask, index) => {
        if (!rank.has(subtask.key)) {
            rank.set(
                subtask.key,
                index === 0 ? 1 : (rank.get(definition.subtasks[index - 1]!.key) ?? 1) + 1,
            );
        }
        const previous = definition.subtasks[index - 1];
        if (previous !== undefined && previous.phase !== subtask.phase) {
            const floor =
                Math.max(
                    ...definition.subtasks.slice(0, index).map((item) => rank.get(item.key) ?? 1),
                ) + 1;
            rank.set(subtask.key, Math.max(rank.get(subtask.key)!, floor));
        }
        for (const edge of shown) {
            if (edge.from === subtask.key && subtasks.has(edge.to)) {
                rank.set(edge.to, Math.max(rank.get(edge.to) ?? 0, rank.get(subtask.key)! + 1));
            }
        }
    });

    const occupied = new Set<string>();
    const place = (cell: Cell): Cell => {
        let column = cell.column;
        while (occupied.has(`${cell.rank}:${column}`)) column += 1;
        occupied.add(`${cell.rank}:${column}`);
        return { rank: cell.rank, column };
    };

    const nodes: LayoutNode[] = [];
    const column = new Map<string, number>();
    for (const key of main) {
        const cell = place({ rank: rank.get(key) ?? 1, column: 0 });
        column.set(key, cell.column);
    }
    for (const subtask of definition.subtasks) {
        if (!column.has(subtask.key)) {
            const cell = place({ rank: rank.get(subtask.key) ?? 1, column: 1 });
            column.set(subtask.key, cell.column);
        }
        nodes.push({
            type: "subtask",
            id: subtask.key,
            subtask,
            rank: rank.get(subtask.key) ?? 1,
            column: column.get(subtask.key) ?? 0,
        });
    }

    const endColumn = Math.max(0, ...column.values()) + 1;
    const layoutEdges: LayoutEdge[] = [];
    for (const edge of shown) {
        let target = edge.to;
        if (!subtasks.has(edge.to)) {
            target = `end:${edge.from}:${edge.on}`;
            const onMain = main.includes(edge.from);
            const from = column.get(edge.from) ?? 0;
            const cell = place({
                rank: (rank.get(edge.from) ?? 1) + 1,
                column: onMain ? endColumn : from,
            });
            nodes.push({
                type: "end",
                id: target,
                end: edge.to === "fail" ? "fail" : "complete",
                ...cell,
            });
        }
        layoutEdges.push(
            link(
                `${edge.from}:${edge.on}->${target}`,
                edge.from,
                target,
                edge.on === "passed" ? null : edge.on,
            ),
        );
    }

    const first = main[0];
    if (first !== undefined) {
        const cell = place({ rank: 0, column: column.get(first) ?? 0 });
        nodes.push(stageNode("stage:workspace", "workspace", cell));
        layoutEdges.push(link("stage:workspace->start", "stage:workspace", first, null));
    }

    if (lastMain !== undefined) {
        const hasMerge = source.subtasks.some((subtask) => subtask.kind === "merge");
        const tail: EngineStage[] = [
            "pull-request",
            ...(hasMerge ? [] : (["merge"] as const)),
            "cleanup",
        ];
        let previous = lastMain;
        let nextRank = (rank.get(lastMain) ?? 1) + 1;
        const mainColumn = column.get(lastMain) ?? 0;
        for (const stage of tail) {
            const id = `stage:${stage}`;
            const cell = place({ rank: nextRank, column: mainColumn });
            nodes.push(stageNode(id, stage, cell));
            layoutEdges.push(link(`${previous}->${id}`, previous, id, null));
            previous = id;
            nextRank = cell.rank + 1;
        }
        const end = place({ rank: nextRank, column: mainColumn });
        const endId = "end:main:complete";
        nodes.push({ type: "end", id: endId, end: "complete", ...end });
        layoutEdges.push(link(`${previous}->${endId}`, previous, endId, null));
    }

    return { nodes, edges: withLanes(nodes, layoutEdges), frames: view.frames };
}

function link(id: string, source: string, target: string, label: string | null): LayoutEdge {
    return { id, source, target, label, lane: null };
}

/** A route that jumps over a card in its own column takes the next free lane to the right of that column. */
function withLanes(nodes: readonly LayoutNode[], edges: readonly LayoutEdge[]): LayoutEdge[] {
    const byId = new Map(nodes.map((node) => [node.id, node]));
    const taken = new Map<number, number>();
    return edges.map((edge) => {
        const source = byId.get(edge.source);
        const target = byId.get(edge.target);
        if (source === undefined || target === undefined || source.column !== target.column) {
            return edge;
        }
        const low = Math.min(source.rank, target.rank);
        const high = Math.max(source.rank, target.rank);
        const blocked = nodes.some(
            (node) => node.column === source.column && node.rank > low && node.rank < high,
        );
        if (!blocked) return edge;
        const lane = taken.get(source.column) ?? 0;
        taken.set(source.column, lane + 1);
        return { ...edge, lane };
    });
}

function stageNode(id: string, stage: EngineStage, cell: Cell): LayoutNode {
    return { type: "stage", id, stage, ...STAGE_COPY[stage], ...cell };
}

/**
 * The path taken when every subtask passes. A decide subtask follows its first option. That option
 * is the main path; the others are detours.
 */
export function mainPath(definition: Pick<Definition, "subtasks">, all: Edge[]): string[] {
    const subtasks = new Map(definition.subtasks.map((subtask) => [subtask.key, subtask]));
    const path: string[] = [];
    let current = definition.subtasks[0];
    while (current !== undefined && !path.includes(current.key)) {
        path.push(current.key);
        const subtask = current;
        const next = all.find(
            (edge) => edge.from === subtask.key && isMainEdge(edge, subtask, all),
        );
        current = next === undefined ? undefined : subtasks.get(next.to);
    }
    return path;
}

function isMainEdge(edge: Edge, subtask: Subtask, all: Edge[]): boolean {
    if (subtask.options === undefined) return edge.on === "passed";
    const leaving = new Set(
        all.filter((candidate) => candidate.from === subtask.key).map((candidate) => candidate.on),
    );
    const preferred = subtask.options.find((option) => leaving.has(option));
    return edge.on === preferred;
}

function indexOf(subtasks: readonly Subtask[], key: string): number {
    return subtasks.findIndex((subtask) => subtask.key === key);
}
