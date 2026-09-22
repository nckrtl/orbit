import { createBrowserHistory, type RouterHistory } from "@tanstack/react-router";
import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { draftFromPoint, setAnnotationMode, submitDraft } from "../../src/annotation/actions";
import { submitOneShotTask } from "../../src/annotation/commander";
import { ANNOTATION_HOST_ID } from "../../src/annotation/host";
import { ensureAnnotationRuntime, teardownAnnotationRuntime } from "../../src/annotation/runtime";
import { annotations, draft } from "../../src/annotation/state";
import { loadAnnotations, saveAnnotations, storageKey } from "../../src/annotation/store";
import type { Annotation, AnnotationDraft } from "../../src/annotation/types";
import { openApp } from "./app";

vi.mock("../../src/annotation/commander", () => ({
    configureCommander: vi.fn(),
    submitOneShotTask: vi.fn(async () => ({ ok: true, taskId: 1 })),
}));

const firstPath = "/processes";
const secondPath = "/nodes";
const first: Annotation = {
    id: "route-first",
    x: 50,
    y: 100,
    comment: "first route",
    element: "main",
    elementPath: "main",
    timestamp: Date.now(),
    pathname: firstPath,
    status: "in_progress",
};
const second: Annotation = {
    ...first,
    id: "route-second",
    comment: "second route",
    pathname: secondPath,
};
const editDraft: AnnotationDraft = {
    ...first,
    annotationId: first.id,
    clientX: 100,
    clientY: 100,
    boundingBox: { x: 1, y: 1, width: 100, height: 100 },
    isFixed: false,
};

let history: RouterHistory;
let originalUrl: string;
let originalHistoryState: unknown;
let originalSettings: Window["__TOOLBAR_AGENTATION__"];

beforeEach(() => {
    teardownAnnotationRuntime();
    originalUrl = window.location.href;
    originalHistoryState = window.history.state;
    originalSettings = window.__TOOLBAR_AGENTATION__;
    window.__TOOLBAR_AGENTATION__ = { dictation: { provider: "", autoStart: false } };
    vi.spyOn(navigator.mediaDevices, "getUserMedia").mockRejectedValue(
        new DOMException("Mock only", "NotAllowedError"),
    );
    vi.spyOn(console, "warn").mockImplementation(() => undefined);
    history = createBrowserHistory();
    history.replace(firstPath);
    history.flush();
    saveAnnotations([first], firstPath);
    saveAnnotations([second], secondPath);
});

afterEach(() => {
    teardownAnnotationRuntime();
    history.destroy();
    window.history.replaceState(originalHistoryState, "", originalUrl);
    window.__TOOLBAR_AGENTATION__ = originalSettings;
    localStorage.removeItem(storageKey(firstPath));
    localStorage.removeItem(storageKey(secondPath));
    vi.restoreAllMocks();
    vi.mocked(submitOneShotTask).mockClear();
});

function visibleComments() {
    const shadow = document.getElementById(ANNOTATION_HOST_ID)?.shadowRoot;
    return [...(shadow?.querySelectorAll<HTMLButtonElement>("[data-annotation-marker]") ?? [])].map(
        (marker) => marker.title,
    );
}

function pendingSubmission() {
    let resolve!: (value: Awaited<ReturnType<typeof submitOneShotTask>>) => void;
    const promise = new Promise<Awaited<ReturnType<typeof submitOneShotTask>>>((onResolve) => {
        resolve = onResolve;
    });
    vi.mocked(submitOneShotTask).mockReturnValueOnce(promise);
    draft.value = { ...editDraft };
    submitDraft("pending edit");
    return { promise, resolve };
}

