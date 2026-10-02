import { execFileSync } from "node:child_process";
import {
    chmodSync,
    copyFileSync,
    mkdtempSync,
    mkdirSync,
    readdirSync,
    readFileSync,
    rmSync,
    statSync,
    symlinkSync,
    writeFileSync,
} from "node:fs";
import { tmpdir, userInfo } from "node:os";
import { delimiter, join, sep } from "node:path";
import { fauxAssistantMessage, fauxToolCall, type JsonObject } from "@earendil-works/pi-ai";
import {
    createBashToolDefinition,
    createReadToolDefinition,
    getAgentDir,
} from "@earendil-works/pi-coding-agent";
import { afterEach, describe, expect, it } from "vite-plus/test";
import {
    OUTPUT_LIMIT_BYTES,
    buildNotice,
    offloadToolOutput,
    splitLines,
} from "../src/tool-output.ts";
import { createWorkspaceTools } from "../src/workspace-tools.ts";
import { type Harness, startHarness, waitFor } from "./support.ts";

const MODEL = "faux/model-a";
const HINT = "View more with the read tool using offset and limit, or grep on that file.";
const PNG = Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
    "base64",
);

let harness: Harness | undefined;
const roots: string[] = [];

afterEach(async () => {
    await harness?.close();
    harness = undefined;
    for (const root of roots.splice(0)) {
        rmSync(root, { recursive: true, force: true });
    }
});

describe("measured text", () => {
    it("counts lines on newlines and ignores a final newline", () => {
        expect(splitLines("")).toEqual([]);
        expect(splitLines("a")).toEqual(["a"]);
        expect(splitLines("a\nb")).toEqual(["a", "b"]);
        expect(splitLines("a\nb\n")).toEqual(["a", "b"]);
        expect(splitLines("\n")).toEqual([""]);
        expect(splitLines("\n\n")).toEqual(["", ""]);
    });

    it("keeps a read preview at the head and a bash preview at the tail, inside 8192 bytes", () => {
        const lines = Array.from({ length: 30 }, (_, index) => `L${index} ${"y".repeat(2_000)}`);
        const text = lines.join("\n");
        const read = buildNotice({
            cwd: "/work",
            toolName: "read",
            sessionId: "thread-1",
            toolCallId: "call",
            text,
            preview: "head",
            previewLines: 20,
            path: "/work/.git/orbit/tool-output/read-thread-1-call.txt",
            bytes: Buffer.byteLength(text),
            lines: lines.length,
        });
        const bash = buildNotice({
            cwd: "/work",
            toolName: "bash",
            sessionId: "thread-1",
            toolCallId: "call",
            text,
            preview: "tail",
            previewLines: 40,
            statusLine: "Exit code: 0",
            path: "/work/.git/orbit/tool-output/bash-thread-1-call.txt",
            bytes: Buffer.byteLength(text),
            lines: lines.length,
        });

        expect(Buffer.byteLength(read)).toBeLessThanOrEqual(OUTPUT_LIMIT_BYTES);
        expect(Buffer.byteLength(bash)).toBeLessThanOrEqual(OUTPUT_LIMIT_BYTES);
        expect(
            read.startsWith(
                `${Buffer.byteLength(text)} bytes, 30 lines, saved to /work/.git/orbit/tool-output/read-thread-1-call.txt`,
            ),
        ).toBe(true);
        expect(read).toContain("Preview shows ");
        expect(read).toContain("L0 ");
        expect(read).not.toContain("L29 ");
        expect(read).toContain(HINT);
        expect(read).not.toContain("Exit code:");
        expect(bash).toContain("L29 ");
        expect(bash).not.toContain("L0 ");
        expect(bash.endsWith("Exit code: 0")).toBe(true);
        expect(bash).toContain(HINT);
    });

    it("drops a preview line that does not fit whole", () => {
        const text = `MARKER${"a".repeat(OUTPUT_LIMIT_BYTES)}`;
        const notice = buildNotice({
            cwd: "/work",
            toolName: "bash",
            sessionId: "s",
            toolCallId: "c",
            text,
            preview: "tail",
            previewLines: 40,
            statusLine: "Command aborted",
            path: "/work/.git/orbit/tool-output/bash-s-c.txt",
            bytes: Buffer.byteLength(text),
            lines: 1,
        });

        expect(notice).toContain("Preview shows 0 lines.");
        expect(notice).not.toContain("MARKER");
        expect(notice).toContain(HINT);
        expect(notice.endsWith("Command aborted")).toBe(true);
        expect(Buffer.byteLength(notice)).toBeLessThanOrEqual(OUTPUT_LIMIT_BYTES);
    });
});

