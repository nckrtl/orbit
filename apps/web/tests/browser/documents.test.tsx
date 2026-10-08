import { expect, it, vi } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { openApp } from "./app";

const nested = "/projects/1/documents?folder=11&state=all";

it("uploads exact binary bytes and replaces content as a version with the captured revision", async () => {
    const app = await openApp(nested);
    await page.getByTestId("documents-create").click();
    await page.getByRole("button", { name: "Upload file", exact: true }).click();
    await page
        .getByLabelText("File (up to 10 MiB)")
        .upload(new File([new Uint8Array([0, 255, 10])], "binary.bin"));
    await page.getByRole("button", { name: "Confirm", exact: true }).click();
    await page.getByRole("button", { name: "binary.bin", exact: true }).click();
    await expect.element(page.getByText("Version history", { exact: true })).toBeVisible();
    await page
        .getByLabelText("Upload replacement (new version, up to 10 MiB)")
        .upload(new File(["replacement\n"], "note.txt"));
    await expect
        .element(page.getByRole("textbox", { name: "Document content" }))
        .toHaveValue("replacement\n");
    const create = app.gateway.requests.find(
        (request) => request.method === "POST" && request.path === "/api/v1/projects/1/documents",
    );
    expect(create?.body).toEqual({
        kind: "file",
        name: "binary.bin",
        parent_id: 11,
        content_base64: "AP8K",
        media_type: "application/octet-stream",
    });
    const write = app.gateway.requests.find((request) => request.method === "PUT");
    expect(write?.body).toEqual({
        expected_revision: 1,
        content_base64: "cmVwbGFjZW1lbnQK",
        media_type: "text/plain",
    });
});

it("requires confirmation to discard drafts on Close or route navigation and reloads only deliberately", async () => {
    const app = await openApp(nested);
    await page.getByRole("button", { name: "Concurrent draft.txt", exact: true }).click();
    const editor = page.getByRole("textbox", { name: "Document content" });
    await expect.element(editor).toHaveValue("first\n");
    await editor.fill("Keep me");
    const confirmation = vi.spyOn(window, "confirm").mockReturnValue(false);
    try {
        await page.getByRole("button", { name: "Close dialog" }).click();
        await expect.element(editor).toHaveValue("Keep me");
        void app.router.navigate({ to: "/$section/$id", params: { section: "projects", id: "1" } });
        await expect.poll(() => confirmation.mock.calls.length).toBeGreaterThan(1);
        expect(app.url()).toContain("/documents");
        await page.getByRole("button", { name: "Reload metadata and content" }).click();
        await expect.element(editor).toHaveValue("Keep me");
        confirmation.mockReturnValue(true);
        await page.getByRole("button", { name: "Reload metadata and content" }).click();
        await expect.element(editor).toHaveValue("first\n");
    } finally {
        confirmation.mockRestore();
    }
});

it("restores historical content as a new version, never rewriting history", async () => {
    const app = await openApp(nested);
    await page
        .getByRole("button", {
            name: "Project launch notes and decisions for the next release.txt",
            exact: true,
        })
        .click();
    const editor = page.getByRole("textbox", { name: "Document content" });
    await expect.element(editor).toHaveValue("first\n");
    await editor.fill("second\n");
    await page.getByTestId("documents-save").click();
    await expect.element(page.getByText("Saved", { exact: true })).toBeVisible();
    const confirmation = vi.spyOn(window, "confirm").mockReturnValue(true);
    try {
        await page.getByRole("button", { name: "Restore v1", exact: true }).click();
    } finally {
        confirmation.mockRestore();
    }
    await expect.element(editor).toHaveValue("first\n");
    expect(
        app.gateway.requests.find((request) => request.path.endsWith("/restore-version"))?.body,
    ).toEqual({ expected_revision: 2, version_id: 10 });
    await expect
        .element(page.getByRole("button", { name: "Download v3", exact: true }))
        .toBeVisible();
    await expect
        .element(page.getByRole("button", { name: "Download v1", exact: true }))
        .toBeVisible();
});

