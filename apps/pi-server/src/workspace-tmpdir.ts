import { execFileSync } from "node:child_process";
import { chmodSync, lstatSync, mkdirSync, realpathSync } from "node:fs";
import { join } from "node:path";

/** Per-workspace, per-role temp: never mutate the server's process-wide environment. */
export function workspaceTmpdir(cwd: string): string {
    let metadata: string;
    let directoryName = "orbit";
    try {
        metadata = execFileSync(
            "git",
            [
                "-c",
                "core.hooksPath=/dev/null",
                "-c",
                "core.fsmonitor=false",
                "-C",
                cwd,
                "rev-parse",
                "--absolute-git-dir",
            ],
            { encoding: "utf-8", stdio: ["ignore", "pipe", "ignore"] },
        ).trim();
    } catch {
        // Pi also accepts non-Git sessions. Keep their temp files local to that workspace.
        metadata = realpathSync(cwd);
        directoryName = ".orbit";
    }
    const orbit = childDirectory(metadata, directoryName, 0o775);
    const temporary = childDirectory(orbit, "tmp", 0o775);
    const directory = childDirectory(temporary, `agent-${process.getuid?.() ?? "user"}`, 0o700);
    // Shared workspace ACLs are useful on the parent, but temp files belong to one role.
    if (process.platform === "linux") {
        execFileSync("setfacl", ["-b", "-k", "--", directory]);
    }
    chmodSync(directory, 0o700);
    return directory;
}

function childDirectory(parent: string, name: string, mode: number): string {
    const path = join(parent, name);
    try {
        mkdirSync(path, { mode });
        chmodSync(path, mode);
    } catch (error) {
        if (
            typeof error !== "object" ||
            error === null ||
            !("code" in error) ||
            error.code !== "EEXIST"
        ) {
            throw error;
        }
    }
    const details = lstatSync(path);
    if (!details.isDirectory() || details.isSymbolicLink()) {
        throw new Error(`Refusing workspace temp directory ${path}`);
    }
    if (mode === 0o700 && process.getuid !== undefined && details.uid !== process.getuid()) {
        throw new Error(`Workspace temp directory has another owner: ${path}`);
    }
    return path;
}
