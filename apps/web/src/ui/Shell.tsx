import { Outlet, useLocation } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { transportLabel } from "../api/client";
import { queryClient } from "../api/queryClient";
import { POLL_SECONDS, useFleet } from "../api/queries";
import { counts } from "../fleet/fleet";
import { connectRealtime } from "../realtime/connect";
import { useLiveness, usePollingReason } from "../realtime/liveness";
import { Frame } from "./Frame";
import {
    FILTERED_SECTIONS,
    NAV,
    navFor,
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
            {NAV.map((key) => {
                const [count, warn] = key === "dashboard" ? [null, 0] : totals[key];

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

function footerHint(section: Section, onList: boolean, onForm: boolean): string {
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

    return `↑↓ sections · → into the page · 1-4 jump${section === "nodes" ? " · c or + create" : ""}${FILTERED_SECTIONS.includes(section) ? " · n/p filters" : ""}`;
}

/** The screen: the sidebar beside the open page, and one footer line with the key hints and the Gateway's WebSocket status. */
export function Shell() {
    const liveness = useLiveness();
    const pollingReason = usePollingReason();
    const { pathname } = useLocation();
    const [first, second] = pathname.split("/").filter(Boolean);
    const section = (SECTIONS as readonly string[]).includes(first ?? "")
        ? (first as Section)
        : "dashboard";
    const message = useUi((state) => state.message);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const go = useGo();
    const fleet = useFleet();
    const totals = counts(fleet);
    const activeNav = navFor(section, second, fleet);

    useUi((state) => `${state.focus}|${state.menu === null}|${state.menu?.confirm}`);
    useKeyboard(pageTarget);

    useEffect(() => {
        setMobileMenuOpen(false);
    }, [pathname]);

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
        <div className="grid h-full min-w-0 max-w-full overflow-x-hidden grid-cols-[minmax(0,1fr)] grid-rows-[auto_minmax(0,1fr)_auto] md:grid-rows-[minmax(0,1fr)_auto] gap-y-[10px] px-[1ch] pt-[10px] md:pt-[14px] pb-[4px]">
            {/* Mobile Header Bar */}
            <header className="flex items-center justify-between gap-2 px-[0.5ch] py-[2px] md:hidden">
                <button
                    type="button"
                    className="cursor-pointer border border-line px-[1.5ch] py-[2px] font-bold text-fg hover:border-fg active:bg-fg active:text-bg"
                    onClick={() => setMobileMenuOpen((open) => !open)}
                    aria-label="Toggle navigation menu"
                >
                    [ ☰ Menu ]
                </button>
                <span className="font-bold tracking-wider uppercase text-cyan">
                    {SECTION_TITLES[section] ?? "Orbit"}
                </span>
                <span className="flex items-center gap-[1ch] text-xs text-dim">
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
                    <span className="max-w-[100px] truncate">{gateway}</span>
                </span>
            </header>

            {/* Mobile Navigation Drawer Overlay */}
            {mobileMenuOpen && (
                <div
                    className="fixed inset-0 z-50 flex flex-col bg-bg/95 p-[2ch] backdrop-blur-xs md:hidden"
                    onClick={(e) => {
                        if (e.target === e.currentTarget) setMobileMenuOpen(false);
                    }}
                >
                    <Frame
                        title="Navigation"
                        topRight={
                            <button
                                type="button"
                                className="cursor-pointer text-dim hover:text-fg"
                                onClick={() => setMobileMenuOpen(false)}
                            >
                                [× close]
                            </button>
                        }
                        className="max-h-[90vh] w-full"
                    >
                        <div className="flex flex-col gap-y-[4px]">
                            <div className="pb-[4px] text-xs font-bold tracking-wider text-dim uppercase">
                                Main
                            </div>
                            {NAV.map((key) => {
                                const [count, warn] = key === "dashboard" ? [null, 0] : totals[key];
                                const isSelected = key === activeNav;

                                return (
                                    <div
                                        key={key}
                                        className="row"
                                        data-link=""
                                        data-selected={isSelected ? "" : undefined}
                                        onClick={() => {
                                            go.section(key);
                                            setMobileMenuOpen(false);
                                        }}
                                        style={{ gridTemplateColumns: "minmax(0, 1fr) 4ch" }}
                                    >
                                        <span className={isSelected ? "font-bold text-cyan" : ""}>
                                            {SECTION_TITLES[key]}
                                        </span>
                                        <span
                                            className={`text-right ${warn > 0 ? "text-yellow" : "text-dim"}`}
                                        >
                                            {count ?? ""}
                                        </span>
                                    </div>
                                );
                            })}

                            <div className="mt-[12px] border-t border-line pt-[8px] pb-[4px] text-xs font-bold tracking-wider text-dim uppercase">
                                Other Sections
                            </div>
                            {SECTIONS.filter((s) => !NAV.includes(s as (typeof NAV)[number])).map(
                                (sec) => {
                                    const isSelected = sec === section;

                                    return (
                                        <div
                                            key={sec}
                                            className="row"
                                            data-link=""
                                            data-selected={isSelected ? "" : undefined}
                                            onClick={() => {
                                                go.section(sec);
                                                setMobileMenuOpen(false);
                                            }}
                                        >
                                            <span
                                                className={isSelected ? "font-bold text-cyan" : ""}
                                            >
                                                {SECTION_TITLES[sec] ?? sec}
                                            </span>
                                        </div>
                                    );
                                },
                            )}
                        </div>
                    </Frame>
                </div>
            )}

            <div className="grid min-h-0 min-w-0 max-w-full grid-cols-[minmax(0,1fr)] md:grid-cols-[auto_minmax(0,1fr)] md:gap-x-[1ch]">
                <div className="hidden md:block">
                    <Sidebar section={section} id={second} />
                </div>
                <main className="min-h-0 min-w-0 flex-1 overflow-y-auto md:overflow-visible">
                    <Outlet />
                </main>
            </div>
            <footer className="flex gap-[2ch] whitespace-nowrap px-[1ch] text-dim">
                <span className="min-w-0 flex-1 overflow-hidden text-ellipsis">
                    {footerHint(section, second === undefined, pathname === "/nodes/create")}
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
