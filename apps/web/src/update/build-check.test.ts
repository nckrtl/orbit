import { afterEach, beforeEach, describe, expect, it, vi } from "vite-plus/test";
import {
    blocksUnload,
    CHUNK_RELOAD_KEY,
    createBuildCheck,
    fetchServedBuild,
    installBuildCheck,
    isEditingField,
    isImportFailure,
    RELOAD_KEY,
    type BuildCheckOptions,
    type ReloadMemory,
} from "./build-check";

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function memoryStore(): ReloadMemory & { values: Map<string, string> } {
    const values = new Map<string, string>();

    return {
        values,
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => void values.set(key, value),
        removeItem: (key) => void values.delete(key),
    };
}

function setup(overrides: Partial<BuildCheckOptions> = {}) {
    let clock = 1_000_000;
    const served = vi.fn(async (): Promise<string | null> => "new");
    const assign = vi.fn();
    const reload = vi.fn();
    const memory = memoryStore();
    const check = createBuildCheck({
        build: "old",
        served,
        now: () => clock,
        assign,
        reload,
        memory,
        ...overrides,
    });

    return {
        check,
        served,
        assign,
        reload,
        memory,
        advance: (ms: number) => {
            clock += ms;
        },
    };
}

describe("navigation", () => {
    it("loads the target URL in full when the Gateway serves another build", async () => {
        const { check, assign, reload, memory, advance } = setup();
        advance(60_000);

        check.navigated("/nodes/1?tab=tools");
        await settle();

        expect(assign).toHaveBeenCalledExactlyOnceWith("/nodes/1?tab=tools");
        expect(reload).not.toHaveBeenCalled();
        expect(memory.values.get(RELOAD_KEY)).toBe("new");
    });

    it("stays client-side while the served build is the page's own", async () => {
        const { check, served, assign, advance } = setup({ build: "new" });
        advance(60_000);

        check.navigated("/tasks");
        await settle();

        expect(served).toHaveBeenCalledOnce();
        expect(assign).not.toHaveBeenCalled();
    });

    it("loads the latest target when more navigations start during the read", async () => {
        let answer: (build: string) => void = () => {};
        const { check, assign, advance } = setup({
            served: () => new Promise((resolve) => (answer = resolve)),
        });
        advance(60_000);

        check.navigated("/tasks");
        check.navigated("/activity");
        answer("new");
        await settle();

        expect(assign).toHaveBeenCalledExactlyOnceWith("/activity");
    });

    it("reads at most once per interval, counting from page load", async () => {
        const { check, served, advance } = setup({ build: "new" });

        check.navigated("/a");
        advance(59_999);
        check.navigated("/b");
        expect(served).not.toHaveBeenCalled();

        advance(1);
        check.navigated("/c");
        await settle();
        check.navigated("/d");
        expect(served).toHaveBeenCalledOnce();

        advance(60_000);
        check.navigated("/e");
        expect(served).toHaveBeenCalledTimes(2);
    });

    it("honors a configured interval", () => {
        const { check, served, advance } = setup({ intervalMs: 5_000 });

        advance(4_999);
        check.navigated("/a");
        expect(served).not.toHaveBeenCalled();
        advance(1);
        check.navigated("/a");
        expect(served).toHaveBeenCalledOnce();
    });

    it("ignores an unknown served build", async () => {
        const { check, assign, advance } = setup({ served: async () => null });
        advance(60_000);

        check.navigated("/tasks");
        await settle();

        expect(assign).not.toHaveBeenCalled();
    });

    it("waits while a draft would ask before unload, then loads on the next navigation", async () => {
        let draft = true;
        const { check, served, assign, advance } = setup({ hasUnsavedInput: () => draft });
        advance(60_000);

        check.navigated("/projects/1/documents");
        await settle();
        expect(assign).not.toHaveBeenCalled();

        draft = false;
        check.navigated("/tasks");
        expect(assign).toHaveBeenCalledExactlyOnceWith("/tasks");
        expect(served).toHaveBeenCalledOnce();
    });

    it("loads the target once the left page's draft is gone", async () => {
        let draft = true;
        const { check, assign, advance } = setup({ hasUnsavedInput: () => draft });
        advance(60_000);

        check.navigated("/nodes");
        await settle();
        check.settled();
        expect(assign).not.toHaveBeenCalled();

        draft = false;
        check.settled();
        expect(assign).toHaveBeenCalledExactlyOnceWith("/nodes");
    });

    it("waits while the user types in a field that changes the URL", async () => {
        let typing = true;
        const { check, assign, advance } = setup({ isEditing: () => typing });
        advance(60_000);

        check.navigated("/activity?command=node");
        await settle();
        expect(assign).not.toHaveBeenCalled();

        typing = false;
        check.navigated("/nodes");
        expect(assign).toHaveBeenCalledExactlyOnceWith("/nodes");
    });

    it("does nothing on a settled navigation without a newer build", async () => {
        const { check, assign, advance } = setup({ build: "new" });
        advance(60_000);

        check.navigated("/nodes");
        await settle();
        check.settled();

        expect(assign).not.toHaveBeenCalled();
    });
});

