import { useMemo } from "react";
import { useFleet } from "../api/queries";
import type { Node } from "../api/types";
import { useFleetMetrics, useFleetReach } from "../metrics/grafana";
import type { NodeMetrics } from "../metrics/prometheus";
import { Bar } from "../ui/Bar";
import type { Column } from "../ui/Pane";
import { Status, statusText } from "../ui/Status";

/**
 * The two node tables, for the dashboard and the Nodes page. A node with a role serves the fleet and
 * is scraped, so it has meters and reads online or offline; one without is a machine that only joins
 * the network. `wide` adds the columns the Nodes page has room for.
 */
export function useNodeTables(wide = false) {
    const fleet = useFleet();
    const metrics = useFleetMetrics();
    const reach = useFleetReach();
    const workers = useMemo(() => fleet.nodes.filter((n) => n.roles.length > 0), [fleet.nodes]);
    const clients = useMemo(() => fleet.nodes.filter((n) => n.roles.length === 0), [fleet.nodes]);

    const workerColumns = useMemo<Column<Node>[]>(() => {
        const reachOf = (node: Node): boolean | null => reach[node.wireguard_ip ?? ""] ?? null;
        const of = (node: Node): NodeMetrics | null => metrics[node.wireguard_ip ?? ""] ?? null;
        const cpu = (m: NodeMetrics): number =>
            m.cores.reduce((sum, core) => sum + core, 0) / Math.max(1, m.cores.length);
        const mem = (m: NodeMetrics): number => (m.mem[1] > 0 ? m.mem[0] / m.mem[1] : 0);
        const disk = (m: NodeMetrics): number => {
            const [, used, total] = m.disks[0] ?? ["/", 0, 0];

            return total > 0 ? used / total : 0;
        };
        const none = <span className="text-dim">—</span>;
        // One meter per reading; a node with no exporter has no metrics and shows a dash.
        const meter = (
            reading: (m: NodeMetrics) => string,
            ratio: (m: NodeMetrics) => number,
            options: { label?: (m: NodeMetrics) => string; thresholds?: [number, number] } = {},
        ) => ({
            value: (node: Node) => {
                const m = of(node);

                return m === null ? "—" : reading(m).trim();
            },
            sort: (node: Node) => {
                const m = of(node);

                return m === null ? -1 : ratio(m);
            },
            cell: (node: Node) => {
                const m = of(node);

                return m === null ? (
                    none
                ) : (
                    <Bar
                        label={options.label?.(m)}
                        ratio={ratio(m)}
                        reading={reading(m)}
                        thresholds={options.thresholds}
                    />
                );
            },
        });

        return [
            { header: "Name", width: 14, fit: true, value: (n) => n.name },
            {
                header: "Status",
                width: 9,
                fit: true,
                value: (n) => statusText({ value: n.status, reach: reachOf(n) }),
                cell: (n) => <Status value={n.status} reach={reachOf(n)} />,
            },
            ...(wide
                ? ([
                      {
                          header: "Roles",
                          width: 8,
                          fit: true,
                          value: (n) => String(n.roles.length),
                          sort: (n) => n.roles.length,
                          // The count keeps the column narrow; the names show on hover.
                          cell: (n) => <span title={n.roles.join(", ")}>{n.roles.length}</span>,
                      },
                      {
                          header: "WireGuard IP",
                          width: 12,
                          fit: true,
                          value: (n) => n.wireguard_ip ?? "—",
                      },
                  ] satisfies Column<Node>[])
                : []),
            {
                header: "CPU",
                width: 17,
                ...meter((m) => `${(cpu(m) * 100).toFixed(0).padStart(3)}%`, cpu),
            },
            {
                header: "Mem",
                width: 25,
                ...meter(
                    (m) => `${m.mem[0].toFixed(1)}G/${m.mem[1].toFixed(0)}G`.padStart(10),
                    mem,
                ),
            },
            {
                header: "Disk",
                width: 25,
                ...meter(
                    (m) => {
                        const [, used, total] = m.disks[0] ?? ["/", 0, 0];

                        return `${used.toFixed(0)}G/${total.toFixed(0)}G`.padStart(10);
                    },
                    disk,
                    { label: (m) => m.disks[0]?.[0] ?? "/", thresholds: [80, 90] },
                ),
            },
            {
                header: "Uptime",
                width: 10,
                fit: true,
                value: (n) => of(n)?.uptime ?? "—",
                cell: (n) => <span className="text-dim">{of(n)?.uptime ?? "—"}</span>,
            },
        ];
    }, [metrics, reach, wide]);

    const clientColumns = useMemo<Column<Node>[]>(
        () => [
            { header: "Name", width: 30, fit: true, value: (n) => n.name },
            {
                header: "Status",
                width: 22,
                fit: true,
                value: (n) => n.status,
                cell: (n) => <Status value={n.status} reach={null} />,
            },
            { header: "User", width: 20, fit: true, value: (n) => n.user ?? "—" },
            ...(wide
                ? ([
                      {
                          header: "Architecture",
                          width: 20,
                          fit: true,
                          value: (n) => n.architecture ?? "—",
                      },
                  ] satisfies Column<Node>[])
                : []),
            { header: "WireGuard IP", width: 28, value: (n) => n.wireguard_ip ?? "—" },
        ],
        [wide],
    );

    return {
        workers,
        clients,
        workerColumns,
        clientColumns,
        offline: (node: Node): boolean => reach[node.wireguard_ip ?? ""] === false,
    };
}
