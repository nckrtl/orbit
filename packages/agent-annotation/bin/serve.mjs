#!/usr/bin/env node
import { isIP } from "node:net";
import { createServer } from "node:http";
import { mkdtempSync, watch } from "node:fs";
import { AnnotationStore, normalizeStatus, validId } from "./store.mjs";
import { tmpdir } from "node:os";
import { resolve, join } from "node:path";
import { stripVTControlCharacters } from "node:util";

const args = process.argv.slice(2);
if (args.includes("--help") || args.includes("-h")) {
    console.log(
        "Usage: annotate serve [--port PORT] [--host IP] [--store DIRECTORY]\n\nStart a local annotation server. Default: random port and a new temporary store.",
    );
    process.exit(0);
}
try {
    if (args.shift() !== "serve")
        throw new Error("Use: annotate serve [--port PORT] [--host IP] [--store DIRECTORY]");
    let port = 0;
    let host = "127.0.0.1";
    let file;
    while (args.length) {
        const flag = args.shift();
        const value = args.shift();
        if (!value) throw new Error(`Missing value for ${flag}`);
        if (flag === "--port" && /^\d+$/.test(value) && Number(value) > 0 && Number(value) <= 65535)
            port = Number(value);
        else if (flag === "--host" && isIP(value)) host = value;
        else if (flag === "--store") file = resolve(value);
        else throw new Error(`Invalid option: ${flag} ${value}`);
    }
    file ??= mkdtempSync(join(tmpdir(), "annotate-"));
    const store = new AnnotationStore(file);
    const base = "/annotations";
    const streams = new Set();
    const knownStatuses = new Map(store.list().map((a) => [a.id, a.status]));
    const notify = () => {
        for (const annotation of store.list()) {
            const previous = knownStatuses.get(annotation.id);
            if (previous === annotation.status) continue;
            const activity =
                previous === undefined ? "created" : annotation.status.replaceAll("_", " ");
            const description = stripVTControlCharacters(annotation.comment)
                .replace(/\s+/g, " ")
                .replace(/\p{Cc}/gu, "")
                .trim();
            console.log(`#${annotation.number} ${activity}: ${description}`);
            knownStatuses.set(annotation.id, annotation.status);
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
            const path = new URL(request.url, "http://localhost").pathname;
            if (
                path !== base &&
                !path.startsWith(`${base}/`) &&
                !["/claim", "/complete", "/release"].includes(path)
            )
                return json(404, { error: "Not found" });
            response.setHeader("Access-Control-Allow-Origin", "*");
            response.setHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
            response.setHeader("Access-Control-Allow-Headers", "Content-Type");
            response.setHeader("Access-Control-Allow-Private-Network", "true");
            if (request.method === "OPTIONS") {
                response.writeHead(204);
                return response.end();
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
                        service: "@nckrtl/annotate",
                        eventsUrl: `${base}/events`,
                        lastNumber: store.lastNumber,
                    },
                });
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
                if (!validId(body.id) || typeof body.comment !== "string" || !body.comment.trim())
                    return json(422, { error: "id and comment are required" });
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
            const updated = store.transition(current, normalizeStatus(body.status), body.summary);
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
        console.log(
            `Annotation server URL: http://${isIP(host) === 6 ? `[${host}]` : host}:${server.address().port}${base}\nStore: ${store.path}\nPaste the URL into Annotation server URL in the toolbar settings.\nPress Ctrl+C to stop.`,
        );
    });
    const stop = () => {
        for (const stream of streams) stream.end();
        for (const watcher of watchers) watcher.close();
        clearTimeout(noticeTimer);
        store.close();
        server.close();
        server.closeAllConnections();
    };
    process.on("SIGINT", stop);
    process.on("SIGTERM", stop);
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
