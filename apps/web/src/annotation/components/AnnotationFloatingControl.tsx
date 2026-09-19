import { ChatBubbleBottomCenterTextIcon } from "@heroicons/react/16/solid";
import { annotationMode, annotations, toggleAnnotationMode } from "@/annotation/runtime";
import { useRefValue } from "@/annotation/state";
import { cn } from "@/lib/utils";

/** Floating annotate icon in the annotation shadow host (icon only). */
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
                    "relative inline-flex size-11 items-center justify-center rounded-full border border-white/10 shadow-lg backdrop-blur-xl transition-colors",
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
                <ChatBubbleBottomCenterTextIcon className="size-5" aria-hidden="true" />
                {count > 0 ? (
                    <span
                        className={cn(
                            "absolute -top-1 -right-1 inline-flex min-w-[1.1rem] items-center justify-center rounded-full px-1 text-[0.65rem] leading-4",
                            isActive ? "bg-black/15 text-[#111]" : "bg-white text-[#111]",
                        )}
                    >
                        {count}
                    </span>
                ) : null}
            </button>
        </div>
    );
}
