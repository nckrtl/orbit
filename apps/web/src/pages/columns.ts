import type { Fleet } from "../api/queries";
import type { Instance, Process, Schedule } from "../api/types";
import { processCpu, processMemory, runtimeNodeName, runtimeOwner } from "../fleet/fleet";
import type { Column } from "../ui/Pane";

const cpuSort = (process: Process): number => process.cpu ?? -1;
const memorySort = (process: Process): number => process.memory_bytes ?? -1;

// The column sets more than one page draws. Widths are the shares `orbit top` gives them.

export const processColumns: Column<Process>[] = [
    { header: "Name", width: 46, value: (p) => p.name },
    { header: "Status", width: 28, value: (p) => p.runtime_status },
    { header: "CPU", width: 11, value: processCpu, sort: cpuSort, align: "right" },
    { header: "MEM", width: 11, value: processMemory, sort: memorySort, align: "right" },
];

export const processListColumns = (fleet: Fleet): Column<Process>[] => [
    { header: "Name", width: 18, value: (p) => p.name },
    { header: "Owner", width: 26, value: (p) => runtimeOwner(fleet, p) },
    { header: "Node", width: 14, value: (p) => runtimeNodeName(fleet, p) },
    { header: "Runtime", width: 12, value: (p) => p.runtime },
    { header: "Status", width: 14, value: (p) => p.runtime_status },
    { header: "CPU", width: 7, value: processCpu, sort: cpuSort, align: "right" },
    { header: "MEM", width: 7, value: processMemory, sort: memorySort, align: "right" },
];

export const processDashboardColumns = (fleet: Fleet): Column<Process>[] => [
    { header: "Name", width: 24, value: (p) => p.name },
    { header: "Where", width: 30, value: (p) => runtimeOwner(fleet, p) },
    { header: "Status", width: 22, value: (p) => p.runtime_status },
    { header: "CPU", width: 10, value: processCpu, sort: cpuSort, align: "right" },
    { header: "MEM", width: 10, value: processMemory, sort: memorySort, align: "right" },
];

export const scheduleColumns = (
    fleet: Fleet,
    where: "none" | "instance" | "owner",
): Column<Schedule>[] => [
    { header: "Name", width: where === "none" ? 30 : 20, value: (s) => s.name },
    ...(where !== "none"
        ? [
              {
                  header: where === "owner" ? "Owner" : "Instance",
                  width: 22,
                  value: (s: Schedule) => runtimeOwner(fleet, s),
              },
          ]
        : []),
    { header: "Calendar", width: where === "none" ? 42 : 36, value: (s) => s.calendar },
    { header: "Last run", width: 22, value: (s) => s.last_run_status ?? "never" },
];

export const scheduleListColumns = (fleet: Fleet): Column<Schedule>[] => [
    { header: "Name", width: 18, value: (s) => s.name },
    { header: "Owner", width: 20, value: (s) => runtimeOwner(fleet, s) },
    { header: "Node", width: 12, value: (s) => runtimeNodeName(fleet, s) },
    { header: "Calendar", width: 30, value: (s) => s.calendar },
    { header: "Last run", width: 20, value: (s) => s.last_run_status ?? "never" },
];

export const instanceColumns = (show: "project" | "node"): Column<Instance>[] => [
    ...(show === "project"
        ? [{ header: "Project", width: 22, value: (i: Instance) => i.project.slug }]
        : []),
    { header: "Name", width: 16, value: (i) => i.name },
    { header: "Environment", width: 16, value: (i) => i.environment },
    ...(show === "node"
        ? [{ header: "Node", width: 14, value: (i: Instance) => i.node.name }]
        : []),
    { header: "Domain", width: show === "project" ? 32 : 40, value: (i) => i.domain ?? "—" },
    { header: "Status", width: 12, value: (i) => i.status },
];
