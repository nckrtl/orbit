import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { ANNOTATION_HOST_ID } from "@nckrtl/annotate/host";
import indexHtml from "../../index.html?raw";
import { displayMode } from "../../src/ui/viewportReadout";
import { openApp } from "./app";

const EDGES = ["top", "right", "bottom", "left"] as const;

type Edge = (typeof EDGES)[number];

function shell(): HTMLElement {
    const element = document.querySelector("[data-app-shell]");
    if (!(element instanceof HTMLElement)) {
        throw new Error("app shell missing");
    }

    return element;
}

function layout(): HTMLElement {
    const element = shell().querySelector("[data-shell-layout]");
    if (!(element instanceof HTMLElement)) {
        throw new Error("shell layout missing");
    }

    return element;
}

function padding(element: HTMLElement): Record<Edge, string> {
    const style = getComputedStyle(element);

    return {
        top: style.paddingTop,
        right: style.paddingRight,
        bottom: style.paddingBottom,
        left: style.paddingLeft,
    };
}

function setInsets(insets: Partial<Record<Edge, string>>): void {
    for (const edge of EDGES) {
        const value = insets[edge];
        if (value === undefined) {
            document.documentElement.style.removeProperty(`--safe-area-inset-${edge}`);
        } else {
            document.documentElement.style.setProperty(`--safe-area-inset-${edge}`, value);
        }
    }
}

function clearInsets(): void {
    setInsets({});
}

/** html, body, and #app are the web view. Anything taller, or any pad of their own, is a second inset. */
function expectWebViewFilled(): void {
    const height = window.innerHeight;
    for (const element of [
        document.documentElement,
        document.body,
        document.getElementById("app"),
    ]) {
        if (!(element instanceof HTMLElement)) {
            throw new Error("web view root missing");
        }
        const style = getComputedStyle(element);
        expect(style.marginTop).toBe("0px");
        expect(style.marginRight).toBe("0px");
        expect(style.marginBottom).toBe("0px");
        expect(style.marginLeft).toBe("0px");
        expect(style.paddingTop).toBe("0px");
        expect(style.paddingRight).toBe("0px");
        expect(style.paddingBottom).toBe("0px");
        expect(style.paddingLeft).toBe("0px");
        expect(Math.abs(element.getBoundingClientRect().height - height)).toBeLessThan(1);
    }
    expect(Math.abs(shell().getBoundingClientRect().height - height)).toBeLessThan(1);
    expect(Math.abs(shell().getBoundingClientRect().bottom - height)).toBeLessThan(1);
}

/** The footer sits on the shell's one bottom inset, plus the layout's own pad, and no further up. */
function expectSingleBottomPad(): void {
    const footer = document.querySelector("footer");
    if (!(footer instanceof HTMLElement)) {
        throw new Error("footer missing");
    }
    const shellPad = Number.parseFloat(getComputedStyle(shell()).paddingBottom);
    const layoutPad = Number.parseFloat(getComputedStyle(layout()).paddingBottom);
    const bottom = footer.getBoundingClientRect().bottom;
    expect(Math.abs(bottom - (window.innerHeight - shellPad - layoutPad))).toBeLessThan(1.5);
}

function box(element: HTMLElement): { top: number; right: number; bottom: number; left: number } {
    const rect = element.getBoundingClientRect();
    const style = getComputedStyle(element);

    return {
        top: rect.top + Number.parseFloat(style.paddingTop),
        right: rect.right - Number.parseFloat(style.paddingRight),
        bottom: rect.bottom - Number.parseFloat(style.paddingBottom),
        left: rect.left + Number.parseFloat(style.paddingLeft),
    };
}

function expectInside(
    element: HTMLElement,
    bounds: { top: number; right: number; bottom: number; left: number },
): void {
    const rect = element.getBoundingClientRect();
    expect(rect.width).toBeGreaterThan(0);
    expect(rect.height).toBeGreaterThan(0);
    expect(rect.top).toBeGreaterThanOrEqual(bounds.top - 0.5);
    expect(rect.left).toBeGreaterThanOrEqual(bounds.left - 0.5);
    expect(rect.right).toBeLessThanOrEqual(bounds.right + 0.5);
    expect(rect.bottom).toBeLessThanOrEqual(bounds.bottom + 0.5);
}

