import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { attachWaveformMeter } from "../src/annotation/waveform";

/** Runs in Node and Chromium with synthetic audio nodes, without microphone access. */
export function waveformLifecycleTests() {
    describe("waveform graph ownership", () => {
        let failure: string;
        let contexts: FakeAudioContext[];
        let frames: Map<number, FrameRequestCallback>;
        let nextFrame: number;
        let stopTrack: ReturnType<typeof vi.fn>;
        let stream: MediaStream;
        let resumeResult: () => Promise<void>;

        class FakeAudioContext {
            readonly source = {
                connect: vi.fn(() => {
                    if (failure === "connect") throw new Error("connect failed");
                }),
                disconnect: vi.fn(),
            };
            readonly analyser = {
                fftSize: 256,
                frequencyBinCount: 128,
                smoothingTimeConstant: 0,
                getByteFrequencyData: vi.fn((data: Uint8Array) => data.fill(128)),
                getByteTimeDomainData: vi.fn((data: Uint8Array) => data.fill(144)),
                disconnect: vi.fn(),
            };
            readonly createMediaStreamSource = vi.fn(() => {
                if (failure === "source") throw new Error("source failed");
                return this.source;
            });
            readonly createAnalyser = vi.fn(() => {
                if (failure === "analyser") throw new Error("analyser failed");
                return this.analyser;
            });
            readonly resume = vi.fn(() => {
                if (failure === "resume") throw new Error("resume failed");
                return resumeResult();
            });
            readonly close = vi.fn(async () => undefined);

            constructor() {
                if (failure === "constructor") throw new Error("constructor failed");
                contexts.push(this);
            }
        }

        beforeEach(() => {
            failure = "";
            contexts = [];
            frames = new Map();
            nextFrame = 1;
            resumeResult = async () => undefined;
            stopTrack = vi.fn();
            stream = { getTracks: () => [{ stop: stopTrack }] } as unknown as MediaStream;
            if (typeof window === "undefined") vi.stubGlobal("window", globalThis);
            vi.stubGlobal("AudioContext", FakeAudioContext);
            vi.stubGlobal(
                "requestAnimationFrame",
                vi.fn((callback: FrameRequestCallback) => {
                    const id = nextFrame++;
                    frames.set(id, callback);
                    return id;
                }),
            );
            vi.stubGlobal(
                "cancelAnimationFrame",
                vi.fn((id: number) => frames.delete(id)),
            );
        });

        afterEach(() => {
            expect(stopTrack).not.toHaveBeenCalled();
            vi.unstubAllGlobals();
            vi.restoreAllMocks();
        });

        it.each(["constructor", "source", "analyser", "connect", "resume"])(
            "releases partial setup after %s failure and stays optional",
            async (stage) => {
                failure = stage;
                let setupError: unknown;
                let stop: () => void = () => undefined;
                try {
                    stop = attachWaveformMeter(stream, vi.fn());
                } catch (error) {
                    setupError = error;
                }
                stop();
                stop();
                await Promise.resolve();

                expect(frames.size).toBe(0);
                expect(window.requestAnimationFrame).not.toHaveBeenCalled();
                expect(contexts).toHaveLength(stage === "constructor" ? 0 : 1);
                const context = contexts[0];
                if (context) {
                    expect(context.close).toHaveBeenCalledOnce();
                    expect(context.source.disconnect).toHaveBeenCalledTimes(
                        stage === "source" ? 0 : 1,
                    );
                    expect(context.analyser.disconnect).toHaveBeenCalledTimes(
                        ["source", "analyser"].includes(stage) ? 0 : 1,
                    );
                }
                expect(setupError).toBeUndefined();
            },
        );

        it("cleans up a rejected resume without an unhandled rejection", async () => {
            resumeResult = async () => {
                throw new Error("resume rejected");
            };
            const stop = attachWaveformMeter(stream, vi.fn());
            await vi.waitFor(() => expect(contexts[0]?.close).toHaveBeenCalledOnce());
            stop();
            expect(contexts[0]?.source.disconnect).toHaveBeenCalledOnce();
            expect(contexts[0]?.analyser.disconnect).toHaveBeenCalledOnce();
            expect(window.requestAnimationFrame).not.toHaveBeenCalled();
        });

        it.each([false, true])(
            "ignores late resume settlement after stop (reject=%s)",
            async (reject) => {
                let resolve!: () => void;
                let fail!: (error: Error) => void;
                const pending = new Promise<void>((done, failed) => {
                    resolve = done;
                    fail = failed;
                });
                resumeResult = () => pending;
                const stop = attachWaveformMeter(stream, vi.fn());
                stop();
                if (reject) fail(new Error("late resume rejected"));
                else resolve();
                await pending.catch(() => undefined);
                await Promise.resolve();
                stop();
                expect(contexts[0]?.close).toHaveBeenCalledOnce();
                expect(contexts[0]?.source.disconnect).toHaveBeenCalledOnce();
                expect(contexts[0]?.analyser.disconnect).toHaveBeenCalledOnce();
                expect(window.requestAnimationFrame).not.toHaveBeenCalled();
            },
        );

        it("emits four bounded bars and tears down its own nodes once", async () => {
            const levels = vi.fn();
            const stop = attachWaveformMeter(stream, levels);
            await Promise.resolve();
            const tick = frames.get(1)!;
            frames.delete(1);
            tick(0);
            expect(levels).toHaveBeenCalledOnce();
            const bars = levels.mock.calls[0]![0] as number[];
            expect(bars).toHaveLength(4);
            expect(bars.every((value) => value >= 0.08 && value <= 1)).toBe(true);
            stop();
            stop();
            tick(1);
            expect(frames.size).toBe(0);
            expect(window.cancelAnimationFrame).toHaveBeenCalledExactlyOnceWith(2);
            expect(contexts[0]?.source.disconnect).toHaveBeenCalledOnce();
            expect(contexts[0]?.analyser.disconnect).toHaveBeenCalledOnce();
            expect(contexts[0]?.close).toHaveBeenCalledOnce();
            expect(levels).toHaveBeenCalledOnce();
        });

        it("does not schedule again when its level consumer stops the meter", async () => {
            const stop = attachWaveformMeter(stream, () => stop());
            await Promise.resolve();
            const tick = frames.get(1)!;
            frames.delete(1);
            tick(0);
            expect(window.requestAnimationFrame).toHaveBeenCalledOnce();
            expect(frames.size).toBe(0);
            expect(contexts[0]?.close).toHaveBeenCalledOnce();
        });

        it("closes the context even when disconnection or close rejects", async () => {
            const stop = attachWaveformMeter(stream, vi.fn());
            await Promise.resolve();
            contexts[0]!.source.disconnect.mockImplementation(() => {
                throw new Error("disconnect failed");
            });
            contexts[0]!.close.mockRejectedValue(new Error("close failed"));
            stop();
            stop();
            await Promise.resolve();
            expect(contexts[0]?.analyser.disconnect).toHaveBeenCalledOnce();
            expect(contexts[0]?.close).toHaveBeenCalledOnce();
            expect(frames.size).toBe(0);
        });

        it("remains a no-op without an AudioContext", () => {
            vi.stubGlobal("AudioContext", undefined);
            vi.stubGlobal("webkitAudioContext", undefined);
            const stop = attachWaveformMeter(stream, vi.fn());
            stop();
            expect(contexts).toHaveLength(0);
            expect(window.requestAnimationFrame).not.toHaveBeenCalled();
        });
    });
}
