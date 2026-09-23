import { afterEach, expect, test } from "vite-plus/test";
import { DatabaseSync } from "node:sqlite";
import { mkdtempSync, rmSync, symlinkSync, mkdirSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { discoverThread } from "../../dev/annotation-thread";

const directories: string[] = [];
afterEach(() => {
    for (const dir of directories.splice(0)) rmSync(dir, { recursive: true });
});
test("detects only a unique live thread for the worktree", () => {
    const dir = mkdtempSync(join(tmpdir(), "annotate-thread-"));
    directories.push(dir);
    const path = join(dir, "state.sqlite");
    const db = new DatabaseSync(path);
    db.exec(
        "CREATE TABLE projection_threads(thread_id TEXT, title TEXT, worktree_path TEXT, deleted_at TEXT, archived_at TEXT)",
    );
    const insert = db.prepare(
        "INSERT INTO projection_threads VALUES (?, 'Annotation', ?, NULL, ?)",
    );
    insert.run("other", "/other", null);
    insert.run("archived", "/project", "2026-01-01");
    expect(discoverThread(path, "/project")).toEqual({ status: "missing" });
    insert.run("current", "/project", null);
    expect(discoverThread(path, "/project")).toEqual({
        status: "detected",
        id: "current",
        title: "Annotation",
    });
    insert.run("second", "/project", null);
    expect(discoverThread(path, "/project")).toEqual({ status: "ambiguous" });
    db.close();
});
test("missing database does not prevent mounting", () => {
    expect(discoverThread("/nonexistent/annotation.sqlite", "/project")).toEqual({
        status: "unavailable",
    });
});

test("matches a T3 path retained as a symlink after Instance registration", () => {
    const dir = mkdtempSync(join(tmpdir(), "annotate-alias-"));
    directories.push(dir);
    const target = join(dir, "managed");
    mkdirSync(target);
    const original = join(dir, "t3");
    symlinkSync(target, original);
    const path = join(dir, "state.sqlite");
    const db = new DatabaseSync(path);
    db.exec(
        "CREATE TABLE projection_threads(thread_id TEXT, title TEXT, worktree_path TEXT, deleted_at TEXT, archived_at TEXT)",
    );
    db.prepare(
        "INSERT INTO projection_threads VALUES ('current', 'Annotation', ?, NULL, NULL)",
    ).run(original);
    expect(discoverThread(path, target)).toEqual({
        status: "detected",
        id: "current",
        title: "Annotation",
    });
    db.close();
});
