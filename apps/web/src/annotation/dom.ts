const IGNORED_SELECTORS = [
    "[data-feedback-toolbar]",
    "[data-agentation-toolbar]",
    "[data-annotation-popup]",
    "[data-annotation-marker]",
    "#toolbar-agentation-root",
];

export function getParentElement(element: Element | null): Element | null {
    if (!element) {
        return null;
    }

    if (element.parentElement) {
        return element.parentElement;
    }

    const root = element.getRootNode();

    if (root instanceof ShadowRoot) {
        return root.host;
    }

    return null;
}

export function closestCrossingShadow(element: Element | null, selector: string): Element | null {
    let current: Element | null = element;

    while (current) {
        if (current.matches(selector)) {
            return current;
        }

        current = getParentElement(current);
    }

    return null;
}

export function deepElementFromPoint(x: number, y: number): Element | null {
    if (typeof document.elementFromPoint !== "function") {
        return null;
    }

    let element = document.elementFromPoint(x, y);

    if (!element) {
        return null;
    }

    while (element?.shadowRoot) {
        const deeper = element.shadowRoot.elementFromPoint(x, y);

        if (!deeper || deeper === element) {
            break;
        }

        element = deeper;
    }

    return element;
}

export function isAnnotationUi(element: Element | null): boolean {
    if (!element) {
        return false;
    }

    return IGNORED_SELECTORS.some((selector) => closestCrossingShadow(element, selector));
}

export function isElementFixed(element: Element): boolean {
    let current: Element | null = element;

    while (current && current !== document.body) {
        const position = window.getComputedStyle(current).position;

        if (position === "fixed" || position === "sticky") {
            return true;
        }

        current = current.parentElement;
    }

    return false;
}

export function selectedText(): string | undefined {
    const text = window.getSelection()?.toString().trim();

    if (!text) {
        return undefined;
    }

    return text.slice(0, 500);
}

export const POPUP_WIDTH = 288;
export const POPUP_HEIGHT = 44;
export const POPUP_CONTROL_SIZE = 28;
export const POPUP_HEIGHT_EXPANDED = 172;
const POPUP_MARGIN = 12;
const TOOLBAR_CLEARANCE = 56;
const MARKER_RADIUS = 11;
const MARKER_GAP = 10;

export function viewportWidth(): number {
    return document.documentElement.clientWidth || window.innerWidth;
}

export function percentToViewportX(percent: number): number {
    return (percent / 100) * viewportWidth();
}

export function hoverLabelPosition(
    cursorX: number,
    cursorY: number,
    height = 32,
): { left: number; top: number } {
    return {
        left: Math.max(8, Math.min(cursorX, window.innerWidth - 100)),
        top: Math.max(8, cursorY - height),
    };
}

export function popupPosition(
    anchorX: number,
    anchorY: number,
    size: { width?: number; height?: number } = {},
): { left: number; top: number } {
    const width = viewportWidth();
    const boxWidth = size.width ?? POPUP_WIDTH;
    const boxHeight = size.height ?? POPUP_HEIGHT;
    let left = anchorX - boxWidth / 2;
    let top = anchorY + MARKER_RADIUS + MARKER_GAP;

    if (left < POPUP_MARGIN) {
        left = POPUP_MARGIN;
    }

    if (left + boxWidth > width - POPUP_MARGIN) {
        left = width - POPUP_MARGIN - boxWidth;
    }

    if (top + boxHeight > window.innerHeight - TOOLBAR_CLEARANCE) {
        top = anchorY - MARKER_RADIUS - MARKER_GAP - boxHeight;

        if (top < POPUP_MARGIN) {
            top = POPUP_MARGIN;
        }
    }

    return { left, top };
}
