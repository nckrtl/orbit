import type { Fleet } from "../api/queries";
import type {
    AnyRecord,
    Deployment,
    FirewallRule,
    Instance,
    Kind,
    Node,
    Process,
    Schedule,
} from "../api/types";

// ---- health: whether a row needs a look, in the Gateway's own vocabulary ----
//
// A record's provisioning `status` and its runtime or desired state use different enums. These
// are the one place that decides "needs attention" per family.

export const nodeHealthy = (node: Node): boolean => node.status === "active";
export const instanceHealthy = (instance: Instance): boolean => instance.status === "active";
export const firewallHealthy = (rule: FirewallRule): boolean => rule.status === "active";
export const deploymentHealthy = (deployment: Deployment): boolean =>
    deployment.status === "succeeded";
export const scheduleHealthy = (schedule: Schedule): boolean =>
    schedule.desired_timer_state === "enabled" && schedule.status !== "failed";

/** A systemd process reports active/inactive; a Docker process reports running/exited. */
const runtimeVocabulary = (process: Process): [string, string] =>
    process.runtime === "docker" ? ["running", "exited"] : ["active", "inactive"];

export const processRuntimeIsActive = (process: Process): boolean =>
    process.runtime_status === runtimeVocabulary(process)[0];

export function processHealthy(process: Process): boolean {
    const [active, inactive] = runtimeVocabulary(process);

    return process.desired_state === "running"
        ? process.runtime_status === active
        : process.desired_state === "stopped" && process.runtime_status === inactive;
}

// ---- names and relations ----

export const nodeName = (fleet: Fleet, id: number): string =>
    fleet.nodes.find((node) => node.id === id)?.name ?? "—";

export function instanceName(fleet: Fleet, id: number): string {
    const instance = fleet.instances.find((candidate) => candidate.id === id);

    return instance === undefined ? "—" : `${instance.project.slug}/${instance.name}`;
}

export const instanceNodeName = (fleet: Fleet, id: number): string =>
    fleet.instances.find((instance) => instance.id === id)?.node.name ?? "—";

export const runtimeOwner = (fleet: Fleet, runtime: Process | Schedule): string =>
    runtime.target_type === "node"
        ? `node ${nodeName(fleet, runtime.target_id)}`
        : instanceName(fleet, runtime.target_id);

export const runtimeNodeName = (fleet: Fleet, runtime: Process | Schedule): string =>
    runtime.target_type === "node"
        ? nodeName(fleet, runtime.target_id)
        : instanceNodeName(fleet, runtime.target_id);

export const instancesForNode = (fleet: Fleet, name: string): Instance[] =>
    fleet.instances.filter((instance) => instance.node.name === name);
export const instancesForProject = (fleet: Fleet, slug: string): Instance[] =>
    fleet.instances.filter((instance) => instance.project.slug === slug);
export const processesFor = (fleet: Fleet, type: "node" | "instance", id: number): Process[] =>
    fleet.processes.filter((process) => process.target_type === type && process.target_id === id);
export const schedulesForInstance = (fleet: Fleet, id: number): Schedule[] =>
    fleet.schedules.filter(
        (schedule) => schedule.target_type === "instance" && schedule.target_id === id,
    );

export function schedulesForProject(fleet: Fleet, slug: string): Schedule[] {
    const ids = new Set(instancesForProject(fleet, slug).map((instance) => instance.id));

    return fleet.schedules.filter(
        (schedule) => schedule.target_type === "instance" && ids.has(schedule.target_id),
    );
}

/** The section's list, narrowed by the node and project filters where the section admits them. */
export function listRows(
    fleet: Fleet,
    section: Kind,
    nodeFilter: string | undefined,
    projectFilter: string | undefined,
): AnyRecord[] {
    const ids = new Set(
        fleet.instances
            .filter(
                (instance) =>
                    (nodeFilter === undefined || instance.node.name === nodeFilter) &&
                    (projectFilter === undefined || instance.project.slug === projectFilter),
            )
            .map((instance) => instance.id),
    );
    const matchesRuntimeTarget = (runtime: Process | Schedule): boolean =>
        runtime.target_type === "node"
            ? projectFilter === undefined &&
              (nodeFilter === undefined || nodeName(fleet, runtime.target_id) === nodeFilter)
            : ids.has(runtime.target_id);

    switch (section) {
        case "nodes":
            return fleet.nodes;
        case "projects":
            return fleet.projects;
        case "instances":
            return fleet.instances.filter((instance) => ids.has(instance.id));
        case "processes":
            return fleet.processes.filter(matchesRuntimeTarget);
        case "schedules":
            return fleet.schedules.filter(matchesRuntimeTarget);
        case "databases":
            return fleet.databases.filter(
                (database) =>
                    nodeFilter === undefined ||
                    nodeName(fleet, Number(database.node_id)) === nodeFilter,
            );
        case "firewall":
            return fleet.firewall.filter(
                (rule) => nodeFilter === undefined || rule.node === nodeFilter,
            );
        default:
            return [];
    }
}

