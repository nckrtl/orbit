import { realpathSync } from "node:fs";
import { DatabaseSync } from "node:sqlite";
import { homedir } from "node:os";
import { resolve } from "node:path";
import type { Plugin } from "vite-plus";

function canonicalPath(path: string): string {
    try {
        return realpathSync(path);
    } catch {
        return path;
    }
}

export function discoverThread(databasePath: string, worktreePath: string) {
    let database: DatabaseSync | undefined;
    try {
        database = new DatabaseSync(databasePath, { readOnly: true });
        const matches = database
            .prepare(
                "SELECT thread_id AS id, title, worktree_path FROM projection_threads WHERE deleted_at IS NULL AND archived_at IS NULL",
            )
            .all()
            .filter(
                (row) =>
                    typeof row.worktree_path === "string" &&
                    canonicalPath(row.worktree_path) === canonicalPath(worktreePath),
            )
            .map((row) => ({ id: row.id, title: row.title }));
        if (matches.length > 1) return { status: "ambiguous" };
        if (matches.length === 1) return { status: "detected", ...matches[0] };
        return { status: "missing" };
    } catch {
        return { status: "unavailable" };
    } finally {
        database?.close();
    }
}

export function annotationThread(): Plugin {
    return {
        name: "annotation-thread",
        configureServer(server) {
            server.middlewares.use((request, response, next) => {
                if (request.url?.split("?")[0] !== "/__annotate/thread") return next();
                if (request.method !== "GET") {
                    response.statusCode = 405;
                    response.end();
                    return;
                }
                response.setHeader("Content-Type", "application/json");
                response.setHeader("Cache-Control", "no-store");
                response.end(
                    JSON.stringify(
                        discoverThread(
                            resolve(homedir(), ".t3/userdata/state.sqlite"),
                            resolve(server.config.root, "../.."),
                        ),
                    ),
                );
            });
        },
    };
}
