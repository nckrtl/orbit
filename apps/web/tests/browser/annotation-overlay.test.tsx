import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { ANNOTATION_HOST_ID, ANNOTATION_ROOT_ID } from "../../src/annotation/host";
import { openApp, pane, row } from "./app";

function placeAnnotationAt(x: number, y: number): void {
    const target = document.elementFromPoint(x, y);
    for (const type of ["mousedown", "mouseup", "click"] as const) {
        target?.dispatchEvent(
            new MouseEvent(type, {
                bubbles: true,
                cancelable: true,
                clientX: x,
                clientY: y,
                button: 0,
                view: window,
            }),
        );
    }
}

it("mounts annotation in open Shadow DOM with sized controls", async () => {
    await openApp("/processes");

    const toggle = page.getByRole("button", { name: "Enter annotation mode" });
    await expect.element(toggle).toBeVisible();
    await toggle.click();

    await expect
        .poll(() => document.documentElement.classList.contains("laravel-toolbar-annotating"))
        .toBe(true);

    const host = document.getElementById(ANNOTATION_HOST_ID);
    expect(host).not.toBeNull();
    expect(host!.shadowRoot).not.toBeNull();
    expect(host!.getAttribute("data-annotation-host")).toBe("true");
    expect(host!.style.pointerEvents).toBe("none");

    const root = host!.shadowRoot!.getElementById(ANNOTATION_ROOT_ID);
    expect(root).not.toBeNull();
    expect(document.getElementById(ANNOTATION_ROOT_ID)).toBeNull();

    const target = row("Processes", "vite");
    await expect.element(target).toBeVisible();
    await target.hover();

    await expect
        .poll(() => {
            const highlight = host!.shadowRoot!.querySelector("[data-annotation-highlight]");
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

    // Place a draft on the Processes pane chrome (avoid navigable table rows).
    const processesPane = pane("Processes");
    await expect.element(processesPane).toBeVisible();
    const paneBox = processesPane.element().getBoundingClientRect();
    placeAnnotationAt(paneBox.left + 24, paneBox.top + 28);

    const shadow = host!.shadowRoot!;

    await expect.poll(() => shadow.querySelector("[data-annotation-popup]") !== null).toBe(true);

    await expect
        .poll(() => {
            const settings = shadow.querySelector("[data-annotation-settings]");
            const mic = shadow.querySelector(
                'button[aria-label="Dictate comment"], button[aria-label="Stop dictation"], button[aria-label="Transcribing"]',
            );
            if (!settings) {
                return false;
            }
            const s = settings.getBoundingClientRect();
            const m = mic?.getBoundingClientRect();
            return s.width >= 26 && s.height >= 26 && (!m || (m.width >= 26 && m.height >= 26));
        })
        .toBe(true);

    const field = page.getByPlaceholder("What should change?");
    await expect.element(field).toBeVisible();
    await field.fill("shadow control sizing");

    await expect
        .poll(() => {
            const submit = shadow.querySelector("[data-annotation-submit]");
            if (!submit) {
                return false;
            }
            const rect = submit.getBoundingClientRect();
            return rect.width >= 26 && rect.height >= 26 && !(submit as HTMLButtonElement).disabled;
        })
        .toBe(true);

    const settings = shadow.querySelector("[data-annotation-settings]") as HTMLButtonElement | null;
    expect(settings).not.toBeNull();
    settings!.click();

    await expect.poll(() => shadow.querySelector("[data-annotation-metadata]") !== null).toBe(true);
});
