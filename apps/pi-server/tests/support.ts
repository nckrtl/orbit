import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import type { AddressInfo } from "node:net";
import type { Server } from "node:http";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fauxProvider, type FauxProviderHandle } from "@earendil-works/pi-ai";
import { ModelRuntime } from "@earendil-works/pi-coding-agent";
import { createPiServer } from "../src/http.ts";
import { SessionRegistry } from "../src/registry.ts";

export const TOKEN = "test-token-that-is-at-least-32-characters";

export interface Harness {
    root: string;
    workspace: string;
    faux: FauxProviderHandle;
    registry: SessionRegistry;
    url: string;
    request: (
        method: string,
        path: string,
        body?: unknown,
        token?: string,
    ) => Promise<{ status: number; body: any }>;
    /** Opens a stream and collects its events until `until` returns true. */
    stream: (id: string, until: (events: any[]) => boolean) => Promise<any[]>;
    /** Starts a new registry and server over the same files, as after a restart. */
    restart: () => Promise<Harness>;
    close: () => Promise<void>;
}

export async function startHarness(
    options: {
        tokensPerSecond?: number;
        root?: string;
        allowApiKeys?: boolean;
        allowedProviders?: string[];
    } = {},
): Promise<Harness> {
    const root = options.root ?? mkdtempSync(join(tmpdir(), "pi-server-test-"));
    const workspace = join(root, "workspace");
    mkdirSync(join(workspace, ".agents", "skills", "orbit-probe"), { recursive: true });
    writeFileSync(
        join(workspace, ".agents", "skills", "orbit-probe", "SKILL.md"),
        "---\nname: orbit-probe\ndescription: Probe skill discovered from the workspace.\n---\n\nProbe.\n",
    );
    writeFileSync(join(workspace, "AGENTS.md"), "Workspace instruction marker.\n");

    const faux = fauxProvider({
        provider: "faux",
        models: [{ id: "model-a" }],
        ...(options.tokensPerSecond === undefined
            ? {}
            : { tokensPerSecond: options.tokensPerSecond }),
    });
    const modelRuntime = await ModelRuntime.create({
        authPath: join(root, "agent", "auth.json"),
        modelsPath: null,
        refreshOnCreate: false,
    });
    modelRuntime.registerNativeProvider(faux.provider);
    await modelRuntime.setRuntimeApiKey("faux", "test-key");

    const registry = new SessionRegistry({
        modelRuntime,
        agentDir: join(root, "agent"),
        sessionDir: join(root, "sessions"),
        workspaceRoots: [root],
        allowApiKeys: options.allowApiKeys ?? true,
        allowedProviders: options.allowedProviders ?? [],
        idleUnloadMs: 60_000,
    });
    const server: Server = createPiServer({
        registry,
        token: TOKEN,
        heartbeatMs: 50,
        capabilities: async () => ({ piVersion: "test", models: await registry.availableModels() }),
    });
    await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
    const url = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;

    const request = async (method: string, path: string, body?: unknown, token = TOKEN) => {
        const response = await fetch(url + path, {
            method,
            headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });

        return { status: response.status, body: await response.json() };
    };

    const stream = async (id: string, until: (events: any[]) => boolean) => {
        const controller = new AbortController();
        const response = await fetch(`${url}/sessions/${id}/stream`, {
            headers: { Authorization: `Bearer ${TOKEN}` },
            signal: controller.signal,
        });
        const reader = response.body!.getReader();
        const decoder = new TextDecoder();
        const events: any[] = [];
        let buffer = "";
        while (!until(events)) {
            const { value, done } = await reader.read();
            if (done) {
                break;
            }
            buffer += decoder.decode(value, { stream: true });
            let newline = buffer.indexOf("\n");
            while (newline >= 0) {
                const event = JSON.parse(buffer.slice(0, newline));
                if (event.kind !== "heartbeat") {
                    events.push(event);
                }
                buffer = buffer.slice(newline + 1);
                newline = buffer.indexOf("\n");
            }
        }
        controller.abort();

        return events;
    };

    const close = async () => {
        server.closeAllConnections();
        await new Promise<void>((resolve) => server.close(() => resolve()));
        registry.dispose();
    };

    return {
        root,
        workspace,
        faux,
        registry,
        url,
        request,
        stream,
        restart: async () => {
            await close();
            return startHarness({ ...options, root });
        },
        close: async () => {
            await close();
            rmSync(root, { recursive: true, force: true });
        },
    };
}

export async function waitFor<T>(
    read: () => Promise<T>,
    done: (value: T) => boolean,
    timeoutMs = 10_000,
): Promise<T> {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        const value = await read();
        if (done(value) || Date.now() > deadline) {
            return value;
        }
        await new Promise((resolve) => setTimeout(resolve, 20));
    }
}
