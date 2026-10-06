import { constants } from "node:fs";
import { access, readFile } from "node:fs/promises";
import { homedir } from "node:os";
import { delimiter, isAbsolute, join, resolve } from "node:path";
import { Type } from "@earendil-works/pi-ai";
import {
    createLocalBashOperations,
    createReadToolDefinition,
    defineTool,
    detectSupportedImageMimeTypeFromFile,
    getAgentDir,
    truncateHead,
} from "@earendil-works/pi-coding-agent";
import type { ExtensionContext } from "@earendil-works/pi-coding-agent";
import {
    BASH_PREVIEW_LINES,
    OUTPUT_LIMIT_BYTES,
    READ_PREVIEW_LINES,
    offloadToolOutput,
} from "./tool-output.ts";

import { workspaceTmpdir } from "./workspace-tmpdir.ts";

const READ_DESCRIPTION =
    "Read the contents of a file. Supports text files and images (jpg, png, gif, webp, bmp). Images are sent as attachments. Text over 8,192 bytes is stored under .git/orbit/tool-output/ and the result contains the path, the byte and line counts, and a short preview of the first lines. Use offset and limit to read a smaller range.";

const BASH_DESCRIPTION =
    "Execute a bash command in the current working directory. Returns stdout and stderr. Output over 8,192 bytes is stored under .git/orbit/tool-output/ and the result contains the path, the byte and line counts, a short preview of the last lines, and the exit status. Optionally provide a timeout in seconds.";

const readParameters = Type.Object({
    path: Type.String({ description: "Path to the file to read (relative or absolute)" }),
    offset: Type.Optional(
        Type.Number({ description: "Line number to start reading from (1-indexed)" }),
    ),
    limit: Type.Optional(Type.Number({ description: "Maximum number of lines to read" })),
});

const bashParameters = Type.Object({
    command: Type.String({ description: "Shell command to execute" }),
    timeout: Type.Optional(
        Type.Number({ description: "Timeout in seconds (optional, no default timeout)" }),
    ),
});

export interface WorkspaceToolOptions {
    /** Session workspace. Large output is stored under its `.git` directory. */
    cwd: string;
    /** Orbit session id, used in the stored file name. */
    sessionId: string;
}

/**
 * `read` and `bash` for an Orbit session.
 *
 * Output at or under 8,192 bytes, and an image read, stays as Pi produced it. Larger text is
 * stored under `.git/orbit/tool-output/` before the result is appended to the session.
 * Pi's 2,000-line cut does not apply: a shorter text is returned whole.
 */
export function createWorkspaceTools(options: WorkspaceToolOptions) {
    return [createOffloadingReadTool(options), createOffloadingBashTool(options)];
}

function createOffloadingReadTool(options: WorkspaceToolOptions) {
    return defineTool({
        name: "read",
        label: "read",
        description: READ_DESCRIPTION,
        promptSnippet: "Read file contents",
        promptGuidelines: ["Use read to examine files instead of cat or sed."],
        parameters: readParameters,
        constrainedSampling: { type: "json_schema", strict: "prefer" },
        async execute(toolCallId, params, signal, onUpdate, ctx) {
            const cwd = ctx?.cwd || options.cwd;
            const delegate = () =>
                createReadToolDefinition(cwd).execute(
                    toolCallId,
                    params,
                    signal,
                    onUpdate,
                    ctx as ExtensionContext,
                );
            if (signal?.aborted) {
                throw new Error("Operation aborted");
            }
            const absolutePath = resolveReadPath(params.path, cwd);
            let mime: string | null | undefined;
            try {
                await access(absolutePath, constants.R_OK);
                mime = await detectSupportedImageMimeTypeFromFile(absolutePath);
            } catch {
                // Keep Pi's error, including a path Pi can resolve and this check cannot.
                return delegate();
            }
            if (mime) {
                return delegate();
            }
            if (signal?.aborted) {
                throw new Error("Operation aborted");
            }
            const decoded = (await readFile(absolutePath)).toString("utf-8");
            const selection = selectReadText(decoded, params.offset, params.limit);
            const bytes = Buffer.byteLength(selection.selected, "utf-8");
            // Pi already returns this text whole. Delegating keeps that result unchanged.
            if (bytes <= OUTPUT_LIMIT_BYTES && !truncateHead(selection.selected).truncated) {
                return delegate();
            }
            const decision = offloadToolOutput({
                cwd,
                toolName: "read",
                sessionId: options.sessionId,
                toolCallId,
                text: selection.selected,
                preview: "head",
                previewLines: READ_PREVIEW_LINES,
            });
            if (decision.kind === "error") {
                throw new Error(decision.text);
            }
            if (decision.kind === "notice") {
                return textResult(decision.text);
            }
            return textResult(withContinuation(selection.selected, selection.continuation));
        },
    });
}

