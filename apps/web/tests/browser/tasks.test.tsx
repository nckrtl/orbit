import { queryClient } from "../../src/api/queryClient";
import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import type { Transport } from "../../src/api/client";
import type { TaskGroup } from "../../src/api/tasks";
import { openApp, pane } from "./app";
import { screenText } from "./screen";

function group(id: number, status: TaskGroup["status"]): TaskGroup {
    return {
        id,
        app_id: 999,
        execution_mode: "managed",
        app: "example-project",
        project_code: "EXA",
        title: `Feature ${id}`,
        brief: "Deliver the requested feature.\nKeep its existing behavior.",
        status,
        taskable_type: null,
        taskable_id: null,
        reviewer_agent_thread_id: null,
        pr_url: null,
        notify_coder: false,
        plan: false,
        assistance_requested: false,
        assistance_reason: null,
        implementer_model: "implementer",
        reviewer_model: "reviewer",
        tokens: null,
        line_diff: null,
        duration_ms: null,
        tasks: [
            {
                id: 1,
                task_group_id: id,
                type: "implementation",
                target_thread_id: null,
                completion_summary: null,
                check: null,
                assistance_requested: false,
                assistance_reason: null,
                position: 1,
                title: "First step",
                brief: "Acceptance details",
                status: "todo",
                implementer_agent_thread_id: null,
                tokens: null,
                line_diff: null,
                duration_ms: null,
            },
        ],
    };
}

async function openTasks(answer: Transport) {
    return openApp("/tasks", {
        wrapTransport: (inner) => (method, path, body) =>
            path.endsWith("/agents")
                ? Promise.resolve({ status: 200, payload: { data: [] } })
                : path.startsWith("/api/v1/task-groups")
                  ? answer(method, path, body)
                  : inner(method, path, body),
    });
}

it("groups every status, opens details, and keeps unsuccessful outcomes visible", async () => {
    const statuses: TaskGroup["status"][] = [
        "todo",
        "backlog",
        "reserved",
        "running",
        "reviewing",
        "settling",
        "completed",
        "failed",
        "cancelled",
    ];
    const groups = statuses.map((status, index) => group(index + 1, status));
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? groups : groups[0] },
    }));
    await expect
        .element(pane("Todo").getByRole("link", { name: "Open task: Feature 1", exact: true }))
        .toBeVisible();
    expect(screenText()).toContain("Tasks │ 9");
    expect(pane("Backlog").getByRole("link").all()).toHaveLength(1);
    await expect.element(pane("Backlog")).toHaveTextContent("EXA-2");
    await expect.element(pane("Backlog")).not.toHaveTextContent("Being prepared");
    expect(document.querySelector(".navigation-page-header")?.textContent?.trim()).toBe("Tasks");
    expect(pane("In progress").getByRole("link").all()).toHaveLength(4);
    expect(pane("Done").getByRole("link").all()).toHaveLength(3);
    await expect.element(pane("In progress")).toHaveTextContent("0/1");
    await expect.element(pane("In progress")).not.toHaveTextContent("Running");
    await expect.element(pane("Done")).toHaveTextContent("Failed");
    await expect.element(pane("Done")).toHaveTextContent("Cancelled");
    await expect.element(pane("Todo")).toHaveTextContent("EXA-1");
    await pane("Todo").getByRole("link").click();
    await expect.element(pane("Description")).toHaveTextContent("Deliver the requested feature.");
    await expect.element(pane("Task")).toHaveTextContent("Todo");
    await expect.element(pane("Subtasks")).toHaveTextContent("First step");
    await expect.element(pane("Subtasks")).not.toHaveTextContent("Acceptance details");
    expect(app.url()).toBe("/tasks/1");
    app.router.history.back();
    await expect.element(pane("Todo")).toBeVisible();
});

it("shows empty columns only after a successful response", async () => {
    await openTasks(async () => ({ status: 200, payload: { data: [] } }));
    expect(screenText()).toContain("Tasks │ 0");
    await expect.element(pane("Backlog")).toHaveTextContent("No tasks being prepared.");
    await expect.element(pane("Todo")).toHaveTextContent("No tasks waiting.");
    await expect.element(pane("In progress")).toHaveTextContent("No tasks in progress.");
    await expect.element(pane("Done")).toHaveTextContent("No finished tasks yet.");
});

it("explains a disabled extension and can retry a failed request", async () => {
    let disabled = true;
    await openTasks(async () =>
        disabled
            ? { status: 409, payload: { error: { code: "tasks.disabled", message: "Disabled" } } }
            : { status: 200, payload: { data: [group(1, "todo")] } },
    );
    await expect.element(page.getByRole("alert")).toHaveTextContent("Tasks are disabled");
    expect(document.querySelector('[aria-label="Todo"]')).toBeNull();
    disabled = false;
    await page.getByRole("button", { name: "Try again" }).click();
    await expect.element(pane("Todo")).toHaveTextContent("Feature 1");
});

