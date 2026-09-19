import { submitOneShotTask } from "@/annotation/commander";
import { loadAnnotations, saveAnnotations } from "@/annotation/store";
import type { Annotation } from "@/annotation/types";

export type AnnotationSync = {
    annotations: Annotation[];
    resolvedIds: string[];
    inProgressIds: string[];
};

export type AnnotationChangeTargets = Record<string, string>;

export type AnnotationEventHandlers = {
    onCreated?: (annotations: Annotation[]) => void;
    onResolved?: (ids: string[], toById?: AnnotationChangeTargets) => void;
    onDeleted?: (ids: string[]) => void;
    onProgress?: (ids: string[], toById?: AnnotationChangeTargets) => void;
    onPending?: (ids: string[]) => void;
};

export function parseAnnotation(value: unknown): Annotation | null {
    if (!value || typeof value !== "object") {
        return null;
    }

    const item = value as Record<string, unknown>;

    if (typeof item.id !== "string" || item.id === "") {
        return null;
    }

    if (typeof item.comment !== "string") {
        return null;
    }

    return item as Annotation;
}

/**
 * Persist locally and hand the pin to Commander as a kind=one-shot task
 * (same intent as toolbar AnnotationController → VisualChangeService → CommanderClient).
 */
export async function pushAnnotation(annotation: Annotation): Promise<Annotation | null> {
    const pathname = annotation.pathname ?? window.location.pathname;
    const existing = loadAnnotations(pathname).filter((item) => item.id !== annotation.id);
    const working: Annotation = {
        ...annotation,
        url: annotation.url ?? window.location.href,
        pathname,
        status: annotation.status ?? "in_progress",
    };
    saveAnnotations([...existing, working], pathname);

    const result = await submitOneShotTask(working);
    if (!result.ok) {
        // Keep the pin locally even if Commander is unreachable; surface via console for now.
        console.warn("[orbit annotation] Commander one-shot failed:", result.error);
        return { ...working, status: "pending" };
    }

    return working;
}

export async function fetchAnnotationSync(): Promise<AnnotationSync> {
    const annotations = loadAnnotations(window.location.pathname);
    return {
        annotations,
        resolvedIds: annotations.filter((a) => a.status === "resolved").map((a) => a.id),
        inProgressIds: annotations.filter((a) => a.status === "in_progress").map((a) => a.id),
    };
}

/** No Laravel SSE in Orbit SPA; lifecycle is localStorage + Commander task status later. */
export function subscribeAnnotationEvents(_handlers: AnnotationEventHandlers): () => void {
    return () => undefined;
}

export async function deleteSyncedAnnotation(id: string): Promise<void> {
    const pathname = window.location.pathname;
    saveAnnotations(
        loadAnnotations(pathname).filter((annotation) => annotation.id !== id),
        pathname,
    );
}
