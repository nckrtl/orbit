import { existsSync, realpathSync, statSync } from "node:fs";
import { sep } from "node:path";
import type { ThinkingLevel } from "@earendil-works/pi-agent-core";
import {
    type AgentSession,
    createAgentSession,
    DefaultResourceLoader,
    type ModelRuntime,
    type SessionEntry,
    SessionManager,
    SettingsManager,
} from "@earendil-works/pi-coding-agent";
import { deriveState, type DerivedState } from "./state.ts";
import { isValidSessionId, type SessionConfig, type SessionRecord, SessionStore } from "./store.ts";

const THINKING_LEVELS: readonly string[] = [
    "off",
    "minimal",
    "low",
    "medium",
    "high",
    "xhigh",
    "max",
];
const STREAMED_EVENTS = new Set(["message_end", "turn_end", "agent_end", "agent_settled"]);

/** A request the server refuses. `status` and `code` become the HTTP error response. */
export class PiServerError extends Error {
    readonly status: number;
    readonly code: string;

    constructor(status: number, code: string, message: string) {
        super(message);
        this.status = status;
        this.code = code;
    }
}

export interface RegistryOptions {
    modelRuntime: ModelRuntime;
    agentDir: string;
    sessionDir: string;
    /** When not empty, a session's working directory must be inside one of these directories. */
    workspaceRoots: string[];
    /** Allow providers authenticated with an API key. Off by default: only subscription logins run. */
    allowApiKeys: boolean;
    /**
     * Providers allowed although Pi sees an API key, such as a CLIProxyAPI endpoint in
     * `models.json` that serves pooled subscription accounts.
     */
    allowedProviders: string[];
    /** Unload a session with no turn and no stream after this many milliseconds. */
    idleUnloadMs: number;
}

export interface CreateRequest {
    id: string;
    cwd: string;
    model: string;
    thinkingLevel: string;
    appendSystemPrompt?: string | null;
}

export interface TranscriptEntry {
    id: string;
    timestamp: string;
    message: unknown;
}

export interface Usage {
    input: number;
    output: number;
    cacheRead: number;
    cacheWrite: number;
    total: number;
}

export interface Snapshot {
    kind: "snapshot";
    sequence: number;
    session: { id: string; cwd: string; model: string; thinkingLevel: string };
    state: DerivedState["state"];
    error: string | null;
    /** The key of the latest accepted send. Each send starts one turn, so it identifies that turn. */
    turnId: string | null;
    entries: TranscriptEntry[];
    usage: Usage;
}

export type StreamEvent =
    | Snapshot
    | { kind: "entry"; sequence: number; entry: TranscriptEntry }
    | {
          kind: "state";
          sequence: number;
          state: DerivedState["state"];
          error: string | null;
          turnId: string | null;
          usage: Usage;
      };

export type StreamListener = (event: StreamEvent) => void;

interface LiveSession {
    id: string;
    record: SessionRecord;
    session: AgentSession;
    working: boolean;
    interruptedByRestart: boolean;
    sequence: number;
    emittedEntryIds: Set<string>;
    lastState: string;
    listeners: Set<StreamListener>;
    lastUsedAt: number;
}

export class SessionRegistry {
    private readonly store: SessionStore;
    private readonly live = new Map<string, LiveSession>();
    private readonly loading = new Map<string, Promise<LiveSession>>();
    private readonly settings = SettingsManager.inMemory({ defaultProjectTrust: "always" });
    private readonly options: RegistryOptions;

    constructor(options: RegistryOptions) {
        this.options = options;
        this.store = new SessionStore(options.sessionDir);
    }

    /** Creates a session. Repeating a create with the same settings returns the existing session. */
    async create(request: CreateRequest): Promise<{ id: string; created: boolean }> {
        const config = await this.validateCreate(request);
        const existing = this.store.read(request.id);
        if (existing !== undefined) {
            if (!sameConfig(existing.config, config)) {
                throw new PiServerError(
                    409,
                    "session_exists",
                    `Session ${request.id} exists with different settings.`,
                );
            }

            return { id: request.id, created: false };
        }

        this.store.write(request.id, { config, acceptedKeys: [], turnActive: false });
        await this.load(request.id);

        return { id: request.id, created: true };
    }

    /** Starts a turn. A key that was already accepted returns without starting another turn. */
    async send(id: string, key: string, text: string): Promise<{ duplicate: boolean }> {
        if (key === "" || text === "") {
            throw new PiServerError(422, "invalid_request", "A send needs a key and text.");
        }
        const live = await this.load(id);
        if (live.record.acceptedKeys.includes(key)) {
            return { duplicate: true };
        }
        if (live.working) {
            throw new PiServerError(
                409,
                "turn_active",
                `Session ${id} is already working on a turn.`,
            );
        }

        live.record = {
            ...live.record,
            acceptedKeys: [...live.record.acceptedKeys, key],
            turnActive: true,
        };
        this.store.write(id, live.record);
        live.working = true;
        live.interruptedByRestart = false;
        this.publishState(live);

        void live.session
            .prompt(text)
            .catch(() => undefined)
            .finally(() => {
                live.working = false;
                live.record = { ...live.record, turnActive: false };
                this.store.write(id, live.record);
                live.lastUsedAt = Date.now();
                this.publishEntries(live);
                this.publishState(live);
            });

        return { duplicate: false };
    }

