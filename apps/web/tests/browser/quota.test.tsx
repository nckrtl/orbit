import { expect, it, vi } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { queryClient } from "../../src/api/queryClient";
import { openApp, pane, row } from "./app";
import { screenText } from "./screen";

it("keeps Quota out of the sidebar while proxycli is disabled", async () => {
    await openApp("/");

    await expect.poll(() => screenText()).toContain("Dashboard");
    expect(screenText()).not.toContain("Quota");
});

it("shows provider quota when proxycli is configured and tasks are disabled", async () => {
    await openApp("/quota", { proxycli: true, tasks: false });

    await expect.element(row("Quota", "Codex")).toBeVisible();
    await expect.element(row("Quota", "7d 60%")).toBeVisible();
    expect(screenText()).not.toContain(
        "require both the CLIProxyAPI collector and the tasks extension",
    );
    expect(document.querySelector('[data-testid="quota-unavailable"]')).toBeNull();
    const headers = [...document.querySelectorAll('[role="columnheader"]')].map((header) =>
        (header.textContent ?? "").replace(/\s+/g, " ").trim(),
    );
    expect(headers).not.toContain("Token spend");
    expect(screenText()).not.toContain("Token spend");
});

it("shows Quota as unconfigured while the proxycli extension is enabled without collector setup", async () => {
    const app = await openApp("/quota", { proxycliExtension: true });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: true,
            proxycli: true,
        });

    await expect.element(page.getByTestId("nav-quota")).toBeVisible();
    await expect
        .element(page.getByTestId("quota-unavailable"))
        .toHaveTextContent("CLIProxyAPI collector is not configured");
    expect(
        app.gateway.requests.some((request) => request.path === "/api/v1/proxycli/providers"),
    ).toBe(false);
});

it("keeps Quota visible after the collector is torn down until the extension is disabled", async () => {
    const app = await openApp("/quota", { proxycli: true });
    await expect.element(row("Quota", "Codex")).toBeVisible();
    app.gateway.teardownProxyCli();
    await queryClient.invalidateQueries({ queryKey: ["proxycli-status"] });

    await expect.element(page.getByTestId("nav-quota")).toBeVisible();
    await expect
        .element(page.getByTestId("quota-unavailable"))
        .toHaveTextContent("CLIProxyAPI collector is not configured");

    app.gateway.disableProxyCliExtension();
    await queryClient.invalidateQueries({ queryKey: ["extensions"] });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: true,
            proxycli: false,
        });
    await expect.poll(() => document.querySelector('[data-testid="nav-quota"]')).toBeNull();
});

it("lists provider windows from the snapshot without Primary or Secondary labels", async () => {
    await openApp("/quota", { proxycli: true });

    await expect.element(row("Quota", "Codex")).toBeVisible();
    await expect.element(row("Quota", "7d 60%")).toBeVisible();
    await expect.element(row("Quota", "5h 90%")).toBeVisible();
    expect(screenText()).not.toContain("Not reported by Gateway");
    expect(screenText()).not.toContain("Token spend");
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

it("shows successful and failed account toggle outcomes", async () => {
    await openApp("/quota/codex", { proxycli: true });
    const control = row("Accounts", "disable").getByText("disable", { exact: true });
    await control.click();
    await expect.element(row("Accounts", "enable")).toBeVisible();

    await openApp("/quota/codex", {
        proxycli: true,
        wrapTransport: (inner) => async (method, path, body) => {
            if (method === "PATCH" && path.includes("/proxycli/accounts/")) {
                throw new Error("Account toggle failed");
            }
            return inner(method, path, body);
        },
    });
    await page.getByText("disable", { exact: true }).click();
    await expect.element(page.getByRole("alert")).toHaveTextContent("Account toggle failed");
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
