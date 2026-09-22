import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { isDictationEnabled, resolveDictationSettings } from "@/annotation/dictation-settings";

const defaults = {
    provider: "diction",
    wsUrl: "wss://diction.orbit/v1/audio/stream",
    codec: "auto",
    autoStart: true,
};

beforeEach(() => vi.stubGlobal("window", {}));
afterEach(() => vi.unstubAllGlobals());

describe("dictation settings overrides", () => {
    it.each([undefined, null, {}, { dictation: {} }])(
        "keeps defaults for empty tool config %j",
        (config) => {
            window.__TOOLBAR_AGENTATION__ = { dictation: {} };
            expect(resolveDictationSettings(config)).toEqual(defaults);
        },
    );

    it("keeps dictation enabled when only automatic start is disabled", () => {
        const settings = resolveDictationSettings({ dictation: { auto_start: false } });

        expect(settings).toEqual({ ...defaults, autoStart: false });
        expect(isDictationEnabled(settings)).toBe(true);
    });

    it("merges defaults, partial window settings, and partial tool settings in order", () => {
        window.__TOOLBAR_AGENTATION__ = {
            dictation: { ws_url: "wss://window.invalid/audio", codec: "opus", auto_start: false },
        };

        expect(resolveDictationSettings({ dictation: { codec: "pcm" } })).toEqual({
            ...defaults,
            wsUrl: "wss://window.invalid/audio",
            codec: "pcm",
            autoStart: false,
        });
    });

    it("uses camel case values over aliases, including false and empty strings", () => {
        expect(
            resolveDictationSettings({
                dictation: {
                    wsUrl: "",
                    ws_url: "wss://alias.invalid/audio",
                    autoStart: false,
                    auto_start: true,
                },
            }),
        ).toEqual({ ...defaults, wsUrl: "", autoStart: false });
    });

    it("preserves explicit empty values that disable dictation", () => {
        const settings = resolveDictationSettings({
            dictation: { provider: "", ws_url: "", codec: "" },
        });

        expect(settings).toEqual({ ...defaults, provider: "", wsUrl: "", codec: "" });
        expect(isDictationEnabled(settings)).toBe(false);
    });

    it("treats undefined values as absent and keeps lower priority settings", () => {
        window.__TOOLBAR_AGENTATION__ = { dictation: { provider: "custom", autoStart: false } };

        expect(
            resolveDictationSettings({
                dictation: {
                    provider: undefined,
                    wsUrl: undefined,
                    codec: undefined,
                    autoStart: undefined,
                },
            }),
        ).toEqual({ ...defaults, provider: "custom", autoStart: false });
    });
});
