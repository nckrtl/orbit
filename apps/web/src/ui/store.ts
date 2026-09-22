import { useSyncExternalStore } from "react";
import type { Action } from "../api/actions";
import type { AnyRecord, Kind } from "../api/types";

export type Target = { kind: Kind; row: AnyRecord };

export type MenuState = Target & {
    /** One opening of the menu, retained when selection and confirmation state are copied. */
    invocation: object;
    title: string;
    actions: Action[];
    selected: number;
    confirm: boolean;
    running: boolean;
    at: [number, number] | null;
    /** `at` is the corner the menu hangs from on its right side, as under the actions button. */
    hangsRight: boolean;
};

/**
 * Everything about the screen that is not fleet data and not in the URL: which pane the arrows
 * point at (`hover`), which pane Enter focused, the selected row per pane, the open actions menu,
 * and the last footer message. The section, the open record, and the filters live in the URL.
 */
export type UiState = {
    hover: string;
    focus: string | null;
    selected: Record<string, number>;
    menu: MenuState | null;
    /** The output of an action that prints a report, such as a profile; `output` is null while it runs. */
    modal: { title: string; output: string | null; failed: boolean } | null;
    message: string;
};

/** What a drawn pane tells the keyboard: how many rows it has, and what a row opens or acts on. */
export type PaneHandle = {
    order: number;
    count: number;
    target: (index: number) => Target | null;
    leaf: boolean;
};

const initial: UiState = {
    hover: "nav",
    focus: null,
    selected: {},
    menu: null,
    modal: null,
    message: "",
};
let state: UiState = initial;
const listeners = new Set<() => void>();

export const panes = new Map<string, PaneHandle>();
export const paneOrder = (): string[] =>
    [...panes.entries()].sort(([, a], [, b]) => a.order - b.order).map(([name]) => name);

export const ui = {
    get: (): UiState => state,
    set(patch: Partial<UiState>): void {
        state = { ...state, ...patch };
        listeners.forEach((listener) => listener());
    },
    reset(): void {
        panes.clear();
        ui.set(initial);
    },
    select(key: string, index: number): void {
        ui.set({ selected: { ...state.selected, [key]: index } });
    },
};

export function useUi<T>(selector: (state: UiState) => T): T {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => selector(state),
    );
}

/** A pane's selection is kept per page, so going back finds the row that was opened. */
export const selectionKey = (pathname: string, pane: string): string => `${pathname}|${pane}`;

type Direction = "ArrowLeft" | "ArrowRight" | "ArrowUp" | "ArrowDown";

/**
 * The pane the arrow points at from `from`, by where the panes are on the screen: the nearest one
 * in that direction that shares a row or a column with it, the top or left one when two tie.
 */
export function paneBeside(from: string, direction: Direction): string | null {
    const rects = new Map<string, DOMRect>();

    for (const element of document.querySelectorAll<HTMLElement>("[data-pane]")) {
        const name = element.dataset.pane ?? "";

        if (panes.has(name)) {
            rects.set(name, element.getBoundingClientRect());
        }
    }

    const current = rects.get(from);

    if (current === undefined) {
        return null;
    }

    const horizontal = direction === "ArrowLeft" || direction === "ArrowRight";
    let best: [number, number, string] | null = null;

    for (const [name, rect] of rects) {
        const distance = {
            ArrowLeft: current.left - rect.right,
            ArrowRight: rect.left - current.right,
            ArrowUp: current.top - rect.bottom,
            ArrowDown: rect.top - current.bottom,
        }[direction];
        const overlaps = horizontal
            ? rect.top < current.bottom && rect.bottom > current.top
            : rect.left < current.right && rect.right > current.left;

        if (name === from || distance < -1 || !overlaps) {
            continue;
        }

        const cross = horizontal ? rect.top : rect.left;

        if (
            best === null ||
            distance < best[0] - 1 ||
            (distance < best[0] + 1 && cross < best[1])
        ) {
            best = [distance, cross, name];
        }
    }

    return best?.[2] ?? null;
}
