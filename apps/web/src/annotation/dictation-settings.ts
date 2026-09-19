import { dictationEnabled, type DictationSettings } from "@/annotation/dictation";

type DictationInput = {
    provider?: string;
    wsUrl?: string;
    ws_url?: string;
    codec?: string;
    autoStart?: boolean;
    auto_start?: boolean;
};

declare global {
    interface Window {
        __TOOLBAR_AGENTATION__?: {
            dictation?: DictationInput;
        };
    }
}

const DEFAULTS: Required<Pick<DictationSettings, "provider" | "wsUrl" | "codec" | "autoStart">> = {
    provider: "diction",
    wsUrl: "wss://diction.orbit/v1/audio/stream",
    codec: "auto",
    autoStart: true,
};

function normalize(input?: DictationInput | null): DictationSettings {
    if (!input) {
        return {};
    }

    return {
        provider: input.provider,
        wsUrl: input.wsUrl ?? input.ws_url,
        codec: input.codec,
        autoStart: input.autoStart ?? input.auto_start,
    };
}

export function resolveDictationSettings(
    toolConfig?: { dictation?: DictationInput } | null,
): DictationSettings {
    return {
        ...DEFAULTS,
        ...normalize(window.__TOOLBAR_AGENTATION__?.dictation),
        ...normalize(toolConfig?.dictation),
    };
}

export function isDictationEnabled(settings?: DictationSettings | null): boolean {
    return dictationEnabled(settings);
}

export function mergeTranscript(existing: string, incoming: string): string {
    const spoken = incoming.trim();

    if (spoken === "") {
        return existing;
    }

    const current = existing.trimEnd();

    if (current === "") {
        return spoken;
    }

    return `${current} ${spoken}`;
}

export function isUnrelatedEditable(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) {
        return false;
    }

    if (target.closest("[data-annotation-popup]")) {
        return false;
    }

    return Boolean(
        target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]'),
    );
}
