/**
 * A Project task definition, as the Gateway stores it, and the drawing rules from the Tasks reference:
 * routes and their defaults, the three findings, and a schedule in words.
 */

export type TaskKind = "agent" | "check" | "merge" | "action" | "decide";

/** A collapsed phase is a card the view draws. It is not a kind the Gateway stores. */
export type DrawKind = TaskKind | "phase";

export type Phase = {
    key: string;
    title: string;
    brief: string;
    /** When true, the phase runs again for each inserted subtask. */
    repeat: boolean;
};

export type Deliverable = {
    id?: string;
    type?: string;
    description?: string;
    command?: string;
};

export type Subtask = {
    key: string;
    title: string;
    kind: DrawKind;
    brief?: string;
    phase?: string;
    implementer_model?: string;
    reviewer_model?: string;
    deliverables?: Deliverable[];
    routes?: Record<string, string>;
    operation?: string;
    arguments?: Record<string, unknown>;
    question?: string;
    options?: string[];
    evidence?: string[];
    min_probability?: number;
    /** Set on a collapsed phase card: the subtasks it stands for. */
    inner?: Subtask[];
};

export type Parameter = {
    name: string;
    type: string;
    required: boolean;
    default?: unknown;
};

export type Schedule = {
    cron: string;
    values: Record<string, unknown>;
};

export type Definition = {
    project_id: number;
    name: string;
    title: string;
    brief: string;
    parameters: Parameter[];
    status: "backlog" | "todo";
    schedule: Schedule | null;
    phases: Phase[];
    subtasks: Subtask[];
};

/** A model ProxyCli offers, and the provider whose accounts serve it. */
export type ProxyModel = { id: string; provider: string };

export type Edge = {
    from: string;
    /** A subtask key, or `complete` or `fail`. */
    to: string;
    /** The outcome or option that takes this edge. */
    on: string;
    /** True when the definition does not name this route and the default applies. */
    implicit: boolean;
};

export type Finding = { key: string; message: string };

const OUTCOMES = ["passed", "skipped", "failed"] as const;
const KINDS = new Set<TaskKind>(["agent", "check", "merge", "action", "decide"]);

/** Providers the task drivers do not run. ProxyCli keeps the name, and no driver serves it. */
const UNRUN_PROVIDERS = new Set(["google", "meta"]);

const DAYS = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

const isRecord = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === "object" && !Array.isArray(value);

const text = (value: unknown): string | undefined =>
    typeof value === "string" && value !== "" ? value : undefined;

const stringList = (value: unknown): string[] | undefined => {
    if (!Array.isArray(value)) return undefined;
    const items = value.filter((item): item is string => typeof item === "string");
    return items.length === 0 ? undefined : items;
};

/** A Claude model runs on T3's own subscription, so it needs no ProxyCli offer. */
export function isClaudeModel(id: string): boolean {
    return /^claude([\s-]|$)/i.test(id);
}

/**
 * A model can run when ProxyCli offers it through a provider a driver runs, or when it is a Claude
 * model. A listed model whose provider no driver runs, such as `google`, cannot.
 */
export function driverCanRun(model: string, models: readonly ProxyModel[]): boolean {
    const listed = models.find((candidate) => candidate.id === model);
    if (isClaudeModel(model) || listed?.provider === "claude") return true;
    if (listed === undefined) return false;
    return !UNRUN_PROVIDERS.has(listed.provider);
}

/** The models an agent subtask names. A phase card unions the models inside it. */
export function modelsOf(subtask: Subtask): string[] {
    if (subtask.kind === "phase") {
        return [...new Set((subtask.inner ?? []).flatMap(modelsOf))];
    }
    if (subtask.kind !== "agent") return [];
    return [subtask.implementer_model, subtask.reviewer_model].filter(
        (model): model is string => model !== undefined,
    );
}

/** The distinct models a definition's subtasks name. */
export function modelNames(definition: Definition): string[] {
    return [...new Set(definition.subtasks.flatMap(modelsOf))];
}

/** Where a task from this definition begins. */
export function startsInLabel(status: Definition["status"]): string {
    return status === "todo" ? "Todo" : "Backlog";
}

/** The command deliverables a check subtask runs. */
export function commandsOf(subtask: Subtask): string[] {
    return (subtask.deliverables ?? []).flatMap((deliverable) =>
        deliverable.type === "command" && deliverable.command !== undefined
            ? [deliverable.command]
            : [],
    );
}

/** The outcomes or options a subtask can end with. A decide subtask has no defaults. */
export function outcomesOf(subtask: Subtask): string[] {
    if (subtask.kind === "decide" || subtask.options !== undefined) return subtask.options ?? [];
    if (subtask.kind === "action") return ["passed", "failed"];
    return [...OUTCOMES];
}

