import { afterEach, expect, it, vi } from "vite-plus/test";
import { GatewayError, setTransport } from "./client";
import {
    documentError,
    downloadDocument,
    inlineEditable,
    uploadBody,
    type DocumentEntry,
} from "./documents";
import fixture from "../../../../packages/php-sdk/fixtures/project-documents/list/default.json";

const entry = fixture.body.data[0] as DocumentEntry;

it("rejects corrupt downloads before creating a browser attachment", async () => {
    setTransport(async () => ({
        status: 200,
        payload: { data: { content_base64: "YmFkYmFk", version: entry.current_version } },
    }));
    await expect(downloadDocument(1, entry)).rejects.toMatchObject({
        code: "project_documents.body_unavailable",
    });
});
afterEach(() => {
    setTransport(null);
    vi.restoreAllMocks();
});

it("only opens bounded admitted text inline, never HTML, SVG or oversized text", () => {
    expect(inlineEditable(entry)).toBe(true);
    for (const media_type of ["text/html", "image/svg+xml", "application/octet-stream"]) {
        expect(
            inlineEditable({
                ...entry,
                current_version: { ...entry.current_version!, media_type },
            }),
        ).toBe(false);
    }
    expect(
        inlineEditable({
            ...entry,
            current_version: { ...entry.current_version!, size_bytes: 1048577 },
        }),
    ).toBe(false);
    expect(inlineEditable({ ...entry, current_version: null })).toBe(false);
});

it("rejects oversized uploads before reading any bytes", async () => {
    const read = vi.fn();
    const file = { size: 10485761, arrayBuffer: read } as unknown as File;
    await expect(uploadBody(file)).rejects.toMatchObject({
        code: "project_documents.content_too_large",
    });
    expect(read).not.toHaveBeenCalled();
});

it("encodes exact bytes with deterministic text types and binary fallback", async () => {
    const bytes = new TextEncoder().encode("  hello\n");
    const file = {
        name: "note.md",
        type: "",
        size: bytes.length,
        arrayBuffer: () => Promise.resolve(bytes.buffer),
    };
    expect(await uploadBody(file as File)).toEqual({
        content_base64: "ICBoZWxsbwo=",
        media_type: "text/markdown",
    });
    expect((await uploadBody({ ...file, name: "note.bin" } as File)).media_type).toBe(
        "application/octet-stream",
    );
});

it("reports stable conflicts and sanitized recovery guidance without provider diagnostics", () => {
    expect(
        documentError(
            new GatewayError("SECRET PROVIDER RESPONSE", 502, "project_documents.body_unavailable"),
        ),
    ).not.toContain("SECRET");
    expect(
        documentError(new GatewayError("conflict", 409, "project_documents.revision_conflict")),
    ).toContain("Copy your draft");
    expect(documentError(new Error("secret network detail"))).toContain(
        "check the exact name first",
    );
    expect(documentError(new Error("secret network detail"))).not.toContain(
        "secret network detail",
    );
});
