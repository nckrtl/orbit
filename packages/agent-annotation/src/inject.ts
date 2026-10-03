import { mountAnnotation, type AnnotationOptions } from "./index";

declare global {
    interface Window {
        __AGENT_ANNOTATION__?: AnnotationOptions;
        AgentAnnotation?: { mountAnnotation: typeof mountAnnotation };
    }
}

// A page can load this script twice (its own toolbar plus a host such as T3). A second
// runtime would take every click while the first renders, so only the first one runs and
// a later load just passes its options on.
const running = window.AgentAnnotation;
if (running) {
    running.mountAnnotation(window.__AGENT_ANNOTATION__);
} else {
    window.AgentAnnotation = { mountAnnotation };
    const start = () => mountAnnotation(window.__AGENT_ANNOTATION__);
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", start, { once: true });
    } else {
        start();
    }
}
