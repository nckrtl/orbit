import type { AnnotationRect } from "@/annotation/types";

export const SCREENSHOT_BLEED = 100;
export const SCREENSHOT_ASPECT = 16 / 9;

const toPixels = (value: string): number => {
    const amount = Number.parseFloat(value);

    return value.endsWith("rem") ? amount * 16 : amount;
};

export function currentBreakpoint(): string {
    if (typeof window === "undefined") {
        return "xs";
    }

    const styles = getComputedStyle(document.documentElement);
    const named = Array.from(styles)
        .filter((property) => property.startsWith("--breakpoint-"))
        .map(
            (property) =>
                [
                    property.slice("--breakpoint-".length),
                    styles.getPropertyValue(property).trim(),
                ] as const,
        )
        .filter(([, value]) => value !== "")
        .sort((left, right) => toPixels(right[1]) - toPixels(left[1]));

    for (const [name, value] of named) {
        if (window.matchMedia(`(min-width: ${value})`).matches) {
            return name;
        }
    }

    return named[named.length - 1]?.[0] ?? "xs";
}

export function currentScreenSize(): string {
    if (typeof window === "undefined") {
        return "";
    }

    return `${window.innerWidth}px, ${window.innerHeight}px`;
}

export function currentScrollPosition(): string {
    if (typeof window === "undefined") {
        return "0px";
    }

    return `${Math.round(window.scrollY)}px`;
}

export function screenshotCrop(
    box: AnnotationRect,
    options: {
        viewportWidth: number;
        viewportHeight: number;
        bleed?: number;
    },
): AnnotationRect {
    const bleed = options.bleed ?? SCREENSHOT_BLEED;
    const viewportWidth = options.viewportWidth;
    const viewportHeight = options.viewportHeight;
    const minLeft = Math.max(0, box.x - bleed);
    const minTop = Math.max(0, box.y - bleed);
    const minRight = Math.min(viewportWidth, box.x + box.width + bleed);
    const minBottom = Math.min(viewportHeight, box.y + box.height + bleed);
    const minWidth = Math.max(1, minRight - minLeft);
    const minHeight = Math.max(1, minBottom - minTop);
    let width = minWidth;
    let height = minHeight;

    if (width / height < SCREENSHOT_ASPECT) {
        width = height * SCREENSHOT_ASPECT;
    } else if (width / height > SCREENSHOT_ASPECT) {
        height = width / SCREENSHOT_ASPECT;
    }

    if (width > viewportWidth || height > viewportHeight) {
        return {
            x: minLeft,
            y: minTop,
            width: minWidth,
            height: minHeight,
        };
    }

    let x = box.x + box.width / 2 - width / 2;
    let y = box.y + box.height / 2 - height / 2;

    if (width >= minWidth) {
        x = Math.min(x, minLeft);
        x = Math.max(x, minRight - width);
    }

    if (height >= minHeight) {
        y = Math.min(y, minTop);
        y = Math.max(y, minBottom - height);
    }

    return {
        x: clamp(x, 0, Math.max(0, viewportWidth - width)),
        y: clamp(y, 0, Math.max(0, viewportHeight - height)),
        width,
        height,
    };
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}
