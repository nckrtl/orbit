import {
    edgeId,
    edges,
    takenEdges,
    type Edge,
    type TaskTemplate,
    type TemplateRun,
    type TemplateTask,
} from "./model";

/** A place on the canvas grid: `rank` counts rows down from the first task, `column` counts right from the main path. */
export type Cell = { rank: number; column: number };

export type LayoutNode =
    | ({ type: "task"; id: string; task: TemplateTask } & Cell)
    | ({ type: "end"; id: string; end: "complete" | "fail" } & Cell);

export type LayoutEdge = {
    id: string;
    source: string;
    target: string;
    /** The outcome or option, or null for `passed`, which the drawing leaves unlabelled. */
    label: string | null;
    state: "plain" | "taken" | "idle";
    /**
     * `left` when the route points back from the main column, `up` when it points back from a side
     * column, or null for a forward route.
     */
    loop: "left" | "up" | null;
};

export type Layout = { nodes: LayoutNode[]; edges: LayoutEdge[] };

/**
 * Lays a template out top to bottom. The main path runs down column 0; detours and failure paths
 * take the columns to its right; each route that ends the group gets its own end node one row
 * below its task. Default routes to an end stay hidden unless a run took them.
 */
export function layout(template: TaskTemplate, run?: TemplateRun): Layout {
    const all = edges(template);
    const taken = run === undefined ? null : takenEdges(template, run);
    const tasks = new Map(template.tasks.map((task) => [task.key, task]));
    const main = mainPath(template, all);
    const lastMain = main.at(-1);

    const shown = all.filter(
        (edge) =>
            tasks.has(edge.to) ||
            !edge.implicit ||
            taken?.has(edgeId(edge)) ||
            (edge.from === lastMain && isMainEdge(edge, tasks.get(edge.from)!, all)),
    );

    const position = new Map(template.tasks.map((task, index) => [task.key, index]));
    const isLoop = (edge: Edge) =>
        tasks.has(edge.to) && position.get(edge.to)! <= position.get(edge.from)!;

    // Ranks: the longest path from the first task over forward routes. Those only point forward, so
    // list order is a topological order. Loops do not change a rank.
    const rank = new Map<string, number>();
    template.tasks.forEach((task, index) => {
        if (!rank.has(task.key)) {
            rank.set(task.key, index === 0 ? 0 : (rank.get(template.tasks[index - 1]!.key) ?? 0) + 1);
        }
        for (const edge of shown) {
            if (edge.from === task.key && tasks.has(edge.to) && !isLoop(edge)) {
                rank.set(edge.to, Math.max(rank.get(edge.to) ?? 0, rank.get(task.key)! + 1));
            }
        }
    });

    const occupied = new Set<string>();
    const place = (cell: Cell): Cell => {
        let column = cell.column;
        while (occupied.has(`${cell.rank}:${column}`)) column++;
        occupied.add(`${cell.rank}:${column}`);
        return { rank: cell.rank, column };
    };

    const nodes: LayoutNode[] = [];
    const column = new Map<string, number>();
    for (const key of main) {
        const cell = place({ rank: rank.get(key)!, column: 0 });
        column.set(key, cell.column);
    }
    for (const task of template.tasks) {
        if (!column.has(task.key)) {
            const cell = place({ rank: rank.get(task.key)!, column: 1 });
            column.set(task.key, cell.column);
        }
        nodes.push({
            type: "task",
            id: task.key,
            task,
            rank: rank.get(task.key)!,
            column: column.get(task.key)!,
        });
    }

    // Ends that branch off the main path get a column right of every task, so a loop that rises in
    // a side column never runs through one.
    const endColumn = Math.max(0, ...column.values()) + 1;
    const layoutEdges: LayoutEdge[] = [];
    for (const edge of shown) {
        let target = edge.to;
        if (!tasks.has(edge.to)) {
            target = `end:${edgeId(edge)}`;
            const onMain = edge.from === lastMain && isMainEdge(edge, tasks.get(edge.from)!, all);
            const from = column.get(edge.from)!;
            const cell = place({
                rank: rank.get(edge.from)! + 1,
                column: onMain || !main.includes(edge.from) ? from : endColumn,
            });
            nodes.push({ type: "end", id: target, end: edge.to as "complete" | "fail", ...cell });
        }
        layoutEdges.push({
            id: edgeId(edge),
            source: edge.from,
            target,
            label: edge.on === "passed" ? null : edge.on,
            state: taken === null ? "plain" : taken.has(edgeId(edge)) ? "taken" : "idle",
            loop: !isLoop(edge)
                ? null
                : column.get(edge.from)! > column.get(edge.to)!
                  ? "up"
                  : "left",
        });
    }

    return { nodes, edges: layoutEdges };
}

/**
 * The path a run takes when every task passes: the `passed` route, or for a task with options the
 * option that past runs chose most, or its first option.
 */
export function mainPath(template: TaskTemplate, all: Edge[] = edges(template)): string[] {
    const tasks = new Map(template.tasks.map((task) => [task.key, task]));
    const path: string[] = [];
    let current = template.tasks[0];
    while (current !== undefined && !path.includes(current.key)) {
        path.push(current.key);
        const task = current;
        const next = all.find((edge) => edge.from === task.key && isMainEdge(edge, task, all));
        current = next === undefined ? undefined : tasks.get(next.to);
    }
    return path;
}

function isMainEdge(edge: Edge, task: TemplateTask, all: Edge[]): boolean {
    if (task.options === undefined) return edge.on === "passed";
    const options = all.filter((candidate) => candidate.from === task.key).map((e) => e.on);
    const counts = task.stats?.outcomes ?? {};
    const preferred = [...options].sort((a, b) => (counts[b] ?? 0) - (counts[a] ?? 0))[0];
    return edge.on === preferred;
}
