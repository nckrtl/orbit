import { get, setTransport } from "../api/client";
import type { Fleet } from "../api/queries";
import type { FirewallRule, Node } from "../api/types";
import { createDemoGateway } from "./gateway";

/** The fixture fleet as the pages see it, read through the demo Gateway. For tests of fleet logic. */
export async function demoFleet(): Promise<Fleet> {
    setTransport(createDemoGateway().transport, "demo fleet");
    const nodes = await get<Node[]>("/api/v1/nodes");
    const firewall = await Promise.all(
        nodes.map((node) => get<FirewallRule[]>(`/api/v1/nodes/${node.id}/firewall-rules`)),
    );

    return {
        nodes,
        apps: await get("/api/v1/apps"),
        instances: await get("/api/v1/instances"),
        processes: await get("/api/v1/processes"),
        schedules: await get("/api/v1/schedules"),
        databases: await get("/api/v1/database-connections"),
        firewall: firewall.flat(),
        processesLoaded: true,
        loading: false,
        error: null,
    };
}
