import { attachWaveformMeter } from "@/annotation/waveform";

/**
 * Diction /v1/audio/stream client.
 *
 * Protocol (Diction gateway):
 *   connect  wss://host/v1/audio/stream?codec=opus|pcm
 *   send     binary frames (Ogg/WebM Opus, or PCM16 LE mono 16 kHz)
 *   send     {"action":"done"}
 *   receive  {"text":"..."}
 *
 * Opus is preferred when MediaRecorder can emit `codecs=opus` (Chrome/Firefox
 * typically `audio/webm;codecs=opus`). Safari and other browsers that cannot
 * produce Opus fall back to PCM16 16 kHz mono, which is Diction's documented
 * default and the path already proven against diction.orbit.
 *
 * The `diction.opus.v1` subprotocol is offered with Opus. If Diction declines
 * it, this client MUST send PCM — the server treats that connection as PCM.
 */

export type DictationCodec = "auto" | "opus" | "pcm";

export type DictationSettings = {
    provider?: string;
    wsUrl?: string;
    codec?: string;
    /** Start recording when a new comment popup textarea first appears. */
    autoStart?: boolean;
};

export type DictateOptions = {
    wsUrl: string;
    codec?: string;
    /** End the audio stream and ask Diction for the transcript. */
    stopSignal?: AbortSignal;
    /** Abandon the session without inserting text. */
    abortSignal?: AbortSignal;
    /** Live microphone levels for the recording waveform. */
    onLevels?: (levels: number[]) => void;
};

const OPUS_SUBPROTOCOL = "diction.opus.v1";
const PCM_RATE = 16_000;
const CONNECT_TIMEOUT_MS = 8_000;
const RESULT_TIMEOUT_MS = 20_000;
const RECORDER_TIMESLICE_MS = 250;

const OPUS_MIME_TYPES = ["audio/webm;codecs=opus", "audio/ogg;codecs=opus"] as const;

export function dictationEnabled(settings?: DictationSettings | null): boolean {
    return settings?.provider === "diction" && Boolean(settings.wsUrl);
}

export function supportedOpusMimeType(): string | null {
    if (
        typeof MediaRecorder === "undefined" ||
        typeof MediaRecorder.isTypeSupported !== "function"
    ) {
        return null;
    }

    return OPUS_MIME_TYPES.find((type) => MediaRecorder.isTypeSupported(type)) ?? null;
}

let sharedMicrophone: MediaStream | null = null;

export async function warmMicrophone(): Promise<void> {
    try {
        await acquireMicrophone();
    } catch {
        // Annotation mode should still work if the mic is unavailable.
    }
}

export function releaseMicrophone(): void {
    if (!sharedMicrophone) {
        return;
    }

    stopTracks(sharedMicrophone);
    sharedMicrophone = null;
}

export async function dictate(options: DictateOptions): Promise<string> {
    const stream = await acquireMicrophone();
    const abort = options.abortSignal;
    const stop = options.stopSignal;
    const stopMeter = options.onLevels
        ? attachWaveformMeter(stream, options.onLevels)
        : () => undefined;

    try {
        throwIfAborted(abort);

        const opusMime = pickOpusMime(options.codec);
        let capture = opusMime ? startOpusCapture(stream, opusMime) : startPcmCapture(stream);
        const connecting = connectDiction(options.wsUrl, opusMime !== null);
        let socket: WebSocket | undefined;

        try {
            const connection = await connecting;
            socket = connection.socket;
            throwIfAborted(abort);

            if (!connection.useOpus) {
                capture = await switchToPcm(stream, capture);
            }

            await capture.attach(socket);
            await waitForStop(socket, stop, abort);
            await capture.stop();

            throwIfAborted(abort);
            socket.send(JSON.stringify({ action: "done" }));

            return await readTranscript(socket, abort);
        } finally {
            await capture.stop().catch(() => undefined);
            if (socket) {
                closeSocket(socket);
            }
        }
    } finally {
        stopMeter();
        options.onLevels?.([0.08, 0.08, 0.08, 0.08]);
    }
}

function pickOpusMime(codec?: string): string | null {
    if (codec === "pcm") {
        return null;
    }

    const mime = supportedOpusMimeType();

    if (codec === "opus" && !mime) {
        return null;
    }

    return mime;
}

async function acquireMicrophone(): Promise<MediaStream> {
    if (sharedMicrophone?.active) {
        return sharedMicrophone;
    }

    sharedMicrophone = await requestMicrophone();

    return sharedMicrophone;
}

async function requestMicrophone(): Promise<MediaStream> {
    if (!navigator.mediaDevices?.getUserMedia) {
        throw new DictationError("Microphone is not available in this browser.", "unsupported");
    }

    try {
        return await navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true,
            },
        });
    } catch (error) {
        if (
            error instanceof DOMException &&
            (error.name === "NotAllowedError" || error.name === "PermissionDeniedError")
        ) {
            throw new DictationError("Microphone access was denied.", "denied");
        }

        throw new DictationError("Could not start the microphone.", "mic");
    }
}

