import { afterEach, expect, it, vi } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { setTransport } from "../../src/api/client";
import { queryClient } from "../../src/api/queryClient";
import { openApp, pane } from "./app";

class FakeSource extends EventTarget {
    static CLOSED = 2;
    static instances: FakeSource[] = [];
    readyState = 1;
    onerror: (() => void) | null = null;
    closed = false;
    url: string;
    constructor(url: string) {
        super();
        this.url = url;
        FakeSource.instances.push(this);
    }
    close() {
        this.closed = true;
    }
    send(value: unknown) {
        this.dispatchEvent(new MessageEvent("agent", { data: JSON.stringify(value) }));
    }
}
afterEach(() => {
    vi.unstubAllGlobals();
    FakeSource.instances = [];
});

it("shows the shared reviewer and selected subtask, streams updates, and closes old subscriptions", async () => {
    vi.stubGlobal("EventSource", FakeSource);
    const app = await openApp();
    const group = {
        id: 7,
        title: "Agent viewer",
        brief: "Observe agents",
        status: "running",
        app_id: 999,
        app: "test",
        taskable_type: null,
        tasks: [{ id: 9, title: "First subtask", brief: "Read progress", status: "running" }],
    };
    const sessions = [
        {
            id: 1,
            task_group_id: 7,
            task_id: null,
            node_id: 3,
            role: "reviewer",
            external_id: "review",
            driver: "t3",
            state: "idle",
        },
        {
            id: 2,
            task_group_id: 7,
            task_id: 9,
            node_id: 2,
            role: "implementer",
            external_id: "implement",
            driver: "t3",
            state: "idle",
        },
        {
            id: 3,
            task_group_id: 7,
            task_id: 10,
            node_id: 3,
            role: "implementer",
            external_id: "other",
            driver: "other-driver",
            state: "done",
        },
    ];
    setTransport((method, path, body) =>
        path.startsWith("/api/v1/task-groups/")
            ? Promise.resolve({
                  status: 200,
                  payload: {
                      data: path.endsWith("/agents")
                          ? sessions
                          : path.endsWith("/comments")
                            ? []
                            : group,
                  },
              })
            : app.gateway.transport(method, path, body),
    );
    await app.router.navigate({
        to: "/tasks/$id/subtasks/$subtaskId",
        params: { id: "7", subtaskId: "9" },
    });
    await expect.element(pane("Agents").getByRole("tab", { name: /Implementer/ })).toBeVisible();
    expect(pane("Agents").getByRole("tab").all()).toHaveLength(2);
    await expect.poll(() => FakeSource.instances.length).toBe(1);
    const implementer = FakeSource.instances[0]!;
    expect(implementer.url).toBe("/api/v1/task-groups/7/agents/2/stream");
    implementer.send({ kind: "snapshot", thread_id: 2, cursor: "5", state: "idle", entries: [] });
    await expect
        .element(page.getByRole("tabpanel"))
        .toHaveTextContent("Thread created. No agent activity yet.");
    await expect.element(page.getByRole("tabpanel")).toHaveTextContent("t3 - beast");
    expect(document.querySelector('[role="tabpanel"]')?.textContent).not.toContain("implement");
    const entry = (id: string, kind: string, text: string) => ({
        id,
        kind,
        label: kind === "message" ? "assistant" : "command",
        text,
        at: "",
    });
    implementer.send({
        kind: "entry",
        thread_id: 2,
        cursor: "6",
        entry: entry("m", "message", "Reading the source <script>unsafe()</script>"),
    });
    await expect
        .element(page.getByRole("tabpanel"))
        .toHaveTextContent("Reading the source <script>unsafe()</script>");
    expect(document.querySelector('[role="tabpanel"] script')).toBeNull();
    implementer.send({ kind: "state", thread_id: 2, cursor: "7", state: "working" });
    implementer.send({
        kind: "entry",
        thread_id: 2,
        cursor: "8",
        entry: entry("a1", "activity", "ran tests"),
    });
    implementer.send({
        kind: "entry",
        thread_id: 2,
        cursor: "9",
        entry: entry("a2", "activity", "edited file"),
    });
    await expect
        .poll(() =>
            document.querySelector("[data-activity-group]")?.getAttribute("data-activity-group"),
        )
        .toBe("active");
    await expect.element(page.getByRole("tabpanel")).toHaveTextContent("edited file");
    implementer.send({
        kind: "entry",
        thread_id: 2,
        cursor: "10",
        entry: entry("m2", "message", "Done."),
    });
    await expect
        .poll(() =>
            document.querySelector("[data-activity-group]")?.getAttribute("data-activity-group"),
        )
        .toBe("complete");
    await expect.element(page.getByRole("tabpanel")).toHaveTextContent("2 steps");
    implementer.send({
        kind: "entry",
        thread_id: 2,
        cursor: "11",
        entry: entry("a3", "activity", "committed"),
    });
    await expect
        .poll(() => document.querySelector("[data-activity-group='active']")?.textContent)
        .toContain("committed");
    for (const [state, label] of [
        ["asking_for_input", "Asking for input"],
        ["done", "Done"],
        ["failed", "Failed"],
    ]) {
        implementer.send({ kind: "state", thread_id: 2, cursor: state, state });
        await expect.element(page.getByRole("status", { name: label, exact: true })).toBeVisible();
    }
    implementer.onerror?.();
    await expect.element(page.getByRole("status", { name: "Failed", exact: true })).toBeVisible();
    await expect.element(page.getByLabelText("Agent connection")).toHaveTextContent("Reconnecting");
    document.getElementById("agent-tab-2")!.focus();
    await userEvent.keyboard("{ArrowUp}");
    await expect
        .element(page.getByRole("tab", { name: /Reviewer/ }))
        .toHaveAttribute("aria-selected", "true");
    expect(implementer.closed).toBe(true);
    await expect.poll(() => FakeSource.instances.length).toBe(2);
    const reviewer = FakeSource.instances[1]!;
    expect(reviewer.url).toBe("/api/v1/task-groups/7/agents/1/stream");
    expect(document.querySelector('[role="tabpanel"]')?.textContent).not.toContain(
        "Reading the source",
    );
    reviewer.onerror?.();
    await expect
        .poll(() =>
            document.querySelector('[role="tabpanel"] [role="status"]')?.getAttribute("aria-label"),
        )
        .toBe("Idle");
    await app.router.navigate({ to: "/" });
    expect(reviewer.closed).toBe(true);
    await app.router.navigate({ to: "/tasks/$id", params: { id: "7" } });
    await expect
        .poll(() => FakeSource.instances.at(-1)?.url)
        .toBe("/api/v1/task-groups/7/agents/3/stream");
});

