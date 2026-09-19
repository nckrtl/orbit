import type { ReactNode } from "react";

type FrameProps = {
    title?: ReactNode;
    hotkey?: number;
    topRight?: ReactNode;
    bottomLeft?: ReactNode;
    bottomRight?: ReactNode;
    state?: "hovered" | "focused" | "warn";
    className?: string;
    bodyClassName?: string;
    children: ReactNode;
    onMouseDown?: () => void;
    /** The name the keyboard knows this frame by, so the arrows can find it on the screen. */
    pane?: string;
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
    children,
    onMouseDown,
    pane,
}: FrameProps) {
    return (
        <section
            className={`frame ${className}`}
            aria-label={typeof title === "string" ? title : undefined}
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
            <div className={`frame-body ${bodyClassName}`}>{children}</div>
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
