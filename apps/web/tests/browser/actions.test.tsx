import { expect, it, vi } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { setTransport } from "../../src/api/client";
import { queryClient } from "../../src/api/queryClient";
import { applyEvent } from "../../src/realtime/apply";
import { connectRealtime } from "../../src/realtime/connect";
import { setLiveness } from "../../src/realtime/liveness";
import { footer, openApp, pane, row } from "./app";

it("runs an action from the menu and shows the row the Gateway answered with", async () => {
    const app = await openApp("/processes");
    await expect.element(row("Processes", "vite")).toHaveAttribute("data-warn");

    // Into the list, down to vite, then its actions.
    await userEvent.keyboard("{ArrowRight}{Enter}{ArrowDown}");
    await expect.element(row("Processes", "vite")).toHaveAttribute("aria-selected", "true");
    await userEvent.keyboard("x");
    await expect
        .element(pane("vite").getByRole("menuitem", { name: "restart", exact: true }))
        .toHaveAttribute("data-selected");

    await userEvent.keyboard("{ArrowDown}");
    await expect
        .element(pane("vite").getByRole("menuitem", { name: "start", exact: true }))
        .toHaveAttribute("data-selected");
    await userEvent.keyboard("{Enter}");

    await expect.element(footer()).toHaveTextContent("Process [vite] started.");
    await expect.element(row("Processes", "vite")).not.toHaveAttribute("data-warn");
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "POST",
        path: "/api/v1/processes/2/start",
    });
});

it("destroys a process only after it is confirmed", async () => {
    const app = await openApp("/processes");
    await row("Processes", "vite").click({ button: "right" });
    await expect.element(pane("vite")).toBeVisible();

    await userEvent.keyboard("{ArrowDown}{ArrowDown}{Enter}");
    await expect.element(pane("vite")).toHaveTextContent("Confirm? Destroy process [vite]?");
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);

    await userEvent.keyboard("{Enter}");
    await expect.element(footer()).toHaveTextContent("Process [vite] destroyed.");
    await expect.element(row("Processes", "vite")).not.toBeInTheDocument();
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "DELETE",
        path: "/api/v1/processes/2",
    });
});

it("profiles an instance and shows what the command printed in a modal", async () => {
    const report = "GET https://charlie-shop.test 200 in 41.20ms\n\nTotal ....... 41.20ms";
    const fetched = vi.spyOn(window, "fetch").mockResolvedValue(
        new Response(JSON.stringify({ ok: true, output: report }), {
            headers: { "content-type": "application/json" },
        }),
    );

    await openApp("/instances/1");
    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "profile", exact: true }).click();

    await expect.element(page.getByRole("dialog")).toHaveTextContent("Total ....... 41.20ms");
    expect(fetched).toHaveBeenCalledWith("/__orbit/profile?instance=1");

    await userEvent.keyboard("{Escape}");
    await expect.element(page.getByRole("dialog")).not.toBeInTheDocument();
    fetched.mockRestore();
});

it("shows an instance's Horizon queue by job state and opens a job in Horizon", async () => {
    // A job opens through a real link with a target, so a new tab is the browser's own behaviour.
    const opened: { href: string; target: string }[] = [];
    const click = vi
        .spyOn(HTMLAnchorElement.prototype, "click")
        .mockImplementation(function (this: HTMLAnchorElement) {
            opened.push({ href: this.href, target: this.target });
        });

    await openApp("/instances/1");
    await expect.element(pane("Jobs")).toHaveTextContent("No pending jobs.");
    await expect.element(pane("Queue")).toHaveTextContent("Jobs per minute12");
    await expect
        .element(pane("Queue"))
        .toHaveTextContent("Queue default0 jobs · 0s wait · 3 workers");

    await page.getByRole("tab", { name: "failed 1" }).click();
    await expect.element(row("Jobs", "SendReceipt")).toHaveTextContent("mail server refused");

    await row("Jobs", "SendReceipt").click();
    expect(opened).toEqual([
        { href: "https://charlie-shop.test/horizon/failed/f1", target: "_blank" },
    ]);
    click.mockRestore();
});

it("shows no queue panel for an instance without Horizon", async () => {
    await openApp("/instances/2");
    await expect.element(pane("Application log")).toBeVisible();

    await expect.element(pane("Jobs")).not.toBeInTheDocument();
});

