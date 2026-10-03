import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { mkdtempSync, rmSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { AnnotationStore } from "../bin/store.mjs";

const root = mkdtempSync(join(tmpdir(), "annotator-transition-stop-"));
const childScript = `
import fs from "node:fs";
import { syncBuiltinESMExports } from "node:module";
import { AnnotationStore } from "./bin/store.mjs";
const { path, edge, stopAt } = JSON.parse(process.env.TRANSITION_TEST);
const store = new AnnotationStore(path);
const original = {};
let mutations = 0;
for (const operation of ["writeFileSync", "renameSync", "unlinkSync"]) {
    original[operation] = fs[operation];
    fs[operation] = (...args) => {
        const result = original[operation](...args);
        mutations++;
        console.log(JSON.stringify({ operation, mutations, committed: operation === "renameSync" && args[1] === path + "/.transition.json" }));
        if (mutations === stopAt) process.exit(77);
        return result;
    };
}
syncBuiltinESMExports();
if (stopAt === 0) process.exit(77);
if (edge === "release") store.transition(store.find("pin"), "todo", "Which color?", true);
else store.claim("pin");
for (const operation of Object.keys(original)) fs[operation] = original[operation];
syncBuiltinESMExports();
store.close();
`;

function seed(path, edge) {
    const store = new AnnotationStore(path);
    store.create({ id: "pin", comment: "Clarify the color" });
    const claimed = store.claim();
    if (edge === "claim") store.transition(claimed, "todo", "Which color?", true);
    store.close();
}
function run(path, edge, stopAt) {
    const child = spawnSync(process.execPath, ["--input-type=module", "--eval", childScript], {
        env: { ...process.env, TRANSITION_TEST: JSON.stringify({ path, edge, stopAt }) },
        encoding: "utf8",
    });
    assert.equal(child.status, stopAt < 0 ? 0 : 77, child.stderr);
    return child.stdout
        .trim()
        .split("\n")
        .filter(Boolean)
        .map((line) => JSON.parse(line));
}
try {
    for (const edge of ["release", "claim"]) {
        const control = join(root, `${edge}-control`);
        seed(control, edge);
        const mutations = run(control, edge, -1);
        assert.ok(
            mutations.some((entry) => entry.committed),
            "Cross-directory transitions commit an intent",
        );
        for (let stopAt = 0; stopAt <= mutations.length; stopAt++) {
            const path = join(root, `${edge}-${stopAt}`);
            seed(path, edge);
            const trace = run(path, edge, stopAt);
            const committed = trace.some((entry) => entry.committed);
            const recovered = new AnnotationStore(path);
            try {
                const records = recovered.list();
                assert.equal(
                    records.length,
                    1,
                    `${edge} stop ${stopAt}: no duplicate or missing record`,
                );
                const record = records[0];
                const question = edge === "release" ? committed : !committed;
                assert.equal(
                    record.status,
                    question ? "todo" : "in_progress",
                    `${edge} stop ${stopAt}`,
                );
                assert.equal(
                    record.question,
                    question ? true : undefined,
                    `${edge} stop ${stopAt}: marker`,
                );
                assert.equal(
                    record.summary,
                    question ? "Which color?" : undefined,
                    `${edge} stop ${stopAt}: summary`,
                );
                assert.equal(
                    recovered.claim(),
                    null,
                    "Automatic claims never take a recovered question or in-progress record",
                );
                if (question) {
                    const claimed = recovered.claim("pin");
                    assert.equal(claimed.status, "in_progress");
                    assert.equal(claimed.question, undefined);
                    assert.equal(claimed.summary, undefined);
                }
            } finally {
                recovered.close();
            }
        }
    }
    console.log(
        "Store recovery: injected stops at every release/claim write boundary preserve question state and cleared fields",
    );
} finally {
    rmSync(root, { recursive: true, force: true });
}
