import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import { mkdtemp, readFile, rm, readdir, writeFile, rename } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { once } from "node:events";
const dir = await mkdtemp(join(tmpdir(), "annotate-test-"));
const children = [];
async function start(extra = []) {
    const child = spawn(process.execPath, ["bin/serve.mjs", "serve", ...extra], {
        stdio: ["ignore", "pipe", "pipe"],
    });
    children.push(child);
    let output = "";
    const url = await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error("Startup timed out")), 5000);
        child.stdout.on("data", (data) => {
            output += data;
            const match = output.match(/Annotation server URL: (\S+)/);
            if (match) {
                clearTimeout(timer);
                resolve(match[1]);
            }
        });
        child.once("exit", (code) => {
            clearTimeout(timer);
            reject(new Error(`Server exited ${code}`));
        });
    });
    return { child, url, output: () => output };
}
async function waitForLog(server, line) {
    const deadline = Date.now() + 5000;
    while (!server.output().split("\n").includes(line)) {
        assert.ok(Date.now() < deadline, `Missing activity: ${line}\n${server.output()}`);
        await new Promise((resolve) => setTimeout(resolve, 10));
    }
}
const post = (url, body) =>
    fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
    });
try {
    const file = join(dir, "store");
    const first = await start(["--host", "127.0.0.1", "--store", file]);
    const second = await start(["--store", join(dir, "second")]);
    assert.notEqual(new URL(first.url).port, new URL(second.url).port);
    assert.equal(new URL(first.url).pathname, "/annotations");
    assert.equal((await fetch(new URL("/unknown", first.url))).status, 404);
    const preflight = await fetch(first.url, {
        method: "OPTIONS",
        headers: { Origin: "https://preview.orbit", "Access-Control-Request-Method": "POST" },
    });
    assert.equal(preflight.status, 204);
    assert.equal(preflight.headers.get("access-control-allow-origin"), "*");
    assert.equal((await post(first.url, {})).status, 422);
    assert.equal((await fetch(first.url, { method: "POST", body: "{" })).status, 400);
    const created = await post(first.url, { id: "one", comment: "Change button", pathname: "/" });
    assert.equal(created.status, 201);
    assert.equal((await created.json()).data.number, 1);
    await waitForLog(first, "#1 created: Change button");
    const streamAbort = new AbortController();
    const stream = await fetch(`${first.url}/events`, { signal: streamAbort.signal });
    const reader = stream.body.getReader();
    await reader.read();
    assert.equal((await post(`${first.url}/one/status`, { status: "in_progress" })).status, 200);
    const event = await reader.read();
    assert.match(new TextDecoder().decode(event.value), /data:/);
    streamAbort.abort();
    const duplicate = await (await post(first.url, { id: "one", comment: "duplicate" })).json();
    assert.equal(duplicate.data.status, "in_progress");
    assert.equal(duplicate.data.number, 1);
    await waitForLog(first, "#1 in progress: Change button");
    assert.equal((await post(`${first.url}/one/status`, { status: "wrong" })).status, 422);
    assert.equal((await post(`${first.url}/missing/status`, { status: "resolved" })).status, 404);
    await post(`${first.url}/one/status`, { status: "resolved", summary: "Done" });
    assert.equal(
        JSON.parse(await readFile(join(file, "done", "one.json"), "utf8")).summary,
        "Done",
    );
    await waitForLog(first, "#1 done: Change button");
    await post(`${first.url}/one/status`, { status: "resolved" });
    assert.deepEqual(
        first
            .output()
            .split("\n")
            .filter((line) => line.startsWith("#")),
        ["#1 created: Change button", "#1 in progress: Change button", "#1 done: Change button"],
    );
    assert.equal((await (await fetch(second.url)).json()).data.length, 0);
    const exited = once(first.child, "exit");
    first.child.kill("SIGTERM");
    await exited;
    const resumed = await start(["--host", "127.0.0.1", "--store", file]);
    assert.equal((await (await fetch(resumed.url)).json()).data[0].status, "done");
    const root = new URL("/", resumed.url);
    const claim = () => fetch(new URL("claim", root), { method: "POST" });
    assert.equal((await claim()).status, 204);
    await post(resumed.url, { id: "task-0", comment: "First" });
    assert.equal((await post(new URL("complete", root), { id: "task-0" })).status, 409);
    for (let n = 1; n < 6; n++) await post(resumed.url, { id: `task-${n}`, comment: `Task ${n}` });
    const claims = await Promise.all(Array.from({ length: 20 }, claim));
    const claimed = await Promise.all(claims.filter((r) => r.status === 200).map((r) => r.json()));
    assert.equal(claimed.length, 6);
    assert.equal(
        new Set(claimed.map((r) => r.data.id)).size,
        6,
        "Concurrent monitors receive distinct annotations",
    );
    assert.equal(claims.filter((r) => r.status === 204).length, 14);
    assert.equal((await readdir(join(file, "todo"))).length, 0);
    assert.equal(
        (await readdir(join(file, "in-progress"))).filter((f) => f.endsWith(".json")).length,
        6,
    );
    assert.equal(
        JSON.parse(await readFile(join(file, "in-progress", "task-0.json"), "utf8")).status,
        "in_progress",
    );
    const completed = await (
        await post(new URL("complete", root), { id: "task-0", summary: "Implemented" })
    ).json();
    assert.equal(completed.data.status, "done");
    assert.equal(completed.data.number, 2);
    await waitForLog(resumed, "#2 created: First");
    await waitForLog(resumed, "#2 in progress: First");
    await waitForLog(resumed, "#2 done: First");
    assert.equal(resumed.output().includes("#1"), false, "Restart does not replay history");
    const duplicateComplete = await (
        await post(new URL("complete", root), { id: "task-0" })
    ).json();
    assert.equal(duplicateComplete.data.revision, completed.data.revision);
    assert.equal(
        JSON.parse(await readFile(join(file, "done", "task-0.json"), "utf8")).summary,
        "Implemented",
    );
    assert.equal((await post(new URL("release", root), { id: "task-0" })).status, 409);
    const released = await (await post(new URL("release", root), { id: "task-1" })).json();
    assert.equal(released.data.status, "todo");
    assert.equal(released.data.number, 3);
    await waitForLog(resumed, "#3 todo: Task 1");
    assert.equal(
        JSON.parse(await readFile(join(file, "todo", "task-1.json"), "utf8")).status,
        "todo",
    );
    assert.equal((await (await post(`${resumed.url}/claim`, {})).json()).data.id, "task-1");
    assert.equal((await post(new URL("complete", root), { id: "missing" })).status, 404);
    assert.equal((await post(resumed.url, { id: "../escape", comment: "Invalid" })).status, 422);
    assert.equal((await post(`${resumed.url}/task-2/status`, { status: "__proto__" })).status, 422);
    const competing = spawn(process.execPath, ["bin/serve.mjs", "serve", "--store", file]);
    assert.equal(
        (await once(competing, "exit"))[0],
        1,
        "A second server cannot claim from the same store",
    );
    const externalAbort = new AbortController();
    const externalStream = await fetch(`${resumed.url}/events`, {
        signal: AbortSignal.any([externalAbort.signal, AbortSignal.timeout(5000)]),
    });
    const externalReader = externalStream.body.getReader();
    await externalReader.read();
    await rename(join(file, "in-progress", "task-2.json"), join(file, "done", "task-2.json"));
    assert.match(new TextDecoder().decode((await externalReader.read()).value), /data:/);
    externalAbort.abort();
    assert.equal(
        (await (await fetch(resumed.url)).json()).data.find((a) => a.id === "task-2").status,
        "done",
    );
    assert.equal(
        JSON.parse(await readFile(join(file, "done", "task-2.json"), "utf8")).status,
        "done",
    );
    await waitForLog(resumed, "#4 done: Task 2");
    // Client-supplied numbers cannot override the server sequence; control characters
    // and multiline comments cannot forge extra activity lines.
    const sanitized = await (
        await post(second.url, {
            id: "safe",
            number: 99,
            comment: "Hello\n\u001b[31mworld\u001b[0m",
        })
    ).json();
    assert.equal(sanitized.data.number, 1);
    await waitForLog(second, "#1 created: Hello world");
    await rm(join(dir, "second", "todo", "safe.json"));
    assert.equal(
        (await (await post(second.url, { id: "next", comment: "Next" })).json()).data.number,
        2,
    );
    const crashed = once(resumed.child, "exit");
    resumed.child.kill("SIGKILL");
    await crashed;
    const recovered = await start(["--store", file]);
    assert.equal(
        (await (await fetch(recovered.url)).json()).data.find((a) => a.id === "task-3").status,
        "in_progress",
    );
    assert.equal(
        (await post(`${recovered.url}/claim`, {})).status,
        204,
        "Restart does not reclaim in-progress work",
    );
    const legacyFile = join(dir, "legacy.json");
    await writeFile(
        legacyFile,
        JSON.stringify([{ id: "legacy", comment: "Preserve", status: "resolved", revision: 7 }]),
    );
    const migrated = await start(["--store", legacyFile]);
    assert.equal((await (await fetch(migrated.url)).json()).data[0].status, "done");
    assert.equal(
        JSON.parse(await readFile(join(`${legacyFile}.d`, "done", "legacy.json"), "utf8")).revision,
        7,
    );
    assert.equal(JSON.parse(await readFile(`${legacyFile}.legacy`, "utf8"))[0].comment, "Preserve");
    const migratedStopped = once(migrated.child, "exit");
    migrated.child.kill("SIGTERM");
    await migratedStopped;
    const migratedAgain = await start(["--store", legacyFile]);
    assert.equal(
        (await (await fetch(migratedAgain.url)).json()).data[0].id,
        "legacy",
        "The old --store argument still resumes migrated work",
    );
    console.log(
        "Local server: concurrent claims, completion, release, directories, migration, persistence, SSE, CORS, and validation passed.",
    );
} finally {
    await Promise.all(
        children.map(async (child) => {
            if (child.exitCode !== null || child.signalCode !== null) return;
            const exited = once(child, "exit");
            child.kill("SIGTERM");
            await exited;
        }),
    );
    await rm(dir, { recursive: true, force: true });
}
