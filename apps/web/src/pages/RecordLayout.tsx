import { type ReactNode, useEffect } from "react";
import { type Fleet, useFleet } from "../api/queries";
import type { AnyRecord, Deployment, Instance, Kind } from "../api/types";
import { recordTitle } from "../fleet/fleet";
import { SECTION_TITLES, type Section, useGo } from "../ui/go";
import { openMenu } from "../ui/menu";
import { setPageTarget } from "../ui/page";

type Crumb = { label: string; open?: () => void };

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
            <div className="flex gap-[2ch] px-[1ch]">
                <nav aria-label="Breadcrumb" className="flex min-w-0 gap-[1ch]">
                    {trail.map((crumb, index) => {
                        const last = index === trail.length - 1;

                        return (
                            <span key={index} className="flex min-w-0 gap-[1ch]">
                                {index > 0 && <span className="text-dim">›</span>}
                                {last || crumb.open === undefined ? (
                                    <span
                                        className={`selectable truncate ${last ? "font-bold" : "text-dim"}`}
                                        aria-current={last ? "page" : undefined}
                                    >
                                        {crumb.label}
                                    </span>
                                ) : (
                                    <span
                                        className="cursor-pointer truncate text-dim hover:text-fg"
                                        onClick={crumb.open}
                                    >
                                        {crumb.label}
                                    </span>
                                )}
                            </span>
                        );
                    })}
                </nav>
                {kind !== "deployments" && (
                    <span
                        className="ml-auto cursor-pointer text-dim hover:text-fg"
                        onClick={(event) => {
                            const button = event.currentTarget.getBoundingClientRect();

                            openMenu({ kind, row }, [button.right, button.bottom + 4], true);
                        }}
                    >
                        actions ▾
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}
