import { expect, it, vi } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { queryClient } from "../../src/api/queryClient";
import { openApp } from "./app";

const nested = "/projects/1/documents?folder=11&state=all";
const note = "Project launch notes and decisions for the next release.txt";
function gate() {
    let release!: () => void;
    const promise = new Promise<void>((resolve) => {
        release = resolve;
    });
    return { promise, release };
}

it.each(["save", "reload", "replacement"] as const)(
    "freezes the editor through a delayed %s and its content reload",
    async (operation) => {
        const write = gate();
        const reload = gate();
        let delay = false;
        let writePending = false;
        let reloadPending = false;
        await openApp(nested, {
            wrapTransport: (inner) => async (method, path, body) => {
                const response = await inner(method, path, body);
                if (delay && method === "PUT" && path.endsWith("/content")) {
                    writePending = true;
                    await write.promise;
                }
                if (delay && method === "GET" && path.endsWith("/content")) {
                    reloadPending = true;
                    await reload.promise;
                }
                return response;
            },
        });
        try {
            await page.getByRole("button", { name: note, exact: true }).click();
            const editor = page.getByRole("textbox", { name: "Document content" });
            await expect.element(editor).toHaveValue("first\n");
            delay = true;
            if (operation === "save") {
                await editor.fill("Submitted A");
                await page.getByTestId("documents-save").click();
            } else if (operation === "replacement") {
                await page
                    .getByLabelText("Upload replacement (new version, up to 10 MiB)")
                    .upload(new File(["Replacement A"], "note.txt"));
            } else {
                await page.getByRole("button", { name: "Reload metadata and content" }).click();
            }
            if (operation !== "reload") {
                await expect.poll(() => writePending).toBe(true);
                await expect.element(editor).toHaveAttribute("readonly");
                await expect
                    .element(page.getByLabelText("Upload replacement (new version, up to 10 MiB)"))
                    .toBeDisabled();
                write.release();
            }
            await expect.poll(() => reloadPending).toBe(true);
            // Remain frozen after the write succeeds, while the replacement content is loading.
            await expect.element(editor).toHaveAttribute("readonly");
            reload.release();
            await expect.element(editor).not.toHaveAttribute("readonly");
            await expect
                .element(editor)
                .toHaveValue(
                    operation === "save"
                        ? "Submitted A"
                        : operation === "replacement"
                          ? "Replacement A"
                          : "first\n",
                );
            await editor.fill("Subsequent B");
            await expect.element(editor).toHaveValue("Subsequent B");
            await expect.element(page.getByText("Unsaved draft", { exact: true })).toBeVisible();
        } finally {
            write.release();
            reload.release();
        }
    },
);

it("freezes all create inputs through the delayed write and folder refresh", async () => {
    const write = gate();
    const refresh = gate();
    let created = false;
    let refreshing = false;
    await openApp(nested, {
        wrapTransport: (inner) => async (method, path, body) => {
            const response = await inner(method, path, body);
            if (method === "POST" && path === "/api/v1/projects/1/documents") {
                created = true;
                await write.promise;
            } else if (
                created &&
                method === "GET" &&
                path.startsWith("/api/v1/projects/1/documents?")
            ) {
                refreshing = true;
                await refresh.promise;
            }
            return response;
        },
    });
    try {
        await page.getByTestId("documents-create").click();
        await page.getByRole("button", { name: "New text file", exact: true }).click();
        const name = page.getByRole("textbox", { name: "Name", exact: true });
        const content = page.getByRole("textbox", { name: "Content", exact: true });
        const type = page.getByRole("combobox", { name: "Type", exact: true });
        await name.fill("Submitted.txt");
        await content.fill("Submitted A");
        await page.getByRole("button", { name: "Confirm", exact: true }).click();
        await expect.poll(() => created).toBe(true);
        for (const input of [name, content, type]) await expect.element(input).toBeDisabled();
        write.release();
        await expect.poll(() => refreshing).toBe(true);
        for (const input of [name, content, type]) await expect.element(input).toBeDisabled();
        await expect.element(content).toHaveValue("Submitted A");
        refresh.release();
        await expect.element(page.getByRole("dialog")).not.toBeInTheDocument();
        await page.getByRole("button", { name: "Submitted.txt", exact: true }).click();
        await expect
            .element(page.getByRole("textbox", { name: "Document content" }))
            .toHaveValue("Submitted A");
    } finally {
        write.release();
        refresh.release();
    }
});

