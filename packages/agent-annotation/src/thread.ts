import { createRef } from "./state";

export type ThreadOptions = { id?: string; discoveryUrl?: string };
const storageKey = "annotate:t3-thread";
export const threadSelection = createRef({
    id: "",
    automaticId: "",
    manual: false,
    status: "No thread detected",
});
let generation = 0;

export function selectThread(id: string | null): void {
    const manual = id !== null;
    const value = id?.trim() || "";
    try {
        if (manual) sessionStorage.setItem(storageKey, value);
        else sessionStorage.removeItem(storageKey);
    } catch {
        /* Storage can be disabled by the host. */
    }
    threadSelection.value = {
        ...threadSelection.value,
        manual,
        id: manual ? value : threadSelection.value.automaticId,
    };
}

export function configureThread(options: ThreadOptions = {}): void {
    const request = ++generation;
    let stored: string | null = null;
    try {
        stored = sessionStorage.getItem(storageKey);
    } catch {
        /* Optional storage. */
    }
    const id = options.id?.trim() || "";
    threadSelection.value = {
        id: stored ?? id,
        automaticId: id,
        manual: stored !== null,
        status: id ? "Configured by host" : "No thread detected",
    };
    if (id || !options.discoveryUrl) return;
    threadSelection.value = { ...threadSelection.value, status: "Detecting thread…" };
    void fetch(options.discoveryUrl, { signal: AbortSignal.timeout(5000) })
        .then(async (response) => {
            if (!response.ok) throw new Error("Discovery unavailable");
            return (await response.json()) as { id?: unknown; title?: unknown; status?: unknown };
        })
        .then((result) => {
            if (request !== generation) return;
            const automaticId = typeof result.id === "string" ? result.id : "";
            const state = threadSelection.value;
            threadSelection.value = {
                ...state,
                automaticId,
                id: state.manual ? state.id : automaticId,
                status: automaticId
                    ? `Detected: ${typeof result.title === "string" ? result.title : automaticId}`
                    : result.status === "ambiguous"
                      ? "Multiple threads found. Enter a thread ID."
                      : "No thread detected",
            };
        })
        .catch(() => {
            if (request === generation)
                threadSelection.value = {
                    ...threadSelection.value,
                    status: "Thread detection unavailable",
                };
        });
}
