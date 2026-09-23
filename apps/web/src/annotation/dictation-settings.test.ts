import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { resolveDictationSettings } from "@nckrtl/annotate/dictation-settings";

beforeEach(() => vi.stubGlobal("window", { location: { href: "https://app.example/page" } }));
afterEach(() => vi.unstubAllGlobals());

it("does not connect to a machine by default", () => {
    expect(resolveDictationSettings().wsUrl).toBe("");
});
it("keeps protocol and codec defaults for a URL-only configuration", () => {
    expect(resolveDictationSettings({ dictation: { wsUrl: "/speech" } })).toEqual({
        provider: "diction",
        wsUrl: "wss://app.example/speech",
        codec: "auto",
        autoStart: true,
    });
});
it("overrides legacy settings only with supplied fields and supports disabling speech", () => {
    window.__TOOLBAR_AGENTATION__ = {
        dictation: { ws_url: "wss://legacy.example/stream", auto_start: false, codec: "pcm" },
    };
    expect(
        resolveDictationSettings({
            dictation: { wsUrl: "https://speech.example/stream?model=small" },
        }),
    ).toEqual({
        provider: "diction",
        wsUrl: "wss://speech.example/stream?model=small",
        codec: "pcm",
        autoStart: false,
    });
    expect(resolveDictationSettings({ dictation: { wsUrl: "" } }).wsUrl).toBe("");
});
it("rejects non-WebSocket schemes before capture", () => {
    expect(() => resolveDictationSettings({ dictation: { wsUrl: "file:///speech" } })).toThrow(
        "Transcription URL",
    );
});
