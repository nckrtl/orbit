import { afterEach, expect, it, vi } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { setTransport } from "../../src/api/client";
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
            thread_id: "review",
        },
        {
            id: 2,
            task_group_id: 7,
            task_id: 9,
            node_id: 2,
            role: "implementer",
            thread_id: "implement",
        },
        {
            id: 3,
            task_group_id: 7,
            task_id: 10,
            node_id: 3,
            role: "implementer",
            thread_id: "other",
        },
    ];
    setTransport((method, path, body) =>
        path.startsWith("/api/v1/task-groups/")
            ? Promise.resolve({
                  status: 200,
                  payload: { data: path.endsWith("/agents") ? sessions : group },
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
    implementer.send({
        kind: "snapshot",
        snapshot: { snapshotSequence: 5, thread: { id: "implement", session: null, messages: [] } },
    });
    await expect
        .element(page.getByRole("tabpanel"))
        .toHaveTextContent("Thread created. No agent activity yet.");
    await expect.element(page.getByRole("tabpanel")).toHaveTextContent("T3 - beast");
    expect(document.querySelector('[role="tabpanel"]')?.textContent).not.toContain("implement");
    implementer.send({
        kind: "event",
        event: {
            sequence: 6,
            aggregateId: "implement",
            type: "thread.message-sent",
            payload: {
                messageId: "m",
                role: "assistant",
                text: "Reading the source <script>unsafe()</script>",
            },
        },
    });
    await expect
        .element(page.getByRole("tabpanel"))
        .toHaveTextContent("Reading the source <script>unsafe()</script>");
    expect(document.querySelector('[role="tabpanel"] script')).toBeNull();
    implementer.send({
        kind: "event",
        event: {
            sequence: 7,
            aggregateId: "implement",
            type: "thread.session-set",
            payload: { session: { status: "running" } },
        },
    });
    implementer.send({
        kind: "event",
        event: {
            sequence: 8,
            aggregateId: "implement",
            type: "thread.activity-appended",
            payload: { activity: { id: "a1", kind: "command", summary: "ran tests" } },
        },
    });
    implementer.send({
        kind: "event",
        event: {
            sequence: 9,
            aggregateId: "implement",
            type: "thread.activity-appended",
            payload: { activity: { id: "a2", kind: "command", summary: "edited file" } },
        },
    });
    await expect
        .poll(() => document.querySelector("[data-activity-group]")?.getAttribute("data-activity-group"))
        .toBe("active");
    await expect.element(page.getByRole("tabpanel")).toHaveTextContent("edited file");
    implementer.send({
        kind: "event",
        event: {
            sequence: 10,
            aggregateId: "implement",
            type: "thread.message-sent",
            payload: { messageId: "m2", role: "assistant", text: "Done." },
        },
    });
    await expect
        .poll(() => document.querySelector("[data-activity-group]")?.getAttribute("data-activity-group"))
        .toBe("complete");
    await expect.element(page.getByRole("tabpanel")).toHaveTextContent("2 steps");
    implementer.send({
        kind: "event",
        event: {
            sequence: 11,
            aggregateId: "implement",
            type: "thread.activity-appended",
            payload: { activity: { id: "a3", kind: "command", summary: "committed" } },
        },
    });
    await expect
        .poll(() => document.querySelector("[data-activity-group='active']")?.textContent)
        .toContain("committed");
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
        .poll(() => document.querySelector('[role="tabpanel"] [role="status"]')?.getAttribute("aria-label"))
        .toBe("Not live");
    await app.router.navigate({ to: "/" });
    expect(reviewer.closed).toBe(true);
    await app.router.navigate({ to: "/tasks/$id", params: { id: "7" } });
    await expect
        .poll(() => FakeSource.instances.at(-1)?.url)
        .toBe("/api/v1/task-groups/7/agents/3/stream");
});
