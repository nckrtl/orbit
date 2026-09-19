import { deepElementFromPoint, isAnnotationUi, isElementFixed } from "@/annotation/dom";
import { identifyElement } from "@/annotation/identify";
import { readInertiaPage } from "@/annotation/inertia-page";
import { formatReactHoverPath } from "@/annotation/react-inspect";
import { annotationMode, annotations, draft, hover, shakeToken } from "@/annotation/state";
import { createAnnotationId, loadAnnotations, saveAnnotations } from "@/annotation/store";
import { consumeDictationEnter, consumeDictationEscape } from "@/annotation/dictation-keys";
import { isUnrelatedEditable } from "@/annotation/dictation-settings";
import { resolveAnnotationContext } from "@/annotation/context";
import { annotationContextFields, annotationPayload } from "@/annotation/payload";
import { releaseMicrophone, warmMicrophone } from "@/annotation/dictation";
import { captureAnnotationScreenshot } from "@/annotation/screenshot";
import { deleteSyncedAnnotation, fetchAnnotationSync, pushAnnotation } from "@/annotation/sync";
import type { Annotation, AnnotationDraft } from "@/annotation/types";
import { currentBreakpoint, currentScreenSize, currentScrollPosition } from "@/annotation/viewport";

let currentPathname = "";
let pendingPlacement: AnnotationDraft | null = null;
let screenshotTimer = 0;

function persist(): void {
    saveAnnotations(annotations.value, currentPathname || window.location.pathname);
}

export function draftFromPoint(clientX: number, clientY: number): AnnotationDraft | null {
    const element = deepElementFromPoint(clientX, clientY);

    if (!(element instanceof HTMLElement) || isAnnotationUi(element)) {
        return null;
    }

    const { name, path } = identifyElement(element);
    const rect = element.getBoundingClientRect();
    const isFixed = isElementFixed(element);

    return {
        x: (clientX / window.innerWidth) * 100,
        y: isFixed ? clientY : clientY + window.scrollY,
        clientX,
        clientY,
        element: name,
        elementPath: path,
        boundingBox: {
            x: rect.left,
            y: rect.top,
            width: rect.width,
            height: rect.height,
        },
        isFixed,
        comment: "",
        targetElement: element,
        url: window.location.href,
        pathname: window.location.pathname,
        screenSize: currentScreenSize(),
        scrollPosition: currentScrollPosition(),
        breakpoint: currentBreakpoint(),
        ...resolveAnnotationContext(element),
    };
}

export function shakeDraft(): void {
    shakeToken.value += 1;
}

function clearPendingPlacement(): void {
    pendingPlacement = null;

    if (screenshotTimer) {
        window.clearTimeout(screenshotTimer);
        screenshotTimer = 0;
    }
}

function scheduleDraftScreenshot(target: AnnotationDraft): void {
    if (typeof window === "undefined") {
        void attachDraftScreenshot(target);
        return;
    }

    window.clearTimeout(screenshotTimer);
    screenshotTimer = window.setTimeout(() => {
        screenshotTimer = 0;
        void attachDraftScreenshot(target);
    }, 0);
}

async function attachDraftScreenshot(target: AnnotationDraft): Promise<void> {
    if (target.screenshot) {
        return;
    }

    const screenshot = await captureAnnotationScreenshot(target);

    if (!screenshot) {
        return;
    }

    target.screenshot = screenshot;

    if (draft.value === target) {
        draft.value = { ...target, screenshot };
    }
}

export function reloadAnnotations(pathname: string = window.location.pathname): void {
    currentPathname = pathname;
    annotations.value = loadAnnotations(pathname);
    clearPendingPlacement();
    draft.value = null;
    hover.value = null;
}

const visualWaiters = new Map<string, number>();
const VISUAL_APPLY_TIMEOUT_MS = 10_000;

export function applyResolvedAnnotations(
    resolvedIds: string[],
    options: { toById?: Record<string, string>; immediate?: boolean } = {},
): void {
    if (resolvedIds.length === 0) {
        return;
    }

    const ready: string[] = [];

    for (const id of resolvedIds) {
        const annotation = annotations.value.find((item) => item.id === id);
        const to = options.toById?.[id] ?? annotation?.change?.to;

        if (
            options.immediate ||
            !annotation ||
            typeof to !== "string" ||
            to === "" ||
            visualChangeVisible(annotation, to)
        ) {
            ready.push(id);
            continue;
        }

        applyAnnotationStatus([id], "applied");
        applyChangeTargets({ [id]: to });
        waitForVisualApply(annotation, to);
    }

    if (ready.length > 0) {
        removeResolvedPins(ready);
    }
}

