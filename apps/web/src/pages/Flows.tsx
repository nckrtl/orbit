import { useQuery } from "@tanstack/react-query";
import { useNavigate, useParams, useSearch } from "@tanstack/react-router";
import { formatCompactCount, formatDurationMs } from "../api/tasks";
import { lists } from "../api/queries";
import { describeCron } from "../flows/cron";
import { FlowCanvas } from "../flows/FlowCanvas";
import { FlowsPane } from "../flows/FlowsPane";
import {
    edges,
    findings,
    type TaskTemplate,
    type TemplateRun,
    type TemplateTask,
} from "../flows/model";
import { templateQuery, templateRunsQuery } from "../flows/queries";
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

type FlowSearch = { run?: number; task?: string };

export const validateFlowSearch = (search: Record<string, unknown>): FlowSearch => ({
    run: typeof search.run === "number" ? search.run : undefined,
    task: typeof search.task === "string" ? search.task : undefined,
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
    const notes = flow === undefined ? [] : findings(flow);

    const setSearch = (next: FlowSearch) =>
        void navigate({ search: (previous: FlowSearch) => ({ ...previous, ...next }), replace: true });

    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            <PageHeader
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
                        />
                    </Frame>
                    <div className="flex min-h-0 flex-col gap-[var(--panel-gap)]">
                        {selected && (
                            <Properties
                                title="Task"
                                properties={taskProperties(flow, selected, run)}
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
                                        onClick={() => setSearch({ task: note.key })}
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

const legend = "◆ model · hidden defaults: failed → fail, skipped → complete";

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

function taskProperties(
    template: TaskTemplate,
    task: TemplateTask,
    run: TemplateRun | undefined,
): Property[] {
    const routes = edges(template)
        .filter((edge) => edge.from === task.key)
        .map((edge) => ({
            name: `On ${edge.on}`,
            value: `→ ${edge.to}${edge.implicit ? " (default)" : ""}`,
        }));
    const runTask = run?.tasks.find((candidate) => candidate.key === task.key);
    const kindFields: Property[] =
        task.kind === "check"
            ? [{ name: "Commands", value: task.commands }]
            : task.kind === "action"
              ? [
                    { name: "Operation", value: task.operation },
                    { name: "Arguments", value: JSON.stringify(task.arguments ?? {}) },
                ]
              : task.kind === "decide"
                ? [
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
