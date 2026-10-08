import { useEffect, useRef, useState, type ReactNode } from "react";
import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { useBlocker, useNavigate, useParams, useSearch } from "@tanstack/react-router";
import { api, apiEnvelope } from "../api/client";
import {
    documentPath,
    documentError,
    downloadDocument,
    inlineEditable,
    uploadBody,
    type DocumentContent,
    type DocumentEntry,
    type DocumentVersion,
} from "../api/documents";
import { useFleet } from "../api/queries";
import type { Project } from "../api/types";
import { RecordLayout } from "./RecordLayout";
import { SectionMenu } from "../ui/SectionMenu";

const control =
    "cursor-pointer border border-edge px-2 py-1 text-left hover:text-cyan focus-visible:outline-2 focus-visible:outline-cyan disabled:opacity-40";
const field = "w-full min-w-0 border border-edge bg-bg p-2 text-fg focus:outline-cyan";

type Action =
    | "folder"
    | "text"
    | "upload"
    | "rename"
    | "move"
    | "archive"
    | "restore"
    | "remove"
    | "open";
type Selection = { action: Action; entry?: DocumentEntry };

export function ProjectMenu({
    project,
    documents,
    beforeLeave = () => true,
}: {
    project: Project;
    documents: boolean;
    beforeLeave?: () => boolean;
}) {
    const navigate = useNavigate();
    return (
        <SectionMenu
            responsive
            label="Project navigation"
            ariaLabel="Project sections"
            idPrefix={`project-${project.id}`}
            items={[
                { id: "overview", label: "Overview", testId: "project-overview" },
                { id: "documents", label: "Documents", testId: "project-documents" },
            ]}
            selected={documents ? "documents" : "overview"}
            onSelect={(section) => {
                if (!beforeLeave()) return;
                if (section === "documents")
                    void navigate({
                        to: "/projects/$id/documents",
                        params: { id: String(project.id) },
                        search: {},
                    });
                else
                    void navigate({
                        to: "/$section/$id",
                        params: { section: "projects", id: String(project.id) },
                    });
            }}
        />
    );
}

export function readDocumentsSearch(search: Record<string, unknown>): {
    folder?: number;
    q?: string;
    state?: "active" | "archived" | "all";
} {
    const folder = Number(search.folder);
    return {
        folder: Number.isSafeInteger(folder) && folder > 0 ? folder : undefined,
        q: typeof search.q === "string" ? search.q : undefined,
        state: ["active", "archived", "all"].includes(String(search.state))
            ? (search.state as "active" | "archived" | "all")
            : undefined,
    };
}

/** Native scrolling requests the next cursor without changing the folder or filters. */
function More({
    next,
    hasNext,
    pending,
    failed = false,
}: {
    next: () => void;
    hasNext: boolean;
    pending: boolean;
    failed?: boolean;
}) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (!hasNext || pending || failed || !ref.current) return;
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) next();
        });
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, [hasNext, pending, failed, next]);
    return (
        <div ref={ref} className="py-2 text-dim" aria-live="polite">
            {pending ? (
                "Loading…"
            ) : hasNext && failed ? (
                <button className={control} onClick={next}>
                    Retry loading more
                </button>
            ) : null}
        </div>
    );
}

function Sheet({
    title,
    close,
    children,
}: {
    title: string;
    close: () => void;
    children: ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const previous = document.activeElement as HTMLElement | null;
        ref.current?.focus();
        return () => previous?.focus();
    }, []);
    return (
        <div
            className="fixed inset-0 z-30 flex items-end justify-center bg-bg/80 md:items-center"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) close();
            }}
        >
            <div
                ref={ref}
                tabIndex={-1}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                className="flex max-h-[90dvh] w-full min-w-0 flex-col border border-edge bg-bg p-3 pb-[max(12px,var(--safe-area-inset-bottom,0px))] md:max-w-[80ch]"
                onKeyDown={(event) => {
                    if (event.key === "Escape") {
                        event.stopPropagation();
                        close();
                    }
                    if (event.key === "Tab") {
                        const nodes = Array.from(
                            ref.current?.querySelectorAll<HTMLElement>(
                                'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]',
                            ) ?? [],
                        );
                        const first = nodes[0];
                        const last = nodes[nodes.length - 1];
                        if (
                            event.shiftKey &&
                            (document.activeElement === first ||
                                document.activeElement === ref.current)
                        ) {
                            event.preventDefault();
                            last?.focus();
                        } else if (
                            !event.shiftKey &&
                            (document.activeElement === last ||
                                document.activeElement === ref.current)
                        ) {
                            event.preventDefault();
                            first?.focus();
                        }
                    }
                }}
            >
                <header className="mb-2 flex items-center justify-between gap-2">
                    <h2 className="min-w-0 break-words font-bold">{title}</h2>
                    <button className={control} aria-label="Close dialog" onClick={close}>
                        Close
                    </button>
                </header>
                <div className="min-h-0 overflow-y-auto">{children}</div>
            </div>
        </div>
    );
}

