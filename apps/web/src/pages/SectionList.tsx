import { useParams, useSearch } from "@tanstack/react-router";
import { useMemo } from "react";
import { type Fleet, useFleet } from "../api/queries";
import type {
    AnyRecord,
    App,
    Database,
    FirewallRule,
    Instance,
    Kind,
    Node,
    Process,
    Schedule,
} from "../api/types";
import {
    firewallHealthy,
    instanceHealthy,
    instancesForApp,
    listRows,
    nodeHealthy,
    nodeName,
    processHealthy,
    scheduleHealthy,
} from "../fleet/fleet";
import { Frame, Note } from "../ui/Frame";
import { FILTERED_SECTIONS, SECTION_TITLES, type Section, useGo } from "../ui/go";
import { PageHeader } from "../ui/PageHeader";
import { type Column, Pane } from "../ui/Pane";
import { Status } from "../ui/Status";
import { processListColumns, scheduleListColumns } from "./columns";
import { NodesList } from "./NodesList";

type ListKind = Exclude<Kind, "deployments">;

function columnsFor(section: ListKind, fleet: Fleet): Column<AnyRecord>[] {
    const columns: { [K in ListKind]: () => Column<never>[] } = {
        // The Nodes page draws its own two tables; see NodesList.
        nodes: (): Column<Node>[] => [],
        apps: (): Column<App>[] => [
            { header: "Name", width: 34, value: (a) => a.name },
            { header: "Slug", width: 24, value: (a) => a.slug },
            { header: "Default branch", width: 22, value: (a) => a.default_branch ?? "main" },
            {
                header: "Instances",
                width: 20,
                value: (a) => String(instancesForApp(fleet, a.slug).length),
                sort: (a) => instancesForApp(fleet, a.slug).length,
            },
        ],
        instances: (): Column<Instance>[] => [
            { header: "App", width: 18, value: (i) => i.app.slug },
            { header: "Name", width: 14, value: (i) => i.name },
            { header: "Environment", width: 14, value: (i) => i.environment },
            { header: "Node", width: 12, value: (i) => i.node.name },
            { header: "Domain", width: 28, value: (i) => i.domain ?? "—" },
            {
                header: "Status",
                width: 10,
                value: (i) => i.status,
                cell: (i) => <Status value={i.status} />,
            },
        ],
        processes: () => processListColumns(fleet),
        schedules: () => scheduleListColumns(fleet),
        databases: (): Column<Database>[] => [
            { header: "Slug", width: 20, value: (d) => d.slug },
            { header: "Driver", width: 12, value: (d) => d.driver },
            { header: "Node", width: 16, value: (d) => nodeName(fleet, Number(d.node_id)) },
            {
                header: "Host",
                width: 28,
                value: (d) => (d.host !== null ? `${d.host}:${d.port}` : (d.path ?? "—")),
            },
            { header: "Database", width: 24, value: (d) => d.database ?? "—" },
        ],
        firewall: (): Column<FirewallRule>[] => [
            { header: "Node", width: 22, value: (f) => f.node },
            {
                header: "Port",
                width: 16,
                value: (f) => `${f.port}/${f.protocol}`,
                sort: (f) => Number(f.port) || 0,
            },
            { header: "Action", width: 14, value: (f) => f.action },
            { header: "Source", width: 30, value: (f) => f.source },
            { header: "Status", width: 18, value: (f) => f.status },
        ],
    };

    return columns[section]() as Column<AnyRecord>[];
}

const WARN: { [K in ListKind]: (row: never) => boolean } = {
    nodes: (row: Node) => !nodeHealthy(row),
    apps: () => false,
    instances: (row: Instance) => !instanceHealthy(row),
    processes: (row: Process) => !processHealthy(row),
    schedules: (row: Schedule) => !scheduleHealthy(row),
    databases: () => false,
    firewall: (row: FirewallRule) => !firewallHealthy(row),
};

/** A section without an open record: the family's list, with its filters and its create link in the border. */
export function SectionList() {
    const { section } = useParams({ from: "/$section" });

    return section === "nodes" ? <NodesList /> : <RecordList />;
}

function RecordList() {
    const { section } = useParams({ from: "/$section" });
    const search = useSearch({ from: "/$section" });
    const go = useGo();
    const fleet = useFleet();
    const known = section in WARN;
    const kind = section as ListKind;
    const rows = useMemo(
        () => (known ? listRows(fleet, kind, search.node, search.app) : []),
        [fleet, kind, known, search.node, search.app],
    );
    const columns = useMemo(() => (known ? columnsFor(kind, fleet) : []), [fleet, kind, known]);

    if (!known) {
        return (
            <Frame title="Not found" state="warn">
                <Note>No section named {section}.</Note>
            </Frame>
        );
    }

    const cycle = (name: "node" | "app") => {
        const values =
            name === "node"
                ? fleet.nodes.map((node) => node.name)
                : fleet.apps.map((app) => app.slug);
        const current = search[name];
        go.filter(section, name, values[current === undefined ? 0 : values.indexOf(current) + 1]);
    };

    const filters = FILTERED_SECTIONS.includes(section)
        ? (["node", "app"] as const).map((name) => (
              <span
                  key={name}
                  className={`cursor-pointer ${search[name] === undefined ? "text-dim hover:text-fg" : "text-cyan"}`}
                  onClick={() => cycle(name)}
              >
                  {name}: {search[name] ?? "all"} ▾
              </span>
          ))
        : undefined;

    return (
        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-y-[16px]">
            <PageHeader trail={[{ label: SECTION_TITLES[section as Section] }]}>
                {filters}
            </PageHeader>
            <Pane
                name="list"
                order={1}
                title={SECTION_TITLES[section as Section]}
                columns={columns}
                rows={rows}
                rowId={(row) => String(row.id)}
                warn={WARN[kind] as (row: AnyRecord) => boolean}
                target={(row) => ({ kind, row })}
                empty={
                    fleet.loading || (kind === "processes" && !fleet.processesLoaded)
                        ? "Loading…"
                        : "None."
                }
            />
        </div>
    );
}
