import { useRouter } from "@tanstack/react-router";
import { useEffect } from "react";
import { queryClient } from "../api/queryClient";
import type { App, Node } from "../api/types";
import { FILTERED_SECTIONS, NAV, navFor, type Section, useGo } from "./go";
import { chooseAction, openMenu } from "./menu";
import { paneBeside, paneOrder, panes, selectionKey, type Target, ui } from "./store";

/**
 * The keys `orbit top` answers, ported from its Interaction class. While nothing is focused the
 * arrows hover: the sidebar reacts straight away, the page panes wait for Enter. A focused pane
 * moves its selection. `a` lists the actions for the selected row or the open record.
 */
export function useKeyboard(pageTarget: () => Target | null): void {
    const router = useRouter();
    const go = useGo();

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const element = event.target as HTMLElement | null;
            const typing =
                element !== null && (element.tagName === "INPUT" || element.tagName === "TEXTAREA");

            if (
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                (typing && event.key !== "Escape")
            ) {
                return;
            }

            const state = ui.get();
            const { pathname, search } = router.state.location;
            const [first, second] = pathname.split("/").filter(Boolean);
            const section = (first ?? "dashboard") as Section;
            const onList = second === undefined;
            const handled = () => event.preventDefault();

            // A modal owns the keys while it is open, and Esc is the only one it answers.
            if (state.modal !== null) {
                handled();

                if (event.key === "Escape" || event.key === "Enter") {
                    ui.set({ modal: null });
                }

                return;
            }

            if (state.menu !== null) {
                const menu = state.menu;
                handled();

                if (event.key === "Escape") {
                    ui.set({ menu: null });
                } else if (event.key === "Enter") {
                    chooseAction();
                } else if (!menu.confirm && event.key === "ArrowDown") {
                    ui.set({
                        menu: {
                            ...menu,
                            selected: Math.min(menu.actions.length - 1, menu.selected + 1),
                        },
                    });
                } else if (!menu.confirm && event.key === "ArrowUp") {
                    ui.set({ menu: { ...menu, selected: Math.max(0, menu.selected - 1) } });
                }

                return;
            }

            if (typing) {
                (element as HTMLElement).blur();
                go.back();

                return;
            }

            const order = paneOrder();
            const focus = state.focus;

            if (focus !== null) {
                const key = selectionKey(pathname, focus);
                const pane = panes.get(focus);
                const index = Math.min(
                    state.selected[key] ?? 0,
                    Math.max(0, (pane?.count ?? 1) - 1),
                );

                switch (event.key) {
                    case "ArrowDown":
                        ui.select(key, Math.min(Math.max(0, (pane?.count ?? 1) - 1), index + 1));
                        return handled();
                    case "ArrowUp":
                        ui.select(key, Math.max(0, index - 1));
                        return handled();
                    case "Enter": {
                        const target = pane?.target(index) ?? null;

                        if (target !== null) {
                            go.record(target.kind, target.row);
                        }

                        return handled();
                    }
                    case "Escape":
                        ui.set({ focus: null });
                        return handled();
                    case "x": {
                        const target = pane?.target(index) ?? null;

                        if (target !== null) {
                            openMenu(target);
                        }

                        return handled();
                    }
                }

                return;
            }

            if (event.key === "x") {
                const target = pageTarget();

                if (target !== null) {
                    openMenu(target);
                }

                return handled();
            }

            if (event.key === "c" && section === "nodes" && onList) {
                go.create();

                return handled();
            }

            if (
                (event.key === "n" || event.key === "p") &&
                onList &&
                FILTERED_SECTIONS.includes(section)
            ) {
                const name = event.key === "n" ? "node" : "app";
                const values =
                    name === "node"
                        ? (queryClient.getQueryData<Node[]>(["nodes"]) ?? []).map(
                              (node) => node.name,
                          )
                        : (queryClient.getQueryData<App[]>(["apps"]) ?? []).map((app) => app.slug);
                const current = (search as Record<string, string | undefined>)[name];
                go.filter(
                    section,
                    name,
                    values[current === undefined ? 0 : values.indexOf(current) + 1],
                );
                ui.select(selectionKey(pathname, "list"), 0);

                return handled();
            }

            if (/^[1-5]$/.test(event.key)) {
                go.section(NAV[Number(event.key) - 1] as Section);

                return handled();
            }

            if (state.hover === "nav") {
                const index = NAV.indexOf(navFor(section));

                switch (event.key) {
                    case "ArrowDown":
                        go.section(NAV[Math.min(NAV.length - 1, index + 1)] as Section);
                        return handled();
                    case "ArrowUp":
                        go.section(NAV[Math.max(0, index - 1)] as Section);
                        return handled();
                    case "ArrowRight":
                    case "Enter":
                        ui.set({ hover: order[0] ?? "nav" });
                        return handled();
                    case "Escape":
                        if (!onList) {
                            go.back();
                        }

                        return handled();
                }

                return;
            }

            switch (event.key) {
                case "ArrowLeft":
                    ui.set({ hover: paneBeside(state.hover, event.key) ?? "nav" });
                    return handled();
                case "ArrowRight":
                case "ArrowDown":
                case "ArrowUp":
                    ui.set({ hover: paneBeside(state.hover, event.key) ?? state.hover });
                    return handled();
                case "Enter":
                    ui.set({ focus: state.hover });
                    return handled();
                case "Escape":
                    if (onList) {
                        ui.set({ hover: "nav" });
                    } else {
                        go.back();
                    }

                    return handled();
            }
        };

        window.addEventListener("keydown", onKey);

        return () => window.removeEventListener("keydown", onKey);
    }, [router, go, pageTarget]);
}
