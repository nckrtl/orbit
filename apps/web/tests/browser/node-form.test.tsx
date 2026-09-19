import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { footer, openApp, pane } from "./app";

const field = (label: string) =>
    page
        .getByText(label, { exact: true })
        .element()
        .parentElement?.querySelector("input") as HTMLInputElement;

it("opens from the Nodes list with c and refuses an empty form", async () => {
    const app = await openApp("/nodes");
    await expect.element(pane("Nodes")).toBeVisible();

    await userEvent.keyboard("c");
    await expect.poll(app.url).toBe("/nodes/create");

    await page.getByRole("button", { name: "Create node" }).click();
    await expect.element(pane("node:add")).toHaveTextContent("⚠ Required.");
    expect(app.gateway.requests.some((request) => request.method === "POST")).toBe(false);
});

it("applies the node:add rules to each field", async () => {
    await openApp("/nodes/create");
    await userEvent.fill(field("Node name"), "Not Valid");
    await userEvent.fill(field("SSH port"), "70000");
    await page.getByRole("button", { name: "Create node" }).click();

    await expect
        .element(pane("node:add"))
        .toHaveTextContent("Use lowercase letters, digits, and dashes.");
    await expect.element(pane("node:add")).toHaveTextContent("A port is a number from 1 to 65535.");
});

it("shows the Gateway's refusal, then creates the node and opens it", async () => {
    const app = await openApp("/nodes/create");
    await userEvent.fill(field("Node name"), "spare");
    await page.getByRole("button", { name: "Create node" }).click();
    await expect.element(pane("node:add")).toHaveTextContent("An app-dev TLD is required");

    await userEvent.fill(field("TLD for its domains"), "spare.test");
    await page.getByRole("button", { name: "Create node" }).click();

    await expect.poll(app.url).toBe("/nodes/4");
    await expect.element(footer()).toHaveTextContent("Node [spare] is active.");
    expect(app.gateway.requests.findLast((request) => request.method === "POST")?.body).toEqual({
        name: "spare",
        roles: ["app-dev"],
        public_ssh_port: 22,
        user: "root",
        tld: "spare.test",
    });
});

it("leaves the form with Esc", async () => {
    const app = await openApp("/nodes");
    await expect.element(pane("Nodes")).toBeVisible();
    await userEvent.keyboard("c");
    await expect.poll(app.url).toBe("/nodes/create");

    await userEvent.keyboard("{Escape}");
    await expect.poll(app.url).toBe("/nodes");
});
