import { createContext, useContext, useSyncExternalStore, type ReactNode } from "react";
import { createPortal } from "react-dom";

export const PageHeaderSlot = createContext<{
    header: HTMLDivElement | null;
    actions: HTMLDivElement | null;
}>({ header: null, actions: null });

const desktop = () => window.matchMedia("(min-width: 768px)").matches;
const subscribe = (notify: () => void) => {
    const media = window.matchMedia("(min-width: 768px)");
    media.addEventListener("change", notify);
    return () => media.removeEventListener("change", notify);
};

export type Crumb = { label: string; open?: () => void };

/**
 * The line above a page: the way to it as crumbs, and the page's tools on the right. Every crumb but
 * the last opens what it names, so the line also takes the reader back up.
 */
export function PageHeader({
    trail,
    children,
    actions,
}: {
    trail: Crumb[];
    children?: ReactNode;
    actions?: ReactNode;
}) {
    const { header: slot, actions: actionsSlot } = useContext(PageHeaderSlot);
    const inNavigation = useSyncExternalStore(subscribe, desktop, () => false) && slot !== null;
    const moveActions = inNavigation && actionsSlot !== null;
    const content = (
        <div
            className={`flex flex-wrap items-center gap-[1ch] md:gap-[2ch] ${inNavigation ? "navigation-page-header" : "px-[1ch]"}`}
        >
            <nav
                aria-label="Breadcrumb"
                className="breadcrumb flex min-w-0 flex-wrap items-center gap-[1ch]"
            >
                {trail.map((crumb, index) => {
                    const last = index === trail.length - 1;

                    return (
                        <span key={index} className="flex min-w-0 items-center gap-[1ch]">
                            {index > 0 && <span className="text-dim">›</span>}
                            {last || crumb.open === undefined ? (
                                <span
                                    className="selectable truncate text-dim"
                                    aria-current={last ? "page" : undefined}
                                >
                                    {crumb.label}
                                </span>
                            ) : (
                                <span
                                    className="cursor-pointer truncate text-dim hover:underline"
                                    onClick={crumb.open}
                                >
                                    {crumb.label}
                                </span>
                            )}
                        </span>
                    );
                })}
            </nav>
            {children !== undefined && (
                <span className="ml-auto flex flex-wrap items-center gap-[1.5ch] md:gap-[2ch]">
                    {children}
                </span>
            )}
            {!moveActions && actions}
        </div>
    );
    return (
        <>
            {inNavigation ? createPortal(content, slot) : content}
            {moveActions && createPortal(actions, actionsSlot)}
        </>
    );
}