export type AttentionRow = {
    id: string;
    kind: Kind;
    record: AnyRecord;
    label: string;
    name: string;
    where: string;
    state: string;
};

/** Everything that is yellow somewhere, gathered for the dashboard's "Needs attention" list. */
export function attentionRows(fleet: Fleet): AttentionRow[] {
    return [
        ...fleet.nodes
            .filter((node) => !nodeHealthy(node))
            .map(
                (node): AttentionRow => ({
                    id: `node-${node.id}`,
                    kind: "nodes",
                    record: node,
                    label: "Node",
                    name: node.name,
                    where: "—",
                    state: node.status,
                }),
            ),
        ...fleet.instances
            .filter((instance) => !instanceHealthy(instance))
            .map(
                (instance): AttentionRow => ({
                    id: `instance-${instance.id}`,
                    kind: "instances",
                    record: instance,
                    label: "Instance",
                    name: `${instance.project.slug}/${instance.name}`,
                    where: instance.node.name,
                    state: instance.status,
                }),
            ),
        ...fleet.processes
            .filter((process) => !processHealthy(process))
            .map(
                (process): AttentionRow => ({
                    id: `process-${process.id}`,
                    kind: "processes",
                    record: process,
                    label: "Process",
                    name: process.name,
                    where: runtimeOwner(fleet, process),
                    state: `${process.runtime_status}, wanted ${process.desired_state}`,
                }),
            ),
        ...fleet.schedules
            .filter((schedule) => !scheduleHealthy(schedule))
            .map(
                (schedule): AttentionRow => ({
                    id: `schedule-${schedule.id}`,
                    kind: "schedules",
                    record: schedule,
                    label: "Schedule",
                    name: schedule.name,
                    where: runtimeOwner(fleet, schedule),
                    state: schedule.status === "failed" ? "failed" : schedule.desired_timer_state,
                }),
            ),
        ...fleet.firewall
            .filter((rule) => !firewallHealthy(rule))
            .map(
                (rule): AttentionRow => ({
                    id: `firewall-${rule.id}`,
                    kind: "firewall",
                    record: rule,
                    label: "Firewall",
                    name: `${rule.port}/${rule.protocol} ${rule.action} ${rule.source}`,
                    where: rule.node,
                    state: rule.status,
                }),
            ),
    ];
}

const off = <T>(rows: T[], healthy: (row: T) => boolean): number =>
    rows.filter((row) => !healthy(row)).length;

/** Per section: how many records, and how many of them need a look. */
export function counts(fleet: Fleet): Record<Exclude<Kind, "deployments">, [number, number]> {
    return {
        nodes: [fleet.nodes.length, off(fleet.nodes, nodeHealthy)],
        projects: [fleet.projects.length, 0],
        instances: [fleet.instances.length, off(fleet.instances, instanceHealthy)],
        processes: [fleet.processes.length, off(fleet.processes, processHealthy)],
        schedules: [fleet.schedules.length, off(fleet.schedules, scheduleHealthy)],
        databases: [fleet.databases.length, 0],
        firewall: [fleet.firewall.length, off(fleet.firewall, firewallHealthy)],
    };
}

/** A Process's live CPU share, such as `20%`. */
export function processCpu(process: Process): string {
    return typeof process.cpu === "number" ? `${(process.cpu * 100).toFixed(0)}%` : "—";
}

/** A Process's live memory use, such as `1.2G`. */
export function processMemory(process: Process): string {
    return typeof process.memory_bytes === "number"
        ? `${(process.memory_bytes / 1024 ** 3).toFixed(1)}G`
        : "—";
}

export function recordTitle(kind: Kind, row: AnyRecord): string {
    switch (kind) {
        case "nodes":
            return (row as Node).name;
        case "instances":
            return `${(row as Instance).project.slug}/${(row as Instance).name}`;
        case "projects":
        case "databases":
            return (row as { slug: string }).slug;
        case "firewall": {
            const rule = row as FirewallRule;

            return `${rule.port}/${rule.protocol} ${rule.action} ${rule.source}`;
        }
        case "deployments":
            return `release ${(row as Deployment).release ?? "—"}`;
        default:
            return (row as { name: string }).name;
    }
}
