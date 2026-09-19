import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { instanceQueueQuery } from "../api/queries";
import type { Instance, QueueJob, QueueState } from "../api/types";
import { openInNewTab } from "../ui/newTab";
import { type Column, Pane } from "../ui/Pane";
import { Properties } from "../ui/Properties";

const STATES: QueueState[] = ["pending", "completed", "failed"];

/** The class without its namespace, as the Horizon dashboard lists a job. */
const shortName = (name: string): string => name.slice(name.lastIndexOf("\\") + 1);
const clock = (time: string | null): string =>
    time === null ? "—" : new Date(time).toLocaleTimeString([], { hour12: false });

/**
 * The instance's Horizon queue: a tab per job state, the newest jobs in it, and the workload in the
 * border. A job opens its page in the Horizon dashboard. An instance without Horizon has no panel.
 */
export function QueuePanel({ instance }: { instance: Instance }) {
    const [state, setState] = useState<QueueState>("pending");
    const queue = useQuery(instanceQueueQuery(instance.id, state)).data;

    const columns = useMemo<Column<QueueJob>[]>(
        () => [
            { header: "Job", width: 30, value: (job) => shortName(job.name) },
            { header: "Queue", width: 12, fit: true, value: (job) => job.queue },
            ...(state === "failed"
                ? ([
                      { header: "Exception", width: 46, value: (job) => job.exception ?? "—" },
                  ] satisfies Column<QueueJob>[])
                : []),
            {
                header:
                    state === "pending" ? "Pushed" : state === "completed" ? "Completed" : "Failed",
                width: 12,
                fit: true,
                value: (job) =>
                    clock(
                        state === "pending"
                            ? job.pushed_at
                            : state === "completed"
                              ? job.completed_at
                              : job.failed_at,
                    ),
            },
        ],
        [state],
    );

    if (queue === undefined || !queue.available) {
        return null;
    }

    const tabs = (
        <span role="tablist" onMouseDown={(event) => event.stopPropagation()}>
            {STATES.map((name) => (
                <span
                    key={name}
                    role="tab"
                    aria-selected={name === state}
                    className={`ml-[2ch] cursor-pointer ${name === state ? "text-fg" : "hover:text-fg"} ${name === "failed" && (queue.totals?.failed ?? 0) > 0 ? "text-red" : ""}`}
                    onClick={() => setState(name)}
                >
                    {name} {queue.totals?.[name] ?? 0}
                </span>
            ))}
        </span>
    );
    const dashboard = queue.dashboard_url ?? null;

    return (
        <div className="grid max-h-[30vh] grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-x-[1ch]">
            <Properties
                title="Queue"
                properties={[
                    { name: "Status", value: queue.status, warn: queue.status !== "running" },
                    { name: "Jobs per minute", value: queue.jobs_per_minute },
                    { name: "Recent jobs", value: queue.recent_jobs },
                    {
                        name: "Recently failed",
                        value: queue.recently_failed_jobs,
                        warn: (queue.recently_failed_jobs ?? 0) > 0,
                    },
                    { name: "Processes", value: queue.processes },
                    ...(queue.queues ?? []).map((entry) => ({
                        name: `Queue ${entry.name}`,
                        // Waiting jobs, how long a new job waits, and the workers on this queue.
                        value: `${entry.length} jobs · ${entry.wait_seconds ?? 0}s wait · ${entry.processes} workers`,
                    })),
                    {
                        name: "Dashboard",
                        value: dashboard === null ? null : dashboard.replace(/^https?:\/\//, ""),
                        onOpen: dashboard === null ? undefined : () => openInNewTab(dashboard),
                    },
                ]}
            />
            <Pane
                name="queue"
                order={3}
                title="Jobs"
                topRight={tabs}
                columns={columns}
                rows={queue.jobs ?? []}
                rowId={(job) => job.id}
                warn={(job) => job.status === "failed"}
                onRowClick={(job) => {
                    if (job.url !== null) {
                        openInNewTab(job.url);
                    }
                }}
                empty={`No ${state} jobs.`}
            />
        </div>
    );
}
