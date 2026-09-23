/**
 * Serves the Pi server with a scripted model, for exercising a Gateway driver locally without a
 * provider login. Each turn runs one real bash command in the workspace, then answers.
 *
 *   PI_SERVER_TOKEN=... bun dev/serve-faux.ts <workspace-root> [port]
 */
import { mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { fauxAssistantMessage, fauxProvider, fauxToolCall } from "@earendil-works/pi-ai";
import { ModelRuntime } from "@earendil-works/pi-coding-agent";
import { createPiServer } from "../src/http.ts";
import { SessionRegistry } from "../src/registry.ts";

const [workspaceRoot, port = "3790"] = process.argv.slice(2);
const token = process.env.PI_SERVER_TOKEN;
if (workspaceRoot === undefined || token === undefined) {
    throw new Error("Usage: PI_SERVER_TOKEN=... bun dev/serve-faux.ts <workspace-root> [port]");
}

const root = mkdtempSync(join(tmpdir(), "pi-faux-"));
const faux = fauxProvider({ provider: "faux", models: [{ id: "scripted" }], tokensPerSecond: 200 });
const modelRuntime = await ModelRuntime.create({
    authPath: join(root, "auth.json"),
    modelsPath: null,
    refreshOnCreate: false,
});
modelRuntime.registerNativeProvider(faux.provider);
await modelRuntime.setRuntimeApiKey("faux", "local");

// Answer every call in a turn: first run a check-shaped command, then finish.
faux.setResponses(
    Array.from({ length: 50 }, (_, index) =>
        index % 2 === 0
            ? fauxAssistantMessage(
                  [fauxToolCall("bash", { command: "echo 'composer check' && true" })],
                  {
                      stopReason: "toolUse",
                  },
              )
            : fauxAssistantMessage("Checks ran."),
    ),
);

const registry = new SessionRegistry({
    modelRuntime,
    agentDir: join(root, "agent"),
    sessionDir: join(root, "sessions"),
    workspaceRoots: [workspaceRoot],
    allowApiKeys: true,
    allowedProviders: [],
    idleUnloadMs: 60_000,
});
createPiServer({
    registry,
    token,
    heartbeatMs: 2_000,
    capabilities: async () => ({ piVersion: "faux", models: await registry.availableModels() }),
}).listen(Number(port), "127.0.0.1", () => console.error(`faux pi-server on 127.0.0.1:${port}`));