    async interrupt(id: string): Promise<void> {
        const live = await this.load(id);
        if (live.working) {
            await live.session.abort();
        }
    }

    async snapshot(id: string): Promise<Snapshot> {
        return this.buildSnapshot(await this.load(id));
    }

    /** Subscribes to a session. The listener receives a full snapshot first, then changes. */
    async subscribe(id: string, listener: StreamListener): Promise<() => void> {
        const live = await this.load(id);
        live.listeners.add(listener);
        listener(this.buildSnapshot(live));

        return () => {
            live.listeners.delete(listener);
            live.lastUsedAt = Date.now();
        };
    }

    /** Unloads sessions that have no turn and no stream and have not been used recently. */
    unloadIdle(now = Date.now()): void {
        for (const live of this.live.values()) {
            if (
                !live.working &&
                live.listeners.size === 0 &&
                now - live.lastUsedAt >= this.options.idleUnloadMs
            ) {
                live.session.dispose();
                this.live.delete(live.id);
            }
        }
    }

    /**
     * Lists the models a session can use, as `provider/model`. Unless API keys are allowed, a
     * provider counts only when it is signed in with a subscription or named in
     * `allowedProviders`, so a stray API key in the environment never enables per-token billing.
     */
    async availableModels(): Promise<string[]> {
        const runtime = this.options.modelRuntime;

        return (await runtime.getAvailable())
            .filter(
                (model) =>
                    this.options.allowApiKeys ||
                    this.options.allowedProviders.includes(model.provider) ||
                    runtime.isUsingSubscription(model.provider),
            )
            .map((model) => `${model.provider}/${model.id}`);
    }

    dispose(): void {
        for (const live of this.live.values()) {
            live.session.dispose();
        }
        this.live.clear();
    }

    private async load(id: string): Promise<LiveSession> {
        if (!isValidSessionId(id)) {
            throw new PiServerError(404, "session_not_found", `Session ${id} does not exist.`);
        }
        const live = this.live.get(id);
        if (live !== undefined) {
            live.lastUsedAt = Date.now();
            return live;
        }
        const pending =
            this.loading.get(id) ?? this.open(id).finally(() => this.loading.delete(id));
        this.loading.set(id, pending);

        return pending;
    }

    private async open(id: string): Promise<LiveSession> {
        const record = this.store.read(id);
        if (record === undefined) {
            throw new PiServerError(404, "session_not_found", `Session ${id} does not exist.`);
        }
        const { cwd, model: modelKey, thinkingLevel, appendSystemPrompt } = record.config;
        const model = this.resolveModel(modelKey);
        const file = this.store.sessionFile(id);
        const sessionManager =
            file === undefined
                ? SessionManager.create(cwd, this.options.sessionDir, { id })
                : SessionManager.open(file, this.options.sessionDir);
        const resourceLoader = new DefaultResourceLoader({
            cwd,
            agentDir: this.options.agentDir,
            settingsManager: this.settings,
            appendSystemPrompt: appendSystemPrompt === null ? [] : [appendSystemPrompt],
        });
        await resourceLoader.reload();

        const { session } = await createAgentSession({
            cwd,
            agentDir: this.options.agentDir,
            modelRuntime: this.options.modelRuntime,
            model,
            thinkingLevel: thinkingLevel as ThinkingLevel,
            settingsManager: this.settings,
            resourceLoader,
            sessionManager,
        });

        const live: LiveSession = {
            id,
            record,
            session,
            working: false,
            interruptedByRestart: record.turnActive,
            sequence: 0,
            emittedEntryIds: new Set(transcript(session).map((entry) => entry.id)),
            lastState: "",
            listeners: new Set(),
            lastUsedAt: Date.now(),
        };
        live.lastState = stateKey(this.derive(live), usage(session));
        session.subscribe((event) => {
            if (STREAMED_EVENTS.has(event.type)) {
                // Pi persists the finished message in its own listener; read entries after it runs.
                setImmediate(() => {
                    this.publishEntries(live);
                    this.publishState(live);
                });
            }
        });
        this.live.set(id, live);

        return live;
    }

    private publishEntries(live: LiveSession): void {
        for (const entry of transcript(live.session)) {
            if (!live.emittedEntryIds.has(entry.id)) {
                live.emittedEntryIds.add(entry.id);
                this.emit(live, { kind: "entry", sequence: ++live.sequence, entry });
            }
        }
    }

