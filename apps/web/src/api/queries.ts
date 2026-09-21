import { queryOptions, useQuery } from "@tanstack/react-query";
import { useLiveness } from "../realtime/liveness";
import { get } from "./client";
import { queryClient } from "./queryClient";
import type {
    Database,
    DatabaseUser,
    Deployment,
    DeploymentEvent,
    FirewallRule,
    Instance,
    InstanceAnalytics,
    InstanceAnalyticsStats,
    LiveFirewallSnapshot,
    ManagedFirewallRule,
    Node,
    Process,
    Project,
    ProxyCliStatus,
    QueueReport,
    QueueState,
    QuotaProvider,
    Schedule,
} from "./types";

/** How often the lists reload while realtime is down. `orbit top --tick` has the same default. */
export const POLL_SECONDS = 10;

const nodesQuery = queryOptions({
    queryKey: ["nodes"],
    queryFn: () => get<Node[]>("/api/v1/nodes"),
});

export const lists = {
    nodes: nodesQuery,
    projects: queryOptions({
        queryKey: ["projects"],
        queryFn: () => get<Project[]>("/api/v1/projects"),
    }),
    instances: queryOptions({
        queryKey: ["instances"],
        queryFn: () => get<Instance[]>("/api/v1/instances"),
    }),
    processes: queryOptions({
        queryKey: ["processes"],
        queryFn: () => get<Process[]>("/api/v1/processes"),
    }),
    schedules: queryOptions({
        queryKey: ["schedules"],
        queryFn: () => get<Schedule[]>("/api/v1/schedules"),
    }),
    databases: queryOptions({
        queryKey: ["databases"],
        queryFn: () => get<Database[]>("/api/v1/database-connections"),
    }),
    // Firewall rules are scoped per Node, so the fleet's list is one request per Node.
    firewall: queryOptions({
        queryKey: ["firewall"],
        queryFn: async () => {
            const nodes = await queryClient.ensureQueryData(nodesQuery);
            const rules = await Promise.all(
                nodes.map((node) =>
                    get<FirewallRule[]>(`/api/v1/nodes/${node.id}/firewall-rules`).catch(() => []),
                ),
            );

            return rules.flat();
        },
    }),
};

export type Fleet = {
    nodes: Node[];
    projects: Project[];
    instances: Instance[];
    processes: Process[];
    schedules: Schedule[];
    databases: Database[];
    firewall: FirewallRule[];
    processesLoaded: boolean;
    loading: boolean;
    error: Error | null;
};

const EMPTY: never[] = [];

/** Every fleet-wide list. Realtime events patch these caches; they poll only while it is down. */
/** Projects in alphabetical order of their name, whatever order the Gateway lists them in. */
const byName = (projects: Project[]): Project[] =>
    [...projects].sort((a, b) =>
        (a.name ?? "").localeCompare(b.name ?? "", undefined, { sensitivity: "base" }),
    );

export function useFleet(): Fleet {
    const live = useLiveness() === "live";
    const refetchInterval = live ? false : POLL_SECONDS * 1000;
    const nodes = useQuery({ ...lists.nodes, refetchInterval });
    const projects = useQuery({ ...lists.projects, refetchInterval, select: byName });
    const instances = useQuery({ ...lists.instances, refetchInterval });
    // No event carries a Process's CPU and memory, so this list reloads on its own clock.
    const processes = useQuery({ ...lists.processes, refetchInterval: 15_000 });
    const schedules = useQuery({ ...lists.schedules, refetchInterval });
    const databases = useQuery({ ...lists.databases, refetchInterval });
    const firewall = useQuery({ ...lists.firewall, refetchInterval });

    return {
        nodes: nodes.data ?? EMPTY,
        projects: projects.data ?? EMPTY,
        instances: instances.data ?? EMPTY,
        processes: processes.data ?? EMPTY,
        schedules: schedules.data ?? EMPTY,
        databases: databases.data ?? EMPTY,
        firewall: firewall.data ?? EMPTY,
        processesLoaded: processes.data !== undefined || processes.isError,
        loading: nodes.isPending || projects.isPending || instances.isPending,
        error: nodes.error ?? projects.error ?? instances.error,
    };
}

export const deploymentsQuery = (instanceId: number) =>
    queryOptions({
        queryKey: ["deployments", instanceId],
        queryFn: () => get<Deployment[]>(`/api/v1/instances/${instanceId}/deployments`),
        refetchInterval: 15_000,
        retry: false,
    });

export const databaseUsersQuery = (slug: string) =>
    queryOptions({
        queryKey: ["database-users", slug],
        queryFn: () =>
            get<DatabaseUser[]>(`/api/v1/database-connections/${encodeURIComponent(slug)}/users`),
        refetchInterval: 15_000,
        retry: false,
    });

export const databaseTablesQuery = (slug: string) =>
    queryOptions({
        queryKey: ["database-tables", slug],
        queryFn: async () => {
            const data = await get<{ tables?: ({ name?: string } | string)[] }>(
                `/api/v1/database-connections/${encodeURIComponent(slug)}/tables`,
            );

            return (data.tables ?? []).map((table) =>
                typeof table === "string" ? table : (table.name ?? ""),
            );
        },
        retry: false,
    });

/** Log text as lines, without the trailing newlines. */
export function logLines(text: string | undefined): string[] {
    return text === undefined || text === "" ? [] : text.replace(/\n+$/, "").split("\n");
}