describe("stored file", () => {
    it("writes only under .git/orbit/tool-output and does not replace an existing file", () => {
        const cwd = workspace();
        mkdirSync(join(cwd, ".git"));
        const first = "a".repeat(OUTPUT_LIMIT_BYTES + 1);
        const second = "b".repeat(OUTPUT_LIMIT_BYTES + 2);
        const request = {
            cwd,
            toolName: "bash",
            sessionId: "ses sion",
            toolCallId: "../../tmp/evil:1",
            preview: "tail" as const,
            previewLines: 40,
            statusLine: "Exit code: 0",
        };

        const saved = offloadToolOutput({ ...request, text: first });
        const again = offloadToolOutput({ ...request, text: second });

        expect(saved.kind).toBe("notice");
        expect(again.kind).toBe("notice");
        if (saved.kind !== "notice" || again.kind !== "notice") {
            return;
        }
        const directory = join(cwd, ".git", "orbit", "tool-output");
        expect(saved.path.startsWith(`${directory}${sep}`)).toBe(true);
        expect(again.path.startsWith(`${directory}${sep}`)).toBe(true);
        expect(saved.path).not.toBe(again.path);
        expect(readFileSync(saved.path, "utf-8")).toBe(first);
        expect(readFileSync(again.path, "utf-8")).toBe(second);
        expect(statSync(saved.path).mode & 0o777).toBe(0o660);
        expect(statSync(directory).mode & 0o777).toBe(0o770);
        expect(statSync(join(cwd, ".git", "orbit")).mode & 0o777).toBe(0o775);
        for (const name of readdirSync(directory)) {
            expect(name.includes("/")).toBe(false);
            expect(name.startsWith("bash-ses_sion-")).toBe(true);
            expect(name.endsWith(".txt")).toBe(true);
        }
        expect(saved.text).toContain(saved.path);
        expect(saved.text).not.toContain(first);
    });

    it("keeps existing shared directory modes and creates group-writable output", () => {
        const cwd = workspace();
        const orbit = join(cwd, ".git", "orbit");
        const directory = join(orbit, "tool-output");
        mkdirSync(directory, { recursive: true });
        chmodSync(orbit, 0o775);
        chmodSync(directory, 0o770);
        const umask = process.umask(0o007);
        try {
            const result = offloadToolOutput({
                cwd,
                toolName: "bash",
                sessionId: "shared",
                toolCallId: "output",
                text: "x".repeat(OUTPUT_LIMIT_BYTES + 1),
                preview: "tail",
                previewLines: 40,
            });

            expect(result.kind).toBe("notice");
            if (result.kind !== "notice") {
                throw new Error("Expected shared output to be saved");
            }
            expect(statSync(orbit).mode & 0o777).toBe(0o775);
            expect(statSync(directory).mode & 0o777).toBe(0o770);
            expect(statSync(result.path).mode & 0o777).toBe(0o660);
        } finally {
            process.umask(umask);
        }
    });

    it("writes as the worker under a Node-owned Orbit directory without closing inherited ACLs", () => {
        const cwd = workspace();
        const orbit = join(cwd, ".git", "orbit");
        mkdirSync(orbit, { recursive: true });
        chmodSync(cwd, 0o755);
        chmodSync(join(cwd, ".git"), 0o755);
        chmodSync(orbit, 0o775);
        execFileSync("setfacl", [
            "-m",
            `u:nobody:rwx,d:u:nobody:rwx,d:u:${userInfo().username}:rwx`,
            "--",
            orbit,
        ]);
        // The source checkout may also live under a private runner home.
        const module = join(cwd, "tool-output.ts");
        copyFileSync(new URL("../src/tool-output.ts", import.meta.url), module);
        chmodSync(module, 0o644);
        const script = `
            import { offloadToolOutput } from ${JSON.stringify(module)};
            const result = offloadToolOutput({
                cwd: ${JSON.stringify(cwd)}, toolName: "read", sessionId: "worker", toolCallId: "acl",
                text: "x".repeat(8193), preview: "head", previewLines: 20,
            });
            if (result.kind !== "notice") throw new Error(JSON.stringify(result));
            console.log(result.path);
        `;

        const runtime = execFileSync("bun", ["--print", "process.execPath"], {
            encoding: "utf-8",
        }).trim();
        // The runtime may live in a private home that nobody cannot traverse.
        const bun = join(cwd, "bun");
        copyFileSync(runtime, bun);
        chmodSync(bun, 0o755);
        // Model sudo's restricted PATH on CI, where Bun is installed in the runner's home.
        const path = execFileSync(
            "sudo",
            ["-n", "-u", "nobody", "--", "env", "PATH=/usr/bin:/bin", bun, "--eval", script],
            { cwd, encoding: "utf-8" },
        ).trim();

        expect(statSync(orbit).uid).toBe(userInfo().uid);
        expect(statSync(orbit).mode & 0o777).toBe(0o775);
        expect(statSync(join(orbit, "tool-output")).mode & 0o777).toBe(0o770);
        expect(statSync(path).uid).toBe(65534);
        expect(statSync(path).mode & 0o777).toBe(0o660);
        expect(readFileSync(path, "utf-8")).toBe("x".repeat(8193));
        writeFileSync(path, "Node can also write\n");
        expect(readFileSync(path, "utf-8")).toBe("Node can also write\n");
    });

    it("returns the full text and writes nothing when .git is missing, a file, or a symlink", () => {
        const text = "m".repeat(OUTPUT_LIMIT_BYTES + 1);
        const missing = workspace();
        const fileGit = workspace();
        writeFileSync(join(fileGit, ".git"), "gitdir: /tmp/not-a-tool-output\n");
        const linked = workspace();
        const outside = join(linked, "outside");
        mkdirSync(outside);
        symlinkSync(outside, join(linked, ".git"));

        for (const cwd of [missing, fileGit, linked]) {
            const result = offloadToolOutput({
                cwd,
                toolName: "read",
                sessionId: "thread-1",
                toolCallId: "call",
                text,
                preview: "head",
                previewLines: 20,
            });
            expect(result).toEqual({ kind: "full" });
        }
        expect(exists(join(outside, "orbit"))).toBe(false);
        expect(exists(join(missing, ".git"))).toBe(false);
        expect(statSync(join(fileGit, ".git")).isFile()).toBe(true);
    });

    it("returns an error without the output when the file cannot be written", () => {
        const cwd = workspace();
        mkdirSync(join(cwd, ".git"));
        writeFileSync(join(cwd, ".git", "orbit"), "not a directory\n");
        const text = `SECRET${"z".repeat(OUTPUT_LIMIT_BYTES)}`;

        const result = offloadToolOutput({
            cwd,
            toolName: "read",
            sessionId: "thread-1",
            toolCallId: "call",
            text,
            preview: "head",
            previewLines: 20,
        });

        expect(result.kind).toBe("error");
        if (result.kind !== "error") {
            return;
        }
        expect(result.text).toBe(
            `${Buffer.byteLength(text)} bytes, 1 lines, not saved: orbit is not a directory`,
        );
        expect(result.text).not.toContain("SECRET");
    });

    it("keeps a bash status line on a failed write", () => {
        const cwd = workspace();
        mkdirSync(join(cwd, ".git"));
        writeFileSync(join(cwd, ".git", "orbit"), "not a directory\n");
        const text = `SECRET${"z".repeat(OUTPUT_LIMIT_BYTES)}`;

        const result = offloadToolOutput({
            cwd,
            toolName: "bash",
            sessionId: "thread-1",
            toolCallId: "call",
            text,
            preview: "tail",
            previewLines: 40,
            statusLine: "Exit code: 9",
        });

        expect(result.kind).toBe("error");
        if (result.kind !== "error") {
            return;
        }
        expect(result.text).toBe(
            `${Buffer.byteLength(text)} bytes, 1 lines, not saved: orbit is not a directory\nExit code: 9`,
        );
        expect(result.text).not.toContain("SECRET");
    });
});

