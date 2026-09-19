import type { GrafanaTransport } from "../metrics/grafana";

type Exporter = {
    address: string;
    cores: number[];
    memory: [number, number];
    swap: [number, number];
    root: [number, number];
    uptime: number;
};

// The Nodes whose exporter is enabled. app-prod (10.44.0.3) has none, so Prometheus never names it.
const EXPORTERS: Exporter[] = [
    {
        address: "10.44.0.1",
        cores: [0.12, 0.34, 0.08, 0.21],
        memory: [3_435_973_836, 8_589_934_592],
        swap: [0, 2_147_483_648],
        root: [6_442_450_944, 85_899_345_920],
        uptime: 1_053_784,
    },
    {
        address: "10.44.0.2",
        cores: [0.91, 0.42, 0.18, 0.07, 0.66, 0.12, 0.03, 0.25],
        memory: [75_161_927_680, 137_438_953_472],
        swap: [1_073_741_824, 8_589_934_592],
        root: [1_546_188_226_560, 1_889_785_610_240],
        uptime: 1_053_784,
    },
];

/** A Grafana that answers the datasource list and the queries the app sends, as Prometheus vectors. */
export const demoGrafana: GrafanaTransport = (path, params) => {
    if (path === "/api/datasources") {
        return Promise.resolve([{ type: "prometheus", uid: "demo" }]);
    }

    const promql = params.query ?? "";
    const only = /instance="([^"]+)"/.exec(promql)?.[1];
    const now = Date.now() / 1000;
    const result: { metric: Record<string, string>; value: [number, string] }[] = [];

    for (const exporter of EXPORTERS) {
        const instance = `${exporter.address}:9100`;
        const sample = (labels: Record<string, string>, value: number) =>
            result.push({ metric: { instance, ...labels }, value: [now, String(value)] });

        if (only !== undefined && only !== instance) {
            continue;
        }

        if (promql.startsWith("up{")) {
            sample({ __name__: "up" }, 1);
        } else if (promql.includes("node_cpu_seconds_total")) {
            exporter.cores.forEach((load, cpu) => sample({ cpu: String(cpu) }, load));
        } else if (promql.includes("node_filesystem")) {
            sample(
                { __name__: "node_filesystem_size_bytes", mountpoint: "/", fstype: "ext4" },
                exporter.root[1],
            );
            sample(
                { __name__: "node_filesystem_avail_bytes", mountpoint: "/", fstype: "ext4" },
                exporter.root[1] - exporter.root[0],
            );
            sample(
                { __name__: "node_filesystem_size_bytes", mountpoint: "/run", fstype: "tmpfs" },
                1_073_741_824,
            );
        } else {
            sample({ __name__: "node_memory_MemTotal_bytes" }, exporter.memory[1]);
            sample(
                { __name__: "node_memory_MemAvailable_bytes" },
                exporter.memory[1] - exporter.memory[0],
            );
            sample({ __name__: "node_memory_SwapTotal_bytes" }, exporter.swap[1]);
            sample({ __name__: "node_memory_SwapFree_bytes" }, exporter.swap[1] - exporter.swap[0]);
            // Thirty seconds past the minute, so the uptime a test reads does not tip over while it runs.
            sample({ __name__: "node_boot_time_seconds" }, now - exporter.uptime - 30);
        }
    }

    return Promise.resolve({ status: "success", data: { resultType: "vector", result } });
};