function waitForVisualApply(annotation: Annotation, to: string): void {
    if (visualWaiters.has(annotation.id) || typeof window === "undefined") {
        return;
    }

    const started = Date.now();
    const tick = () => {
        if (
            visualChangeVisible(annotation, to) ||
            Date.now() - started >= VISUAL_APPLY_TIMEOUT_MS
        ) {
            visualWaiters.delete(annotation.id);
            removeResolvedPins([annotation.id]);
            return;
        }

        visualWaiters.set(annotation.id, window.setTimeout(tick, 100));
    };

    visualWaiters.set(annotation.id, window.setTimeout(tick, 50));
}

function visualChangeVisible(annotation: Annotation, to: string): boolean {
    if (typeof document === "undefined") {
        return true;
    }

    return Boolean(resolveTargetElement(annotation)?.classList.contains(to));
}

function removeResolvedPins(resolvedIds: string[]): void {
    const resolved = new Set(resolvedIds);
    const remaining = annotations.value.filter((annotation) => !resolved.has(annotation.id));

    for (const id of resolvedIds) {
        const timer = visualWaiters.get(id);

        if (timer) {
            window.clearTimeout(timer);
            visualWaiters.delete(id);
        }
    }

    if (remaining.length === annotations.value.length) {
        return;
    }

    annotations.value = remaining;
    persist();

    if (draft.value?.annotationId && resolved.has(draft.value.annotationId)) {
        draft.value = null;
    }
}

function applyChangeTargets(toById?: Record<string, string>): void {
    if (!toById || Object.keys(toById).length === 0) {
        return;
    }

    let changed = false;

    annotations.value = annotations.value.map((annotation) => {
        const to = toById[annotation.id];

        if (!to || annotation.change?.to === to) {
            return annotation;
        }

        changed = true;

        return {
            ...annotation,
            change: {
                ...annotation.change,
                to,
            },
        };
    });

    if (changed) {
        persist();
    }
}

export function annotationBelongsToCurrentPage(
    annotation: Pick<Annotation, "url" | "pathname">,
): boolean {
    const pathname =
        currentPathname || (typeof window === "undefined" ? "" : window.location.pathname);
    const href = typeof window === "undefined" ? "" : window.location.href;
    const path = annotation.pathname ?? "";
    const url = annotation.url ?? "";

    if (path === "" && url === "") {
        return true;
    }

    if (path !== "" && path === pathname) {
        return true;
    }

    if (url === "") {
        return false;
    }

    try {
        return new URL(url, href || "http://localhost").pathname === pathname;
    } catch {
        return url.includes(pathname);
    }
}

export function applyCreatedAnnotations(incoming: Annotation[]): void {
    const next = new Map(annotations.value.map((annotation) => [annotation.id, annotation]));
    let changed = false;

    for (const annotation of incoming) {
        if ((annotation.status ?? "pending") === "resolved") {
            if (next.delete(annotation.id)) {
                changed = true;
            }

            continue;
        }

        if (!annotationBelongsToCurrentPage(annotation)) {
            continue;
        }

        const existing = next.get(annotation.id);

        if (
            existing &&
            existing.comment === annotation.comment &&
            existing.status === annotation.status &&
            existing.x === annotation.x &&
            existing.y === annotation.y
        ) {
            continue;
        }

        next.set(annotation.id, existing ? { ...existing, ...annotation } : annotation);
        changed = true;
    }

    if (!changed) {
        return;
    }

    annotations.value = [...next.values()];
    persist();
}

export function applyInProgressAnnotations(
    inProgressIds: string[],
    toById?: Record<string, string>,
): void {
    applyAnnotationStatus(inProgressIds, "in_progress");
    applyChangeTargets(toById);
}

export function applyPendingAnnotations(pendingIds: string[]): void {
    applyAnnotationStatus(pendingIds, "pending");
}

function applyAnnotationStatus(ids: string[], status: Annotation["status"]): void {
    if (ids.length === 0) {
        return;
    }

    const matching = new Set(ids);
    let changed = false;

    annotations.value = annotations.value.map((annotation) => {
        if (!matching.has(annotation.id) || annotation.status === status) {
            return annotation;
        }

        changed = true;

        return { ...annotation, status };
    });

    if (changed) {
        persist();
    }
}