it("shows each thread's polled state in its tab, whatever the selected thread's stream says", async () => {
    vi.stubGlobal("EventSource", FakeSource);
    const app = await openApp();
    const group = {
        id: 49,
        title: "Receipts",
        brief: "Record run receipts",
        status: "running",
        app_id: 999,
        app: "test",
        taskable_type: null,
        tasks: [{ id: 50, title: "Only subtask", brief: "Write receipts", status: "running" }],
    };
    const thread = (id: number, role: string, state: string) => ({
        id,
        task_group_id: 49,
        task_id: role === "reviewer" ? null : 50,
        node_id: 2,
        role,
        external_id: role,
        driver: "t3",
        state,
    });
    let sessions = [thread(1, "reviewer", "idle"), thread(2, "implementer", "working")];
    setTransport((method, path, body) =>
        path.startsWith("/api/v1/task-groups/")
            ? Promise.resolve({
                  status: 200,
                  payload: {
                      data: path.endsWith("/agents")
                          ? sessions
                          : path.endsWith("/comments")
                            ? []
                            : group,
                  },
              })
            : app.gateway.transport(method, path, body),
    );
    await app.router.navigate({ to: "/tasks/$id", params: { id: "49" } });
    const indicator = (role: RegExp) =>
        pane("Agents").getByRole("tab", { name: role }).getByRole("img");
    await expect.element(indicator(/Implementer/)).toHaveAccessibleName("Working");
    await expect.element(indicator(/Reviewer/)).toHaveAccessibleName("Idle");
    await expect.poll(() => FakeSource.instances.length).toBe(1);
    const implementer = FakeSource.instances[0]!;
    implementer.send({
        kind: "snapshot",
        thread_id: 2,
        cursor: "1",
        state: "working",
        entries: [],
    });

    // The implementer hands off; its stream never reports the end of the turn.
    sessions = [thread(1, "reviewer", "working"), thread(2, "implementer", "done")];
    await queryClient.invalidateQueries({ queryKey: ["task-groups"] });

    await expect.element(indicator(/Implementer/)).toHaveAccessibleName("Done");
    await expect.element(indicator(/Reviewer/)).toHaveAccessibleName("Working");
    await expect.element(indicator(/Implementer/)).not.toHaveClass("text-green");
    await expect.element(indicator(/Reviewer/)).toHaveClass("text-green");
    await expect
        .element(page.getByRole("tabpanel").getByRole("status", { name: "Done", exact: true }))
        .toBeVisible();
    await expect
        .element(pane("Agents").getByRole("tab", { name: /Implementer/ }))
        .toHaveAttribute("aria-selected", "true");
});

