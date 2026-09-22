import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import { deleteDraft, reloadAnnotations, submitDraft } from "../../src/annotation/actions";
import { submitOneShotTask } from "../../src/annotation/commander";
import { teardownAnnotationRuntime } from "../../src/annotation/runtime";
import { annotations, draft } from "../../src/annotation/state";
import { loadAnnotations, saveAnnotations, storageKey } from "../../src/annotation/store";
import type { Annotation, AnnotationDraft } from "../../src/annotation/types";

vi.mock("../../src/annotation/commander", () => ({ submitOneShotTask: vi.fn() }));

const firstPath = "/__annotation-collection-a";
const secondPath = "/__annotation-collection-b";
const original: Annotation = {
    id: "existing",
    x: 10,
    y: 20,
    comment: "original",
    element: "button",
    elementPath: "main > button",
    timestamp: Date.now(),
    pathname: firstPath,
    url: `${window.location.origin}${firstPath}`,
    status: "in_progress",
};
const draftFields: AnnotationDraft = {
    ...original,
    clientX: 10,
    clientY: 20,
    boundingBox: { x: 1, y: 2, width: 30, height: 40 },
    isFixed: false,
    screenshot: "data:image/png;base64,mock",
    component: "ExampleButton.tsx",
};

type Outcome = Awaited<ReturnType<typeof submitOneShotTask>>;
function deferred() {
    let resolve!: (outcome: Outcome) => void;
    const promise = new Promise<Outcome>((onResolve) => {
        resolve = onResolve;
    });

    return { promise, resolve };
}

let requests: ReturnType<typeof deferred>[];
let writes: ReturnType<typeof vi.spyOn<Storage, "setItem">>;

beforeEach(() => {
    teardownAnnotationRuntime();
    localStorage.removeItem(storageKey(firstPath));
    localStorage.removeItem(storageKey(secondPath));
    reloadAnnotations(firstPath);
    requests = [];
    vi.mocked(submitOneShotTask).mockImplementation(() => {
        const request = deferred();
        requests.push(request);
        return request.promise;
    });
    writes = vi.spyOn(Storage.prototype, "setItem");
    vi.spyOn(console, "warn").mockImplementation(() => undefined);
});

afterEach(async () => {
    teardownAnnotationRuntime();
    for (const request of requests) {
        request.resolve({ ok: true });
        await request.promise;
    }
    vi.restoreAllMocks();
    vi.mocked(submitOneShotTask).mockReset();
    localStorage.removeItem(storageKey(firstPath));
    localStorage.removeItem(storageKey(secondPath));
});

function seed(annotation: Annotation = original) {
    saveAnnotations([annotation], firstPath);
    reloadAnnotations(firstPath);
    writes.mockClear();
}

function edit(comment: string) {
    draft.value = { ...draftFields, annotationId: original.id };
    submitDraft(comment);
}

async function complete(index: number, ok: boolean) {
    const request = requests[index]!;
    request.resolve({ ok, error: ok ? undefined : "mock failure" });
    await request.promise;
}

describe("annotation collection ownership", () => {
    it("saves once before submitting an unchanged snapshot and does not resave success", async () => {
        draft.value = { ...draftFields };
        submitDraft("  new comment  ");

        expect(writes).toHaveBeenCalledOnce();
        expect(draft.value).toBeNull();
        expect(annotations.value).toHaveLength(1);
        const saved = annotations.value[0]!;
        expect(saved).toMatchObject({
            comment: "new comment",
            status: "pending",
            pathname: firstPath,
            url: original.url,
            component: draftFields.component,
            screenshot: draftFields.screenshot,
            boundingBox: draftFields.boundingBox,
        });
        expect(submitOneShotTask).toHaveBeenCalledExactlyOnceWith(saved);
        expect(loadAnnotations(firstPath)).toEqual([saved]);

        await complete(0, true);

        expect(writes).toHaveBeenCalledOnce();
        expect(annotations.value[0]).toBe(saved);
    });

    it.each([true, false])(
        "does not recreate a deleted annotation when submission returns %s",
        async (ok) => {
            seed();
            edit("edited");
            draft.value = { ...draftFields, annotationId: original.id };
            deleteDraft();
            expect(writes).toHaveBeenCalledTimes(2);

            await complete(0, ok);

            expect(annotations.value).toEqual([]);
            expect(loadAnnotations(firstPath)).toEqual([]);
            expect(writes).toHaveBeenCalledTimes(2);
        },
    );

    it.each([true, false])(
        "ignores an earlier edit returning %s after the latest edit",
        async (oldOk) => {
            seed();
            edit("first edit");
            edit("latest edit");
            await complete(1, true);
            const latest = annotations.value[0];

            await complete(0, oldOk);

            expect(annotations.value[0]).toBe(latest);
            expect(annotations.value[0]).toMatchObject({
                comment: "latest edit",
                status: "in_progress",
            });
            expect(loadAnnotations(firstPath)).toEqual(annotations.value);
            expect(writes).toHaveBeenCalledTimes(2);
        },
    );

    it("only persists a failed submission when its current revision needs a status change", async () => {
        seed();
        edit("edited");
        expect(writes).toHaveBeenCalledOnce();

        await complete(0, false);

        expect(annotations.value[0]).toMatchObject({ comment: "edited", status: "pending" });
        expect(writes).toHaveBeenCalledTimes(2);
        edit("retry");
        await complete(1, false);
        expect(annotations.value[0]).toMatchObject({ comment: "retry", status: "pending" });
        expect(writes).toHaveBeenCalledTimes(3);
    });

    it.each([true, false])(
        "ignores a response returning %s after navigation away and back",
        async (ok) => {
            seed();
            edit("saved on first route");
            reloadAnnotations(secondPath);
            expect(annotations.value).toEqual([]);
            reloadAnnotations(firstPath);
            const reloaded = annotations.value[0];

            await complete(0, ok);

            expect(annotations.value[0]).toBe(reloaded);
            expect(annotations.value[0]?.status).toBe("in_progress");
            expect(loadAnnotations(secondPath)).toEqual([]);
            expect(writes).toHaveBeenCalledOnce();
        },
    );

    it.each([true, false])("ignores a response returning %s after teardown", async (ok) => {
        seed();
        edit("saved before teardown");
        teardownAnnotationRuntime();

        await complete(0, ok);

        expect(annotations.value).toEqual([]);
        expect(loadAnnotations(firstPath)[0]?.comment).toBe("saved before teardown");
        expect(loadAnnotations(firstPath)[0]?.status).toBe("in_progress");
        expect(writes).toHaveBeenCalledOnce();
    });

    it("keeps resolved and expired stored annotations out of the loaded collection", () => {
        saveAnnotations(
            [
                original,
                { ...original, id: "resolved", status: "resolved" },
                { ...original, id: "expired", timestamp: 1 },
            ],
            firstPath,
        );

        reloadAnnotations(firstPath);

        expect(annotations.value).toEqual([original]);
    });
});
