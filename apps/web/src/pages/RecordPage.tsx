import { extensionsQuery } from "../api/extensions";
import { taskGroupsQuery, tasksForInstance } from "../api/tasks";
import { useTaskPoll } from "../realtime/polling";
import { DefinitionPane } from "../definitions/definition-pane";
import { TasksBoard } from "./Tasks";
import { ProjectCodeEditor } from "../ui/ProjectCodeEditor";
import { useQuery } from "@tanstack/react-query";
import { useParams } from "@tanstack/react-router";
import { useEffect, useMemo, useState } from "react";
import {
    databaseTablesQuery,
    databaseUsersQuery,
    deploymentLogQuery,
    deploymentsQuery,
    type Fleet,
    instanceAnalyticsQuery,
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
    Process,
    Project,
    Schedule,
} from "../api/types";
import {
    deploymentHealthy,
    firewallHealthy,
    instanceEnvironment,
    instanceHealthy,
    instanceName,
    instanceNodeName,
    instancesForProject,
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
import { useFallbackPoll } from "../realtime/liveness";
import { Frame, Note } from "../ui/Frame";
import { useGo } from "../ui/go";
import { LogPane } from "../ui/LogPane";
import { useLogTail } from "../realtime/log-stream";
import { openInNewTab } from "../ui/newTab";
import { type Column, Pane } from "../ui/Pane";
import { Properties, type Property } from "../ui/Properties";
import { SectionMenu } from "../ui/SectionMenu";
import { instanceColumns, processColumns, scheduleColumns } from "./columns";
import { AnalyticsPanel } from "./AnalyticsPanel";
import { QueuePanel } from "./QueuePanel";
import { QuotaProviderPage } from "./Quota";
import { RecordLayout } from "./RecordLayout";
import { ProjectMenu } from "./ProjectDocuments";

const GAPS = "gap-x-[1ch] gap-y-[var(--panel-gap)]";

function ProjectPage({ fleet, project }: { fleet: Fleet; project: Project }) {
    return (
        <div className="flex min-h-0 min-w-0 flex-col gap-2 md:flex-row">
            <ProjectMenu project={project} documents={false} />
            <div
                id={`project-${project.id}-overview-panel`}
                role="tabpanel"
                aria-labelledby={`project-${project.id}-overview-tab`}
                className="min-h-0 min-w-0 flex-1"
            >
                <ProjectOverview fleet={fleet} project={project} />
            </div>
        </div>
    );
}

function ProjectOverview({ fleet, project }: { fleet: Fleet; project: Project }) {
    const columns = useMemo(() => instanceColumns(fleet, "node"), [fleet]);
    const schedules = useMemo(() => scheduleColumns(fleet, "instance"), [fleet]);

    return (
        <div
            className={`w-full min-w-0 max-w-full flex flex-col md:grid md:h-full md:grid-rows-[auto_minmax(120px,0.8fr)_minmax(0,1fr)_minmax(0,1fr)] ${GAPS}`}
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
            <DefinitionPane
                projectId={project.id}
                order={1}
                className="w-full min-h-[140px] max-h-[32vh] md:max-h-none"
            />
            <Pane
                name="instances"
                order={2}
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
                order={3}
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
    const tasksEnabled = useQuery(extensionsQuery).data?.tasks === true;
    const [requestedTab, setRequestedTab] = useState<"overview" | "tasks">("overview");
    const tab = requestedTab === "tasks" && tasksEnabled ? "tasks" : "overview";
    const sections = tasksEnabled ? (["overview", "tasks"] as const) : (["overview"] as const);
    const groups = useQuery({
        ...taskGroupsQuery,
        enabled: tasksEnabled,
        refetchInterval: useTaskPoll(),
    });
    const taskCount = tasksEnabled ? tasksForInstance(groups.data ?? [], instance.id).length : 0;

    useEffect(() => {
        if (!tasksEnabled) {
            setRequestedTab("overview");
        }
    }, [tasksEnabled]);

    return (
        <div className={`flex h-full min-h-0 min-w-0 flex-row ${GAPS}`}>
            <SectionMenu
                label="Instance navigation"
                ariaLabel="Instance sections"
                idPrefix={`instance-${instance.id}`}
                items={sections.map((section) => ({
                    id: section,
                    label: section === "overview" ? "Overview" : "Tasks",
                    testId: section === "overview" ? "instance-overview" : "instance-tasks",
                    count: section === "tasks" ? taskCount : undefined,
                }))}
                selected={tab}
                onSelect={setRequestedTab}
            />
            {sections.map((section) => (
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
    const project = fleet.projects.find((candidate) => candidate.id === instance.project.id);
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
                            value: instance.project.slug,
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
                        { name: "Environment", value: instanceEnvironment(fleet, instance) },
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