describe("read and bash tools", () => {
    it("describes the 8192 byte file and not Pi's 50KB or 2000 line cap", () => {
        const { read, bash } = tools(workspace());

        for (const tool of [read, bash]) {
            expect(tool.description).toContain(".git/orbit/tool-output/");
            expect(tool.description).toContain("8,192");
            expect(tool.description).toContain("preview");
            expect(tool.description).not.toContain("50KB");
            expect(tool.description).not.toContain("2000");
            expect(tool.description).not.toContain("2,000");
        }
    });

    it("keeps a read at 8192 bytes and stores one at 8193, previewing the first lines", async () => {
        const cwd = gitWorkspace();
        const { read } = tools(cwd);
        const smallPath = join(cwd, "small.txt");
        const largePath = join(cwd, "large.txt");
        const small = "s".repeat(OUTPUT_LIMIT_BYTES);
        const large = numbered("HEAD", "TAIL", 30, 400);
        writeFileSync(smallPath, small);
        writeFileSync(largePath, large);

        const inline = await run(read, { path: smallPath });
        const stored = await run(read, { path: largePath });
        const saved = onlyOutput(cwd);

        expect(inline).toEqual({ text: small, isError: false });
        expect(stored.isError).toBe(false);
        expect(stored.text).toContain("HEAD");
        expect(stored.text).not.toContain("TAIL");
        expect(stored.text).toContain(HINT);
        expect(stored.text).not.toContain("Exit code:");
        expect(Buffer.byteLength(stored.text)).toBeLessThanOrEqual(OUTPUT_LIMIT_BYTES);
        expect(readFileSync(saved, "utf-8")).toBe(large);
        expect(stored.text).toContain(
            `${Buffer.byteLength(large)} bytes, ${splitLines(large).length} lines, saved to ${saved}`,
        );
    });

    it("returns more than 2000 short lines whole", async () => {
        const cwd = gitWorkspace();
        const { read } = tools(cwd);
        const text = Array.from({ length: 2001 }, () => "x").join("\n");
        const path = join(cwd, "lines.txt");
        writeFileSync(path, text);

        const result = await run(read, { path });

        expect(result).toEqual({ text, isError: false });
        expect(exists(join(cwd, ".git", "orbit"))).toBe(false);
    });

    it("stores the selected read slice, not the rest of the file", async () => {
        const cwd = gitWorkspace();
        const { read } = tools(cwd);
        const lines = Array.from(
            { length: 80 },
            (_, index) => `L${String(index + 1).padStart(2, "0")}${"q".repeat(200)}`,
        );
        const path = join(cwd, "wide.txt");
        writeFileSync(path, lines.join("\n"));
        const selected = lines.slice(10, 60).join("\n");
        expect(Buffer.byteLength(selected)).toBeGreaterThan(OUTPUT_LIMIT_BYTES);

        const result = await run(read, { path, offset: 11, limit: 50 });

        expect(result.isError).toBe(false);
        expect(readFileSync(onlyOutput(cwd), "utf-8")).toBe(selected);
        expect(result.text).toContain("L11");
        expect(result.text).not.toContain("L80");
        expect(result.text).not.toContain("more lines in file");
    });

    it("matches Pi for a small read, including a limit and a missing file", async () => {
        const cwd = workspace();
        const path = join(cwd, "note.txt");
        writeFileSync(path, "one\ntwo\nthree\n");
        const { read } = tools(cwd);
        const pi = createReadToolDefinition(cwd);

        for (const params of [
            { path },
            { path, offset: 2, limit: 1 },
            { path: join(cwd, "missing.txt") },
            { path, offset: 50 },
        ]) {
            expect(await run(read, params)).toEqual(await run(pi, params));
        }
    });

    it("leaves an image read unchanged", async () => {
        const cwd = gitWorkspace();
        const path = join(cwd, "pixel.png");
        writeFileSync(path, PNG);
        const { read } = tools(cwd);

        const result = await run(read, { path });

        expect(result.isError).toBe(false);
        expect(result.text).toContain("Read image file");
        expect(exists(join(cwd, ".git", "orbit"))).toBe(false);
    });

    it("keeps bash at 8192 bytes and stores 8193 with the tail, the hint, and exit code 0", async () => {
        const cwd = gitWorkspace();
        const { bash } = tools(cwd);
        const small = "b".repeat(OUTPUT_LIMIT_BYTES);
        const large = numbered("HEAD", "TAIL", 50, 200);
        writeFileSync(join(cwd, "small.txt"), small);
        writeFileSync(join(cwd, "large.txt"), large);

        const inline = await run(bash, { command: "cat small.txt" });
        const stored = await run(bash, { command: "cat large.txt" });
        const saved = onlyOutput(cwd);

        expect(inline).toEqual({ text: small, isError: false });
        expect(stored.isError).toBe(false);
        expect(stored.text).toContain("TAIL");
        expect(stored.text).not.toContain("HEAD");
        expect(stored.text).toContain(HINT);
        expect(stored.text.endsWith("Exit code: 0")).toBe(true);
        expect(Buffer.byteLength(stored.text)).toBeLessThanOrEqual(OUTPUT_LIMIT_BYTES);
        expect(readFileSync(saved, "utf-8")).toBe(large);
    });

    it("keeps a non-zero exit on a small command and on a stored command", async () => {
        const cwd = gitWorkspace();
        const { bash } = tools(cwd);
        const large = "e".repeat(OUTPUT_LIMIT_BYTES + 1);
        writeFileSync(join(cwd, "large.txt"), large);

        const small = await run(bash, { command: "printf 'x'; exit 4" });
        const stored = await run(bash, { command: "cat large.txt; exit 7" });

        expect(small).toEqual({ text: "x\n\nCommand exited with code 4", isError: true });
        expect(stored.isError).toBe(true);
        expect(stored.text.endsWith("Exit code: 7")).toBe(true);
        expect(stored.text).not.toContain(large);
        expect(readFileSync(onlyOutput(cwd), "utf-8")).toBe(large);
    });

    it("matches Pi for a small successful command and a small failure", async () => {
        const cwd = workspace();
        const { bash } = tools(cwd);
        const pi = createBashToolDefinition(cwd);

        for (const command of ["echo probe", "true", "printf 'x'; exit 4"]) {
            expect(await run(bash, { command })).toEqual(await run(pi, { command }));
        }
    });

    it("keeps Pi's agent bin directory on PATH", async () => {
        const cwd = workspace();
        const { bash } = tools(cwd);
        const pi = createBashToolDefinition(cwd);
        const command = "printf '%s' \"$PATH\"";

        const ours = await run(bash, { command });
        const theirs = await run(pi, { command });

        expect(ours).toEqual(theirs);
        expect(ours.text.split(delimiter)).toContain(join(getAgentDir(), "bin"));
    });

    it("keeps the exit, abort, or timeout line when a large command cannot be saved", async () => {
        const cwd = workspace();
        mkdirSync(join(cwd, ".git"));
        writeFileSync(join(cwd, ".git", "orbit"), "not a directory\n");
        const { bash } = tools(cwd);
        const large = `SECRET${"z".repeat(OUTPUT_LIMIT_BYTES)}`;
        writeFileSync(join(cwd, "large.txt"), large);

        const failed = await run(bash, { command: "cat large.txt; exit 9" });
        const controller = new AbortController();
        const aborted = bash.execute(
            "abort-save",
            { command: "cat large.txt; sleep 30" },
            controller.signal,
            undefined,
            undefined as never,
        );
        const abortSettled = aborted.catch((error: unknown) => error);
        await new Promise((resolve) => setTimeout(resolve, 200));
        controller.abort();
        const abortError = await abortSettled;
        expect(() => {
            throw abortError;
        }).toThrow(/not saved: .*\nCommand aborted$/);
        const timeout = await run(
            bash,
            { command: "cat large.txt; sleep 30", timeout: 1 },
            "timeout-save",
        );

        expect(failed.isError).toBe(true);
        expect(failed.text.endsWith("Exit code: 9")).toBe(true);
        expect(failed.text).toContain("not saved:");
        expect(failed.text).not.toContain("SECRET");
        expect(timeout.isError).toBe(true);
        expect(timeout.text).toContain("not saved:");
        expect(timeout.text.endsWith("Command timed out after 1 seconds")).toBe(true);
        expect(timeout.text).not.toContain("Exit code:");
        expect(timeout.text).not.toContain("SECRET");
    });

    it("stores an aborted or timed out command without inventing an exit code", async () => {
        const cwd = gitWorkspace();
        const { bash } = tools(cwd);
        const large = "t".repeat(OUTPUT_LIMIT_BYTES + 1);
        writeFileSync(join(cwd, "large.txt"), large);
        const controller = new AbortController();
        const aborted = bash.execute(
            "abort-1",
            { command: "cat large.txt; sleep 30" },
            controller.signal,
            undefined,
            undefined as never,
        );
        await new Promise((resolve) => setTimeout(resolve, 200));
        controller.abort();

        await expect(aborted).rejects.toThrow(/Command aborted$/);
        const timeout = await run(
            bash,
            { command: "cat large.txt; sleep 30", timeout: 1 },
            "timeout-1",
        );

        expect(timeout.isError).toBe(true);
        expect(timeout.text.endsWith("Command timed out after 1 seconds")).toBe(true);
        expect(timeout.text).not.toContain("Exit code:");
        const files = outputFiles(cwd);
        expect(files).toHaveLength(2);
        for (const file of files) {
            expect(readFileSync(file, "utf-8")).toBe(large);
        }
    });

    it("returns a large result whole when the workspace has no .git directory", async () => {
        const cwd = workspace();
        const { read, bash } = tools(cwd);
        const text = `WHOLE${"w".repeat(OUTPUT_LIMIT_BYTES)}`;
        writeFileSync(join(cwd, "whole.txt"), text);
        const before = new Set(readdirSync(tmpdir()));

        const readResult = await run(read, { path: join(cwd, "whole.txt") });
        const bashResult = await run(bash, { command: "cat whole.txt" });

        expect(readResult).toEqual({ text, isError: false });
        expect(bashResult).toEqual({ text, isError: false });
        expect(exists(join(cwd, ".git"))).toBe(false);
        expect(
            newTmpFiles(before).some((path) => readFileSync(path, "utf-8").includes("WHOLE")),
        ).toBe(false);
    });
});

