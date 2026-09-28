/**
 * Task templates and their flows, as ADR 0181 proposes them. The Gateway has no template API yet,
 * so the demo Gateway serves these shapes.
 */

export type TaskKind = "agent" | "check" | "merge" | "action" | "decide";

/** The outcomes every kind but `decide` can end with. A `decide` task ends with one of its options. */
export const OUTCOMES = ["passed", "skipped", "failed"] as const;
export type Outcome = (typeof OUTCOMES)[number];

/** `complete` and `fail` end the group. Any other target is the key of a later task. */
export type Terminal = "complete" | "fail";

export type TemplateTask = {
    key: string;
    title: string;
    kind: TaskKind;
    brief?: string;
    /** `check`: the commands it runs. */
    commands?: string[];
    /** `action`: the Gateway operation and its arguments. */
    operation?: string;
    arguments?: Record<string, unknown>;
    /** `decide`: the question, its options, the tasks it reads, and the threshold. */
    question?: string;
    options?: string[];
    evidence?: string[];
    min_probability?: number;
    /** Outcome or option → target. A missing outcome uses its default. */
    routes?: Record<string, string>;
    /** Figures from past runs of this template. */
    stats?: TaskStats;
};

export type TaskStats = {
    runs: number;
    /** How often each outcome or option ended this task. */
    outcomes: Record<string, number>;
    avg_duration_ms: number | null;
    avg_tokens: number | null;
};

export type TaskTemplate = {
    name: string;
    title: string;
    project: string;
    project_code: string;
    status: "backlog" | "todo";
    cron: string | null;
    app: string | null;
    tasks: TemplateTask[];
};

export type RunTaskStatus = "todo" | "running" | "reviewing" | "completed" | "failed" | "cancelled";

export type RunTask = {
    key: string;
    status: RunTaskStatus;
    outcome: string | null;
    duration_ms: number | null;
    tokens: number | null;
    /** `decide`: the probability of each option. */
    probabilities?: Record<string, number>;
};

export type TemplateRun = {
    id: number;
    template: string;
    title: string;
    status: "todo" | "running" | "settling" | "completed" | "failed" | "cancelled";
    started_at: string;
    tasks: RunTask[];
};

export type Edge = {
    from: string;
    /** A task key, or a terminal. */
    to: string;
    /** The outcome or option that takes this edge. */
    on: string;
    /** True when the template does not name this route and the default applies. */
    implicit: boolean;
};

/** Kinds that call a model, and so cost tokens. */
export const MODEL_KINDS: readonly TaskKind[] = ["agent", "decide"];

/** The outcomes or options a task can end with. */
export function outcomesOf(task: TemplateTask): string[] {
    if (task.kind === "decide") return task.options ?? [];
    if (task.kind === "action") return ["passed", "failed"];
    return [...OUTCOMES];
}

/** Every route of every task, with the defaults filled in. */
export function edges(template: TaskTemplate): Edge[] {
    return template.tasks.flatMap((task, index) =>
        outcomesOf(task).flatMap((on): Edge[] => {
            const named = task.routes?.[on];
            if (named !== undefined) return [{ from: task.key, to: named, on, implicit: false }];
            const fallback = defaultTarget(template, index, on);
            return fallback === null ? [] : [{ from: task.key, to: fallback, on, implicit: true }];
        }),
    );
}

function defaultTarget(template: TaskTemplate, index: number, on: string): string | null {
    if (template.tasks[index]?.kind === "decide") return null;
    if (on === "passed") return template.tasks[index + 1]?.key ?? "complete";
    if (on === "skipped") return "complete";
    if (on === "failed") return "fail";
    return null;
}

export type Finding = { key: string; message: string };

/** What a template author should look at: tasks no path reaches, and routing that decides nothing. */
export function findings(template: TaskTemplate): Finding[] {
    const all = edges(template);
    const reached = new Set<string>([template.tasks[0]?.key ?? ""]);
    for (const task of template.tasks) {
        if (!reached.has(task.key)) continue;
        for (const edge of all) if (edge.from === task.key) reached.add(edge.to);
    }
    const result: Finding[] = [];
    for (const task of template.tasks) {
        if (!reached.has(task.key)) {
            result.push({ key: task.key, message: "No path reaches this task." });
        }
        if (task.kind === "decide") {
            const targets = new Set(all.filter((edge) => edge.from === task.key).map((e) => e.to));
            if (targets.size === 1) {
                result.push({ key: task.key, message: "Every option leads to the same task." });
            }
        }
        const stats = task.stats;
        if (stats !== undefined && stats.runs >= 5) {
            const [top, count] = Object.entries(stats.outcomes).sort((a, b) => b[1] - a[1])[0] ?? [];
            if (task.kind === "decide" && count === stats.runs) {
                result.push({
                    key: task.key,
                    message: `Chose ${top} in all ${stats.runs} runs. A fixed route may do.`,
                });
            }
            if (task.kind !== "decide" && (stats.outcomes.skipped ?? 0) === stats.runs) {
                result.push({ key: task.key, message: `Skipped in all ${stats.runs} runs.` });
            }
        }
    }
    return result;
}

/** The edges a run took: each ended task's outcome, followed to its target. */
export function takenEdges(template: TaskTemplate, run: TemplateRun): Set<string> {
    const outcomes = new Map(run.tasks.map((task) => [task.key, task.outcome]));
    return new Set(
        edges(template)
            .filter((edge) => outcomes.get(edge.from) === edge.on)
            .map((edge) => edgeId(edge)),
    );
}

export const edgeId = (edge: Edge): string => `${edge.from}:${edge.on}`;
