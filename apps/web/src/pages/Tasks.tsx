import { DefinitionPane } from "../definitions/definition-pane";
import { AgentSessions } from "../tasks/AgentSessions";
import { TaskComments } from "../tasks/TaskComments";
import { useQuery } from "@tanstack/react-query";
import { useEffect, useState, type CSSProperties } from "react";
import { Link, useParams, useRouter } from "@tanstack/react-router";
import { extensionsQuery } from "../api/extensions";
import { GatewayError } from "../api/client";
import { lists } from "../api/queries";
import {
    completedSubtaskProgress,
    accumulatedLineChanges,
    formatCompactCount,
    formatDurationMs,
    formatCardDuration,
    formatLineDiff,
    formatSignedLineChanges,
    formatTokens,
    isActiveTaskGroup,
    liveDurationMs,
    taskColumn,
    taskAssistanceLabel,
    taskDirectionQuestion,
    taskGroupQuery,
    taskGroupsQuery,
    tasksForInstance,
    taskIdentity,
    taskStatusLabels,
    type Task,
    type TaskGroup,
    type TaskColumn,
} from "../api/tasks";
import { Frame } from "../ui/Frame";
import { PageHeader } from "../ui/PageHeader";
import { Properties } from "../ui/Properties";
import { useGo } from "../ui/go";
import { useTaskPoll } from "../realtime/polling";

const columns: TaskColumn[] = ["Backlog", "Todo", "In progress", "Done"];
const subtaskColumns: TaskColumn[] = ["Todo", "In progress", "Done"];

/** Keep the lane order, but give space only to lanes with cards. */
function filledLanes<T extends { status: TaskGroup["status"] }>(order: TaskColumn[], cards: T[]) {
    return order
        .map((column) => ({
            column,
            cards: cards.filter((card) => taskColumn(card.status) === column),
        }))
        .filter((lane) => lane.cards.length > 0);
}

function laneWidths(count: number): CSSProperties {
    return { "--kanban-lanes": count } as CSSProperties;
}

/** The current time, updated every `intervalMs` while `enabled`, so an active group's duration counts forward. */
function useNow(intervalMs: number, enabled: boolean): number {
    const [now, setNow] = useState(Date.now);
    useEffect(() => {
        if (!enabled) return;
        setNow(Date.now());
        const timer = setInterval(() => setNow(Date.now()), intervalMs);
        return () => clearInterval(timer);
    }, [intervalMs, enabled]);
    return now;
}

function TaskStatus({ status }: { status: TaskGroup["status"] }) {
    const color =
        status === "failed"
            ? "text-red"
            : status === "completed"
              ? "text-green"
              : status === "cancelled"
                ? "text-dim"
                : "text-yellow";
    return <span className={color}>{taskStatusLabels[status]}</span>;
}

function lineDiffProperty(detail: TaskGroup | Task) {
    const accumulated = "tasks" in detail ? accumulatedLineChanges(detail.tasks) : null;
    const added = accumulated?.lines_added ?? detail.lines_added;
    const deleted = accumulated?.lines_deleted ?? detail.lines_deleted;
    return [
        {
            name: "Line diff",
            value: formatSignedLineChanges(added, deleted) ?? formatLineDiff(detail.line_diff),
            title:
                formatSignedLineChanges(added, deleted, formatLineDiff) ??
                formatLineDiff(detail.line_diff) ??
                undefined,
            node:
                added != null && deleted != null ? (
                    <span aria-label="Line changes">
                        <span className="text-green">+{formatCompactCount(added)}</span>{" "}
                        <span className="text-red">−{formatCompactCount(deleted)}</span>
                    </span>
                ) : undefined,
        },
    ];
}

/** The group's pull request, labelled owner/repo#number when it is a GitHub pull request URL. */
function pullRequestProperty(url: string | null) {
    if (url === null || url === "") {
        return [];
    }
    const match = /^https:\/\/github\.com\/([^/]+\/[^/]+)\/pull\/(\d+)$/.exec(url);
    const label = match === null ? url : `${match[1]}#${match[2]}`;

    return [
        {
            name: "Pull request",
            value: label,
            title: url,
            node: (
                <a className="link" href={url} target="_blank" rel="noreferrer">
                    {label}
                </a>
            ),
        },
    ];
}