it("keeps phone navigation and controls within the viewport without a fixed table", async () => {
    await page.viewport(393, 659);
    try {
        await openApp(nested);
        await expect
            .element(page.getByText("Design attachment.bin", { exact: true }))
            .toBeVisible();
        expect(document.documentElement.scrollWidth).toBeLessThanOrEqual(393);
        const list = page.getByTestId("documents-list").element().getBoundingClientRect();
        expect(list.top).toBeLessThan(300);
        for (const id of ["documents-create", "documents-filters", "documents-breadcrumbs"]) {
            const bounds = page.getByTestId(id).element().getBoundingClientRect();
            expect(bounds.left).toBeGreaterThanOrEqual(0);
            expect(bounds.right).toBeLessThanOrEqual(393);
        }
        await page.getByTestId("documents-filters").click();
        const sheet = page.getByRole("dialog").element().getBoundingClientRect();
        expect(sheet.right).toBeLessThanOrEqual(393);
        await page.getByRole("button", { name: "Close dialog" }).click();
        await page
            .getByRole("navigation", { name: "Document folders" })
            .getByRole("button", { name: "Root", exact: true })
            .click();
        await expect
            .element(page.getByRole("button", { name: "▸ Planning", exact: true }))
            .toBeVisible();
    } finally {
        await page.viewport(1280, 800);
    }
});

const actions = (name: string) => page.getByLabelText(`Actions for ${name}`, { exact: true });
const dialog = () => page.getByRole("dialog");
const confirm = () => dialog().getByRole("button", { name: "Confirm", exact: true });

it("opens Documents from every Project menu, navigates nested folders, and searches paths", async () => {
    const app = await openApp("/projects/1");
    await page.getByTestId("project-documents").click();
    await expect.poll(app.url).toBe("/projects/1/documents");
    await page.getByRole("button", { name: "▸ Planning", exact: true }).click();
    await page.getByRole("button", { name: "▸ Specifications", exact: true }).click();
    await expect.element(page.getByText("Design attachment.bin", { exact: true })).toBeVisible();
    await page.getByTestId("documents-filters").click();
    await page.getByRole("textbox", { name: "Name or path" }).fill("Planning/Specifications");
    await page.getByRole("combobox", { name: "Archive state" }).selectOptions("all");
    await page.getByRole("button", { name: "Apply filters" }).click();
    await expect.element(page.getByText("Archived research.txt", { exact: true })).toBeVisible();
    await expect
        .poll(() => app.gateway.requests.filter((r) => r.path.includes("/documents/search")).length)
        .toBeGreaterThan(0);
    await page
        .getByRole("navigation", { name: "Document folders" })
        .getByRole("button", { name: "Root", exact: true })
        .click();
    await expect
        .element(page.getByRole("button", { name: "▸ Planning", exact: true }))
        .toBeVisible();
});

it("creates folders and JSON files with exact untrimmed text, then saves using the captured content revision", async () => {
    const app = await openApp(nested);
    await page.getByTestId("documents-create").click();
    await page.getByRole("button", { name: "Create folder", exact: true }).click();
    await page.getByRole("textbox", { name: "Name", exact: true }).fill("Review");
    await confirm().click();
    await expect.element(page.getByRole("button", { name: "▸ Review", exact: true })).toBeVisible();
    await page.getByTestId("documents-create").click();
    await page.getByRole("button", { name: "New text file", exact: true }).click();
    await page.getByRole("textbox", { name: "Name", exact: true }).fill("settings.json");
    await page
        .getByRole("combobox", { name: "Type", exact: true })
        .selectOptions("application/json");
    await page.getByRole("textbox", { name: "Content", exact: true }).fill('  {"safe":true}\n');
    await confirm().click();
    await page.getByRole("button", { name: "settings.json", exact: true }).click();
    const editor = page.getByRole("textbox", { name: "Document content" });
    await expect.element(editor).toHaveValue('  {"safe":true}\n');
    await editor.fill('{"safe":false}');
    await page.getByTestId("documents-save").click();
    await expect.element(page.getByText("Saved", { exact: true })).toBeVisible();
    const write = app.gateway.requests.find(
        (request) => request.method === "PUT" && request.path.endsWith("/content"),
    );
    expect(write?.body).toEqual({
        expected_revision: 1,
        content_text: '{"safe":false}',
        media_type: "application/json",
    });
    await expect
        .element(page.getByText("v2 · application/json · 14 bytes", { exact: true }))
        .toBeVisible();
});

