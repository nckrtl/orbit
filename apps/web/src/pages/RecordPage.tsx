import { taskGroupsQuery, tasksForInstance } from "../api/tasks";
import { useTaskPoll } from "../realtime/polling";
import { TasksBoard } from "./Tasks";
import { ProjectCodeEditor } from "../ui/ProjectCodeEditor";
import { useQuery } from "@tanstack/react-query";
import { useParams } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import {
    databaseTablesQuery,
    databaseUsersQuery,
    deploymentLogQuery,
    deploymentsQuery,
    type Fleet,
    instanceAnalyticsQuery,
    liveFirewallQuery,
    managedFirewallQuery,
    scheduleLogsQuery,
    useFleet,
} from "../api/queries";
import type {
    Database,
    DatabaseUser,
    Deployment,
    FirewallRule,
    Instance,
    InstanceAnalytics,
    LiveFirewallMatch,
    LiveFirewallRule,
    ManagedFirewallRule,
    Node,
    Process,
    Project,
    Schedule,
} from "../api/types";
import {
    deploymentHealthy,
    firewallHealthy,
    instanceHealthy,
    instanceName,
    instanceNodeName,
    instancesForProject,
    instancesForNode,
    nodeHealthy,
    nodeName,
    processesFor,
    processCpu,
    processHealthy,
    processMemory,
    processNodeName,
    processOwner,
    scheduleHealthy,
    schedulesForProject,
    schedulesForInstance,
} from "../fleet/fleet";
import { firewallLineTone, firewallPort, firewallSource } from "../fleet/firewall";
import { useNodeMetrics } from "../metrics/grafana";
import { useFallbackPoll } from "../realtime/liveness";
import { Bar } from "../ui/Bar";
import { Frame, Note } from "../ui/Frame";
import { useGo } from "../ui/go";
import { LogPane } from "../ui/LogPane";
import { useLogTail } from "../realtime/log-stream";
import { openInNewTab } from "../ui/newTab";
import { type Column, Pane } from "../ui/Pane";
import { Properties, type Property } from "../ui/Properties";
import { Status } from "../ui/Status";
import { instanceColumns, processColumns, scheduleColumns } from "./columns";
import { AnalyticsPanel } from "./AnalyticsPanel";
import { QueuePanel } from "./QueuePanel";
import { QuotaProviderPage } from "./Quota";
import { RecordLayout } from "./RecordLayout";

const GAPS = "gap-x-[1ch] gap-y-[var(--panel-gap)]";

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
            <div className="grid grid-cols-1 gap-x-[2ch] sm:grid-cols-2">
                {metrics.cores.map((load, core) => (
                    <Bar
                        key={core}
                        label={String(core)}
                        ratio={load}
                        reading={`${(load * 100).toFixed(0).padStart(3)}%`}
                    />
                ))}
            </div>
            <div className="mt-[20px] grid grid-cols-1 gap-x-[2ch] sm:grid-cols-2">
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

/** One line of a node's firewall: a live UFW rule, a missing desired rule, or a catalog fallback. */
type FirewallLine = {
    key: string;
    name: string;
    port: string;
    action: string;
    source: string;
    state: string;
    match: LiveFirewallMatch | null;
    rule: FirewallRule | null;
};

const firewallLineColumns: Column<FirewallLine>[] = [
    { header: "Name", width: 34, value: (line) => line.name },
    { header: "Port", width: 16, fit: true, value: (line) => line.port },
    { header: "Action", width: 10, fit: true, value: (line) => line.action },
    { header: "Source", width: 26, fit: true, value: (line) => line.source },
    {
        header: "Status",
        width: 14,
        fit: true,
        value: (line) => line.state,
        cell: (line) =>
            line.rule !== null && line.match !== "missing" ? (
                <Status value={line.state} />
            ) : (
                <span
                    className={
                        firewallLineTone(line) === "danger"
                            ? "text-red"
                            : firewallLineTone(line) === "warn"
                              ? "text-yellow"
                              : "text-dim"
                    }
                >
                    {line.state}
                </span>
            ),
    },
];

/**
 * A node's firewall: live UFW first, then desired rules that are missing from live. Drift is red.
 * When live UFW cannot be read, the page falls back to operator rules and the desired catalog.
 */
