import { useQuery } from "@tanstack/react-query";
import { useNavigate, useParams, useSearch } from "@tanstack/react-router";
import { useCallback, useMemo } from "react";
import { formatCompactCount, formatDurationMs } from "../api/tasks";
import { lists } from "../api/queries";
import { describeCron } from "../flows/cron";
import { FlowCanvas } from "../flows/FlowCanvas";
import { FlowsPane } from "../flows/FlowsPane";
import {
    DECIDE_MODEL,
    edges,
    type Phase,
    type ProxyModel,
    findings,
    type TaskTemplate,
    type TemplateRun,
    type TemplateTask,
} from "../flows/model";
import { proxyModelsQuery, templateQuery, templateRunsQuery } from "../flows/queries";
import { Frame } from "../ui/Frame";
import { PageHeader } from "../ui/PageHeader";
import { Properties, type Property } from "../ui/Properties";
import { useGo } from "../ui/go";

export function FlowsList() {
    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            <PageHeader trail={[{ label: "Flows" }]} />
            <FlowsPane order={1} className="w-full md:min-h-0 md:flex-1" />
        </div>
    );
}

const NO_PHASES: Phase[] = [];

/** `open` lists the open phases, comma-separated; "*" opens every phase. */
type FlowSearch = { run?: number; task?: string; open?: string };

export const validateFlowSearch = (search: Record<string, unknown>): FlowSearch => ({
    run: typeof search.run === "number" ? search.run : undefined,
    task: typeof search.task === "string" ? search.task : undefined,
    open: typeof search.open === "string" && search.open !== "" ? search.open : undefined,
});

