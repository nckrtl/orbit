import { useMemo } from "react";
import { useFleet } from "../api/queries";
import type { Instance, Project } from "../api/types";
import {
    type AttentionRow,
    attentionRows,
    instanceHealthy,
    instancesForProject,
    nodeHealthy,
    processHealthy,
    scheduleHealthy,
} from "../fleet/fleet";
import { Frame, Note } from "../ui/Frame";
import { type Column, Pane } from "../ui/Pane";
import { Status, StatusDot } from "../ui/Status";
import { processDashboardColumns, scheduleColumns } from "./columns";
import { useNodeTables } from "./nodeTables";

/**
 * The dashboard: one compact row per node, a pane per family, and everything that needs a look.
 * Fleet counts live in the sidebar. Each pane scrolls its own rows.
 */
export function Dashboard() {
    const fleet = useFleet();
    const attention = useMemo(() => attentionRows(fleet), [fleet]);

    const { workers, clients, workerColumns, clientColumns, offline } = useNodeTables();
    const projectColumns = useMemo<Column<Project>[]>(
        () => [
            { header: "Slug", width: 44, value: (project) => project.slug },
            {
                header: "Branch",
                width: 30,
                hideOnMobile: true,
                value: (project) => project.default_branch ?? "main",
            },
            {
                header: "Instances",
                width: 26,
                value: (project) => String(instancesForProject(fleet, project.slug).length),
                sort: (project) => instancesForProject(fleet, project.slug).length,
            },
        ],
        [fleet],
    );
    const instanceColumns = useMemo<Column<Instance>[]>(
        () => [
            {
                header: "Name",
                width: 26,
                value: (i) => i.name,
                cell: (i) => (
                    <span className="flex items-center">
                        <StatusDot value={i.status} />
                        <span>{i.name}</span>
                    </span>
                ),
            },
            { header: "Project", width: 26, value: (i) => i.project.slug },
            { header: "Node", width: 24, value: (i) => i.node.name },
            {
                header: "Status",
                width: 24,
                hideOnMobile: true,
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
    const scheduleCols = useMemo(() => scheduleColumns(fleet, "owner"), [fleet]);

    if (fleet.error !== null) {
        return (
            <Frame title="Gateway" state="warn">
                <Note>{fleet.error.message}</Note>
            </Frame>
        );
    }

    return (
        <div className="flex flex-col gap-y-[var(--panel-gap)] md:grid md:h-full md:grid-cols-6 md:grid-rows-[auto_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] md:gap-x-[1ch]">
            <Pane
                name="nodes"
                order={0}
                title="Worker nodes"
                className="w-full min-h-[160px] max-h-[40vh] md:col-span-4 md:max-h-[34vh]"
                columns={workerColumns}
                rows={workers}
                rowId={(n) => String(n.id)}
                warn={(n) => !nodeHealthy(n) || offline(n)}
                target={(row) => ({ kind: "nodes", row })}
                empty={fleet.loading ? "Loading fleet data…" : "No worker nodes."}
            />
            <Pane
                name="clients"
                order={1}
                title="Client nodes"
                className="w-full min-h-[140px] max-h-[35vh] md:col-span-2 md:max-h-[34vh]"
                columns={clientColumns}
                rows={clients}
                rowId={(n) => String(n.id)}
                warn={(n) => !nodeHealthy(n)}
                target={(row) => ({ kind: "nodes", row })}
                empty={fleet.loading ? "Loading fleet data…" : "No client nodes."}
            />
            <Pane
                name="projects"
                className="w-full min-h-[140px] max-h-[35vh] md:col-span-3 md:max-h-none"
                order={2}
                title="Projects"
                columns={projectColumns}
                rows={fleet.projects}
                rowId={(project) => String(project.id)}
                target={(row) => ({ kind: "projects", row })}
                empty="No projects."
            />
            <Pane
                name="instances"
                className="w-full min-h-[140px] max-h-[35vh] md:col-span-3 md:max-h-none"
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
                className="w-full min-h-[140px] max-h-[35vh] md:col-span-3 md:max-h-none"
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
                className="w-full min-h-[140px] max-h-[35vh] md:col-span-3 md:max-h-none"
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
                className="w-full min-h-[140px] max-h-[35vh] md:col-span-6 md:max-h-none"
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
