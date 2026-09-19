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
import { useFleetMetrics } from "../metrics/grafana";
import type { NodeMetrics } from "../metrics/prometheus";
import { Bar } from "../ui/Bar";
import { Frame, Note } from "../ui/Frame";
import { useGo } from "../ui/go";
import { type Column, Pane } from "../ui/Pane";
import { processDashboardColumns, scheduleColumns } from "./columns";

const NODE_TEMPLATE =
    "minmax(0, 14fr) minmax(0, 9fr) minmax(0, 21fr) minmax(0, 21fr) minmax(0, 19fr) minmax(0, 16fr)";

/** One line per node: name, status, cpu/mem/disk bars, and uptime; a yellow row means the node needs a look. */
function NodeSummaryRow({ node, metrics }: { node: Node; metrics: NodeMetrics | null }) {
    const go = useGo();
    const cpu =
        metrics === null
            ? 0
            : metrics.cores.reduce((sum, core) => sum + core, 0) /
              Math.max(1, metrics.cores.length);
    const [mount, used, total] = metrics?.disks[0] ?? ["/", 0, 0];

    return (
        <div
            role="row"
            className="row"
            data-link=""
            style={{ gridTemplateColumns: NODE_TEMPLATE }}
            data-warn={nodeHealthy(node) ? undefined : ""}
            onClick={() => go.record("nodes", node)}
        >
            <span>{node.name}</span>
            <span>{node.status}</span>
            {metrics === null ? (
                <>
                    <span className="text-dim">—</span>
                    <span className="text-dim">—</span>
                    <span className="text-dim">—</span>
                    <span className="text-right text-dim">—</span>
                </>
            ) : (
                <>
                    <Bar ratio={cpu} reading={`${(cpu * 100).toFixed(0).padStart(3)}%`} />
                    <Bar
                        ratio={metrics.mem[1] > 0 ? metrics.mem[0] / metrics.mem[1] : 0}
                        reading={`${metrics.mem[0].toFixed(1)}G/${metrics.mem[1].toFixed(0)}G`.padStart(
                            10,
                        )}
                    />
                    <Bar
                        label={mount}
                        ratio={total > 0 ? used / total : 0}
                        reading={`${used.toFixed(0)}G/${total.toFixed(0)}G`.padStart(10)}
                        thresholds={[80, 90]}
                    />
                    <span className="text-right text-dim">{metrics.uptime}</span>
                </>
            )}
        </div>
    );
}

/**
 * The dashboard: one compact row per node, a pane per family, and everything that needs a look.
 * Fleet counts live in the sidebar. Each pane scrolls its own rows.
 */
export function Dashboard() {
    const fleet = useFleet();
    const metrics = useFleetMetrics();
    const attention = useMemo(() => attentionRows(fleet), [fleet]);

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
            { header: "Status", width: 24, value: (i) => i.status },
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
        <div className="grid h-full grid-cols-2 grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-x-[1ch] gap-y-[16px]">
            <Frame title="Nodes" className="col-span-2 max-h-[34vh]">
                <div
                    className="row"
                    role="row"
                    data-head
                    style={{ gridTemplateColumns: NODE_TEMPLATE }}
                >
                    <span>Name</span>
                    <span>Status</span>
                    <span>CPU</span>
                    <span>Mem</span>
                    <span>Disk</span>
                    <span className="text-right">Uptime</span>
                </div>
                {fleet.loading ? (
                    <Note>Loading fleet data…</Note>
                ) : (
                    fleet.nodes.map((node) => (
                        <NodeSummaryRow
                            key={node.id}
                            node={node}
                            metrics={metrics[node.wireguard_ip ?? ""] ?? null}
                        />
                    ))
                )}
            </Frame>
            <Pane
                name="apps"
                order={1}
                title="Apps"
                columns={appColumns}
                rows={fleet.apps}
                rowId={(a) => String(a.id)}
                target={(row) => ({ kind: "apps", row })}
                empty="No apps."
            />
            <Pane
                name="instances"
                order={2}
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
                order={3}
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
                order={4}
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
                order={5}
                title="Needs attention"
                className="col-span-2"
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
