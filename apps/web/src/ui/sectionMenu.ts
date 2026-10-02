export type SectionOrientation = "vertical" | "horizontal";

/**
 * The next tab for a section menu. Two tabs still swap on either arrow, which is what the
 * instance menu already did. Three or more move one step and wrap, so the middle tab is reachable.
 */
export function nextSectionIndex(
    current: number,
    count: number,
    key: string,
    orientation: SectionOrientation,
): number | null {
    if (count <= 0 || current < 0 || current >= count) {
        return null;
    }

    const previous = orientation === "vertical" ? "ArrowUp" : "ArrowLeft";
    const next = orientation === "vertical" ? "ArrowDown" : "ArrowRight";

    if (key === "Home") {
        return 0;
    }

    if (key === "End") {
        return count - 1;
    }

    if (key === next) {
        return (current + 1) % count;
    }

    if (key === previous) {
        return (current - 1 + count) % count;
    }

    return null;
}