async function connectDiction(
    wsUrl: string,
    wantOpus: boolean,
): Promise<{ socket: WebSocket; useOpus: boolean }> {
    if (wantOpus) {
        try {
            const socket = await openSocket(withCodec(wsUrl, "opus"), [OPUS_SUBPROTOCOL]);
            const useOpus = socket.protocol === OPUS_SUBPROTOCOL;

            return { socket, useOpus };
        } catch {
            // Browser or gateway rejected the Opus handshake — PCM is the documented fallback.
        }
    }

    return {
        socket: await openSocket(withCodec(wsUrl, "pcm")),
        useOpus: false,
    };
}

async function openSocket(url: string, protocols?: string[]): Promise<WebSocket> {
    const socket = protocols ? new WebSocket(url, protocols) : new WebSocket(url);
    socket.binaryType = "arraybuffer";
    await waitForOpen(socket);

    return socket;
}

function withCodec(wsUrl: string, codec: "opus" | "pcm"): string {
    try {
        const url = new URL(wsUrl);

        if (!url.searchParams.has("codec")) {
            url.searchParams.set("codec", codec);
        }

        return url.toString();
    } catch {
        const separator = wsUrl.includes("?") ? "&" : "?";

        return `${wsUrl}${separator}codec=${codec}`;
    }
}

function waitForOpen(socket: WebSocket): Promise<void> {
    if (socket.readyState === WebSocket.OPEN) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => {
            cleanup();
            socket.close();
            reject(new DictationError("Could not reach the dictation service.", "ws"));
        }, CONNECT_TIMEOUT_MS);

        const onOpen = (): void => {
            cleanup();
            resolve();
        };

        const onError = (): void => {
            cleanup();
            reject(new DictationError("Could not reach the dictation service.", "ws"));
        };

        const cleanup = (): void => {
            window.clearTimeout(timer);
            socket.removeEventListener("open", onOpen);
            socket.removeEventListener("error", onError);
        };

        socket.addEventListener("open", onOpen);
        socket.addEventListener("error", onError);
    });
}

type LiveCapture = {
    kind: "opus" | "pcm";
    attach(socket: WebSocket): Promise<void>;
    stop(): Promise<void>;
};

async function switchToPcm(stream: MediaStream, capture: LiveCapture): Promise<LiveCapture> {
    if (capture.kind === "pcm") {
        return capture;
    }

    await capture.stop();

    return startPcmCapture(stream);
}

function startOpusCapture(stream: MediaStream, mimeType: string): LiveCapture {
    const queue: ArrayBuffer[] = [];
    const pending: Promise<void>[] = [];
    let socket: WebSocket | null = null;
    let stopped = false;
    const recorder = new MediaRecorder(stream, {
        mimeType,
        audioBitsPerSecond: 24_000,
    });

    recorder.addEventListener("dataavailable", (event: BlobEvent) => {
        if (event.data.size === 0) {
            return;
        }

        pending.push(
            event.data.arrayBuffer().then((buffer) => {
                if (socket?.readyState === WebSocket.OPEN) {
                    socket.send(buffer);
                    return;
                }

                if (!stopped) {
                    queue.push(buffer);
                }
            }),
        );
    });

    recorder.start(RECORDER_TIMESLICE_MS);

    return {
        kind: "opus",
        async attach(next) {
            socket = next;
            await Promise.all(pending);

            while (queue.length > 0) {
                const buffer = queue.shift();

                if (buffer && next.readyState === WebSocket.OPEN) {
                    next.send(buffer);
                }
            }
        },
        async stop() {
            stopped = true;

            if (recorder.state !== "inactive") {
                await stopRecorder(recorder);
            }

            await Promise.all(pending);
        },
    };
}

function stopRecorder(recorder: MediaRecorder): Promise<void> {
    return new Promise((resolve) => {
        recorder.addEventListener("stop", () => resolve(), { once: true });
        recorder.stop();
    });
}

