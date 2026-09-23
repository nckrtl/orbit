import { AgentSessions } from "../tasks/AgentSessions";
import { useQuery } from "@tanstack/react-query";
import { Link, useParams, useRouter } from "@tanstack/react-router";
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
    taskColumn,
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

const columns: TaskColumn[] = ["Backlog", "Todo", "In progress", "Done"];
const subtaskColumns: TaskColumn[] = ["Todo", "In progress", "Done"];

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

function taskProperties(
    group: TaskGroup,
    detail: TaskGroup | Task,
    projectName: string,
    openProject?: () => void,
    openInstance?: () => void,
) {
    return [
        { name: "Title", value: detail.title },
        ...(group.execution_mode === "existing_thread"
            ? [
                  { name: "Type", value: "Annotation" },
                  { name: "Execution", value: "Existing T3 thread" },
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
        {
            name: "Tokens",
            value: formatCompactCount(detail.tokens),
            title: formatTokens(detail.tokens) ?? undefined,
        },
        ...lineDiffProperty(detail),
        { name: "Duration", value: formatDurationMs(detail.duration_ms) },
    ];
}

function TaskError({ error, retry }: { error: Error; retry: () => void }) {
    const disabled = error instanceof GatewayError && error.code === "tasks.disabled";
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
    progress,
}: {
    task: TaskGroup | Task;
    progress?: { completed: number; total: number };
}) {
    const column = taskColumn(task.status);
    if (column === "Backlog" || column === "Todo") return null;

    const duration = formatCardDuration(task.duration_ms);
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
    progress,
}: {
    identity: string;
    task: TaskGroup | Task;
    progress?: { completed: number; total: number };
}) {
    return (
        <>
            <div
                className={`mb-[6px] flex justify-between gap-[1ch] text-dim ${cardMetaClassName}`}
            >
                <span className="break-words">{identity}</span>
                <CardDiff task={task} />
            </div>
            <h2 className="break-words font-bold">{task.title}</h2>
            <CardFooter task={task} progress={progress} />
        </>
    );
}

const kanbanCardClassName =
    "kanban-card block rounded-[2px] focus-visible:outline-2 focus-visible:outline-cyan";

export function TasksBoard({ instanceId }: { instanceId?: number } = {}) {
    const groups = useQuery(taskGroupsQuery);
    const visibleGroups =
        instanceId === undefined ? groups.data : tasksForInstance(groups.data ?? [], instanceId);
    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            {instanceId === undefined && <PageHeader trail={[{ label: "Tasks" }]} />}
            {groups.isPending && <p role="status">Loading tasks…</p>}
            {groups.error && <TaskError error={groups.error} retry={() => void groups.refetch()} />}
            {groups.data && !groups.error && (
                <div className="kanban-board grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-4">
                    {columns.map((column) => {
                        const tasks = (visibleGroups ?? []).filter(
                            (group) => taskColumn(group.status) === column,
                        );
                        return (
                            <Frame
                                key={column}
                                title={column}
                                topRight={tasks.length}
                                bodyClassName="space-y-[var(--panel-padding)]"
                                className="min-h-[160px]"
                            >
                                {tasks.length === 0 && (
                                    <p className="text-dim">
                                        {column === "Backlog"
                                            ? "No tasks being prepared."
                                            : column === "Todo"
                                              ? "No tasks waiting."
                                              : column === "In progress"
                                                ? "No tasks in progress."
                                                : "No finished tasks yet."}
                                    </p>
                                )}
                                {tasks.map((group) => (
                                    <Link
                                        key={group.id}
                                        to="/tasks/$id"
                                        params={{ id: String(group.id) }}
                                        className={kanbanCardClassName}
                                        aria-label={`Open task: ${group.title}`}
                                    >
                                        <KanbanCardBody
                                            identity={taskIdentity(group.id, group.project_code)}
                                            task={group}
                                            progress={completedSubtaskProgress(group.tasks)}
                                        />
                                    </Link>
                                ))}
                            </Frame>
                        );
                    })}
                </div>
            )}
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
    const group = useQuery(taskGroupQuery(id));
    const projects = useQuery(lists.projects);
    const go = useGo();
    const task = group.data;
    const project = projects.data?.find((item) => item.id === task?.app_id);
    const detail =
        subtaskId === undefined ? task : task?.tasks.find((item) => String(item.id) === subtaskId);
    return (
        <div className="flex min-w-0 flex-col gap-[var(--panel-gap)] md:h-full">
            <PageHeader
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
                    <div className="grid min-w-0 shrink-0 grid-cols-1 gap-[var(--panel-gap)] lg:h-[320px] lg:grid-cols-2">
                        <Properties
                            title="Task"
                            className="min-h-0"
                            properties={taskProperties(
                                task,
                                detail,
                                project?.name ?? task.app,
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
                        <section aria-label="Subtasks" className="shrink-0">
                            <div className="kanban-board grid grid-cols-1 lg:grid-cols-3">
                                {subtaskColumns.map((column) => {
                                    const subtasks = task.tasks
                                        .filter((subtask) => taskColumn(subtask.status) === column)
                                        .sort((a, b) => a.position - b.position);
                                    return (
                                        <Frame
                                            key={column}
                                            title={column}
                                            topRight={subtasks.length}
                                            className="min-h-[160px]"
                                            bodyClassName="space-y-[var(--panel-padding)]"
                                        >
                                            {subtasks.length === 0 && (
                                                <p className="text-dim">
                                                    {column === "Todo"
                                                        ? "No subtasks waiting."
                                                        : column === "In progress"
                                                          ? "No subtasks in progress."
                                                          : "No finished subtasks yet."}
                                                </p>
                                            )}
                                            {subtasks.map((subtask) => (
                                                <Link
                                                    to="/tasks/$id/subtasks/$subtaskId"
                                                    params={{ id, subtaskId: String(subtask.id) }}
                                                    key={subtask.id}
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
                                    );
                                })}
                            </div>
                        </section>
                    )}
                    <AgentSessions
                        key={`${id}:${subtaskId ?? "group"}`}
                        groupId={task.id}
                        subtaskId={subtaskId}
                        projectCode={task.project_code}
                    />
                </>
            )}
        </div>
    );
}
