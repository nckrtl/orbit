import { join } from "node:path";
import { ModelRuntime } from "@earendil-works/pi-coding-agent";
import serverPackage from "../package.json" with { type: "json" };
import { readConfig } from "./config.ts";
import { createPiServer } from "./http.ts";
import { SessionRegistry } from "./registry.ts";

// Pi reads its own version from disk, which a compiled binary does not include.
const piVersion = serverPackage.dependencies["@earendil-works/pi-coding-agent"];
const config = readConfig();
const modelRuntime = await ModelRuntime.create({
    authPath: join(config.agentDir, "auth.json"),
    modelsPath: join(config.agentDir, "models.json"),
});
const registry = new SessionRegistry({
    modelRuntime,
    agentDir: config.agentDir,
    sessionDir: config.sessionDir,
    workspaceRoots: config.workspaceRoots,
    allowApiKeys: config.allowApiKeys,
    idleUnloadMs: config.idleUnloadMs,
});
const server = createPiServer({
    registry,
    token: config.token,
    capabilities: async () => ({ piVersion, models: await registry.availableModels() }),
});

const sweep = setInterval(() => registry.unloadIdle(), 60_000);
server.listen(config.port, config.host, () => {
    console.error(`pi-server listening on ${config.host}:${config.port} with Pi ${piVersion}`);
});

// Exit without settling active turns: their records keep `turnActive`, so the next start
// reports them as failed by a restart instead of as interrupted.
const shutdown = () => {
    clearInterval(sweep);
    server.close();
    process.exit(0);
};
process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);