it.each(["rows", "destinations", "history"] as const)(
    "does not let automatic %s pagination cancel a delayed refresh",
    async (scope) => {
        // Deliver visibility events deterministically, including while a refetch is pending.
        const observed = new Map<object, () => void>();
        vi.stubGlobal(
            "IntersectionObserver",
            class {
                private callback: IntersectionObserverCallback;
                constructor(callback: IntersectionObserverCallback) {
                    this.callback = callback;
                }
                observe(target: Element) {
                    observed.set(this, () =>
                        this.callback(
                            [{ isIntersecting: true, target } as IntersectionObserverEntry],
                            this as unknown as IntersectionObserver,
                        ),
                    );
                }
                disconnect() {
                    observed.delete(this);
                }
            },
        );
        const refresh = gate();
        let refreshing = false;
        let nextRequests = 0;
        let initialRequests = 0;
        const matches = (path: string) =>
            scope === "history"
                ? path.includes("/1/versions?")
                : path.startsWith("/api/v1/projects/1/documents?") &&
                  (scope === "destinations"
                      ? path.includes("kind=folder")
                      : !path.includes("kind="));
        await openApp(nested, {
            wrapTransport: (inner) => async (method, path, body) => {
                const fixturePath =
                    scope === "destinations" && matches(path)
                        ? path.replace(/&parent_id=11/, "")
                        : path;
                const response = await inner(method, fixturePath.replace(/&cursor=next/, ""), body);
                if (method !== "GET" || !matches(path)) return response;
                const payload = response.payload as {
                    data: { name?: string; number?: number }[];
                    meta: Record<string, unknown>;
                };
                if (path.includes("cursor=")) {
                    nextRequests++;
                    return {
                        ...response,
                        payload: {
                            ...payload,
                            data: [],
                            meta: { ...payload.meta, next_cursor: null },
                        },
                    };
                }
                initialRequests++;
                const data = payload.data.slice(0, 1).map((row) =>
                    scope === "history"
                        ? { ...row, number: initialRequests === 1 ? 1 : 99 }
                        : {
                              ...row,
                              name: initialRequests === 1 ? "Stale name.txt" : "Refreshed name.txt",
                          },
                );
                if (initialRequests > 1) {
                    refreshing = true;
                    await refresh.promise;
                }
                return {
                    ...response,
                    payload: { ...payload, data, meta: { ...payload.meta, next_cursor: "next" } },
                };
            },
        });
        try {
            if (scope === "history")
                await page.getByRole("button", { name: note, exact: true }).click();
            if (scope === "destinations") {
                await page
                    .getByLabelText("Actions for Design attachment.bin", { exact: true })
                    .click();
                await page.getByRole("button", { name: "Move", exact: true }).click();
            }
            await expect.poll(() => observed.size).toBeGreaterThan(0);
            const key =
                scope === "rows"
                    ? ["documents", 1, "list"]
                    : scope === "history"
                      ? ["documents", 1, "versions"]
                      : ["documents", 1, "destinations"];
            const invalidation = queryClient.invalidateQueries({ queryKey: key });
            await expect.poll(() => refreshing).toBe(true);
            // Other scopes can have visible sentinels too. Notify all of them; the refreshing one
            // must be disconnected and may not issue a next-page request until its refresh ends.
            for (const notify of observed.values()) notify();
            expect(nextRequests).toBe(0);
            refresh.release();
            await invalidation;
            if (scope === "history")
                await expect
                    .element(page.getByRole("button", { name: "Download v99", exact: true }))
                    .toBeVisible();
            else
                await expect
                    .element(
                        page.getByRole("button", {
                            name:
                                scope === "destinations"
                                    ? "▸ Refreshed name.txt"
                                    : "Refreshed name.txt",
                            exact: true,
                        }),
                    )
                    .toBeVisible();
            for (const notify of observed.values()) notify();
            await expect.poll(() => nextRequests).toBe(1);
        } finally {
            refresh.release();
            vi.unstubAllGlobals();
        }
    },
);