it("pauses a hidden tab's stream and resumes it after the last cursor", async () => {
    vi.stubGlobal("EventSource", FakeSource);
    let visibility: DocumentVisibilityState = "visible";
    Object.defineProperty(document, "visibilityState", {
        configurable: true,
        get: () => visibility,
    });
    const setVisibility = (value: DocumentVisibilityState) => {
        visibility = value;
        document.dispatchEvent(new Event("visibilitychange"));
    };
    try {
        const app = await openApp();
        const group = {
            id: 51,
            title: "Resume",
            brief: "Resume streams",
            status: "running",
            app_id: 999,
            app: "test",
            taskable_type: null,
            tasks: [{ id: 52, title: "Only subtask", brief: "Stream", status: "running" }],
        };
        const session = {
            id: 4,
            task_group_id: 51,
            task_id: 52,
            node_id: 2,
            role: "implementer",
            external_id: "pi-session",
            driver: "pi",
            state: "working",
        };
        setTransport((method, path, body) =>
            path.startsWith("/api/v1/task-groups/")
                ? Promise.resolve({
                      status: 200,
                      payload: {
                          data: path.endsWith("/agents")
                              ? [session]
                              : path.endsWith("/comments")
                                ? []
                                : group,
                      },
                  })
                : app.gateway.transport(method, path, body),
        );
        await app.router.navigate({ to: "/tasks/$id", params: { id: "51" } });
        await expect.poll(() => FakeSource.instances.length).toBe(1);
        const first = FakeSource.instances[0]!;
        expect(first.url).toBe("/api/v1/task-groups/51/agents/4/stream");
        first.send({ kind: "snapshot", thread_id: 4, cursor: "run-1.7", entries: [] });
        first.send({
            kind: "entry",
            thread_id: 4,
            cursor: "run-1.8",
            entry: {
                id: "e2:0",
                kind: "activity",
                label: "Running",
                text: "Running: $ composer test",
                at: "",
            },
        });
        await expect
            .element(page.getByRole("tabpanel"))
            .toHaveTextContent("Running: $ composer test");

        setVisibility("hidden");
        await expect.element(page.getByLabelText("Agent connection")).toHaveTextContent("Paused");
        expect(first.closed).toBe(true);

        setVisibility("visible");
        await expect.poll(() => FakeSource.instances.length).toBe(2);
        expect(FakeSource.instances[1]!.url).toBe(
            "/api/v1/task-groups/51/agents/4/stream?after_sequence=run-1.8",
        );
        await expect
            .element(page.getByRole("tabpanel"))
            .toHaveTextContent("Running: $ composer test");
    } finally {
        delete (document as { visibilityState?: unknown }).visibilityState;
    }
});
