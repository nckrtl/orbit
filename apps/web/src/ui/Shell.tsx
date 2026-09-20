import { Outlet, useLocation } from "@tanstack/react-router";
import { useEffect } from "react";
import { transportLabel } from "../api/client";
import { queryClient } from "../api/queryClient";
import { POLL_SECONDS, useFleet } from "../api/queries";
import { counts } from "../fleet/fleet";
import { connectRealtime } from "../realtime/connect";
import { useLiveness, usePollingReason } from "../realtime/liveness";
import { Frame } from "./Frame";
import {
    FILTERED_SECTIONS,
    navFor,
    useNav,
    SECTION_TITLES,
    SECTIONS,
    type Section,
    useGo,
} from "./go";
import { useKeyboard } from "./keyboard";
import { MenuPopup } from "./MenuPopup";
import { Modal } from "./Modal";
import { pageTarget } from "./page";
import { ui, useUi } from "./store";

declare const __ORBIT_GATEWAY__: string | null;

function Sidebar({ section, id }: { section: Section; id: string | undefined }) {
    const go = useGo();
    const fleet = useFleet();
    const nav = useNav();
    const hovered = useUi((state) => state.hover === "nav" && state.focus === null);
    const totals = counts(fleet);
    const active = navFor(section, id, fleet);

    return (
        <Frame
            title="Navigation"
            state={hovered ? "hovered" : undefined}
            className="w-[18ch]"
            onMouseDown={() => ui.set({ hover: "nav", focus: null })}
        >
            {nav.map((key) => {
                const [count, warn] =
                    key === "dashboard" || key === "quota" ? [null, 0] : totals[key];

                return (
                    <div
                        key={key}
                        className="row"
                        data-link=""
                        style={{ gridTemplateColumns: "minmax(0, 1fr) 4ch" }}
                        data-selected={key === active ? "" : undefined}
                        data-focused={hovered ? "" : undefined}
                        onClick={() => go.section(key)}
                    >
                        <span>{SECTION_TITLES[key]}</span>
                        <span
                            className={`text-right ${warn > 0 && !(key === active && hovered) ? "text-yellow" : ""}`}
                        >
                            {count ?? ""}
                        </span>
                    </div>
                );
            })}
        </Frame>
    );
}

function footerHint(section: Section, onList: boolean, onForm: boolean, navCount: number): string {
    const { menu, focus } = ui.get();

    if (menu !== null) {
        return menu.confirm
            ? "↑↓ choose · Enter or click confirms · Esc cancels"
            : "↑↓ choose · Enter or click runs · Esc closes";
    }

    if (onForm) {
        return "Tab moves between fields · Space toggles a role · Enter creates the node · Esc cancels";
    }

    if (focus !== null) {
        return "↑↓ move · Enter or click opens · x or right-click actions · Esc back to panes";
    }

    if (!onList) {
        return "←→ sidebar or page · ↑↓ panes · Enter focuses · Esc back · x or right-click actions";
    }

    return `↑↓ sections · → into the page · 1-${navCount} jump${section === "nodes" ? " · c or + create" : ""}${FILTERED_SECTIONS.includes(section) ? " · n/p filters" : ""}`;
}

/** The screen: the sidebar beside the open page, and one footer line with the key hints and the Gateway's WebSocket status. */
export function Shell() {
    const liveness = useLiveness();
    const pollingReason = usePollingReason();
    const nav = useNav();
    const { pathname } = useLocation();
    const [first, second] = pathname.split("/").filter(Boolean);
    const section = (SECTIONS as readonly string[]).includes(first ?? "")
        ? (first as Section)
        : "dashboard";
    const message = useUi((state) => state.message);
    useUi((state) => `${state.focus}|${state.menu === null}|${state.menu?.confirm}`);
    useKeyboard(pageTarget);

    useEffect(() => {
        const controller = new AbortController();
        void connectRealtime(queryClient, controller.signal);

        return () => controller.abort();
    }, []);

    const gateway =
        transportLabel() ??
        (__ORBIT_GATEWAY__ ?? window.location.origin).replace(/^https?:\/\//, "");
    // A green dot means the WebSocket is subscribed. Anything else says why in its tooltip; the lists poll meanwhile.
    const status =
        liveness === "live"
            ? "Live: connected to the Gateway's WebSocket."
            : liveness === "reconnecting"
              ? "Reconnecting to the Gateway's WebSocket."
              : `Not connected to the WebSocket${pollingReason === null ? "" : ` (${pollingReason})`}; refreshing every ${POLL_SECONDS}s.`;

    return (
        <div className="grid h-full grid-rows-[minmax(0,1fr)_auto] gap-y-[10px] px-[1ch] pt-[14px] pb-[4px]">
            <div className="grid min-h-0 grid-cols-[auto_minmax(0,1fr)] gap-x-[1ch]">
                <Sidebar section={section} id={second} />
                <main className="min-h-0 min-w-0">
                    <Outlet />
                </main>
            </div>
            <footer className="flex gap-[2ch] whitespace-nowrap px-[1ch] text-dim">
                <span className="min-w-0 flex-1 overflow-hidden text-ellipsis">
                    {footerHint(section, second === undefined, pathname === "/nodes/create", nav.length)}
                    {message !== "" && <span className="selectable text-fg"> │ {message}</span>}
                </span>
                <span className="flex items-center gap-[1ch]">
                    {gateway}
                    <span
                        role="status"
                        aria-label={status}
                        title={status}
                        className={`inline-block size-[8px] rounded-full ${
                            liveness === "live"
                                ? "bg-green"
                                : liveness === "reconnecting"
                                  ? "animate-pulse bg-yellow"
                                  : "border border-dim"
                        }`}
                    />
                </span>
            </footer>
            <MenuPopup />
            <Modal />
        </div>
    );
}
