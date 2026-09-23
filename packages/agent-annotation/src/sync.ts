import { createStore } from "./core/store";
import { threadSelection } from "./thread";
import { connectAnnotationRealtime, type AnnotationRealtime } from "./realtime";
import { checkOrbit } from "./orbit";
import { dismissAnnotations, isDismissed, loadAnnotations, saveAnnotations } from "./store";
import type { Annotation } from "./types";

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
export const serviceConnection = createStore<
    "Connecting" | "Connected" | "Unavailable" | "Host integration"
>("Host integration");
let serviceUrl = "";
let generation = 0;
let eventsUrl = "";
let realtime: AnnotationRealtime = {};
let defaults: { serviceUrl: string; realtime: AnnotationRealtime } = {
    serviceUrl: "",
    realtime: {},
};
const configurationListeners = new Set<() => void>();
export type DeliveryMode = "server" | "orbit";
export const deliveryMode = createStore<DeliveryMode>("server");
export const localSessionCount = createStore(0);
function rememberNumber(value: unknown): void {
    if (
        settings.mode !== "server" ||
        typeof value !== "number" ||
        !Number.isSafeInteger(value) ||
        value <= localSessionCount.getSnapshot()
    )
        return;
    localSessionCount.setState(value);
    try {
        sessionStorage.setItem(`annotate:counter:${serviceUrl}`, String(value));
    } catch {
        /* Optional storage. */
    }
}
type ServiceSettings = { mode: DeliveryMode; serviceUrl: string };
let settings: ServiceSettings = { mode: "server", serviceUrl: "" };
export function serviceSettings() {
    return { ...settings };
}
export function saveServiceSettings(url: string, mode: DeliveryMode): void {
    if (mode === "server") {
        if (!url.trim()) throw new Error("Enter the annotation server URL.");
        if (!["http:", "https:"].includes(new URL(url, window.location.href).protocol))
            throw new Error("Use an HTTP or HTTPS URL.");
    }
    const override = { mode, serviceUrl: url.trim() };
    try {
        sessionStorage.setItem("annotate:service", JSON.stringify(override));
    } catch {
        /* Optional storage. */
    }
    configureAnnotationService(defaults.serviceUrl, defaults.realtime, override);
}
const revisions = new Map<string, number>();
export function configureAnnotationService(
    url?: string,
    options: AnnotationRealtime = {},
    override?: ServiceSettings,
): void {
    defaults = { serviceUrl: url ?? "", realtime: options };
    if (!override) {
        try {
            const saved = JSON.parse(sessionStorage.getItem("annotate:service") ?? "null");
            if (saved && typeof saved.serviceUrl === "string") {
                override = {
                    serviceUrl: saved.serviceUrl,
                    mode:
                        saved.mode === "t3"
                            ? "orbit"
                            : ["server", "orbit"].includes(saved.mode)
                              ? saved.mode
                              : saved.mode === "browser" ||
                                  (saved.serviceUrl && saved.serviceUrl !== (url ?? ""))
                                ? "server"
                                : url
                                  ? "orbit"
                                  : "server",
                };
            }
        } catch {
            /* Optional storage. */
        }
    }
    settings = override ?? { mode: url ? "orbit" : "server", serviceUrl: "" };
    deliveryMode.setState(settings.mode);
    serviceUrl =
        (settings.mode === "server"
            ? settings.serviceUrl
            : settings.mode === "orbit"
              ? url
              : ""
        )?.replace(/\/$/, "") || "";
    if (settings.mode === "server" && serviceUrl)
        serviceUrl = resolveAnnotationServerUrl(serviceUrl);
    localSessionCount.setState(0);
    try {
        rememberNumber(Number(sessionStorage.getItem(`annotate:counter:${serviceUrl}`)));
    } catch {
        /* Optional storage. */
    }
    realtime = settings.mode === "orbit" ? options : {};
    eventsUrl = "";
    serviceConnection.setState(serviceUrl ? "Connecting" : "Host integration");
    generation++;
    revisions.clear();
    configurationListeners.forEach((restart) => restart());
}
/** Use the same transport for connection checks and annotation writes. */
export function resolveAnnotationServerUrl(value: string): string {
    const url = value.trim().replace(/\/$/, "");
    if (!url) throw new Error("Enter an annotation server URL.");
    const target = new URL(url, window.location.href);
    if (!["http:", "https:"].includes(target.protocol))
        throw new Error("Use an HTTP or HTTPS URL.");
    const bridge = document.querySelector<HTMLMetaElement>(
        'meta[name="annotate-local-server-proxy"]',
    )?.content;
    if (
        bridge?.startsWith("/") &&
        !bridge.startsWith("//") &&
        target.protocol === "http:" &&
        ["127.0.0.1", "localhost", "[::1]"].includes(target.hostname) &&
        target.pathname.replace(/\/$/, "") === "/annotations"
    )
        return `${bridge}/${target.port || "80"}/annotations`;
    return url;
}