describe("resume", () => {
    it("reloads when the Gateway serves another build", async () => {
        const { check, assign, reload, advance } = setup();
        advance(60_000);

        check.resumed();
        await settle();

        expect(reload).toHaveBeenCalledOnce();
        expect(assign).not.toHaveBeenCalled();
    });

    it("never reloads over unsaved input, and the next navigation loads the build", async () => {
        let typing = true;
        const editing = setup({ isEditing: () => typing });
        const drafting = setup({ hasUnsavedInput: () => true });
        for (const each of [editing, drafting]) {
            each.advance(60_000);
            each.check.resumed();
        }
        await settle();

        expect(editing.reload).not.toHaveBeenCalled();
        expect(drafting.reload).not.toHaveBeenCalled();

        // A click on a link moves the focus out of the field.
        typing = false;
        editing.check.navigated("/nodes");
        expect(editing.assign).toHaveBeenCalledExactlyOnceWith("/nodes");
        expect(editing.served).toHaveBeenCalledOnce();
    });

    it("does not reload twice for the same build", async () => {
        const { check, reload, memory, advance } = setup();
        memory.setItem(RELOAD_KEY, "new");
        advance(60_000);

        check.resumed();
        await settle();

        expect(reload).not.toHaveBeenCalled();
    });

    it("looks for a later build after the guard refused one", async () => {
        let build = "new";
        const { check, assign, memory, advance } = setup({ served: async () => build });
        memory.setItem(RELOAD_KEY, "new");
        advance(60_000);
        check.navigated("/nodes");
        await settle();
        expect(assign).not.toHaveBeenCalled();

        build = "newer";
        advance(60_000);
        check.navigated("/tasks");
        await settle();
        expect(assign).toHaveBeenCalledExactlyOnceWith("/tasks");
    });

    it("clears the mark when the loaded build arrived, and keeps it on an older page", () => {
        const arrived = memoryStore();
        arrived.setItem(RELOAD_KEY, "new");
        createBuildCheck({ build: "new", assign: vi.fn(), reload: vi.fn(), memory: arrived });
        expect(arrived.values.has(RELOAD_KEY)).toBe(false);

        const stale = memoryStore();
        stale.setItem(RELOAD_KEY, "new");
        createBuildCheck({ build: "old", assign: vi.fn(), reload: vi.fn(), memory: stale });
        expect(stale.values.get(RELOAD_KEY)).toBe("new");
    });

    it("reloads again for a later build", async () => {
        const { check, reload, memory, advance } = setup({ served: async () => "newer" });
        memory.setItem(RELOAD_KEY, "new");
        advance(60_000);

        check.resumed();
        await settle();

        expect(reload).toHaveBeenCalledOnce();
        expect(memory.values.get(RELOAD_KEY)).toBe("newer");
    });

    it("keeps the guard in memory when sessionStorage throws", async () => {
        const throwing: ReloadMemory = {
            getItem: () => {
                throw new Error("SecurityError");
            },
            setItem: () => {
                throw new Error("QuotaExceededError");
            },
            removeItem: () => {
                throw new Error("SecurityError");
            },
        };
        const { check, reload, advance } = setup({ memory: throwing });
        advance(60_000);
        check.resumed();
        await settle();
        advance(60_000);
        check.resumed();
        await settle();

        expect(reload).toHaveBeenCalledOnce();
    });
});

