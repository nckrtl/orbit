import { api, GatewayError } from "./client";
import type { components } from "./schema";

export type DocumentVersion = Required<components["schemas"]["DocumentVersion"]>;
export type DocumentEntry = Omit<
    Required<components["schemas"]["DocumentEntry"]>,
    "current_version"
> & {
    current_version: DocumentVersion | null;
};
export type DocumentContent = {
    entry_id: number;
    revision: number;
    version: DocumentVersion;
    content_text: string;
};
export const documentPath = (project: number, entry?: number) =>
    `/api/v1/projects/${project}/documents${entry === undefined ? "" : `/${entry}`}`;
export const inlineEditable = (entry: DocumentEntry) =>
    entry.current_version !== null &&
    ["text/plain", "text/markdown", "application/json", "application/yaml", "text/csv"].includes(
        entry.current_version.media_type,
    ) &&
    entry.current_version.size_bytes <= 1048576;

export function documentError(error: unknown): string {
    if (!(error instanceof GatewayError))
        return "The request could not be confirmed. Keep your draft. Reload metadata before retrying; for a create, check the exact name first.";
    const guidance: Record<string, string> = {
        "project_documents.revision_conflict":
            "Another writer changed this entry. Copy your draft or deliberately reload before saving again. No change was committed.",
        "project_documents.upload_abandoned":
            "The upload expired. Reload metadata before starting a new upload.",
        "project_documents.body_unavailable":
            "Stored bytes are missing or corrupt. Ask the operator to recover storage; metadata and history remain available.",
        "project_documents.storage_unavailable":
            "Storage is unavailable. Keep your draft and retry after storage recovers.",
        "project_documents.storage_not_configured":
            "Ask the Gateway operator to configure document storage.",
        "project_documents.archived":
            "Restore the parent folders before restoring or editing this entry.",
        "project_documents.not_editable":
            "Use download and upload replacement instead of inline editing.",
        "project_documents.name_conflict":
            "Choose another name. Archived siblings also reserve names.",
    };
    return `${error.code ?? "request.failed"}: ${guidance[error.code ?? ""] ?? error.message}`;
}

export async function uploadBody(
    file: File,
): Promise<{ content_base64: string; media_type: string }> {
    if (file.size > 10485760)
        throw new GatewayError(
            "Uploads must be at most 10 MiB.",
            413,
            "project_documents.content_too_large",
        );
    const bytes = new Uint8Array(await file.arrayBuffer());
    let binary = "";
    for (let offset = 0; offset < bytes.length; offset += 8192)
        binary += String.fromCharCode(...bytes.subarray(offset, offset + 8192));
    const extension = file.name.split(".").pop()?.toLowerCase();
    const types: Record<string, string> = {
        md: "text/markdown",
        txt: "text/plain",
        json: "application/json",
        yaml: "application/yaml",
        yml: "application/yaml",
        csv: "text/csv",
    };
    return {
        content_base64: btoa(binary),
        media_type: types[extension ?? ""] ?? (file.type || "application/octet-stream"),
    };
}

export async function downloadDocument(
    project: number,
    entry: DocumentEntry,
    version?: number,
): Promise<void> {
    const body = await api<{ content_base64: string; version: DocumentVersion }>(
        "GET",
        `${documentPath(project, entry.id)}/download${version === undefined ? "" : `?version=${version}`}`,
    );
    const bytes = Uint8Array.from(atob(body.content_base64), (char) => char.charCodeAt(0));
    const digest = Array.from(
        new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
        (byte) => byte.toString(16).padStart(2, "0"),
    ).join("");
    if (
        bytes.length > 10485760 ||
        bytes.length !== body.version.size_bytes ||
        digest !== body.version.sha256
    )
        throw new GatewayError(
            "Download integrity verification failed. Ask the operator to recover storage.",
            502,
            "project_documents.body_unavailable",
        );
    // Always an attachment: never navigate to, execute, or render user HTML/SVG.
    const url = URL.createObjectURL(new Blob([bytes], { type: "application/octet-stream" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = entry.name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
