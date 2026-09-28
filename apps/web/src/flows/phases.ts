import { edges, type Edge, type Phase, type TaskTemplate, type TemplateTask } from "./model";

/** A template as the viewer draws it: collapsed phases replaced by one card each. */
export type FlowView = {
    template: TaskTemplate;
    edges: Edge[];
    /** The open phases and the keys of the tasks inside them. */
    frames: { phase: Phase; keys: string[] }[];
};

export const phaseKey = (phase: string) => `phase:${phase}`;

/**
 * Collapses every phase that is not in `open` into one card. Routes inside a collapsed phase are
 * hidden; routes that cross its edge attach to its card. A card's outcome keeps the name of the
 * route it stands for, prefixed with the inner task's key when two inner routes share a name.
 */
export function flowView(template: TaskTemplate, open: ReadonlySet<string>): FlowView {
    const phases = template.phases ?? [];
    const all = edges(template);
    if (phases.length === 0) return { template, edges: all, frames: [] };

    const collapsed = (task: TemplateTask) =>
        task.phase !== undefined && !open.has(task.phase) && phases.some((p) => p.key === task.phase);
    const groupOf = new Map(
        template.tasks.map((task) => [task.key, collapsed(task) ? phaseKey(task.phase!) : task.key]),
    );

    const tasks: TemplateTask[] = [];
    for (const task of template.tasks) {
        if (!collapsed(task)) {
            tasks.push(task);
            continue;
        }
        const key = phaseKey(task.phase!);
        if (tasks.some((candidate) => candidate.key === key)) continue;
        const phase = phases.find((candidate) => candidate.key === task.phase)!;
        tasks.push({
            key,
            title: phase.title,
            kind: "phase",
            brief: phase.brief,
            actor: phase.repeat,
            phase: phase.key,
            inner: template.tasks.filter((candidate) => candidate.phase === phase.key),
        });
    }

    const mapped: Edge[] = [];
    for (const edge of all) {
        const from = groupOf.get(edge.from)!;
        const to = groupOf.get(edge.to) ?? edge.to;
        if (from === to) continue;
        // A hidden default that ends the group stays hidden: it is no outcome of a phase card.
        if (from !== edge.from && edge.implicit && !groupOf.has(edge.to)) continue;
        const clash = mapped.find((m) => m.from === from && m.on === edge.on && m.to !== to);
        const on = clash === undefined || from === edge.from ? edge.on : `${edge.from} ${edge.on}`;
        if (mapped.some((m) => m.from === from && m.on === on && m.to === to)) continue;
        mapped.push({ from, to, on, implicit: edge.implicit });
    }

    // A collapsed card ends with the outcomes of the routes that leave it: forward routes to a
    // task first, so its main path runs down the page.
    const position = new Map(tasks.map((task, index) => [task.key, index]));
    for (const task of tasks) {
        if (task.kind !== "phase") continue;
        const leaving = mapped.filter((edge) => edge.from === task.key);
        const rank = (edge: Edge) => {
            const target = position.get(edge.to);
            return target === undefined ? 2 : target > position.get(task.key)! ? 0 : 1;
        };
        task.options = [...leaving].sort((a, b) => rank(a) - rank(b)).map((edge) => edge.on);
        task.routes = Object.fromEntries(leaving.map((edge) => [edge.on, edge.to]));
    }

    return {
        template: { ...template, tasks },
        edges: mapped,
        frames: phases
            .filter((phase) => open.has(phase.key))
            .map((phase) => ({
                phase,
                keys: template.tasks.filter((task) => task.phase === phase.key).map((t) => t.key),
            })),
    };
}
