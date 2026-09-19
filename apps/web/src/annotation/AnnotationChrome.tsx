import { useEffect } from "react";
import {
    annotationMode,
    annotations,
    ensureAnnotationRuntime,
    teardownAnnotationRuntime,
    toggleAnnotationMode,
} from "@/annotation/runtime";
import { useRefValue } from "@/annotation/state";
import { configureCommander } from "@/annotation/commander";

export type AnnotationChromeProps = {
    /** Commander project for one-shot tasks (TOOLBAR_COMMANDER_PROJECT / VITE_COMMANDER_PROJECT). */
    commanderProject?: string;
    commanderEnabled?: boolean;
};

/**
 * Footer chrome matching laravel-toolbar tools/Annotation.tsx: chat icon, toggle mode, open count.
 */
export function AnnotationChrome({
    commanderProject = import.meta.env.VITE_COMMANDER_PROJECT || "commander",
    commanderEnabled = import.meta.env.VITE_COMMANDER_ENABLED !== "0",
}: AnnotationChromeProps) {
    const isActive = useRefValue(annotationMode);
    const count = useRefValue(annotations).length;

    useEffect(() => {
        configureCommander({
            enabled: commanderEnabled,
            project: commanderProject,
        });
        ensureAnnotationRuntime();
        return () => {
            teardownAnnotationRuntime();
        };
    }, [commanderEnabled, commanderProject]);

    return (
        <button
            type="button"
            data-orbit-annotation-chrome=""
            data-active={isActive ? "" : undefined}
            aria-pressed={isActive}
            aria-label={isActive ? "Exit annotation mode" : "Enter annotation mode"}
            title={isActive ? "Annotation mode on (Esc to exit)" : "Annotate the page (A)"}
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
