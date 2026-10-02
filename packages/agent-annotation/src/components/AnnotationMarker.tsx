import { deliveryMode } from "../sync";
import { useStore } from "../core/store";
import type { CSSProperties, MouseEvent } from "react";
import { useAnnotationAccent } from "../accent";
import { percentToViewportX } from "../dom";
import { useRefValue, viewportTick } from "../state";
import type { Annotation } from "../types";

type AnnotationMarkerProps = {
    annotation: Annotation;
    index: number;
    active?: boolean;
    onEdit: (annotation: Annotation, clientX: number, clientY: number) => void;
};

export default function AnnotationMarker({
    annotation,
    index,
    active = false,
    onEdit,
}: AnnotationMarkerProps) {
    const mode = useStore(deliveryMode);
    useRefValue(viewportTick);
    const { color: accentColor, textColor: accentTextColor } = useAnnotationAccent();
    const inProgress = annotation.status === "in_progress";
    const applied = annotation.status === "applied";
    const left = percentToViewportX(annotation.x);
    const top = annotation.isFixed ? annotation.y : annotation.y - window.scrollY;

    const onClick = (event: MouseEvent<HTMLButtonElement>) => {
        event.preventDefault();
        event.stopPropagation();
        onEdit(annotation, event.clientX, event.clientY);
    };

    return (
        <div
            className="absolute z-1 size-[22px] -translate-x-1/2 -translate-y-1/2"
            style={{ left: `${left}px`, top: `${top}px` }}
        >
            {inProgress ? (
                <>
                    <span
                        aria-hidden
                        className="toolbar-annotation-marker-pulse"
                        style={{ "--toolbar-annotation-pulse": accentColor } as CSSProperties}
                    />
                    <span
                        aria-hidden
                        className="toolbar-annotation-marker-pulse toolbar-annotation-marker-pulse-delay"
                        style={{ "--toolbar-annotation-pulse": accentColor } as CSSProperties}
                    />
                </>
            ) : null}

            <button
                type="button"
                data-annotation-marker
                className="pointer-events-auto relative flex size-full items-center justify-center rounded-full text-xxs font-semibold text-white"
                style={{
                    backgroundColor: active
                        ? `color-mix(in srgb, ${accentColor} 85%, black)`
                        : accentColor,
                    color: accentTextColor,
                    boxShadow: "0 2px 6px rgba(0, 0, 0, 0.2), inset 0 0 0 1px rgba(0, 0, 0, 0.04)",
                }}
                title={
                    annotation.syncError
                        ? mode === "server"
                            ? "Could not save annotation to the local server."
                            : `Delivery failed: ${annotation.syncError}`
                        : inProgress
                          ? `In progress: ${annotation.comment}`
                          : applied
                            ? `Applied: ${annotation.comment}`
                            : annotation.comment
                }
                aria-label={`${inProgress ? "In progress annotation" : applied ? "Applied annotation" : "Edit annotation"} ${index}`}
                onClick={onClick}
            >
                {index}
            </button>
        </div>
    );
}