export function FlowPage() {
    const { project: projectSlug, name } = useParams({ from: "/flows/$project/$name" });
    const search = useSearch({ from: "/flows/$project/$name" });
    const navigate = useNavigate({ from: "/flows/$project/$name" });
    const go = useGo();
    const template = useQuery(templateQuery(projectSlug, name));
    const runs = useQuery(templateRunsQuery(projectSlug, name));
    const projects = useQuery(lists.projects);
    const project = projects.data?.find((candidate) => candidate.slug === projectSlug);
    const run = runs.data?.find((candidate) => candidate.id === search.run);
    const flow = template.data;
    const selected = flow?.tasks.find((task) => task.key === search.task) ?? flow?.tasks[0];
    const models = useQuery(proxyModelsQuery).data;
    const notes = flow === undefined ? [] : findings(flow, models);

    const setSearch = (next: FlowSearch) =>
        void navigate({ search: (previous: FlowSearch) => ({ ...previous, ...next }), replace: true });

    const phases = flow?.phases ?? NO_PHASES;
    const open = useMemo(
        () =>
            new Set<string>(
                search.open === "*"
                    ? phases.map((phase) => phase.key)
                    : (search.open?.split(",") ?? []),
            ),
        [search.open, phases],
    );
    const setOpen = (next: Set<string>) =>
        setSearch({
            open:
                next.size === 0
                    ? undefined
                    : next.size === phases.length
                      ? "*"
                      : [...next].join(","),
        });
    const togglePhase = useCallback(
        (phase: string) => {
            const next = new Set(open);
            if (next.has(phase)) next.delete(phase);
            else next.add(phase);
            setOpen(next);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [open],
    );
    /** Selects a task and opens the phase it sits in. */
    const selectTask = (key: string) => {
        const phase = flow?.tasks.find((task) => task.key === key)?.phase;
        if (phase === undefined || open.has(phase)) return setSearch({ task: key });
        const next = new Set(open).add(phase);
        setSearch({ task: key, open: next.size === phases.length ? "*" : [...next].join(",") });
    };

    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            <PageHeader
                actions={
                    phases.length > 0 ? (
                        <span className="flex gap-[2ch]">
                            <button type="button" className="link" onClick={() => setOpen(new Set())}>
                                Collapse phases
                            </button>
                            <button
                                type="button"
                                className="link"
                                onClick={() => setOpen(new Set(phases.map((phase) => phase.key)))}
                            >
                                Open phases
                            </button>
                        </span>
                    ) : undefined
                }
                trail={[
                    { label: "Flows", open: () => go.section("flows") },
                    {
                        label: project?.name ?? projectSlug,
                        open: project === undefined ? undefined : () => go.record("projects", project),
                    },
                    { label: flow?.title ?? name },
                ]}
            />
            {template.isPending && <p role="status">Loading flow…</p>}
            {template.error && <p role="alert">{template.error.message}</p>}
            {flow && (
                <div className="grid min-w-0 grid-cols-1 gap-[var(--panel-gap)] lg:min-h-0 lg:flex-1 lg:grid-cols-[minmax(0,1fr)_minmax(40ch,56ch)] lg:grid-rows-[minmax(0,1fr)]">
                    <Frame
                        title={run === undefined ? "Flow" : `Run #${run.id}`}
                        topRight={run === undefined ? describeCron(flow.cron) : run.status}
                        className="min-h-0"
                        bodyClassName="flow-frame-body"
                        bottomLeft={legend}
                    >
                        <FlowCanvas
                            template={flow}
                            run={run}
                            selected={selected?.key}
                            onSelect={(key) => setSearch({ task: key })}
                            open={open}
                            onTogglePhase={togglePhase}
                        />
                    </Frame>
                    <div className="flex min-h-0 flex-col gap-[var(--panel-gap)]">
                        {selected && (
                            <Properties
                                title="Task"
                                properties={taskProperties(flow, selected, run, models)}
                            />
                        )}
                        <Frame title="Runs" topRight={runs.data?.length ?? 0} className="w-full">
                            <RunRow
                                label="Design"
                                meta="the template, with figures from past runs"
                                active={run === undefined}
                                onOpen={() => setSearch({ run: undefined })}
                            />
                            {runs.data?.map((candidate) => (
                                <RunRow
                                    key={candidate.id}
                                    label={`#${candidate.id} ${candidate.title}`}
                                    meta={runSummary(flow, candidate)}
                                    status={candidate.status}
                                    active={candidate.id === run?.id}
                                    onOpen={() => setSearch({ run: candidate.id })}
                                />
                            ))}
                        </Frame>
                        {notes.length > 0 && (
                            <Frame title="Findings" topRight={notes.length} className="w-full">
                                {notes.map((note) => (
                                    <button
                                        key={`${note.key}:${note.message}`}
                                        type="button"
                                        className="block w-full text-left"
                                        onClick={() => selectTask(note.key)}
                                    >
                                        <span className="text-yellow">{note.key}</span>{" "}
                                        {note.message}
                                    </button>
                                ))}
                            </Frame>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

const legend = "click a phase to open it · cyan: models · amber dashed: loops back · hidden: failed → fail";

function RunRow({
    label,
    meta,
    status,
    active,
    onOpen,
}: {
    label: string;
    meta: string;
    status?: TemplateRun["status"];
    active: boolean;
    onOpen: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onOpen}
            className="flow-run block w-full text-left"
            data-active={active || undefined}
        >
            <span className="flex justify-between gap-[1ch]">
                <span className="truncate">{label}</span>
                {status !== undefined && (
                    <span
                        className={
                            status === "failed"
                                ? "text-red"
                                : status === "completed"
                                  ? "text-green"
                                  : "text-yellow"
                        }
                    >
                        {status}
                    </span>
                )}
            </span>
            <span className="flow-meta">{meta}</span>
        </button>
    );
}

function runSummary(template: TaskTemplate, run: TemplateRun): string {
    const ran = run.tasks.filter((task) => task.status !== "cancelled" && task.status !== "todo");
    const tokens = run.tasks.reduce((sum, task) => sum + (task.tokens ?? 0), 0);
    const duration = run.tasks.reduce((sum, task) => sum + (task.duration_ms ?? 0), 0);
    return [
        `${ran.length} of ${template.tasks.length} tasks ran`,
        formatDurationMs(duration),
        tokens > 0 ? `${formatCompactCount(tokens)} tok` : null,
    ]
        .filter(Boolean)
        .join(" · ");
}

/** A model with the ProxyCli provider that serves it, or a warning when ProxyCli does not offer it. */
function modelProperty(name: string, model: string | undefined, models?: ProxyModel[]): Property {
    if (model === undefined) return { name, value: null };
    if (models === undefined) return { name, value: model };
    const offered = models.find((candidate) => candidate.id === model);
    return offered === undefined
        ? { name, value: `${model} · not in ProxyCli`, warn: true }
        : { name, value: `${model} · ${offered.provider}` };
}

function taskProperties(
    template: TaskTemplate,
    task: TemplateTask,
    run: TemplateRun | undefined,
    models?: ProxyModel[],
): Property[] {
    const routes = edges(template)
        .filter((edge) => edge.from === task.key)
        .map((edge) => ({
            name: `On ${edge.on}`,
            value: `→ ${edge.to}${edge.implicit ? " (default)" : ""}`,
        }));
    const runTask = run?.tasks.find((candidate) => candidate.key === task.key);
    const kindFields: Property[] =
        task.kind === "agent"
            ? [
                  modelProperty("Implementer", runTask?.implementer_model ?? task.implementer_model, models),
                  modelProperty("Reviewer", runTask?.reviewer_model ?? task.reviewer_model, models),
              ]
            : task.kind === "check"
            ? [{ name: "Commands", value: task.commands }]
            : task.kind === "action"
              ? [
                    { name: "Operation", value: task.operation },
                    { name: "Arguments", value: JSON.stringify(task.arguments ?? {}) },
                ]
              : task.kind === "decide"
                ? [
                      { name: "Model", value: DECIDE_MODEL },
                      { name: "Question", value: task.question },
                      { name: "Options", value: task.options },
                      { name: "Evidence", value: task.evidence },
                      { name: "Min probability", value: task.min_probability ?? 0.8 },
                  ]
                : [];
    const stats = task.stats;
    return [
        { name: "Key", value: task.key },
        { name: "Kind", value: task.kind },
        { name: "Title", value: task.title },
        ...(task.brief === undefined ? [] : [{ name: "Brief", value: task.brief }]),
        ...kindFields,
        ...routes,
        ...(runTask === undefined
            ? stats === undefined
                ? []
                : [
                      { name: "Past runs", value: stats.runs },
                      {
                          name: "Outcomes",
                          value: Object.entries(stats.outcomes)
                              .map(([outcome, count]) => `${outcome} ${count}`)
                              .join(" · "),
                      },
                      { name: "Avg duration", value: formatDurationMs(stats.avg_duration_ms) },
                      { name: "Avg tokens", value: formatCompactCount(stats.avg_tokens) },
                  ]
            : [
                  { name: "Status", value: runTask.status },
                  { name: "Outcome", value: runTask.outcome },
                  ...(runTask.probabilities === undefined
                      ? []
                      : [
                            {
                                name: "Probabilities",
                                value: Object.entries(runTask.probabilities)
                                    .map(([option, p]) => `${option} ${p.toFixed(2)}`)
                                    .join(" · "),
                            },
                        ]),
                  { name: "Duration", value: formatDurationMs(runTask.duration_ms) },
                  { name: "Tokens", value: formatCompactCount(runTask.tokens) },
              ]),
    ];
}