export async function checkAnnotationServer(url: string, signal: AbortSignal): Promise<void> {
    const target = resolveAnnotationServerUrl(url);
    const response = await fetch(target, {
        signal: AbortSignal.any([signal, AbortSignal.timeout(5000)]),
    });
    if (!response.ok) throw new Error(`Server returned HTTP ${response.status}.`);
    const body = await response.json();
    if (!Array.isArray(body.data ?? body.annotations))
        throw new Error("This URL is not an annotation endpoint.");
}

export function parseAnnotation(value: unknown): Annotation | null {
    if (!value || typeof value !== "object") return null;
    const item = value as Record<string, unknown>;
    if (typeof item.id !== "string" || !item.id || typeof item.comment !== "string") return null;
    return {
        ...item,
        status:
            item.status === "todo" ? "pending" : item.status === "done" ? "resolved" : item.status,
    } as Annotation;
}
function receive(value: unknown): Annotation | null {
    const annotation = parseAnnotation(value);
    if (annotation) rememberNumber(annotation.number);
    if (!annotation || isDismissed(annotation.id)) return null;
    if (
        typeof annotation.revision !== "number" ||
        annotation.revision < (revisions.get(annotation.id) ?? 0)
    )
        return null;
    revisions.set(annotation.id, annotation.revision);
    // Update all paths, including pages not currently mounted, before advancing the event cursor.
    const path = annotation.pathname || window.location.pathname;
    const existing = loadAnnotations(path).filter((a) => a.id !== annotation.id);
    saveAnnotations(
        annotation.status === "resolved" || annotation.status === "cancelled"
            ? existing
            : [...existing, annotation],
        path,
    );
    return annotation;
}
async function request(path: string, annotation?: Annotation): Promise<unknown> {
    const response = await fetch(path, {
        ...(annotation
            ? {
                  method: "POST",
                  headers: { "Content-Type": "application/json" },
                  body: JSON.stringify(annotation),
              }
            : {}),
        signal: AbortSignal.timeout(10000),
    });
    const result = (await response.json()) as { error?: string | { message?: string } };
    if (!response.ok)
        throw new Error(
            (typeof result.error === "string" ? result.error : result.error?.message) ||
                `Annotation service returned HTTP ${response.status}`,
        );
    return result;
}
export async function pushAnnotation(annotation: Annotation): Promise<Annotation | null> {
    const pathname = annotation.pathname ?? window.location.pathname;
    const working: Annotation = {
        ...annotation,
        threadId: settings.mode === "orbit" ? threadSelection.value.id : undefined,
        url: annotation.url ?? window.location.href,
        pathname,
        status: annotation.status ?? "pending",
    };
    const existing = loadAnnotations(pathname).filter((a) => a.id !== annotation.id);
    saveAnnotations([...existing, working], pathname);
    if (settings.mode === "server" && !serviceUrl) {
        const failed = {
            ...working,
            delivery: "error" as const,
            syncError: "Enter the annotation server URL in settings.",
        };
        saveAnnotations([...existing, failed], pathname);
        return failed;
    }
    if (settings.mode === "orbit" && !working.threadId) {
        const failed = {
            ...working,
            delivery: "error" as const,
            syncError: "Enter a T3 thread ID or choose another mode.",
        };
        saveAnnotations([...existing, failed], pathname);
        return failed;
    }
    if (serviceUrl) {
        const current = generation;
        try {
            if (settings.mode === "orbit") {
                const availability = await checkOrbit();
                if (current !== generation) return working;
                if (availability.state !== "available") throw new Error(availability.reason);
            }
            const result = (await request(serviceUrl, working)) as {
                annotation?: unknown;
                data?: unknown;
            };
            return receive(result.annotation ?? result.data);
        } catch (error) {
            const failed = {
                ...working,
                syncError: error instanceof Error ? error.message : "Could not submit annotation",
                delivery: "error" as const,
            };
            if (!isDismissed(failed.id))
                saveAnnotations(
                    [...loadAnnotations(pathname).filter((a) => a.id !== failed.id), failed],
                    pathname,
                );
            return failed;
        }
    }
    const failed = {
        ...working,
        delivery: "error" as const,
        syncError: "No Orbit annotation service configured.",
    };
    saveAnnotations([...existing, failed], pathname);
    return failed;
}
export async function retryAnnotation(annotation: Annotation): Promise<Annotation | null> {
    if (
        settings.mode !== "orbit" ||
        !serviceUrl ||
        !annotation.revision ||
        !threadSelection.value.id
    )
        return pushAnnotation(annotation);
    const current = generation;
    try {
        const availability = await checkOrbit();
        if (current !== generation) return annotation;
        if (availability.state !== "available") throw new Error(availability.reason);
        const response = await fetch(`${serviceUrl}/${encodeURIComponent(annotation.id)}/retry`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                threadId: annotation.threadId || threadSelection.value.id || undefined,
            }),
            signal: AbortSignal.timeout(10000),
        });
        const result = (await response.json()) as {
            annotation?: unknown;
            data?: unknown;
            error?: string | { message?: string };
        };
        if (!response.ok)
            throw new Error(
                (typeof result.error === "string" ? result.error : result.error?.message) ||
                    "Retry failed",
            );
        return receive(result.annotation ?? result.data);
    } catch (error) {
        return {
            ...annotation,
            syncError: error instanceof Error ? error.message : "Retry failed",
        };
    }
}
function summary(annotations: Annotation[]): AnnotationSync {
    return {
        annotations,
        resolvedIds: annotations
            .filter((a) => a.status === "resolved" || a.status === "cancelled")
            .map((a) => a.id),
        inProgressIds: annotations.filter((a) => a.status === "in_progress").map((a) => a.id),
    };
}
export async function fetchAnnotationSync(): Promise<AnnotationSync> {
    const current = generation;
    if (serviceUrl) {
        try {
            const result = (await request(serviceUrl)) as {
                annotations?: unknown[];
                data?: unknown[];
                meta?: { eventsUrl?: string; lastNumber?: number };
            };
            if (current !== generation) return summary([]);
            serviceConnection.setState("Connected");
            rememberNumber(result.meta?.lastNumber);
            if (result.meta?.eventsUrl) {
                const endpoint = new URL(serviceUrl, window.location.href);
                const candidate = new URL(result.meta.eventsUrl, endpoint);
                if (candidate.origin === endpoint.origin) eventsUrl = candidate.href;
            }
            return summary(
                (result.annotations ?? result.data ?? []).flatMap((value) => receive(value) ?? []),
            );
        } catch {
            if (current === generation) serviceConnection.setState("Unavailable");
            /* Keep local annotations visible when the service is offline. */
        }
    }
    return summary(loadAnnotations(window.location.pathname));
}
export function subscribeAnnotationEvents(handlers: AnnotationEventHandlers): () => void {
    let stop = () => {};
    const start = () => {
        stop();
        if (!serviceUrl) return;
        let closed = false;
        let fetching = false;
        let again = false;
        let stream: EventSource | undefined;
        const refresh = async () => {
            if (closed) return;
            if (fetching) {
                again = true;
                return;
            }
            fetching = true;
            const result = await fetchAnnotationSync();
            fetching = false;
            if (closed) return;
            if (eventsUrl && !stream) {
                stream = new EventSource(eventsUrl);
                stream.onmessage = () => void refresh();
            }
            handlers.onCreated?.(result.annotations);
            handlers.onProgress?.(result.inProgressIds);
            handlers.onResolved?.(result.resolvedIds);
            if (again) {
                again = false;
                void refresh();
            }
        };
        let disconnect = () => {};
        if (settings.mode === "orbit") {
            void checkOrbit().then((availability) => {
                if (closed || availability.state !== "available") return;
                disconnect = connectAnnotationRealtime(realtime, () => void refresh());
            });
        }
        const timer = setInterval(() => void refresh(), 15000);
        stop = () => {
            closed = true;
            clearInterval(timer);
            disconnect();
            stream?.close();
        };
        void refresh();
    };
    configurationListeners.add(start);
    start();
    return () => {
        configurationListeners.delete(start);
        stop();
    };
}
export async function deleteSyncedAnnotation(id: string): Promise<void> {
    dismissAnnotations([id]);
    const pathname = window.location.pathname;
    saveAnnotations(
        loadAnnotations(pathname).filter((annotation) => annotation.id !== id),
        pathname,
    );
}