function taskProperties(
    group: TaskGroup,
    detail: TaskGroup | Task,
    durationMs: number | null,
    projectName: string,
    openProject?: () => void,
    openInstance?: () => void,
) {
    return [
        { name: "Title", value: detail.title },
        ...(group.execution_mode === "existing_thread"
            ? [
                  { name: "Type", value: "Annotation" },
                  {
                      name: "Thread",
                      value:
                          ("target_thread_id" in detail
                              ? detail.target_thread_id
                              : group.tasks[0]?.target_thread_id) ?? "Unassigned",
                  },
              ]
            : []),
        {
            name: "Status",
            value: taskColumn(detail.status),
            title: `${taskColumn(detail.status)} · ${taskStatusLabels[detail.status]}`,
            warn: taskColumn(detail.status) === "In progress",
        },
        {
            name: "Project",
            value: projectName,
            onOpen: openProject,
        },
        ...(group.taskable_type === "instance" && group.taskable_id !== null
            ? [
                  {
                      name: "Instance",
                      value: `Instance #${group.taskable_id}`,
                      onOpen: openInstance,
                  },
              ]
            : []),
        ...pullRequestProperty(group.pr_url),
        {
            name: "Tokens",
            value: formatCompactCount(detail.tokens),
            title: formatTokens(detail.tokens) ?? undefined,
        },
        ...lineDiffProperty(detail),
        { name: "Duration", value: formatDurationMs(durationMs) },
        { name: "Questions", value: detail.questions },
        { name: "Escalations", value: detail.escalations },
    ];
}

function TaskError({ error, retry }: { error: Error; retry: () => void }) {
    const disabled = error instanceof GatewayError && error.code === "extension.disabled";
    return (
        <div role="alert" className="px-[1ch] py-[8px]">
            <p>
                {disabled
                    ? "Tasks are disabled on this Gateway. Enable the tasks extension to view tracked work."
                    : `Could not load tasks: ${error.message}`}
            </p>
            <button type="button" className="link mt-[8px]" onClick={retry}>
                Try again
            </button>
        </div>
    );
}

function CardDiff({ task }: { task: TaskGroup | Task }) {
    const hasDiff = task.lines_added != null && task.lines_deleted != null;
    const tokens = formatCompactCount(task.tokens);
    if (!hasDiff && tokens === null) return null;

    return (
        <span className="flex gap-[1ch] whitespace-nowrap">
            {hasDiff && (
                <span className="flex gap-[1ch]" aria-label="Line changes">
                    <span className="text-green">+{formatCompactCount(task.lines_added)}</span>
                    <span className="text-red">−{formatCompactCount(task.lines_deleted)}</span>
                </span>
            )}
            {hasDiff && tokens !== null && <span>/</span>}
            {tokens !== null && (
                <span aria-label={`${formatTokens(task.tokens)} tokens`}>{tokens}</span>
            )}
        </span>
    );
}

const cardMetaClassName = "text-[11px] font-medium uppercase tracking-[0.08em]";

function CardFooter({
    task,
    durationMs,
    progress,
}: {
    task: TaskGroup | Task;
    durationMs: number | null;
    progress?: { completed: number; total: number };
}) {
    const column = taskColumn(task.status);
    if (column === "Backlog" || column === "Todo") return null;

    const duration = formatCardDuration(durationMs);
    const progressLabel =
        column === "In progress" && progress !== undefined && progress.total > 0
            ? `${progress.completed}/${progress.total}`
            : null;
    const showStatus = column === "Done";
    if (progressLabel === null && !showStatus && duration === null) return null;

    return (
        <div
            className={`mt-[10px] flex items-center justify-between gap-[1ch] ${cardMetaClassName}`}
        >
            {progressLabel !== null && progress !== undefined && (
                <span
                    className="text-dim"
                    aria-label={`${progress.completed} of ${progress.total} subtasks completed`}
                >
                    {progressLabel}
                </span>
            )}
            {showStatus && <TaskStatus status={task.status} />}
            <span className="ml-auto shrink-0 text-dim">{duration}</span>
        </div>
    );
}

function KanbanCardBody({
    identity,
    task,
    durationMs = task.duration_ms,
    progress,
}: {
    identity: string;
    task: TaskGroup | Task;
    durationMs?: number | null;
    progress?: { completed: number; total: number };
}) {
    const assistance = taskAssistanceLabel(task);
    return (
        <>
            <div
                className={`mb-[6px] flex justify-between gap-[1ch] text-dim ${cardMetaClassName}`}
            >
                <span className="break-words">{identity}</span>
                <CardDiff task={task} />
            </div>
            <h2 className="break-words font-bold">{task.title}</h2>
            {assistance !== null && (
                <p
                    className={`mt-[6px] break-words ${task.assistance_kind === "direction" ? "text-cyan" : "text-red"}`}
                >
                    {assistance}
                </p>
            )}
            <CardFooter task={task} durationMs={durationMs} progress={progress} />
        </>
    );
}

const kanbanCardClassName =
    "kanban-card block rounded-[2px] focus-visible:outline-2 focus-visible:outline-cyan";