export function ProjectDocumentsPage() {
    const { id } = useParams({ strict: false });
    const project = useFleet().projects.find((row) => row.id === Number(id));
    if (!project) return <p>Project not found.</p>;
    return (
        <RecordLayout kind="projects" row={project}>
            <div className="flex min-h-0 min-w-0 flex-col gap-2 md:flex-row">
                <ProjectMenu project={project} documents />
                <DocumentsWorkspace key={project.id} project={project.id} />
            </div>
        </RecordLayout>
    );
}

export function DocumentsWorkspace({ project }: { project: number }) {
    const search = useSearch({ from: "/projects/$id/documents" });
    const navigate = useNavigate();
    const cache = useQueryClient();
    const parent = search.folder ?? null;
    const state = search.state ?? "active";
    const q = search.q?.trim() ?? "";
    const [selection, setSelection] = useState<Selection | null>(null);
    const [filters, setFilters] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const update = (next: typeof search) =>
        void navigate({
            to: "/projects/$id/documents",
            params: { id: String(project) },
            search: next,
        });
    const base = documentPath(project);
    const folder = useQuery({
        queryKey: ["documents", project, "trail", parent],
        queryFn: async () => {
            const trail: DocumentEntry[] = [];
            let id = parent;
            while (id !== null && trail.length < 32) {
                const entry = await api<DocumentEntry>("GET", documentPath(project, id));
                trail.unshift(entry);
                id = entry.parent_id;
            }
            return trail;
        },
    });
    const rows = useInfiniteQuery({
        queryKey: ["documents", project, "list", parent, state, q],
        initialPageParam: null as string | null,
        queryFn: ({ pageParam }) => {
            const params = new URLSearchParams({ state, limit: "50" });
            if (q) params.set("q", q);
            else if (parent !== null) params.set("parent_id", String(parent));
            if (pageParam) params.set("cursor", pageParam);
            return apiEnvelope<DocumentEntry[]>("GET", `${base}${q ? "/search" : ""}?${params}`);
        },
        getNextPageParam: (page) => page.meta?.next_cursor ?? undefined,
    });
    const refreshed = () => cache.invalidateQueries({ queryKey: ["documents", project] });
    const current = folder.data?.at(-1);
    const writable = !current?.is_archived && !folder.isError;
    async function download(entry: DocumentEntry) {
        setBusy(true);
        setError(null);
        try {
            await downloadDocument(project, entry);
        } catch (failure) {
            setError(documentError(failure));
        } finally {
            setBusy(false);
        }
    }
    return (
        <section
            id={`project-${project}-documents-panel`}
            role="tabpanel"
            aria-labelledby={`project-${project}-documents-tab`}
            className="min-h-0 min-w-0 flex-1 overflow-y-auto"
        >
            <div className="mb-2 flex flex-wrap items-center gap-2">
                <h1 className="font-bold">Documents</h1>
                <button
                    className={control}
                    data-testid="documents-filters"
                    onClick={() => setFilters(true)}
                >
                    Search / filters{q || state !== "active" ? " •" : ""}
                </button>
                <details
                    className="relative"
                    onClick={(event) => {
                        if ((event.target as HTMLElement).closest("button"))
                            event.currentTarget.open = false;
                    }}
                >
                    <summary className={control} data-testid="documents-create">
                        New
                    </summary>
                    <div className="absolute right-0 z-10 flex w-[20ch] flex-col border border-edge bg-bg p-1">
                        {(["folder", "text", "upload"] as const).map((action) => (
                            <button
                                key={action}
                                className={control}
                                disabled={!writable}
                                onClick={() => setSelection({ action })}
                            >
                                {action === "folder"
                                    ? "Create folder"
                                    : action === "text"
                                      ? "New text file"
                                      : "Upload file"}
                            </button>
                        ))}
                    </div>
                </details>
                <button
                    className={control}
                    aria-label="Refresh Documents"
                    onClick={() => void refreshed()}
                >
                    ↻
                </button>
            </div>
            <nav
                aria-label="Document folders"
                className="mb-2 flex flex-wrap items-center gap-x-2 break-words"
                data-testid="documents-breadcrumbs"
            >
                <button
                    className={control}
                    onClick={() => update({ ...search, folder: undefined, q: undefined })}
                >
                    Root
                </button>
                {folder.data?.map((entry) => (
                    <span key={entry.id} className="min-w-0 max-w-full">
                        {" "}
                        /{" "}
                        <button
                            className={`${control} max-w-full break-words`}
                            onClick={() => update({ ...search, folder: entry.id, q: undefined })}
                        >
                            {entry.name}
                        </button>
                    </span>
                ))}
            </nav>
            {q && <p className="mb-2 break-words text-dim">Search across this Project: {q}</p>}
            {current?.is_archived && (
                <p className="text-warn">Archived folder. Restore its ancestors before editing.</p>
            )}
            {(error || rows.error || folder.error) && (
                <p role="alert" className="my-2 break-words text-warn">
                    {error ?? documentError(rows.error ?? folder.error)}
                </p>
            )}
            {rows.isPending && <p>Loading Documents…</p>}
            <ul className="divide-y divide-edge" data-testid="documents-list">
                {rows.data?.pages
                    .flatMap((page) => page.data)
                    .map((entry) => (
                        <li key={entry.id} className="flex min-w-0 items-start gap-2 py-2">
                            <div className="min-w-0 flex-1">
                                <button
                                    className="cursor-pointer text-left break-words text-cyan hover:underline"
                                    onClick={() =>
                                        entry.kind === "folder"
                                            ? update({ ...search, folder: entry.id, q: undefined })
                                            : setSelection({ action: "open", entry })
                                    }
                                >
                                    {entry.kind === "folder" ? "▸ " : ""}
                                    {entry.name}
                                </button>
                                <p className="break-words text-dim">
                                    {entry.kind}
                                    {entry.current_version
                                        ? ` · v${entry.current_version.number} · ${entry.current_version.size_bytes} bytes`
                                        : ""}
                                    {entry.is_archived ? " · Archived" : ""}
                                </p>
                                {q && <p className="break-words text-dim">{entry.path}</p>}
                            </div>
                            <details
                                className="relative shrink-0"
                                onClick={(event) => {
                                    if ((event.target as HTMLElement).closest("button"))
                                        event.currentTarget.open = false;
                                }}
                            >
                                <summary
                                    className={control}
                                    aria-label={`Actions for ${entry.name}`}
                                >
                                    •••
                                </summary>
                                <div className="absolute right-0 z-10 flex w-[20ch] flex-col border border-edge bg-bg p-1">
                                    {entry.kind === "file" && (
                                        <>
                                            <button
                                                className={control}
                                                onClick={() =>
                                                    setSelection({ action: "open", entry })
                                                }
                                            >
                                                Edit / history
                                            </button>
                                            <button
                                                className={control}
                                                disabled={busy}
                                                onClick={() => void download(entry)}
                                            >
                                                Download
                                            </button>
                                        </>
                                    )}
                                    {!entry.is_archived &&
                                        (["rename", "move", "archive"] as const).map((action) => (
                                            <button
                                                key={action}
                                                className={control}
                                                onClick={() => setSelection({ action, entry })}
                                            >
                                                {action.slice(0, 1).toUpperCase() + action.slice(1)}
                                            </button>
                                        ))}
                                    {entry.is_archived && (
                                        <button
                                            className={control}
                                            onClick={() =>
                                                setSelection({ action: "restore", entry })
                                            }
                                        >
                                            Restore
                                        </button>
                                    )}
                                    <button
                                        className={control}
                                        onClick={() => setSelection({ action: "remove", entry })}
                                    >
                                        Remove permanently
                                    </button>
                                </div>
                            </details>
                        </li>
                    ))}
            </ul>
            {!rows.isPending && rows.data?.pages.every((page) => page.data.length === 0) && (
                <p>No Documents match. Create a folder, write a note, or upload a file.</p>
            )}
            <More
                hasNext={rows.hasNextPage}
                pending={rows.isFetching}
                failed={rows.isFetchNextPageError}
                next={() => void rows.fetchNextPage()}
            />
            {filters && (
                <FilterSheet
                    search={search}
                    close={() => setFilters(false)}
                    apply={(next) => {
                        update(next);
                        setFilters(false);
                    }}
                />
            )}
            {selection &&
                (selection.action === "open" && selection.entry ? (
                    <FileSheet
                        project={project}
                        initial={selection.entry}
                        close={() => setSelection(null)}
                        refresh={refreshed}
                    />
                ) : (
                    <MutationSheet
                        project={project}
                        parent={parent}
                        selection={selection}
                        close={() => setSelection(null)}
                        refresh={refreshed}
                    />
                ))}
        </section>
    );
}

