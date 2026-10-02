import { mountAnnotation, type AnnotationOptions } from "./index";

declare global {
    interface Window {
        __AGENT_ANNOTATION__?: AnnotationOptions;
        AgentAnnotation?: { mountAnnotation: typeof mountAnnotation };
    }
}

window.AgentAnnotation = { mountAnnotation };
const start = () => mountAnnotation(window.__AGENT_ANNOTATION__);
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
} else {
    start();
}
