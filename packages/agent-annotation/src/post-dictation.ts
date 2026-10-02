/** Keep only the latest measurement for each phase; never record dictation text. */
export function recordDictationTiming(name: string, start: number): void {
    performance.clearMeasures(name);
    performance.measure(name, { start, end: performance.now() });
}

export async function postDictation(
    url: string,
    signal: AbortSignal,
    phase: "dictate" | "dictate-stop",
): Promise<Response> {
    const start = performance.now();
    try {
        return await fetch(url, { method: "POST", credentials: "omit", signal });
    } finally {
        recordDictationTiming(`annotate:${phase}-request`, start);
    }
}
