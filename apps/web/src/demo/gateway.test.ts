import { beforeEach, describe, expect, it } from "vite-plus/test";
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
