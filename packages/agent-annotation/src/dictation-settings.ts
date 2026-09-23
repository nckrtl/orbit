import { dictationEnabled, type DictationSettings } from "./dictation";

export type DictationInput = {
    provider?: string;
    wsUrl?: string;
    postUrl?: string;
    stopUrl?: string;
    post_url?: string;
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
    wsUrl: "",
    codec: "auto",
    autoStart: true,
};

function normalize(input?: DictationInput | null): DictationSettings {
    if (!input) {
        return {};
    }

    return Object.fromEntries(
        Object.entries({
            provider: input.provider,
            wsUrl: input.wsUrl ?? input.ws_url,
            postUrl: input.postUrl ?? input.post_url,
            stopUrl: input.stopUrl,
            codec: input.codec,
            autoStart: input.autoStart ?? input.auto_start,
        }).filter(([, value]) => value !== undefined),
    );
}

export function resolveDictationSettings(
    toolConfig?: { dictation?: DictationInput } | null,
): DictationSettings {
    const settings = {
        ...DEFAULTS,
        ...normalize(
            typeof window === "undefined" ? undefined : window.__TOOLBAR_AGENTATION__?.dictation,
        ),
        ...normalize(toolConfig?.dictation),
    };
    if (settings.wsUrl) {
        const url = new URL(settings.wsUrl, window.location.href);
        if (url.protocol === "https:") url.protocol = "wss:";
        if (url.protocol === "http:") url.protocol = "ws:";
        if (!["ws:", "wss:"].includes(url.protocol)) {
            throw new Error("Transcription URL must use HTTP(S) or WebSocket(S).");
        }
        settings.wsUrl = url.href;
    }
    for (const key of ["postUrl", "stopUrl"] as const) {
        if (!settings[key]) continue;
        const url = new URL(settings[key], window.location.href);
        if (!["http:", "https:"].includes(url.protocol)) {
            throw new Error("Dictation trigger URL must use HTTP(S).");
        }
        settings[key] = url.href;
    }
    return settings;
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
