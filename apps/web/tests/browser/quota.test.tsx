import { expect, it, vi } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { queryClient } from "../../src/api/queryClient";
import { proxycliStatusQuery } from "../../src/api/queries";
import { openApp, pane, row } from "./app";
import { screenText } from "./screen";

it("keeps Quota out of the sidebar while proxycli is disabled", async () => {
    await openApp("/");

    await expect.poll(() => screenText()).toContain("Dashboard");
    expect(screenText()).not.toContain("Quota");
});

it.each([false, true])(
    "matches mobile primary navigation to desktop when proxycli enabled is %s",
    async (enabled) => {
        const app = await openApp("/", { proxycli: enabled });
        await expect
            .poll(() => queryClient.getQueryData(proxycliStatusQuery.queryKey)?.enabled)
            .toBe(enabled);
        const desktop = [
            ...pane("Navigation").element().querySelectorAll(".nav-row > span:first-child"),
        ].map((item) => item.textContent);
        expect(desktop).toEqual([
            "Dashboard",
            "Nodes",
            "Projects",
            "Databases",
            "Tasks",
            ...(enabled ? ["Quota"] : []),
        ]);

        await page.viewport(390, 844);
        try {
            await page.getByRole("button", { name: "Toggle navigation menu" }).click();
            const drawer = pane("Navigation");
            await expect.element(drawer).toHaveTextContent("Other Sections");
            const mobile = [
                ...drawer.element().querySelectorAll("[data-link] > span:first-child"),
            ].map((item) => item.textContent);

            expect(mobile.slice(0, desktop.length)).toEqual(desktop);
            expect(mobile.slice(desktop.length)).toEqual([
                "Instances",
                "Processes",
                "Schedules",
                "Firewall",
            ]);
            expect(mobile.filter((label) => label === "Quota")).toHaveLength(enabled ? 1 : 0);
            await drawer.getByText("Processes", { exact: true }).click();
            await expect.poll(app.url).toBe("/processes");
            await expect
                .element(page.getByText("Other Sections", { exact: true }))
                .not.toBeInTheDocument();
        } finally {
            await page.viewport(1280, 800);
        }
    },
);

it("keeps direct mobile Quota navigation disabled while hiding its drawer link", async () => {
    await page.viewport(390, 844);
    try {
        await openApp("/quota");
        await expect
            .poll(() => screenText())
            .toContain("require both the CLIProxyAPI collector and the tasks extension");
        await page.getByRole("button", { name: "Toggle navigation menu" }).click();

        await expect
            .element(pane("Navigation").getByText("Quota", { exact: true }))
            .not.toBeInTheDocument();
    } finally {
        await page.viewport(1280, 800);
    }
});

it("shows an explicit disabled state when tasks are disabled", async () => {
    await openApp("/quota", { proxycli: true, tasks: false });

    await expect
        .poll(() => screenText())
        .toContain("require both the CLIProxyAPI collector and the tasks extension");
});

it("lists provider windows from the snapshot without Primary or Secondary labels", async () => {
    await openApp("/quota", { proxycli: true });

    await expect.element(row("Quota", "Codex")).toBeVisible();
    await expect.element(row("Quota", "7d 60%")).toBeVisible();
    await expect.element(row("Quota", "5h 90%")).toBeVisible();
    await expect.element(row("Quota", "Not reported by Gateway")).toBeVisible();
    expect(screenText()).not.toContain("Primary");
    expect(screenText()).not.toContain("Secondary");
    expect(screenText()).toContain("Cache updated");
    const meters = document.querySelectorAll('[role="meter"]');
    expect(meters.length).toBeGreaterThan(0);
    for (const meter of meters) {
        const bounds = meter.getBoundingClientRect();
        const rowBounds = meter.closest('[role="row"]')!.getBoundingClientRect();
        expect(bounds.bottom).toBeLessThanOrEqual(rowBounds.bottom);
    }
});

it("shows account controls and never renders a missing window as zero", async () => {
    await openApp("/quota/codex", { proxycli: true });

    await expect.element(pane("Accounts")).toBeVisible();
    await expect.element(row("Accounts", "plus.json")).toHaveTextContent("7d 60%");
    expect(screenText()).not.toContain(" 0%");

    await expect.element(row("Accounts", "disable")).toBeVisible();
});

it("positions a red even-pace marker and omits it without a reset", async () => {
    const clock = vi.spyOn(Date, "now").mockReturnValue(Date.parse("2026-09-23T12:00:00Z"));
    try {
        await openApp("/quota", { proxycli: true });
        await expect.element(row("Quota", "7d 60%")).toBeVisible();
        const weekly = document.querySelector('[role="meter"][aria-label="7d remaining"]')!;
        const marker = weekly.querySelector<HTMLElement>("[data-quota-pace]")!;
        expect(marker.style.left).toBe("50%");
        expect(marker.getBoundingClientRect().width).toBeGreaterThan(0);
        expect(weekly.getAttribute("aria-valuetext")).toContain("On pace to last until reset");
        const short = document.querySelector('[role="meter"][aria-label="5h remaining"]')!;
        expect(short.querySelector("[data-quota-pace]")).toBeNull();
    } finally {
        clock.mockRestore();
    }
});
