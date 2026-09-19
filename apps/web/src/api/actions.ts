import { processRuntimeIsActive } from "../fleet/fleet";
import { applyRow } from "../realtime/apply";
import { api } from "./client";
import { queryClient } from "./queryClient";
import type {
    AnyRecord,
    App,
    Database,
    DoctorReport,
    FirewallRule,
    Instance,
    Kind,
    Node,
    Process,
    Schedule,
} from "./types";

/**
 * One entry in a record's actions menu. `run` sends the same request the matching command sends.
 * An action with `command` instead has no synchronous request: the menu names the command to run
 * in a terminal and does not fabricate a result.
 */
export type Action = {
    label: string;
    description: string;
    destructive?: boolean;
    command?: string;
    run?: () => Promise<string>;
    /** An action that prints a report: the menu shows what this resolves to in a modal. */
    report?: () => Promise<{ ok: boolean; output: string }>;
};

const leaves = (label: string, command: string, description: string): Action => ({
    label,
    command,
    description,
});

async function processAction(
    process: Process,
    verb: "start" | "stop" | "restart",
    pastTense: string,
): Promise<string> {
    const row = await api<Process>("POST", `/api/v1/processes/${process.id}/${verb}`);
    applyRow(queryClient, "processes", "updated", row);

    return `Process [${process.name}] ${pastTense}.`;
}

async function scheduleAction(
    schedule: Schedule,
    verb: "run" | "activate",
    pastTense: string,
): Promise<string> {
    const row = await api<Schedule>(
        "POST",
        `/api/v1/schedules/${encodeURIComponent(schedule.id)}/${verb}`,
    );
    applyRow(queryClient, "schedules", "updated", row);

    return `Schedule [${schedule.name}] ${pastTense}.`;
}

export function actionsFor(kind: Kind, row: AnyRecord): Action[] {
    switch (kind) {
        case "nodes": {
            const node = row as Node;

            return [
                {
                    label: "doctor",
                    description: `Check node [${node.name}] for drift.`,
                    run: async () => {
                        const report = await api<DoctorReport>("POST", "/api/v1/doctor", {
                            node_id: node.id,
                        });
                        const drift = Number(report.summary?.drift ?? 0);

                        return drift === 0
                            ? `Node [${node.name}] has no drift.`
                            : `Node [${node.name}] has ${drift} drifted check(s); see doctor --node=${node.id}.`;
                    },
                },
                leaves(
                    "ssh",
                    `orbit node:ssh ${node.name}`,
                    "No request opens a shell; run this from a terminal.",
                ),
            ];
        }
        case "apps": {
            const app = row as App;

            return [
                leaves(
                    "show",
                    `orbit app:show ${app.slug}`,
                    "Open the App record instead of running this from the menu.",
                ),
            ];
        }
        case "instances": {
            const instance = row as Instance;
            const target = `${instance.app.slug}/${instance.name}`;

            return [
                leaves(
                    "deploy",
                    `orbit instance:deploy ${target}`,
                    "A deploy streams for minutes; it is not run from inside the live screen.",
                ),
                {
                    label: "profile",
                    description: `Profile one request to [${target}] from this machine.`,
                    // `orbit profile` sends its GET from the operator's machine, never through the
                    // Gateway, and a browser cannot read another origin's timings. The dev server
                    // runs the command here and returns what it printed.
                    report: async () => {
                        const response = await fetch(`/__orbit/profile?instance=${instance.id}`);

                        if (!response.headers.get("content-type")?.includes("json")) {
                            return {
                                ok: false,
                                output: `This page has no local server to run the command. Run it in a terminal:\n\norbit profile --instance=${instance.id}`,
                            };
                        }

                        return (await response.json()) as { ok: boolean; output: string };
                    },
                },
            ];
        }
        case "processes": {
            const process = row as Process;

            return [
                {
                    label: "restart",
                    description: `Restart process [${process.name}].`,
                    run: () => processAction(process, "restart", "restarted"),
                },
                processRuntimeIsActive(process)
                    ? {
                          label: "stop",
                          description: `Stop process [${process.name}].`,
                          run: () => processAction(process, "stop", "stopped"),
                      }
                    : {
                          label: "start",
                          description: `Start process [${process.name}].`,
                          run: () => processAction(process, "start", "started"),
                      },
                {
                    label: "destroy",
                    destructive: true,
                    description: `Destroy process [${process.name}]? Its unit or container is removed from the node.`,
                    run: async () => {
                        await api("DELETE", `/api/v1/processes/${process.id}`);
                        applyRow(queryClient, "processes", "deleted", { id: process.id });

                        return `Process [${process.name}] destroyed.`;
                    },
                },
            ];
        }
        case "schedules": {
            const schedule = row as Schedule;

            return [
                {
                    label: "run now",
                    description: `Run schedule [${schedule.name}] now.`,
                    run: () => scheduleAction(schedule, "run", "ran"),
                },
                ...(schedule.desired_timer_state === "enabled"
                    ? []
                    : [
                          {
                              label: "enable",
                              description: `Enable schedule [${schedule.name}].`,
                              run: () => scheduleAction(schedule, "activate", "enabled"),
                          },
                      ]),
            ];
        }
        case "databases": {
            const database = row as Database;

            return [
                {
                    label: "destroy",
                    destructive: true,
                    description: `Destroy the Database connection record [${database.slug}]? The physical database is not dropped.`,
                    run: async () => {
                        await api(
                            "DELETE",
                            `/api/v1/database-connections/${encodeURIComponent(database.slug)}`,
                        );
                        applyRow(queryClient, "databases", "deleted", { id: database.id });

                        return `Database connection [${database.slug}] destroyed.`;
                    },
                },
                leaves(
                    "query",
                    `orbit database:query ${database.slug}`,
                    "The query console needs an interactive terminal.",
                ),
            ];
        }
        case "firewall": {
            const rule = row as FirewallRule;

            return [
                {
                    label: "remove",
                    destructive: true,
                    description: `Remove firewall rule [${rule.name}] on node [${rule.node}]?`,
                    run: async () => {
                        await api(
                            "DELETE",
                            `/api/v1/nodes/${rule.node_id}/firewall-rules/${encodeURIComponent(rule.name)}`,
                        );
                        applyRow(queryClient, "firewall", "deleted", { id: rule.id });

                        return `Firewall rule [${rule.name}] removed.`;
                    },
                },
            ];
        }
        default:
            return [];
    }
}
