import type { Annotation } from "./types";

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

        return data.filter(
            (annotation) =>
                !isDismissed(annotation.id) &&
                (!annotation.timestamp || annotation.timestamp > cutoff),
        );
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

/** Remove only this tool's records, across all paths on the current origin. */
export function clearStoredAnnotations(): void {
    try {
        const keys = Object.keys(localStorage).filter((key) => key.startsWith(STORAGE_PREFIX));
        for (const key of keys) {
            dismissAnnotations(
                loadAnnotations(key.slice(STORAGE_PREFIX.length)).map(
                    (annotation) => annotation.id,
                ),
            );
            localStorage.removeItem(key);
        }
    } catch {
        // Keep the UI usable when browser storage is unavailable.
    }
}

/** A successful server snapshot owns all pins; missing records are not tombstones. */
export function reconcileServerAnnotations(records: Annotation[]): string[] {
    const present = new Set(records.map((record) => record.id));
    const removed: string[] = [];
    try {
        for (const key of Object.keys(localStorage).filter((key) =>
            key.startsWith(STORAGE_PREFIX),
        )) {
            const path = key.slice(STORAGE_PREFIX.length);
            const cached = loadAnnotations(path);
            removed.push(
                ...cached.filter((record) => !present.has(record.id)).map((record) => record.id),
            );
            saveAnnotations(
                cached.filter((record) => present.has(record.id)),
                path,
            );
        }
    } catch {
        /* Optional storage. */
    }
    return removed;
}

const dismissedKey = "annotate:dismissed";
export function isDismissed(id: string): boolean {
    try {
        return (JSON.parse(localStorage.getItem(dismissedKey) || "[]") as string[]).includes(id);
    } catch {
        return false;
    }
}
export function dismissAnnotations(ids: string[]): void {
    try {
        const existing = JSON.parse(localStorage.getItem(dismissedKey) || "[]") as string[];
        localStorage.setItem(dismissedKey, JSON.stringify([...new Set([...existing, ...ids])]));
    } catch {
        /* Keep local removal usable when storage is disabled. */
    }
}
