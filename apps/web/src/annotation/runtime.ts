import { createRoot, type Root } from "react-dom/client";
import { createElement } from "react";
import {
    handleDocumentClick,
    handleDocumentMouseDown,
    handleExternalToolbar,
    handleKeyDown,
    handleMouseMove,
    handleNavigation,
    applyCreatedAnnotations,
    applyInProgressAnnotations,
    applyPendingAnnotations,
    applyResolvedAnnotations,
    pullResolvedAnnotations,
    reloadAnnotations,
    resetAnnotationState,
} from "@/annotation/actions";
import AnnotationOverlay from "@/annotation/components/AnnotationOverlay";
import { ensureAnnotationRoot, removeAnnotationHost } from "@/annotation/host";
import { resolveDictationSettings } from "@/annotation/dictation-settings";
import { cacheInertiaPage, readInertiaPage, resetInertiaPage } from "@/annotation/inertia-page";
import { subscribeAnnotationEvents } from "@/annotation/sync";
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
let stopAnnotationEvents: (() => void) | null = null;

function bumpViewport(): void {
    viewportTick.value += 1;
}

function startResolveSync(): void {
    stopResolveSync();
    void pullResolvedAnnotations();
    stopAnnotationEvents = subscribeAnnotationEvents({
        onCreated: applyCreatedAnnotations,
        onResolved: (ids, toById) => applyResolvedAnnotations(ids, { toById }),
        onDeleted: (ids) => applyResolvedAnnotations(ids, { immediate: true }),
        onProgress: applyInProgressAnnotations,
        onPending: applyPendingAnnotations,
    });
}

function stopResolveSync(): void {
    stopAnnotationEvents?.();
    stopAnnotationEvents = null;
}

function bindListeners(): void {
    if (listenersBound) {
        return;
    }

    document.addEventListener("mousedown", handleDocumentMouseDown, true);
    document.addEventListener("click", handleDocumentClick, true);
    document.addEventListener("mousemove", handleMouseMove, true);
    document.addEventListener("keydown", handleKeyDown, true);
    window.addEventListener("popstate", handleNavigation);
    window.addEventListener("scroll", bumpViewport, true);
    window.addEventListener("resize", bumpViewport);
    document.addEventListener("inertia:navigate", handleInertiaNavigation);
    document.addEventListener("inertia:success", handleInertiaNavigation);

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
    window.removeEventListener("popstate", handleNavigation);
    window.removeEventListener("scroll", bumpViewport, true);
    window.removeEventListener("resize", bumpViewport);
    document.removeEventListener("inertia:navigate", handleInertiaNavigation);
    document.removeEventListener("inertia:success", handleInertiaNavigation);
    toolbarObserver?.disconnect();
    toolbarObserver = null;
    listenersBound = false;
}

export function ensureAnnotationRuntime(toolConfig?: {
    dictation?: Record<string, unknown>;
}): void {
    dictationSettings.value = resolveDictationSettings(toolConfig);

    if (overlayRoot) {
        return;
    }

    const root = ensureAnnotationRoot();
    overlayRoot = createRoot(root);
    overlayRoot.render(createElement(AnnotationOverlay));
    bindListeners();
    readInertiaPage();
    reloadAnnotations();
    startResolveSync();
}

function handleInertiaNavigation(event: Event): void {
    cacheInertiaPage((event as CustomEvent).detail?.page);
    handleNavigation();
}

export function teardownAnnotationRuntime(): void {
    stopResolveSync();
    unbindListeners();
    overlayRoot?.unmount();
    overlayRoot = null;
    resetAnnotationState();
    resetInertiaPage();
    removeAnnotationHost();
}
