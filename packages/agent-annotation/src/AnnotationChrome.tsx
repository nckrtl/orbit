import { annotationMode, annotations, toggleAnnotationMode } from "./runtime";
import { useRefValue } from "./state";
import { useStore } from "./core/store";
import { deliveryMode, localSessionCount } from "./sync";

export type AnnotationChromeProps = {
    /** Kept for call-site compatibility; commander is configured at app entry. */
    commanderProject?: string;
    commanderEnabled?: boolean;
};

/**
 * Optional footer chrome — toggles the same annotation mode as the floating FAB.
 * Runtime mount/teardown lives in main.tsx / ensureAnnotationRuntime (once).
 */
export function AnnotationChrome(_props: AnnotationChromeProps = {}) {
    const isActive = useRefValue(annotationMode);
    const visibleCount = useRefValue(annotations).length;
    const mode = useStore(deliveryMode);
    const sessionCount = useStore(localSessionCount);
    const count = mode === "server" ? Math.max(sessionCount, visibleCount) : visibleCount;

    return (
        <button
            type="button"
            data-feedback-toolbar=""
            data-orbit-annotation-chrome=""
            data-active={isActive ? "" : undefined}
            aria-pressed={isActive}
            aria-label={isActive ? "Exit annotation mode" : "Enter annotation mode"}
            title={isActive ? "Annotation mode on (Esc to exit)" : "Annotate the page"}
            className={`inline-flex items-center gap-[0.5ch] rounded px-[0.5ch] ${
                isActive ? "bg-fg text-bg" : "hover:text-fg"
            }`}
            onClick={() => toggleAnnotationMode()}
        >
            <span aria-hidden="true">✎</span>
            {count > 0 ? <span>{count}</span> : null}
        </button>
    );
}