describe("session transcript", () => {
    it("gives the model the offload descriptions and a small bash result unchanged", async () => {
        harness = await startHarness();
        let request = "";
        harness.faux.setResponses([
            (context) => {
                request = JSON.stringify(context);
                return fauxAssistantMessage(
                    [fauxToolCall("bash", { command: "printf '%s' \"$PI_SESSION_ID\"" })],
                    {
                        stopReason: "toolUse",
                    },
                );
            },
            fauxAssistantMessage("finished"),
        ]);
        await harness.request("POST", "/sessions", {
            id: "thread-1",
            cwd: harness.workspace,
            model: MODEL,
            thinkingLevel: "low",
        });
        await harness.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const snapshot = await settled("thread-1");

        expect(request).toContain(".git/orbit/tool-output/");
        expect(request).toContain("8,192");
        expect(request).not.toContain("50KB");
        expect(request).not.toContain("2,000");
        expect(toolText(snapshot)).toEqual({ text: "thread-1", isError: false });
        expect(exists(join(harness.workspace, ".git"))).toBe(false);
    });

    it("stores a large read and a large failed bash in the transcript as notices", async () => {
        harness = await startHarness();
        mkdirSync(join(harness.workspace, ".git"));
        const readText = numbered("HEAD", "TAIL", 30, 400);
        const bashText = numbered("BASHHEAD", "BASHTAIL", 50, 200);
        writeFileSync(join(harness.workspace, "read.txt"), readText);
        writeFileSync(join(harness.workspace, "bash.txt"), bashText);
        const beforeTmp = new Set(readdirSync(tmpdir()));

        const readSnapshot = await turn("read-session", "read", {
            path: "read.txt",
        });
        const bashSnapshot = await turn("bash-session", "bash", {
            command: "cat bash.txt; exit 3",
        });
        const readNotice = toolText(readSnapshot);
        const bashNotice = toolText(bashSnapshot);
        const directory = join(harness.workspace, ".git", "orbit", "tool-output");
        const files = readdirSync(directory).map((name) => join(directory, name));

        expect(readNotice.isError).toBe(false);
        expect(readNotice.text).toContain("HEAD");
        expect(readNotice.text).not.toContain("TAIL");
        expect(readNotice.text).toContain(HINT);
        expect(Buffer.byteLength(readNotice.text)).toBeLessThanOrEqual(OUTPUT_LIMIT_BYTES);
        expect(bashNotice.isError).toBe(true);
        expect(bashNotice.text).toContain("BASHTAIL");
        expect(bashNotice.text).not.toContain("BASHHEAD");
        expect(bashNotice.text.endsWith("Exit code: 3")).toBe(true);
        expect(files).toHaveLength(2);
        expect(files.some((file) => readFileSync(file, "utf-8") === readText)).toBe(true);
        expect(files.some((file) => readFileSync(file, "utf-8") === bashText)).toBe(true);
        expect(
            files.every((file) => file.includes(`${sep}.git${sep}orbit${sep}tool-output${sep}`)),
        ).toBe(true);
        const leaked = newTmpFiles(beforeTmp).filter((file) => {
            try {
                return readFileSync(file, "utf-8").includes("BASHHEAD");
            } catch {
                return false;
            }
        });
        expect(leaked).toEqual([]);
    });

    it("does not store edit output", async () => {
        harness = await startHarness();
        mkdirSync(join(harness.workspace, ".git"));
        writeFileSync(join(harness.workspace, "note.txt"), "before\n");
        const snapshot = await turn("edit-session", "edit", {
            path: "note.txt",
            edits: [{ oldText: "before", newText: "after" }],
        });

        expect(toolText(snapshot).isError).toBe(false);
        expect(readFileSync(join(harness.workspace, "note.txt"), "utf-8")).toBe("after\n");
        expect(exists(join(harness.workspace, ".git", "orbit"))).toBe(false);
    });
});

