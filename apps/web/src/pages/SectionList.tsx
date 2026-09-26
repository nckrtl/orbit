import { useParams, useSearch } from "@tanstack/react-router";
import { useMemo } from "react";
import { type Fleet, useFleet } from "../api/queries";
import type {
    AnyRecord,
    Database,
    FirewallRule,
    Instance,
    Kind,
    Node,
    Process,
    Project,
    Schedule,
} from "../api/types";
import {
    firewallHealthy,
    instanceHealthy,
    instancesForProject,
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
import { QuotaList } from "./Quota";

type ListKind = Exclude<Kind, "deployments">;

function columnsFor(section: ListKind, fleet: Fleet): Column<AnyRecord>[] {
    const columns: { [K in ListKind]: () => Column<never>[] } = {
        // The Nodes page draws its own two tables; see NodesList.
        nodes: (): Column<Node>[] => [],
        projects: (): Column<Project>[] => [
            { header: "Name", width: 34, value: (project) => project.name },
            { header: "Slug", width: 24, value: (project) => project.slug },
            {
                header: "Default branch",
                width: 22,
                value: (project) => project.default_branch ?? "main",
            },
            {
                header: "Instances",
                width: 20,
                value: (project) => String(instancesForProject(fleet, project.slug).length),
                sort: (project) => instancesForProject(fleet, project.slug).length,
            },
        ],
        instances: (): Column<Instance>[] => [
            { header: "Project", width: 18, value: (i) => (i.project ?? i.app).slug },
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
    projects: () => false,
    instances: (row: Instance) => !instanceHealthy(row),
    processes: (row: Process) => !processHealthy(row),
    schedules: (row: Schedule) => !scheduleHealthy(row),
    databases: () => false,
    firewall: (row: FirewallRule) => !firewallHealthy(row),
};

/** A section without an open record: the family's list, with its filters and its create link in the border. */
export function SectionList() {
    const { section } = useParams({ from: "/$section" });

    if (section === "nodes") {
        return <NodesList />;
    }

    if (section === "quota") {
        return <QuotaList />;
    }

    return <RecordList />;
}

function RecordList() {
    const { section } = useParams({ from: "/$section" });
    const search = useSearch({ from: "/$section" });
    const go = useGo();
    const fleet = useFleet();
    const known = section in WARN;
    const kind = section as ListKind;
    const rows = useMemo(
        () => (known ? listRows(fleet, kind, search.node, search.project) : []),
        [fleet, kind, known, search.node, search.project],
    );
    const columns = useMemo(() => (known ? columnsFor(kind, fleet) : []), [fleet, kind, known]);

    if (!known) {
        return (
            <Frame title="Not found" testId="section-list" state="warn">
                <Note>No section named {section}.</Note>
            </Frame>
        );
    }

    const cycle = (name: "node" | "project") => {
        const values =
            name === "node"
                ? fleet.nodes.map((node) => node.name)
                : fleet.projects.map((project) => project.slug);
        const current = search[name];
        go.filter(section, name, values[current === undefined ? 0 : values.indexOf(current) + 1]);
    };

    const filters = FILTERED_SECTIONS.includes(section)
        ? (["node", "project"] as const).map((name) => (
              <span
                  key={name}
                  data-testid={name === "node" ? "section-filter-node" : "section-filter-project"}
                  className={`cursor-pointer ${search[name] === undefined ? "text-dim hover:text-fg" : "text-cyan"}`}
                  onClick={() => cycle(name)}
              >
                  {name}: {search[name] ?? "all"} ▾
              </span>
          ))
        : undefined;

    return (
        <div
            data-testid="section-list"
            className="flex flex-col gap-y-[var(--panel-gap)] md:grid md:h-full md:grid-rows-[minmax(0,1fr)]"
        >
            <PageHeader trail={[{ label: SECTION_TITLES[section as Section] }]}>
                {filters}
            </PageHeader>
            <Pane
                name="list"
                order={1}
                title={SECTION_TITLES[section as Section]}
                className="w-full flex-1 min-h-[300px] md:min-h-0"
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