function NodeFirewall({
    fleet,
    node,
    className,
}: {
    fleet: Fleet;
    node: Node;
    className?: string;
}) {
    const managed = useQuery(managedFirewallQuery(node.id)).data;
    const live = useQuery(liveFirewallQuery(node.id)).data;
    const operator = useMemo(
        () => fleet.firewall.filter((rule) => rule.node_id === node.id),
        [fleet.firewall, node.id],
    );
    const lines = useMemo<FirewallLine[]>(() => {
        if (live !== undefined && live.backend_status === "active") {
            return [
                ...live.live.map((rule, index) => liveLine(rule, operator, `live-${index}`)),
                ...live.missing.map((rule, index) => liveLine(rule, operator, `missing-${index}`)),
            ];
        }

        return [
            ...operator.map((rule) => ({
                key: `operator-${rule.id}`,
                name: rule.name,
                port: firewallPort(rule.port, rule.protocol),
                action: rule.action,
                source: rule.source,
                state: rule.status,
                match: null,
                rule,
            })),
            ...(managed ?? []).map((rule, index) => intendedLine(rule, index)),
        ];
    }, [live, managed, operator]);

    const tone = (line: FirewallLine) => firewallLineTone(line);
    const empty =
        live !== undefined && live.backend_status !== "active" && lines.length === 0
            ? `Live UFW is ${live.backend_status}.`
            : "No firewall rules.";

    return (
        <Pane
            name="firewall"
            order={3}
            title="Firewall"
            className={className}
            columns={firewallLineColumns}
            rows={lines}
            rowId={(line) => line.key}
            warn={(line) => tone(line) === "warn"}
            danger={(line) => tone(line) === "danger"}
            target={(line) => (line.rule === null ? null : { kind: "firewall", row: line.rule })}
            divide={{ label: "Missing from live", below: (line) => line.match === "missing" }}
            empty={empty}
        />
    );
}

function liveLine(rule: LiveFirewallRule, operator: FirewallRule[], key: string): FirewallLine {
    const record = operator.find((candidate) => candidate.name === rule.name) ?? null;
    const state =
        rule.match === "missing"
            ? "missing"
            : rule.match === "exact" && record !== null
              ? record.status
              : rule.match === "exact"
                ? "live"
                : "drift";

    return {
        key,
        name: rule.name,
        port: firewallPort(rule.port, rule.protocol),
        action: rule.action,
        source: firewallSource(rule.source, rule.interface),
        state,
        match: rule.match,
        rule: record,
    };
}

function intendedLine(rule: ManagedFirewallRule, index: number): FirewallLine {
    return {
        key: `orbit-${index}`,
        name: rule.name,
        port: firewallPort(rule.port, rule.protocol),
        action: rule.action,
        source: firewallSource(rule.source, rule.interface),
        state: "locked",
        match: null,
        rule: null,
    };
}

function NodePage({ fleet, node }: { fleet: Fleet; node: Node }) {
    const columns = useMemo(() => instanceColumns("project"), []);

    return (
        <div
            className={`w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-cols-2 md:grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)] ${GAPS}`}
        >
            <div
                className={`w-full min-w-0 max-w-full col-span-1 flex flex-col md:col-span-2 md:grid md:grid-cols-[2fr_3fr] ${GAPS}`}
            >
                <Properties
                    testId="record-properties"
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
                            // Over WireGuard, as Orbit itself connects. A managed node closes its public port 22.
                            value: `${node.user}@${node.wireguard_ip ?? node.public_ssh_host}:${node.wireguard_ip == null ? node.public_ssh_port : 22}`,
                        },
                    ]}
                />
                <NodeMetricsPanel node={node} />
            </div>
            <Pane
                name="instances"
                order={1}
                title="Instances on this node"
                className="w-full col-span-1 min-h-[160px] max-h-[40vh] md:col-span-2 md:max-h-none"
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
                className="w-full min-h-[160px] max-h-[40vh] md:max-h-none"
                columns={processColumns}
                rows={processesFor(fleet, "node", node.id)}
                rowId={(p) => String(p.id)}
                warn={(p) => !processHealthy(p)}
                target={(row) => ({ kind: "processes", row })}
            />
            <NodeFirewall
                fleet={fleet}
                node={node}
                className="w-full min-h-[160px] max-h-[40vh] md:max-h-none"
            />
        </div>
    );
}