function tools(cwd: string) {
    const [read, bash] = createWorkspaceTools({ cwd, sessionId: "thread-1" });
    if (read === undefined || bash === undefined) {
        throw new Error("expected read and bash tools");
    }
    return { read, bash };
}

async function run(
    tool: { execute: (...args: any[]) => Promise<{ content: { type: string; text?: string }[] }> },
    params: Record<string, unknown>,
    id = "call:1",
): Promise<{ text: string; isError: boolean }> {
    try {
        const result = await tool.execute(id, params, undefined, undefined, undefined);
        return { text: result.content.map((block) => block.text ?? "").join(""), isError: false };
    } catch (error) {
        return { text: error instanceof Error ? error.message : String(error), isError: true };
    }
}

function workspace(): string {
    const cwd = mkdtempSync(join(tmpdir(), "pi-tool-output-"));
    roots.push(cwd);
    return cwd;
}

function gitWorkspace(): string {
    const cwd = workspace();
    mkdirSync(join(cwd, ".git"));
    return cwd;
}

function numbered(head: string, tail: string, count: number, width: number): string {
    return Array.from({ length: count }, (_, index) => {
        const label =
            index === 0
                ? head
                : index === count - 1
                  ? tail
                  : `L${String(index + 1).padStart(3, "0")}`;
        return label + "x".repeat(Math.max(0, width - label.length));
    }).join("\n");
}