export function TasksBoard({ instanceId }: { instanceId?: number } = {}) {
    const extensions = useQuery(extensionsQuery);
    const tasksEnabled = extensions.data?.tasks === true;
    const groups = useQuery({
        ...taskGroupsQuery,
        enabled: tasksEnabled,
        refetchInterval: useTaskPoll(),
    });
    const visibleGroups =
        instanceId === undefined ? groups.data : tasksForInstance(groups.data ?? [], instanceId);
    const lanes = filledLanes(columns, visibleGroups ?? []);
    // Cards show whole minutes, so the board ticks once a minute while a group is active.
    const now = useNow(
        60_000,
        tasksEnabled && (visibleGroups ?? []).some((group) => isActiveTaskGroup(group.status)),
    );
    if (!tasksEnabled) {
        return (
            <Frame title="Tasks" state="warn">
                <p role={extensions.isPending ? "status" : undefined}>
                    {extensions.isPending
                        ? "Loading Gateway extension state…"
                        : "The tasks extension is disabled on this Gateway."}
                </p>
            </Frame>
        );
    }
    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            {instanceId === undefined && <PageHeader trail={[{ label: "Tasks" }]} />}
            {instanceId === undefined && (
                <DefinitionPane
                    order={1}
                    className="w-full max-h-[220px] min-h-[120px] shrink-0 md:max-h-[30%]"
                />
            )}
            {groups.isPending && <p role="status">Loading tasks…</p>}
            {groups.error && <TaskError error={groups.error} retry={() => void groups.refetch()} />}
            {groups.data &&
                !groups.error &&
                (lanes.length === 0 ? (
                    <p data-testid="tasks-board" className="text-dim">
                        No tasks yet.
                    </p>
                ) : (
                    <div
                        data-testid="tasks-board"
                        className="kanban-board grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[repeat(var(--kanban-lanes),minmax(0,1fr))]"
                        style={laneWidths(lanes.length)}
                    >
                        {lanes.map(({ column, cards: tasks }) => (
                            <Frame
                                key={column}
                                title={column}
                                topRight={tasks.length}
                                bodyClassName="space-y-[var(--panel-padding)]"
                                className="min-h-[80px] lg:min-h-[160px]"
                            >
                                {tasks.map((group) => (
                                    <Link
                                        key={group.id}
                                        to="/tasks/$id"
                                        params={{ id: String(group.id) }}
                                        className={kanbanCardClassName}
                                        data-testid="task-group-card"
                                        aria-label={`Open task: ${group.title}`}
                                    >
                                        <KanbanCardBody
                                            identity={taskIdentity(group.id, group.project_code)}
                                            task={group}
                                            durationMs={liveDurationMs(
                                                group,
                                                groups.dataUpdatedAt,
                                                now,
                                            )}
                                            progress={completedSubtaskProgress(group.tasks)}
                                        />
                                    </Link>
                                ))}
                            </Frame>
                        ))}
                    </div>
                ))}
        </div>
    );
}

export function TaskDetail() {
    const { id } = useParams({ from: "/tasks/$id" });
    return <TaskDetailView id={id} />;
}

export function SubtaskDetail() {
    const { id, subtaskId } = useParams({ from: "/tasks/$id/subtasks/$subtaskId" });
    return <TaskDetailView id={id} subtaskId={subtaskId} />;
}

