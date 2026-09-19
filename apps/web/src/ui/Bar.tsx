type BarProps = { label?: string; ratio: number; reading: string; thresholds?: [number, number] };

/**
 * One btop-style meter: label, a row of blocks, and a reading. The colour follows the position in
 * the meter, green through yellow at the first threshold to red at the second, and the unused part
 * stays as a dim track.
 */
export function Bar({ label = "", ratio, reading, thresholds = [60, 85] }: BarProps) {
    const percent = Math.max(0, Math.min(1, Number.isFinite(ratio) ? ratio : 0)) * 100;
    const blocks = "■".repeat(240);

    return (
        <span
            className="flex min-w-0 whitespace-nowrap"
            role="meter"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(percent)}
        >
            {label === "" ? null : (
                <span className="whitespace-pre text-dim">{label.padEnd(2)}</span>
            )}
            <span className="relative min-w-0 flex-1 overflow-hidden" aria-hidden="true">
                <span className="block overflow-hidden text-line opacity-60">{blocks}</span>
                <span
                    className="absolute inset-0 overflow-hidden bg-clip-text text-transparent"
                    style={{
                        backgroundImage: `linear-gradient(90deg, var(--green), var(--yellow) ${thresholds[0]}%, var(--red) ${thresholds[1]}%)`,
                        clipPath: `inset(0 calc(100% - round(down, ${percent}%, 1ch)) 0 0)`,
                    }}
                >
                    {blocks}
                </span>
            </span>
            <span className="whitespace-pre pl-[1ch] text-dim">{reading}</span>
        </span>
    );
}
