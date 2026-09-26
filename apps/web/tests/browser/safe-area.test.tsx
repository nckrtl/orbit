import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { ANNOTATION_HOST_ID } from "@nckrtl/annotate/host";
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

        await page.getByRole("button", { name: "Toggle navigation menu" }).click();
        const menu = [...document.querySelectorAll("[aria-label='Nav']")].find(
            (element) => element instanceof HTMLElement && element.getClientRects().length > 0,
        );
        if (!(menu instanceof HTMLElement)) {
            throw new Error("navigation menu missing");
        }
        expectInside(menu, box(shell()));
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