function startPcmCapture(stream: MediaStream): LiveCapture {
    const AudioContextCtor =
        window.AudioContext ||
        (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

    if (!AudioContextCtor) {
        throw new DictationError("This browser cannot capture PCM audio.", "unsupported");
    }

    const queue: ArrayBuffer[] = [];
    let socket: WebSocket | null = null;
    let stopped = false;
    const context = new AudioContextCtor();
    const source = context.createMediaStreamSource(stream);
    const processor = context.createScriptProcessor(4096, 1, 1);
    const mute = context.createGain();
    mute.gain.value = 0;

    processor.onaudioprocess = (event: AudioProcessingEvent) => {
        const input = event.inputBuffer.getChannelData(0);
        const pcm = downsampleToPcm16(input, context.sampleRate, PCM_RATE);

        if (pcm.byteLength === 0) {
            return;
        }

        if (socket?.readyState === WebSocket.OPEN) {
            socket.send(pcm);
            return;
        }

        if (!stopped) {
            queue.push(pcm);
        }
    };

    source.connect(processor);
    processor.connect(mute);
    mute.connect(context.destination);

    if (context.state === "suspended") {
        void context.resume();
    }

    return {
        kind: "pcm",
        async attach(next) {
            socket = next;

            if (context.state === "suspended") {
                await context.resume();
            }

            while (queue.length > 0) {
                const buffer = queue.shift();

                if (buffer && next.readyState === WebSocket.OPEN) {
                    next.send(buffer);
                }
            }
        },
        async stop() {
            if (stopped) {
                return;
            }

            stopped = true;
            processor.onaudioprocess = null;
            processor.disconnect();
            source.disconnect();
            mute.disconnect();
            await context.close().catch(() => undefined);
        },
    };
}

function downsampleToPcm16(
    input: Float32Array,
    inputRate: number,
    outputRate: number,
): ArrayBuffer {
    if (input.length === 0) {
        return new ArrayBuffer(0);
    }

    const ratio = inputRate / outputRate;
    const outputLength = ratio <= 1 ? input.length : Math.round(input.length / ratio);
    const view = new DataView(new ArrayBuffer(outputLength * 2));

    if (ratio <= 1) {
        for (let i = 0; i < input.length; i++) {
            view.setInt16(i * 2, floatToInt16(input[i] ?? 0), true);
        }

        return view.buffer;
    }

    for (let i = 0; i < outputLength; i++) {
        const position = i * ratio;
        const index = Math.floor(position);
        const next = Math.min(index + 1, input.length - 1);
        const fraction = position - index;
        const sample = (input[index] ?? 0) * (1 - fraction) + (input[next] ?? 0) * fraction;
        view.setInt16(i * 2, floatToInt16(sample), true);
    }

    return view.buffer;
}

function floatToInt16(sample: number): number {
    const clamped = Math.max(-1, Math.min(1, sample));

    return clamped < 0 ? Math.round(clamped * 0x8000) : Math.round(clamped * 0x7fff);
}

function waitForStop(socket: WebSocket, stop?: AbortSignal, abort?: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
        if (abort?.aborted) {
            reject(new DOMException("Aborted", "AbortError"));
            return;
        }

        if (stop?.aborted) {
            resolve();
            return;
        }

        const onStop = (): void => {
            cleanup();
            resolve();
        };

        const onAbort = (): void => {
            cleanup();
            reject(new DOMException("Aborted", "AbortError"));
        };

        const onClose = (): void => {
            cleanup();
            reject(new DictationError("The dictation connection closed unexpectedly.", "ws"));
        };

        const onError = (): void => {
            cleanup();
            reject(new DictationError("The dictation connection failed.", "ws"));
        };

        const cleanup = (): void => {
            stop?.removeEventListener("abort", onStop);
            abort?.removeEventListener("abort", onAbort);
            socket.removeEventListener("close", onClose);
            socket.removeEventListener("error", onError);
        };

        stop?.addEventListener("abort", onStop, { once: true });
        abort?.addEventListener("abort", onAbort, { once: true });
        socket.addEventListener("close", onClose);
        socket.addEventListener("error", onError);
    });
}

function readTranscript(socket: WebSocket, abort?: AbortSignal): Promise<string> {
    return new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => {
            cleanup();
            reject(new DictationError("Dictation timed out waiting for a transcript.", "timeout"));
        }, RESULT_TIMEOUT_MS);

        const onMessage = (event: MessageEvent<string | ArrayBuffer>): void => {
            if (typeof event.data !== "string") {
                return;
            }

            let payload: { text?: unknown; error?: unknown };

            try {
                payload = JSON.parse(event.data) as { text?: unknown; error?: unknown };
            } catch {
                return;
            }

            if (typeof payload.error === "string" && payload.error !== "") {
                cleanup();
                reject(new DictationError(payload.error, "ws"));
                return;
            }

            if (typeof payload.text === "string") {
                cleanup();
                resolve(payload.text);
            }
        };

        const onClose = (): void => {
            cleanup();
            reject(
                new DictationError(
                    "The dictation service closed before sending a transcript.",
                    "ws",
                ),
            );
        };

        const onAbort = (): void => {
            cleanup();
            reject(new DOMException("Aborted", "AbortError"));
        };

        const cleanup = (): void => {
            window.clearTimeout(timer);
            socket.removeEventListener("message", onMessage);
            socket.removeEventListener("close", onClose);
            abort?.removeEventListener("abort", onAbort);
        };

        if (abort?.aborted) {
            cleanup();
            reject(new DOMException("Aborted", "AbortError"));
            return;
        }

        abort?.addEventListener("abort", onAbort, { once: true });
        socket.addEventListener("message", onMessage);
        socket.addEventListener("close", onClose);
    });
}

function closeSocket(socket: WebSocket): void {
    try {
        socket.close();
    } catch {
        // already closed
    }
}

function stopTracks(stream: MediaStream): void {
    for (const track of stream.getTracks()) {
        track.stop();
    }
}

function throwIfAborted(signal?: AbortSignal): void {
    if (signal?.aborted) {
        throw new DOMException("Aborted", "AbortError");
    }
}

export class DictationError extends Error {
    readonly code: "denied" | "mic" | "ws" | "timeout" | "unsupported";

    constructor(message: string, code: DictationError["code"]) {
        super(message);
        this.name = "DictationError";
        this.code = code;
    }
}
