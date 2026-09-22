import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { fauxAssistantMessage, fauxToolCall } from "@earendil-works/pi-ai";
import { afterEach, describe, expect, it } from "vite-plus/test";
import { type Harness, startHarness, waitFor } from "./support.ts";

const MODEL = "faux/model-a";
let harness: Harness | undefined;

afterEach(async () => {
    await harness?.close();
    harness = undefined;
});

async function created(options: { tokensPerSecond?: number } = {}): Promise<Harness> {
    harness = await startHarness(options);
    const response = await harness.request("POST", "/sessions", {
        id: "thread-1",
        cwd: harness.workspace,
        model: MODEL,
        thinkingLevel: "low",
        appendSystemPrompt: "Role prompt marker.",
    });
    expect(response.status).toBe(201);

    return harness;
}

const settled = (h: Harness) =>
    waitFor(
        async () => (await h.request("GET", "/sessions/thread-1")).body,
        (snapshot) => snapshot.state !== "working",
    );

describe("authentication", () => {
    it("refuses requests without the bearer token", async () => {
        harness = await startHarness();
        const response = await harness.request("GET", "/capabilities", undefined, "wrong");

        expect(response.status).toBe(401);
        expect(response.body.error.code).toBe("unauthorized");
    });

    it("lists the models this Node can run", async () => {
        harness = await startHarness();
        const response = await harness.request("GET", "/capabilities");

        expect(response.status).toBe(200);
        expect(response.body.models).toContain(MODEL);
    });
});

describe("create", () => {
    it("returns the existing session when the same create is repeated", async () => {
        const h = await created();
        const again = await h.request("POST", "/sessions", {
            id: "thread-1",
            cwd: h.workspace,
            model: MODEL,
            thinkingLevel: "low",
            appendSystemPrompt: "Role prompt marker.",
        });

        expect(again.status).toBe(200);
    });

    it("refuses a repeated ID with different settings", async () => {
        const h = await created();
        const conflict = await h.request("POST", "/sessions", {
            id: "thread-1",
            cwd: h.workspace,
            model: MODEL,
            thinkingLevel: "high",
        });

        expect(conflict.status).toBe(409);
        expect(conflict.body.error.code).toBe("session_exists");
    });

    it.each([
        [{ model: "faux/missing" }, "model_unavailable"],
        [{ model: "no-slash" }, "model_unavailable"],
        [{ id: "../escape" }, "invalid_request"],
        [{ thinkingLevel: "extreme" }, "invalid_request"],
        [{ cwd: "/" }, "invalid_request"],
    ])("refuses %j", async (override, code) => {
        harness = await startHarness();
        const response = await harness.request("POST", "/sessions", {
            id: "thread-2",
            cwd: harness.workspace,
            model: MODEL,
            thinkingLevel: "low",
            ...override,
        });

        expect(response.status).toBe(422);
        expect(response.body.error.code).toBe(code);
    });

    it("refuses a model signed in with an API key unless API keys are allowed", async () => {
        harness = await startHarness({ allowApiKeys: false });
        const response = await harness.request("POST", "/sessions", {
            id: "thread-2",
            cwd: harness.workspace,
            model: MODEL,
            thinkingLevel: "low",
        });

        expect(response.status).toBe(422);
        expect(response.body.error.message).toContain("subscription");
        expect((await harness.request("GET", "/capabilities")).body.models).toEqual([]);
    });

    it("returns not found for an unknown session", async () => {
        harness = await startHarness();

        expect((await harness.request("GET", "/sessions/unknown")).status).toBe(404);
    });
});

