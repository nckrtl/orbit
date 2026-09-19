// The PromQL `orbit top` sends and the mapping it applies, ported from the CLI's
// PrometheusMetricsQueries and PrometheusNodeMetricsMapper. Pure: every value comes from the
// decoded responses passed in, so it is testable from Prometheus JSON alone.

/** The window every rate() covers; at a five-second scrape it holds six samples. */
const RATE_WINDOW = "30s";

const SCALAR_NAMES = [
    "node_memory_MemTotal_bytes",
    "node_memory_MemAvailable_bytes",
    "node_memory_SwapTotal_bytes",
    "node_memory_SwapFree_bytes",
    "node_memory_total_bytes",
    "node_memory_free_bytes",
    "node_memory_inactive_bytes",
    "node_memory_purgeable_bytes",
    "node_memory_swap_total_bytes",
    "node_memory_swap_used_bytes",
    "node_boot_time_seconds",
].join("|");

/** Pseudo and virtual filesystems left out of `disks`. */
const PSEUDO_FILESYSTEMS = [
    "tmpfs",
    "devtmpfs",
    "overlay",
    "squashfs",
    "efivarfs",
    "proc",
    "sysfs",
    "cgroup",
    "cgroup2",
    "ramfs",
];

const clause = (instance: string | null): string =>
    instance === null ? "" : `,instance="${instance}"`;

/** One query per metric group. Pass an `IP:9100` scrape target to scope it to one Node. */
export const queries = {
    scalars: (instance: string | null = null): string =>
        `{__name__=~"${SCALAR_NAMES}"${clause(instance)}}`,
    cores: (instance: string | null = null): string =>
        `1 - rate(node_cpu_seconds_total{mode="idle"${clause(instance)}}[${RATE_WINDOW}])`,
    disks: (instance: string | null = null): string =>
        `{__name__=~"node_filesystem_size_bytes|node_filesystem_avail_bytes"${clause(instance)}}`,
};

type Sample = { metric?: Record<string, string>; value?: [number, string | number] };
export type PrometheusResponse = {
    status?: string;
    data?: { resultType?: string; result?: Sample[] };
};

export type NodeMetrics = {
    cores: number[];
    mem: [number, number];
    swap: [number, number];
    uptime: string;
    disks: [string, number, number][];
};

const GIB = 1024 ** 3;

const vector = (response: PrometheusResponse): Sample[] =>
    response.status === "success" && response.data?.resultType === "vector"
        ? (response.data.result ?? [])
        : [];

/** The scraped `instance` label with its port stripped: "10.44.0.5:9100" is "10.44.0.5". */
function address(sample: Sample): string | null {
    const instance = sample.metric?.instance;

    if (instance === undefined) {
        return null;
    }

    const found = instance.includes(":") ? instance.slice(0, instance.lastIndexOf(":")) : instance;

    return found === "" ? null : found;
}

function value(sample: Sample): number {
    const number = Number(sample.value?.[1]);

    return Number.isFinite(number) ? number : 0;
}

const bytes = (number: number): number => Math.round(Math.max(0, number));

export function formatUptime(seconds: number): string {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    return days > 0
        ? `${days}d ${hours}h ${minutes}m`
        : hours > 0
          ? `${hours}h ${minutes}m`
          : `${minutes}m`;
}

/**
 * Metrics per Node, keyed by WireGuard address. An instance's memory family (Linux or Darwin) is
 * read from which metrics its exporter sent. An instance with neither total is left out: the
 * caller treats that as "no metrics", not as a zeroed Node.
 */
export function mapMetrics(
    scalars: PrometheusResponse,
    cores: PrometheusResponse,
    disks: PrometheusResponse,
    now: number,
): Record<string, NodeMetrics> {
    const values: Record<string, Record<string, number>> = {};

    for (const sample of vector(scalars)) {
        const instance = address(sample);
        const name = sample.metric?.__name__;

        if (instance !== null && name !== undefined) {
            (values[instance] ??= {})[name] = value(sample);
        }
    }

    const loads: Record<string, Record<number, number>> = {};

    for (const sample of vector(cores)) {
        const instance = address(sample);
        const cpu = Number(sample.metric?.cpu);

        if (instance !== null && sample.metric?.cpu !== undefined && Number.isInteger(cpu)) {
            (loads[instance] ??= {})[cpu] = Math.max(0, Math.min(1, value(sample)));
        }
    }

    const mounts: Record<string, Record<string, { size?: number; avail?: number }>> = {};

    for (const sample of vector(disks)) {
        const instance = address(sample);
        const { __name__: name, mountpoint, fstype } = sample.metric ?? {};

        if (
            instance === null ||
            mountpoint === undefined ||
            (fstype !== undefined && PSEUDO_FILESYSTEMS.includes(fstype))
        ) {
            continue;
        }

        const key =
            name === "node_filesystem_size_bytes"
                ? "size"
                : name === "node_filesystem_avail_bytes"
                  ? "avail"
                  : null;

        if (key !== null) {
            ((mounts[instance] ??= {})[mountpoint] ??= {})[key] = value(sample);
        }
    }

    const metrics: Record<string, NodeMetrics> = {};

    for (const [instance, found] of Object.entries(values)) {
        const linux = found.node_memory_MemTotal_bytes;
        const darwin = found.node_memory_total_bytes;
        let memory: [number, number];

        if (linux !== undefined) {
            memory = [bytes(linux - (found.node_memory_MemAvailable_bytes ?? linux)), bytes(linux)];
        } else if (darwin !== undefined) {
            const free =
                (found.node_memory_free_bytes ?? 0) +
                (found.node_memory_inactive_bytes ?? 0) +
                (found.node_memory_purgeable_bytes ?? 0);
            memory = [bytes(darwin - free), bytes(darwin)];
        } else {
            continue;
        }

        const swap: [number, number] =
            found.node_memory_SwapTotal_bytes !== undefined
                ? [
                      bytes(
                          found.node_memory_SwapTotal_bytes -
                              (found.node_memory_SwapFree_bytes ?? 0),
                      ),
                      bytes(found.node_memory_SwapTotal_bytes),
                  ]
                : found.node_memory_swap_total_bytes !== undefined
                  ? [
                        bytes(
                            Math.min(
                                found.node_memory_swap_used_bytes ?? 0,
                                found.node_memory_swap_total_bytes,
                            ),
                        ),
                        bytes(found.node_memory_swap_total_bytes),
                    ]
                  : [0, 0];

        const boot = found.node_boot_time_seconds;
        const entries = Object.entries(mounts[instance] ?? {})
            .filter(([, sizes]) => sizes.size !== undefined && sizes.size > 0)
            .map(([mount, sizes]): [string, number, number] => [
                mount,
                bytes((sizes.size as number) - (sizes.avail ?? (sizes.size as number))),
                bytes(sizes.size as number),
            ])
            // Root first, then by path.
            .sort(([a], [b]) => (a === "/" ? -1 : b === "/" ? 1 : a < b ? -1 : a > b ? 1 : 0));

        metrics[instance] = {
            cores: Object.entries(loads[instance] ?? {})
                .sort(([a], [b]) => Number(a) - Number(b))
                .map(([, load]) => load),
            mem: [memory[0] / GIB, memory[1] / GIB],
            swap: [swap[0] / GIB, swap[1] / GIB],
            uptime: formatUptime(boot === undefined ? 0 : Math.max(0, Math.round(now - boot))),
            disks: entries.map(([mount, used, total]) => [mount, used / GIB, total / GIB]),
        };
    }

    return metrics;
}
