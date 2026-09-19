import { useEffect } from "react";
import { cancelDraft, deleteDraft, startEdit, submitDraft } from "@/annotation/actions";
import AnnotationHighlight from "@/annotation/components/AnnotationHighlight";
import AnnotationMarker from "@/annotation/components/AnnotationMarker";
import AnnotationPopup from "@/annotation/components/AnnotationPopup";
import { AnnotationFloatingControl } from "@/annotation/components/AnnotationFloatingControl";
import { useAnnotationAccent } from "@/annotation/accent";
import { getAnnotationRootElement } from "@/annotation/host";
import { hoverLabelPosition, percentToViewportX } from "@/annotation/dom";
import {
    annotationMode,
    annotations,
    draft,
    hover,
    shakeToken,
    useRefValue,
    viewportTick,
} from "@/annotation/state";
import { useToolbar } from "@/composables/useToolbar";
import { resolveToolbarFontSize } from "@/core/font-size";

export default function AnnotationOverlay() {
    const isActive = useRefValue(annotationMode);
    const currentAnnotations = useRefValue(annotations);
    const currentDraft = useRefValue(draft);
    const currentHover = useRefValue(hover);
    const currentShake = useRefValue(shakeToken);
    useRefValue(viewportTick);
    const { data } = useToolbar();
    const fontSize = resolveToolbarFontSize(data.font_size);
    const { color: accentColor, textColor: accentTextColor } = useAnnotationAccent();

    useEffect(() => {
        const root = getAnnotationRootElement();
        root?.setAttribute("data-toolbar-font-size", fontSize);
    }, [fontSize]);

    const selectedRect = (() => {
        if (!currentDraft) {
            return null;
        }

        if (currentDraft.targetElement && document.contains(currentDraft.targetElement)) {
            const rect = currentDraft.targetElement.getBoundingClientRect();

            return { x: rect.left, y: rect.top, width: rect.width, height: rect.height };
        }

        const box = currentDraft.boundingBox;

        if (!box || (box.width === 0 && box.height === 0)) {
            return null;
        }

        return {
            x: box.x,
            y: box.y,
            width: box.width,
            height: box.height,
        };
    })();

    const hoverLabelStyle =
        currentHover && !currentDraft
            ? hoverLabelPosition(
                  currentHover.cursorX,
                  currentHover.cursorY,
                  currentHover.reactComponents ? 48 : 32,
              )
            : null;

    const pendingMarkerStyle =
        currentDraft && !currentDraft.annotationId
            ? {
                  left: `${percentToViewportX(currentDraft.x)}px`,
                  top: `${currentDraft.isFixed ? currentDraft.y : currentDraft.y - window.scrollY}px`,
              }
            : null;

    return (
        <div className="contents">
            <AnnotationFloatingControl />
            {!isActive ? null : (
                <>
                    {currentHover && !currentDraft ? (
                        <AnnotationHighlight rect={currentHover.rect} />
                    ) : null}
                    {selectedRect ? <AnnotationHighlight rect={selectedRect} strong /> : null}

                    {currentHover && !currentDraft && hoverLabelStyle ? (
                        <div
                            data-annotation-hover-label
                            className="pointer-events-none absolute z-2 max-w-[280px] overflow-hidden rounded-[6px] border border-white/8 bg-[#101010]/95 px-2.5 py-[0.35rem] font-medium text-white shadow-none backdrop-blur-sm"
                            style={{
                                left: `${hoverLabelStyle.left}px`,
                                top: `${hoverLabelStyle.top}px`,
                            }}
                        >
                            {currentHover.reactComponents ? (
                                <div
                                    data-annotation-hover-react
                                    className="mb-[0.15rem] overflow-hidden text-[0.625rem] text-ellipsis whitespace-nowrap text-white/60"
                                >
                                    {currentHover.reactComponents}
                                </div>
                            ) : null}
                            <div className="overflow-hidden text-ellipsis whitespace-nowrap">
                                {currentHover.name}
                            </div>
                        </div>
                    ) : null}

                    {pendingMarkerStyle ? (
                        <div
                            data-annotation-plus
                            className="toolbar-annotation-marker-in pointer-events-none absolute z-1 flex size-[22px] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full text-white"
                            style={{
                                ...pendingMarkerStyle,
                                backgroundColor: accentColor,
                                color: accentTextColor,
                                boxShadow:
                                    "0 2px 6px rgba(0, 0, 0, 0.2), inset 0 0 0 1px rgba(0, 0, 0, 0.04)",
                            }}
                            aria-hidden="true"
                        >
                            <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                                <path
                                    d="M8 3v10M3 8h10"
                                    stroke="currentColor"
                                    strokeWidth="1.5"
                                    strokeLinecap="round"
                                />
                            </svg>
                        </div>
                    ) : null}

                    {currentAnnotations.map((annotation, index) => (
                        <AnnotationMarker
                            key={annotation.id}
                            annotation={annotation}
                            index={index + 1}
                            active={currentDraft?.annotationId === annotation.id}
                            onEdit={startEdit}
                        />
                    ))}

                    {currentDraft ? (
                        <AnnotationPopup
                            key={currentDraft.annotationId ?? "draft"}
                            draft={currentDraft}
                            shakeToken={currentShake}
                            onSubmit={(comment) => submitDraft(comment, currentDraft)}
                            onCancel={cancelDraft}
                            onDelete={deleteDraft}
                        />
                    ) : null}
                </>
            )}
        </div>
    );
}
