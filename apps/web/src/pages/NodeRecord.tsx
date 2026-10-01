import { useQuery } from "@tanstack/react-query";
import { Outlet, useLocation, useNavigate, useParams } from "@tanstack/react-router";
import { useEffect, useMemo } from "react";
import { type Fleet, liveFirewallQuery, managedFirewallQuery, useFleet } from "../api/queries";
import type {
    FirewallRule,
    LiveFirewallMatch,
    LiveFirewallRule,
    ManagedFirewallRule,
    Node,
} from "../api/types";
import {
    instanceHealthy,
    instancesForNode,
    nodeHealthy,
    processHealthy,
    processesFor,
} from "../fleet/fleet";
import { firewallLineTone, firewallPort, firewallSource } from "../fleet/firewall";
import { useNodeMetrics } from "../metrics/grafana";
import { Bar } from "../ui/Bar";
import { Frame, Note } from "../ui/Frame";
import { type Column, Pane } from "../ui/Pane";
import { Properties } from "../ui/Properties";
import { SectionMenu } from "../ui/SectionMenu";
import { Status } from "../ui/Status";
import { instanceColumns, processColumns } from "./columns";
import { NodeTools } from "./NodeTools";
import { RecordLayout } from "./RecordLayout";

const GAPS = "gap-x-[1ch] gap-y-[var(--panel-gap)]";

export type NodePanel = "overview" | "tools" | "firewall";

const NODE_PANELS: readonly NodePanel[] = ["overview", "tools", "firewall"];

/** Linux is the only platform whose page can read UFW. A Mac never asks for those rules. */
export function linuxFirewallSupported(node: Node): boolean {
    return node.platform === "linux";
}

export function nodePanelFromPath(pathname: string): NodePanel {
    const panel = pathname.split("/").filter(Boolean).at(-1);

    return panel !== undefined &&
        (NODE_PANELS as readonly string[]).includes(panel) &&
        panel !== "overview"
        ? (panel as NodePanel)
        : "overview";
}

/** The node page's htop-like block: cores in two columns, then memory and swap beside the root disk and uptime. */
function NodeMetricsPanel({ node }: { node: Node }) {
    const metrics = useNodeMetrics(node);
    const state = nodeHealthy(node) ? undefined : "warn";

    if (node.platform !== "linux") {
        return (
            <Frame title={`${node.name} · ${node.status}`} state={state}>
                <Note>Metrics are not available on this Node.</Note>
            </Frame>
        );
    }

    if (metrics === null) {
        return (
            <Frame title={`${node.name} · ${node.status}`} state={state}>
                <Note>No metrics.</Note>
            </Frame>
        );
    }

    const [mount, used, total] = metrics.disks[0] ?? ["/", 0, 0];

    return (
        <Frame
            title={`${node.name} · ${node.status} · metrics`}
            state={state}
            bottomRight={`up ${metrics.uptime}`}
        >
            <div className="grid grid-cols-1 gap-x-[2ch] sm:grid-cols-2">
                {metrics.cores.map((load, core) => (
                    <Bar
                        key={core}
                        label={String(core)}
                        ratio={load}
                        reading={`${(load * 100).toFixed(0).padStart(3)}%`}
                    />
                ))}
            </div>
            <div className="mt-[20px] grid grid-cols-1 gap-x-[2ch] sm:grid-cols-2">
                <Bar
                    label="Mem"
                    ratio={metrics.mem[1] > 0 ? metrics.mem[0] / metrics.mem[1] : 0}
                    reading={`${metrics.mem[0].toFixed(1)}G/${metrics.mem[1].toFixed(0)}G`}
                />
                <Bar
                    label={mount}
                    ratio={total > 0 ? used / total : 0}
                    reading={`${used.toFixed(0)}G/${total.toFixed(0)}G`}
                    thresholds={[80, 90]}
                />
                <Bar
                    label="Swp"
                    ratio={metrics.swap[1] > 0 ? metrics.swap[0] / metrics.swap[1] : 0}
                    reading={`${metrics.swap[0].toFixed(1)}G/${metrics.swap[1].toFixed(0)}G`}
                />
            </div>
        </Frame>
    );
}

/** One line of a node's firewall: a live UFW rule, a missing desired rule, or a catalog fallback. */
type FirewallLine = {
    key: string;
    name: string;
    port: string;
    action: string;
    source: string;
    state: string;
    match: LiveFirewallMatch | null;
    rule: FirewallRule | null;
};

