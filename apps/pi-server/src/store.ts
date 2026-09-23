import { mkdirSync, readdirSync, readFileSync, renameSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/;

export function isValidSessionId(id: string): boolean {
    return ID_PATTERN.test(id);
}

/** What the Gateway asked for when it created the session. Needed to reopen it after a restart. */
export interface SessionConfig {
    cwd: string;
    model: string;
    thinkingLevel: string;
    appendSystemPrompt: string | null;
}

/** Orbit's own record beside Pi's JSONL session file. */
export interface SessionRecord {
    config: SessionConfig;
    /** Send keys already accepted, so a repeated send never starts a second turn. */
    acceptedKeys: string[];
    /** Set when a turn starts and cleared when it settles. Still set on load means a restart cut the turn off. */
    turnActive: boolean;
}

/**
 * Stores Orbit records as `<id>.orbit.json` in the session directory. Pi writes its own
 * `<timestamp>_<id>.jsonl` files there. Writes replace the file atomically.
 */
export class SessionStore {
    readonly directory: string;

    constructor(directory: string) {
        this.directory = directory;
        mkdirSync(directory, { recursive: true });
    }

    read(id: string): SessionRecord | undefined {
        try {
            return JSON.parse(readFileSync(this.recordPath(id), "utf8")) as SessionRecord;
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code === "ENOENT") {
                return undefined;
            }
            throw error;
        }
    }

    write(id: string, record: SessionRecord): void {
        const path = this.recordPath(id);
        const temporary = `${path}.${process.pid}.tmp`;
        writeFileSync(temporary, JSON.stringify(record));
        renameSync(temporary, path);
    }

    /** Finds Pi's session file for an ID. Pi prefixes the file name with its creation time. */
    sessionFile(id: string): string | undefined {
        const suffix = `_${id}.jsonl`;
        const match = readdirSync(this.directory).find((name) => name.endsWith(suffix));

        return match === undefined ? undefined : join(this.directory, match);
    }

    private recordPath(id: string): string {
        return join(this.directory, `${id}.orbit.json`);
    }
}