it("shows missing task errors on a direct detail URL", async () => {
    const app = await openTasks(async () => ({
        status: 404,
        payload: { error: { code: "not_found", message: "Task not found" } },
    }));
    await app.router.navigate({ to: "/tasks/$id", params: { id: "999" } });
    await expect.element(page.getByRole("alert")).toHaveTextContent("Task not found");
});

it("shows completed subtask counts on in-progress cards instead of a running label", async () => {
    const task = group(1, "running");
    const statusAt = (id: number, status: TaskGroup["tasks"][number]["status"]) => ({
        ...task.tasks[0]!,
        id,
        position: id,
        title: `Step ${id}`,
        status,
    });
    task.tasks = [
        statusAt(1, "completed"),
        statusAt(2, "completed"),
        statusAt(3, "running"),
        statusAt(4, "todo"),
        statusAt(5, "failed"),
    ];
    await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await expect.element(pane("In progress")).toHaveTextContent("2/5");
    await expect.element(pane("In progress")).not.toHaveTextContent("Running");
    await expect
        .element(pane("In progress").getByLabelText("2 of 5 subtasks completed"))
        .toBeVisible();
});

it("lets the keyboard activate a focused card", async () => {
    await openTasks(async (_, path) => ({
        status: 200,
        payload: {
            data: path === "/api/v1/task-groups" ? [group(1, "running")] : group(1, "running"),
        },
    }));
    await expect.element(pane("In progress").getByRole("link")).toBeVisible();
    (document.querySelector('a[href="/tasks/1"]') as HTMLAnchorElement).focus();
    await userEvent.keyboard("{Enter}");
    await expect.element(pane("Task")).toHaveTextContent("In progress");
});

it("groups subtasks by status and keeps their sequence within columns", async () => {
    const task = group(1, "running");
    const statuses = [
        "completed",
        "todo",
        "reviewing",
        "running",
        "reserved",
        "failed",
        "cancelled",
        "todo",
    ] as const;
    task.tasks = statuses
        .map((status, index) => ({
            ...task.tasks[0]!,
            id: index + 1,
            position: index + 1,
            title: `Step ${index + 1}`,
            status,
        }))
        .reverse();
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });
    await expect.element(pane("Todo")).toHaveTextContent("Step 2");
    const titles = (column: string) =>
        [...document.querySelectorAll(`[aria-label="${column}"] a h2`)].map(
            (element) => element.textContent,
        );
    expect(titles("Todo")).toEqual(["Step 2", "Step 8"]);
    expect(titles("In progress")).toEqual(["Step 3", "Step 4", "Step 5"]);
    expect(titles("Done")).toEqual(["Step 1", "Step 6", "Step 7"]);
    await expect.element(pane("Todo")).toHaveTextContent("EXA-2");
    await expect.element(pane("Todo")).not.toHaveTextContent("Acceptance details");
    await expect.element(pane("Done")).toHaveTextContent("Failed");
    await expect.element(pane("Done")).toHaveTextContent("Cancelled");
});

it("shows card identity, separate line changes and elapsed time, with tokens in details", async () => {
    const task = group(1, "running");
    task.tokens = 1500;
    task.line_diff = 13_998;
    task.lines_added = 23_320;
    task.lines_deleted = 9_322;
    task.duration_ms = 5000;
    task.tasks = [
        {
            ...task.tasks[0]!,
            tokens: 1200,
            line_diff: 5,
            duration_ms: 4000,
        },
    ];
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await expect.element(pane("In progress")).toHaveTextContent("EXA-1");
    await expect.element(pane("In progress")).toHaveTextContent("+23.32K");
    await expect.element(pane("In progress")).toHaveTextContent("−9.322K");
    await expect.element(pane("In progress")).toHaveTextContent("1.5K");
    await expect.element(pane("In progress")).toHaveTextContent("0/1");
    await expect.element(pane("In progress")).toHaveTextContent("<1m");
    await expect.element(pane("In progress")).not.toHaveTextContent("Running");
    await expect.element(pane("In progress")).not.toHaveTextContent("tokens");
    await pane("In progress").getByRole("link").click();
    await expect.element(pane("Task")).toHaveTextContent("Tokens");
    await expect.element(pane("Task")).toHaveTextContent("1.5K");
    await expect.element(pane("Task").getByTitle("1,500")).toBeVisible();
    await expect.element(pane("Task")).toHaveTextContent("Line diff");
    await expect.element(pane("Task")).toHaveTextContent("+23.32K −9.322K");
    await expect.element(pane("Task").getByTitle("+23,320 −9,322")).toBeVisible();
    await expect.element(pane("Task")).toHaveTextContent("5s");
    await expect.element(pane("Todo")).toHaveTextContent("First step");
    await expect.element(pane("Todo")).toHaveTextContent("1.2K");
    await expect.element(pane("Todo")).not.toHaveTextContent("tokens");
    await pane("Todo").getByRole("link", { name: "Open subtask: First step" }).click();
    await expect.element(pane("Task")).toHaveTextContent("First step");
    await expect.element(pane("Task")).toHaveTextContent("1.2K");
    await expect.element(pane("Task")).toHaveTextContent("5");
    await expect.element(pane("Task")).not.toHaveTextContent("1.5K");
    expect(app.url()).toBe("/tasks/1/subtasks/1");
});

