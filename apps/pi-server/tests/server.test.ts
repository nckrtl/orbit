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

    it("allows a named API-key provider, such as a CLIProxyAPI endpoint", async () => {
        harness = await startHarness({ allowApiKeys: false, allowedProviders: ["faux"] });
        const response = await harness.request("POST", "/sessions", {
            id: "thread-2",
            cwd: harness.workspace,
            model: MODEL,
            thinkingLevel: "low",
        });

        expect(response.status).toBe(201);
        expect((await harness.request("GET", "/capabilities")).body.models).toEqual([MODEL]);
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
            run: expect.any(String),
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

    it("gives the model search_docs, which explains when no Laravel app is found", async () => {
        const h = await created();
        let context = "";
        h.faux.setResponses([
            (request) => {
                context = JSON.stringify(request);
                return fauxAssistantMessage(
                    [fauxToolCall("search_docs", { queries: ["validation"] })],
                    { stopReason: "toolUse" },
                );
            },
            fauxAssistantMessage("finished"),
        ]);
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });
        const snapshot = await settled(h);

        for (const tool of ["read", "bash", "edit", "write", "search_docs"]) {
            expect(context).toContain(`"name":"${tool}"`);
        }
        const result = snapshot.entries.find((e: any) => e.message.role === "toolResult").message;
        expect(result.isError).toBe(true);
        expect(JSON.stringify(result.content)).toContain("No Laravel app with Laravel Boost found");
    });
});

describe("resume", () => {
    const done = (events: any[]) => events.some((e) => e.kind === "state" && e.state === "done");

    /** Runs one turn with a bash call and returns the events of a stream that watched it. */
    async function completedTurn(): Promise<{ h: Harness; events: any[] }> {
        const h = await created();
        h.faux.setResponses([
            fauxAssistantMessage([fauxToolCall("bash", { command: "echo probe" })], {
                stopReason: "toolUse",
            }),
            fauxAssistantMessage("finished"),
        ]);
        const collecting = h.stream("thread-1", done);
        await new Promise((resolve) => setTimeout(resolve, 50));
        await h.request("POST", "/sessions/thread-1/messages", { key: "k1", text: "go" });

        return { h, events: await collecting };
    }

    it("sends only the events after the cursor, without a snapshot", async () => {
        const { h, events } = await completedTurn();
        const run = events[0].run;
        const first = events.find((e) => e.kind === "entry");

        const resumed = await h.stream("thread-1", done, `?run=${run}&after=${first.sequence}`);

        expect(resumed[0]).toMatchObject({
            kind: "resumed",
            run,
            sequence: first.sequence,
            session: { id: "thread-1" },
            context: [],
        });
        expect(resumed.some((e) => e.kind === "snapshot")).toBe(false);
        const later = (e: any) => e.kind === "entry" && e.sequence > first.sequence;
        expect(resumed.filter(later)).toEqual(events.filter(later));
        expect(resumed.filter((e) => e.kind === "entry" && e.sequence <= first.sequence)).toEqual(
            [],
        );
        expect(resumed.at(-1)).toMatchObject({ kind: "state", state: "done", run });
        expect(resumed.at(-1).sequence).toBe(events.at(-1).sequence);
        const sequences = resumed.slice(1).map((e) => e.sequence);
        expect(sequences).toEqual([...sequences].sort((a, b) => a - b));
    });

    it("names a tool call that has no result yet at the cursor", async () => {
        const { h, events } = await completedTurn();
        const call = events.find((e) => e.kind === "entry" && e.entry.message.role === "assistant");

        const resumed = await h.stream("thread-1", done, `?run=${call.run}&after=${call.sequence}`);

        expect(resumed[0].context).toEqual([call.entry]);
        expect(resumed[1].entry.message).toMatchObject({
            role: "toolResult",
            toolCallId: call.entry.message.content[0].id,
        });
    });

    it.each([
        ["another run", (run: string) => `?run=other${run}&after=1`],
        ["a sequence ahead of the run", (run: string) => `?run=${run}&after=999999`],
        ["a malformed sequence", (run: string) => `?run=${run}&after=abc`],
        ["no run", () => "?after=1"],
    ])("sends a snapshot for %s", async (_name, query) => {
        const { h, events } = await completedTurn();

        const [first] = await h.stream("thread-1", (e) => e.length > 0, query(events[0].run));

        expect(first).toMatchObject({ kind: "snapshot", run: events[0].run, state: "done" });
        expect(first.entries).toHaveLength(4);
    });

    it("sends a snapshot for a cursor from before a restart", async () => {
        const { h, events } = await completedTurn();
        const cursor = `?run=${events[0].run}&after=${events.at(-1).sequence}`;

        harness = await h.restart();
        const [first] = await harness.stream("thread-1", (e) => e.length > 0, cursor);

        expect(first).toMatchObject({ kind: "snapshot", state: "done" });
        expect(first.run).not.toBe(events[0].run);
        expect(first.entries).toHaveLength(4);
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
