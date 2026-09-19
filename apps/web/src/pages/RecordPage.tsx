import { useQuery } from "@tanstack/react-query";
import { useParams } from "@tanstack/react-router";
import { useMemo } from "react";
import {
    databaseTablesQuery,
    databaseUsersQuery,
    deploymentLogQuery,
    deploymentsQuery,
    type Fleet,
    instanceLogsQuery,
    processLogsQuery,
    scheduleLogsQuery,
    useFleet,
} from "../api/queries";
import type {
    App,
    Database,
    DatabaseUser,
    Deployment,
    FirewallRule,
    Instance,
    Node,
    Process,
    Schedule,
} from "../api/types";
import {
    deploymentHealthy,
    firewallHealthy,
    instanceHealthy,
    instanceName,
    instanceNodeName,
    instancesForApp,
    instancesForNode,
    nodeHealthy,
    nodeName,
    processesFor,
    processHealthy,
    processNodeName,
    processOwner,
    processUsage,
    scheduleHealthy,
    schedulesForApp,
    schedulesForInstance,
} from "../fleet/fleet";
import { useNodeMetrics } from "../metrics/grafana";
import { Bar } from "../ui/Bar";
import { Frame, Note } from "../ui/Frame";
import { useGo } from "../ui/go";
import { LogPane } from "../ui/LogPane";
import { type Column, Pane } from "../ui/Pane";
import { Properties } from "../ui/Properties";
import { firewallColumns, instanceColumns, processColumns, scheduleColumns } from "./columns";
import { RecordLayout } from "./RecordLayout";

const GAPS = "gap-x-[1ch] gap-y-[16px]";

/** The node page's htop-like block: cores in two columns, then memory and swap beside the root disk and uptime. */
function NodeMetricsPanel({ node }: { node: Node }) {
    const metrics = useNodeMetrics(node);
    const state = nodeHealthy(node) ? undefined : "warn";

    if (metrics === null) {
        return (
            <Frame title={`${node.name} · ${node.status}`} state={state}>
                <Note>No metrics.</Note>
            </Frame>
        );
    }

    const [mount, used, total] = metrics.disks[0] ?? ["/", 0, 0];

    return (
        <Frame
            title={`${node.name} · ${node.status} · metrics`}
            state={state}
            bottomRight={`up ${metrics.uptime}`}
        >
            <div className="grid grid-cols-2 gap-x-[2ch]">
                {metrics.cores.map((load, core) => (
                    <Bar
                        key={core}
                        label={String(core)}
                        ratio={load}
                        reading={`${(load * 100).toFixed(0).padStart(3)}%`}
                    />
                ))}
            </div>
            <div className="mt-[20px] grid grid-cols-2 gap-x-[2ch]">
                <Bar
                    label="Mem"
                    ratio={metrics.mem[1] > 0 ? metrics.mem[0] / metrics.mem[1] : 0}
                    reading={`${metrics.mem[0].toFixed(1)}G/${metrics.mem[1].toFixed(0)}G`}
                />
                <Bar
                    label={mount}
                    ratio={total > 0 ? used / total : 0}
                    reading={`${used.toFixed(0)}G/${total.toFixed(0)}G`}
                    thresholds={[80, 90]}
                />
                <Bar
                    label="Swp"
                    ratio={metrics.swap[1] > 0 ? metrics.swap[0] / metrics.swap[1] : 0}
                    reading={`${metrics.swap[0].toFixed(1)}G/${metrics.swap[1].toFixed(0)}G`}
                />
            </div>
        </Frame>
    );
}

