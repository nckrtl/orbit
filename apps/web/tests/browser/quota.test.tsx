import { expect, it } from "vite-plus/test";
import { openApp, pane, row } from "./app";
import { screenText } from "./screen";

it("keeps Quota out of the sidebar while proxycli is disabled", async () => {
    await openApp("/");

    await expect.poll(() => screenText()).toContain("Dashboard");
    expect(screenText()).not.toContain("Quota");
});

it("lists provider windows from the snapshot without Primary or Secondary labels", async () => {
    await openApp("/quota", { proxycli: true });

    await expect.element(row("Quota", "codex")).toBeVisible();
    await expect.element(row("Quota", "7d 60%")).toBeVisible();
    await expect.element(row("Quota", "5h 90%")).toBeVisible();
    expect(screenText()).not.toContain("Primary");
    expect(screenText()).not.toContain("Secondary");
});

it("shows account controls and never renders a missing window as zero", async () => {
    await openApp("/quota/codex", { proxycli: true });

    await expect.element(pane("Accounts")).toBeVisible();
    await expect.element(row("Accounts", "plus.json")).toHaveTextContent("7d 60%");
    expect(screenText()).not.toContain(" 0%");

    await expect.element(row("Accounts", "disable")).toBeVisible();
});