const firewallLineColumns: Column<FirewallLine>[] = [
    { header: "Name", width: 34, value: (line) => line.name },
    { header: "Port", width: 16, fit: true, value: (line) => line.port },
    { header: "Action", width: 10, fit: true, value: (line) => line.action },
    { header: "Source", width: 26, fit: true, value: (line) => line.source },
    {
        header: "Status",
        width: 14,
        fit: true,
        value: (line) => line.state,
        cell: (line) =>
            line.rule !== null && line.match !== "missing" ? (
                <Status value={line.state} />
            ) : (
                <span
                    className={
                        firewallLineTone(line) === "danger"
                            ? "text-red"
                            : firewallLineTone(line) === "warn"
                              ? "text-yellow"
                              : "text-dim"
                    }
                >
                    {line.state}
                </span>
            ),
    },
];

/**
 * A node's firewall: live UFW first, then desired rules that are missing from live. Drift is red.
 * When live UFW cannot be read, the page falls back to operator rules and the desired catalog.
 */
function NodeFirewall({ fleet, node }: { fleet: Fleet; node: Node }) {
    const managed = useQuery(managedFirewallQuery(node.id)).data;
    const live = useQuery(liveFirewallQuery(node.id)).data;
    const operator = useMemo(
        () => fleet.firewall.filter((rule) => rule.node_id === node.id),
        [fleet.firewall, node.id],
    );
    const lines = useMemo<FirewallLine[]>(() => {
        if (live !== undefined && live.backend_status === "active") {
            return [
                ...live.live.map((rule, index) => liveLine(rule, operator, `live-${index}`)),
                ...live.missing.map((rule, index) => liveLine(rule, operator, `missing-${index}`)),
            ];
        }

        return [
            ...operator.map((rule) => ({
                key: `operator-${rule.id}`,
                name: rule.name,
                port: firewallPort(rule.port, rule.protocol),
                action: rule.action,
                source: rule.source,
                state: rule.status,
                match: null,
                rule,
            })),
            ...(managed ?? []).map((rule, index) => intendedLine(rule, index)),
        ];
    }, [live, managed, operator]);

    const tone = (line: FirewallLine) => firewallLineTone(line);
    const empty =
        live !== undefined && live.backend_status !== "active" && lines.length === 0
            ? `Live UFW is ${live.backend_status}.`
            : "No firewall rules.";

    return (
        <Pane
            name="firewall"
            order={1}
            title="Firewall"
            className="h-full min-h-[160px] w-full"
            columns={firewallLineColumns}
            rows={lines}
            rowId={(line) => line.key}
            warn={(line) => tone(line) === "warn"}
            danger={(line) => tone(line) === "danger"}
            target={(line) => (line.rule === null ? null : { kind: "firewall", row: line.rule })}
            divide={{ label: "Missing from live", below: (line) => line.match === "missing" }}
            empty={empty}
        />
    );
}

function liveLine(rule: LiveFirewallRule, operator: FirewallRule[], key: string): FirewallLine {
    const record = operator.find((candidate) => candidate.name === rule.name) ?? null;
    const state =
        rule.match === "missing"
            ? "missing"
            : rule.match === "exact" && record !== null
              ? record.status
              : rule.match === "exact"
                ? "live"
                : "drift";

    return {
        key,
        name: rule.name,
        port: firewallPort(rule.port, rule.protocol),
        action: rule.action,
        source: firewallSource(rule.source, rule.interface),
        state,
        match: rule.match,
        rule: record,
    };
}

function intendedLine(rule: ManagedFirewallRule, index: number): FirewallLine {
    return {
        key: `orbit-${index}`,
        name: rule.name,
        port: firewallPort(rule.port, rule.protocol),
        action: rule.action,
        source: firewallSource(rule.source, rule.interface),
        state: "locked",
        match: null,
        rule: null,
    };
}

