import { expect, it } from "vite-plus/test";
import { openApp, pane, row } from "./app";
import { screenText } from "./screen";

// Each screen against the fixture fleet, as text. Review a change in `expected/`, then accept it
// with `bun run test:browser -- -u`.

it("draws the dashboard", async () => {
    const app = await openApp("/");
    await expect.element(row("Needs attention", /Node\s*app-prod/)).toBeVisible();
    await expect.element(row("Worker nodes", "beast")).toHaveTextContent("1440G/1760G");

    await expect(screenText()).toMatchFileSnapshot("./expected/dashboard.txt");
    // The Gateway names the Nodes and hands out the Grafana credential once; no metrics request goes out per Node.
    expect(
        app.gateway.requests
            .map((request) => request.path)
            .filter((path) => path.includes("metrics")),
    ).toEqual(["/api/v1/metrics/credentials"]);
});

it("draws a section list with its filters", async () => {
    await openApp("/processes?node=beast");
    await expect.element(row("Processes", "valkey")).toBeVisible();

    await expect(screenText()).toMatchFileSnapshot("./expected/processes-on-beast.txt");
});

it("draws a node record", async () => {
    await openApp("/nodes/2");
    await expect.element(row("Firewall", "443/tcp")).toBeVisible();
    await expect.element(pane("beast · active · metrics")).toBeVisible();

    await expect(screenText()).toMatchFileSnapshot("./expected/node-beast.txt");
});

it("draws an instance record with its deployments", async () => {
    await openApp("/instances/1");
    await expect.element(row("Deployments", "20260102000000")).toBeVisible();
    await expect
        .element(pane("Application log · storage/logs/laravel.log"))
        .toHaveTextContent("Order 1042 paid.");

    await expect(screenText()).toMatchFileSnapshot("./expected/instance-charlie-shop-dev.txt");
});

it("says so when a node has no metrics", async () => {
    await openApp("/nodes/3");

    await expect.element(pane("app-prod · failed")).toHaveTextContent("No metrics.");
});