function NodePage({ fleet, node }: { fleet: Fleet; node: Node }) {
    const columns = useMemo(() => instanceColumns("app"), []);

    return (
        <div
            className={`grid h-full grid-cols-2 grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)] ${GAPS}`}
        >
            <div className={`col-span-2 grid grid-cols-[2fr_3fr] ${GAPS}`}>
                <Properties
                    properties={[
                        { name: "Name", value: node.name },
                        { name: "Status", value: node.status, warn: !nodeHealthy(node) },
                        { name: "Roles", value: node.roles },
                        { name: "Platform", value: node.platform },
                        { name: "Architecture", value: node.architecture },
                        { name: "TLD", value: node.tld },
                        { name: "WireGuard IP", value: node.wireguard_ip },
                        {
                            name: "SSH",
                            value: `${node.user}@${node.public_ssh_host}:${node.public_ssh_port}`,
                        },
                    ]}
                />
                <NodeMetricsPanel node={node} />
            </div>
            <Pane
                name="instances"
                order={1}
                title="Instances on this node"
                className="col-span-2"
                columns={columns}
                rows={instancesForNode(fleet, node.name)}
                rowId={(i) => String(i.id)}
                warn={(i) => !instanceHealthy(i)}
                target={(row) => ({ kind: "instances", row })}
            />
            <Pane
                name="processes"
                order={2}
                title="Node processes"
                columns={processColumns}
                rows={processesFor(fleet, "node", node.id)}
                rowId={(p) => String(p.id)}
                warn={(p) => !processHealthy(p)}
                target={(row) => ({ kind: "processes", row })}
            />
            <Pane
                name="firewall"
                order={3}
                title="Firewall"
                columns={firewallColumns}
                rows={fleet.firewall.filter((rule) => rule.node_id === node.id)}
                rowId={(f) => String(f.id)}
                warn={(f) => !firewallHealthy(f)}
                target={(row) => ({ kind: "firewall", row })}
            />
        </div>
    );
}

function AppPage({ fleet, app }: { fleet: Fleet; app: App }) {
    const columns = useMemo(() => instanceColumns("node"), []);
    const schedules = useMemo(() => scheduleColumns(fleet, "instance"), [fleet]);

    return (
        <div className={`grid h-full grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)] ${GAPS}`}>
            <Properties
                properties={[
                    { name: "Name", value: app.name },
                    { name: "Slug", value: app.slug },
                    { name: "Repository", value: app.repository_url },
                    { name: "Default branch", value: app.default_branch },
                    { name: "Root", value: app.root },
                ]}
            />
            <Pane
                name="instances"
                order={1}
                title="Instances"
                columns={columns}
                rows={instancesForApp(fleet, app.slug)}
                rowId={(i) => String(i.id)}
                warn={(i) => !instanceHealthy(i)}
                target={(row) => ({ kind: "instances", row })}
            />
            <Pane
                name="schedules"
                order={2}
                title="Schedules"
                columns={schedules}
                rows={schedulesForApp(fleet, app.slug)}
                rowId={(s) => String(s.id)}
                warn={(s) => !scheduleHealthy(s)}
                target={(row) => ({ kind: "schedules", row })}
            />
        </div>
    );
}

const deploymentColumns: Column<Deployment>[] = [
    { header: "Started", width: 20, value: (d) => d.started_at },
    { header: "Release", width: 18, value: (d) => d.release ?? "—" },
    { header: "Branch", width: 12, value: (d) => d.branch ?? "—" },
    { header: "Commit", width: 10, value: (d) => d.commit?.slice(0, 7) ?? "—" },
    { header: "By", width: 12, value: (d) => d.triggered_by ?? "—" },
    {
        header: "Duration",
        width: 10,
        value: (d) => (d.duration_seconds === null ? "—" : `${d.duration_seconds}s`),
        sort: (d) => d.duration_seconds ?? -1,
    },
    { header: "Status", width: 14, value: (d) => d.status },
];

