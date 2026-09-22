import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import type { Schedule } from "../../src/api/types";
import { openApp, pane, row } from "./app";

function openSchedules(path: string) {
    return openApp(path, {
        wrapTransport: (inner) => async (method, url, body) => {
            if (url === "/api/v1/schedules/node-nightly/logs") {
                return { status: 200, payload: { data: { output: "synthetic schedule log" } } };
            }
            const response = await inner(method, url, body);
            if (url !== "/api/v1/schedules") return response;
            const payload = response.payload as { data: Schedule[] };
            return {
                ...response,
                payload: {
                    ...payload,
                    data: [
                        ...payload.data,
                        {
                            ...payload.data[0]!,
                            id: "node-nightly",
                            name: "node-nightly",
                            target_type: "node",
                            target_id: 1,
                            desired_timer_state: "disabled",
                        },
                    ],
                },
            };
        },
    });
}

it.each(["/schedules", "/schedules?node=gateway"])(
    "shows a Node Schedule in %s and opens its correct owner",
    async (path) => {
        const app = await openSchedules(path);
        await expect.element(row("Schedules", "node-nightly")).toBeVisible();
        await expect.element(row("Schedules", "node-nightly")).toHaveTextContent("node gateway");
        await expect
            .element(row("Schedules", "node-nightly"))
            .not.toHaveTextContent("charlie-shop");
        await row("Schedules", "node-nightly").click();
        await expect.element(pane("Properties")).toHaveTextContent("Ownernode gateway");
        await expect.element(pane("Properties")).toHaveTextContent("Nodegateway");
        await expect.element(pane("Log")).toHaveTextContent("synthetic schedule log");
        const breadcrumb = page.getByRole("navigation", { name: "Breadcrumb" });
        await expect.element(breadcrumb).toHaveTextContent("Nodes›gateway›node-nightly");
        await breadcrumb.getByText("gateway", { exact: true }).click();
        await expect.poll(app.url).toBe("/nodes/1");
        expect(app.gateway.requests.every((request) => request.method === "GET")).toBe(true);
    },
);

it.each([
    "/schedules?project=charlie-shop",
    "/schedules?node=beast",
    "/projects/3",
    "/instances/1",
])("keeps Node schedules out of the unrelated Instance scope %s", async (path) => {
    await openSchedules(path);
    await expect.element(row("Schedules", "backup")).toBeVisible();
    await expect.element(row("Schedules", "node-nightly")).not.toBeInTheDocument();
});

it("uses the same Node owner in dashboard and attention rows", async () => {
    await openSchedules("/");
    await expect.element(row("Schedules", "node-nightly")).toHaveTextContent("node gateway");
    await expect.element(row("Needs attention", "node-nightly")).toHaveTextContent("node gateway");
});