describe("annotation browser history ownership", () => {
    it("loads push and replace destinations and saves each draft under the displayed pathname", async () => {
        const { router } = await openApp(firstPath, { history });
        setAnnotationMode(true);
        await expect.poll(visibleComments).toEqual(["In progress: first route"]);
        draft.value = { ...editDraft };

        await router.navigate({ to: "/$section", params: { section: "nodes" } });

        expect(window.location.pathname).toBe(secondPath);
        expect(draft.value).toBeNull();
        expect(annotations.value).toEqual([second]);
        await expect.poll(visibleComments).toEqual(["In progress: second route"]);
        const placed = draftFromPoint(300, 300);
        expect(placed?.pathname).toBe(secondPath);
        draft.value = placed;
        submitDraft("new second route annotation");
        expect(loadAnnotations(firstPath)).toEqual([first]);
        expect(loadAnnotations(secondPath).map((annotation) => annotation.comment)).toEqual([
            "second route",
            "new second route annotation",
        ]);

        await router.navigate({ to: "/$section", params: { section: "processes" }, replace: true });

        expect(window.location.pathname).toBe(firstPath);
        expect(annotations.value).toEqual([first]);
        await expect.poll(visibleComments).toEqual(["In progress: first route"]);
    });

    it("follows real browser back and forward without mixing saved collections", async () => {
        const { router } = await openApp(firstPath, { history });
        await router.navigate({ to: "/$section", params: { section: "nodes" } });

        history.back();

        await expect.poll(() => window.location.pathname).toBe(firstPath);
        await expect.poll(() => annotations.value).toEqual([first]);
        history.forward();
        await expect.poll(() => window.location.pathname).toBe(secondPath);
        await expect.poll(() => annotations.value).toEqual([second]);
        expect(loadAnnotations(firstPath)).toEqual([first]);
        expect(loadAnnotations(secondPath)).toEqual([second]);
    });

    it("keeps the draft and saved revision on query-only navigation", async () => {
        const { router } = await openApp(firstPath, { history });
        const saved = annotations.value[0];
        const currentDraft = { ...editDraft };
        draft.value = currentDraft;

        await router.navigate({
            to: "/$section",
            params: { section: "processes" },
            search: { node: "beast" },
        });

        expect(window.location.pathname).toBe(firstPath);
        expect(window.location.search).toContain("node=beast");
        expect(draft.value).toBe(currentDraft);
        expect(annotations.value[0]).toBe(saved);
    });

    it("retains pending completion ownership across a query change", async () => {
        const { router } = await openApp(firstPath, { history });
        const request = pendingSubmission();
        await router.navigate({
            to: "/$section",
            params: { section: "processes" },
            search: { node: "beast" },
        });

        request.resolve({ ok: false, error: "mock failure" });
        await request.promise;

        expect(annotations.value[0]).toMatchObject({ comment: "pending edit", status: "pending" });
        expect(loadAnnotations(firstPath)).toEqual(annotations.value);
    });

    it("invalidates pending completion ownership after a route round trip", async () => {
        const { router } = await openApp(firstPath, { history });
        const request = pendingSubmission();
        await router.navigate({ to: "/$section", params: { section: "nodes" } });
        await router.navigate({ to: "/$section", params: { section: "processes" } });
        const reloaded = annotations.value[0];

        request.resolve({ ok: false, error: "mock failure" });
        await request.promise;

        expect(annotations.value[0]).toBe(reloaded);
        expect(annotations.value[0]).toMatchObject({
            comment: "pending edit",
            status: "in_progress",
        });
        expect(loadAnnotations(secondPath)).toEqual([second]);
    });

    it("binds once, unsubscribes on teardown, and loads the current route on remount", async () => {
        const { router } = await openApp(firstPath, { history });
        const boundCount = history.subscribers.size;
        ensureAnnotationRuntime(history);
        expect(history.subscribers.size).toBe(boundCount);

        teardownAnnotationRuntime();
        expect(history.subscribers.size).toBe(boundCount - 1);
        teardownAnnotationRuntime();
        expect(history.subscribers.size).toBe(boundCount - 1);
        await router.navigate({ to: "/$section", params: { section: "nodes" } });
        expect(annotations.value).toEqual([]);

        ensureAnnotationRuntime(history);

        expect(history.subscribers.size).toBe(boundCount);
        expect(annotations.value).toEqual([second]);
    });
});
