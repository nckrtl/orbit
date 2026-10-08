import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import path from "node:path";
import type { Plugin } from "vite-plus";

const COMMIT = /^[0-9a-f]{40}$/;
const OVERRIDE = /^[A-Za-z0-9._-]{1,64}$/;

/** The inputs of a build, hashed when no commit names it. Paths are relative to `apps/web`. */
const SOURCES = [
    "index.html",
    "package.json",
    "bun.lock",
    "vite.config.ts",
    "tsconfig.json",
    "dev",
    "src",
    "public",
    "../../packages/agent-annotation/src",
];

type BuildIdInputs = {
    env: Record<string, string | undefined>;
    /** The checkout's `HEAD` commit, or null outside a Git checkout. */
    commit: () => string | null;
    /** A hash of the build's source files. */
    contentHash: () => string;
};

/**
 * Names a web build. CI and `bin/web-deploy` build one exact commit, so the build is that commit.
 * `ORBIT_WEB_BUILD` overrides it, for a test that needs two builds of one checkout. Without Git,
 * the build is a hash of its source files, so two builds of the same sources share an id.
 */
export function resolveBuildId({ env, commit, contentHash }: BuildIdInputs): string {
    const override = env.ORBIT_WEB_BUILD;
    if (override !== undefined && OVERRIDE.test(override)) return override;

    const head = commit();
    if (head !== null && COMMIT.test(head)) return head;

    const ci = env.GITHUB_SHA;
    if (ci !== undefined && COMMIT.test(ci)) return ci;

    return `content-${contentHash()}`;
}

function gitHead(root: string): string | null {
    const result = spawnSync("git", ["rev-parse", "HEAD"], { cwd: root, encoding: "utf8" });

    return result.status === 0 ? result.stdout.trim() : null;
}

function hashSources(root: string): string {
    const hash = createHash("sha256");
    const add = (relative: string) => {
        const absolute = path.join(root, relative);
        if (!existsSync(absolute)) return;
        if (statSync(absolute).isDirectory()) {
            for (const entry of readdirSync(absolute).sort()) add(path.join(relative, entry));

            return;
        }
        hash.update(relative).update("\0").update(readFileSync(absolute)).update("\0");
    };
    for (const source of SOURCES) add(source);

    return hash.digest("hex").slice(0, 16);
}

/**
 * Embeds the build id as `__ORBIT_BUILD__` and writes `version.json` (`{"build": "<id>"}`) at the
 * top of the build. An open page compares the two to find a newer release ([Web app](/reference/web-app#updates-to-open-pages)).
 */
export function orbitBuild(root: string): Plugin {
    let build: string | undefined;
    const resolve = () =>
        (build ??= resolveBuildId({
            env: process.env,
            commit: () => gitHead(root),
            contentHash: () => hashSources(root),
        }));

    return {
        name: "orbit-build",
        config: () => ({ define: { __ORBIT_BUILD__: JSON.stringify(resolve()) } }),
        generateBundle() {
            this.emitFile({
                type: "asset",
                fileName: "version.json",
                source: `${JSON.stringify({ build: resolve() })}\n`,
            });
        },
    };
}
