import { afterEach, describe, expect, it, vi } from "vite-plus/test";
import { configureCommander, submitOneShotTask } from "@/annotation/commander";
import type { Annotation } from "@/annotation/types";

const annotation: Annotation = {
    id: "ann-1",
    x: 10,
    y: 20,
    comment: "Make the footer quieter",
    element: "footer",
    elementPath: "div > footer",
    timestamp: 1,
};

afterEach(() => {
    vi.unstubAllGlobals();
    configureCommander({
        enabled: true,
        project: "commander",
        endpoint: "/__orbit/commander/one-shot",
    });
});

describe("submitOneShotTask", () => {
    it("posts a one-shot create-task payload matching SubmitOneShotTask", async () => {
        const fetchMock = vi.fn<
            (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>
        >(async () => Response.json({ task: { id: 42 } }, { status: 200 }));
        vi.stubGlobal("fetch", fetchMock);

        const result = await submitOneShotTask(annotation);

        expect(result).toEqual({ ok: true, taskId: 42 });
        expect(fetchMock).toHaveBeenCalledOnce();
        const call = fetchMock.mock.calls[0];
        expect(call).toBeDefined();
        const [url, init] = call!;
        expect(url).toBe("/__orbit/commander/one-shot");
        expect(init?.method).toBe("POST");
        const raw = init?.body;
        const body = JSON.parse(typeof raw === "string" ? raw : "");
        expect(body).toMatchObject({
            project_id: "commander",
            title: "Make the footer quieter",
            kind: "one-shot",
            creation_key: "annotation:ann-1",
        });
        expect(body.description).toMatchObject({ id: "ann-1", comment: "Make the footer quieter" });
    });

    it("uses the configured project id", async () => {
        configureCommander({ project: "orbit" });
        const fetchMock = vi.fn<
            (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>
        >(async () => Response.json({ task: { id: 7 } }));
        vi.stubGlobal("fetch", fetchMock);

        await submitOneShotTask(annotation);

        const call = fetchMock.mock.calls[0];
        expect(call).toBeDefined();
        const raw = call![1]?.body;
        const body = JSON.parse(typeof raw === "string" ? raw : "");
        expect(body.project_id).toBe("orbit");
    });

    it("no-ops when disabled", async () => {
        configureCommander({ enabled: false });
        const fetchMock = vi.fn();
        vi.stubGlobal("fetch", fetchMock);

        const result = await submitOneShotTask(annotation);

        expect(result.ok).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("rejects a missing annotation id", async () => {
        const result = await submitOneShotTask({ ...annotation, id: "" });
        expect(result).toEqual({ ok: false, error: "Annotation id is required" });
    });

    it.each([
        null,
        {},
        { task: null },
        { task: {} },
        ...[0, -1, 1.5, "42", Number.MAX_SAFE_INTEGER + 1].map((id) => ({ task: { id } })),
        { task: { id: 42 }, error: "failed" },
        { task: { id: 42 }, dry_run: true },
    ])("rejects a successful HTTP response without a valid task: %j", async (body) => {
        vi.stubGlobal(
            "fetch",
            vi.fn(async () => Response.json(body)),
        );
        expect(await submitOneShotTask(annotation)).toMatchObject({ ok: false });
    });

    it.each([undefined, { id: null }])(
        "keeps dry runs explicit without a task id: %j",
        async (task) => {
            vi.stubGlobal(
                "fetch",
                vi.fn(async () => Response.json({ dry_run: true, warning: "not forwarded", task })),
            );
            expect(await submitOneShotTask(annotation)).toEqual({
                ok: true,
                dryRun: true,
                warning: "not forwarded",
            });
        },
    );

    it("bounds error messages returned by the adapter", async () => {
        vi.stubGlobal(
            "fetch",
            vi.fn(async () => Response.json({ error: "x".repeat(1000) }, { status: 502 })),
        );
        expect(await submitOneShotTask(annotation)).toEqual({ ok: false, error: "x".repeat(512) });
    });

    it.each(["fetch", "read", "json"])(
        "does not expose diagnostics after a %s failure",
        async (failure) => {
            vi.stubGlobal(
                "fetch",
                vi.fn(async () => {
                    if (failure === "fetch") throw new Error("private diagnostic");
                    if (failure === "read")
                        return {
                            json: async () => {
                                throw new Error("private diagnostic");
                            },
                        };
                    return new Response("private diagnostic", { status: 502 });
                }),
            );
            expect(await submitOneShotTask(annotation)).toEqual({
                ok: false,
                error: "Could not submit the annotation to Commander.",
            });
        },
    );
});
