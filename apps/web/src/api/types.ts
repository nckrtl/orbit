import type { components, operations } from "./schema";

type Schema<K extends keyof components["schemas"]> = Required<components["schemas"][K]>;

// The spec marks no field as required, so every property is optional in the generated types.
// The Gateway always sends them; Required<> states that once instead of at every read.
export type Node = Schema<"Node">;
export type Project = Schema<"App">;
export type ProjectIdentity = { id: number; name: string; slug: string };
export type Instance = Omit<Schema<"AppInstance">, "app" | "project" | "node" | "deploy_steps"> & {
    app: ProjectIdentity;
    project?: ProjectIdentity;
    node: { id: number; name: string };
    deploy_steps: DeployStep[];
};
export type DeployStep = { phase: string; name: string; timeout_seconds: number };
export type Process = Schema<"Process">;
export type Schedule = Schema<"Schedule">;
export type FirewallRule = Schema<"FirewallRule">;
export type Database = Schema<"DatabaseConnection">;
export type DatabaseUser = Schema<"DatabaseUser">;
export type Deployment = Schema<"AppInstanceDeployment">;
export type DoctorReport = Schema<"DoctorReport">;

export type DeploymentEvent = {
    type: string;
    phase?: string | null;
    step_name?: string | null;
    stream?: string | null;
    value_base64?: string | null;
};

/** The record families a pane, a page, or an actions menu can name. */
export type Kind =
    | "nodes"
    | "projects"
    | "instances"
    | "processes"
    | "schedules"
    | "databases"
    | "firewall"
    | "deployments";

export type RecordOf = {
    nodes: Node;
    projects: Project;
    instances: Instance;
    processes: Process;
    schedules: Schedule;
    databases: Database;
    firewall: FirewallRule;
    deployments: Deployment;
};

export type AnyRecord = RecordOf[Kind];

type QueueBody = NonNullable<
    operations["instance-queue"]["responses"][200]["content"]["application/json"]["data"]
>;
export type QueueJob = Required<NonNullable<QueueBody["jobs"]>[number]>;
/** An instance's Horizon queue. Only `available` and `state` are there when it has none. */
export type QueueReport = Omit<QueueBody, "jobs"> & { jobs?: QueueJob[] };
export type QueueState = QueueReport["state"];

/** One of Orbit's own firewall rules on a Node. The Gateway has no request that changes one. */
export type ManagedFirewallRule = Required<
    NonNullable<
        operations["firewall-managed-list"]["responses"][200]["content"]["application/json"]["data"]
    >[number]
>;

type LiveFirewallData = Required<
    NonNullable<
        operations["firewall-live-list"]["responses"][200]["content"]["application/json"]["data"]
    >
>;

export type LiveFirewallSnapshot = {
    backend_status: LiveFirewallData["backend_status"];
    live: Array<Required<NonNullable<LiveFirewallData["live"]>[number]>>;
    missing: Array<Required<NonNullable<LiveFirewallData["missing"]>[number]>>;
};

export type LiveFirewallRule =
    | LiveFirewallSnapshot["live"][number]
    | LiveFirewallSnapshot["missing"][number];
export type LiveFirewallMatch = LiveFirewallRule["match"];

/** One tracking host an Instance publishes for the analytics role. */
export type AnalyticsHost = {
    host: string;
    route_id: number;
    status: string;
    publication: string;
    failed_step: string | null;
    error_code: string | null;
    script_url: string;
    event_url: string;
    /** Null while the instance has no domain to point the host at. */
    dns: { type: string; name: string; value: string } | null;
};
/** One CLIProxyAPI quota window. A missing window is omitted, never shown as zero. */
export type QuotaWindow = {
    label: string;
    used_percent: number;
    remaining_percent: number;
    resets_at: string | null;
};

export type QuotaAccount = {
    id: string;
    provider: string;
    label: string;
    disabled: boolean;
    status: string | null;
    windows: QuotaWindow[];
    error: string | null;
};

export type QuotaProvider = {
    provider: string;
    windows: QuotaWindow[];
    accounts: QuotaAccount[];
};

export type ProxyCliStatus = {
    enabled: boolean;
    hostname: string;
    node_id: number | null;
    cache_connection: string | null;
    collected_at: string | null;
};

export type TasksStatus = { enabled: boolean };

/** An Instance's analytics: its tracking hosts, and what the operator does next. */
export type InstanceAnalytics = {
    instance_id: number;
    enabled: boolean;
    domain: string | null;
    dashboard_url: string | null;
    hosts: AnalyticsHost[];
    snippet: string | null;
};

/** One path in the Instance's top-pages breakdown. */
export type AnalyticsPage = {
    path: string;
    visitors: number;
};

/**
 * Visitor counts for an Instance's tracked site. Only `available` is there when the panel
 * must not show. Visitor fields are absent when `readable` is false.
 */
export type InstanceAnalyticsStats = {
    available: boolean;
    readable?: boolean;
    driver?: "plausible_ce";
    site_domain?: string | null;
    live_visitors?: number;
    visitors?: { past_24h: number; past_7d: number; past_30d: number };
    pages?: AnalyticsPage[];
    error_code?: string | null;
    error?: string | null;
};
