import { useRouter } from "@tanstack/react-router";
import { useMemo } from "react";
import type { AnyRecord, Deployment, Kind } from "../api/types";
import { ui } from "./store";

export const SECTIONS = [
    "dashboard",
    "nodes",
    "apps",
    "instances",
    "processes",
    "schedules",
    "databases",
    "firewall",
] as const;
export type Section = (typeof SECTIONS)[number];

/** The sections the sidebar lists. The others have no list of their own: their records open from what owns them. */
export const NAV = [
    "dashboard",
    "nodes",
    "apps",
    "databases",
] as const satisfies readonly Section[];

type Owned = { id: number | string; target_type: string };

/**
 * The sidebar entry a page belongs under. An App owns its instances, a node owns its firewall
 * rules, and a process or a schedule belongs to a node or, through its instance, to an App.
 */
export function navFor(
    section: Section,
    id: string | undefined,
    owned: { processes: Owned[]; schedules: Owned[] },
): (typeof NAV)[number] {
    switch (section) {
        case "instances":
            return "apps";
        case "firewall":
            return "nodes";
        case "processes":
        case "schedules":
            return owned[section].find((row) => String(row.id) === id)?.target_type === "node"
                ? "nodes"
                : "apps";
        default:
            return section;
    }
}

export const SECTION_TITLES: Record<Section, string> = {
    dashboard: "Dashboard",
    nodes: "Nodes",
    apps: "Apps",
    instances: "Instances",
    processes: "Processes",
    schedules: "Schedules",
    databases: "Databases",
    firewall: "Firewall",
};

export const FILTERED_SECTIONS: readonly string[] = ["instances", "processes", "schedules"];

/** Where the screen can go. Every move clears the pane focus, as opening a page does in `orbit top`. */
export function useGo() {
    const router = useRouter();

    return useMemo(() => {
        const reset = () => ui.set({ hover: "nav", focus: null, menu: null });

        return {
            section(section: Section): void {
                reset();
                void (section === "dashboard"
                    ? router.navigate({ to: "/" })
                    : router.navigate({ to: "/$section", params: { section } }));
            },
            record(kind: Kind, row: AnyRecord): void {
                reset();

                if (kind === "deployments") {
                    const deployment = row as Deployment;
                    void router.navigate({
                        to: "/instances/$id/deployments/$deploymentId",
                        params: {
                            id: String(deployment.app_instance_id),
                            deploymentId: String(deployment.id),
                        },
                    });

                    return;
                }

                void router.navigate({
                    to: "/$section/$id",
                    params: { section: kind, id: String(row.id) },
                });
            },
            create(): void {
                reset();
                void router.navigate({ to: "/nodes/create" });
            },
            filter(section: string, name: "node" | "app", value: string | undefined): void {
                void router.navigate({
                    to: "/$section",
                    params: { section },
                    search: (previous: { node?: string; app?: string }) => ({
                        ...previous,
                        [name]: value,
                    }),
                    replace: true,
                });
            },
            back(): void {
                reset();

                if (router.history.canGoBack()) {
                    router.history.back();

                    return;
                }

                const [section] = router.state.location.pathname.split("/").filter(Boolean);
                void (section === undefined
                    ? router.navigate({ to: "/" })
                    : router.navigate({ to: "/$section", params: { section } }));
            },
        };
    }, [router]);
}
