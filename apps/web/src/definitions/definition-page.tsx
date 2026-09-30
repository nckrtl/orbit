import { useQuery } from "@tanstack/react-query";
import { useNavigate, useParams, useSearch } from "@tanstack/react-router";
import { useCallback, useMemo } from "react";
import { GatewayError } from "../api/client";
import { extensionsQuery } from "../api/extensions";
import { lists } from "../api/queries";
import { Frame } from "../ui/Frame";
import { PageHeader } from "../ui/PageHeader";
import { Properties, type Property } from "../ui/Properties";
import { useGo } from "../ui/go";
import { DefinitionCanvas, AGENT_STAGES } from "./definition-canvas";
import {
    commandsOf,
    describeSchedule,
    driverCanRun,
    edges,
    findings,
    type Definition,
    type ProxyModel,
    type Subtask,
} from "./definition";
import { layout, type EngineStage } from "./definition-layout";
import { proxyModelsQuery, taskDefinitionQuery } from "./definition-queries";

const STAGE_DETAIL: Record<EngineStage, string> = {
    workspace: "Orbit provisions the task workspace before the first subtask.",
    "pull-request": "After the last subtask, Orbit opens the pull request.",
    merge: "A person merges. A definition with a merge subtask merges there instead.",
    cleanup: "Orbit removes the workspace.",
};

export type DefinitionSearch = { subtask?: string; open?: string };

/** `open` lists the open phases, comma-separated. `subtask` is the selected card. */
export function readDefinitionSearch(search: Record<string, unknown>): DefinitionSearch {
    return {
        subtask:
            typeof search.subtask === "string" && search.subtask !== ""
                ? search.subtask
                : undefined,
        open: typeof search.open === "string" && search.open !== "" ? search.open : undefined,
    };
}

/** One task definition: the canvas, the selected subtask, and the findings. Opening it does not start a task. */
export function DefinitionPage() {
    const extensions = useQuery(extensionsQuery);
    if (extensions.isPending) {
        return (
            <Frame title="Task definition">
                <p role="status">Loading Gateway extension state…</p>
            </Frame>
        );
    }
    if (extensions.data?.tasks !== true) {
        return (
            <Frame title="Task definition" state="warn">
                <p>The tasks extension is disabled on this Gateway.</p>
            </Frame>
        );
    }
    return <DefinitionView />;
}

function DefinitionView() {
    const { project: projectId, name } = useParams({
        from: "/projects/$project/task-definitions/$name",
    });
    const search = useSearch({ from: "/projects/$project/task-definitions/$name" });
    const navigate = useNavigate({ from: "/projects/$project/task-definitions/$name" });
    const go = useGo();
    const definition = useQuery(taskDefinitionQuery(projectId, name));
    const projects = useQuery(lists.projects);
    const extensions = useQuery(extensionsQuery);
    const proxycliOn = extensions.data?.proxycli === true;
    const models = useQuery({
        ...proxyModelsQuery,
        enabled: proxycliOn,
    });
    // While ProxyCli's list is still loading, models are not checked. Once the extension state is
    // known, a list that is off or refused is empty, so each non-Claude model is a finding.
    const offered = proxycliOn && !models.isFetched ? undefined : (models.data ?? []);
    const project = projects.data?.find((candidate) => String(candidate.id) === projectId);
    const flow = definition.data ?? undefined;

    const setSearch = (next: DefinitionSearch) =>
        void navigate({
            search: (previous: DefinitionSearch) => ({ ...previous, ...next }),
            replace: true,
        });

    const open = useMemo(
        () =>
            new Set<string>(
                search.open === undefined ? [] : search.open.split(",").filter(Boolean),
            ),
        [search.open],
    );
    const drawn = useMemo(
        () => (flow === undefined ? undefined : layout(flow, open)),
        [flow, open],
    );
    const selectedId = search.subtask ?? flow?.subtasks[0]?.key;
    const selected = flow?.subtasks.find((subtask) => subtask.key === selectedId);
    const stage =
        selected === undefined
            ? drawn?.nodes.find((node) => node.type === "stage" && node.id === selectedId)
            : undefined;
    const notes = flow === undefined ? [] : findings(flow, offered);

    const togglePhase = useCallback(
        (phase: string) => {
            const next = new Set(open);
            if (next.has(phase)) next.delete(phase);
            else next.add(phase);
            void navigate({
                search: (previous: DefinitionSearch) => ({
                    ...previous,
                    open: next.size === 0 ? undefined : [...next].join(","),
                }),
                replace: true,
            });
        },
        [navigate, open],
    );

    const select = (key: string) => {
        const phase = flow?.subtasks.find((subtask) => subtask.key === key)?.phase;
        if (phase === undefined || open.has(phase)) {
            setSearch({ subtask: key });
            return;
        }
        const next = new Set(open);
        next.add(phase);
        setSearch({ subtask: key, open: [...next].join(",") });
    };

    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            <PageHeader
                titleTestId="definition-title"
                trail={[
                    { label: "Tasks", open: () => go.section("tasks") },
                    {
                        label: project?.name ?? `Project ${projectId}`,
                        open:
                            project === undefined
                                ? undefined
                                : () => go.record("projects", project),
                    },
                    { label: flow?.title ?? name },
                ]}
            />
            {definition.isPending && <p role="status">Loading task definition…</p>}
            {definition.error && <DefinitionError error={definition.error} />}
            {!definition.isPending && !definition.error && definition.data === null && (
                <p role="alert">Task definition not found.</p>
            )}
            {flow && drawn && (
                <div className="grid min-w-0 grid-cols-1 gap-[var(--panel-gap)] lg:min-h-0 lg:flex-1 lg:grid-cols-[minmax(0,1fr)_minmax(36ch,48ch)] lg:grid-rows-[minmax(0,1fr)]">
                    <Frame
                        title="Definition"
                        topRight={describeSchedule(flow)}
                        className="min-h-[360px] lg:h-full lg:min-h-0"
                        bodyClassName="definition-frame-body"
                        bottomLeft={
                            flow.phases.length > 0 ? "Open a phase to see its subtasks" : undefined
                        }
                    >
                        <DefinitionCanvas
                            definition={flow}
                            selected={selectedId}
                            open={open}
                            onSelect={select}
                            onTogglePhase={togglePhase}
                        />
                    </Frame>
                    <div className="flex min-h-0 flex-col gap-[var(--panel-gap)]">
                        {selected !== undefined && (
                            <Properties
                                title="Subtask"
                                testId="definition-subtask"
                                properties={subtaskProperties(flow, selected, offered)}
                            />
                        )}
                        {stage?.type === "stage" && (
                            <Properties
                                title="Stage"
                                testId="definition-subtask"
                                properties={[
                                    { name: "Stage", value: stage.title },
                                    { name: "Detail", value: STAGE_DETAIL[stage.stage] },
                                ]}
                            />
                        )}
                        <Frame
                            title="Findings"
                            topRight={offered === undefined ? undefined : notes.length}
                            testId="definition-findings"
                        >
                            {offered === undefined && notes.length === 0 && (
                                <p role="status">Loading models…</p>
                            )}
                            {offered !== undefined && notes.length === 0 && (
                                <p className="text-dim">None.</p>
                            )}
                            {notes.map((note) => (
                                <button
                                    key={`${note.key}:${note.message}`}
                                    type="button"
                                    className="block w-full text-left"
                                    onClick={() => select(note.key)}
                                >
                                    <span className="text-yellow">{note.key}</span> {note.message}
                                </button>
                            ))}
                        </Frame>
                    </div>
                </div>
            )}
        </div>
    );
}