function ProjectPage({ fleet, project }: { fleet: Fleet; project: Project }) {
    const columns = useMemo(() => instanceColumns("node"), []);
    const schedules = useMemo(() => scheduleColumns(fleet, "instance"), [fleet]);

    return (
        <div
            className={`w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)] ${GAPS}`}
        >
            <Properties
                testId="record-properties"
                properties={[
                    { name: "Name", value: project.name },
                    { name: "Slug", value: project.slug },
                    { name: "Repository", value: project.repository_url },
                    { name: "Default branch", value: project.default_branch },
                    { name: "Root", value: project.root },
                ]}
            >
                <ProjectCodeEditor key={project.id} project={project} />
            </Properties>
            <Pane
                name="instances"
                order={1}
                title="Instances"
                className="w-full min-h-[160px] max-h-[40vh] md:max-h-none"
                columns={columns}
                rows={instancesForProject(fleet, project.slug)}
                rowId={(i) => String(i.id)}
                warn={(i) => !instanceHealthy(i)}
                target={(row) => ({ kind: "instances", row })}
            />
            <Pane
                name="schedules"
                order={2}
                title="Schedules"
                className="w-full min-h-[160px] max-h-[40vh] md:max-h-none"
                columns={schedules}
                rows={schedulesForProject(fleet, project.slug)}
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

/**
 * The instance's tracking hosts, each a link to its script, and the private dashboard. An instance
 * without tracking shows nothing: the menu offers to enable it when an analytics role exists.
 */
function analyticsProperties(analytics: InstanceAnalytics | undefined): Property[] {
    if (analytics === undefined || !analytics.enabled) {
        return [];
    }

    return [
        ...analytics.hosts.map((host, index) => ({
            name: index === 0 ? "Analytics" : "",
            value:
                host.status === "active" && host.error_code === null
                    ? host.host
                    : `${host.host} · ${host.status}`,
            warn: host.error_code !== null,
            onOpen: host.error_code === null ? () => openInNewTab(host.script_url) : undefined,
        })),
        ...(analytics.dashboard_url === null
            ? []
            : [
                  {
                      name: "Dashboard",
                      value: analytics.dashboard_url.replace(/^https?:\/\//, ""),
                      onOpen: () => openInNewTab(analytics.dashboard_url as string),
                  },
              ]),
    ];
}

function InstancePage({ fleet, instance }: { fleet: Fleet; instance: Instance }) {
    const [tab, setTab] = useState<"overview" | "tasks">("overview");
    const groups = useQuery({ ...taskGroupsQuery, refetchInterval: useTaskPoll() });
    const taskCount = tasksForInstance(groups.data ?? [], instance.id).length;
    return (
        <div className={`flex h-full min-h-0 min-w-0 flex-row ${GAPS}`}>
            <Frame
                title="Menu"
                label="Instance navigation"
                className="w-[16ch] min-h-0 shrink-0 self-stretch"
            >
                <div
                    role="tablist"
                    aria-label="Instance sections"
                    aria-orientation="vertical"
                    className="flex flex-col gap-1"
                >
                    {(["overview", "tasks"] as const).map((section, index) => (
                        <button
                            key={section}
                            id={`instance-${instance.id}-${section}-tab`}
                            data-testid={
                                section === "overview" ? "instance-overview" : "instance-tasks"
                            }
                            role="tab"
                            type="button"
                            aria-selected={tab === section}
                            aria-controls={`instance-${instance.id}-${section}-panel`}
                            tabIndex={tab === section ? 0 : -1}
                            className="row nav-row text-left focus-visible:outline-2 focus-visible:outline-cyan"
                            style={{ gridTemplateColumns: "1fr auto" }}
                            data-link=""
                            data-selected={tab === section ? "" : undefined}
                            data-focused=""
                            onClick={() => setTab(section)}
                            onKeyDown={(event) => {
                                if (!["ArrowUp", "ArrowDown", "Home", "End"].includes(event.key))
                                    return;
                                event.preventDefault();
                                event.stopPropagation();
                                const next =
                                    event.key === "Home" ? 0 : event.key === "End" ? 1 : 1 - index;
                                setTab(next === 0 ? "overview" : "tasks");
                                event.currentTarget.parentElement
                                    ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
                                    [next]?.focus();
                            }}
                        >
                            <span>{section === "overview" ? "Overview" : "Tasks"}</span>
                            {section === "tasks" && taskCount > 0 && (
                                <span className="font-normal">{taskCount}</span>
                            )}
                        </button>
                    ))}
                </div>
            </Frame>
            {(["overview", "tasks"] as const).map((section) => (
                <div
                    key={section}
                    id={`instance-${instance.id}-${section}-panel`}
                    role="tabpanel"
                    aria-labelledby={`instance-${instance.id}-${section}-tab`}
                    hidden={tab !== section}
                    className="min-h-0 min-w-0 flex-1"
                >
                    {tab === section &&
                        (section === "overview" ? (
                            <InstanceOverview fleet={fleet} instance={instance} />
                        ) : (
                            <TasksBoard instanceId={instance.id} />
                        ))}
                </div>
            ))}
        </div>
    );
}

function InstanceOverview({ fleet, instance }: { fleet: Fleet; instance: Instance }) {
    const go = useGo();
    const deployments = useQuery({
        ...deploymentsQuery(instance.id),
        refetchInterval: useFallbackPoll(),
    });
    const logs = useLogTail("instances", instance.id, 500);
    const hasDeployments = (deployments.data?.length ?? 0) > 0;
    const analytics = useQuery(instanceAnalyticsQuery(instance.id)).data;
    const schedules = useMemo(() => scheduleColumns(fleet, "none"), [fleet]);
    const project = fleet.projects.find(
        (candidate) => candidate.id === (instance.project ?? instance.app).id,
    );
    const node = fleet.nodes.find((candidate) => candidate.id === instance.node.id);

    return (
        // A column, not a grid: analytics and queue panels are only there when available, and the log takes what is left either way.
        <div className={`w-full min-w-0 max-w-full flex h-full flex-col ${GAPS}`}>
            {/* The deployment history sits beside the properties, and only once there is one. */}
            <div
                className={`w-full min-w-0 max-w-full flex flex-col md:grid md:max-h-[40vh] ${hasDeployments ? "md:grid-cols-2" : "md:grid-cols-1"} ${GAPS}`}
            >
                <Properties
                    testId="record-properties"
                    properties={[
                        { name: "Name", value: instance.name },
                        {
                            name: "Project",
                            value: (instance.project ?? instance.app).slug,
                            onOpen:
                                project === undefined
                                    ? undefined
                                    : () => go.record("projects", project),
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
                            onOpen: () => openInNewTab(`https://${instance.domain}`),
                        },
                        {
                            name: "Status",
                            value: instance.status,
                            warn: !instanceHealthy(instance),
                        },
                        ...analyticsProperties(analytics),
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
                        className="w-full min-h-[160px] max-h-[40vh] md:max-h-none"
                        columns={deploymentColumns}
                        rows={deployments.data ?? []}
                        rowId={(d) => String(d.id)}
                        warn={(d) => !deploymentHealthy(d)}
                        target={(row) => ({ kind: "deployments", row })}
                    />
                )}
            </div>
            <div
                className={`w-full min-w-0 max-w-full flex flex-col md:grid md:max-h-[30vh] md:grid-cols-2 ${GAPS}`}
            >
                <Pane
                    name="processes"
                    order={1}
                    title="Processes"
                    className="w-full min-h-[160px] max-h-[35vh] md:max-h-none"
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
                    className="w-full min-h-[160px] max-h-[35vh] md:max-h-none"
                    columns={schedules}
                    rows={schedulesForInstance(fleet, instance.id)}
                    rowId={(s) => String(s.id)}
                    warn={(s) => !scheduleHealthy(s)}
                    target={(row) => ({ kind: "schedules", row })}
                />
            </div>
            <AnalyticsPanel instance={instance} />
            <QueuePanel instance={instance} />
            <LogPane
                title="Application log"
                testId="record-log"
                className="min-h-[220px] flex-1"
                lines={logs.lines}
                loading={logs.loading}
                error={logs.error}
                live={logs.live}
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
        <div
            className={`w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-cols-[45fr_55fr] md:grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}
        >
            <Properties
                testId="record-properties"
                className="w-full col-span-1 md:col-span-2"
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
                title="Tables"
                className="w-full min-h-[160px] max-h-[40vh] md:max-h-none"
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
                    className="w-full min-h-[160px] max-h-[40vh] md:max-h-none"
                    columns={userColumns}
                    rows={users.data ?? []}
                    rowId={(u) => u.username}
                    empty={users.isPending ? "Loading…" : "No users recorded."}
                />
            )}
        </div>
    );
}

/** The command as a shell would show it: the arguments in order, and the image first for a Docker process. */
function processCommand(process: Process): string | null {
    const config = process.runtime_config as { command?: string[]; image?: string } | null;
    const parts = [config?.image, ...(config?.command ?? [])].filter(
        (part): part is string => typeof part === "string" && part !== "",
    );

    return parts.length === 0 ? null : parts.join(" ");
}

function ProcessPage({ fleet, process }: { fleet: Fleet; process: Process }) {
    const logs = useLogTail("processes", process.id, 100);

    return (
        <div
            className={`w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}
        >
            <Properties
                testId="record-properties"
                properties={[
                    { name: "Name", value: process.name },
                    { name: "Owner", value: processOwner(fleet, process) },
                    { name: "Node", value: processNodeName(fleet, process) },
                    { name: "Runtime", value: process.runtime },
                    { name: "Command", value: processCommand(process) },
                    { name: "Working directory", value: process.working_directory },
                    { name: "Restart policy", value: process.restart_policy },
                    { name: "Desired state", value: process.desired_state },
                    {
                        name: "Runtime status",
                        value: process.runtime_status,
                        warn: !processHealthy(process),
                    },
                    { name: "CPU", value: processCpu(process) },
                    { name: "Memory", value: processMemory(process) },
                ]}
            />
            <LogPane
                title="Log"
                testId="record-log"
                className="min-h-[220px] flex-1"
                lines={logs.lines}
                loading={logs.loading}
                error={logs.error}
                live={logs.live}
            />
        </div>
    );
}

function SchedulePage({ fleet, schedule }: { fleet: Fleet; schedule: Schedule }) {
    const logs = useQuery(scheduleLogsQuery(schedule.id));

    return (
        <div
            className={`w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}
        >
            <Properties
                testId="record-properties"
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
            <LogPane
                title="Log"
                testId="record-log"
                className="min-h-[220px] flex-1"
                lines={logs.data}
                loading={logs.isPending}
            />
        </div>
    );
}

function FirewallPage({ rule }: { rule: FirewallRule }) {
    return (
        <div className="w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-rows-[auto]">
            <Properties
                testId="record-properties"
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
        if (section === "quota") {
            return <QuotaProviderPage />;
        }

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
            case "projects": {
                const project = find(fleet.projects);

                return (
                    project && (
                        <RecordLayout kind="projects" row={project}>
                            <ProjectPage fleet={fleet} project={project} />
                        </RecordLayout>
                    )
                );
            }
            case "instances": {
                const instance = find(fleet.instances);

                return (
                    instance && (
                        <RecordLayout kind="instances" row={instance}>
                            <InstancePage key={instance.id} fleet={fleet} instance={instance} />
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
            <Frame
                title={section}
                testId={fleet.loading || !fleet.processesLoaded ? undefined : "record-missing"}
            >
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
    const deployments = useQuery({
        ...deploymentsQuery(Number(id)),
        refetchInterval: useFallbackPoll(),
    });
    const log = useQuery(deploymentLogQuery(Number(deploymentId)));
    const deployment = deployments.data?.find((candidate) => String(candidate.id) === deploymentId);

    if (deployment === undefined) {
        return (
            <Frame
                title="Deployment"
                testId={deployments.isPending ? undefined : "deployment-missing"}
            >
                <Note>{deployments.isPending ? "Loading…" : `No deployment ${deploymentId}.`}</Note>
            </Frame>
        );
    }

    return (
        <RecordLayout kind="deployments" row={deployment} titleTestId="deployment-title">
            <div
                className={`w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-rows-[auto_minmax(0,1fr)] ${GAPS}`}
            >
                <Properties
                    testId="deployment-properties"
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
                    title="Log"
                    testId="deployment-log"
                    className="min-h-[220px] flex-1"
                    lines={log.data}
                    loading={log.isPending}
                />
            </div>
        </RecordLayout>
    );
}
