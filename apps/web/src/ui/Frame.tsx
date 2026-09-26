import type { ReactNode, Ref } from "react";

type FrameProps = {
    title?: ReactNode;
    hotkey?: number;
    topRight?: ReactNode;
    bottomLeft?: ReactNode;
    bottomRight?: ReactNode;
    state?: "hovered" | "focused" | "warn";
    className?: string;
    bodyClassName?: string;
    /** The scrolling body, when the caller has to read its scroll position. */
    bodyRef?: Ref<HTMLDivElement>;
    children: ReactNode;
    onMouseDown?: () => void;
    /** The name the keyboard knows this frame by, so the arrows can find it on the screen. */
    pane?: string;
    /** The accessible name of a frame that shows no title. */
    label?: string;
};

/** A box with its labels in the border: title top left, and optional labels on the other corners. */
export function Frame({
    title,
    hotkey,
    topRight,
    bottomLeft,
    bottomRight,
    state,
    className = "",
    bodyClassName = "",
    bodyRef,
    children,
    onMouseDown,
    pane,
    label,
}: FrameProps) {
    return (
        <section
            className={`frame ${className}`}
            aria-label={label ?? (typeof title === "string" ? title : undefined)}
            data-state={state}
            data-pane={pane}
            onMouseDown={onMouseDown}
        >
            <div className="frame-edge" data-edge="top">
                {title === undefined ? (
                    <span />
                ) : (
                    <span className="frame-label" data-role="title">
                        {hotkey !== undefined && <sup>{hotkey}</sup>}
                        {title}
                    </span>
                )}
                {topRight !== undefined && <span className="frame-label">{topRight}</span>}
            </div>
            {/* With labels in the bottom border, the body ends above them, so no row scrolls under a label. */}
            <div
                ref={bodyRef}
                className={`frame-body ${bodyClassName} ${bottomLeft !== undefined || bottomRight !== undefined ? "mb-[10px]" : ""}`}
            >
                {children}
            </div>
            {(bottomLeft !== undefined || bottomRight !== undefined) && (
                <div className="frame-edge" data-edge="bottom">
                    {bottomLeft === undefined ? (
                        <span />
                    ) : (
                        <span className="frame-label">{bottomLeft}</span>
                    )}
                    {bottomRight !== undefined && (
                        <span className="frame-label">{bottomRight}</span>
                    )}
                </div>
            )}
        </section>
    );
}

export function Note({ children }: { children: ReactNode }) {
    return <div className="text-dim">{children}</div>;
}
