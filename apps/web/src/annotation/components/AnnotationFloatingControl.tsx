import { ChatBubbleBottomCenterTextIcon } from "@heroicons/react/16/solid";
import { annotationMode, annotations, toggleAnnotationMode } from "@/annotation/runtime";
import { useRefValue } from "@/annotation/state";
import { cn } from "@/lib/utils";

/**
 * Agentation-like floating annotate control — always visible in the annotation
 * shadow host so mode can be entered without hunting the footer ✎.
 */
export function AnnotationFloatingControl() {
    const isActive = useRefValue(annotationMode);
    const count = useRefValue(annotations).length;

    return (
        <div
            data-feedback-toolbar=""
            data-orbit-annotation-fab=""
            className="pointer-events-auto fixed right-5 bottom-5 z-[2147483646]"
        >
            <button
                type="button"
                data-orbit-annotation-chrome=""
                data-active={isActive ? "" : undefined}
                aria-pressed={isActive}
                aria-label={isActive ? "Exit annotation mode" : "Enter annotation mode"}
                title={isActive ? "Annotation mode on (Esc to exit)" : "Annotate the page (A)"}
                className={cn(
                    "inline-flex items-center gap-1.5 rounded-full border border-white/10 px-3.5 py-2.5 text-sm font-medium shadow-lg backdrop-blur-xl transition-colors",
                    isActive
                        ? "bg-white text-[#111111] hover:bg-white/90"
                        : "bg-[#111111]/92 text-white hover:bg-[#1a1a1a]",
                )}
                onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleAnnotationMode();
                }}
                onMouseDown={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                }}
            >
                <ChatBubbleBottomCenterTextIcon className="size-4" aria-hidden="true" />
                <span className="leading-none">{isActive ? "Annotating" : "Annotate"}</span>
                {count > 0 ? (
                    <span
                        className={cn(
                            "ml-0.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full px-1.5 text-xs leading-5",
                            isActive ? "bg-black/10 text-[#111]" : "bg-white/15 text-white",
                        )}
                    >
                        {count}
                    </span>
                ) : null}
            </button>
        </div>
    );
}
