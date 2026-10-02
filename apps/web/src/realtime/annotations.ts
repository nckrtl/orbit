/** Fan out annotation notifications from Orbit's single authenticated connection. */
const listeners = new Set<() => void>();
export function subscribeAnnotationUpdates(refresh: () => void): () => void {
    listeners.add(refresh);
    return () => {
        listeners.delete(refresh);
    };
}
export function notifyAnnotationUpdates(): void {
    listeners.forEach((refresh) => refresh());
}
