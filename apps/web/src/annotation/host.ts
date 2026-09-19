import "./toolbar-annotation.css";

export const ANNOTATION_HOST_ID = "laravel-toolbar-annotation-host";
export const ANNOTATION_ROOT_ID = "laravel-toolbar-annotation-root";
export const ANNOTATION_STYLE_ID = "laravel-toolbar-annotation-page-styles";

const HOST_STYLE = "position:fixed;inset:0;z-index:999998;pointer-events:none;";

const PAGE_STYLES = `
html.laravel-toolbar-annotating,
html.laravel-toolbar-annotating * {
    cursor: crosshair !important;
}
html.laravel-toolbar-annotating [data-feedback-toolbar],
html.laravel-toolbar-annotating [data-feedback-toolbar] *,
html.laravel-toolbar-annotating [data-annotation-popup],
html.laravel-toolbar-annotating [data-annotation-popup] *,
html.laravel-toolbar-annotating [data-annotation-marker],
html.laravel-toolbar-annotating [data-annotation-marker] * {
    cursor: pointer !important;
}

@keyframes toolbar-annotation-shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-4px); }
    75% { transform: translateX(4px); }
}
.toolbar-annotation-shake {
    animation: toolbar-annotation-shake 0.25s ease;
}
@keyframes toolbar-annotation-spin {
    to { transform: rotate(360deg); }
}
.toolbar-annotation-spin {
    animation: toolbar-annotation-spin 0.7s linear infinite;
}
@keyframes toolbar-annotation-marker-in {
    0% { opacity: 0; scale: 0.3; }
    100% { opacity: 1; scale: 1; }
}
.toolbar-annotation-marker-in {
    animation: toolbar-annotation-marker-in 0.25s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes toolbar-annotation-marker-pulse {
    0% { opacity: 0.55; transform: scale(1); }
    100% { opacity: 0; transform: scale(2.15); }
}
.toolbar-annotation-marker-pulse {
    position: absolute;
    inset: 0;
    border-radius: 9999px;
    border: 2px solid var(--toolbar-annotation-pulse);
    pointer-events: none;
    animation: toolbar-annotation-marker-pulse 1.7s ease-out infinite;
}
.toolbar-annotation-marker-pulse-delay {
    animation-delay: 0.85s;
}
@media (prefers-reduced-motion: reduce) {
    .toolbar-annotation-marker-pulse {
        animation: none;
        opacity: 0.4;
        transform: scale(1.25);
    }
    .toolbar-annotation-marker-pulse-delay {
        display: none;
    }
}
#${ANNOTATION_ROOT_ID} {
    position: fixed;
    inset: 0;
    pointer-events: none;
}
`;

function injectPageStyles(): void {
    if (document.getElementById(ANNOTATION_STYLE_ID)) {
        return;
    }

    const style = document.createElement("style");
    style.id = ANNOTATION_STYLE_ID;
    style.textContent = PAGE_STYLES;
    document.head.appendChild(style);
}

/**
 * Mount the annotation React root in the light DOM so Orbit's Tailwind utilities apply.
 * The host stays pointer-events:none so deepElementFromPoint still hits page content.
 */
export function ensureAnnotationRoot(): HTMLElement {
    injectPageStyles();

    let host = document.getElementById(ANNOTATION_HOST_ID);

    if (!host) {
        host = document.createElement("div");
        host.id = ANNOTATION_HOST_ID;
        host.setAttribute("data-feedback-toolbar", "true");
        host.setAttribute("data-annotation-host", "true");
        host.setAttribute("style", HOST_STYLE);
        document.body.appendChild(host);
    }

    // Shadow roots cannot be detached — recreate the host if an older bundle left one.
    if (host.shadowRoot) {
        host.remove();
        host = document.createElement("div");
        host.id = ANNOTATION_HOST_ID;
        host.setAttribute("data-feedback-toolbar", "true");
        host.setAttribute("data-annotation-host", "true");
        host.setAttribute("style", HOST_STYLE);
        document.body.appendChild(host);
    }

    let root = host.querySelector<HTMLElement>(`#${ANNOTATION_ROOT_ID}`);

    if (!root) {
        root = document.createElement("div");
        root.id = ANNOTATION_ROOT_ID;
        host.appendChild(root);
    }

    return root;
}

export function removeAnnotationHost(): void {
    document.getElementById(ANNOTATION_HOST_ID)?.remove();
    document.getElementById(ANNOTATION_STYLE_ID)?.remove();
    document.documentElement.classList.remove("laravel-toolbar-annotating");
}
