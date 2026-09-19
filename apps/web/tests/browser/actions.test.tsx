import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { setTransport } from "../../src/api/client";
import { queryClient } from "../../src/api/queryClient";
import { applyEvent } from "../../src/realtime/apply";
import { connectRealtime } from "../../src/realtime/connect";
import { footer, openApp, pane, row } from "./app";

it("runs an action from the menu and shows the row the Gateway answered with", async () => {
    const app = await openApp("/processes");
    await expect.element(row("Processes", "vite")).toHaveAttribute("data-warn");

    // Into the list, down to vite, then its actions.
    await userEvent.keyboard("{ArrowRight}{Enter}{ArrowDown}");
    await expect.element(row("Processes", "vite")).toHaveAttribute("aria-selected", "true");
    await userEvent.keyboard("a");
    await expect.element(pane("vite")).toHaveTextContent("Restart process [vite].");

    await userEvent.keyboard("{ArrowDown}");
    await expect.element(pane("vite")).toHaveTextContent("Start process [vite].");
    await userEvent.keyboard("{Enter}");

    await expect.element(footer()).toHaveTextContent("Process [vite] started.");
    await expect.element(row("Processes", "vite")).not.toHaveAttribute("data-warn");
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "POST",
        path: "/api/v1/processes/2/start",
    });
});

it("asks before a destructive action and sends nothing until it is confirmed", async () => {
    const app = await openApp("/firewall");
    await row("Firewall", "443/tcp").click({ button: "right" });
    await expect.element(pane("https-public")).toBeVisible();

    await userEvent.keyboard("{Enter}");
    await expect
        .element(pane("https-public"))
        .toHaveTextContent("Confirm? Remove firewall rule [https-public] on node [beast]?");
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);

    await userEvent.keyboard("{Enter}");
    await expect.element(footer()).toHaveTextContent("Firewall rule [https-public] removed.");
    await expect.element(row("Firewall", "443/tcp")).not.toBeInTheDocument();
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "DELETE",
        path: "/api/v1/nodes/2/firewall-rules/https-public",
    });
});

it("cancels a menu with Esc", async () => {
    const app = await openApp("/firewall");
    await row("Firewall", "443/tcp").click({ button: "right" });
    await userEvent.keyboard("{Enter}{Escape}");

    await expect.element(pane("https-public")).not.toBeInTheDocument();
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);
});

it("names the command for an action that has no request", async () => {
    await openApp("/nodes/2");
    await expect.element(pane("Instances on this node")).toBeVisible();

    await userEvent.keyboard("a{ArrowDown}{Enter}");

    await expect.element(footer()).toHaveTextContent("orbit node:ssh beast");
});

it("applies a realtime event to the open screen", async () => {
    await openApp("/");
    await expect.element(row("Needs attention", "vite")).toBeVisible();
    await expect
        .element(page.getByRole("status"))
        .toHaveAccessibleName(/Not connected to the WebSocket; refreshing every 10s/);

    applyEvent(queryClient, {
        type: "process.status",
        id: 2,
        at: "2026-01-01T00:00:00+00:00",
        data: { id: 2, runtime_status: "active" },
    });

    await expect.element(row("Needs attention", "vite")).not.toBeInTheDocument();
    await expect.element(row("Processes", "vite")).toHaveTextContent("active");
});

it("keeps the reason it is not live in the status dot, not in the footer text", async () => {
    const app = await openApp("/");
    await expect
        .element(page.getByRole("status"))
        .toHaveAccessibleName("Not connected to the WebSocket; refreshing every 10s.");

    const refuse: typeof app.gateway.transport = (method, path, body) =>
        path === "/api/v1/realtime"
            ? Promise.resolve({
                  status: 403,
                  payload: {
                      error: { code: "node_access.required", message: "Node access is required." },
                  },
              })
            : app.gateway.transport(method, path, body);
    setTransport(refuse, "demo fleet");
    await connectRealtime(queryClient, new AbortController().signal);

    await expect
        .element(page.getByRole("status"))
        .toHaveAccessibleName(/Not connected to the WebSocket \(Node access is required\.\)/);
    await expect.element(footer()).not.toHaveTextContent("Node access is required.");
});