describe("turns", () => {
    it("streams a snapshot, then entries and states through a completed turn", async () => {
        const h = await created();
        h.faux.setResponses([
            fauxAssistantMessage([fauxToolCall("bash", { command: "echo probe" })], {
                stopReason: "toolUse",
            }),
            fauxAssistantMessage("finished"),
        ]);
        const collecting = h.stream("thread-1", (events) =>
            events.some((e) => e.kind === "state" && e.state === "done"),
        );
        await new Promise((resolve) => setTimeout(resolve, 50));

        expect(
            (await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" }))
                .status,
        ).toBe(202);
        const events = await collecting;

        expect(events[0]).toMatchObject({
            kind: "snapshot",
            state: "idle",
            turnId: null,
            entries: [],
        });
        expect(events[1]).toMatchObject({ kind: "state", state: "working", turnId: "k1" });
        const roles = events.filter((e) => e.kind === "entry").map((e) => e.entry.message.role);
        expect(roles).toEqual(["user", "assistant", "toolResult", "assistant"]);
        const sequences = events.slice(1).map((e) => e.sequence);
        expect(sequences).toEqual([...sequences].sort((a, b) => a - b));
        expect(events.at(-1)).toMatchObject({ kind: "state", state: "done", error: null });
        expect(events.at(-1).usage.total).toBeGreaterThan(0);
    });

    it("does not start a second turn for a repeated key", async () => {
        const h = await created();
        h.faux.setResponses([fauxAssistantMessage("once")]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await settled(h);

        const repeat = await h.request("POST", "/sessions/thread-1/messages", {
            key: "k1",
            text: "go",
        });

        expect(repeat).toMatchObject({ status: 200, body: { duplicate: true } });
        expect(h.faux.state.callCount).toBe(1);
    });

    it("refuses a new key while a turn is active and reports an interrupt as failed", async () => {
        const h = await created({ tokensPerSecond: 5 });
        h.faux.setResponses([
            fauxAssistantMessage("a slow answer that keeps streaming for a while"),
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });

        const busy = await h.request("POST", "/sessions/thread-1/messages", {
            key: "k2",
            text: "more",
        });
        expect(busy.status).toBe(409);
        expect(busy.body.error.code).toBe("turn_active");

        expect((await h.request("POST", "/sessions/thread-1/interrupt")).status).toBe(202);
        expect(await settled(h)).toMatchObject({
            state: "failed",
            error: "The turn was interrupted.",
        });
    });

    it("reports a provider error as failed with its message", async () => {
        const h = await created();
        h.faux.setResponses([
            fauxAssistantMessage("", { stopReason: "error", errorMessage: "quota exceeded" }),
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });

        expect(await settled(h)).toMatchObject({ state: "failed", error: "quota exceeded" });
    });

    it("sends project skills, AGENTS.md, and the role prompt to the model", async () => {
        const h = await created();
        let systemPrompt = "";
        h.faux.setResponses([
            (context) => {
                systemPrompt = JSON.stringify(context);
                return fauxAssistantMessage("ok");
            },
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await settled(h);

        expect(systemPrompt).toContain("orbit-probe");
        expect(systemPrompt).toContain("Workspace instruction marker.");
        expect(systemPrompt).toContain("Role prompt marker.");
    });
});

describe("restart", () => {
    it("reloads the transcript and keeps completed outcomes", async () => {
        const h = await created();
        h.faux.setResponses([fauxAssistantMessage("persisted")]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await settled(h);

        harness = await h.restart();
        const snapshot = (await harness.request("GET", "/sessions/thread-1")).body;

        expect(snapshot.state).toBe("done");
        expect(snapshot.entries.map((e: any) => e.message.role)).toEqual(["user", "assistant"]);
        const repeat = await harness.request("POST", "/sessions/thread-1/messages", {
            key: "k1",
            text: "go",
        });
        expect(repeat.body.duplicate).toBe(true);
    });

    it("reports a turn that was active during a restart as failed", async () => {
        const h = await created();
        h.faux.setResponses([fauxAssistantMessage("done before the crash")]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        await settled(h);
        const recordPath = join(h.root, "sessions", "thread-1.orbit.json");
        const record = JSON.parse(readFileSync(recordPath, "utf8"));
        writeFileSync(recordPath, JSON.stringify({ ...record, turnActive: true }));

        harness = await h.restart();

        expect((await harness.request("GET", "/sessions/thread-1")).body).toMatchObject({
            state: "failed",
            error: "The Pi server restarted during the turn.",
        });
    });
});
