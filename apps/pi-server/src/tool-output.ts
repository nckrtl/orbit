import { chmodSync, lstatSync, mkdirSync, realpathSync, rmSync, writeFileSync } from "node:fs";
import { isAbsolute, join, relative, sep } from "node:path";

/** 8 KiB of UTF-8. A measured text of this size or smaller stays in the session. */
export const OUTPUT_LIMIT_BYTES = 8_192;

export const READ_PREVIEW_LINES = 20;
export const BASH_PREVIEW_LINES = 40;

const HINT = "View more with the read tool using offset and limit, or grep on that file.";

export interface OffloadRequest {
    /** Session workspace. The file is written only when `<cwd>/.git` is a real directory. */
    cwd: string;
    /** Tool name used in the file name, `read` or `bash`. */
    toolName: string;
    /** Orbit session id. Sanitized into the file name. */
    sessionId: string;
    /** Tool call id. Sanitized into the file name. */
    toolCallId: string;
    /** Decoded stdout/stderr or the selected read text, with no status line. */
    text: string;
    /** `read` keeps the first lines. `bash` keeps the last lines. */
    preview: "head" | "tail";
    /** How many preview lines to try before dropping any that do not fit. */
    previewLines: number;
    /** Final notice line for bash: exit, abort, or timeout. Omitted for read. */
    statusLine?: string;
}

export type OffloadResult =
    | { kind: "inline" }
    | { kind: "full" }
    | { kind: "notice"; text: string; path: string }
    | { kind: "error"; text: string };

/**
 * Apply ADR 0168 to one measured text.
 *
 * At or under 8,192 bytes, the caller keeps Pi's result. Over that, the full text is written
 * under `.git/orbit/tool-output/` and the caller stores the notice. A workspace whose `.git`
 * entry is not a real directory keeps the full text and writes nothing. A failed write becomes
 * an error that names the size and the reason, without the output. A bash status line is kept.
 */
export function offloadToolOutput(request: OffloadRequest): OffloadResult {
    const bytes = Buffer.byteLength(request.text, "utf-8");
    if (bytes <= OUTPUT_LIMIT_BYTES) {
        return { kind: "inline" };
    }
    const lines = splitLines(request.text).length;
    if (!isRealDirectory(join(request.cwd, ".git"))) {
        return { kind: "full" };
    }
    try {
        const path = writeMeasuredText(request);
        return {
            kind: "notice",
            path,
            text: buildNotice({ ...request, path, bytes, lines }),
        };
    } catch (error) {
        const reason = error instanceof Error ? error.message : String(error);
        const text = `${bytes} bytes, ${lines} lines, not saved: ${reason}`;
        return {
            kind: "error",
            text: request.statusLine === undefined ? text : `${text}\n${request.statusLine}`,
        };
    }
}

/**
 * Lines of a measured text. Split on `\n`. A final newline does not add a line.
 * An empty text has no lines.
 */
export function splitLines(text: string): string[] {
    if (text === "") {
        return [];
    }
    const lines = text.split("\n");
    if (text.endsWith("\n")) {
        lines.pop();
    }
    return lines;
}

interface NoticeInput extends OffloadRequest {
    path: string;
    bytes: number;
    lines: number;
}

/** The model-facing notice. At most 8,192 bytes, unless the path alone cannot fit. */
export function buildNotice(input: NoticeInput): string {
    const lines = splitLines(input.text);
    const wanted = input.previewLines;
    let preview = input.preview === "head" ? lines.slice(0, wanted) : lines.slice(-wanted);
    while (
        Buffer.byteLength(renderNotice(input, preview), "utf-8") > OUTPUT_LIMIT_BYTES &&
        preview.length > 0
    ) {
        preview = input.preview === "head" ? preview.slice(0, -1) : preview.slice(1);
    }
    return renderNotice(input, preview);
}

function renderNotice(input: NoticeInput, preview: string[]): string {
    const header =
        `${input.bytes} bytes, ${input.lines} lines, saved to ${input.path}` +
        (preview.length < input.previewLines ? ` Preview shows ${preview.length} lines.` : "");
    const parts = [header, ...preview, HINT];
    if (input.statusLine !== undefined) {
        parts.push(input.statusLine);
    }
    return parts.join("\n");
}

function writeMeasuredText(request: OffloadRequest): string {
    const git = join(request.cwd, ".git");
    const orbit = ensureChildDirectory(git, "orbit", 0o775);
    const directory = ensureChildDirectory(orbit, "tool-output", 0o770);
    assertInside(git, directory);
    const base = sanitizeName(`${request.toolName}-${request.sessionId}-${request.toolCallId}`);
    for (let suffix = 1; suffix < 10_000; suffix++) {
        const name = suffix === 1 ? `${base}.txt` : `${base}-${suffix}.txt`;
        if (name !== sanitizeName(name) || name.includes(sep) || name.includes("/")) {
            throw new Error(`Refusing tool output name ${name}`);
        }
        const path = join(directory, name);
        try {
            writeFileSync(path, request.text, { encoding: "utf-8", mode: 0o660, flag: "wx" });
        } catch (error) {
            if (isErrno(error, "EEXIST")) {
                continue;
            }
            throw error;
        }
        chmodSync(path, 0o660);
        try {
            assertInside(directory, path);
        } catch (error) {
            rmSync(path, { force: true });
            throw error;
        }
        return path;
    }
    throw new Error("could not choose a new tool output file name");
}

/** Create a real directory with shared ACL-compatible bits; never chmod an existing directory. */
function ensureChildDirectory(parent: string, name: string, mode: number): string {
    const path = join(parent, name);
    if (!isRealDirectory(path)) {
        try {
            if (exists(path)) {
                throw new Error(`${name} is not a directory`);
            }
            mkdirSync(path, { mode });
            chmodSync(path, mode);
        } catch (error) {
            if (!isErrno(error, "EEXIST") || !isRealDirectory(path)) {
                throw error;
            }
        }
    }
    if (!isRealDirectory(path)) {
        throw new Error(`${name} is not a directory`);
    }
    return path;
}

function exists(path: string): boolean {
    try {
        lstatSync(path);
        return true;
    } catch (error) {
        if (isErrno(error, "ENOENT")) {
            return false;
        }
        throw error;
    }
}

/** A symlink is not a directory, so tool output cannot escape through `.git` or `orbit`. */
function isRealDirectory(path: string): boolean {
    try {
        const stat = lstatSync(path);
        return stat.isDirectory() && !stat.isSymbolicLink();
    } catch (error) {
        if (isErrno(error, "ENOENT")) {
            return false;
        }
        throw error;
    }
}

function assertInside(parent: string, child: string): void {
    const fromRoot = relative(realpathSync(parent), realpathSync(child));
    if (fromRoot === ".." || fromRoot.startsWith(`..${sep}`) || isAbsolute(fromRoot)) {
        throw new Error(`${child} is outside ${parent}`);
    }
}

function sanitizeName(value: string): string {
    const sanitized = value.replace(/[^A-Za-z0-9._-]/g, "_");
    return sanitized === "" ? "output" : sanitized;
}

function isErrno(error: unknown, code: string): boolean {
    return typeof error === "object" && error !== null && "code" in error && error.code === code;
}
