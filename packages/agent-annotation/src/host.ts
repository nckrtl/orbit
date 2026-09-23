import shadowCss from "./shadow.css?inline";

export const ANNOTATION_HOST_ID = "laravel-toolbar-annotation-host";
export const ANNOTATION_ROOT_ID = "laravel-toolbar-annotation-root";
export const ANNOTATION_STYLE_ID = "laravel-toolbar-annotation-page-styles";

const HOST_STYLE = "position:fixed;inset:0;z-index:999998;pointer-events:none;";

/** Page-level cursor only — overlay chrome styles live inside the shadow sheet. */
const PAGE_STYLES = `
html.laravel-toolbar-annotating,
html.laravel-toolbar-annotating * {
    cursor: crosshair !important;
}
html.laravel-toolbar-annotating [data-feedback-toolbar],
html.laravel-toolbar-annotating [data-feedback-toolbar] *,
html.laravel-toolbar-annotating [data-orbit-annotation-fab],
html.laravel-toolbar-annotating [data-orbit-annotation-fab] *,
html.laravel-toolbar-annotating [data-orbit-annotation-chrome],
html.laravel-toolbar-annotating [data-orbit-annotation-chrome] *,
html.laravel-toolbar-annotating [data-annotation-popup],
html.laravel-toolbar-annotating [data-annotation-popup] *,
html.laravel-toolbar-annotating [data-annotation-marker],
html.laravel-toolbar-annotating [data-annotation-marker] * {
    cursor: pointer !important;
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

function adoptAnnotationStyles(shadow: ShadowRoot): void {
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(shadowCss);
    shadow.adoptedStyleSheets = [...shadow.adoptedStyleSheets, sheet];
}

export function getAnnotationRootElement(): HTMLElement | null {
    const host = document.getElementById(ANNOTATION_HOST_ID);
    return host?.shadowRoot?.getElementById(ANNOTATION_ROOT_ID) ?? null;
}

/**
 * Mount the annotation React root inside an open Shadow DOM (laravel-toolbar host.ts),
 * with a self-contained Tailwind sheet adopted onto that shadow.
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

    let shadow = host.shadowRoot;

    if (!shadow) {
        shadow = host.attachShadow({ mode: "open" });
        adoptAnnotationStyles(shadow);

        const root = document.createElement("div");
        root.id = ANNOTATION_ROOT_ID;
        shadow.appendChild(root);
    }

    const root = shadow.getElementById(ANNOTATION_ROOT_ID);

    if (!root) {
        throw new Error("Annotation overlay root was not created");
    }

    return root;
}

export function removeAnnotationHost(): void {
    document.getElementById(ANNOTATION_HOST_ID)?.remove();
    document.getElementById(ANNOTATION_STYLE_ID)?.remove();
    document.documentElement.classList.remove("laravel-toolbar-annotating");
}
