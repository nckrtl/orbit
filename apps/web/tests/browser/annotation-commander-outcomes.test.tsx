import { afterEach, describe, expect, it, vi } from "vite-plus/test";
import { reloadAnnotations, submitDraft } from "../../src/annotation/actions";
import { configureCommander } from "../../src/annotation/commander";
import { teardownAnnotationRuntime } from "../../src/annotation/runtime";
import { annotations, draft } from "../../src/annotation/state";
import { loadAnnotations, saveAnnotations, storageKey } from "../../src/annotation/store";
import type { Annotation } from "../../src/annotation/types";

const pathname = "/__annotation-commander-outcomes";

afterEach(() => {
    teardownAnnotationRuntime();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    localStorage.removeItem(storageKey(pathname));
});

describe("annotation Commander outcomes", () => {
    it.each([{ task: null }, { dry_run: true, warning: "not forwarded" }])(
        "keeps a non-created annotation pending after HTTP success: %j",
        async (body) => {
            teardownAnnotationRuntime();
            configureCommander({ enabled: true });
            vi.spyOn(console, "warn").mockImplementation(() => undefined);
            const fetchMock = vi.fn(async () => Response.json(body));
            vi.stubGlobal("fetch", fetchMock);
            const original: Annotation = {
                id: "existing",
                x: 10,
                y: 20,
                comment: "original",
                element: "button",
                elementPath: "main > button",
                timestamp: Date.now(),
                pathname,
                status: "in_progress",
            };
            saveAnnotations([original], pathname);
            reloadAnnotations(pathname);
            draft.value = {
                ...original,
                annotationId: original.id,
                clientX: 10,
                clientY: 20,
                boundingBox: { x: 1, y: 2, width: 30, height: 40 },
                isFixed: false,
            };

            submitDraft("edited");

            await expect.poll(() => annotations.value[0]?.status).toBe("pending");
            expect(fetchMock).toHaveBeenCalledOnce();
            expect(loadAnnotations(pathname)[0]).toMatchObject({
                comment: "edited",
                status: "pending",
            });
        },
    );
});
