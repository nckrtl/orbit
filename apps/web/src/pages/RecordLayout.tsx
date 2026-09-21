import { type ReactNode, useEffect } from "react";
import { type Fleet, useFleet } from "../api/queries";
import type {
    AnyRecord,
    Deployment,
    FirewallRule,
    Instance,
    Kind,
    Node,
    Process,
    Schedule,
} from "../api/types";
import { recordTitle } from "../fleet/fleet";
import { SECTION_TITLES, type Section, useGo } from "../ui/go";
import { openMenu } from "../ui/menu";
import { type Crumb, PageHeader } from "../ui/PageHeader";
import { setPageTarget } from "../ui/page";

/**
 * The way to a record by what owns it, not by the clicks that led there: a Project owns its instances,
 * an instance or a node owns its processes and schedules, and a node owns its firewall rules. Every
 * crumb but the last opens what it names, so the line also takes the reader back up.
 */
function crumbs(kind: Kind, row: AnyRecord, fleet: Fleet, go: ReturnType<typeof useGo>): Crumb[] {
    const section = (name: Section): Crumb => ({
        label: SECTION_TITLES[name],
        open: () => go.section(name),
    });
    const instanceCrumbs = (instance: Instance | undefined): Crumb[] => {
        if (instance === undefined) {
            return [];
        }

        const project = fleet.projects.find(
            (candidate) => candidate.id === (instance.project ?? instance.app).id,
        );

        return [
            section("projects"),
            {
                label: (instance.project ?? instance.app).slug,
                open: project === undefined ? undefined : () => go.record("projects", project),
            },
            { label: instance.name, open: () => go.record("instances", instance) },
        ];
    };
    const nodeCrumbs = (node: Node | undefined): Crumb[] =>
        node === undefined
            ? []
            : [section("nodes"), { label: node.name, open: () => go.record("nodes", node) }];
    const instanceById = (id: number | undefined) =>
        fleet.instances.find((candidate) => candidate.id === id);
    const nodeById = (id: number | undefined) =>
        fleet.nodes.find((candidate) => candidate.id === id);
    // The owner's crumbs, or the record's own section when the owner is not in the fleet.
    const under = (owner: Crumb[]): Crumb[] => [
        ...(owner.length > 0 ? owner : [section(kind as Section)]),
        { label: recordTitle(kind, row) },
    ];

    switch (kind) {
        case "instances":
            return instanceCrumbs(row as Instance);
        case "deployments":
            return under(instanceCrumbs(instanceById((row as Deployment).app_instance_id)));
        case "processes":
        case "schedules": {
            const owned = row as Process | Schedule;

            return under(
                owned.target_type === "node"
                    ? nodeCrumbs(nodeById(owned.target_id))
                    : instanceCrumbs(instanceById(owned.target_id)),
            );
        }
        case "firewall":
            return under(nodeCrumbs(nodeById((row as FirewallRule).node_id)));
        default:
            return under([]);
    }
}

/** One record: the crumbs line, then the panes its family has. */
export function RecordLayout({
    kind,
    row,
    children,
}: {
    kind: Kind;
    row: AnyRecord;
    children: ReactNode;
}) {
    const go = useGo();
    const fleet = useFleet();
    const trail = crumbs(kind, row, fleet, go);

    useEffect(() => {
        setPageTarget({ kind, row });

        return () => setPageTarget(null);
    }, [kind, row]);

    return (
        <div className="flex min-w-0 max-w-full flex-col gap-y-[16px] md:grid md:h-full md:grid-rows-[auto_minmax(0,1fr)]">
            <PageHeader trail={trail}>
                {kind !== "deployments" && (
                    <span
                        className="cursor-pointer text-dim hover:text-fg"
                        onClick={(event) => {
                            const button = event.currentTarget.getBoundingClientRect();

                            openMenu({ kind, row }, [button.right, button.bottom + 4], true);
                        }}
                    >
                        actions ▾
                    </span>
                )}
            </PageHeader>
            {children}
        </div>
    );
}