export const processLogsQuery = (id: number) =>
    queryOptions({
        queryKey: ["process-logs", id],
        queryFn: async () =>
            logLines((await get<{ logs?: string }>(`/api/v1/processes/${id}/logs`)).logs),
        refetchInterval: 10_000,
        retry: false,
    });

/** Orbit's own firewall rules for a Node. They change only when its roles do, so they reload rarely. */
export const managedFirewallQuery = (nodeId: number) =>
    queryOptions({
        queryKey: ["managed-firewall", nodeId],
        queryFn: () => get<ManagedFirewallRule[]>(`/api/v1/nodes/${nodeId}/managed-firewall-rules`),
        staleTime: 60_000,
        retry: false,
    });

/** Live UFW on a Node, classified against the desired managed set and operator records. */
export const liveFirewallQuery = (nodeId: number) =>
    queryOptions({
        queryKey: ["live-firewall", nodeId],
        queryFn: () => get<LiveFirewallSnapshot>(`/api/v1/nodes/${nodeId}/live-firewall-rules`),
        refetchInterval: 15_000,
        retry: false,
    });

const disabledProxyCli = (): ProxyCliStatus => ({
    enabled: false,
    hostname: "collector.proxycli.orbit",
    node_id: null,
    cache_connection: null,
    collected_at: null,
});

/** Fleet proxycli status. A disabled or unreachable feature hides the Quota section. */
export const proxycliStatusQuery = queryOptions({
    queryKey: ["proxycli-status"],
    refetchInterval: POLL_SECONDS * 1000,
    queryFn: () => get<ProxyCliStatus>("/api/v1/proxycli").catch(() => disabledProxyCli()),
    retry: false,
});

/** Provider pools from the Valkey snapshot. A refresh never starts an upstream poll. */
export const proxycliProvidersQuery = queryOptions({
    queryKey: ["proxycli-providers"],
    queryFn: () => get<QuotaProvider[]>("/api/v1/proxycli/providers"),
    refetchInterval: POLL_SECONDS * 1000,
    retry: false,
});

export const proxycliProviderQuery = (provider: string) =>
    queryOptions({
        queryKey: ["proxycli-providers", provider],
        queryFn: () =>
            get<QuotaProvider>(`/api/v1/proxycli/providers/${encodeURIComponent(provider)}`),
        refetchInterval: POLL_SECONDS * 1000,
        retry: false,
    });

/** The tracking hosts an instance publishes for the analytics role. They change only on enable and disable. */
export const instanceAnalyticsQuery = (id: number) =>
    queryOptions({
        queryKey: ["instance-analytics", id],
        queryFn: () => get<InstanceAnalytics>(`/api/v1/instances/${id}/analytics`),
        staleTime: 60_000,
        retry: false,
    });

/** Live visitors, period counts, and top pages for an instance that publishes a tracking host. */
export const instanceAnalyticsStatsQuery = (id: number) =>
    queryOptions({
        queryKey: ["instance-analytics-stats", id],
        queryFn: () => get<InstanceAnalyticsStats>(`/api/v1/instances/${id}/analytics/stats`),
        refetchInterval: 10_000,
        retry: false,
    });

/** The instance's Horizon queue: its summary, its queues, and the newest jobs in one state. */
export const instanceQueueQuery = (id: number, state: QueueState) =>
    queryOptions({
        queryKey: ["instance-queue", id, state],
        queryFn: () => get<QueueReport>(`/api/v1/instances/${id}/queue?state=${state}`),
        refetchInterval: 10_000,
        retry: false,
    });

/** The instance's own application log, `storage/logs/laravel.log`, as the Gateway redacts it. */
export const instanceLogsQuery = (id: number) =>
    queryOptions({
        queryKey: ["instance-logs", id],
        queryFn: async () =>
            logLines((await get<{ logs?: string }>(`/api/v1/instances/${id}/logs?lines=500`)).logs),
        refetchInterval: 10_000,
        retry: false,
    });

export const scheduleLogsQuery = (id: string) =>
    queryOptions({
        queryKey: ["schedule-logs", id],
        queryFn: async () =>
            logLines(
                (await get<{ output?: string }>(`/api/v1/schedules/${encodeURIComponent(id)}/logs`))
                    .output,
            ),
        retry: false,
    });

/** Phase markers and output lines from a deployment's events, as instance:deployment:show prints them. */
export function deploymentLogLines(events: DeploymentEvent[]): string[] {
    const out: string[] = [];

    for (const event of events) {
        if (event.type === "phase") {
            const phase = (event.phase ?? "").replaceAll("_", " ");
            out.push(event.step_name ? `== ${phase}: ${event.step_name} ==` : `== ${phase} ==`);
        } else if (event.type === "output" && event.value_base64) {
            const text = new TextDecoder().decode(
                Uint8Array.from(atob(event.value_base64), (char) => char.charCodeAt(0)),
            );
            out.push(...logLines(text).map((line) => `${event.stream}: ${line}`));
        } else if (event.type === "output_truncated") {
            out.push("[output truncated]");
        }
    }

    return out;
}

export const deploymentLogQuery = (id: number) =>
    queryOptions({
        queryKey: ["deployment-log", id],
        queryFn: async () =>
            deploymentLogLines(
                (await get<{ events?: DeploymentEvent[] }>(`/api/v1/deployments/${id}`)).events ??
                    [],
            ),
        retry: false,
    });