function FilterSheet({
    search,
    close,
    apply,
}: {
    search: ReturnType<typeof readDocumentsSearch>;
    close: () => void;
    apply: (next: ReturnType<typeof readDocumentsSearch>) => void;
}) {
    const [q, setQ] = useState(search.q ?? "");
    const [state, setState] = useState(search.state ?? "active");
    return (
        <Sheet title="Search and filters" close={close}>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({ ...search, q: q.trim() || undefined, state });
                }}
            >
                <label className="block">
                    Name or path
                    <input
                        className={field}
                        maxLength={200}
                        value={q}
                        onChange={(event) => setQ(event.target.value)}
                    />
                </label>
                <label className="block">
                    Archive state
                    <select
                        className={field}
                        value={state}
                        onChange={(event) => setState(event.target.value as typeof state)}
                    >
                        <option value="active">Active</option>
                        <option value="archived">Archived</option>
                        <option value="all">All</option>
                    </select>
                </label>
                <button className={control}>Apply filters</button>
            </form>
        </Sheet>
    );
}

function Destination({
    project,
    value,
    set,
}: {
    project: number;
    value: number | null;
    set: (id: number | null) => void;
}) {
    const folder = useQuery({
        queryKey: ["documents", project, "destination", value],
        queryFn: () =>
            value === null
                ? Promise.resolve(null)
                : api<DocumentEntry>("GET", documentPath(project, value)),
    });
    const children = useInfiniteQuery({
        queryKey: ["documents", project, "destinations", value],
        initialPageParam: null as string | null,
        queryFn: ({ pageParam }) => {
            const params = new URLSearchParams({ kind: "folder", state: "active", limit: "50" });
            if (value !== null) params.set("parent_id", String(value));
            if (pageParam) params.set("cursor", pageParam);
            return apiEnvelope<DocumentEntry[]>("GET", `${documentPath(project)}?${params}`);
        },
        getNextPageParam: (page) => page.meta?.next_cursor ?? undefined,
    });
    return (
        <div className="space-y-2 border border-edge p-2">
            <p className="break-words">Destination: {folder.data?.path ?? "Root"}</p>
            <button type="button" className={control} onClick={() => set(null)}>
                Root
            </button>
            {value !== null && (
                <button
                    type="button"
                    className={control}
                    onClick={() => set(folder.data?.parent_id ?? null)}
                >
                    Up
                </button>
            )}
            <ul>
                {children.data?.pages
                    .flatMap((page) => page.data)
                    .map((entry) => (
                        <li key={entry.id}>
                            <button
                                type="button"
                                className={`${control} max-w-full break-words`}
                                onClick={() => set(entry.id)}
                            >
                                ▸ {entry.name}
                            </button>
                        </li>
                    ))}
            </ul>
            {(children.error || folder.error) && (
                <p role="alert">{documentError(children.error ?? folder.error)}</p>
            )}
            <More
                hasNext={children.hasNextPage}
                pending={children.isFetching}
                failed={children.isFetchNextPageError}
                next={() => void children.fetchNextPage()}
            />
        </div>
    );
}

