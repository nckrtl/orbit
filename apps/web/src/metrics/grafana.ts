import { queryOptions, useQuery } from "@tanstack/react-query";
import { get } from "../api/client";
import { queryClient } from "../api/queryClient";
import type { Node } from "../api/types";
import {
    mapMetrics,
    mapReach,
    type NodeMetrics,
    type PrometheusResponse,
    queries,
} from "./prometheus";

/**
 * Node metrics, read the way `orbit top` reads them: straight from the Metrics role's Grafana,
 * whose datasource proxy re-serves Prometheus, authorized by the Grafana credential the Gateway
 * stores. The Gateway names the Nodes; Prometheus only has samples for the Nodes whose exporter
 * is enabled, so a Node it does not answer for simply has no metrics. No request is sent per
 * Node: one fleet-wide query covers the dashboard.
 *
 * Grafana is reached at the same-origin path `/grafana`. The dev server proxies that path to the
 * URL the credentials name (see vite.config.ts), so the browser needs no trust in the Orbit CA
 * and no CORS.
 */
export type GrafanaTransport = (
    path: string,
    params: Record<string, string>,
    authorization: string,
) => Promise<unknown>;

type Credentials = { url: string; username: string; password: string };

const http: GrafanaTransport = async (path, params, authorization) => {
    const query = new URLSearchParams(params).toString();
    const response = await fetch(`/grafana${path}${query === "" ? "" : `?${query}`}`, {
        headers: { Accept: "application/json", Authorization: authorization },
    });

    if (!response.ok) {
        throw new Error(`Grafana answered ${response.status}.`);
    }

    return response.json();
};

let transport: GrafanaTransport = http;

export function setGrafanaTransport(next: GrafanaTransport | null): void {
    transport = next ?? http;
}

// A Gateway without Metrics refuses this; every Node then shows no metrics, and the page asks again in a minute.
const credentialsQuery = queryOptions({
    queryKey: ["metrics", "credentials"],
    queryFn: () => get<Credentials>("/api/v1/metrics/credentials"),
    staleTime: Number.POSITIVE_INFINITY,
    retry: false,
});

const authorization = async (): Promise<string> => {
    const { username, password } = await queryClient.ensureQueryData(credentialsQuery);

    return `Basic ${btoa(`${username}:${password}`)}`;
};

const datasourceQuery = queryOptions({
    queryKey: ["metrics", "datasource"],
    queryFn: async () => {
        const datasources = (await transport("/api/datasources", {}, await authorization())) as {
            type?: string;
            uid?: string;
        }[];
        const uid = datasources.find((datasource) => datasource.type === "prometheus")?.uid;

        if (uid === undefined) {
            throw new Error("Grafana has no Prometheus datasource.");
        }

        return uid;
    },
    staleTime: Number.POSITIVE_INFINITY,
    retry: false,
});

/** Metrics keyed by WireGuard address, for one scrape target or, with null, for every Node Prometheus has. */
async function readMetrics(instance: string | null): Promise<Record<string, NodeMetrics>> {
    const uid = await queryClient.ensureQueryData(datasourceQuery);
    const auth = await authorization();
    const query = (promql: string) =>
        transport(
            `/api/datasources/proxy/uid/${uid}/api/v1/query`,
            { query: promql },
            auth,
        ) as Promise<PrometheusResponse>;
    // Only the scalars decide whether a Node has metrics. The rest enrich the panel, so a query
    // Prometheus refuses must not blank the dashboard.
    const optional = (promql: string) => query(promql).catch((): PrometheusResponse => ({}));
    const [scalars, cores, disks] = await Promise.all([
        query(queries.scalars(instance)),
        optional(queries.cores(instance)),
        optional(queries.disks(instance)),
    ]);

    return mapMetrics(scalars, cores, disks, Date.now() / 1000);
}

const interval = (seconds: number) => (query: { state: { status: string } }) =>
    query.state.status === "error" ? 60_000 : seconds * 1000;

/** Every Node's metrics in one read, for the dashboard. Look a Node up by its `wireguard_ip`. */
export function useFleetMetrics(): Record<string, NodeMetrics> {
    const { data } = useQuery({
        queryKey: ["metrics", "fleet"],
        queryFn: () => readMetrics(null),
        refetchInterval: interval(10),
        retry: false,
        staleTime: 0,
    });

    return data ?? {};
}

/** Which Nodes Prometheus reached on its last scrape. Look a Node up by its `wireguard_ip`. */
export function useFleetReach(): Record<string, boolean> {
    const { data } = useQuery({
        queryKey: ["metrics", "reach"],
        queryFn: async () => {
            const uid = await queryClient.ensureQueryData(datasourceQuery);

            return mapReach(
                (await transport(
                    `/api/datasources/proxy/uid/${uid}/api/v1/query`,
                    { query: queries.up() },
                    await authorization(),
                )) as PrometheusResponse,
            );
        },
        refetchInterval: interval(10),
        retry: false,
        staleTime: 0,
    });

    return data ?? {};
}

/** One Node's metrics, for its page. A Node without a WireGuard address has none. */
export function useNodeMetrics(node: Node): NodeMetrics | null {
    const ip = node.wireguard_ip;
    const { data } = useQuery({
        queryKey: ["metrics", "node", ip],
        queryFn: () => readMetrics(`${ip}:9100`),
        enabled: typeof ip === "string" && ip !== "",
        refetchInterval: interval(10),
        retry: false,
        staleTime: 0,
    });

    return (typeof ip === "string" ? data?.[ip] : undefined) ?? null;
}