function InstancePage({ fleet, instance }: { fleet: Fleet; instance: Instance }) {
    const go = useGo();
    const deployments = useQuery(deploymentsQuery(instance.id));
    const logs = useQuery(instanceLogsQuery(instance.id));
    const hasDeployments = (deployments.data?.length ?? 0) > 0;
    const schedules = useMemo(() => scheduleColumns(fleet, "none"), [fleet]);
    const app = fleet.apps.find((candidate) => candidate.id === instance.app.id);
    const node = fleet.nodes.find((candidate) => candidate.id === instance.node.id);

    return (
        <div className={`grid h-full grid-rows-[auto_auto_minmax(0,1fr)] ${GAPS}`}>
            {/* The deployment history sits beside the properties, and only once there is one. */}
            <div
                className={`grid max-h-[40vh] ${hasDeployments ? "grid-cols-2" : "grid-cols-1"} ${GAPS}`}
            >
                <Properties
                    properties={[
                        { name: "Name", value: instance.name },
                        {
                            name: "App",
                            value: instance.app.slug,
                            onOpen: app === undefined ? undefined : () => go.record("apps", app),
                        },
                        {
                            name: "Node",
                            value: instance.node.name,
                            onOpen: node === undefined ? undefined : () => go.record("nodes", node),
                        },
                        { name: "Environment", value: instance.environment },
                        {
                            name: "Domain",
                            value: instance.domain,
                            // Caddy terminates TLS for every route, so the site answers on https.
                            onOpen: () =>
                                window.open(`https://${instance.domain}`, "_blank", "noopener"),
                        },
                        {
                            name: "Status",
                            value: instance.status,
                            warn: !instanceHealthy(instance),
                        },
                        { name: "Checkout", value: instance.checkout_path },
                        { name: "Selected branch", value: instance.selected_branch },
                        { name: "Deploy steps", value: `${instance.deploy_steps.length} steps` },
                    ]}
                />
                {hasDeployments && (
                    <Pane
                        name="deployments"
                        order={0}
                        title="Deployments"
                        columns={deploymentColumns}
                        rows={deployments.data ?? []}
                        rowId={(d) => String(d.id)}
                        warn={(d) => !deploymentHealthy(d)}
                        target={(row) => ({ kind: "deployments", row })}
                    />
                )}
            </div>
            <div className={`grid max-h-[30vh] grid-cols-2 ${GAPS}`}>
                <Pane
                    name="processes"
                    order={1}
                    title="Processes"
                    columns={processColumns}
                    rows={processesFor(fleet, "instance", instance.id)}
                    rowId={(p) => String(p.id)}
                    warn={(p) => !processHealthy(p)}
                    target={(row) => ({ kind: "processes", row })}
                />
                <Pane
                    name="schedules"
                    order={2}
                    title="Schedules"
                    columns={schedules}
                    rows={schedulesForInstance(fleet, instance.id)}
                    rowId={(s) => String(s.id)}
                    warn={(s) => !scheduleHealthy(s)}
                    target={(row) => ({ kind: "schedules", row })}
                />
            </div>
            <LogPane
                title="Application log · storage/logs/laravel.log"
                lines={logs.data}
                loading={logs.isPending}
            />
        </div>
    );
}

const userColumns: Column<DatabaseUser>[] = [
    { header: "Username", width: 24, value: (u) => u.username },
    { header: "Privileges", width: 46, value: (u) => u.privileges },
    { header: "Created by", width: 30, value: (u) => u.created_by ?? "—" },
];

const tableColumns: Column<{ name: string }>[] = [
    { header: "Table", width: 100, value: (table) => table.name },
];