it("keeps a dirty draft on revision conflict and refuses silent retries", async () => {
    const app = await openApp(nested);
    await page.getByRole("button", { name: "Concurrent draft.txt", exact: true }).click();
    const editor = page.getByRole("textbox", { name: "Document content" });
    await expect.element(editor).toHaveValue("first\n");
    await editor.fill("My unsaved draft");
    await page.getByTestId("documents-save").click();
    await expect
        .element(page.getByTestId("documents-error"))
        .toHaveTextContent("project_documents.revision_conflict");
    await expect.element(editor).toHaveValue("My unsaved draft");
    expect(app.gateway.requests.filter((request) => request.method === "PUT")).toHaveLength(1);
    await page.getByRole("button", { name: "Safe preview" }).click();
    await expect.element(page.getByText("My unsaved draft", { exact: true })).toBeVisible();
    await expect.element(page.getByRole("button", { name: "Copy draft" })).toBeVisible();
});

it("preserves the draft and exposes recovery guidance when storage is unavailable", async () => {
    await openApp(nested, {
        wrapTransport: (inner) => async (method, path, body) =>
            method === "PUT" && path.endsWith("/content")
                ? {
                      status: 503,
                      payload: {
                          error: {
                              code: "project_documents.storage_unavailable",
                              message: "Storage failed",
                          },
                      },
                  }
                : inner(method, path, body),
    });
    await page
        .getByRole("button", {
            name: "Project launch notes and decisions for the next release.txt",
            exact: true,
        })
        .click();
    const editor = page.getByRole("textbox", { name: "Document content" });
    await expect.element(editor).toHaveValue("first\n");
    await editor.fill("Draft during outage");
    await page.getByTestId("documents-save").click();
    await expect.element(page.getByTestId("documents-error")).toHaveTextContent("Keep your draft");
    await expect.element(editor).toHaveValue("Draft during outage");
});

it("never executes user markup in previews and treats attachments as upload/download only", async () => {
    await openApp(nested);
    await page
        .getByRole("button", {
            name: "Project launch notes and decisions for the next release.txt",
            exact: true,
        })
        .click();
    const editor = page.getByRole("textbox", { name: "Document content" });
    await expect.element(editor).toHaveValue("first\n");
    await editor.fill('<svg onload="document.body.dataset.executed=1"></svg>');
    await page.getByRole("button", { name: "Safe preview" }).click();
    expect(document.body.dataset.executed).toBeUndefined();
    expect(dialog().element().querySelector("svg")).toBeNull();
    // Reload discards only after explicit confirmation; exercise attachment separately below.
});

it("attachment details expose history, checksum, author and replacement without an inline editor", async () => {
    const app = await openApp(nested);
    await page.getByRole("button", { name: "Design attachment.bin", exact: true }).click();
    await expect.element(page.getByText("Version history", { exact: true })).toBeVisible();
    await expect
        .element(page.getByRole("textbox", { name: "Document content" }))
        .not.toBeInTheDocument();
    await expect.element(page.getByText("application/octet-stream", { exact: true })).toBeVisible();
    await expect
        .element(page.getByRole("button", { name: "Download v30" }))
        .not.toBeInTheDocument();
    await expect
        .element(page.getByRole("button", { name: "Download v1", exact: true }))
        .toBeVisible();
    await expect.element(page.getByText("SHA-256:", { exact: false })).toBeVisible();
    expect(
        app.gateway.requests.some((r) => r.path === "/api/v1/projects/1/documents/3/content"),
    ).toBe(false);
});

