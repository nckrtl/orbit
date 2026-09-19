import { useSyncExternalStore } from "react";
import type { Action } from "../api/actions";
import type { AnyRecord, Kind } from "../api/types";

export type Target = { kind: Kind; row: AnyRecord };

export type MenuState = Target & {
    title: string;
    actions: Action[];
    selected: number;
    confirm: boolean;
    running: boolean;
    at: [number, number] | null;
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
    message: string;
};

/** What a drawn pane tells the keyboard: how many rows it has, and what a row opens or acts on. */
export type PaneHandle = {
    order: number;
    count: number;
    target: (index: number) => Target | null;
    leaf: boolean;
};

const initial: UiState = { hover: "nav", focus: null, selected: {}, menu: null, message: "" };
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
