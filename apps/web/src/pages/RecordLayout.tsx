import { type ReactNode, useEffect } from "react";
import { type Fleet, useFleet } from "../api/queries";
import type { AnyRecord, Deployment, Instance, Kind } from "../api/types";
import { recordTitle } from "../fleet/fleet";
import { SECTION_TITLES, type Section, useGo } from "../ui/go";
import { openMenu } from "../ui/menu";
import { type Crumb, PageHeader } from "../ui/PageHeader";
import { setPageTarget } from "../ui/page";

/**
 * The way to a record: its section, the records that own it, then the record itself. Every crumb
 * but the last opens what it names, so the line also takes the reader back up.
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

        const app = fleet.apps.find((candidate) => candidate.id === instance.app.id);

        return [
            {
                label: instance.app.slug,
                open: app === undefined ? undefined : () => go.record("apps", app),
            },
            { label: instance.name, open: () => go.record("instances", instance) },
        ];
    };

    switch (kind) {
        case "instances":
            return [section("instances"), ...instanceCrumbs(row as Instance)];
        case "deployments": {
            const deployment = row as Deployment;

            return [
                section("instances"),
                ...instanceCrumbs(
                    fleet.instances.find(
                        (candidate) => candidate.id === deployment.app_instance_id,
                    ),
                ),
                { label: recordTitle(kind, row) },
            ];
        }
        default:
            return [section(kind as Section), { label: recordTitle(kind, row) }];
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
        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-y-[16px]">
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