describe("failed chunks", () => {
    it("reload once per build", () => {
        const { check, reload, memory } = setup();

        expect(check.chunkFailed()).toBe(true);
        expect(check.chunkFailed()).toBe(false);
        expect(reload).toHaveBeenCalledOnce();
        expect(memory.values.get(CHUNK_RELOAD_KEY)).toBe("old");
    });

    it("do not reload over unsaved input", () => {
        const { check, reload } = setup({ isEditing: () => true });

        expect(check.chunkFailed()).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });

    it("recognize the import failures of each engine", () => {
        expect(
            isImportFailure(new TypeError("Failed to fetch dynamically imported module: /a.js")),
        ).toBe(true);
        expect(isImportFailure(new TypeError("Importing a module script failed."))).toBe(true);
        expect(
            isImportFailure(new TypeError("error loading dynamically imported module: /a.js")),
        ).toBe(true);
        expect(isImportFailure(new TypeError("Failed to fetch"))).toBe(false);
        expect(isImportFailure("Importing a module script failed.")).toBe(false);
    });
});

describe("fetchServedBuild", () => {
    const answer = (body: string, init: ResponseInit = {}) =>
        vi.fn(async (_url: RequestInfo | URL, _init?: RequestInit) => new Response(body, init));

    it("reads the build past every cache", async () => {
        const fetcher = answer('{"build":"abc"}');

        await expect(fetchServedBuild(fetcher)).resolves.toBe("abc");
        expect(fetcher).toHaveBeenCalledWith(
            "/version.json",
            expect.objectContaining({ cache: "no-store" }),
        );
    });

    it("gives null for HTML, a failed response, a bad shape, and a network error", async () => {
        await expect(fetchServedBuild(answer("<!doctype html>"))).resolves.toBeNull();
        await expect(
            fetchServedBuild(answer('{"build":"abc"}', { status: 404 })),
        ).resolves.toBeNull();
        await expect(fetchServedBuild(answer('{"build":7}'))).resolves.toBeNull();
        await expect(fetchServedBuild(answer("null"))).resolves.toBeNull();
        await expect(
            fetchServedBuild(async () => {
                throw new TypeError("Load failed");
            }),
        ).resolves.toBeNull();
    });
});

describe("unsaved input", () => {
    it("follows the router blockers that ask before unload", () => {
        expect(blocksUnload([])).toBe(false);
        expect(blocksUnload([{ enableBeforeUnload: false }])).toBe(false);
        expect(blocksUnload([{ enableBeforeUnload: () => false }])).toBe(false);
        expect(blocksUnload([{ enableBeforeUnload: false }, { enableBeforeUnload: true }])).toBe(
            true,
        );
        expect(blocksUnload([{ enableBeforeUnload: () => true }])).toBe(true);
        expect(blocksUnload([{}])).toBe(true);
    });

    it("counts a focused writable text field as editing", () => {
        const field = (tagName: string, props: Record<string, unknown> = {}) =>
            ({ tagName, ...props }) as unknown as Element;

        expect(isEditingField(null)).toBe(false);
        expect(isEditingField(field("BODY"))).toBe(false);
        expect(isEditingField(field("TEXTAREA"))).toBe(true);
        expect(isEditingField(field("TEXTAREA", { readOnly: true }))).toBe(false);
        expect(isEditingField(field("INPUT", { type: "text" }))).toBe(true);
        expect(isEditingField(field("INPUT", { type: "search" }))).toBe(true);
        expect(isEditingField(field("INPUT", { type: "checkbox" }))).toBe(false);
        expect(isEditingField(field("INPUT", { type: "text", disabled: true }))).toBe(false);
        expect(isEditingField(field("DIV", { isContentEditable: true }))).toBe(true);
        expect(
            isEditingField(field("DIV", { shadowRoot: { activeElement: field("TEXTAREA") } })),
        ).toBe(true);
        expect(
            isEditingField(field("DIV", { shadowRoot: { activeElement: field("BUTTON") } })),
        ).toBe(false);
    });
});

