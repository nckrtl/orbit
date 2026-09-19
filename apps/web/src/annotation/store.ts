import type { Annotation } from "@/annotation/types";

export const STORAGE_PREFIX = "toolbar-annotations-";
export const RETENTION_MS = 7 * 24 * 60 * 60 * 1000;

export function storageKey(pathname: string): string {
    return `${STORAGE_PREFIX}${pathname}`;
}

export function loadAnnotations(pathname: string = window.location.pathname): Annotation[] {
    if (typeof window === "undefined") {
        return [];
    }

    try {
        const stored = localStorage.getItem(storageKey(pathname));

        if (!stored) {
            return [];
        }

        const data = JSON.parse(stored) as Annotation[];
        const cutoff = Date.now() - RETENTION_MS;

        return data.filter((annotation) => !annotation.timestamp || annotation.timestamp > cutoff);
    } catch {
        return [];
    }
}

export function saveAnnotations(
    annotations: Annotation[],
    pathname: string = window.location.pathname,
): void {
    if (typeof window === "undefined") {
        return;
    }

    try {
        localStorage.setItem(storageKey(pathname), JSON.stringify(annotations));
    } catch {
        // Quota or private-mode failures should not break annotation mode.
    }
}

export function createAnnotationId(): string {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `ann-${Date.now()}-${Math.random().toString(16).slice(2)}`
    );
}
