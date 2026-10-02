import { postDictation } from "./post-dictation";
import { useEffect, useRef, useState } from "react";
import { dictate, type DictationSettings } from "./dictation";
import { setDictationKeyHandlers } from "./dictation-keys";
import { isDictationEnabled, isUnrelatedEditable, mergeTranscript } from "./dictation-settings";
import type { Ref } from "./state";

export type DictationState = "idle" | "recording" | "transcribing" | "triggering";

type Options = {
    comment: Ref<string>;
    autoStart: boolean;
    settings: DictationSettings;
    onBeforeTrigger: () => void;
    onSubmit: () => void;
};

export function useDictation(options: Options) {
    const [state, setState] = useState<DictationState>("idle");
    const [error, setError] = useState<string | null>(null);
    const [emptySpeech, setEmptySpeech] = useState(false);
    const [elapsedMs, setElapsedMs] = useState(0);
    const [levels, setLevels] = useState([0.12, 0.16, 0.14, 0.18]);
    const enabled = isDictationEnabled(options.settings);

    const session = useRef<{
        stop: AbortController | null;
        abort: AbortController | null;
        elapsedTimer: number;
        elapsedStarted: number;
        state: DictationState;
        comment: Ref<string>;
        settings: DictationSettings;
        onSubmit: () => void;
        enabled: boolean;
        disposed: boolean;
    }>({
        stop: null,
        abort: null,
        elapsedTimer: 0,
        elapsedStarted: 0,
        state: "idle",
        comment: options.comment,
        settings: options.settings,
        onSubmit: options.onSubmit,
        enabled,
        disposed: false,
    });

    session.current.comment = options.comment;
    session.current.settings = options.settings;
    session.current.onSubmit = options.onSubmit;
    session.current.enabled = enabled;

    const startElapsed = () => {
        session.current.elapsedStarted = Date.now();
        setElapsedMs(0);
        window.clearInterval(session.current.elapsedTimer);
        session.current.elapsedTimer = window.setInterval(() => {
            setElapsedMs(Date.now() - session.current.elapsedStarted);
        }, 200);
    };

    const stopElapsed = () => {
        window.clearInterval(session.current.elapsedTimer);
        session.current.elapsedTimer = 0;
    };

    const beginStop = (): boolean => {
        if (session.current.state !== "recording" || !session.current.stop) {
            return false;
        }

        stopElapsed();
        session.current.state = "transcribing";
        setState("transcribing");
        session.current.stop.abort();

        return true;
    };

    const cancelSession = () => {
        stopElapsed();
        session.current.abort?.abort();
        session.current.stop = null;
        session.current.abort = null;
        session.current.state = "idle";
        setState("idle");
    };

    const start = async (): Promise<void> => {
        if (
            session.current.disposed ||
            !session.current.enabled ||
            session.current.state !== "idle"
        ) {
            return;
        }

        setEmptySpeech(false);
        setError(null);
        session.current.stop = new AbortController();
        session.current.abort = new AbortController();
        if (session.current.settings.provider === "post") {
            session.current.state = "triggering";
            setState("triggering");
            options.onBeforeTrigger();
            try {
                const response = await postDictation(
                    session.current.settings.postUrl!,
                    AbortSignal.any([session.current.abort.signal, AbortSignal.timeout(10000)]),
                    "dictate",
                );
                if (!response.ok)
                    throw new Error(`Dictation trigger failed (HTTP ${response.status}).`);
            } catch (cause) {
                if (!session.current.disposed) {
                    setError(
                        cause instanceof Error
                            ? `Could not trigger local dictation: ${cause.message}`
                            : "Could not trigger local dictation.",
                    );
                }
            } finally {
                if (!session.current.disposed) {
                    session.current.state = "idle";
                    setState("idle");
                }
                session.current.stop = null;
                session.current.abort = null;
            }
            return;
        }
        session.current.state = "recording";
        setState("recording");
        startElapsed();

        try {
            const text = await dictate({
                wsUrl: session.current.settings.wsUrl!,
                codec: session.current.settings.codec,
                stopSignal: session.current.stop.signal,
                abortSignal: session.current.abort.signal,
                onLevels: (next) => {
                    setLevels(next);
                },
            });

            if (session.current.disposed) {
                return;
            }

            if (text.trim() === "") {
                setEmptySpeech(true);
                session.current.state = "idle";
                setState("idle");
                return;
            }

            session.current.comment.value = mergeTranscript(session.current.comment.value, text);
            session.current.state = "idle";
            setState("idle");
        } catch (cause) {
            if (session.current.disposed) {
                return;
            }

            if (cause instanceof DOMException && cause.name === "AbortError") {
                session.current.state = "idle";
                setState("idle");
                return;
            }

            setError(cause instanceof Error ? cause.message : "Could not start dictation.");
            session.current.state = "idle";
            setState("idle");
        } finally {
            stopElapsed();
            session.current.stop = null;
            session.current.abort = null;
        }
    };

    const toggle = () => {
        if (session.current.state === "recording") {
            beginStop();
            return;
        }

        if (session.current.state === "idle") {
            void start();
        }
    };

    const onEnter = (): boolean => {
        if (beginStop()) {
            return true;
        }

        if (!session.current.comment.value.trim()) {
            return false;
        }

        session.current.onSubmit();

        return true;
    };

    const onEscape = (): boolean => false;

    useEffect(() => {
        session.current.disposed = false;
        setDictationKeyHandlers({
            onEnter: () => {
                if (isUnrelatedEditable(document.activeElement)) {
                    return false;
                }

                return onEnter();
            },
            onEscape,
        });

        if (enabled && options.autoStart) {
            void start();
        }

        return () => {
            session.current.disposed = true;
            setDictationKeyHandlers(null);
            cancelSession();
        };
        // Mount-only, matching the Vue composable.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return {
        enabled,
        state,
        emptySpeech,
        error,
        elapsedMs,
        levels,
        toggle,
        onEnter,
        onEscape,
    };
}
