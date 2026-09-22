export const WAVEFORM_BARS = 4;
export const WAVEFORM_FLOOR = 0.08;

export function computeWaveformBars(frequency: Uint8Array, count = WAVEFORM_BARS): number[] {
    const bars = Array.from({ length: count }, () => WAVEFORM_FLOOR);

    if (frequency.length < count + 1) {
        return bars;
    }

    const start = 1;
    const end = Math.min(
        frequency.length,
        Math.max(start + count, Math.floor(frequency.length * 0.35)),
    );
    const bucket = Math.max(1, Math.floor((end - start) / count));

    for (let index = 0; index < count; index++) {
        const from = start + index * bucket;
        const to = Math.min(end, from + bucket);
        let peak = 0;

        for (let cursor = from; cursor < to; cursor++) {
            peak = Math.max(peak, frequency[cursor] ?? 0);
        }

        bars[index] = Math.min(1, Math.max(WAVEFORM_FLOOR, (peak / 255) ** 0.85));
    }

    return bars;
}

export function rmsFromTimeDomain(samples: Uint8Array): number {
    if (samples.length === 0) {
        return 0;
    }

    let sum = 0;

    for (let index = 0; index < samples.length; index++) {
        const centered = ((samples[index] ?? 128) - 128) / 128;
        sum += centered * centered;
    }

    return Math.sqrt(sum / samples.length);
}

export function mixWaveformLevels(frequency: Uint8Array, time: Uint8Array): number[] {
    const rms = rmsFromTimeDomain(time);

    return computeWaveformBars(frequency).map((bar) =>
        Math.min(1, Math.max(WAVEFORM_FLOOR, bar * 0.65 + rms * 2.4)),
    );
}

export function attachWaveformMeter(
    stream: MediaStream,
    onLevels: (levels: number[]) => void,
): () => void {
    const AudioContextCtor =
        window.AudioContext ||
        (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;

    if (!AudioContextCtor) {
        return () => undefined;
    }

    let context: AudioContext | undefined;
    let source: MediaStreamAudioSourceNode | undefined;
    let analyser: AnalyserNode | undefined;
    let frame: number | undefined;
    let stopped = false;
    const stop = (): void => {
        if (stopped) {
            return;
        }

        stopped = true;
        if (frame !== undefined) {
            window.cancelAnimationFrame(frame);
        }
        for (const node of [source, analyser]) {
            try {
                node?.disconnect();
            } catch {
                // A failed node must not prevent the rest of this graph from closing.
            }
        }
        try {
            void context?.close().catch(() => undefined);
        } catch {
            // Closing an optional meter must not interrupt dictation.
        }
    };

    try {
        context = new AudioContextCtor();
        source = context.createMediaStreamSource(stream);
        const meter = context.createAnalyser();
        analyser = meter;
        meter.fftSize = 256;
        meter.smoothingTimeConstant = 0.5;
        source.connect(meter);

        const frequency = new Uint8Array(meter.frequencyBinCount);
        const time = new Uint8Array(meter.fftSize);
        const tick = (): void => {
            if (stopped) {
                return;
            }

            try {
                meter.getByteFrequencyData(frequency);
                meter.getByteTimeDomainData(time);
                onLevels(mixWaveformLevels(frequency, time));
                if (!stopped) {
                    frame = window.requestAnimationFrame(tick);
                }
            } catch {
                stop();
            }
        };

        void context
            .resume()
            .then(() => {
                if (!stopped) {
                    frame = window.requestAnimationFrame(tick);
                }
            })
            .catch(stop);
    } catch {
        stop();
    }

    return stop;
}
