import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { dictate, releaseMicrophone, warmMicrophone } from "../src/annotation/dictation";

function fakeStream() {
    const stop = vi.fn();
    const stream = { active: true, getTracks: () => [{ stop }] } as unknown as MediaStream;
    stop.mockImplementation(() => Object.assign(stream, { active: false }));

    return { stream, stop };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((onResolve, onReject) => {
        resolve = onResolve;
        reject = onReject;
    });

    return { promise, resolve, reject };
}

/** Runs with mocked media and sockets in Node and Chromium; never requests real audio. */
export function dictationLifecycleTests() {
    describe("dictation capture ownership", () => {
        let microphone: ReturnType<typeof fakeStream>;
        let getUserMedia: ReturnType<typeof vi.fn<() => Promise<MediaStream>>>;
        let protocol: string;
        let failAudioSend: boolean;
        let failWaveform: boolean;
        let sockets: FakeSocket[];
        let recorders: FakeRecorder[];
        let contexts: FakeAudioContext[];

        class FakeSocket extends EventTarget {
            static OPEN = 1;
            readyState = 0;
            protocol = protocol;
            readonly listeners = new Set<string>();
            readonly send = vi.fn((data: string | ArrayBuffer) => {
                if (data instanceof ArrayBuffer && failAudioSend) {
                    throw new Error("Audio send failed");
                }

                if (data === JSON.stringify({ action: "done" })) {
                    queueMicrotask(() => {
                        this.dispatchEvent(
                            new MessageEvent("message", {
                                data: JSON.stringify({ text: "hello" }),
                            }),
                        );
                    });
                }
            });
            readonly close = vi.fn(() => {
                this.readyState = 3;
            });

            constructor() {
                super();
                sockets.push(this);
                queueMicrotask(() => {
                    this.readyState = FakeSocket.OPEN;
                    this.dispatchEvent(new Event("open"));
                });
            }

            override addEventListener(
                type: string,
                listener: EventListenerOrEventListenerObject | null,
                options?: boolean | AddEventListenerOptions,
            ): void {
                this.listeners.add(type);
                super.addEventListener(type, listener, options);
            }
        }

        class FakeRecorder extends EventTarget {
            static isTypeSupported = () => true;
            state = "inactive";
            readonly start = vi.fn(() => {
                this.state = "recording";
                const event = new Event("dataavailable");
                Object.assign(event, { data: new Blob(["mock opus"]) });
                this.dispatchEvent(event);
            });
            readonly stop = vi.fn(() => {
                this.state = "inactive";
                this.dispatchEvent(new Event("stop"));
            });

            constructor() {
                super();
                recorders.push(this);
            }
        }

        class FakeAudioContext {
            state = "running";
            sampleRate = 48_000;
            destination = {};
            readonly source = { connect: vi.fn(), disconnect: vi.fn() };
            readonly processor = {
                onaudioprocess: null as
                    | ((event: { inputBuffer: { getChannelData(): Float32Array } }) => void)
                    | null,
                connect: vi.fn(() => {
                    // Audio can be queued before the negotiated socket is attached.
                    this.processor.onaudioprocess?.({
                        inputBuffer: { getChannelData: () => new Float32Array([0.1, 0.2, 0.3]) },
                    });
                }),
                disconnect: vi.fn(),
            };
            readonly mute = { gain: { value: 1 }, connect: vi.fn(), disconnect: vi.fn() };
            readonly analyser = {
                frequencyBinCount: 128,
                fftSize: 256,
                smoothingTimeConstant: 0,
                getByteFrequencyData: vi.fn(),
                getByteTimeDomainData: vi.fn(),
                disconnect: vi.fn(),
            };
            readonly createMediaStreamSource = vi.fn(() => this.source);
            readonly createScriptProcessor = vi.fn(() => this.processor);
            readonly createGain = vi.fn(() => this.mute);
            readonly createAnalyser = vi.fn(() => {
                if (failWaveform) throw new Error("Waveform unavailable");
                return this.analyser;
            });
            readonly resume = vi.fn(async () => undefined);
            readonly close = vi.fn(async () => {
                this.state = "closed";
            });

            constructor() {
                contexts.push(this);
            }
        }

        beforeEach(() => {
            protocol = "diction.opus.v1";
            failAudioSend = false;
            failWaveform = false;
            sockets = [];
            recorders = [];
            contexts = [];
            microphone = fakeStream();
            getUserMedia = vi.fn(async () => microphone.stream);
            if (typeof window === "undefined") {
                vi.stubGlobal("window", globalThis);
            }
            vi.stubGlobal("navigator", { mediaDevices: { getUserMedia } });
            vi.stubGlobal("WebSocket", FakeSocket);
            vi.stubGlobal("MediaRecorder", FakeRecorder);
            vi.stubGlobal("AudioContext", FakeAudioContext);
            vi.stubGlobal(
                "requestAnimationFrame",
                vi.fn(() => 1),
            );
            vi.stubGlobal("cancelAnimationFrame", vi.fn());
        });

        afterEach(() => {
            releaseMicrophone();
            vi.unstubAllGlobals();
        });

        function expectReleasedCaptures(pcm: boolean) {
            for (const recorder of recorders) {
                expect(recorder.stop).toHaveBeenCalledOnce();
            }
            // Every session requests a waveform graph; PCM adds a capture graph.
            expect(contexts).toHaveLength(pcm ? 2 : 1);
            for (const context of contexts) {
                expect(context.source.disconnect).toHaveBeenCalledOnce();
                expect(context.close).toHaveBeenCalledOnce();
                if (context.createScriptProcessor.mock.calls.length > 0) {
                    expect(context.processor.onaudioprocess).toBeNull();
                    expect(context.processor.disconnect).toHaveBeenCalledOnce();
                    expect(context.mute.disconnect).toHaveBeenCalledOnce();
                }
            }
            expect(sockets[0]?.close).toHaveBeenCalledOnce();
            expect(window.cancelAnimationFrame).toHaveBeenCalledOnce();
            expect(microphone.stop).not.toHaveBeenCalled();
        }

        it.each(["opus", "fallback", "pcm"])(
            "stops %s capture and returns its transcript",
            async (mode) => {
                protocol = mode === "opus" ? "diction.opus.v1" : "";
                const stop = new AbortController();
                const onLevels = vi.fn();
                await warmMicrophone();
                const result = dictate({
                    wsUrl: "wss://mock.invalid/dictation",
                    codec: mode === "pcm" ? "pcm" : "auto",
                    stopSignal: stop.signal,
                    onLevels,
                });

                await vi.waitFor(() => expect(sockets[0]?.listeners.has("close")).toBe(true));
                stop.abort();

                await expect(result).resolves.toBe("hello");
                expect(recorders).toHaveLength(mode === "pcm" ? 0 : 1);
                expect(sockets[0]?.send).toHaveBeenCalledWith(expect.any(ArrayBuffer));
                expect(sockets[0]?.send).toHaveBeenCalledWith(JSON.stringify({ action: "done" }));
                expect(getUserMedia).toHaveBeenCalledOnce();
                expectReleasedCaptures(mode !== "opus");
                expect(onLevels).toHaveBeenLastCalledWith([0.08, 0.08, 0.08, 0.08]);
            },
        );

        it("keeps Opus dictation working when optional waveform setup fails", async () => {
            failWaveform = true;
            const stop = new AbortController();
            const onLevels = vi.fn();
            const result = dictate({
                wsUrl: "wss://mock.invalid/dictation",
                stopSignal: stop.signal,
                onLevels,
            });
            await vi.waitFor(() => expect(sockets[0]?.listeners.has("close")).toBe(true));
            stop.abort();

            await expect(result).resolves.toBe("hello");
            expect(contexts).toHaveLength(1);
            expect(contexts[0]?.source.disconnect).toHaveBeenCalledOnce();
            expect(contexts[0]?.close).toHaveBeenCalledOnce();
            expect(recorders[0]?.stop).toHaveBeenCalledOnce();
            expect(sockets[0]?.close).toHaveBeenCalledOnce();
            expect(microphone.stop).not.toHaveBeenCalled();
            expect(window.requestAnimationFrame).not.toHaveBeenCalled();
            expect(onLevels).toHaveBeenLastCalledWith([0.08, 0.08, 0.08, 0.08]);
        });

        it("releases the replacement PCM graph when attaching its queued audio fails", async () => {
            protocol = "";
            failAudioSend = true;
            const onLevels = vi.fn();

            await expect(
                dictate({ wsUrl: "wss://mock.invalid/dictation", onLevels }),
            ).rejects.toThrow("Audio send failed");

            expectReleasedCaptures(true);
            expect(onLevels).toHaveBeenLastCalledWith([0.08, 0.08, 0.08, 0.08]);
        });

        it.each(["opus", "fallback"])("releases %s capture when aborted", async (mode) => {
            protocol = mode === "opus" ? "diction.opus.v1" : "";
            const abort = new AbortController();
            const onLevels = vi.fn();
            const result = dictate({
                wsUrl: "wss://mock.invalid/dictation",
                abortSignal: abort.signal,
                onLevels,
            });
            const rejected = expect(result).rejects.toMatchObject({ name: "AbortError" });
            await vi.waitFor(() => expect(sockets[0]?.listeners.has("close")).toBe(true));

            abort.abort();

            await rejected;
            expectReleasedCaptures(mode !== "opus");
            expect(sockets[0]?.send).not.toHaveBeenCalledWith(JSON.stringify({ action: "done" }));
            expect(onLevels).toHaveBeenLastCalledWith([0.08, 0.08, 0.08, 0.08]);
        });

        it.each(["error", "close"])(
            "releases the replacement PCM graph after a socket %s",
            async (event) => {
                protocol = "";
                const onLevels = vi.fn();
                const result = dictate({ wsUrl: "wss://mock.invalid/dictation", onLevels });
                const rejected = expect(result).rejects.toMatchObject({ code: "ws" });
                await vi.waitFor(() => expect(sockets[0]?.listeners.has("close")).toBe(true));

                sockets[0]?.dispatchEvent(new Event(event));

                await rejected;
                expectReleasedCaptures(true);
                expect(onLevels).toHaveBeenLastCalledWith([0.08, 0.08, 0.08, 0.08]);
            },
        );

        describe("microphone acquisition", () => {
            it("shares a pending permission request between warm and dictation callers", async () => {
                const pending = deferred<MediaStream>();
                getUserMedia.mockReturnValue(pending.promise);
                const warm = warmMicrophone();
                const warmAgain = warmMicrophone();
                const stop = new AbortController();
                stop.abort();
                const result = dictate({
                    wsUrl: "wss://mock.invalid/dictation",
                    stopSignal: stop.signal,
                });

                expect(getUserMedia).toHaveBeenCalledOnce();
                pending.resolve(microphone.stream);

                await Promise.all([warm, warmAgain]);
                await expect(result).resolves.toBe("hello");
                expect(getUserMedia).toHaveBeenCalledOnce();
                expect(microphone.stop).not.toHaveBeenCalled();
            });

            it("stops a late permission result after release without opening a capture or socket", async () => {
                const pending = deferred<MediaStream>();
                getUserMedia.mockReturnValue(pending.promise);
                const warm = warmMicrophone();
                const result = dictate({ wsUrl: "wss://mock.invalid/dictation" });
                const rejected = expect(result).rejects.toMatchObject({ name: "AbortError" });

                releaseMicrophone();
                pending.resolve(microphone.stream);

                await warm;
                await rejected;
                expect(getUserMedia).toHaveBeenCalledOnce();
                expect(microphone.stop).toHaveBeenCalledOnce();
                expect(sockets).toHaveLength(0);
                expect(recorders).toHaveLength(0);
                expect(contexts).toHaveLength(0);
                releaseMicrophone();
                expect(microphone.stop).toHaveBeenCalledOnce();
            });

            it.each(["old first", "new first"])(
                "keeps a new acquisition when released requests resolve %s",
                async (order) => {
                    const oldRequest = deferred<MediaStream>();
                    const newRequest = deferred<MediaStream>();
                    const oldMicrophone = fakeStream();
                    getUserMedia
                        .mockReturnValueOnce(oldRequest.promise)
                        .mockReturnValueOnce(newRequest.promise);
                    const oldWarm = warmMicrophone();
                    releaseMicrophone();
                    const newWarm = warmMicrophone();
                    expect(getUserMedia).toHaveBeenCalledTimes(2);

                    if (order === "old first") {
                        oldRequest.resolve(oldMicrophone.stream);
                        await oldWarm;
                        newRequest.resolve(microphone.stream);
                        await newWarm;
                    } else {
                        newRequest.resolve(microphone.stream);
                        await newWarm;
                        oldRequest.resolve(oldMicrophone.stream);
                        await oldWarm;
                    }

                    await warmMicrophone();
                    expect(getUserMedia).toHaveBeenCalledTimes(2);
                    expect(oldMicrophone.stop).toHaveBeenCalledOnce();
                    expect(microphone.stop).not.toHaveBeenCalled();
                    releaseMicrophone();
                    expect(microphone.stop).toHaveBeenCalledOnce();
                    expect(oldMicrophone.stop).toHaveBeenCalledOnce();
                },
            );

            it("does not clear a newer pending acquisition when a released request fails", async () => {
                const oldRequest = deferred<MediaStream>();
                const newRequest = deferred<MediaStream>();
                getUserMedia
                    .mockReturnValueOnce(oldRequest.promise)
                    .mockReturnValueOnce(newRequest.promise);
                const oldWarm = warmMicrophone();
                releaseMicrophone();
                const newWarm = warmMicrophone();

                oldRequest.reject(new DOMException("Denied", "NotAllowedError"));
                await oldWarm;
                const joinedWarm = warmMicrophone();
                expect(getUserMedia).toHaveBeenCalledTimes(2);
                newRequest.resolve(microphone.stream);
                await Promise.all([newWarm, joinedWarm]);

                releaseMicrophone();
                expect(microphone.stop).toHaveBeenCalledOnce();
            });

            it.each([
                [
                    new DOMException("Denied", "NotAllowedError"),
                    "denied",
                    "Microphone access was denied.",
                ],
                [
                    new DOMException("Denied", "PermissionDeniedError"),
                    "denied",
                    "Microphone access was denied.",
                ],
                [new Error("Unavailable"), "mic", "Could not start the microphone."],
            ])("permits retry after acquisition fails with %s", async (error, code, message) => {
                const pending = deferred<MediaStream>();
                getUserMedia.mockReturnValueOnce(pending.promise);
                const warm = warmMicrophone();
                const result = dictate({ wsUrl: "wss://mock.invalid/dictation" });
                const rejected = expect(result).rejects.toMatchObject({ code, message });
                pending.reject(error);

                await warm;
                await rejected;
                expect(getUserMedia).toHaveBeenCalledOnce();
                expect(sockets).toHaveLength(0);
                await warmMicrophone();
                expect(getUserMedia).toHaveBeenCalledTimes(2);
                releaseMicrophone();
                expect(microphone.stop).toHaveBeenCalledOnce();
            });

            it("preserves the unsupported microphone error and permits retry", async () => {
                vi.stubGlobal("navigator", {});

                await expect(
                    dictate({ wsUrl: "wss://mock.invalid/dictation" }),
                ).rejects.toMatchObject({
                    code: "unsupported",
                    message: "Microphone is not available in this browser.",
                });

                vi.stubGlobal("navigator", { mediaDevices: { getUserMedia } });
                await warmMicrophone();
                expect(getUserMedia).toHaveBeenCalledOnce();
            });

            it("releases the active stream once and allows a fresh acquisition", async () => {
                await warmMicrophone();
                await warmMicrophone();
                expect(getUserMedia).toHaveBeenCalledOnce();

                releaseMicrophone();
                releaseMicrophone();
                expect(microphone.stop).toHaveBeenCalledOnce();
                const next = fakeStream();
                getUserMedia.mockResolvedValue(next.stream);
                await warmMicrophone();
                expect(getUserMedia).toHaveBeenCalledTimes(2);
                expect(next.stop).not.toHaveBeenCalled();
                releaseMicrophone();
                expect(next.stop).toHaveBeenCalledOnce();
            });

            it("reacquires a stream whose tracks have ended", async () => {
                await warmMicrophone();
                Object.assign(microphone.stream, { active: false });
                const next = fakeStream();
                getUserMedia.mockResolvedValue(next.stream);

                await warmMicrophone();

                expect(getUserMedia).toHaveBeenCalledTimes(2);
                releaseMicrophone();
                expect(next.stop).toHaveBeenCalledOnce();
            });
        });
    });
}
