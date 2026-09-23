import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
    checkDurationMs,
    formatDurationMs,
    formatRelativeTime,
    taskCommentsQuery,
    taskCommentTypeLabel,
    type Task,
    type TaskCheck,
    type TaskComment,
} from "../api/tasks";
import { Frame } from "../ui/Frame";

const metaClassName = "text-[11px] font-medium uppercase tracking-[0.08em]";

function commentColor(type: string): string {
    return type === "approved"
        ? "text-green"
        : type === "blocked"
          ? "text-red"
          : type === "changes_requested" || type === "assistance_requested"
            ? "text-yellow"
            : type === "ready_for_review"
              ? "text-cyan"
              : "text-fg";
}

function checkColor(status: TaskCheck["status"]): string {
    return status === "passed"
        ? "text-green"
        : status === "failed" || status === "lost"
          ? "text-red"
          : status === "running" || status === "changed"
            ? "text-yellow"
            : "text-dim";
}

const checkStatusLabels: Record<NonNullable<TaskCheck["status"]>, string> = {
    running: "Running",
    passed: "Passed",
    failed: "Failed",
    changed: "Changed",
    lost: "Lost",
    cancelled: "Cancelled",
};

function useClock(): number {
    const [now, setNow] = useState(Date.now);
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(timer);
    }, []);
    return now;
}

function CheckCard({ check, now }: { check: TaskCheck; now: number }) {
    const status = check.status ? checkStatusLabels[check.status] : "Unknown";
    const duration = formatDurationMs(checkDurationMs(check, now));
    const facts = [
        duration,
        check.exit_code == null ? null : `Exit ${check.exit_code}`,
        check.failed_step ? `Failed step: ${check.failed_step}` : null,
    ].filter((fact): fact is string => fact !== null);
    return (
        <article aria-label="Latest check" className="kanban-card">
            <div className={`flex justify-between gap-[1ch] ${metaClassName}`}>
                <span className="text-dim">Check · {check.kind ?? "unknown"}</span>
                <span className={checkColor(check.status)}>● {status}</span>
            </div>
            {facts.length > 0 && <p className="mt-[6px] break-words">{facts.join(" · ")}</p>}
            {check.output && (
                <details className="mt-[6px]">
                    <summary className="cursor-pointer text-dim">Output</summary>
                    <pre className="selectable mt-[4px] max-h-[240px] overflow-auto whitespace-pre-wrap break-words text-dim">
                        {check.output}
                    </pre>
                </details>
            )}
        </article>
    );
}

function CommentCard({ comment, now }: { comment: TaskComment; now: number }) {
    const label = taskCommentTypeLabel(comment.type);
    return (
        <article aria-label={`${label} by ${comment.author}`} className="kanban-card">
            <div className={`mb-[6px] flex justify-between gap-[1ch] ${metaClassName}`}>
                <span className="min-w-0 break-words">
                    <span className={commentColor(comment.type)}>{label}</span>
                    <span className="text-dim"> · {comment.author}</span>
                </span>
                <time
                    className="shrink-0 text-dim"
                    dateTime={comment.posted_at}
                    title={new Date(comment.posted_at).toLocaleString()}
                >
                    {formatRelativeTime(comment.posted_at, now)}
                </time>
            </div>
            <p className="selectable whitespace-pre-wrap break-words">{comment.body}</p>
        </article>
    );
}

/** The task's latest check and its comments and run receipts, newest first, as stacked cards. */
export function TaskComments({ groupId, task }: { groupId: number; task: Task }) {
    const query = useQuery(taskCommentsQuery(groupId, task.id));
    const now = useClock();
    const comments = query.data ?? [];
    return (
        <Frame
            title="Comments"
            topRight={query.isSuccess ? comments.length : undefined}
            className="max-h-[560px] shrink-0 lg:max-h-none lg:min-h-0"
            bodyClassName="space-y-[var(--panel-padding)]"
        >
            {task.check && <CheckCard check={task.check} now={now} />}
            {query.isPending && <p role="status">Loading comments…</p>}
            {query.error && (
                <p role="alert">
                    Could not load comments.{" "}
                    <button className="link" onClick={() => void query.refetch()}>
                        Retry comments
                    </button>
                </p>
            )}
            {query.isSuccess && comments.length === 0 && (
                <p className="text-dim">No comments on this task yet.</p>
            )}
            {comments.map((comment) => (
                <CommentCard key={comment.id} comment={comment} now={now} />
            ))}
        </Frame>
    );
}
