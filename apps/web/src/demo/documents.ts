import type { Method } from "../api/client";
import type { DocumentEntry, DocumentVersion } from "../api/documents";
import listFixture from "../../../../packages/php-sdk/fixtures/project-documents/list/default.json";
import readFixture from "../../../../packages/php-sdk/fixtures/project-documents/read/default.json";
import downloadFixture from "../../../../packages/php-sdk/fixtures/project-documents/download/default.json";

/** Demo shapes and initial bytes come from recorded Gateway responses, not a competing schema. */
export function createDemoDocuments() {
    const projects = new Map<number, DocumentEntry[]>();
    const bodies = new Map<
        string,
        { base64: string; text: string; versions: DocumentVersion[]; contents: Map<number, string> }
    >();
    let sequence = 100;
    const conflicted = new Set<string>();
    const recorded = listFixture.body.data[0] as DocumentEntry;
    const seed = (project: number) => {
        if (projects.has(project)) return projects.get(project)!;
        const file = (
            id: number,
            name: string,
            parent: number | null,
            type = "text/plain",
            archived = false,
        ): DocumentEntry => {
            const entry = structuredClone(recorded);
            Object.assign(entry, {
                id,
                project_id: project,
                name,
                parent_id: parent,
                path: name,
                is_archived: archived,
                archived_at: archived ? recorded.created_at : null,
            });
            entry.current_version!.id = id * 10;
            entry.current_version!.media_type = type;
            bodies.set(`${project}:${id}`, {
                base64: downloadFixture.body.data.content_base64,
                text: readFixture.body.data.content_text,
                versions: [structuredClone(entry.current_version!)],
                contents: new Map([[id * 10, downloadFixture.body.data.content_base64]]),
            });
            return entry;
        };
        const folder = (id: number, name: string, parent: number | null): DocumentEntry => ({
            ...structuredClone(recorded),
            id,
            project_id: project,
            kind: "folder",
            name,
            parent_id: parent,
            path: name,
            current_version: null,
        });
        const entries = [
            folder(10, "Planning", null),
            folder(11, "Specifications", 10),
            file(1, "Project launch notes and decisions for the next release.txt", 11),
            file(2, "Archived research.txt", 11, "text/plain", true),
            file(3, "Design attachment.bin", 11, "application/octet-stream"),
            file(4, "Concurrent draft.txt", 11),
            file(5, "Welcome.txt", null),
        ];
        projects.set(project, entries);
        return entries;
    };
    const answer = (data: unknown, status = 200, cursor: string | null = null) => ({
        status,
        payload: {
            data: structuredClone(data),
            meta: { request_id: "demo-documents", next_cursor: cursor },
        },
    });
    const fail = (status: number, code: string, message: string) => ({
        status,
        payload: { error: { code: `project_documents.${code}`, message } },
    });
    return async (method: Method, path: string, input: unknown) => {
        const url = new URL(path, "http://demo");
        const match =
            /^\/api\/v1\/projects\/(\d+)\/documents(?:\/(search|\d+))?(?:\/(content|download|versions|restore-version|archive|restore))?$/.exec(
                url.pathname,
            );
        if (!match) return undefined;
        const project = Number(match[1]);
        const entries = seed(project);
        const body = (input ?? {}) as Record<string, unknown>;
        const find = (id: unknown) => entries.find((entry) => entry.id === Number(id));
        const archived = (entry: DocumentEntry): boolean =>
            entry.archived_at !== null ||
            (entry.parent_id !== null &&
                Boolean(find(entry.parent_id) && archived(find(entry.parent_id)!)));
        const display = (entry: DocumentEntry): DocumentEntry => ({
            ...entry,
            is_archived: archived(entry),
            path:
                entry.parent_id === null
                    ? entry.name
                    : `${display(find(entry.parent_id)!).path}/${entry.name}`,
        });
        const paginate = <T>(rows: T[]) => {
            const start = Number(url.searchParams.get("cursor") ?? 0);
            const limit = Number(url.searchParams.get("limit") ?? 50);
            return answer(
                rows.slice(start, start + limit),
                200,
                start + limit < rows.length ? String(start + limit) : null,
            );
        };
        if (method === "GET" && (!match[2] || match[2] === "search")) {
            const state = url.searchParams.get("state") ?? "active";
            const parent = Number(url.searchParams.get("parent_id")) || null;
            const q = (url.searchParams.get("q") ?? "").toLowerCase();
            return paginate(
                entries
                    .map(display)
                    .filter(
                        (entry) =>
                            (state === "all" || entry.is_archived === (state === "archived")) &&
                            (match[2] === "search"
                                ? entry.path.toLowerCase().includes(q)
                                : entry.parent_id === parent) &&
                            (!url.searchParams.get("kind") ||
                                entry.kind === url.searchParams.get("kind")),
                    )
                    .sort((a, b) =>
                        a.kind === b.kind
                            ? a.name.localeCompare(b.name)
                            : a.kind === "folder"
                              ? -1
                              : 1,
                    ),
            );
        }
        const entry = find(match[2]);
        const record = entry ? bodies.get(`${project}:${entry.id}`) : undefined;
        if (match[2] && !entry) return fail(404, "not_found", "Entry not found.");
        if (method === "GET" && entry) {
            if (!match[3]) return answer(display(entry));
            if (match[3] === "versions") return paginate(record?.versions ?? []);
            const version = url.searchParams.has("version")
                ? record?.versions.find((v) => v.id === Number(url.searchParams.get("version")))
                : entry.current_version;
            if (!version || !record) return fail(404, "not_found", "Version not found.");
            const base64 = record.contents.get(version.id)!;
            if (match[3] === "download")
                return answer({
                    entry_id: entry.id,
                    revision: entry.revision,
                    version,
                    content_base64: base64,
                });
            if (match[3] === "content")
                return answer({
                    entry_id: entry.id,
                    revision: entry.revision,
                    version,
                    content_text: new TextDecoder().decode(
                        Uint8Array.from(atob(base64), (char) => char.charCodeAt(0)),
                    ),
                });
        }
        if (entry && body.expected_revision !== entry.revision)
            return fail(409, "revision_conflict", "Another writer changed this entry.");
        // A reproducible concurrent writer for conflict/recovery UI review.
        if (entry?.id === 4 && method === "PUT" && !conflicted.has(`${project}:4`)) {
            conflicted.add(`${project}:4`);
            entry.revision++;
            return fail(409, "revision_conflict", "Another writer changed this entry.");
        }
        if (entry && archived(entry) && match[3] !== "restore" && method !== "DELETE")
            return fail(409, "archived", "Restore ancestors first.");
        const parent =
            body.parent_id === undefined
                ? (entry?.parent_id ?? null)
                : (body.parent_id as number | null);
        if ((method === "POST" && !entry) || method === "PATCH") {
            if (parent !== null && (!find(parent) || archived(find(parent)!)))
                return fail(409, "archived", "Invalid destination.");
            let ancestor = find(parent);
            while (ancestor) {
                if (ancestor.id === entry?.id)
                    return fail(422, "invalid_parent", "Cannot move into a descendant.");
                ancestor = find(ancestor.parent_id);
            }
            if (
                entries.some(
                    (other) =>
                        other.id !== entry?.id &&
                        other.parent_id === parent &&
                        other.name === (body.name ?? entry?.name),
                )
            )
                return fail(409, "name_conflict", "Name reserved by a sibling.");
        }
        async function write(target: DocumentEntry, base64: string, type: string) {
            const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
            const sha256 = Array.from(
                new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
                (byte) => byte.toString(16).padStart(2, "0"),
            ).join("");
            if (
                target.current_version?.sha256 === sha256 &&
                target.current_version.media_type === type
            )
                return;
            const version: DocumentVersion = {
                id: ++sequence,
                number: (target.current_version?.number ?? 0) + 1,
                media_type: type,
                size_bytes: bytes.length,
                sha256,
                created_at: new Date().toISOString(),
                created_by_node_id: 1,
            };
            const data = bodies.get(`${project}:${target.id}`) ?? {
                base64,
                text: "",
                versions: [] as DocumentVersion[],
                contents: new Map<number, string>(),
            };
            data.versions.unshift(version);
            data.contents.set(version.id, base64);
            bodies.set(`${project}:${target.id}`, data);
            target.current_version = version;
        }
        const encoded = () =>
            (body.content_base64 as string) ??
            btoa(
                Array.from(
                    new TextEncoder().encode((body.content_text as string | undefined) ?? ""),
                    (byte) => String.fromCharCode(byte),
                ).join(""),
            );
        if (method === "POST" && !entry) {
            const created: DocumentEntry = {
                ...structuredClone(recorded),
                id: ++sequence,
                project_id: project,
                kind: String(body.kind),
                name: String(body.name),
                parent_id: parent,
                current_version: null,
            };
            entries.push(created);
            if (created.kind === "file")
                await write(
                    created,
                    encoded(),
                    (body.media_type as string | undefined) ?? "text/plain",
                );
            return answer(display(created), 201);
        }
        if (!entry) return fail(404, "not_found", "Entry not found.");
        if (method === "PATCH") {
            entry.name = (body.name as string | undefined) ?? entry.name;
            entry.parent_id = parent;
        } else if (method === "PUT")
            await write(
                entry,
                encoded(),
                (body.media_type as string | undefined) ?? entry.current_version!.media_type,
            );
        else if (match[3] === "restore-version") {
            const version = record?.versions.find((v) => v.id === body.version_id);
            if (!version) return fail(404, "not_found", "Version not found.");
            await write(entry, record!.contents.get(version.id)!, version.media_type);
        } else if (match[3] === "archive") entry.archived_at = new Date().toISOString();
        else if (match[3] === "restore") {
            if (entry.parent_id && archived(find(entry.parent_id)!))
                return fail(409, "archived", "Restore ancestors first.");
            entry.archived_at = null;
        } else if (method === "DELETE") {
            const descendants = (id: number): number[] =>
                entries
                    .filter((other) => other.parent_id === id)
                    .flatMap((other) => [other.id, ...descendants(other.id)]);
            const ids = descendants(entry.id);
            if (ids.length && !body.recursive)
                return fail(409, "folder_not_empty", "Inspect and confirm recursive removal.");
            for (let i = entries.length - 1; i >= 0; i--)
                if (entries[i]?.id === entry.id || ids.includes(entries[i]!.id))
                    entries.splice(i, 1);
            return answer({ id: entry.id, removed: true, cleanup_pending: true });
        }
        entry.revision++;
        entry.updated_at = new Date().toISOString();
        return answer(display(entry));
    };
}