function DatabasePage({ fleet, database }: { fleet: Fleet; database: Database }) {
    const tables = useQuery(databaseTablesQuery(database.slug));
    const users = useQuery(databaseUsersQuery(database.slug));
    const tableRows = useMemo(() => (tables.data ?? []).map((name) => ({ name })), [tables.data]);

    return (
        <div className={`grid h-full grid-cols-[45fr_55fr] grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}>
            <Properties
                className="col-span-2"
                properties={[
                    { name: "Slug", value: database.slug },
                    { name: "Driver", value: database.driver },
                    { name: "Node", value: nodeName(fleet, Number(database.node_id)) },
                    {
                        name: "Host",
                        value: database.host === null ? null : `${database.host}:${database.port}`,
                    },
                    { name: "Path", value: database.path },
                    { name: "Database", value: database.database },
                    { name: "Username", value: database.username },
                    { name: "Password", value: database.has_password ? "••••••••" : null },
                ]}
            />
            <Pane
                name="tables"
                order={1}
                title="Tables · database:tables"
                columns={tableColumns}
                rows={tableRows}
                rowId={(table) => table.name}
                empty={tables.isPending ? "Loading…" : "No tables."}
            />
            {users.isError ? (
                <Frame title="Users">
                    <Note>Database users unavailable right now.</Note>
                </Frame>
            ) : (
                <Pane
                    name="users"
                    order={2}
                    title="Users"
                    columns={userColumns}
                    rows={users.data ?? []}
                    rowId={(u) => u.username}
                    empty={users.isPending ? "Loading…" : "No users recorded."}
                />
            )}
        </div>
    );
}

function ProcessPage({ fleet, process }: { fleet: Fleet; process: Process }) {
    const logs = useQuery(processLogsQuery(process.id));

    return (
        <div className={`grid h-full grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}>
            <Properties
                properties={[
                    { name: "Name", value: process.name },
                    { name: "Owner", value: processOwner(fleet, process) },
                    { name: "Node", value: processNodeName(fleet, process) },
                    { name: "Runtime", value: process.runtime },
                    { name: "Working directory", value: process.working_directory },
                    { name: "Restart policy", value: process.restart_policy },
                    { name: "Desired state", value: process.desired_state },
                    {
                        name: "Runtime status",
                        value: process.runtime_status,
                        warn: !processHealthy(process),
                    },
                    { name: "CPU/MEM", value: processUsage(process) },
                ]}
            />
            <LogPane title="Log · process:logs" lines={logs.data} loading={logs.isPending} />
        </div>
    );
}

