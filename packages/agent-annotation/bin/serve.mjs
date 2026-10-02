#!/usr/bin/env node
import { isIP } from "node:net";
import { createServer } from "node:http";
import { mkdtempSync, readFileSync, watch } from "node:fs";
import { AnnotationStore, normalizeStatus, validId } from "./store.mjs";
import { clearState, start, status, stop as stopServer, writeState } from "./lifecycle.mjs";
import { networkInterfaces, tmpdir } from "node:os";
import { resolve, join } from "node:path";
import { fileURLToPath } from "node:url";
import { stripVTControlCharacters } from "node:util";

const usage = `Usage:
  annotator serve [--port PORT] [--host [IP]] [--store DIRECTORY] [--state FILE]
  annotator start --state FILE [--port PORT] [--host [IP]] [--store DIRECTORY]
  annotator stop --state FILE
  annotator status --state FILE

serve runs in the foreground. Default: 127.0.0.1, a random port and a new temporary store.
--host without an IP binds to 0.0.0.0, so other machines can reach the server.
--state writes the server's PID, port and URL to FILE while it runs.
start runs serve in the background; stop and status use the same state file.
start, stop and status print JSON.`;
const args = process.argv.slice(2);
if (args.includes("--help") || args.includes("-h")) {
    console.log(usage);
    process.exit(0);
}
const command = args.shift();
try {
    if (!["serve", "start", "stop", "status"].includes(command))
        throw new Error(
            "Use: annotator serve|start|stop|status. Run annotator --help for options.",
        );
    let port = 0;
    let host = "127.0.0.1";
    let file;
    let stateFile;
    const serveArgs = [];
    while (args.length) {
        const flag = args.shift();
        if (flag === "--host" && (!args.length || args[0].startsWith("--"))) {
            host = "0.0.0.0";
            serveArgs.push(flag);
            continue;
        }
        const value = args.shift();
        if (!value) throw new Error(`Missing value for ${flag}`);
        if (flag === "--port" && /^\d+$/.test(value) && Number(value) > 0 && Number(value) <= 65535)
            port = Number(value);
        else if (flag === "--host" && isIP(value)) host = value;
        else if (flag === "--store") file = resolve(value);
        else if (flag === "--state") stateFile = resolve(value);
        else throw new Error(`Invalid option: ${flag} ${value}`);
        if (flag !== "--state") serveArgs.push(flag, flag === "--store" ? file : value);
    }
    if (command !== "serve") {
        if (!stateFile) throw new Error(`${command} requires --state FILE`);
        const script = fileURLToPath(import.meta.url);
        const run =
            command === "start"
                ? start(stateFile, serveArgs, script)
                : command === "stop"
                  ? stopServer(stateFile)
                  : status(stateFile);
        run.then(
            (result) => console.log(JSON.stringify(result)),
            (error) => {
                console.log(JSON.stringify({ running: false, error: error.message }));
                process.exitCode = 1;
            },
        );
    } else {
        file ??= mkdtempSync(join(tmpdir(), "annotate-"));
        const store = new AnnotationStore(file);
        const base = "/annotations";
        const skillTemplate = readFileSync(new URL("./SKILL.md", import.meta.url), "utf8");
        // A wildcard bind is printed with the first network address, so the URL works from other machines.
        const networkAddress = (family) =>
            Object.values(networkInterfaces())
                .flat()
                .find(
                    (address) =>
                        address?.family === family &&
                        !address.internal &&
                        !address.address.startsWith("fe80:"),
                )?.address;
        const publicHost =
            host === "0.0.0.0"
                ? (networkAddress("IPv4") ?? "127.0.0.1")
                : host === "::"
                  ? (networkAddress("IPv6") ?? "::1")
                  : host;
        const serverUrl = () =>
            `http://${isIP(publicHost) === 6 ? `[${publicHost}]` : publicHost}:${server.address().port}`;
        const streams = new Set();
        const knownAnnotations = new Map(store.list().map((a) => [a.id, a]));
        const logActivity = (annotation, activity) => {
            const description = stripVTControlCharacters(annotation.comment)
                .replace(/\s+/g, " ")
                .replace(/\p{Cc}/gu, "")
                .trim();
            console.log(`#${annotation.number} ${activity}: ${description}`);
        };
        const notify = () => {
            const records = store.list();
            const present = new Set(records.map((a) => a.id));
            const removed = [...knownAnnotations.values()].filter((a) => !present.has(a.id));
            store.rememberDeleted(removed.map((a) => a.id));
            for (const annotation of removed) {
                logActivity(annotation, "deleted");
                knownAnnotations.delete(annotation.id);
            }
            for (const annotation of records) {
                const previous = knownAnnotations.get(annotation.id)?.status;
                knownAnnotations.set(annotation.id, annotation);
                if (previous === annotation.status) continue;
                const activity =
                    previous === undefined ? "created" : annotation.status.replaceAll("_", " ");
                logActivity(annotation, activity);
            }
            for (const response of streams) response.write("data: {}\n\n");
        };
        let noticeTimer;
        const watchers = ["todo", "in-progress", "done"].map((folder) =>
            watch(join(store.path, folder), () => {
                clearTimeout(noticeTimer);
                noticeTimer = setTimeout(() => {
                    try {
                        notify();
                    } catch (error) {
                        console.error(error.message);
                    }
                }, 25);
            }),
        );
        const server = createServer(async (request, response) => {
            const json = (status, body) => {
                response.writeHead(status, {
                    "Content-Type": "application/json",
                    "Cache-Control": "no-store",
                });
                response.end(JSON.stringify(body));
            };
            try {
                const url = new URL(request.url, "http://localhost");
                const path = url.pathname;
                if (
                    path !== base &&
                    !path.startsWith(`${base}/`) &&
                    !["/claim", "/complete", "/release", "/skill"].includes(path)
                )
                    return json(404, { error: "Not found" });
                response.setHeader("Access-Control-Allow-Origin", "*");
                response.setHeader(
                    "Access-Control-Allow-Methods",
                    "GET, HEAD, POST, DELETE, OPTIONS",
                );
                response.setHeader("Access-Control-Allow-Headers", "Content-Type");
                response.setHeader("Access-Control-Allow-Private-Network", "true");
                if (request.method === "OPTIONS") {
                    response.writeHead(204);
                    return response.end();
                }
                if (path === "/skill" || path === `${base}/skill`) {
                    if (!["GET", "HEAD"].includes(request.method))
                        return json(405, { error: "Method not allowed" });
                    response.writeHead(200, {
                        "Content-Type": "text/markdown; charset=utf-8",
                        "Cache-Control": "no-store",
                    });
                    return response.end(
                        request.method === "HEAD"
                            ? undefined
                            : skillTemplate
                                  .replaceAll("{{ANNOTATIONS_URL}}", `${serverUrl()}${base}`)
                                  .replaceAll("{{PROJECT_ROOT}}", process.cwd()),
                    );
                }
                if (request.method === "GET" && path === `${base}/events`) {
                    response.writeHead(200, {
                        "Content-Type": "text/event-stream",
                        "Cache-Control": "no-cache",
                        Connection: "keep-alive",
                    });
                    response.write("data: {}\n\n");
                    streams.add(response);
                    const heartbeat = setInterval(() => response.write(": heartbeat\n\n"), 15000);
                    response.on("close", () => {
                        streams.delete(response);
                        clearInterval(heartbeat);
                    });
                    return;
                }
                if (request.method === "GET" && path === base)
                    return json(200, {
                        data: store.list(),
                        meta: {
                            service: "@nckrtl/annotator",
                            eventsUrl: `${base}/events`,
                            skillUrl: `${base}/skill`,
                            lastNumber: store.lastNumber,
                            deletedIds: [...store.deletedIds],
                        },
                    });
                if (request.method === "DELETE") {
                    const id =
                        path === base ? undefined : decodeURIComponent(path.slice(base.length + 1));
                    if (id !== undefined && !validId(id))
                        return json(422, { error: "Invalid annotation id" });
                    // "?pathname=/page" limits a bulk removal to the annotations of one page.
                    const pathname =
                        id === undefined
                            ? (url.searchParams.get("pathname") ?? undefined)
                            : undefined;
                    const removed = store.remove(id, pathname);
                    if (id === undefined && pathname === undefined) {
                        knownAnnotations.clear();
                        console.log("All annotations removed");
                    }
                    notify();
                    return json(200, { data: { removedIds: removed.map((a) => a.id) } });
                }
                if (request.method !== "POST") return json(405, { error: "Method not allowed" });
                let raw = "";
                for await (const chunk of request) {
                    raw += chunk;
                    if (Buffer.byteLength(raw) > 10 * 1024 * 1024)
                        return json(413, { error: "Annotation exceeds 10 MiB" });
                }
                let body;
                try {
                    body = raw.trim() ? JSON.parse(raw) : {};
                } catch {
                    return json(400, { error: "Invalid JSON" });
                }
                if (!body || typeof body !== "object" || Array.isArray(body))
                    return json(422, { error: "Expected an object" });
                if (path === base) {
                    if (
                        !validId(body.id) ||
                        typeof body.comment !== "string" ||
                        !body.comment.trim()
                    )
                        return json(422, { error: "id and comment are required" });
                    if (store.deletedIds.has(body.id))
                        return json(410, { error: "Annotation was removed" });
                    const { annotation, created } = store.create(body);
                    if (created) notify();
                    return json(created ? 201 : 200, { data: annotation });
                }
                const action = path.startsWith(`${base}/`) ? path.slice(base.length) : path;
                if (action === "/claim") {
                    const annotation = store.claim();
                    if (!annotation) {
                        response.writeHead(204);
                        return response.end();
                    }
                    notify();
                    return json(200, { data: annotation });
                }
                if (["/complete", "/release"].includes(action)) {
                    if (
                        !validId(body.id) ||
                        (body.summary !== undefined && typeof body.summary !== "string")
                    )
                        return json(422, { error: "A valid id and optional summary are required" });
                    const current = store.find(body.id);
                    if (!current) return json(404, { error: "Annotation not found" });
                    const status = action === "/complete" ? "done" : "todo";
                    if (current.status === status) return json(200, { data: current });
                    if (current.status !== "in_progress")
                        return json(409, { error: "Annotation must be in progress" });
                    const updated = store.transition(current, status, body.summary);
                    notify();
                    return json(200, { data: updated });
                }
                const match = path.slice(base.length).match(/^\/([^/]+)\/status$/);
                if (!match) return json(404, { error: "Not found" });
                const id = decodeURIComponent(match[1]);
                const current = store.find(id);
                if (!current) return json(404, { error: "Annotation not found" });
                if (
                    !normalizeStatus(body.status) ||
                    (body.summary !== undefined && typeof body.summary !== "string")
                )
                    return json(422, { error: "Invalid status or summary" });
                const updated = store.transition(
                    current,
                    normalizeStatus(body.status),
                    body.summary,
                );
                notify();
                json(200, { data: updated });
            } catch (error) {
                console.error(error.message);
                if (!response.headersSent) json(500, { error: "Could not process annotation" });
                else response.end();
            }
        });
        server.on("error", (error) => {
            console.error(error.message);
            process.exitCode = 1;
            stop();
        });
        server.listen(port, host, () => {
            if (stateFile)
                writeState(stateFile, {
                    pid: process.pid,
                    port: server.address().port,
                    url: `${serverUrl()}${base}`,
                    skillUrl: `${serverUrl()}/skill`,
                    store: store.path,
                    cwd: process.cwd(),
                    startedAt: new Date().toISOString(),
                });
            console.log(
                `Annotation server URL: ${serverUrl()}${base}\nSkill URL: ${serverUrl()}/skill\nStore: ${store.path}\nPaste the URL into Annotation server URL in the toolbar settings.\nPress Ctrl+C to stop.`,
            );
        });
        const stop = () => {
            if (stateFile) clearState(stateFile, process.pid);
            for (const stream of streams) stream.end();
            for (const watcher of watchers) watcher.close();
            clearTimeout(noticeTimer);
            store.close();
            server.close();
            server.closeAllConnections();
        };
        process.on("SIGINT", stop);
        process.on("SIGTERM", stop);
    }
} catch (error) {
    if (["start", "stop", "status"].includes(command))
        console.log(JSON.stringify({ running: false, error: error.message }));
    else console.error(error.message);
    process.exitCode = 1;
}
