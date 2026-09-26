import { useLayoutEffect, useRef, useState, type CSSProperties } from "react";

const DISPLAY_MODES = [
    "fullscreen",
    "standalone",
    "minimal-ui",
    "window-controls-overlay",
    "picture-in-picture",
    "browser",
] as const;

/** The CSS display mode, or the legacy iOS standalone flag when no mode matches. */
export function displayMode(): string {
    for (const mode of DISPLAY_MODES) {
        if (window.matchMedia(`(display-mode: ${mode})`).matches) {
            return mode;
        }
    }

    const standalone = (navigator as Navigator & { standalone?: boolean }).standalone;

    return standalone === true ? "standalone" : "browser";
}

/**
 * A set --safe-area-inset-* wins, so a test can simulate a device. Otherwise each edge is env().
 * The shell ignores a tab inset; this probe still resolves it.
 */
const PROBE_STYLE: CSSProperties = {
    position: "fixed",
    top: 0,
    left: 0,
    boxSizing: "content-box",
    width: 0,
    height: 0,
    visibility: "hidden",
    pointerEvents: "none",
    paddingTop: "var(--safe-area-inset-top, env(safe-area-inset-top, 0px))",
    paddingRight: "var(--safe-area-inset-right, env(safe-area-inset-right, 0px))",
    paddingBottom: "var(--safe-area-inset-bottom, env(safe-area-inset-bottom, 0px))",
    paddingLeft: "var(--safe-area-inset-left, env(safe-area-inset-left, 0px))",
};

function readoutLine(probe: HTMLElement): string {
    const style = getComputedStyle(probe);

    // CSS order, and short enough to stay one line in the 390px Menu drawer.
    return `${displayMode()} ${window.innerWidth}x${window.innerHeight} · ${window.screen.width}x${window.screen.height} · ${style.paddingTop} ${style.paddingRight} ${style.paddingBottom} ${style.paddingLeft}`;
}

/** One support line in the Menu drawer: display mode, view size, screen size, then top, right, bottom, and left. */
export function ViewportReadout() {
    const probeRef = useRef<HTMLDivElement>(null);
    const [line, setLine] = useState("");

    useLayoutEffect(() => {
        const probe = probeRef.current;
        if (probe === null) {
            return;
        }

        const read = () => setLine(readoutLine(probe));
        read();

        const observer = new MutationObserver(read);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ["style"],
        });
        window.addEventListener("resize", read);
        window.addEventListener("orientationchange", read);

        return () => {
            observer.disconnect();
            window.removeEventListener("resize", read);
            window.removeEventListener("orientationchange", read);
        };
    }, []);

    return (
        <>
            <div ref={probeRef} data-safe-area-probe="" aria-hidden="true" style={PROBE_STYLE} />
            <p
                data-viewport-readout=""
                className="mt-[12px] -mx-[var(--panel-padding)] whitespace-nowrap px-[var(--inset)] text-[11px] leading-[16px] text-dim"
            >
                {line}
            </p>
        </>
    );
}
