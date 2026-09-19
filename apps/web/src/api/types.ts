import type { components, operations } from "./schema";

type Schema<K extends keyof components["schemas"]> = Required<components["schemas"][K]>;

// The spec marks no field as required, so every property is optional in the generated types.
// The Gateway always sends them; Required<> states that once instead of at every read.
export type Node = Schema<"Node">;
export type App = Schema<"App">;
export type Instance = Omit<Schema<"AppInstance">, "app" | "node" | "deploy_steps"> & {
    app: { id: number; name: string; slug: string };
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
    | "apps"
    | "instances"
    | "processes"
    | "schedules"
    | "databases"
    | "firewall"
    | "deployments";

export type RecordOf = {
    nodes: Node;
    apps: App;
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