function MutationSheet({
    project,
    parent,
    selection,
    close,
    refresh,
}: {
    project: number;
    parent: number | null;
    selection: Selection;
    close: () => void;
    refresh: () => Promise<unknown>;
}) {
    const { action, entry } = selection;
    const [name, setName] = useState(entry?.name ?? "");
    const [text, setText] = useState("");
    const [type, setType] = useState("text/markdown");
    const [file, setFile] = useState<File | null>(null);
    const [destination, setDestination] = useState<number | null>(entry?.parent_id ?? parent);
    const [recursive, setRecursive] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const title = entry
        ? `${action === "remove" ? "Remove permanently" : action.slice(0, 1).toUpperCase() + action.slice(1)}: ${entry.name}`
        : action === "folder"
          ? "Create folder"
          : action === "text"
            ? "New text file"
            : "Upload file";
    const creating = ["folder", "text", "upload"].includes(action);
    useBlocker({
        shouldBlockFn: () =>
            creating && Boolean(text || file) && !window.confirm("Discard the unsaved new file?"),
        enableBeforeUnload: creating && Boolean(text || file),
    });
    const dismiss = () => {
        if (!busy && (!(text || file) || window.confirm("Discard the unsaved new file?"))) close();
    };
    async function submit() {
        setBusy(true);
        setError(null);
        try {
            if (creating) {
                const content =
                    action === "upload"
                        ? await uploadBody(file!)
                        : action === "text"
                          ? { content_text: text, media_type: type }
                          : {};
                await api("POST", documentPath(project), {
                    kind: action === "folder" ? "folder" : "file",
                    parent_id: parent,
                    name,
                    ...content,
                });
            } else if (entry) {
                const path = documentPath(project, entry.id);
                const revision = { expected_revision: entry.revision };
                if (action === "rename" || action === "move")
                    await api("PATCH", path, {
                        ...revision,
                        ...(action === "rename" ? { name } : { parent_id: destination }),
                    });
                else if (action === "remove") await api("DELETE", path, { ...revision, recursive });
                else await api("POST", `${path}/${action}`, revision);
            }
            await refresh();
            close();
        } catch (failure) {
            setError(documentError(failure));
        } finally {
            setBusy(false);
        }
    }
    return (
        <Sheet title={title} close={dismiss}>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                <fieldset disabled={busy} className="min-w-0 space-y-3">
                    {(creating || action === "rename") && (
                        <label className="block">
                            Name
                            <input
                                className={field}
                                required
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                            />
                        </label>
                    )}
                    {action === "text" && (
                        <>
                            <label className="block">
                                Type
                                <select
                                    className={field}
                                    value={type}
                                    onChange={(event) => setType(event.target.value)}
                                >
                                    <option value="text/markdown">Markdown</option>
                                    <option value="text/plain">Plain text</option>
                                    <option value="application/json">JSON</option>
                                </select>
                            </label>
                            <label className="block">
                                Content
                                <textarea
                                    className={`${field} min-h-[25vh]`}
                                    value={text}
                                    onChange={(event) => setText(event.target.value)}
                                />
                            </label>
                        </>
                    )}
                    {action === "upload" && (
                        <label className="block">
                            File (up to 10 MiB)
                            <input
                                type="file"
                                className={field}
                                required
                                onChange={(event) => {
                                    const next = event.target.files?.[0] ?? null;
                                    setFile(next);
                                    if (next && !name) setName(next.name);
                                }}
                            />
                        </label>
                    )}
                    {action === "move" && (
                        <Destination project={project} value={destination} set={setDestination} />
                    )}
                    {action === "remove" && (
                        <>
                            <p className="break-words text-warn">
                                Permanently remove {entry?.kind} “{entry?.path}” and all its
                                versions. This cannot be undone.
                            </p>
                            {entry?.kind === "folder" && (
                                <>
                                    <p>
                                        Inspect the folder's children before confirming. Its
                                        revision is not a snapshot of descendant content.
                                    </p>
                                    <label className="block">
                                        <input
                                            type="checkbox"
                                            checked={recursive}
                                            onChange={(event) => setRecursive(event.target.checked)}
                                        />{" "}
                                        Recursively remove every descendant, including archived
                                        files and their versions
                                    </label>
                                </>
                            )}
                        </>
                    )}
                    {entry && (
                        <p className="text-dim">
                            Revision {entry.revision}. No automatic conflict retry.
                        </p>
                    )}
                    {error && (
                        <p role="alert" className="break-words text-warn">
                            {error}
                        </p>
                    )}
                    <button className={control} disabled={busy || (action === "upload" && !file)}>
                        {busy
                            ? "Submitting…"
                            : action === "remove"
                              ? "Confirm permanent removal"
                              : "Confirm"}
                    </button>
                </fieldset>
            </form>
        </Sheet>
    );
}

