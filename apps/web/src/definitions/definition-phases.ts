import { edges, type Edge, type Phase, type Definition, type Subtask } from "./definition";

/** A definition as the canvas draws it: each collapsed phase is one card. */
export type DefinitionDrawing = {
    definition: Definition;
    edges: Edge[];
    /** The open phases and the keys of the subtasks inside them. */
    frames: { phase: Phase; keys: string[] }[];
};

export const phaseKey = (phase: string) => `phase:${phase}`;

/**
 * Collapses every phase that is not in `open` into one card. Routes inside a collapsed phase are
 * hidden. Routes that cross its edge attach to the card.
 */
export function definitionDrawing(
    definition: Definition,
    open: ReadonlySet<string>,
): DefinitionDrawing {
    const phases = definition.phases;
    const all = edges(definition);
    if (phases.length === 0) return { definition, edges: all, frames: [] };

    const collapsed = (subtask: Subtask) =>
        subtask.phase !== undefined &&
        !open.has(subtask.phase) &&
        phases.some((phase) => phase.key === subtask.phase);
    const groupOf = new Map(
        definition.subtasks.map((subtask) => [
            subtask.key,
            collapsed(subtask) ? phaseKey(subtask.phase!) : subtask.key,
        ]),
    );

    const subtasks: Subtask[] = [];
    for (const subtask of definition.subtasks) {
        if (!collapsed(subtask)) {
            subtasks.push(subtask);
            continue;
        }
        const key = phaseKey(subtask.phase!);
        if (subtasks.some((candidate) => candidate.key === key)) continue;
        const phase = phases.find((candidate) => candidate.key === subtask.phase)!;
        const inner = definition.subtasks.filter((candidate) => candidate.phase === phase.key);
        subtasks.push({
            key,
            title: phase.title,
            kind: "phase",
            brief: phase.brief,
            phase: phase.key,
            inner,
        });
    }

    const mapped: Edge[] = [];
    for (const edge of all) {
        const from = groupOf.get(edge.from);
        if (from === undefined) continue;
        const to = groupOf.get(edge.to) ?? edge.to;
        if (from === to) continue;
        // A hidden default that ends the definition stays hidden. It is not an outcome of the card.
        if (from !== edge.from && edge.implicit && !groupOf.has(edge.to)) continue;
        const clash = mapped.find(
            (candidate) =>
                candidate.from === from && candidate.on === edge.on && candidate.to !== to,
        );
        const on = clash === undefined || from === edge.from ? edge.on : `${edge.from} ${edge.on}`;
        if (
            mapped.some(
                (candidate) =>
                    candidate.from === from && candidate.on === on && candidate.to === to,
            )
        ) {
            continue;
        }
        mapped.push({ from, to, on, implicit: edge.implicit });
    }

    const position = new Map(subtasks.map((subtask, index) => [subtask.key, index]));
    for (const subtask of subtasks) {
        if (subtask.kind !== "phase") continue;
        const leaving = mapped.filter((edge) => edge.from === subtask.key);
        const rank = (edge: Edge) => {
            const target = position.get(edge.to);
            return target === undefined ? 2 : target > position.get(subtask.key)! ? 0 : 1;
        };
        subtask.options = [...leaving]
            .sort((left, right) => rank(left) - rank(right))
            .map((edge) => edge.on);
        subtask.routes = Object.fromEntries(leaving.map((edge) => [edge.on, edge.to]));
    }

    return {
        definition: { ...definition, subtasks },
        edges: mapped,
        frames: phases
            .filter((phase) => open.has(phase.key))
            .map((phase) => ({
                phase,
                keys: definition.subtasks
                    .filter((subtask) => subtask.phase === phase.key)
                    .map((subtask) => subtask.key),
            })),
    };
}
