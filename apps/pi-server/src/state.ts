/** The five Orbit thread states from ADR 0112. Pi never reports `asking_for_input`. */
export type ThreadState = "idle" | "working" | "done" | "failed";

export interface StateInput {
    /** A turn was accepted and Pi has not settled it yet. */
    working: boolean;
    /** The server restarted while the latest turn was active. */
    interruptedByRestart: boolean;
    /** The last assistant message in the transcript, if any. */
    lastAssistant: { stopReason: string; errorMessage?: string | undefined } | undefined;
}

export interface DerivedState {
    state: ThreadState;
    error: string | null;
}

/**
 * Maps Pi evidence to an Orbit thread state. Only a normal stop counts as success; every other
 * reason a settled turn can end with is a reported failure, never inferred idleness.
 */
export function deriveState(input: StateInput): DerivedState {
    if (input.working) {
        return { state: "working", error: null };
    }
    if (input.interruptedByRestart) {
        return { state: "failed", error: "The Pi server restarted during the turn." };
    }
    const last = input.lastAssistant;
    if (last === undefined) {
        return { state: "idle", error: null };
    }

    switch (last.stopReason) {
        case "stop":
            return { state: "done", error: null };
        case "aborted":
            return { state: "failed", error: "The turn was interrupted." };
        case "length":
            return { state: "failed", error: "The model reached its output limit." };
        case "error":
            return {
                state: "failed",
                error: last.errorMessage ?? "The model provider reported an error.",
            };
        default:
            return {
                state: "failed",
                error: `The turn ended with stop reason "${last.stopReason}".`,
            };
    }
}
