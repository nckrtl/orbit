import assert from "node:assert/strict";
import { execFile } from "node:child_process";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { promisify } from "node:util";

const run = promisify(execFile);
const dir = await mkdtemp(join(tmpdir(), "annotate-lifecycle-"));
const state = join(dir, "annotator.json");
const cli = async (...args) => {
    try {
        const { stdout } = await run(process.execPath, ["bin/serve.mjs", ...args]);
        return JSON.parse(stdout);
    } catch (error) {
        return { exitCode: error.code, ...JSON.parse(error.stdout) };
    }
};
const alive = (pid) => {
    try {
        process.kill(pid, 0);
        return true;
    } catch {
        return false;
    }
};
let pid;
try {
    assert.deepEqual(await cli("status", "--state", state), { running: false });
    assert.match((await cli("start")).error, /requires --state/);

    const started = await cli("start", "--state", state, "--store", join(dir, "store"));
    assert.equal(started.running, true);
    pid = started.pid;
    assert.ok(alive(pid), "The server outlives the start command");
    assert.equal(JSON.parse(await readFile(state, "utf8")).pid, pid);
    assert.equal((await (await fetch(started.url)).json()).meta.service, "@nckrtl/annotator");
    assert.equal(started.skillUrl, new URL("/skill", started.url).href);

    const again = await cli("start", "--state", state);
    assert.equal(again.pid, pid, "Start reuses a running server");
    assert.equal((await cli("status", "--state", state)).port, started.port);

    assert.deepEqual(await cli("stop", "--state", state), { running: false, stopped: true });
    assert.equal(alive(pid), false);
    await assert.rejects(readFile(state), "Stop removes the state file");
    assert.deepEqual(await cli("stop", "--state", state), { running: false, stopped: false });

    // A state file left by a crashed server is cleaned up.
    await writeFile(
        state,
        JSON.stringify({ pid: 2147483646, url: "http://127.0.0.1:1/annotations" }),
    );
    assert.deepEqual(await cli("status", "--state", state), { running: false });
    await assert.rejects(readFile(state));

    // Stop never signals a live process that is not the annotation server.
    await writeFile(
        state,
        JSON.stringify({ pid: process.pid, url: "http://127.0.0.1:1/annotations" }),
    );
    const refused = await cli("stop", "--state", state);
    assert.equal(refused.exitCode, 1);
    assert.match(refused.error, /does not respond as an annotation server/);
    assert.ok(alive(process.pid));

    console.log(
        "Lifecycle: start, reuse, status, stop, stale state cleanup, and safe stop passed.",
    );
} finally {
    if (pid && alive(pid)) process.kill(pid, "SIGTERM");
    await rm(dir, { recursive: true, force: true });
}
