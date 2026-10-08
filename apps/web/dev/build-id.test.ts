import { afterEach, describe, expect, it, vi } from "vite-plus/test";
import { orbitBuild, resolveBuildId } from "./build-id";

const commit = "0123456789abcdef0123456789abcdef01234567";
const other = "fedcba9876543210fedcba9876543210fedcba98";

describe("resolveBuildId", () => {
    it("names the build after the checkout's commit", () => {
        expect(
            resolveBuildId({
                env: { GITHUB_SHA: other },
                commit: () => commit,
                contentHash: () => "hash",
            }),
        ).toBe(commit);
    });

    it("uses CI's commit without a Git checkout", () => {
        expect(
            resolveBuildId({
                env: { GITHUB_SHA: other },
                commit: () => null,
                contentHash: () => "hash",
            }),
        ).toBe(other);
    });

    it("falls back to a hash of the sources", () => {
        expect(
            resolveBuildId({ env: {}, commit: () => "not a commit", contentHash: () => "abc123" }),
        ).toBe("content-abc123");
    });

    it("takes a well-formed override first", () => {
        expect(
            resolveBuildId({
                env: { ORBIT_WEB_BUILD: "verify-2" },
                commit: () => commit,
                contentHash: () => "hash",
            }),
        ).toBe("verify-2");
        expect(
            resolveBuildId({
                env: { ORBIT_WEB_BUILD: '"><script>' },
                commit: () => commit,
                contentHash: () => "hash",
            }),
        ).toBe(commit);
    });
});

describe("orbitBuild", () => {
    afterEach(() => vi.unstubAllEnvs());

    it("embeds the build id and writes version.json at the top of the build", () => {
        vi.stubEnv("ORBIT_WEB_BUILD", "verify-1");
        const plugin = orbitBuild(process.cwd());
        const emitted: unknown[] = [];

        const config = (plugin.config as () => { define: Record<string, string> })();
        (plugin.generateBundle as (this: unknown) => void).call({
            emitFile: (file: unknown) => emitted.push(file),
        });

        expect(config.define.__ORBIT_BUILD__).toBe('"verify-1"');
        expect(emitted).toEqual([
            { type: "asset", fileName: "version.json", source: '{"build":"verify-1"}\n' },
        ]);
    });
});