it("lists live UFW on a node and keeps Orbit's own rules without actions", async () => {
    const app = await openApp("/nodes/2");
    const managed = row("Firewall", "orbit:wireguard-members");

    await expect.element(managed).toHaveTextContent("any on orbit");
    await expect.element(managed).toHaveTextContent("live");
    await expect.element(row("Firewall", "orbit:public-ssh-recovery")).not.toBeInTheDocument();

    // A managed live rule opens nothing and offers no menu; an operator rule still does both.
    await managed.click();
    expect(app.url()).toBe("/nodes/2");
    await managed.click({ button: "right" });
    await expect.element(page.getByRole("menuitem")).not.toBeInTheDocument();

    await row("Firewall", "https-public").click({ button: "right" });
    await expect.element(page.getByRole("menuitem", { name: "remove" })).toBeVisible();
});

it("enables analytics tracking from the menu, shows what to do next, then disables it after a confirmation", async () => {
    const app = await openApp("/instances/1");
    await expect.element(pane("Application log")).toBeVisible();
    await expect.element(pane("Properties")).not.toHaveTextContent("Analytics");

    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "enable analytics", exact: true }).click();

    const modal = page.getByRole("dialog");
    await expect
        .element(modal)
        .toHaveTextContent("DNS CNAME analytics.dev.charlie-shop.test -> dev.charlie-shop.test");
    await expect
        .element(modal)
        .toHaveTextContent('src="https://analytics.dev.charlie-shop.test/js/script.js"');
    expect(
        app.gateway.requests.some(
            (request) =>
                request.method === "POST" && request.path === "/api/v1/instances/1/analytics",
        ),
    ).toBe(true);

    await userEvent.keyboard("{Escape}");
    await expect
        .element(pane("Properties"))
        .toHaveTextContent("Analyticsanalytics.dev.charlie-shop.test");
    await expect.element(pane("Properties")).toHaveTextContent("Dashboardanalytics.orbit");
    await expect.element(pane("Analytics")).toHaveTextContent("Live visitors");
    await expect.element(pane("Analytics")).toHaveTextContent("2");
    await expect.element(pane("Top pages")).toHaveTextContent("/");
    await expect.element(pane("Top pages")).toHaveTextContent("120");

    // Enabled now, so the menu offers the opposite, and asks before it stops the tracking.
    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "disable analytics", exact: true }).click();
    await expect
        .element(pane("charlie-shop/dev"))
        .toHaveTextContent("Confirm? Remove every analytics tracking host");
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);

    await userEvent.keyboard("{Enter}");
    await expect
        .element(footer())
        .toHaveTextContent("Analytics tracking disabled for [charlie-shop/dev].");
    await expect.element(pane("Properties")).not.toHaveTextContent("Analytics");
    await expect.element(pane("Analytics")).not.toBeInTheDocument();
});

it("shows a warn analytics panel when stats cannot be read, not zeros", async () => {
    await openApp("/instances/2");
    await expect.element(pane("Application log")).toBeVisible();
    await expect.element(pane("Analytics")).not.toBeInTheDocument();

    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "enable analytics", exact: true }).click();
    await userEvent.keyboard("{Escape}");

    const analytics = pane("Analytics");
    await expect.element(analytics).toHaveAttribute("data-state", "warn");
    await expect.element(analytics).toHaveTextContent("No Plausible Stats API key is stored");
    await expect.element(analytics).not.toHaveTextContent("Live visitors");
    await expect.element(pane("Top pages")).not.toBeInTheDocument();
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

    await userEvent.keyboard("x{ArrowDown}{Enter}");

    await expect.element(footer()).toHaveTextContent("orbit node:ssh beast");
});

it("applies a realtime event to the open screen", async () => {
    await openApp("/");
    await expect.element(row("Needs attention", "vite")).toBeVisible();
    await expect
        .element(page.getByRole("status"))
        .toHaveAccessibleName(/Not connected to the WebSocket\. Lists refresh every 30 seconds/);

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
        .toHaveAccessibleName(
            "Not connected to the WebSocket. Lists refresh every 30 seconds, then less often, up to every 5 minutes, and not while the tab is hidden.",
        );

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

it("says live updates are paused while realtime is down, and refreshes on a click", async () => {
    const app = await openApp("/");
    const paused = footer().getByRole("button", { name: "live updates paused" });
    await expect.element(paused).toHaveTextContent("live updates paused");

    const before = app.gateway.requests.filter(
        (request) => request.path === "/api/v1/nodes",
    ).length;
    await paused.click();
    await vi.waitFor(() =>
        expect(
            app.gateway.requests.filter((request) => request.path === "/api/v1/nodes").length,
        ).toBeGreaterThan(before),
    );

    setLiveness("live");
    await expect.element(paused).not.toBeInTheDocument();
    setLiveness("polling");
});
