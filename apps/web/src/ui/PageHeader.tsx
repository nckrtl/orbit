import type { ReactNode } from "react";

export type Crumb = { label: string; open?: () => void };

/**
 * The line above a page: the way to it as crumbs, and the page's tools on the right. Every crumb but
 * the last opens what it names, so the line also takes the reader back up.
 */
export function PageHeader({ trail, children }: { trail: Crumb[]; children?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-center gap-[1ch] px-[1ch] md:gap-[2ch]">
            <nav aria-label="Breadcrumb" className="flex min-w-0 flex-wrap items-center gap-[1ch]">
                {trail.map((crumb, index) => {
                    const last = index === trail.length - 1;

                    return (
                        <span key={index} className="flex min-w-0 items-center gap-[1ch]">
                            {index > 0 && <span className="text-dim">›</span>}
                            {last || crumb.open === undefined ? (
                                <span
                                    className={`selectable truncate ${last ? "font-bold" : "text-dim"}`}
                                    aria-current={last ? "page" : undefined}
                                >
                                    {crumb.label}
                                </span>
                            ) : (
                                <span
                                    className="cursor-pointer truncate text-dim hover:text-fg"
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
        </div>
    );
}