/** Every route of every subtask, with the defaults filled in. */
export function edges(definition: Pick<Definition, "subtasks">): Edge[] {
    return definition.subtasks.flatMap((subtask, index) =>
        outcomesOf(subtask).flatMap((on): Edge[] => {
            const named = subtask.routes?.[on];
            if (named !== undefined) return [{ from: subtask.key, to: named, on, implicit: false }];
            const fallback = defaultTarget(definition.subtasks, index, on);
            return fallback === null
                ? []
                : [{ from: subtask.key, to: fallback, on, implicit: true }];
        }),
    );
}

function defaultTarget(subtasks: readonly Subtask[], index: number, on: string): string | null {
    const subtask = subtasks[index];
    if (subtask === undefined || subtask.options !== undefined || subtask.kind === "decide") {
        return null;
    }
    if (on === "passed") return subtasks[index + 1]?.key ?? "complete";
    if (on === "skipped") return "complete";
    if (on === "failed") return "fail";
    return null;
}

/**
 * What the drawing reports: a decide subtask whose options all lead to one subtask, and a model no
 * driver can run. It can also report a subtask no path reaches, but the Gateway refuses a stored
 * definition that contains one, so that finding is not shown for a stored definition. Without
 * `models`, models are not checked.
 */
export function findings(
    definition: Pick<Definition, "subtasks">,
    models?: readonly ProxyModel[],
): Finding[] {
    const all = edges(definition);
    const reached = new Set<string>();
    const first = definition.subtasks[0]?.key;
    if (first !== undefined) reached.add(first);
    for (const subtask of definition.subtasks) {
        if (!reached.has(subtask.key)) continue;
        for (const edge of all) {
            if (edge.from === subtask.key) reached.add(edge.to);
        }
    }

    const result: Finding[] = [];
    for (const subtask of definition.subtasks) {
        if (!reached.has(subtask.key)) {
            result.push({ key: subtask.key, message: "No path reaches this subtask." });
        }
        if (subtask.kind === "decide") {
            const targets = new Set(
                all.filter((edge) => edge.from === subtask.key).map((edge) => edge.to),
            );
            if (targets.size === 1) {
                result.push({
                    key: subtask.key,
                    message: "Every option leads to the same subtask.",
                });
            }
        }
        if (models !== undefined && subtask.kind === "agent") {
            for (const model of modelsOf(subtask)) {
                if (!driverCanRun(model, models)) {
                    result.push({ key: subtask.key, message: `No driver can run ${model}.` });
                }
            }
        }
    }
    return result;
}

const number = (field: string): number | null => (/^\d+$/.test(field) ? Number(field) : null);
const clock = (hour: number, minute: number) =>
    `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")} UTC`;
const ordinal = (day: number) =>
    `${day}${day % 10 === 1 && day !== 11 ? "st" : day % 10 === 2 && day !== 12 ? "nd" : day % 10 === 3 && day !== 13 ? "rd" : "th"}`;

/**
 * A cron expression in words, for the common shapes. Any other expression comes back as it is.
 * No schedule is on demand.
 */
export function describeCron(expression: string | null): string {
    if (expression === null) return "On demand";
    const fields = expression.trim().split(/\s+/);
    if (fields.length !== 5) return expression;
    const [minuteField, hourField, dayField, monthField, weekdayField] = fields as [
        string,
        string,
        string,
        string,
        string,
    ];
    if (monthField !== "*") return expression;

    const step = /^\*\/(\d+)$/.exec(minuteField);
    if (step && hourField === "*" && dayField === "*" && weekdayField === "*") {
        return `Every ${step[1]} minutes`;
    }

    const minute = number(minuteField);
    if (minute === null) return expression;

    if (hourField === "*" && dayField === "*" && weekdayField === "*") {
        return minute === 0 ? "Hourly" : `Hourly at :${String(minute).padStart(2, "0")}`;
    }

    const hour = number(hourField);
    if (hour === null) return expression;
    const at = clock(hour, minute);

    if (dayField === "*" && weekdayField === "*") return `Daily at ${at}`;
    if (dayField === "*" && weekdayField === "1-5") return `Weekdays at ${at}`;

    const weekday = number(weekdayField);
    if (dayField === "*" && weekday !== null && weekday <= 7) {
        return `Weekly on ${DAYS[weekday % 7]} at ${at}`;
    }

    const day = number(dayField);
    if (day !== null && weekdayField === "*") return `Monthly on the ${ordinal(day)} at ${at}`;

    return expression;
}

/** The schedule in words. A definition without one is on demand. */
export function describeSchedule(definition: Pick<Definition, "schedule">): string {
    return describeCron(definition.schedule?.cron ?? null);
}

