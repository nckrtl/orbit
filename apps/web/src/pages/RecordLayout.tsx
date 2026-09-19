import { type ReactNode, useEffect } from "react";
import type { AnyRecord, Kind } from "../api/types";
import { recordTitle } from "../fleet/fleet";
import { useGo } from "../ui/go";
import { openMenu } from "../ui/menu";
import { setPageTarget } from "../ui/page";

const LABELS: Record<Kind, string> = {
    nodes: "Node",
    apps: "App",
    instances: "App instance",
    processes: "Process",
    schedules: "Schedule",
    databases: "Database",
    firewall: "Firewall rule",
    deployments: "Deployment",
};

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

    useEffect(() => {
        setPageTarget({ kind, row });

        return () => setPageTarget(null);
    }, [kind, row]);

    return (
        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-y-[16px]">
            <div className="flex gap-[2ch] px-[1ch]">
                <span className="cursor-pointer text-cyan" onClick={() => go.back()}>
                    ‹ back
                </span>
                <span>
                    <span className="text-dim">{LABELS[kind]}: </span>
                    <span className="font-bold selectable">{recordTitle(kind, row)}</span>
                </span>
                {kind !== "deployments" && (
                    <span
                        className="ml-auto cursor-pointer text-dim hover:text-fg"
                        onClick={(event) => openMenu({ kind, row }, [event.clientX, event.clientY])}
                    >
                        actions ▾
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}