export async function pullResolvedAnnotations(): Promise<void> {
    const sync = await fetchAnnotationSync();
    applyCreatedAnnotations(sync.annotations);
    applyResolvedAnnotations(sync.resolvedIds);
    applyInProgressAnnotations(sync.inProgressIds);
}

export function submitDraft(comment: string, source: AnnotationDraft | null = draft.value): void {
    const current = draft.value;
    const trimmed = comment.trim();

    if (!current || !trimmed || (source && source !== current)) {
        return;
    }

    const saved: Annotation = current.annotationId
        ? {
              ...(annotations.value.find(
                  (annotation) => annotation.id === current.annotationId,
              ) ?? {
                  id: current.annotationId,
                  x: current.x,
                  y: current.y,
                  element: current.element,
                  elementPath: current.elementPath,
                  timestamp: Date.now(),
              }),
              comment: trimmed,
              ...annotationContextFields(current),
          }
        : {
              id: createAnnotationId(),
              timestamp: Date.now(),
              status: "pending",
              ...annotationPayload(current, trimmed),
          };

    annotations.value = current.annotationId
        ? annotations.value.map((annotation) => (annotation.id === saved.id ? saved : annotation))
        : [...annotations.value, saved];

    persist();
    void pushAnnotation(saved).then((updated) => {
        if (!updated) {
            return;
        }

        if ((updated.status ?? "pending") === "resolved") {
            applyResolvedAnnotations([updated.id]);
            return;
        }

        applyCreatedAnnotations([updated]);

        if (updated.status === "in_progress") {
            applyInProgressAnnotations([updated.id]);
        }

        void followAnnotationLifecycle(updated.id);
    });
    draft.value = null;
}

async function followAnnotationLifecycle(id: string): Promise<void> {
    for (const delay of [400, 800, 1600, 3200, 5000]) {
        await new Promise((resolve) => window.setTimeout(resolve, delay));

        if (!annotations.value.some((annotation) => annotation.id === id)) {
            return;
        }

        await pullResolvedAnnotations();

        const current = annotations.value.find((annotation) => annotation.id === id);

        if (!current || current.status === "resolved") {
            return;
        }
    }
}

export function deleteDraft(): void {
    const id = draft.value?.annotationId;

    if (!id) {
        draft.value = null;
        return;
    }

    annotations.value = annotations.value.filter((annotation) => annotation.id !== id);
    persist();
    void deleteSyncedAnnotation(id);
    draft.value = null;
}

export function cancelDraft(): void {
    clearPendingPlacement();
    draft.value = null;
}

function resolveTargetElement(annotation: Annotation): HTMLElement | undefined {
    const box = annotation.boundingBox;

    if (!box || box.width === 0 || box.height === 0) {
        return undefined;
    }

    const centerX = box.x + box.width / 2;
    const centerY = box.y + box.height / 2;
    const element = deepElementFromPoint(centerX, centerY);

    if (!(element instanceof HTMLElement) || isAnnotationUi(element)) {
        return undefined;
    }

    const live = element.getBoundingClientRect();

    if (live.width / box.width < 0.5 || live.height / box.height < 0.5) {
        return undefined;
    }

    return element;
}

export function startEdit(annotation: Annotation, clientX?: number, clientY?: number): void {
    const box = annotation.boundingBox;

    draft.value = {
        ...annotationContextFields(annotation),
        annotationId: annotation.id,
        x: annotation.x,
        y: annotation.y,
        clientX: clientX ?? (annotation.x / 100) * window.innerWidth,
        clientY: clientY ?? (annotation.isFixed ? annotation.y : annotation.y - window.scrollY),
        element: annotation.element,
        boundingBox: box ?? {
            x: (annotation.x / 100) * window.innerWidth,
            y: annotation.isFixed ? annotation.y : annotation.y - window.scrollY,
            width: 0,
            height: 0,
        },
        isFixed: Boolean(annotation.isFixed),
        comment: annotation.comment,
        targetElement: resolveTargetElement(annotation),
    };
    hover.value = null;
    scheduleDraftScreenshot(draft.value);
}

export function setAnnotationMode(active: boolean): void {
    annotationMode.value = active;
    document.documentElement.classList.toggle("laravel-toolbar-annotating", active);

    if (!active) {
        clearPendingPlacement();
        draft.value = null;
        hover.value = null;
        releaseMicrophone();
        return;
    }

    void warmMicrophone();
    void pullResolvedAnnotations();
}

export function toggleAnnotationMode(): void {
    setAnnotationMode(!annotationMode.value);
}

function consumeAnnotationGesture(event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
}