describe("installBuildCheck", () => {
    function fakeWindow(served: string) {
        const win = new EventTarget() as EventTarget & Record<string, unknown>;
        const document = Object.assign(new EventTarget(), {
            visibilityState: "visible",
            activeElement: null,
        });
        const location = { assign: vi.fn(), reload: vi.fn() };
        Object.assign(win, {
            document,
            location,
            sessionStorage: memoryStore(),
            setTimeout: globalThis.setTimeout,
        });
        const fetcher = vi.fn(async () => new Response(JSON.stringify({ build: served })));
        vi.stubGlobal("fetch", fetcher);

        return { win: win as unknown as Window, document, location, fetcher };
    }

    function fakeRouter() {
        type Listener = (event: { toLocation: { href: string }; hrefChanged: boolean }) => void;
        const listeners = new Map<string, Listener>();

        return {
            subscribe: vi.fn((event: "onBeforeLoad" | "onResolved", fn: Listener) => {
                listeners.set(event, fn);

                return () => listeners.delete(event);
            }),
            navigate: (href: string) =>
                listeners.get("onBeforeLoad")?.({ toLocation: { href }, hrefChanged: true }),
            resolve: (href: string) =>
                listeners.get("onResolved")?.({ toLocation: { href }, hrefChanged: true }),
            listening: () => listeners.size,
        };
    }

    async function installed(hasUnsavedInput: () => boolean = () => false) {
        const { win, document, location, fetcher } = fakeWindow("new");
        const router = fakeRouter();
        const stop = installBuildCheck({ build: "old", router, hasUnsavedInput, window: win });
        vi.setSystemTime(Date.now() + 60_000);

        return { win, document, location, fetcher, router, stop };
    }

    beforeEach(() => void vi.useFakeTimers({ toFake: ["Date"] }));
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it("reloads a page that becomes visible again, not one that hides", async () => {
        const { document, location, fetcher } = await installed();

        document.visibilityState = "hidden";
        document.dispatchEvent(new Event("visibilitychange"));
        expect(fetcher).not.toHaveBeenCalled();

        document.visibilityState = "visible";
        document.dispatchEvent(new Event("visibilitychange"));
        await vi.waitFor(() => expect(location.reload).toHaveBeenCalledOnce());
    });

    it("reloads a page restored from the back-forward cache, not a fresh one", async () => {
        const { win, location, fetcher } = await installed();

        win.dispatchEvent(Object.assign(new Event("pageshow"), { persisted: false }));
        expect(fetcher).not.toHaveBeenCalled();

        win.dispatchEvent(Object.assign(new Event("pageshow"), { persisted: true }));
        await vi.waitFor(() => expect(location.reload).toHaveBeenCalledOnce());
    });

    it("loads the navigation target after the left page's draft is gone", async () => {
        let draft = true;
        const { location, router } = await installed(() => draft);

        router.navigate("/activity");
        await settle();
        expect(location.assign).not.toHaveBeenCalled();

        draft = false;
        router.resolve("/activity");
        await vi.waitFor(() =>
            expect(location.assign).toHaveBeenCalledExactlyOnceWith("/activity"),
        );
    });

    it("reloads once for a failed preload or import, and stops listening", async () => {
        const { win, location, router, stop } = await installed();

        const preload = new Event("vite:preloadError", { cancelable: true });
        win.dispatchEvent(preload);
        expect(preload.defaultPrevented).toBe(true);
        expect(location.reload).toHaveBeenCalledOnce();

        const rejection = Object.assign(new Event("unhandledrejection", { cancelable: true }), {
            reason: new TypeError("Importing a module script failed."),
        });
        win.dispatchEvent(rejection);
        expect(rejection.defaultPrevented).toBe(false);
        expect(location.reload).toHaveBeenCalledOnce();

        stop();
        expect(router.listening()).toBe(0);
    });
});
