import { beforeEach, describe, expect, it } from "vite-plus/test";
import type { Activity } from "../api/activities";
import { api, GatewayError, get, setTransport } from "../api/client";
import type { Process, Tool, ToolInventory } from "../api/types";
import { parseDefinitionList, type Definition } from "../definitions/definition";
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

    it("serves task definitions from the fleet fixtures", async () => {
        const listed = parseDefinitionList(await get<Definition[]>("/api/v1/task-definitions"));
        const charlie = parseDefinitionList(
            await get<Definition[]>("/api/v1/task-definitions?project_id=3"),
        );
        const maintenance = await get<Definition>(
            "/api/v1/projects/3/task-definitions/maintenance",
        );

        expect(listed.map((row) => row.name).sort()).toEqual(["digest", "maintenance", "publish"]);
        expect(charlie.map((row) => row.name)).toEqual(["maintenance"]);
        expect(maintenance.schedule).toEqual({
            cron: "0 3 * * 1",
            values: { app: "charlie-shop" },
        });
        await expect(get("/api/v1/projects/3/task-definitions/missing")).rejects.toMatchObject({
            status: 404,
        });
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

    it("returns every stored tool for the node, including failed rows", async () => {
        const stored = await get<Tool[]>("/api/v1/tools?node_id=3");
        const healthy = await get<Tool[]>("/api/v1/tools?node_id=2");

        expect(stored.map((tool) => [tool.package, tool.status])).toEqual([
            ["nginx", "failed"],
            ["gh", "installed"],
        ]);
        expect(stored.find((tool) => tool.package === "nginx")).toMatchObject({
            manager: "apt",
            installed_version: null,
            failed_operation: "install",
            error_code: "tool.manager_failed",
        });
        expect(healthy.map((tool) => tool.package)).toEqual(["jq", "ripgrep", "vite-plus"]);
        await expect(get("/api/v1/tools")).rejects.toMatchObject({ status: 422 });
        expect(await get<Tool[]>("/api/v1/tools?node_id=4")).toHaveLength(11);
    });

    it("reads a macOS inventory without adopting or updating", async () => {
        const scan = await get<ToolInventory>("/api/v1/tool-inventory?node_id=4");
        const unmanaged = scan.managers.flatMap((manager) =>
            manager.scan_state === "complete"
                ? manager.packages.filter((pkg) => !pkg.registered)
                : [],
        );

        expect(scan.managers.map((manager) => manager.scan_state)).toEqual([
            "complete",
            "complete",
            "complete",
        ]);
        expect(unmanaged.map((pkg) => [pkg.manager, pkg.package, pkg.adoption_block])).toEqual([
            ["brew", "delta", null],
            ["brew", "ghost", null],
            ["brew", "openssl@3", "dependency"],
            ["brew", "visual-studio-code", null],
            ["brew", "wireguard-tools", "protected"],
            ["brew-cask", "docker", "authorization_required"],
            ["brew-cask", "font-hack", "unsupported_artifact"],
            ["vp", "pnpm", "protected"],
            ["vp", "typescript", "version_unreadable"],
        ]);
        expect(gateway.requests.every((request) => request.method === "GET")).toBe(true);
        await expect(get("/api/v1/tool-inventory?node_id=3")).rejects.toMatchObject({
            status: 409,
            code: "tool.node_inactive",
        });
    });

    it("adopts one package and leaves the rest of the inventory", async () => {
        const adopted = await api<Tool>("POST", "/api/v1/tools/adopt", {
            node_id: 4,
            manager: "brew",
            package: "delta",
            version_constraint: "^0.18.0",
        });

        expect(adopted).toMatchObject({
            manager: "brew",
            package: "delta",
            version_constraint: "^0.18.0",
            status: "installed",
            installed_version: "0.18.2",
            outcome: "applied",
        });
        expect(gateway.requests.at(-1)).toEqual({
            method: "POST",
            path: "/api/v1/tools/adopt",
            body: {
                node_id: 4,
                manager: "brew",
                package: "delta",
                version_constraint: "^0.18.0",
            },
        });

        const names = (await get<Tool[]>("/api/v1/tools?node_id=4")).map((tool) => tool.package);
        const scan = await get<ToolInventory>("/api/v1/tool-inventory?node_id=4");
        const unmanaged = scan.managers
            .flatMap((manager) => manager.packages)
            .filter((pkg) => !pkg.registered)
            .map((pkg) => pkg.package);

        expect(names).toContain("delta");
        expect(unmanaged).not.toContain("delta");
        expect(unmanaged).toContain("openssl@3");
        expect(unmanaged).toContain("docker");
        await expect(
            api("POST", "/api/v1/tools/adopt", {
                node_id: 4,
                manager: "brew",
                package: "ghost",
            }),
        ).rejects.toMatchObject({ status: 409, code: "tool.package_absent" });
        expect(
            (await get<Tool[]>("/api/v1/tools?node_id=4")).some((tool) => tool.package === "ghost"),
        ).toBe(false);
    });

    it("updates the recorded version and removes only the named tool", async () => {
        const updated = await api<Tool>("POST", "/api/v1/tools/6/update", {});

        expect(updated).toMatchObject({
            package: "jq",
            installed_version: "1.8.0",
            outcome: "applied",
        });
        expect(gateway.requests.at(-1)).toEqual({
            method: "POST",
            path: "/api/v1/tools/6/update",
            body: {},
        });

        const removed = await api<Tool>("DELETE", "/api/v1/tools/13");

        expect(removed).toMatchObject({ package: "wget", outcome: "applied" });
        expect(
            (await get<Tool[]>("/api/v1/tools?node_id=4")).map((tool) => tool.package),
        ).not.toContain("wget");
        expect(
            (await get<Tool[]>("/api/v1/tools?node_id=4")).map((tool) => tool.package),
        ).toContain("jq");
    });
});
