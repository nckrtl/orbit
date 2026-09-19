import { useVirtualizer } from "@tanstack/react-virtual";
import { useEffect, useRef } from "react";
import { Note } from "./Frame";

const ROW = 20; // --lh

/** Log lines over the full width. Only the visible lines are in the page, so a long log stays fast. */
export function LogPane({
    title,
    lines,
    loading,
    className = "",
}: {
    title: string;
    lines: string[] | undefined;
    loading: boolean;
    className?: string;
}) {
    const scroller = useRef<HTMLDivElement>(null);
    const count = lines?.length ?? 0;
    const virtual = useVirtualizer({
        count,
        getScrollElement: () => scroller.current,
        estimateSize: () => ROW,
        overscan: 30,
    });

    // A log opens at its end, as `tail` does.
    useEffect(() => {
        if (count > 0) {
            virtual.scrollToIndex(count - 1, { align: "end" });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [count]);

    return (
        <section className={`frame ${className}`} aria-label={title}>
            <div className="frame-edge" data-edge="top">
                <span className="frame-label" data-role="title">
                    {title}
                </span>
            </div>
            <div ref={scroller} className="frame-body selectable">
                {count === 0 ? (
                    <Note>{loading ? "Loading…" : "No log lines yet."}</Note>
                ) : (
                    <div className="relative" style={{ height: virtual.getTotalSize() }}>
                        {virtual.getVirtualItems().map((item) => (
                            <div
                                key={item.key}
                                className="absolute left-0 whitespace-pre"
                                style={{ top: item.start, height: ROW }}
                            >
                                {lines?.[item.index]}
                            </div>
                        ))}
                    </div>
                )}
            </div>
            {count > 0 && (
                <div className="frame-edge" data-edge="bottom">
                    <span />
                    <span className="frame-label">{count} lines</span>
                </div>
            )}
        </section>
    );
}
