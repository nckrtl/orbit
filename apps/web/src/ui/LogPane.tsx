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
    error = null,
    live = false,
    testId,
}: {
    title: string;
    lines: string[] | undefined;
    loading: boolean;
    className?: string;
    /** Shown instead of the lines when the log cannot be read any more. */
    error?: string | null;
    /** True while a live stream appends the lines. */
    live?: boolean;
    /** Stable id for web verification. Omitted when unset. */
    testId?: string;
}) {
    const scroller = useRef<HTMLDivElement>(null);
    const count = lines?.length ?? 0;
    const virtual = useVirtualizer({
        count,
        getScrollElement: () => scroller.current,
        estimateSize: () => ROW,
        overscan: 30,
    });

    // A log opens at its end, as `tail` does, and stays there while new lines arrive or the pane
    // changes size. It stops following once the reader scrolls up, and follows again at the end.
    const follows = useRef(true);

    useEffect(() => {
        const element = scroller.current;

        if (element === null) {
            return;
        }

        const toEnd = () => {
            if (follows.current) {
                element.scrollTop = element.scrollHeight;
            }
        };
        const onScroll = () => {
            follows.current = element.scrollHeight - element.scrollTop - element.clientHeight < ROW;
        };
        const resize = new ResizeObserver(toEnd);

        toEnd();
        resize.observe(element);
        element.addEventListener("scroll", onScroll);

        return () => {
            resize.disconnect();
            element.removeEventListener("scroll", onScroll);
        };
    }, [count]);

    return (
        <section className={`frame ${className}`} aria-label={title} data-testid={testId}>
            <div className="frame-edge" data-edge="top">
                <span className="frame-label" data-role="title">
                    {title}
                </span>
            </div>
            {/* The lines scroll inside the padding, so none of them passes under the title in the border. */}
            <div className="frame-body flex flex-col !overflow-hidden">
                <div ref={scroller} className="selectable min-h-0 flex-1 overflow-auto">
                    {error !== null ? (
                        <div role="alert" className="text-red">
                            {error}
                        </div>
                    ) : count === 0 ? (
                        <Note>{loading ? "Loading…" : "No log lines yet."}</Note>
                    ) : virtual.getVirtualItems().length === 0 ? (
                        // Scrollport height 0 before layout — paint anyway so the body is not blank.
                        lines!.map((line, index) => (
                            <div key={index} className="whitespace-pre" style={{ height: ROW }}>
                                {line}
                            </div>
                        ))
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
            </div>
            {error === null && (count > 0 || live) && (
                <div className="frame-edge" data-edge="bottom">
                    {live ? <span className="frame-label">live</span> : <span />}
                    {count > 0 ? <span className="frame-label">{count} lines</span> : <span />}
                </div>
            )}
        </section>
    );
}
