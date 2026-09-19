import { expect, it } from "vite-plus/test";
import { userEvent } from "vite-plus/test/browser";
import { footer, openApp, pane, row } from "./app";

it("jumps to a section with its digit and walks the sidebar with the arrows", async () => {
    const app = await openApp("/");
    await expect.element(row("Worker nodes", "beast")).toBeVisible();

    await userEvent.keyboard("2");
    await expect.poll(app.url).toBe("/nodes");

    await userEvent.keyboard("{ArrowDown}");
    await expect.poll(app.url).toBe("/apps");

    await userEvent.keyboard("{ArrowUp}{ArrowUp}");
    await expect.poll(app.url).toBe("/");
});

it("hovers a pane, focuses it, moves the selection, and opens the row", async () => {
    const app = await openApp("/nodes");
    await expect.element(row("Worker nodes", "beast")).toBeVisible();

    await userEvent.keyboard("{ArrowRight}");
    await expect.element(pane("Worker nodes")).toHaveAttribute("data-state", "hovered");

    await userEvent.keyboard("{Enter}");
    await expect.element(pane("Worker nodes")).toHaveAttribute("data-state", "focused");
    await expect.element(footer()).toHaveTextContent("Enter or click opens");

    await userEvent.keyboard("{ArrowDown}");
    await expect.element(row("Worker nodes", "beast")).toHaveAttribute("aria-selected", "true");

    await userEvent.keyboard("{Enter}");
    await expect.poll(app.url).toBe("/nodes/2");
    await expect.element(pane("Instances on this node")).toBeVisible();
});

it("moves between the dashboard panes by where they are on the screen", async () => {
    await openApp("/");
    await expect.element(row("Worker nodes", "beast")).toBeVisible();

    await userEvent.keyboard("{ArrowRight}");
    await expect.element(pane("Worker nodes")).toHaveAttribute("data-state", "hovered");

    await userEvent.keyboard("{ArrowDown}");
    await expect.element(pane("Apps")).toHaveAttribute("data-state", "hovered");

    await userEvent.keyboard("{ArrowRight}");
    await expect.element(pane("Instances")).toHaveAttribute("data-state", "hovered");

    await userEvent.keyboard("{ArrowDown}");
    await expect.element(pane("Schedules")).toHaveAttribute("data-state", "hovered");

    await userEvent.keyboard("{ArrowLeft}");
    await expect.element(pane("Processes")).toHaveAttribute("data-state", "hovered");

    await userEvent.keyboard("{ArrowUp}{ArrowUp}");
    await expect.element(pane("Worker nodes")).toHaveAttribute("data-state", "hovered");

    await userEvent.keyboard("{ArrowLeft}");
    await expect.element(pane("Worker nodes")).not.toHaveAttribute("data-state", "hovered");
});

it("goes back with Esc and finds the row it left selected", async () => {
    const app = await openApp("/nodes");
    await expect.element(row("Worker nodes", "beast")).toBeVisible();
    await userEvent.keyboard("{ArrowRight}{Enter}{ArrowDown}{Enter}");
    await expect.poll(app.url).toBe("/nodes/2");

    await userEvent.keyboard("{Escape}");
    await expect.poll(app.url).toBe("/nodes");
    await expect.element(row("Worker nodes", "beast")).toHaveAttribute("aria-selected", "true");
});

it("cycles the node filter with n and keeps it in the URL", async () => {
    const app = await openApp("/processes");
    await expect.element(row("Processes", "valkey")).toBeVisible();

    await userEvent.keyboard("n");
    await expect.poll(app.url).toBe("/processes?node=gateway");
    await expect.element(pane("Processes")).toHaveTextContent("None.");

    await userEvent.keyboard("n");
    await expect.poll(app.url).toBe("/processes?node=beast");
    await expect.element(row("Processes", "valkey")).toBeVisible();
});

it("opens a record with one click and keeps the row selected for the way back", async () => {
    const app = await openApp("/apps");

    await row("Apps", "charlie-shop").click();
    await expect.poll(app.url).toBe("/apps/3");

    await userEvent.keyboard("{Escape}");
    await expect.poll(app.url).toBe("/apps");
    await expect.element(row("Apps", "charlie-shop")).toHaveAttribute("aria-selected", "true");
});

it("only selects a row that leads nowhere", async () => {
    const app = await openApp("/databases/1");

    await row("Users", "app").click();
    await expect.element(row("Users", "app")).toHaveAttribute("aria-selected", "true");
    expect(app.url()).toBe("/databases/1");
});

it("sorts a pane by a column header", async () => {
    await openApp("/apps");
    await expect.element(row("Apps", "charlie-shop")).toBeVisible();

    await pane("Apps").getByRole("columnheader", { name: "Slug" }).click();
    await pane("Apps").getByRole("columnheader", { name: "Slug" }).click();

    await expect.element(pane("Apps").getByRole("row").nth(1)).toHaveTextContent("charlie-shop");
});
