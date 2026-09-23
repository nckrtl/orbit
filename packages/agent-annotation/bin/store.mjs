import {
    existsSync,
    mkdirSync,
    openSync,
    closeSync,
    readFileSync,
    writeFileSync,
    renameSync,
    unlinkSync,
    readdirSync,
    statSync,
} from "node:fs";
import { basename, dirname, join } from "node:path";

export const validId = (id) =>
    typeof id === "string" && /^[a-zA-Z0-9][a-zA-Z0-9_-]{0,199}$/.test(id);
const states = {
    pending: "todo",
    todo: "todo",
    in_progress: "in_progress",
    resolved: "done",
    done: "done",
    cancelled: "cancelled",
};
const folderFor = (status) =>
    status === "todo" ? "todo" : status === "in_progress" ? "in-progress" : "done";
export const normalizeStatus = (status) =>
    Object.hasOwn(states, status) ? states[status] : undefined;

export class AnnotationStore {
    constructor(path) {
        let legacy;
        if (
            (existsSync(path) && statSync(path).isFile()) ||
            (!existsSync(path) && existsSync(`${path}.legacy`))
        ) {
            legacy = existsSync(path) ? path : undefined;
            path = basename(path) === "annotations.json" ? dirname(path) : `${path}.d`;
        }
        this.path = path;
        mkdirSync(path, { recursive: true });
        this.lock = join(path, ".server.pid");
        if (existsSync(this.lock)) {
            const pid = Number(readFileSync(this.lock, "utf8"));
            if (!Number.isSafeInteger(pid) || pid <= 0)
                throw new Error(`Invalid store lock: ${this.lock}`);
            try {
                process.kill(pid, 0);
                throw new Error("This annotation store is already served by another process.");
            } catch (error) {
                if (error.code !== "ESRCH") throw error;
            }
            unlinkSync(this.lock);
        }
        const descriptor = openSync(this.lock, "wx", 0o600);
        writeFileSync(descriptor, String(process.pid));
        closeSync(descriptor);
        this.ownsLock = true;
        try {
            this.counterFile = join(path, ".sequence");
            this.lastNumber = existsSync(this.counterFile)
                ? Number(readFileSync(this.counterFile, "utf8"))
                : 0;
            if (!Number.isSafeInteger(this.lastNumber) || this.lastNumber < 0)
                throw new Error("Invalid annotation sequence");
            for (const directory of ["todo", "in-progress", "done"])
                mkdirSync(join(path, directory), { recursive: true });
            legacy ??= join(path, "annotations.json");
            if (existsSync(legacy)) {
                const records = JSON.parse(readFileSync(legacy, "utf8"));
                if (
                    !Array.isArray(records) ||
                    records.some(
                        (a) =>
                            !validId(a?.id) ||
                            typeof a.comment !== "string" ||
                            !normalizeStatus(a.status),
                    )
                )
                    throw new Error("Invalid legacy annotation store");
                for (const annotation of records) {
                    if (this.find(annotation.id)) continue;
                    const status = normalizeStatus(annotation.status);
                    this.write({
                        ...annotation,
                        status,
                        revision: Number.isSafeInteger(annotation.revision)
                            ? annotation.revision
                            : 1,
                    });
                }
                renameSync(legacy, `${legacy}.legacy`);
            }
            this.list();
        } catch (error) {
            this.close();
            throw error;
        }
    }
    close() {
        if (this.ownsLock) {
            this.ownsLock = false;
            unlinkSync(this.lock);
        }
    }
    list() {
        const result = [];
        const ids = new Set();
        for (const folder of ["todo", "in-progress", "done"]) {
            for (const name of readdirSync(join(this.path, folder))) {
                if (!name.endsWith(".json")) continue;
                const file = join(this.path, folder, name);
                const annotation = JSON.parse(readFileSync(file, "utf8"));
                if (
                    !validId(annotation?.id) ||
                    name !== `${annotation.id}.json` ||
                    typeof annotation.comment !== "string" ||
                    !Number.isSafeInteger(annotation.revision)
                )
                    throw new Error(`Invalid annotation file: ${file}`);
                if (ids.has(annotation.id))
                    throw new Error(`Duplicate annotation: ${annotation.id}`);
                ids.add(annotation.id);
                // Directory ownership survives a crash between a move and the JSON update.
                const status =
                    folder === "todo"
                        ? "todo"
                        : folder === "in-progress"
                          ? "in_progress"
                          : annotation.status === "cancelled"
                            ? "cancelled"
                            : "done";
                if (annotation.status !== status) {
                    annotation.status = status;
                    annotation.revision++;
                    this.write(annotation);
                }
                result.push(annotation);
            }
        }
        result.sort(
            (a, b) =>
                (a.createdAt ?? a.timestamp ?? 0) - (b.createdAt ?? b.timestamp ?? 0) ||
                a.id.localeCompare(b.id),
        );
        this.saveNumber(
            Math.max(
                this.lastNumber,
                ...result.map((a) =>
                    Number.isSafeInteger(a.number) && a.number > 0 ? a.number : 0,
                ),
            ),
        );
        const numbers = new Set();
        for (const annotation of result) {
            if (
                !Number.isSafeInteger(annotation.number) ||
                annotation.number < 1 ||
                numbers.has(annotation.number)
            ) {
                annotation.number = this.nextNumber();
                this.write(annotation);
            }
            numbers.add(annotation.number);
        }
        return result;
    }
    saveNumber(number) {
        if (number === this.lastNumber) return;
        writeFileSync(`${this.counterFile}.tmp`, String(number), { mode: 0o600 });
        renameSync(`${this.counterFile}.tmp`, this.counterFile);
        this.lastNumber = number;
    }
    nextNumber() {
        if (this.lastNumber >= Number.MAX_SAFE_INTEGER)
            throw new Error("Annotation sequence exhausted");
        this.saveNumber(this.lastNumber + 1);
        return this.lastNumber;
    }
    find(id) {
        return this.list().find((a) => a.id === id);
    }
    write(annotation) {
        const target = join(this.path, folderFor(annotation.status), `${annotation.id}.json`);
        writeFileSync(`${target}.tmp`, JSON.stringify(annotation, null, 2), { mode: 0o600 });
        renameSync(`${target}.tmp`, target);
    }
    create(body) {
        const records = this.list();
        const existing = records.find((a) => a.id === body.id);
        if (existing) return { annotation: existing, created: false };
        const annotation = {
            ...body,
            number: this.nextNumber(),
            status: "todo",
            createdAt: Date.now(),
            revision: Math.max(0, ...records.map((a) => a.revision)) + 1,
            syncError: null,
        };
        delete annotation.threadId;
        delete annotation.delivery;
        this.write(annotation);
        return { annotation, created: true };
    }
    transition(annotation, status, summary) {
        const updated = {
            ...annotation,
            status,
            summary: summary ?? annotation.summary,
            revision: Math.max(0, ...this.list().map((a) => a.revision)) + 1,
        };
        if (status === "in_progress") updated.claimedAt = Date.now();
        if (status === "done") updated.completedAt = Date.now();
        if (status === "todo") {
            delete updated.claimedAt;
            delete updated.completedAt;
        }
        const source = join(this.path, folderFor(annotation.status), `${annotation.id}.json`);
        const target = join(this.path, folderFor(status), `${annotation.id}.json`);
        // No await between selecting work and committing the move; one server owns this store.
        if (source !== target) renameSync(source, target);
        try {
            this.write(updated);
        } catch (error) {
            if (source !== target) renameSync(target, source);
            throw error;
        }
        return updated;
    }
    claim() {
        const annotation = this.list().find((a) => a.status === "todo");
        return annotation ? this.transition(annotation, "in_progress") : null;
    }
}