export function handleDocumentMouseDown(event: MouseEvent): void {
    if (event.button !== 0 || !annotationMode.value) {
        return;
    }

    const pathTarget =
        (event.composedPath()[0] as Element | undefined) ?? (event.target as Element | null);

    if (isAnnotationUi(pathTarget)) {
        return;
    }

    // Pane rows navigate on mousedown — consume before Orbit handlers run.
    consumeAnnotationGesture(event);

    if (draft.value) {
        return;
    }

    const nextDraft = draftFromPoint(event.clientX, event.clientY);

    if (!nextDraft) {
        clearPendingPlacement();
        return;
    }

    pendingPlacement = nextDraft;
    scheduleDraftScreenshot(nextDraft);
}

export function handleDocumentClick(event: MouseEvent): void {
    const pathTarget =
        (event.composedPath()[0] as Element | undefined) ?? (event.target as Element | null);

    if (isAnnotationUi(pathTarget)) {
        return;
    }

    if (draft.value) {
        consumeAnnotationGesture(event);
        shakeDraft();
        return;
    }

    if (!annotationMode.value) {
        return;
    }

    consumeAnnotationGesture(event);

    const nextDraft = pendingPlacement ?? draftFromPoint(event.clientX, event.clientY);
    const prepared = pendingPlacement;
    pendingPlacement = null;

    if (!nextDraft) {
        return;
    }

    draft.value = nextDraft;
    hover.value = null;

    if (!prepared && !nextDraft.screenshot) {
        scheduleDraftScreenshot(nextDraft);
    }
}

export function handleMouseMove(event: MouseEvent): void {
    if (!annotationMode.value || draft.value) {
        hover.value = null;
        return;
    }

    const element = deepElementFromPoint(event.clientX, event.clientY);

    if (!(element instanceof HTMLElement) || isAnnotationUi(element)) {
        hover.value = null;
        return;
    }

    const { name, path } = identifyElement(element);
    const rect = element.getBoundingClientRect();

    hover.value = {
        name,
        path,
        reactComponents: formatReactHoverPath(element, readInertiaPage()?.component),
        rect: {
            x: rect.left,
            y: rect.top,
            width: rect.width,
            height: rect.height,
        },
        cursorX: event.clientX,
        cursorY: event.clientY,
    };
}

function isEditableKeyTarget(event: KeyboardEvent): boolean {
    for (const node of event.composedPath()) {
        if (
            node instanceof Element &&
            node.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]')
        ) {
            return true;
        }
    }

    return isUnrelatedEditable(event.target) || isUnrelatedEditable(document.activeElement);
}

export function handleKeyDown(event: KeyboardEvent): void {
    if (event.isComposing) {
        return;
    }

    if (event.key.toLowerCase() === "a" && !event.metaKey && !event.ctrlKey && !event.altKey) {
        if (isEditableKeyTarget(event) || annotationMode.value) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        setAnnotationMode(true);
        return;
    }

    if (
        event.key === "Enter" &&
        !event.shiftKey &&
        !event.metaKey &&
        !event.ctrlKey &&
        !event.altKey
    ) {
        if (isUnrelatedEditable(event.target)) {
            return;
        }

        if (consumeDictationEnter()) {
            event.preventDefault();
            event.stopPropagation();
        }

        return;
    }

    if (event.key !== "Escape") {
        return;
    }

    if (consumeDictationEscape()) {
        event.preventDefault();
        event.stopPropagation();
        return;
    }

    if (draft.value || pendingPlacement) {
        event.preventDefault();
        cancelDraft();
        return;
    }

    if (annotationMode.value) {
        event.preventDefault();
        setAnnotationMode(false);
    }
}

export function handleExternalToolbar(): void {
    if (
        document
            .getElementById("laravel-toolbar-shadow-host")
            ?.classList.contains("toolbar-external-active")
    ) {
        setAnnotationMode(false);
    }
}

export function handleNavigation(): void {
    const pathname = window.location.pathname;
    const pathChanged = pathname !== currentPathname;

    currentPathname = pathname;
    annotations.value = loadAnnotations(pathname);

    if (pathChanged) {
        clearPendingPlacement();
        draft.value = null;
        hover.value = null;
    }

    void pullResolvedAnnotations();
}

export function resetAnnotationState(): void {
    for (const timer of visualWaiters.values()) {
        window.clearTimeout(timer);
    }

    visualWaiters.clear();
    setAnnotationMode(false);
    clearPendingPlacement();
    draft.value = null;
    hover.value = null;
    annotations.value = [];
    currentPathname = "";
}
