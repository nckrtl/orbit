import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { ANNOTATION_HOST_ID, ANNOTATION_ROOT_ID } from "../../src/annotation/host";
import { openApp, row } from "./app";

it("mounts the annotation overlay in the light DOM so Tailwind utilities apply", async () => {
    await openApp("/processes");

    const toggle = page.getByRole("button", { name: "Enter annotation mode" });
    await expect.element(toggle).toBeVisible();
    await toggle.click();

    await expect
        .poll(() => document.documentElement.classList.contains("laravel-toolbar-annotating"))
        .toBe(true);

    const host = document.getElementById(ANNOTATION_HOST_ID);
    expect(host).not.toBeNull();
    expect(host!.shadowRoot).toBeNull();
    expect(host!.getAttribute("data-annotation-host")).toBe("true");
    expect(host!.style.pointerEvents).toBe("none");

    const root = document.getElementById(ANNOTATION_ROOT_ID);
    expect(root).not.toBeNull();
    expect(host!.contains(root)).toBe(true);

    const target = row("Processes", "vite");
    await expect.element(target).toBeVisible();
    await target.hover();

    await expect
        .poll(() => {
            const highlight = document.querySelector("[data-annotation-highlight]");
            const rect = highlight?.getBoundingClientRect();
            if (!highlight || !rect) {
                return false;
            }
            return (
                rect.width > 0 &&
                rect.height > 0 &&
                getComputedStyle(highlight).position === "absolute"
            );
        })
        .toBe(true);
});