function SchedulePage({ fleet, schedule }: { fleet: Fleet; schedule: Schedule }) {
    const logs = useQuery(scheduleLogsQuery(schedule.id));

    return (
        <div className={`grid h-full grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}>
            <Properties
                properties={[
                    { name: "Name", value: schedule.name },
                    { name: "Instance", value: instanceName(fleet, schedule.target_id) },
                    { name: "Node", value: instanceNodeName(fleet, schedule.target_id) },
                    { name: "Calendar", value: schedule.calendar },
                    { name: "Timeout", value: `${schedule.timeout_seconds} s` },
                    {
                        name: "Desired timer",
                        value: schedule.desired_timer_state,
                        warn: schedule.desired_timer_state !== "enabled",
                    },
                    { name: "Status", value: schedule.status, warn: schedule.status === "failed" },
                    { name: "Last run", value: schedule.last_run_at ?? "never" },
                    { name: "Last run status", value: schedule.last_run_status },
                ]}
            />
            <LogPane title="Log · schedule:logs" lines={logs.data} loading={logs.isPending} />
        </div>
    );
}

function FirewallPage({ rule }: { rule: FirewallRule }) {
    return (
        <div className="grid h-full grid-rows-[auto]">
            <Properties
                properties={[
                    { name: "Name", value: rule.name },
                    { name: "Port", value: rule.port },
                    { name: "Protocol", value: rule.protocol },
                    { name: "Action", value: rule.action },
                    { name: "Source", value: rule.source },
                    { name: "Status", value: rule.status, warn: !firewallHealthy(rule) },
                    { name: "Node", value: rule.node },
                ]}
            />
        </div>
    );
}

/** `/$section/$id`: the record the URL names, read from the live lists so it changes as events arrive. */
export function RecordPage() {
    const { section, id } = useParams({ from: "/$section/$id" });
    const fleet = useFleet();
    const find = <T extends { id: number | string }>(rows: T[]): T | undefined =>
        rows.find((row) => String(row.id) === id);

    const page = (() => {
        switch (section) {
            case "nodes": {
                const node = find(fleet.nodes);

                return (
                    node && (
                        <RecordLayout kind="nodes" row={node}>
                            <NodePage fleet={fleet} node={node} />
                        </RecordLayout>
                    )
                );
            }
            case "apps": {
                const app = find(fleet.apps);

                return (
                    app && (
                        <RecordLayout kind="apps" row={app}>
                            <AppPage fleet={fleet} app={app} />
                        </RecordLayout>
                    )
                );
            }
            case "instances": {
                const instance = find(fleet.instances);

                return (
                    instance && (
                        <RecordLayout kind="instances" row={instance}>
                            <InstancePage fleet={fleet} instance={instance} />
                        </RecordLayout>
                    )
                );
            }
            case "processes": {
                const process = find(fleet.processes);

                return (
                    process && (
                        <RecordLayout kind="processes" row={process}>
                            <ProcessPage fleet={fleet} process={process} />
                        </RecordLayout>
                    )
                );
            }
            case "schedules": {
                const schedule = find(fleet.schedules);

                return (
                    schedule && (
                        <RecordLayout kind="schedules" row={schedule}>
                            <SchedulePage fleet={fleet} schedule={schedule} />
                        </RecordLayout>
                    )
                );
            }
            case "databases": {
                const database = find(fleet.databases);

                return (
                    database && (
                        <RecordLayout kind="databases" row={database}>
                            <DatabasePage fleet={fleet} database={database} />
                        </RecordLayout>
                    )
                );
            }
            case "firewall": {
                const rule = find(fleet.firewall);

                return (
                    rule && (
                        <RecordLayout kind="firewall" row={rule}>
                            <FirewallPage rule={rule} />
                        </RecordLayout>
                    )
                );
            }
            default:
                return undefined;
        }
    })();

    return (
        page ?? (
            <Frame title={section}>
                <Note>
                    {fleet.loading || !fleet.processesLoaded
                        ? "Loading…"
                        : `No record ${id} in ${section}.`}
                </Note>
            </Frame>
        )
    );
}

/** `/instances/$id/deployments/$deploymentId`: one deployment's properties and its recorded output. */
export function DeploymentPage() {
    const { id, deploymentId } = useParams({ from: "/instances/$id/deployments/$deploymentId" });
    const deployments = useQuery(deploymentsQuery(Number(id)));
    const log = useQuery(deploymentLogQuery(Number(deploymentId)));
    const deployment = deployments.data?.find((candidate) => String(candidate.id) === deploymentId);

    if (deployment === undefined) {
        return (
            <Frame title="Deployment">
                <Note>{deployments.isPending ? "Loading…" : `No deployment ${deploymentId}.`}</Note>
            </Frame>
        );
    }

    return (
        <RecordLayout kind="deployments" row={deployment}>
            <div className={`grid h-full grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}>
                <Properties
                    properties={[
                        { name: "Release", value: deployment.release },
                        { name: "Branch", value: deployment.branch },
                        { name: "Commit", value: deployment.commit?.slice(0, 7) },
                        { name: "Started", value: deployment.started_at },
                        { name: "Finished", value: deployment.finished_at },
                        {
                            name: "Duration",
                            value:
                                deployment.duration_seconds === null
                                    ? null
                                    : `${deployment.duration_seconds}s`,
                        },
                        {
                            name: "Status",
                            value: deployment.status,
                            warn: !deploymentHealthy(deployment),
                        },
                        { name: "Failed step", value: deployment.failed_step },
                        { name: "Error code", value: deployment.error_code },
                        { name: "Selected release", value: deployment.selected_release },
                        { name: "Triggered by", value: deployment.triggered_by },
                    ]}
                />
                <LogPane
                    title="Log · instance:deployment:show"
                    lines={log.data}
                    loading={log.isPending}
                />
            </div>
        </RecordLayout>
    );
}
