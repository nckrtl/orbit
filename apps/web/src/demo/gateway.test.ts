import { beforeEach, describe, expect, it } from "vite-plus/test";
import type { Activity } from "../api/activities";
import { api, GatewayError, get, setTransport } from "../api/client";
import type { Process } from "../api/types";
import { createDemoGateway } from "./gateway";

let gateway: ReturnType<typeof createDemoGateway>;

beforeEach(() => {
    gateway = createDemoGateway();
    setTransport(gateway.transport, "demo fleet");
});

describe("the demo Gateway", () => {
    it("keeps the result of an action, so the next read agrees with it", async () => {
        const stopped = await api<Process>("POST", "/api/v1/processes/1/stop");
        const listed = (await get<Process[]>("/api/v1/processes")).find(
            (process) => process.id === 1,
        );

        expect([stopped.desired_state, stopped.runtime_status]).toEqual(["stopped", "inactive"]);
        expect(listed?.runtime_status).toBe("inactive");
    });

    it("refuses an app-dev Node without a TLD with the recorded Gateway error", async () => {
        const refusal = await api("POST", "/api/v1/nodes", {
            name: "spare",
            roles: ["app-dev"],
        }).catch((error: unknown) => error);

        expect(refusal).toBeInstanceOf(GatewayError);
        expect((refusal as GatewayError).code).toBe("node.tld_required");
    });

    it("pages and filters activity, and shows one row with its properties", async () => {
        const newest = await get<Activity[]>("/api/v1/activities?limit=2");
        const older = await get<Activity[]>("/api/v1/activities?limit=2&before_id=149");
        const failed = await get<Activity[]>(
            "/api/v1/activities?limit=25&status=failed&command=node%3Aadd",
        );
        const byCaller = await get<Activity[]>("/api/v1/activities?limit=25&caller_node_id=2");
        const shown = await get<Activity>("/api/v1/activities/148");

        expect(newest.map((row) => row.id)).toEqual([150, 149]);
        expect(older.map((row) => row.id)).toEqual([148, 147]);
        expect(failed.map((row) => row.id).sort((a, b) => a - b)).toEqual([127, 148]);
        expect(byCaller.length).toBeGreaterThan(0);
        expect(byCaller.every((row) => row.caller_node_id === 2)).toBe(true);
        expect(await get("/api/v1/activities?limit=25&command=missing")).toEqual([]);
        expect(shown.properties).toMatchObject({ password: "[REDACTED]" });
        await expect(get("/api/v1/activities/99999")).rejects.toMatchObject({ status: 404 });
    });

    it("pages a log of at least 150 rows by before_id until a short page", async () => {
        const first = await get<Activity[]>("/api/v1/activities?limit=50");
        const second = await get<Activity[]>("/api/v1/activities?limit=50&before_id=101");
        const third = await get<Activity[]>("/api/v1/activities?limit=50&before_id=51");
        const end = await get<Activity[]>("/api/v1/activities?limit=50&before_id=1");

        expect(first).toHaveLength(50);
        expect(first.map((row) => row.id)).toEqual(
            Array.from({ length: 50 }, (_, index) => 150 - index),
        );
        expect(second.map((row) => row.id)).toEqual(
            Array.from({ length: 50 }, (_, index) => 100 - index),
        );
        expect(third.map((row) => row.id)).toEqual(
            Array.from({ length: 50 }, (_, index) => 50 - index),
        );
        expect(end).toEqual([]);
        expect(first.length + second.length + third.length).toBeGreaterThanOrEqual(150);
    });

    it("records each request for a test to assert on", async () => {
        await api("DELETE", "/api/v1/nodes/2/firewall-rules/https-public");

        expect(gateway.requests.at(-1)).toEqual({
            method: "DELETE",
            path: "/api/v1/nodes/2/firewall-rules/https-public",
            body: undefined,
        });
        expect(await get("/api/v1/nodes/2/firewall-rules")).toHaveLength(1);
    });
});
