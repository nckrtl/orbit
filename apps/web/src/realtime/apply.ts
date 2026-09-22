import type { QueryClient } from "@tanstack/react-query";
import { normalizeInstance } from "../api/instances";
import type { InstanceWire } from "../api/types";

export type RealtimeEvent = { type: string; id: number; at: string; data: Record<string, unknown> };

const COLLECTIONS: Record<string, string> = {
    node: "nodes",
    app: "projects",
    project: "projects",
    instance: "instances",
    process: "processes",
    schedule: "schedules",
    database: "databases",
    firewall: "firewall",
};

type Row = Record<string, unknown>;

/** Merges a row into a cached list by id, or removes it. Actions use it too, ahead of their event. */
export function applyRow(client: QueryClient, collection: string, verb: string, data: Row): void {
    if (data.id === undefined || data.id === null) {
        return;
    }

    client.setQueryData<Row[]>([collection], (rows) => {
        if (rows === undefined) {
            return rows;
        }

        if (verb === "deleted") {
            return rows.filter((row) => row.id !== data.id);
        }

        const patch =
            collection === "instances" ? normalizeInstance(data as Partial<InstanceWire>) : data;

        if (rows.some((row) => row.id === data.id)) {
            return rows.map((row) => (row.id === data.id ? { ...row, ...patch } : row));
        }

        if (collection === "instances" && patch.project === undefined) {
            void client.invalidateQueries({ queryKey: ["instances"] });
            return undefined;
        }

        return [...rows, patch];
    });
}

/** Applies one record-change event to the cached list it names. */
export function applyEvent(client: QueryClient, event: RealtimeEvent): void {
    const [family = "", verb = ""] = event.type.split(".", 2);
    const collection = COLLECTIONS[family];

    if (collection !== undefined) {
        applyRow(client, collection, verb, event.data);

        return;
    }

    // Deploy steps live inside their Instance; a deployment changes that instance's history.
    if (family === "deploy_step") {
        void client.invalidateQueries({ queryKey: ["instances"] });
    }

    if (family === "deployment") {
        void client.invalidateQueries({ queryKey: ["deployments"] });
    }
}
