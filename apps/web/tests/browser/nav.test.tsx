import { afterEach, expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
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
