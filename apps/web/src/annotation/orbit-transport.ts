import type { Annotation, AnnotationTransport, TransportAvailability } from "@nckrtl/annotator";
import { subscribeAnnotationUpdates } from "../realtime/annotations";
import { isLive } from "../realtime/liveness";

/** An annotation as Orbit stores it: the overlay record plus the thread that receives the task. */
export type OrbitAnnotation = Annotation & { threadId?: string };

export type OrbitTransportOptions = {
    /** The Instance annotation endpoint. */
    serviceUrl: string;
    /** Defaults to `/api/v1/tasks/status` on the annotation service origin. */
    tasksStatusUrl?: string;
    /** Thread chosen by the host; a thread saved in this tab wins. */
    threadId?: string;
    /** Development endpoint that detects the thread for this worktree. */
    threadDiscoveryUrl?: string;
};

type Thread = { id: string; automaticId: string; manual: boolean; status: string };

const threadKey = "annotate:t3-thread";

/** Delivers annotations as Orbit tasks for an existing thread. */
export function orbitTransport(options: OrbitTransportOptions): AnnotationTransport {
    const listeners = new Set<() => void>();
    let thread: Thread = { id: "", automaticId: "", manual: false, status: "No thread detected" };
    const setThread = (next: Thread) => {
        thread = next;
        listeners.forEach((listener) => listener());
    };
    let pending: Promise<TransportAvailability> | undefined;

    let stored: string | null = null;
    try {
        stored = sessionStorage.getItem(threadKey);
    } catch {
        /* Storage can be disabled. */
    }
    const configured = options.threadId?.trim() || "";
    setThread({
        id: stored ?? configured,
        automaticId: configured,
        manual: stored !== null,
        status: configured ? "Configured by host" : "No thread detected",
    });
    if (!configured && options.threadDiscoveryUrl) discoverThread(options.threadDiscoveryUrl);

    function discoverThread(url: string) {
        setThread({ ...thread, status: "Detecting thread…" });
        void fetch(url, { signal: AbortSignal.timeout(5000) })
            .then(async (response) => {
                if (!response.ok) throw new Error("Discovery unavailable");
                return (await response.json()) as { id?: unknown; title?: unknown; status?: unknown };
            })
            .then((result) => {
                const automaticId = typeof result.id === "string" ? result.id : "";
                setThread({
                    ...thread,
                    automaticId,
                    id: thread.manual ? thread.id : automaticId,
                    status: automaticId
                        ? `Detected: ${typeof result.title === "string" ? result.title : automaticId}`
                        : result.status === "ambiguous"
                          ? "Multiple threads found. Enter a thread ID."
                          : "No thread detected",
                });
            })
            .catch(() => setThread({ ...thread, status: "Thread detection unavailable" }));
    }

    function selectThread(value: string) {
        try {
            sessionStorage.setItem(threadKey, value);
        } catch {
            /* Optional storage. */
        }
        setThread({ ...thread, manual: true, id: value });
    }

    /** Read-only checks; never enable extensions or send an annotation. */
    function check(): Promise<TransportAvailability> {
        pending ??= (async (): Promise<TransportAvailability> => {
            try {
                const endpoint = new URL(options.serviceUrl, window.location.href);
                const tasksUrl = new URL(options.tasksStatusUrl ?? "/api/v1/tasks/status", endpoint);
                const init = {
                    headers: { Accept: "application/json" },
                    signal: AbortSignal.timeout(5000),
                    cache: "no-store" as const,
                };
                const tasks = await fetch(tasksUrl, init);
                if (!tasks.ok) throw new Error(`Cannot check Orbit tasks (HTTP ${tasks.status}).`);
                const status = await tasks.json();
                if (typeof status.data?.enabled !== "boolean")
                    throw new Error("Invalid Orbit tasks status response.");
                if (!status.data.enabled) throw new Error("Enable the tasks extension in Orbit.");
                const annotations = await fetch(endpoint, init);
                if (!annotations.ok)
                    throw new Error(`Cannot access Orbit annotations (HTTP ${annotations.status}).`);
                if (!Array.isArray((await annotations.json()).data))
                    throw new Error("Invalid Orbit annotation endpoint.");
                return { state: "available", reason: "Orbit available" };
            } catch (error) {
                return {
                    state: "unavailable",
                    reason:
                        error instanceof TypeError
                            ? "Cannot reach Orbit. Check the connection."
                            : error instanceof Error
                              ? error.message
                              : "Cannot check Orbit availability.",
                };
            }
        })().finally(() => {
            pending = undefined;
        });
        return pending;
    }

    async function send(path: string, body?: unknown): Promise<unknown> {
        const response = await fetch(path, {
            ...(body === undefined
                ? {}
                : {
                      method: "POST",
                      headers: { "Content-Type": "application/json" },
                      body: JSON.stringify(body),
                  }),
            signal: AbortSignal.timeout(10000),
        });
        const result = (await response.json()) as {
            data?: unknown;
            annotation?: unknown;
            error?: string | { message?: string };
        };
        if (!response.ok)
            throw new Error(
                (typeof result.error === "string" ? result.error : result.error?.message) ||
                    `Orbit returned HTTP ${response.status}`,
            );
        return result.annotation ?? result.data;
    }

    const serviceUrl = options.serviceUrl.replace(/\/$/, "");

    return {
        id: "orbit",
        label: "Orbit",
        check,
        submit: async (annotation) => {
            if (!thread.id) throw new Error("Enter a T3 thread ID or choose another mode.");
            return send(serviceUrl, { ...annotation, threadId: thread.id });
        },
        list: async () => {
            const records = await send(serviceUrl);
            if (!Array.isArray(records)) throw new Error("Invalid annotation list");
            return records;
        },
        retry: async (annotation: OrbitAnnotation) => {
            const threadId = annotation.threadId || thread.id;
            if (!threadId) throw new Error("Enter a T3 thread ID or choose another mode.");
            return send(`${serviceUrl}/${encodeURIComponent(annotation.id)}/retry`, { threadId });
        },
        subscribe: (refresh) => ({ stop: subscribeAnnotationUpdates(refresh), live: isLive }),
        fields: [
            {
                id: "annotate-thread-id",
                label: "T3 thread ID",
                placeholder: "Enter thread ID",
                value: () => thread.id,
                save: selectThread,
                status: () => (thread.manual ? "Saved for this tab" : thread.status),
                subscribe: (listener) => {
                    listeners.add(listener);
                    return () => listeners.delete(listener);
                },
            },
        ],
    };
}
