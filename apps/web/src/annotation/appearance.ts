export type AppearanceSnapshot = {
    classes: string[];
    computed: Record<string, string>;
    utilities: Record<string, string>;
};

const TYPE = /^(text-(?:xs|sm|base|lg|xl|[2-9]xl))$/;
const SPACE = /^((?:p|m)(?:[xytblr])?|gap(?:-[xy])?)-(\d+(?:\.\d+)?|px)$/;
const TRACKING = /^(tracking-(?:tighter|tight|normal|wide|wider|widest))$/;
const WEIGHT = /^(font-(?:thin|extralight|light|normal|medium|semibold|bold|extrabold|black))$/;
const TEXT_TOKEN =
    /^(text-(?:white|black|transparent|current|foreground|muted-foreground|primary|primary-foreground|secondary-foreground|destructive|card-foreground|accent-foreground|(?:red|blue|green|yellow|pink|orange|purple|indigo|sky|rose|slate|gray|zinc|neutral|stone)-(?:50|100|200|300|400|500|600|700|800|900|950)))$/;
const BG_TOKEN = /^(bg-(?:background|card|muted|primary|secondary|accent|destructive|popover))$/;
const MAX_WIDTH = /^(max-w-(?:xs|sm|md|lg|xl|[2-7]xl|full|prose))$/;
const WIDTH = /^(w-(?:\d+|px|full|fit|auto|screen))$/;

const COMPUTED = [
    "fontSize",
    "fontWeight",
    "lineHeight",
    "letterSpacing",
    "color",
    "backgroundColor",
    "padding",
    "paddingTop",
    "paddingRight",
    "paddingBottom",
    "paddingLeft",
] as const;

export function captureAppearance(element: HTMLElement): AppearanceSnapshot {
    const classes = classList(element);
    const computed = computedStyles(element);

    return {
        classes,
        computed,
        utilities: detectUtilities(classes),
    };
}

export function detectUtilities(classes: string[]): Record<string, string> {
    const utilities: Record<string, string> = {};

    for (const value of classes) {
        if (TYPE.test(value)) {
            utilities.type = value;
        } else if (SPACE.test(value) && value.startsWith("p")) {
            utilities.padding ??= value;
        } else if (SPACE.test(value) && (value.startsWith("m") || value.startsWith("gap"))) {
            utilities.spacing ??= value;
        } else if (TRACKING.test(value)) {
            utilities.tracking = value;
        } else if (WEIGHT.test(value)) {
            utilities.weight = value;
        } else if (TEXT_TOKEN.test(value)) {
            utilities.color = value;
        } else if (BG_TOKEN.test(value)) {
            utilities.background = value;
        } else if (MAX_WIDTH.test(value)) {
            utilities.width = value;
        } else if (WIDTH.test(value)) {
            utilities.width ??= value;
        }
    }

    return utilities;
}

function classList(element: HTMLElement): string[] {
    if (typeof element.className !== "string" || !element.className) {
        return [];
    }

    return element.className.split(/\s+/).filter(Boolean);
}

function computedStyles(element: HTMLElement): Record<string, string> {
    if (typeof window === "undefined") {
        return {};
    }

    const styles = window.getComputedStyle(element);
    const computed: Record<string, string> = {};

    for (const property of COMPUTED) {
        const value = styles[property];

        if (value && value !== "0px" && value !== "normal" && value !== "rgba(0, 0, 0, 0)") {
            computed[property] = value;
        }
    }

    return computed;
}
