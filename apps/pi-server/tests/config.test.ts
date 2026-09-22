import { mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vite-plus/test";
import { readConfig } from "../src/config.ts";

const TOKEN = "config-test-token-with-more-than-32-chars";

function tokenFile(): string {
    const path = join(mkdtempSync(join(tmpdir(), "pi-config-")), "token");
    writeFileSync(path, `${TOKEN}\n`);

    return path;
}

describe("readConfig", () => {
    it("reads every setting from flags, as a systemd Process passes them", () => {
        const config = readConfig(
            [
                "--host=10.44.0.9",
                "--port=3800",
                `--token-file=${tokenFile()}`,
                "--agent-dir=/home/orbit/.pi/agent",
                "--session-dir=/home/orbit/.pi/sessions",
                "--workspace-root=/srv/a",
                "--workspace-root=/srv/b",
                "--allow-api-keys",
                "--allow-provider=cliproxyapi",
                "--idle-unload-seconds=60",
            ],
            {},
        );

        expect(config).toEqual({
            host: "10.44.0.9",
            port: 3800,
            token: TOKEN,
            agentDir: "/home/orbit/.pi/agent",
            sessionDir: "/home/orbit/.pi/sessions",
            workspaceRoots: ["/srv/a", "/srv/b"],
            allowApiKeys: true,
            allowedProviders: ["cliproxyapi"],
            idleUnloadMs: 60_000,
        });
    });

    it("falls back to the environment and applies defaults", () => {
        const config = readConfig([], {
            PI_SERVER_HOST: "127.0.0.1",
            PI_SERVER_TOKEN: TOKEN,
            PI_SERVER_AGENT_DIR: "/agent",
        });

        expect(config).toMatchObject({
            port: 3774,
            sessionDir: "/agent/orbit-sessions",
            workspaceRoots: [],
            allowApiKeys: false,
            allowedProviders: [],
            idleUnloadMs: 900_000,
        });
    });

    it("prefers a flag over the environment", () => {
        expect(
            readConfig(["--host=10.0.0.2"], { PI_SERVER_HOST: "10.0.0.1", PI_SERVER_TOKEN: TOKEN })
                .host,
        ).toBe("10.0.0.2");
    });

    it.each([
        [[], { PI_SERVER_TOKEN: TOKEN }, "--host or PI_SERVER_HOST is required."],
        [["--host=127.0.0.1"], {}, "is required."],
        [["--host=127.0.0.1"], { PI_SERVER_TOKEN: "short" }, "at least 32 characters"],
        [["--host=127.0.0.1", "--token=inline"], { PI_SERVER_TOKEN: TOKEN }, "--token"],
    ])("refuses %j", (argv, env, message) => {
        expect(() => readConfig(argv, env)).toThrow(message);
    });
});