function FileSheet({
    project,
    initial,
    close,
    refresh,
}: {
    project: number;
    initial: DocumentEntry;
    close: () => void;
    refresh: () => Promise<unknown>;
}) {
    const [entry, setEntry] = useState(initial);
    const [loaded, setLoaded] = useState<DocumentContent | null>(null);
    const [draft, setDraft] = useState("");
    const [preview, setPreview] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const dirty = loaded !== null && draft !== loaded.content_text;
    useBlocker({
        shouldBlockFn: () => dirty && !window.confirm("Discard the unsaved draft?"),
        enableBeforeUnload: dirty,
    });
    const path = documentPath(project, initial.id);
    const cache = useQueryClient();
    const versions = useInfiniteQuery({
        queryKey: ["documents", project, "versions", initial.id],
        initialPageParam: null as string | null,
        queryFn: ({ pageParam }) =>
            apiEnvelope<DocumentVersion[]>(
                "GET",
                `${path}/versions?limit=50${pageParam ? `&cursor=${encodeURIComponent(pageParam)}` : ""}`,
            ),
        getNextPageParam: (page) => page.meta?.next_cursor ?? undefined,
    });
    const dismiss = () => {
        if (!busy && (!dirty || window.confirm("Discard the unsaved draft?"))) close();
    };

    async function load() {
        setBusy(true);
        setLoadError(null);
        try {
            const current = await api<DocumentEntry>("GET", path);
            setEntry(current);
            if (inlineEditable(current)) {
                const content = await api<DocumentContent>("GET", `${path}/content`);
                setLoaded(content);
                setDraft(content.content_text);
            } else {
                setLoaded(null);
                setDraft("");
            }
            setError(null);
        } catch (failure) {
            setLoadError(documentError(failure));
        } finally {
            setBusy(false);
        }
    }
    useEffect(() => {
        void load();
    }, []);
    async function run(work: () => Promise<unknown>, contentMutation = false) {
        setBusy(true);
        setError(null);
        try {
            const result = await work();
            if (contentMutation) {
                setEntry(result as DocumentEntry);
                await refresh();
                await cache.invalidateQueries({
                    queryKey: ["documents", project, "versions", entry.id],
                });
                await load();
            }
        } catch (failure) {
            setError(documentError(failure));
        } finally {
            setBusy(false);
        }
    }
    const current = entry.current_version;
    const revision = loaded?.revision ?? entry.revision;
    return (
        <Sheet title={entry.name} close={dismiss}>
            <div className="space-y-3">
                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 text-dim">
                    <dt>Type</dt>
                    <dd className="break-words">{current?.media_type}</dd>
                    <dt>Size</dt>
                    <dd>{current?.size_bytes} bytes</dd>
                    <dt>Version</dt>
                    <dd>
                        {current?.number} · revision {revision}
                    </dd>
                    <dt>Author</dt>
                    <dd>
                        {current?.created_by_node_id === null
                            ? "Deleted / unknown Node"
                            : `Node ${current?.created_by_node_id}`}
                    </dd>
                    <dt>Created</dt>
                    <dd className="break-words">{entry.created_at}</dd>
                    <dt>Updated</dt>
                    <dd className="break-words">{entry.updated_at}</dd>
                    <dt>SHA-256</dt>
                    <dd className="break-all">{current?.sha256}</dd>
                </dl>
                {entry.is_archived && <p className="text-warn">Archived — read only.</p>}
                {loadError && (
                    <p role="alert" className="break-words text-warn">
                        {loadError}
                    </p>
                )}
                {loaded ? (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <button className={control} onClick={() => setPreview(!preview)}>
                                {preview ? "Edit text" : "Safe preview"}
                            </button>
                            <button
                                className={control}
                                data-testid="documents-save"
                                disabled={!dirty || busy || entry.is_archived}
                                onClick={() =>
                                    void run(
                                        () =>
                                            api<DocumentEntry>("PUT", `${path}/content`, {
                                                expected_revision: loaded.revision,
                                                content_text: draft,
                                                media_type: loaded.version.media_type,
                                            }),
                                        true,
                                    )
                                }
                            >
                                Save
                            </button>
                            <span aria-live="polite">{dirty ? "Unsaved draft" : "Saved"}</span>
                        </div>
                        {preview ? (
                            <pre className="max-h-[35vh] overflow-auto border border-edge p-2 whitespace-pre-wrap break-words">
                                {draft}
                            </pre>
                        ) : (
                            <label className="block">
                                Document content
                                <textarea
                                    className={`${field} min-h-[25vh] font-mono`}
                                    value={draft}
                                    readOnly={busy || entry.is_archived}
                                    onChange={(event) => setDraft(event.target.value)}
                                />
                            </label>
                        )}
                        <p className="text-dim">
                            Preview is escaped text, including Markdown and JSON. No markup or
                            scripts run. Save is explicit.
                        </p>
                        <button
                            className={control}
                            disabled={busy}
                            onClick={() =>
                                void run(async () => {
                                    await navigator.clipboard.writeText(draft);
                                    setCopied(true);
                                })
                            }
                        >
                            Copy draft
                        </button>
                        {copied && <span role="status"> Draft copied</span>}
                    </>
                ) : (
                    <p>
                        Attachment, oversized text, or unavailable body: use download and upload
                        replacement.
                    </p>
                )}
                <div className="flex flex-wrap gap-2">
                    <button
                        className={control}
                        disabled={busy}
                        onClick={() => {
                            if (
                                !dirty ||
                                window.confirm(
                                    "Reload and discard the unsaved draft? Copy it first to keep it.",
                                )
                            )
                                void load();
                        }}
                    >
                        Reload metadata and content
                    </button>
                    <button
                        className={control}
                        disabled={busy}
                        onClick={() => void run(() => downloadDocument(project, entry))}
                    >
                        Download current
                    </button>
                </div>
                {!entry.is_archived && (
                    <label className="block">
                        Upload replacement (new version, up to 10 MiB)
                        <input
                            type="file"
                            className={field}
                            disabled={busy}
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                event.target.value = "";
                                if (
                                    file &&
                                    (!dirty ||
                                        window.confirm(
                                            "Replace content and discard the unsaved draft?",
                                        ))
                                )
                                    void run(
                                        async () =>
                                            api<DocumentEntry>("PUT", `${path}/content`, {
                                                expected_revision: revision,
                                                ...(await uploadBody(file)),
                                            }),
                                        true,
                                    );
                            }}
                        />
                    </label>
                )}
                {error && (
                    <p role="alert" data-testid="documents-error" className="break-words text-warn">
                        {error}
                    </p>
                )}
                <h3 className="font-bold">Version history</h3>
                {versions.error && <p role="alert">{documentError(versions.error)}</p>}
                <ul className="divide-y divide-edge">
                    {versions.data?.pages
                        .flatMap((page) => page.data)
                        .map((version) => (
                            <li key={version.id} className="space-y-1 py-2">
                                <p>
                                    v{version.number} · {version.media_type} · {version.size_bytes}{" "}
                                    bytes
                                </p>
                                <p className="break-words text-dim">
                                    {version.created_at} ·{" "}
                                    {version.created_by_node_id === null
                                        ? "Deleted / unknown Node"
                                        : `Node ${version.created_by_node_id}`}
                                </p>
                                <p className="break-all text-dim">SHA-256: {version.sha256}</p>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        className={control}
                                        disabled={busy}
                                        onClick={() =>
                                            void run(() =>
                                                downloadDocument(project, entry, version.id),
                                            )
                                        }
                                    >
                                        Download v{version.number}
                                    </button>
                                    <button
                                        className={control}
                                        disabled={busy || entry.is_archived}
                                        onClick={() => {
                                            if (
                                                (!dirty ||
                                                    window.confirm(
                                                        "Discard the draft and restore this version?",
                                                    )) &&
                                                window.confirm(
                                                    `Restore v${version.number} as a new version?`,
                                                )
                                            )
                                                void run(
                                                    () =>
                                                        api<DocumentEntry>(
                                                            "POST",
                                                            `${path}/restore-version`,
                                                            {
                                                                expected_revision: revision,
                                                                version_id: version.id,
                                                            },
                                                        ),
                                                    true,
                                                );
                                        }}
                                    >
                                        Restore v{version.number}
                                    </button>
                                </div>
                            </li>
                        ))}
                </ul>
                <More
                    hasNext={versions.hasNextPage}
                    pending={versions.isFetching}
                    failed={versions.isFetchNextPageError}
                    next={() => void versions.fetchNextPage()}
                />
            </div>
        </Sheet>
    );
}