function DefinitionError({ error }: { error: Error }) {
    const disabled = error instanceof GatewayError && error.code === "extension.disabled";
    return (
        <p role="alert">
            {disabled ? "The tasks extension is disabled on this Gateway." : error.message}
        </p>
    );
}

function subtaskProperties(
    definition: Definition,
    subtask: Subtask,
    models: ProxyModel[] | undefined,
): Property[] {
    const routes = edges(definition)
        .filter((edge) => edge.from === subtask.key)
        .map((edge) => ({
            name: `On ${edge.on}`,
            value: `→ ${edge.to}${edge.implicit ? " (default)" : ""}`,
        }));
    const kindFields: Property[] =
        subtask.kind === "agent"
            ? [
                  modelProperty("Implementer", subtask.implementer_model, models),
                  { name: "Handoff check", value: "The Project task check" },
                  modelProperty("Reviewer", subtask.reviewer_model, models),
                  { name: "Inside", value: AGENT_STAGES },
              ]
            : subtask.kind === "check"
              ? [{ name: "Commands", value: commandsOf(subtask) }]
              : subtask.kind === "action"
                ? [
                      { name: "Operation", value: subtask.operation },
                      { name: "Arguments", value: JSON.stringify(subtask.arguments ?? {}) },
                  ]
                : subtask.kind === "decide"
                  ? [
                        { name: "Question", value: subtask.question },
                        { name: "Options", value: subtask.options },
                        { name: "Evidence", value: subtask.evidence },
                        { name: "Min probability", value: subtask.min_probability ?? 0.8 },
                    ]
                  : subtask.kind === "merge"
                    ? [{ name: "Merge", value: "This subtask merges the pull request." }]
                    : [];

    return [
        { name: "Key", value: subtask.key },
        { name: "Kind", value: subtask.kind },
        { name: "Title", value: subtask.title },
        ...(subtask.brief === undefined ? [] : [{ name: "Brief", value: subtask.brief }]),
        ...kindFields,
        ...routes,
    ];
}

function modelProperty(
    name: string,
    model: string | undefined,
    models: ProxyModel[] | undefined,
): Property {
    if (model === undefined) return { name, value: "The task default" };
    if (models === undefined) return { name, value: model };
    const offered = models.find((candidate) => candidate.id === model);
    if (!driverCanRun(model, models)) {
        return {
            name,
            value: offered === undefined ? model : `${model} · ${offered.provider}`,
            warn: true,
        };
    }
    return { name, value: offered === undefined ? model : `${model} · ${offered.provider}` };
}
