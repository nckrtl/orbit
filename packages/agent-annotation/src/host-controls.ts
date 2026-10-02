import { annotationMode, annotations } from "./state";
import {
    deliveryMode,
    localSessionCount,
    serviceConnection,
    removalError,
    serviceSettings,
    saveServiceSettings,
    type DeliveryMode,
} from "./sync";
import { orbitAvailability } from "./orbit";
import { threadSelection, selectThread } from "./thread";

const stores = [
    annotationMode.store,
    annotations.store,
    deliveryMode,
    localSessionCount,
    serviceConnection,
    removalError,
    orbitAvailability,
    threadSelection.store,
];
function snapshot() {
    const visibleCount = annotations.value.length;
    return {
        active: annotationMode.value,
        count:
            deliveryMode.getSnapshot() === "server"
                ? Math.max(localSessionCount.getSnapshot(), visibleCount)
                : visibleCount,
        /** Open annotations on the current page. */
        pageCount: visibleCount,
        mode: deliveryMode.getSnapshot(),
        connection: serviceConnection.getSnapshot(),
        removalError: removalError.getSnapshot(),
        orbit: orbitAvailability.getSnapshot(),
        thread: threadSelection.value,
    };
}
let cached = snapshot();
/** Stable snapshots can be consumed by any framework, without sharing React. */
export function getAnnotationState() {
    const next = snapshot();
    if (
        Object.keys(next).some(
            (key) => next[key as keyof typeof next] !== cached[key as keyof typeof cached],
        )
    )
        cached = next;
    return cached;
}
export function subscribeAnnotationState(listener: () => void): () => void {
    const unsubscribe = stores.map((store) => store.subscribe(listener));
    return () => unsubscribe.forEach((stop) => stop());
}
export function getAnnotationSettings() {
    return { ...serviceSettings(), threadId: threadSelection.value.id };
}
export function saveAnnotationSettings(settings: {
    mode: DeliveryMode;
    serviceUrl: string;
    threadId?: string;
}): void {
    saveServiceSettings(settings.serviceUrl, settings.mode);
    if (settings.threadId !== undefined) selectThread(settings.threadId);
}