it("renames, moves, archives and restores with the displayed entry revisions", async () => {
    const app = await openApp(nested);
    await actions("Design attachment.bin").click();
    await page.getByRole("button", { name: "Rename", exact: true }).click();
    await page.getByRole("textbox", { name: "Name", exact: true }).fill("Renamed.bin");
    await confirm().click();
    await expect
        .element(page.getByRole("button", { name: "Renamed.bin", exact: true }))
        .toBeVisible();
    await actions("Renamed.bin").click();
    await page.getByRole("button", { name: "Move", exact: true }).click();
    await dialog().getByRole("button", { name: "Root", exact: true }).click();
    await confirm().click();
    await expect
        .element(page.getByRole("button", { name: "Renamed.bin", exact: true }))
        .not.toBeInTheDocument();
    const updates = app.gateway.requests.filter((r) => r.method === "PATCH");
    expect(updates.map((r) => r.body)).toEqual([
        { expected_revision: 1, name: "Renamed.bin" },
        { expected_revision: 2, parent_id: null },
    ]);
    await actions("Archived research.txt").click();
    await page.getByRole("button", { name: "Restore", exact: true }).click();
    await confirm().click();
    await actions("Archived research.txt").click();
    await page.getByRole("button", { name: "Archive", exact: true }).click();
    await confirm().click();
    expect(
        app.gateway.requests
            .filter((r) => r.method === "POST" && /\/(restore|archive)$/.test(r.path))
            .map((r) => r.body),
    ).toEqual([{ expected_revision: 1 }, { expected_revision: 2 }]);
});

it("distinguishes irreversible recursive folder removal and sends explicit scope", async () => {
    const app = await openApp("/projects/1/documents");
    await actions("Planning").click();
    await page.getByRole("button", { name: "Remove permanently", exact: true }).click();
    await expect.element(page.getByText(/Permanently remove folder/)).toHaveTextContent("Planning");
    await page.getByRole("checkbox").click();
    await page.getByRole("button", { name: "Confirm permanent removal" }).click();
    await expect
        .element(page.getByRole("button", { name: "▸ Planning", exact: true }))
        .not.toBeInTheDocument();
    expect(app.gateway.requests.filter((r) => r.method === "DELETE")).toEqual([
        {
            method: "DELETE",
            path: "/api/v1/projects/1/documents/10",
            body: { expected_revision: 1, recursive: true },
        },
    ]);
});

it("retains folder and filters while appending cursor pages", async () => {
    const app = await openApp(nested, {
        wrapTransport: (inner) => async (method, path, body) => {
            const response = await inner(method, path.replace(/&cursor=next-page/, ""), body);
            if (method === "GET" && /\/documents\?/.test(path)) {
                const payload = response.payload as {
                    data: { id: number; name: string }[];
                    meta: Record<string, unknown>;
                };
                if (!path.includes("cursor="))
                    return {
                        ...response,
                        payload: {
                            ...payload,
                            data: payload.data.slice(0, 1),
                            meta: { ...payload.meta, next_cursor: "next-page" },
                        },
                    };
                return {
                    ...response,
                    payload: {
                        ...payload,
                        data: payload.data.slice(1),
                        meta: { ...payload.meta, next_cursor: null },
                    },
                };
            }
            return response;
        },
    });
    await expect
        .element(page.getByRole("button", { name: "Design attachment.bin", exact: true }))
        .toBeVisible();
    await expect
        .poll(
            () =>
                app.gateway.requests.filter(
                    (r) => r.path.includes("parent_id=11") && r.path.includes("state=all"),
                ).length,
        )
        .toBeGreaterThan(1);
});
