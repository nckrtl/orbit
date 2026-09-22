import { createRoot, type Root } from "react-dom/client";
import { createElement } from "react";
import type { RouterHistory } from "@tanstack/react-router";
import {
    handleDocumentClick,
    handleDocumentMouseDown,
    handleExternalToolbar,
    handleKeyDown,
    handleMouseMove,
    handleNavigation,
    reloadAnnotations,
    resetAnnotationState,
} from "@/annotation/actions";
import AnnotationOverlay from "@/annotation/components/AnnotationOverlay";
import { ensureAnnotationRoot, removeAnnotationHost } from "@/annotation/host";
import { resolveDictationSettings } from "@/annotation/dictation-settings";
import { readInertiaPage, resetInertiaPage } from "@/annotation/inertia-page";
import { dictationSettings, viewportTick } from "@/annotation/state";

export { annotationMode, annotations, draft, hover, shakeToken } from "@/annotation/state";
export {
    cancelDraft,
    deleteDraft,
    reloadAnnotations,
    setAnnotationMode,
    startEdit,
    submitDraft,
    toggleAnnotationMode,
} from "@/annotation/actions";

let overlayRoot: Root | null = null;
let listenersBound = false;
let toolbarObserver: MutationObserver | null = null;
let stopNavigation: (() => void) | null = null;

function bumpViewport(): void {
    viewportTick.value += 1;
}

function bindListeners(): void {
    if (listenersBound) {
        return;
    }

    document.addEventListener("mousedown", handleDocumentMouseDown, true);
    document.addEventListener("click", handleDocumentClick, true);
    document.addEventListener("mousemove", handleMouseMove, true);
    document.addEventListener("keydown", handleKeyDown, true);
    window.addEventListener("scroll", bumpViewport, true);
    window.addEventListener("resize", bumpViewport);

    const toolbarHost = document.getElementById("laravel-toolbar-shadow-host");

    if (toolbarHost) {
        toolbarObserver = new MutationObserver(handleExternalToolbar);
        toolbarObserver.observe(toolbarHost, { attributes: true, attributeFilter: ["class"] });
    }

    listenersBound = true;
}

function unbindListeners(): void {
    if (!listenersBound) {
        return;
    }

    document.removeEventListener("mousedown", handleDocumentMouseDown, true);
    document.removeEventListener("click", handleDocumentClick, true);
    document.removeEventListener("mousemove", handleMouseMove, true);
    document.removeEventListener("keydown", handleKeyDown, true);
    window.removeEventListener("scroll", bumpViewport, true);
    window.removeEventListener("resize", bumpViewport);
    toolbarObserver?.disconnect();
    toolbarObserver = null;
    listenersBound = false;
}

export function ensureAnnotationRuntime(
    history: RouterHistory,
    toolConfig?: { dictation?: Record<string, unknown> },
): void {
    dictationSettings.value = resolveDictationSettings(toolConfig);

    if (overlayRoot) {
        return;
    }

    const root = ensureAnnotationRoot();
    overlayRoot = createRoot(root);
    overlayRoot.render(createElement(AnnotationOverlay));
    bindListeners();
    readInertiaPage();
    reloadAnnotations(history.location.pathname);
    stopNavigation = history.subscribe(({ location }) => handleNavigation(location.pathname));
}

export function teardownAnnotationRuntime(): void {
    stopNavigation?.();
    stopNavigation = null;
    unbindListeners();
    overlayRoot?.unmount();
    overlayRoot = null;
    resetAnnotationState();
    resetInertiaPage();
    removeAnnotationHost();
}