function NodeOverview({ fleet, node }: { fleet: Fleet; node: Node }) {
    const columns = useMemo(() => instanceColumns(fleet, "project"), [fleet]);

    return (
        <div
            className={`flex h-full w-full min-w-0 max-w-full flex-col md:grid md:grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)] ${GAPS}`}
        >
            <div
                className={`flex w-full min-w-0 max-w-full flex-col md:grid md:grid-cols-[2fr_3fr] ${GAPS}`}
            >
                <Properties
                    testId="record-properties"
                    properties={[
                        { name: "Name", value: node.name },
                        { name: "Status", value: node.status, warn: !nodeHealthy(node) },
                        { name: "Roles", value: node.roles },
                        { name: "Platform", value: node.platform },
                        { name: "Architecture", value: node.architecture },
                        { name: "TLD", value: node.tld },
                        { name: "WireGuard IP", value: node.wireguard_ip },
                        {
                            name: "SSH",
                            // Over WireGuard, as Orbit itself connects. A managed node closes its public port 22.
                            value: `${node.user}@${node.wireguard_ip ?? node.public_ssh_host}:${node.wireguard_ip == null ? node.public_ssh_port : 22}`,
                        },
                    ]}
                />
                <NodeMetricsPanel node={node} />
            </div>
            <Pane
                name="instances"
                order={1}
                title="Instances on this node"
                className="max-h-[40vh] min-h-[160px] w-full md:max-h-none"
                columns={columns}
                rows={instancesForNode(fleet, node.name)}
                rowId={(instance) => String(instance.id)}
                warn={(instance) => !instanceHealthy(instance)}
                target={(row) => ({ kind: "instances", row })}
            />
            <Pane
                name="processes"
                order={2}
                title="Node processes"
                className="max-h-[40vh] min-h-[160px] w-full md:max-h-none"
                columns={processColumns}
                rows={processesFor(fleet, "node", node.id)}
                rowId={(process) => String(process.id)}
                warn={(process) => !processHealthy(process)}
                target={(row) => ({ kind: "processes", row })}
            />
        </div>
    );
}

function NodeSections({ fleet, node, panel }: { fleet: Fleet; node: Node; panel: NodePanel }) {
    const navigate = useNavigate();
    const firewall = linuxFirewallSupported(node);
    const sections = firewall
        ? (["overview", "tools", "firewall"] as const)
        : (["overview", "tools"] as const);
    const selected = sections.find((section) => section === panel) ?? "overview";

    useEffect(() => {
        if (panel === "firewall" && !firewall) {
            void navigate({
                to: "/nodes/$id",
                params: { id: String(node.id) },
                replace: true,
            });
        }
    }, [firewall, navigate, node.id, panel]);

    const open = (next: NodePanel) => {
        if (next === "overview") {
            void navigate({ to: "/nodes/$id", params: { id: String(node.id) } });

            return;
        }

        void navigate({
            to: next === "tools" ? "/nodes/$id/tools" : "/nodes/$id/firewall",
            params: { id: String(node.id) },
        });
    };

    return (
        <div className={`flex h-full min-h-0 min-w-0 flex-col md:flex-row ${GAPS}`}>
            <SectionMenu
                label="Node navigation"
                ariaLabel="Node sections"
                idPrefix={`node-${node.id}`}
                responsive
                items={sections.map((section) => ({
                    id: section,
                    label:
                        section === "overview"
                            ? "Overview"
                            : section === "tools"
                              ? "Tools"
                              : "Firewall",
                    testId:
                        section === "overview"
                            ? "node-overview"
                            : section === "tools"
                              ? "node-tools"
                              : "node-firewall",
                }))}
                selected={selected}
                onSelect={open}
            />
            <div
                id={`node-${node.id}-${selected}-panel`}
                role="tabpanel"
                aria-labelledby={`node-${node.id}-${selected}-tab`}
                className="min-h-0 min-w-0 flex-1"
            >
                {selected === "overview" ? (
                    <NodeOverview fleet={fleet} node={node} />
                ) : selected === "tools" ? (
                    <NodeTools key={node.id} node={node} />
                ) : (
                    <NodeFirewall fleet={fleet} node={node} />
                )}
            </div>
        </div>
    );
}

/** `/nodes/$id` and its Tools and Firewall sections. The URL names the section. */
export function NodeRecordPage() {
    const { id } = useParams({ from: "/nodes/$id" });
    const { pathname } = useLocation();
    const fleet = useFleet();
    const node = fleet.nodes.find((candidate) => String(candidate.id) === id);
    const panel = nodePanelFromPath(pathname);

    if (node === undefined) {
        return (
            <Frame
                title="nodes"
                testId={fleet.loading || !fleet.processesLoaded ? undefined : "record-missing"}
            >
                <Note>
                    {fleet.loading || !fleet.processesLoaded
                        ? "Loading…"
                        : `No record ${id} in nodes.`}
                </Note>
            </Frame>
        );
    }

    return (
        <RecordLayout kind="nodes" row={node}>
            <NodeSections fleet={fleet} node={node} panel={panel} />
            <Outlet />
        </RecordLayout>
    );
}
