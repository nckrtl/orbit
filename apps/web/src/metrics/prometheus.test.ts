import { describe, expect, it } from "vite-plus/test";
import { formatUptime, mapMetrics, type PrometheusResponse, queries } from "./prometheus";

// Ported from the CLI's PrometheusNodeMetricsMapperTest, so both clients read Prometheus alike.

const NOW = 1_700_100_000;
const GIB = 1024 ** 3;
const sample = (metric: Record<string, string>, value: number) => ({
    metric,
    value: [1_700_000_000, String(value)] as [number, string],
});
const vector = (result: ReturnType<typeof sample>[]): PrometheusResponse => ({
    status: "success",
    data: { resultType: "vector", result },
});
const memory = (instance: string) => [
    sample({ __name__: "node_memory_MemTotal_bytes", instance }, 4 * GIB),
    sample({ __name__: "node_memory_MemAvailable_bytes", instance }, 2 * GIB),
];

describe("mapMetrics", () => {
    it("maps a 4-core Linux instance", () => {
        const instance = "10.44.0.3:9100";
        const metrics = mapMetrics(
            vector([
                sample({ __name__: "node_memory_MemTotal_bytes", instance }, 8_589_934_592),
                sample({ __name__: "node_memory_MemAvailable_bytes", instance }, 5_153_960_756),
                sample({ __name__: "node_memory_SwapTotal_bytes", instance }, 2_147_483_648),
                sample({ __name__: "node_memory_SwapFree_bytes", instance }, 2_147_483_648),
                sample({ __name__: "node_boot_time_seconds", instance }, NOW - 1_053_784),
            ]),
            vector(
                [0.12, 0.34, 0.08, 0.21].map((load, cpu) =>
                    sample({ instance, cpu: String(cpu) }, load),
                ),
            ),
            vector([
                sample(
                    {
                        __name__: "node_filesystem_size_bytes",
                        instance,
                        mountpoint: "/",
                        fstype: "ext4",
                    },
                    85_899_345_920,
                ),
                sample(
                    {
                        __name__: "node_filesystem_avail_bytes",
                        instance,
                        mountpoint: "/",
                        fstype: "ext4",
                    },
                    79_456_894_976,
                ),
            ]),
            NOW,
        );

        expect(metrics["10.44.0.3"]).toEqual({
            cores: [0.12, 0.34, 0.08, 0.21],
            mem: [3_435_973_836 / GIB, 8],
            swap: [0, 2],
            uptime: "12d 4h 43m",
            disks: [["/", 6, 80]],
        });
    });

    it("orders cores by CPU index, not by the order Prometheus returned them", () => {
        const instance = "10.44.0.7:9100";
        const order = [10, 2, 0, 15, 1, 11, 3, 12, 4, 13, 5, 14, 6, 9, 7, 8];
        const metrics = mapMetrics(
            vector(memory(instance)),
            vector(order.map((cpu) => sample({ instance, cpu: String(cpu) }, cpu / 100))),
            vector([]),
            NOW,
        );

        expect(metrics["10.44.0.7"]?.cores).toEqual(
            Array.from({ length: 16 }, (_, cpu) => cpu / 100),
        );
    });

    it("reads a Darwin exporter's memory family", () => {
        const instance = "10.44.0.8:9100";
        const metrics = mapMetrics(
            vector([
                sample({ __name__: "node_memory_total_bytes", instance }, 16 * GIB),
                sample({ __name__: "node_memory_free_bytes", instance }, 2 * GIB),
                sample({ __name__: "node_memory_inactive_bytes", instance }, 3 * GIB),
                sample({ __name__: "node_memory_purgeable_bytes", instance }, 1 * GIB),
                sample({ __name__: "node_memory_swap_total_bytes", instance }, 2 * GIB),
                sample({ __name__: "node_memory_swap_used_bytes", instance }, 5 * GIB),
            ]),
            vector([]),
            vector([]),
            NOW,
        );

        expect(metrics["10.44.0.8"]).toMatchObject({ mem: [10, 16], swap: [2, 2], uptime: "0m" });
    });

    it("leaves out an instance that sent no total memory, and everything when the query failed", () => {
        const scalars = vector([
            sample({ __name__: "node_boot_time_seconds", instance: "10.44.0.9:9100" }, NOW - 60),
        ]);

        expect(mapMetrics(scalars, vector([]), vector([]), NOW)).toEqual({});
        expect(mapMetrics({ status: "error" }, vector([]), vector([]), NOW)).toEqual({});
    });

    it("skips pseudo filesystems, keeps a mount path with spaces, and puts root first", () => {
        const instance = "10.44.0.11:9100";
        const disk = (mountpoint: string, fstype: string, size: number, avail: number) => [
            sample({ __name__: "node_filesystem_size_bytes", instance, mountpoint, fstype }, size),
            sample(
                { __name__: "node_filesystem_avail_bytes", instance, mountpoint, fstype },
                avail,
            ),
        ];
        const metrics = mapMetrics(
            vector(memory(instance)),
            vector([]),
            vector([
                ...disk("/mnt/backup drive", "ext4", 2 * GIB, GIB),
                ...disk("/run", "tmpfs", GIB, GIB),
                ...disk("/", "ext4", 80 * GIB, 74 * GIB),
            ]),
            NOW,
        );

        expect(metrics["10.44.0.11"]?.disks).toEqual([
            ["/", 6, 80],
            ["/mnt/backup drive", 1, 2],
        ]);
    });
});

describe("queries", () => {
    it("covers every instance, or one scrape target", () => {
        expect(queries.cores()).toBe('1 - rate(node_cpu_seconds_total{mode="idle"}[30s])');
        expect(queries.cores("10.44.0.2:9100")).toBe(
            '1 - rate(node_cpu_seconds_total{mode="idle",instance="10.44.0.2:9100"}[30s])',
        );
        expect(queries.disks("10.44.0.2:9100")).toContain(',instance="10.44.0.2:9100"}');
    });
});

describe("formatUptime", () => {
    it("drops the units that are zero at the front", () => {
        expect(formatUptime(59)).toBe("0m");
        expect(formatUptime(3 * 3600 + 120)).toBe("3h 2m");
        expect(formatUptime(5 * 86400 + 60)).toBe("5d 0h 1m");
    });
});