function outputFiles(cwd: string): string[] {
    const directory = join(cwd, ".git", "orbit", "tool-output");
    return readdirSync(directory).map((name) => join(directory, name));
}

function onlyOutput(cwd: string): string {
    const files = outputFiles(cwd);
    expect(files).toHaveLength(1);
    return files[0]!;
}

function exists(path: string): boolean {
    try {
        statSync(path);
        return true;
    } catch {
        return false;
    }
}

function newTmpFiles(before: Set<string>): string[] {
    return readdirSync(tmpdir())
        .filter((name) => !before.has(name))
        .map((name) => join(tmpdir(), name))
        .filter((path) => {
            try {
                return statSync(path).isFile();
            } catch {
                return false;
            }
        });
}

async function turn(id: string, tool: string, args: JsonObject) {
    const h = harness;
    if (h === undefined) {
        throw new Error("harness is not started");
    }
    const created = await h.request("POST", "/sessions", {
        id,
        cwd: h.workspace,
        model: MODEL,
        thinkingLevel: "low",
    });
    expect(created.status).toBe(201);
    h.faux.setResponses([
        fauxAssistantMessage([fauxToolCall(tool, args)], { stopReason: "toolUse" }),
        fauxAssistantMessage("finished"),
    ]);
    expect(
        (await h.request("POST", `/sessions/${id}/messages`, { key: `${id}-k`, text: "go" }))
            .status,
    ).toBe(202);
    return settled(id);
}

async function settled(id: string) {
    const h = harness;
    if (h === undefined) {
        throw new Error("harness is not started");
    }
    return waitFor(
        async () => (await h.request("GET", `/sessions/${id}`)).body,
        (snapshot) => snapshot.state !== "working",
    );
}

function toolText(snapshot: {
    entries: { message: { role?: string; isError?: boolean; content?: { text?: string }[] } }[];
}) {
    const message = snapshot.entries.find((entry) => entry.message.role === "toolResult")?.message;
    if (message === undefined) {
        throw new Error("no tool result");
    }
    return {
        text: (message.content ?? []).map((block) => block.text ?? "").join(""),
        isError: message.isError === true,
    };
}
