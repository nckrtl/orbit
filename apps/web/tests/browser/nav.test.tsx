import { afterEach, expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { queryClient } from "../../src/api/queryClient";
import { openApp } from "./app";

afterEach(async () => {
    await page.viewport(1280, 800);
});

/** The desktop nav stays in the page on a phone, inside `hidden md:block`. */
function desktopNav(): HTMLElement {
    const nav = [...document.querySelectorAll("div")].find(
        (element) =>
            element.classList.contains("hidden") &&
            element.classList.contains("md:block") &&
            element.querySelector("[aria-label=Nav]") !== null,
    );
    if (!(nav instanceof HTMLElement)) {
        throw new Error("desktop nav missing");
    }

    return nav;
}

it("hides disabled extensions from both desktop and phone navigation", async () => {
    await page.viewport(390, 800);
    const app = await openApp("/", { tasks: false });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: false,
            proxycli: false,
        });
    expect(desktopNav().textContent).not.toContain("Tasks");
    await page.getByTestId("nav-menu").click();
    const menu = document.querySelector("[data-mobile-menu]");
    expect(menu?.textContent).not.toContain("Tasks");
    expect(menu?.textContent).not.toContain("Quota");
    await page.getByTestId("nav-close").click();
    await expect.poll(app.url).toBe("/");
});

it("follows one mapped nav selector from the phone menu", async () => {
    await page.viewport(390, 800);
    const app = await openApp("/");
    await page.getByTestId("nav-menu").click();

    // The desktop nav stays mounted and hidden. The mapped id is only on the open phone menu.
    expect(desktopNav().textContent).toContain("Tasks");
    expect(desktopNav().querySelector("[data-testid=nav-tasks]")).toBeNull();
    expect(document.querySelectorAll("[data-testid=nav-tasks]")).toHaveLength(1);
    await page.getByTestId("nav-tasks").click();
    await expect.poll(app.url).toBe("/tasks");
});

it("follows one mapped nav selector on the desktop nav", async () => {
    const app = await openApp("/");
    expect(document.querySelectorAll("[data-testid=nav-tasks]")).toHaveLength(1);
    await page.getByTestId("nav-tasks").click();
    await expect.poll(app.url).toBe("/tasks");
});
