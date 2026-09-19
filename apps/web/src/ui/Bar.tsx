type BarProps = { label?: string; ratio: number; reading: string; thresholds?: [number, number] };

/** One htop-style bar: label, [|||||    ], and a reading; green, then yellow, then red past the thresholds. */
export function Bar({ label = "", ratio, reading, thresholds = [60, 85] }: BarProps) {
    const percent = Math.max(0, Math.min(1, Number.isFinite(ratio) ? ratio : 0)) * 100;
    const colour =
        percent >= thresholds[1]
            ? "text-red"
            : percent >= thresholds[0]
              ? "text-yellow"
              : "text-green";

    return (
        <span
            className="flex min-w-0 whitespace-nowrap"
            role="meter"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(percent)}
        >
            <span className="text-dim">{label.padEnd(label === "" ? 0 : 3)}[</span>
            <span className="min-w-0 flex-1 overflow-hidden" aria-hidden="true">
                <span
                    className={`block overflow-hidden ${colour}`}
                    style={{ width: `round(down, ${percent}%, 1ch)` }}
                >
                    {"|".repeat(240)}
                </span>
            </span>
            <span className="text-dim">] {reading}</span>
        </span>
    );
}