    private publishState(live: LiveSession): void {
        const derived = this.derive(live);
        const tokens = usage(live.session);
        const key = stateKey(derived, tokens);
        if (key === live.lastState) {
            return;
        }
        live.lastState = key;
        this.emit(live, {
            kind: "state",
            sequence: ++live.sequence,
            ...derived,
            turnId: latestTurn(live),
            usage: tokens,
        });
    }

    private emit(live: LiveSession, event: StreamEvent): void {
        for (const listener of live.listeners) {
            listener(event);
        }
    }

    private buildSnapshot(live: LiveSession): Snapshot {
        const { cwd, model, thinkingLevel } = live.record.config;

        return {
            kind: "snapshot",
            sequence: live.sequence,
            session: { id: live.id, cwd, model, thinkingLevel },
            ...this.derive(live),
            turnId: latestTurn(live),
            entries: transcript(live.session),
            usage: usage(live.session),
        };
    }

    private derive(live: LiveSession): DerivedState {
        const last = [...live.session.messages]
            .reverse()
            .find((message) => message.role === "assistant");

        return deriveState({
            working: live.working,
            interruptedByRestart: live.interruptedByRestart,
            lastAssistant:
                last === undefined || last.role !== "assistant"
                    ? undefined
                    : { stopReason: last.stopReason, errorMessage: last.errorMessage },
        });
    }

    private async validateCreate(request: CreateRequest): Promise<SessionConfig> {
        if (!isValidSessionId(request.id)) {
            throw new PiServerError(
                422,
                "invalid_request",
                "The session ID must be 1 to 128 letters, digits, hyphens, or underscores.",
            );
        }
        if (!THINKING_LEVELS.includes(request.thinkingLevel)) {
            throw new PiServerError(
                422,
                "invalid_request",
                `Unknown thinking level ${request.thinkingLevel}.`,
            );
        }
        const cwd = this.resolveWorkspace(request.cwd);
        const model = this.resolveModel(request.model);
        const available = await this.availableModels();
        if (!available.includes(`${model.provider}/${model.id}`)) {
            throw new PiServerError(
                422,
                "model_unavailable",
                this.options.allowApiKeys
                    ? `Provider ${model.provider} is not signed in on this Node, or it does not offer ${model.id}.`
                    : `Provider ${model.provider} is not signed in with a subscription or allowed with --allow-provider on this Node, or it does not offer ${model.id}.`,
            );
        }

        return {
            cwd,
            model: request.model,
            thinkingLevel: request.thinkingLevel,
            appendSystemPrompt: request.appendSystemPrompt ?? null,
        };
    }

    private resolveWorkspace(cwd: string): string {
        if (!cwd.startsWith("/") || !existsSync(cwd) || !statSync(cwd).isDirectory()) {
            throw new PiServerError(
                422,
                "invalid_request",
                `The workspace ${cwd} is not an existing absolute directory.`,
            );
        }
        const real = realpathSync(cwd);
        const roots = this.options.workspaceRoots.map((root) => realpathSync(root));
        if (
            roots.length > 0 &&
            !roots.some((root) => real === root || real.startsWith(root + sep))
        ) {
            throw new PiServerError(
                422,
                "invalid_request",
                `The workspace ${cwd} is outside the allowed roots.`,
            );
        }

        return real;
    }

    private resolveModel(key: string): NonNullable<ReturnType<ModelRuntime["getModel"]>> {
        const slash = key.indexOf("/");
        const model =
            slash > 0
                ? this.options.modelRuntime.getModel(key.slice(0, slash), key.slice(slash + 1))
                : undefined;
        if (model === undefined) {
            throw new PiServerError(
                422,
                "model_unavailable",
                `Unknown model ${key}. Use provider/model.`,
            );
        }

        return model;
    }
}

function transcript(session: AgentSession): TranscriptEntry[] {
    return session.sessionManager
        .getEntries()
        .filter(
            (entry): entry is Extract<SessionEntry, { type: "message" }> =>
                entry.type === "message",
        )
        .filter((entry) => entry.message.role !== "system")
        .map((entry) => ({ id: entry.id, timestamp: entry.timestamp, message: entry.message }));
}

function latestTurn(live: LiveSession): string | null {
    return live.record.acceptedKeys.at(-1) ?? null;
}

function usage(session: AgentSession): Usage {
    return { ...session.getSessionStats().tokens };
}

function stateKey(derived: DerivedState, tokens: Usage): string {
    return JSON.stringify([derived.state, derived.error, tokens.total]);
}

function sameConfig(a: SessionConfig, b: SessionConfig): boolean {
    return (
        a.cwd === b.cwd &&
        a.model === b.model &&
        a.thinkingLevel === b.thinkingLevel &&
        a.appendSystemPrompt === b.appendSystemPrompt
    );
}