function TaskDetailView({ id, subtaskId }: { id: string; subtaskId?: string }) {
    const router = useRouter();
    const extensions = useQuery(extensionsQuery);
    const tasksEnabled = extensions.data?.tasks === true;
    const group = useQuery({
        ...taskGroupQuery(id),
        enabled: tasksEnabled,
        refetchInterval: useTaskPoll(),
    });
    const projects = useQuery({ ...lists.projects, enabled: tasksEnabled });
    const go = useGo();
    const task = group.data;
    const project = projects.data?.find((item) => item.id === task?.project_id);
    const detail =
        subtaskId === undefined ? task : task?.tasks.find((item) => String(item.id) === subtaskId);
    const question = detail === undefined ? null : taskDirectionQuestion(detail);
    // The group's duration counts forward every second while it is active. A subtask's duration is
    // the value the Gateway stored when it last refreshed the group.
    const countsForward =
        subtaskId === undefined && task !== undefined && isActiveTaskGroup(task.status);
    const now = useNow(1_000, countsForward);
    const subtaskLanes = filledLanes(
        subtaskColumns,
        [...(task?.tasks ?? [])].sort((a, b) => a.position - b.position),
    );
    if (!tasksEnabled) {
        return (
            <Frame title="Tasks" state="warn">
                <p role={extensions.isPending ? "status" : undefined}>
                    {extensions.isPending
                        ? "Loading Gateway extension state…"
                        : "The tasks extension is disabled on this Gateway."}
                </p>
            </Frame>
        );
    }
    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            <PageHeader
                titleTestId={subtaskId === undefined ? "task-group-title" : "subtask-title"}
                trail={[
                    { label: "Tasks", open: () => go.section("tasks") },
                    {
                        label: task?.title ?? `Task #${id}`,
                        open:
                            subtaskId === undefined
                                ? undefined
                                : () => {
                                      void router.navigate({ to: "/tasks/$id", params: { id } });
                                  },
                    },
                    ...(subtaskId === undefined
                        ? []
                        : [{ label: detail?.title ?? `Subtask #${subtaskId}` }]),
                ]}
            />
            {group.isPending && <p role="status">Loading task…</p>}
            {group.error && <TaskError error={group.error} retry={() => void group.refetch()} />}
            {task && subtaskId !== undefined && !detail && !group.error && (
                <p role="alert">Subtask not found in this task.</p>
            )}
            {task && detail && (
                <>
                    {question !== null && (
                        <Frame title="Needs your direction" className="min-w-0 shrink-0">
                            <p className="selectable whitespace-pre-wrap [overflow-wrap:anywhere] text-cyan">
                                {question}
                            </p>
                            <p className="mt-[8px] text-dim">
                                Answer through the CLI, MCP, or API. This board is read-only.
                            </p>
                        </Frame>
                    )}
                    <div className="grid min-w-0 shrink-0 grid-cols-1 gap-[var(--panel-gap)] lg:grid-cols-2">
                        <Properties
                            title="Task"
                            className="min-h-0"
                            properties={taskProperties(
                                task,
                                detail,
                                "tasks" in detail
                                    ? liveDurationMs(detail, group.dataUpdatedAt, now)
                                    : detail.duration_ms,
                                project?.name ?? task.project,
                                project === undefined
                                    ? undefined
                                    : () => go.record("projects", project),
                                task.taskable_id === null
                                    ? undefined
                                    : () => {
                                          void router.navigate({
                                              to: "/$section/$id",
                                              params: {
                                                  section: "instances",
                                                  id: String(task.taskable_id),
                                              },
                                          });
                                      },
                            )}
                        />
                        <Frame title="Description" className="h-[320px] min-h-0 lg:h-auto">
                            <p className="selectable whitespace-pre-wrap break-words">
                                {detail.brief || "No description provided."}
                            </p>
                        </Frame>
                    </div>
                    {subtaskId === undefined && (
                        <section
                            aria-label="Subtasks"
                            className="shrink-0 lg:flex lg:min-h-0 lg:flex-1 lg:basis-0 lg:flex-col"
                        >
                            {subtaskLanes.length === 0 ? (
                                <p className="text-dim">No subtasks yet.</p>
                            ) : (
                                <div
                                    className="kanban-board grid grid-cols-1 lg:min-h-0 lg:flex-1 lg:grid-cols-[repeat(var(--kanban-lanes),minmax(0,1fr))] lg:grid-rows-[minmax(0,1fr)]"
                                    style={laneWidths(subtaskLanes.length)}
                                >
                                    {subtaskLanes.map(({ column, cards: subtasks }) => (
                                        <Frame
                                            key={column}
                                            title={column}
                                            topRight={subtasks.length}
                                            className="min-h-[160px]"
                                            bodyClassName="space-y-[var(--panel-padding)]"
                                        >
                                            {subtasks.map((subtask) => (
                                                <Link
                                                    to="/tasks/$id/subtasks/$subtaskId"
                                                    params={{ id, subtaskId: String(subtask.id) }}
                                                    key={subtask.id}
                                                    data-testid="subtask-card"
                                                    aria-label={`Open subtask: ${subtask.title}`}
                                                    className={kanbanCardClassName}
                                                >
                                                    <KanbanCardBody
                                                        identity={taskIdentity(
                                                            subtask.id,
                                                            task.project_code,
                                                        )}
                                                        task={subtask}
                                                    />
                                                </Link>
                                            ))}
                                        </Frame>
                                    ))}
                                </div>
                            )}
                        </section>
                    )}
                    {"tasks" in detail ? (
                        <div className="flex min-w-0 flex-col md:min-h-0 md:flex-1 lg:basis-0">
                            <AgentSessions
                                key={`${id}:group`}
                                groupId={task.id}
                                projectCode={task.project_code}
                            />
                        </div>
                    ) : (
                        <div
                            key={`${id}:${detail.id}`}
                            className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:min-h-0 md:flex-1 lg:grid lg:basis-0 lg:grid-cols-[minmax(0,1fr)_minmax(36ch,52ch)] lg:grid-rows-[minmax(0,1fr)]"
                        >
                            <AgentSessions
                                groupId={task.id}
                                subtaskId={subtaskId}
                                projectCode={task.project_code}
                            />
                            <TaskComments groupId={task.id} task={detail} />
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
