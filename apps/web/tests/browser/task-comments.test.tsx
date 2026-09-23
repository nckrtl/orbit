import { afterEach, expect, it, vi } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { queryClient } from "../../src/api/queryClient";
import type { TaskComment } from "../../src/api/tasks";
import { openApp, pane } from "./app";

// The agent streams are not part of the demo Gateway; a closed source keeps them quiet.
class SilentSource extends EventTarget {
    static CLOSED = 2;
    readyState = 2;
    onerror: (() => void) | null = null;
    close() {}
}

afterEach(() => {
    vi.unstubAllGlobals();
});

const cards = () =>
    [...document.querySelectorAll('[aria-label="Comments"] article')].map((card) =>
        card.getAttribute("aria-label"),
    );

it("shows the subtask's latest check and its comments beside the agents, newest first", async () => {
    vi.stubGlobal("EventSource", SilentSource);
    const app = await openApp("/tasks/12/subtasks/31");
    const comments = pane("Comments");
    await expect.element(comments).toHaveTextContent("Changes requested");
    await expect.element(pane("Agents")).toBeVisible();
    expect(cards()).toEqual([
        "Latest check",
        "Ready for review by implementer",
        "Resolution by nick",
        "Assistance requested by implementer",
        "Changes requested by reviewer",
        "Ready for review by implementer",
    ]);
    expect(document.querySelector('[aria-label="Comments"] .frame-edge')?.textContent).toContain(
        "5",
    );

    const check = comments.getByRole("article", { name: "Latest check" });
    await expect.element(check).toHaveTextContent("handoff");
    await expect.element(check).toHaveTextContent("Failed");
    await expect.element(check).toHaveTextContent("1m 12s · Exit 1 · Failed step: composer test");
    await expect.element(check.getByText("Expected 90 but got 100.")).not.toBeVisible();
    await check.getByText("Output").click();
    await expect.element(check.getByText("Expected 90 but got 100.")).toBeVisible();

    const latest = comments.getByRole("article").nth(1);
    await expect.element(latest).toHaveTextContent("Expired codes now fail validation.");
    await expect
        .element(latest.getByRole("time"))
        .toHaveAttribute("datetime", "2026-09-23T09:43:05+00:00");
    await expect.element(latest.getByRole("time")).toHaveTextContent(/just now|ago$/);

    // The agents keep their own states: the implementer handed off and the reviewer works.
    await expect
        .element(
            pane("Agents")
                .getByRole("tab", { name: /Implementer/ })
                .getByRole("img"),
        )
        .toHaveAccessibleName("Done");
    await expect
        .element(
            pane("Agents")
                .getByRole("tab", { name: /Reviewer/ })
                .getByRole("img"),
        )
        .toHaveAccessibleName("Working");

    // A refresh brings in a new receipt at the top.
    const approved: TaskComment = {
        id: 47,
        task_group_id: 12,
        task_id: 31,
        agent_thread_id: 21,
        type: "approved",
        body: "The boundary day is covered. Ready to merge.",
        author: "reviewer",
        posted_at: new Date().toISOString(),
        review_attempt: 2,
        commit_sha: "5f2c9e1",
    };
    queryClient.setQueryData<TaskComment[]>(
        ["task-groups", "12", "tasks", "31", "comments"],
        (previous) => [approved, ...(previous ?? [])],
    );
    await expect.element(comments.getByRole("article").nth(1)).toHaveTextContent("Approved");
    await expect.element(comments.getByRole("article").nth(1)).toHaveTextContent("just now");
    expect(
        app.gateway.requests.some(
            (request) => request.path === "/api/v1/task-groups/12/tasks/31/comments",
        ),
    ).toBe(true);

    // The group page keeps the agents full width, without a comments column.
    await app.router.navigate({ to: "/tasks/$id", params: { id: "12" } });
    await expect.element(pane("Subtasks")).toBeVisible();
    await expect.element(page.getByRole("region", { name: "Comments" })).not.toBeInTheDocument();
});

it("says so when a subtask has no comments or check yet", async () => {
    vi.stubGlobal("EventSource", SilentSource);
    await openApp("/tasks/12/subtasks/32");
    await expect.element(pane("Comments")).toHaveTextContent("No comments on this task yet.");
    expect(cards()).toEqual([]);
});