function floatingControl(): HTMLElement {
    const fab = document
        .getElementById(ANNOTATION_HOST_ID)
        ?.shadowRoot?.querySelector("[data-orbit-annotation-fab]");
    if (!(fab instanceof HTMLElement)) {
        throw new Error("floating action missing");
    }

    return fab;
}

function expectReadoutShowsProbe(): void {
    const probe = document.querySelector("[data-safe-area-probe]");
    const readout = document.querySelector("[data-viewport-readout]");
    if (!(probe instanceof HTMLElement) || !(readout instanceof HTMLElement)) {
        throw new Error("viewport readout missing");
    }

    const style = getComputedStyle(probe);
    for (const edge of ["top", "right", "bottom", "left"] as const) {
        const override = getComputedStyle(document.documentElement)
            .getPropertyValue(`--safe-area-inset-${edge}`)
            .trim();
        if (override !== "") {
            expect(style.getPropertyValue(`padding-${edge}`)).toBe(override);
        }
    }
    const text = readout.textContent ?? "";
    expect(probe.style.paddingTop).toContain("env(safe-area-inset-top");
    expect(probe.style.paddingRight).toContain("env(safe-area-inset-right");
    expect(probe.style.paddingBottom).toContain("env(safe-area-inset-bottom");
    expect(probe.style.paddingLeft).toContain("env(safe-area-inset-left");
    expect(text).toBe(
        `${displayMode()} ${window.innerWidth}x${window.innerHeight} · ${window.screen.width}x${window.screen.height} · ${style.paddingTop} ${style.paddingRight} ${style.paddingBottom} ${style.paddingLeft}`,
    );
    const range = document.createRange();
    range.selectNodeContents(readout);
    const readoutStyle = getComputedStyle(readout);
    const contentWidth =
        readout.clientWidth -
        Number.parseFloat(readoutStyle.paddingLeft) -
        Number.parseFloat(readoutStyle.paddingRight);
    expect(range.getBoundingClientRect().width).toBeLessThanOrEqual(contentWidth + 1);
    expect(readout.getBoundingClientRect().height).toBeLessThanOrEqual(
        Number.parseFloat(readoutStyle.lineHeight) + 1,
    );
}

it("sets an opaque black status bar and keeps the viewport cover fit", () => {
    const document = new DOMParser().parseFromString(indexHtml, "text/html");
    expect(
        document
            .querySelector('meta[name="apple-mobile-web-app-status-bar-style"]')
            ?.getAttribute("content"),
    ).toBe("black");
    expect(document.querySelector('meta[name="viewport"]')?.getAttribute("content")).toContain(
        "viewport-fit=cover",
    );
    expect(document.querySelector('meta[name="theme-color"]')?.getAttribute("content")).toBe(
        "#0d0f12",
    );
    expect(indexHtml).not.toContain("black-translucent");
});

it("leaves a zero-inset browser tab on the existing shell padding", async () => {
    await openApp("/");
    await expect.element(page.getByRole("region", { name: "Nav" })).toBeVisible();

    const layoutPadding = padding(layout());
    expect(padding(shell())).toEqual({ top: "0px", right: "0px", bottom: "0px", left: "0px" });
    expect(layoutPadding.top).toBe("14px");
    expect(layoutPadding.bottom).toBe("4px");
    expect(layoutPadding.left).toBe(layoutPadding.right);
    expect(Number.parseFloat(layoutPadding.left)).toBeGreaterThan(0);
    expect(
        getComputedStyle(document.documentElement).getPropertyValue("--shell-safe-top").trim(),
    ).toBe("0px");
    expect(shell().style.paddingTop).toContain("env(safe-area-inset-top");
    expect(shell().style.paddingRight).toContain("env(safe-area-inset-right");
    expect(shell().style.paddingBottom).toContain("env(safe-area-inset-bottom");
    expect(shell().style.paddingLeft).toContain("env(safe-area-inset-left");

    setInsets({ top: "0px", right: "0px", bottom: "0px", left: "0px" });
    expect(padding(shell())).toEqual({ top: "0px", right: "0px", bottom: "0px", left: "0px" });
    expect(padding(layout())).toEqual(layoutPadding);
    expectWebViewFilled();
    expectSingleBottomPad();
    clearInsets();
});

