import {
    createMemoryHistory,
    createRootRoute,
    createRouter,
    RouterProvider,
} from "@tanstack/react-router";
import { beforeEach, expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { render } from "vitest-browser-react";
import type { Node } from "../../src/api/types";
import { Pane } from "../../src/ui/Pane";
import { panes, selectionKey, ui } from "../../src/ui/store";

type Item = { id: number; name: string; below: boolean };
const items: Item[] = [
    { id: 1, name: "zulu", below: true },
    { id: 2, name: "charlie", below: false },
    { id: 3, name: "alpha", below: true },
    { id: 4, name: "bravo", below: false },
];
const names = () =>
    [...document.querySelectorAll('[role="row"]:not([data-head])')].map((row) => row.textContent);

beforeEach(() => ui.reset());

async function draw(rows: Item[], divided = true) {
    const root = createRootRoute({
        component: () => (
            <Pane
                name="partition"
                title="Partition"
                order={1}
                columns={[{ header: "Name", width: 1, value: (item: Item) => item.name }]}
                rows={rows}
                rowId={(item) => String(item.id)}
                target={(item) => ({ kind: "nodes", row: item as unknown as Node })}
                divide={divided ? { label: "Below", below: (item) => item.below } : undefined}
            />
        ),
    });
    const router = createRouter({
        routeTree: root,
        history: createMemoryHistory({ initialEntries: ["/"] }),
    });
    await render(<RouterProvider router={router} />);
}

it("partitions once in model order and keeps sorting, selection and targets within each group", async () => {
    await draw(items);
    await expect.poll(names).toEqual(["charlie", "bravo", "zulu", "alpha"]);
    ui.set({ focus: "partition" });
    ui.select(selectionKey("/", "partition"), 2);

    await page.getByRole("columnheader", { name: "Name" }).click();

    await expect.poll(names).toEqual(["bravo", "charlie", "alpha", "zulu"]);
    const divider = document.querySelector(".divider")!;
    expect(divider.previousElementSibling?.textContent).toBe("charlie");
    expect(divider.nextElementSibling?.textContent).toBe("alpha");
    await expect
        .element(page.getByRole("row", { name: "alpha", exact: true }))
        .toHaveAttribute("aria-selected", "true");
    expect(panes.get("partition")?.target(2)?.row.id).toBe(3);
    expect(panes.get("partition")?.count).toBe(4);

    await page.getByRole("columnheader", { name: /Name/ }).click();

    await expect.poll(names).toEqual(["charlie", "bravo", "zulu", "alpha"]);
    expect(panes.get("partition")?.target(2)?.row.id).toBe(1);
    await expect
        .element(page.getByRole("row", { name: "zulu", exact: true }))
        .toHaveAttribute("aria-selected", "true");
});

it("uses the sorted model directly when no divider is requested", async () => {
    await draw(items, false);
    await expect.poll(names).toEqual(["zulu", "charlie", "alpha", "bravo"]);

    await page.getByRole("columnheader", { name: "Name" }).click();

    await expect.poll(names).toEqual(["alpha", "bravo", "charlie", "zulu"]);
    expect(document.querySelector(".divider")).toBeNull();
    expect(panes.get("partition")?.target(0)?.row.id).toBe(3);
});

it.each([true, false])(
    "keeps all rows without a divider when below is always %s",
    async (below) => {
        await draw(items.map((item) => ({ ...item, below })));
        await page.getByRole("columnheader", { name: "Name" }).click();

        await expect.poll(names).toEqual(["alpha", "bravo", "charlie", "zulu"]);
        expect(document.querySelector(".divider")).toBeNull();
        expect(panes.get("partition")?.count).toBe(4);
    },
);

it("renders an empty divided pane with no divider or selected target", async () => {
    await draw([]);

    await expect
        .element(page.getByRole("region", { name: "Partition" }))
        .toHaveTextContent("None.");
    expect(document.querySelector(".divider")).toBeNull();
    expect(panes.get("partition")?.count).toBe(0);
    expect(panes.get("partition")?.target(0)).toBeNull();
});