function createOffloadingBashTool(options: WorkspaceToolOptions) {
    const operations = createLocalBashOperations();
    return defineTool({
        name: "bash",
        label: "bash",
        description: BASH_DESCRIPTION,
        promptSnippet: "Execute bash commands (ls, grep, find, etc.)",
        promptGuidelines: [
            "You can inspect PI_* environment variables for current model and session details.",
        ],
        parameters: bashParameters,
        constrainedSampling: { type: "json_schema", strict: "prefer" },
        async execute(toolCallId, { command, timeout }, signal, _onUpdate, ctx) {
            const cwd = ctx?.cwd || options.cwd;
            const chunks: Buffer[] = [];
            let status: BashStatus;
            try {
                const result = await operations.exec(command, cwd, {
                    onData: (data) => {
                        chunks.push(Buffer.from(data));
                    },
                    signal,
                    timeout,
                    env: { ...sessionEnv(ctx), TMPDIR: workspaceTmpdir(options.cwd) },
                });
                status = { kind: "exit", code: result.exitCode };
            } catch (error) {
                if (error instanceof Error && error.message === "aborted") {
                    status = { kind: "aborted" };
                } else if (error instanceof Error && error.message.startsWith("timeout:")) {
                    status = { kind: "timeout", seconds: error.message.slice("timeout:".length) };
                } else {
                    throw error;
                }
            }
            const output = Buffer.concat(chunks).toString("utf-8");
            const decision = offloadToolOutput({
                cwd,
                toolName: "bash",
                sessionId: options.sessionId,
                toolCallId,
                text: output,
                preview: "tail",
                previewLines: BASH_PREVIEW_LINES,
                statusLine: noticeStatus(status),
            });
            if (decision.kind === "error") {
                throw new Error(decision.text);
            }
            const text = decision.kind === "notice" ? decision.text : piBashText(output, status);
            if (failed(status)) {
                throw new Error(text);
            }
            return textResult(text);
        },
    });
}

interface ReadSelection {
    /** The offset/limit slice Pi would measure, before its line or byte cap. */
    selected: string;
    /** Set when `limit` stops before the end of the file. */
    continuation?: { remaining: number; nextOffset: number };
}

/**
 * The same slice as Pi's read tool. `offset` is 1-based. A trailing empty split element
 * is kept here so the continuation line matches Pi; the offload line count does not use it.
 */
function selectReadText(text: string, offset?: number, limit?: number): ReadSelection {
    const allLines = text.split("\n");
    const startLine = offset ? Math.max(0, offset - 1) : 0;
    if (startLine >= allLines.length) {
        throw new Error(`Offset ${offset} is beyond end of file (${allLines.length} lines total)`);
    }
    if (limit === undefined) {
        return { selected: allLines.slice(startLine).join("\n") };
    }
    const endLine = Math.min(startLine + limit, allLines.length);
    const selected = allLines.slice(startLine, endLine).join("\n");
    if (startLine + (endLine - startLine) < allLines.length) {
        return {
            selected,
            continuation: {
                remaining: allLines.length - endLine,
                nextOffset: endLine + 1,
            },
        };
    }
    return { selected };
}

function withContinuation(selected: string, continuation: ReadSelection["continuation"]): string {
    if (continuation === undefined) {
        return selected;
    }
    return `${selected}\n\n[${continuation.remaining} more lines in file. Use offset=${continuation.nextOffset} to continue.]`;
}

type BashStatus =
    | { kind: "exit"; code: number | null }
    | { kind: "aborted" }
    | { kind: "timeout"; seconds: string };

function noticeStatus(status: BashStatus): string {
    if (status.kind === "aborted") {
        return "Command aborted";
    }
    if (status.kind === "timeout") {
        return `Command timed out after ${status.seconds} seconds`;
    }
    if (status.code === null) {
        return "Command terminated without an exit code";
    }
    return `Exit code: ${status.code}`;
}

function failed(status: BashStatus): boolean {
    return status.kind !== "exit" || status.code !== 0;
}

/** Pi's bash text for a result that stays in the session: no exit line on success. */
function piBashText(output: string, status: BashStatus): string {
    if (status.kind === "exit" && status.code === 0) {
        return output || "(no output)";
    }
    if (status.kind === "exit") {
        const line =
            status.code === null
                ? "Command terminated without an exit code"
                : `Command exited with code ${status.code}`;
        return appendStatus(output || "(no output)", line);
    }
    return appendStatus(output, noticeStatus(status));
}

function appendStatus(text: string, status: string): string {
    return `${text ? `${text}\n\n` : ""}${status}`;
}

function sessionEnv(ctx: ExtensionContext | undefined): NodeJS.ProcessEnv {
    // Pi's getShellEnv is not exported. Copy it so the agent bin stays on PATH, then set PI_*.
    const env = shellEnv();
    delete env.PI_SESSION_ID;
    delete env.PI_SESSION_FILE;
    delete env.PI_PROVIDER;
    delete env.PI_MODEL;
    delete env.PI_REASONING_LEVEL;
    if (ctx === undefined) {
        return env;
    }
    env.PI_SESSION_ID = ctx.sessionManager.getSessionId();
    const sessionFile = ctx.sessionManager.getSessionFile();
    if (sessionFile !== undefined) {
        env.PI_SESSION_FILE = sessionFile;
    }
    if (ctx.model !== undefined) {
        env.PI_PROVIDER = ctx.model.provider;
        env.PI_MODEL = ctx.model.id;
    }
    if (ctx.thinkingLevel !== undefined) {
        env.PI_REASONING_LEVEL = ctx.thinkingLevel;
    }
    return env;
}

/** Pi prepends `~/.pi/agent/bin` to PATH unless it is already there. */
function shellEnv(): NodeJS.ProcessEnv {
    const binDir = join(getAgentDir(), "bin");
    const pathKey = Object.keys(process.env).find((key) => key.toLowerCase() === "path") ?? "PATH";
    const currentPath = process.env[pathKey] ?? "";
    const pathEntries = currentPath.split(delimiter).filter(Boolean);
    const updatedPath = pathEntries.includes(binDir)
        ? currentPath
        : [binDir, currentPath].filter(Boolean).join(delimiter);
    return { ...process.env, [pathKey]: updatedPath };
}

function resolveReadPath(input: string, cwd: string): string {
    let path = input.startsWith("@") ? input.slice(1) : input;
    if (path === "~") {
        path = homedir();
    } else if (path.startsWith("~/")) {
        path = join(homedir(), path.slice(2));
    }
    return isAbsolute(path) ? path : resolve(cwd, path);
}

function textResult(text: string) {
    return { content: [{ type: "text" as const, text }], details: undefined };
}