it("pads the shell from the safe-area overrides and keeps the page inside them", async () => {
    try {
        await openApp("/");
        await expect
            .poll(() => floatingControl().getBoundingClientRect().height)
            .toBeGreaterThan(0);

        const layoutPadding = padding(layout());
        const mainPadding = getComputedStyle(
            document.querySelector("main") as HTMLElement,
        ).paddingTop;
        const fabAtRest = getComputedStyle(floatingControl()).bottom;

        setInsets({ top: "47px", right: "11px", bottom: "34px", left: "12px" });

        expect(padding(shell())).toEqual({
            top: "47px",
            right: "11px",
            bottom: "34px",
            left: "12px",
        });
        // The layout keeps its own padding. The inset is applied once, on the shell.
        expect(padding(layout())).toEqual(layoutPadding);
        expect(getComputedStyle(document.querySelector("main") as HTMLElement).paddingTop).toBe(
            mainPadding,
        );
        expect(getComputedStyle(floatingControl()).bottom).toBe("54px");
        expect(fabAtRest).toBe("20px");

        const nav = document.querySelector("[aria-label='Nav']");
        const footer = document.querySelector("footer");
        const main = document.querySelector("main");
        if (
            !(nav instanceof HTMLElement) ||
            !(footer instanceof HTMLElement) ||
            !(main instanceof HTMLElement)
        ) {
            throw new Error("shell content missing");
        }
        const safe = box(shell());
        expectInside(nav, safe);
        expectInside(footer, safe);
        expectInside(main, safe);

        // Portrait phone: header and the menu it opens sit below the status bar, footer above the home indicator.
        await page.viewport(390, 844);
        setInsets({ top: "47px", right: "0px", bottom: "34px", left: "0px" });
        await expect
            .element(page.getByRole("button", { name: "Toggle navigation menu" }))
            .toBeVisible();

        const header = document.querySelector("header");
        const portraitFooter = document.querySelector("footer");
        if (!(header instanceof HTMLElement) || !(portraitFooter instanceof HTMLElement)) {
            throw new Error("portrait shell missing");
        }
        const portrait = box(shell());
        expect(padding(shell())).toEqual({
            top: "47px",
            right: "0px",
            bottom: "34px",
            left: "0px",
        });
        expect(padding(layout()).top).toBe("10px");
        expectInside(header, portrait);
        expectInside(portraitFooter, portrait);
        expect(portraitFooter.getBoundingClientRect().bottom).toBeLessThanOrEqual(844 - 34 + 0.5);
        expectWebViewFilled();
        expectSingleBottomPad();

        await page.getByRole("button", { name: "Toggle navigation menu" }).click();
        const menu = [...document.querySelectorAll("[aria-label='Nav']")].find(
            (element) => element instanceof HTMLElement && element.getClientRects().length > 0,
        );
        if (!(menu instanceof HTMLElement)) {
            throw new Error("navigation menu missing");
        }
        expectInside(menu, box(shell()));
        expectReadoutShowsProbe();
        expect(floatingControl().getBoundingClientRect().bottom).toBeLessThanOrEqual(
            844 - 34 + 0.5,
        );

        // Landscape: side insets keep the navigation and footer on the screen.
        await page.viewport(844, 390);
        setInsets({ top: "0px", right: "47px", bottom: "21px", left: "47px" });
        await expect.element(page.getByRole("region", { name: "Nav" })).toBeVisible();
        const landscapeNav = [...document.querySelectorAll("[aria-label='Nav']")].find(
            (element) => element instanceof HTMLElement && element.getClientRects().length > 0,
        );
        const landscapeFooter = document.querySelector("footer");
        if (!(landscapeNav instanceof HTMLElement) || !(landscapeFooter instanceof HTMLElement)) {
            throw new Error("landscape shell missing");
        }
        expect(padding(shell())).toEqual({
            top: "0px",
            right: "47px",
            bottom: "21px",
            left: "47px",
        });
        expectInside(landscapeNav, box(shell()));
        expectInside(landscapeFooter, box(shell()));
        expect(floatingControl().getBoundingClientRect().right).toBeLessThanOrEqual(844 - 47 + 0.5);
    } finally {
        clearInsets();
        await page.viewport(1280, 800);
    }
});
