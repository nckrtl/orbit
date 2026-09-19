import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { ANNOTATION_HOST_ID, ANNOTATION_ROOT_ID } from "../../src/annotation/host";
import { openApp } from "./app";

function annotationShadow(): ShadowRoot {
    const host = document.getElementById(ANNOTATION_HOST_ID);
    if (!host?.shadowRoot) {
        throw new Error("annotation shadow host missing");
    }
    return host.shadowRoot;
}

function floatingToggle() {
    return page.getByRole("button", { name: "Enter annotation mode" }).first();
}

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

it("shows a floating annotate control on load without hunting the footer", async () => {
    await openApp("/processes");

    await expect
        .poll(() => document.getElementById(ANNOTATION_HOST_ID)?.shadowRoot != null)
        .toBe(true);

    const fab = annotationShadow().querySelector("[data-orbit-annotation-fab]");
    expect(fab).not.toBeNull();
    await expect.element(floatingToggle()).toBeVisible();

    const errors: string[] = [];
    const onError = (event: ErrorEvent) => {
        errors.push(event.message);
    };
    window.addEventListener("error", onError);
    // Give StrictMode a beat; lifecycle must not tear down the overlay root.
    await expect
        .poll(() => annotationShadow().querySelector("[data-orbit-annotation-fab]") != null)
        .toBe(true);
    window.removeEventListener("error", onError);
    expect(errors.some((message) => message.includes("synchronously unmount"))).toBe(false);
});

it("mounts annotation in open Shadow DOM with sized controls and blocks row navigation", async () => {
    await openApp("/processes");

    const pathBefore = window.location.pathname;
    await expect.element(floatingToggle()).toBeVisible();
    await floatingToggle().click();

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

    // Sidebar / table rows navigate on mousedown — annotation mode must consume the gesture.
    const navRow = page.getByText("Dashboard", { exact: true }).first();
    await expect.element(navRow).toBeVisible();
    const navBox = navRow.element().getBoundingClientRect();
    placeAnnotationAt(navBox.left + navBox.width / 2, navBox.top + navBox.height / 2);

    expect(window.location.pathname).toBe(pathBefore);

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
