import { useMemo } from "react";
import { useFleet } from "../api/queries";
import type { App, Instance, Node } from "../api/types";
import {
    type AttentionRow,
    attentionRows,
    instanceHealthy,
    instancesForApp,
    nodeHealthy,
    processHealthy,
    scheduleHealthy,
} from "../fleet/fleet";
import { useFleetMetrics, useFleetReach } from "../metrics/grafana";
import type { NodeMetrics } from "../metrics/prometheus";
import { Bar } from "../ui/Bar";
import { Frame, Note } from "../ui/Frame";
import { type Column, Pane } from "../ui/Pane";
import { Status, statusText } from "../ui/Status";
import { processDashboardColumns, scheduleColumns } from "./columns";

/**
 * The dashboard: one compact row per node, a pane per family, and everything that needs a look.
 * Fleet counts live in the sidebar. Each pane scrolls its own rows.
 */
export function Dashboard() {
    const fleet = useFleet();
    const metrics = useFleetMetrics();
    const reach = useFleetReach();
    const attention = useMemo(() => attentionRows(fleet), [fleet]);

    const nodeColumns = useMemo<Column<Node>[]>(() => {
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
            { header: "Name", width: 14, value: (n) => n.name },
            {
                header: "Status",
                width: 9,
                value: (n) => statusText({ value: n.status, reach: reachOf(n) }),
                cell: (n) => <Status value={n.status} reach={reachOf(n)} />,
            },
            {
                header: "CPU",
                width: 21,
                ...meter((m) => `${(cpu(m) * 100).toFixed(0).padStart(3)}%`, cpu),
            },
            {
                header: "Mem",
                width: 21,
                ...meter(
                    (m) => `${m.mem[0].toFixed(1)}G/${m.mem[1].toFixed(0)}G`.padStart(10),
                    mem,
                ),
            },
            {
                header: "Disk",
                width: 19,
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
                width: 16,
                value: (n) => of(n)?.uptime ?? "—",
                cell: (n) => <span className="text-dim">{of(n)?.uptime ?? "—"}</span>,
            },
        ];
    }, [metrics, reach]);
    // A node with a role serves the fleet and is scraped; one without is a machine that only joins the network.
    const servers = useMemo(() => fleet.nodes.filter((n) => n.roles.length > 0), [fleet.nodes]);
    const clients = useMemo(() => fleet.nodes.filter((n) => n.roles.length === 0), [fleet.nodes]);
    const clientColumns = useMemo<Column<Node>[]>(
        () => [
            { header: "Name", width: 30, value: (n) => n.name },
            {
                header: "Status",
                width: 22,
                value: (n) => n.status,
                cell: (n) => <Status value={n.status} reach={null} />,
            },
            { header: "User", width: 20, value: (n) => n.user ?? "—" },
            { header: "WireGuard IP", width: 28, value: (n) => n.wireguard_ip ?? "—" },
        ],
        [],
    );
    const appColumns = useMemo<Column<App>[]>(
        () => [
            { header: "Slug", width: 44, value: (a) => a.slug },
            { header: "Branch", width: 30, value: (a) => a.default_branch ?? "main" },
            {
                header: "Instances",
                width: 26,
                value: (a) => String(instancesForApp(fleet, a.slug).length),
                sort: (a) => instancesForApp(fleet, a.slug).length,
            },
        ],
        [fleet],
    );
    const instanceColumns = useMemo<Column<Instance>[]>(
        () => [
            { header: "Name", width: 26, value: (i) => i.name },
            { header: "App", width: 26, value: (i) => i.app.slug },
            { header: "Node", width: 24, value: (i) => i.node.name },
            {
                header: "Status",
                width: 24,
                value: (i) => i.status,
                cell: (i) => <Status value={i.status} />,
            },
        ],
        [],
    );
    const attentionColumns = useMemo<Column<AttentionRow>[]>(
        () => [
            { header: "Kind", width: 12, value: (a) => a.label },
            { header: "Name", width: 32, value: (a) => a.name },
            { header: "Where", width: 26, value: (a) => a.where },
            { header: "State", width: 28, value: (a) => a.state },
        ],
        [],
    );
    const processColumns = useMemo(() => processDashboardColumns(fleet), [fleet]);
    const scheduleCols = useMemo(() => scheduleColumns(fleet, "instance"), [fleet]);

    if (fleet.error !== null) {
        return (
            <Frame title="Gateway" state="warn">
                <Note>{fleet.error.message}</Note>
            </Frame>
        );
    }

    return (
        <div className="grid h-full grid-cols-6 grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-x-[1ch] gap-y-[16px]">
            <Pane
                name="nodes"
                order={0}
                title="Nodes"
                className="col-span-4 max-h-[34vh]"
                columns={nodeColumns}
                rows={servers}
                rowId={(n) => String(n.id)}
                warn={(n) => !nodeHealthy(n) || reach[n.wireguard_ip ?? ""] === false}
                target={(row) => ({ kind: "nodes", row })}
                empty={fleet.loading ? "Loading fleet data…" : "No nodes."}
            />
            <Pane
                name="clients"
                order={1}
                title="Clients"
                className="col-span-2 max-h-[34vh]"
                columns={clientColumns}
                rows={clients}
                rowId={(n) => String(n.id)}
                warn={(n) => !nodeHealthy(n)}
                target={(row) => ({ kind: "nodes", row })}
                empty={fleet.loading ? "Loading fleet data…" : "No clients."}
            />
            <Pane
                name="apps"
                className="col-span-3"
                order={2}
                title="Apps"
                columns={appColumns}
                rows={fleet.apps}
                rowId={(a) => String(a.id)}
                target={(row) => ({ kind: "apps", row })}
                empty="No apps."
            />
            <Pane
                name="instances"
                className="col-span-3"
                order={3}
                title="Instances"
                columns={instanceColumns}
                rows={fleet.instances}
                rowId={(i) => String(i.id)}
                warn={(i) => !instanceHealthy(i)}
                target={(row) => ({ kind: "instances", row })}
                empty="No instances."
            />
            <Pane
                name="processes"
                className="col-span-3"
                order={4}
                title="Processes"
                columns={processColumns}
                rows={fleet.processes}
                rowId={(p) => String(p.id)}
                warn={(p) => !processHealthy(p)}
                target={(row) => ({ kind: "processes", row })}
                empty={fleet.processesLoaded ? "No processes." : "Checking processes…"}
            />
            <Pane
                name="schedules"
                className="col-span-3"
                order={5}
                title="Schedules"
                columns={scheduleCols}
                rows={fleet.schedules}
                rowId={(s) => String(s.id)}
                warn={(s) => !scheduleHealthy(s)}
                target={(row) => ({ kind: "schedules", row })}
                empty="No schedules."
            />
            <Pane
                name="attention"
                order={6}
                title="Needs attention"
                className="col-span-6"
                columns={attentionColumns}
                rows={attention}
                rowId={(a) => a.id}
                warn={() => true}
                target={(a) => ({ kind: a.kind, row: a.record })}
                empty={fleet.processesLoaded ? "Nothing needs attention." : "Checking processes…"}
            />
        </div>
    );
}