export function parseDefinition(value: unknown): Definition | null {
    if (!isRecord(value)) return null;
    const name = text(value.name);
    const title = text(value.title);
    const brief = typeof value.brief === "string" ? value.brief : undefined;
    const projectId = value.project_id;
    const status = value.status === "todo" || value.status === "backlog" ? value.status : undefined;
    if (
        name === undefined ||
        title === undefined ||
        brief === undefined ||
        status === undefined ||
        typeof projectId !== "number"
    ) {
        return null;
    }

    const subtasks = Array.isArray(value.subtasks)
        ? value.subtasks.flatMap((row) => {
              const parsed = parseSubtask(row);
              return parsed === null ? [] : [parsed];
          })
        : [];
    if (subtasks.length === 0) return null;

    return {
        project_id: projectId,
        name,
        title,
        brief,
        parameters: parseParameters(value.parameters),
        status,
        schedule: parseSchedule(value.schedule),
        phases: parsePhases(value.phases),
        subtasks,
    };
}

export function parseDefinitionList(value: unknown): Definition[] {
    if (!Array.isArray(value)) return [];
    return value.flatMap((row) => {
        const parsed = parseDefinition(row);
        return parsed === null ? [] : [parsed];
    });
}

function parseParameters(value: unknown): Parameter[] {
    if (!Array.isArray(value)) return [];
    return value.flatMap((row) => {
        if (!isRecord(row)) return [];
        const name = text(row.name);
        const type = text(row.type);
        if (name === undefined || type === undefined || typeof row.required !== "boolean")
            return [];
        return [
            {
                name,
                type,
                required: row.required,
                ...(Object.prototype.hasOwnProperty.call(row, "default")
                    ? { default: row.default }
                    : {}),
            },
        ];
    });
}

function parseSchedule(value: unknown): Schedule | null {
    if (!isRecord(value)) return null;
    const cron = text(value.cron);
    if (cron === undefined) return null;
    const values = isRecord(value.values) ? value.values : {};
    return { cron, values };
}

function parsePhases(value: unknown): Phase[] {
    if (!Array.isArray(value)) return [];
    return value.flatMap((row) => {
        if (!isRecord(row)) return [];
        const key = text(row.key);
        const title = text(row.title);
        if (
            key === undefined ||
            title === undefined ||
            typeof row.brief !== "string" ||
            typeof row.repeat !== "boolean"
        ) {
            return [];
        }
        return [{ key, title, brief: row.brief, repeat: row.repeat }];
    });
}

function parseSubtask(value: unknown): Subtask | null {
    if (!isRecord(value)) return null;
    const key = text(value.key);
    const title = text(value.title);
    const kind = text(value.kind);
    if (
        key === undefined ||
        title === undefined ||
        kind === undefined ||
        !KINDS.has(kind as TaskKind)
    ) {
        return null;
    }

    const routes = isRecord(value.routes)
        ? Object.fromEntries(
              Object.entries(value.routes).filter(
                  (entry): entry is [string, string] => typeof entry[1] === "string",
              ),
          )
        : undefined;
    const min = value.min_probability;

    return {
        key,
        title,
        kind: kind as TaskKind,
        ...(text(value.brief) !== undefined ? { brief: text(value.brief) } : {}),
        ...(text(value.phase) !== undefined ? { phase: text(value.phase) } : {}),
        ...(text(value.implementer_model) !== undefined
            ? { implementer_model: text(value.implementer_model) }
            : {}),
        ...(text(value.reviewer_model) !== undefined
            ? { reviewer_model: text(value.reviewer_model) }
            : {}),
        ...(Array.isArray(value.deliverables)
            ? { deliverables: value.deliverables.flatMap(parseDeliverable) }
            : {}),
        ...(routes !== undefined && Object.keys(routes).length > 0 ? { routes } : {}),
        ...(text(value.operation) !== undefined ? { operation: text(value.operation) } : {}),
        ...(isRecord(value.arguments) ? { arguments: value.arguments } : {}),
        ...(text(value.question) !== undefined ? { question: text(value.question) } : {}),
        ...(stringList(value.options) !== undefined ? { options: stringList(value.options) } : {}),
        ...(stringList(value.evidence) !== undefined
            ? { evidence: stringList(value.evidence) }
            : {}),
        ...(typeof min === "number" ? { min_probability: min } : {}),
    };
}

function parseDeliverable(value: unknown): Deliverable[] {
    if (!isRecord(value)) return [];
    return [
        {
            ...(text(value.id) !== undefined ? { id: text(value.id) } : {}),
            ...(text(value.type) !== undefined ? { type: text(value.type) } : {}),
            ...(text(value.description) !== undefined
                ? { description: text(value.description) }
                : {}),
            ...(text(value.command) !== undefined ? { command: text(value.command) } : {}),
        },
    ];
}