it("opens a subtask with its own detail and returns to the parent board", async () => {
    const task = group(1, "running");
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });
    await pane("Todo").getByRole("link", { name: "Open subtask: First step" }).click();
    await expect.element(pane("Task")).toHaveTextContent("First step");
    await expect.element(pane("Task")).toHaveTextContent("Todo");
    await expect.element(pane("Task")).toHaveTextContent("example-project");
    await expect.element(pane("Description")).toHaveTextContent("Acceptance details");
    expect(document.querySelector('[aria-label="Subtasks"]')).toBeNull();
    expect(app.url()).toBe("/tasks/1/subtasks/1");
    await page
        .getByRole("navigation", { name: "Breadcrumb" })
        .getByText("Feature 1", { exact: true })
        .click();
    await expect.element(pane("Subtasks")).toBeVisible();
    expect(app.url()).toBe("/tasks/1");
});

it("loads a subtask URL directly and refuses an unknown subtask", async () => {
    const task = group(1, "running");
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await app.router.navigate({
        to: "/tasks/$id/subtasks/$subtaskId",
        params: { id: "1", subtaskId: "1" },
    });
    await expect.element(pane("Description")).toHaveTextContent("Acceptance details");
    expect(document.querySelector('[aria-label="Subtasks"]')).toBeNull();
    await app.router.navigate({
        to: "/tasks/$id/subtasks/$subtaskId",
        params: { id: "1", subtaskId: "999" },
    });
    await expect.element(page.getByRole("alert")).toHaveTextContent("Subtask not found");
    expect(document.querySelector('[aria-label="Description"]')).toBeNull();
});

it("shows Instance overview and an Instance-scoped Tasks board with keyboard tabs", async () => {
    const owned = {
        ...group(80, "running"),
        title: "Fix this Instance",
        taskable_type: "instance",
        taskable_id: 1,
    };
    const other = {
        ...group(81, "todo"),
        title: "Another Instance task",
        taskable_type: "instance",
        taskable_id: 2,
    };
    const unassigned = { ...group(82, "todo"), title: "Unassigned task" };
    await openApp("/instances/1", {
        wrapTransport: (inner) => (method, path, body) =>
            path === "/api/v1/task-groups"
                ? Promise.resolve({ status: 200, payload: { data: [owned, other, unassigned] } })
                : inner(method, path, body),
    });
    await expect
        .element(page.getByRole("tab", { name: "Overview", exact: true }))
        .toHaveAttribute("aria-selected", "true");
    await expect.element(pane("Application log")).toBeVisible();
    await expect.element(pane("Annotations")).not.toBeInTheDocument();
    await expect.element(page.getByRole("tab", { name: /^Tasks/ })).toHaveTextContent(/Tasks\s*1/);
    await page.getByRole("tab", { name: /^Tasks/ }).click();
    await expect
        .element(page.getByRole("link", { name: "Open task: Fix this Instance" }))
        .toBeVisible();
    await expect
        .element(page.getByRole("link", { name: "Open task: Another Instance task" }))
        .not.toBeInTheDocument();
    await expect
        .element(page.getByRole("link", { name: "Open task: Unassigned task" }))
        .not.toBeInTheDocument();
    await expect.element(pane("Application log")).not.toBeInTheDocument();
    await userEvent.keyboard("{ArrowUp}");
    await expect
        .element(page.getByRole("tab", { name: "Overview", exact: true }))
        .toHaveAttribute("aria-selected", "true");
    await expect.element(pane("Application log")).toBeVisible();
    queryClient.setQueryData(["task-groups"], [other, unassigned]);
    await expect
        .element(page.getByRole("tab", { name: "Tasks", exact: true }))
        .toHaveTextContent("Tasks");
    queryClient.setQueryData(["task-groups"], [owned, other, unassigned]);
    await expect.element(page.getByRole("tab", { name: /^Tasks/ })).toHaveTextContent(/Tasks\s*1/);
});
