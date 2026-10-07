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
        project_id: 999,
        execution_mode: "managed",
        task_compute: null,
        capacity_wait_reason: null,
        project: "example-project",
        project_code: "EXA",
        title: `Feature ${id}`,
        brief: "Deliver the requested feature.\nKeep its existing behavior.",
        status,
        taskable_type: null,
        taskable_id: null,
        reviewer_agent_thread_id: null,
        pr_url: null,
        watched_pr_url: null,
        watched_pr_number: null,
        watched_pr_state: null,
        notify_coder: false,
        assistance_requested: false,
        assistance_kind: null,
        assistance_question: null,
        assistance_reason: null,
        implementer_model: "implementer",
        reviewer_model: "reviewer",
        tokens: null,
        line_diff: null,
        duration_ms: null,
        questions: 0,
        escalations: 0,
        tasks: [
            {
                id: 1,
                task_group_id: id,
                type: "implementation",
                target_thread_id: null,
                completion_summary: null,
                check: null,
                assistance_requested: false,
                assistance_kind: null,
                assistance_question: null,
                assistance_reason: null,
                fixup_problem: null,
                position: 1,
                title: "First step",
                brief: "Acceptance details",
                deliverables: [],
                status: "todo",
                implementer_agent_thread_id: null,
                tokens: null,
                line_diff: null,
                duration_ms: null,
                questions: 0,
                escalations: 0,
            },
        ],
    };
}

async function openTasks(answer: Transport) {
    return openApp("/tasks", {
        wrapTransport: (inner) => (method, path, body) =>
            path.endsWith("/agents") || path.endsWith("/comments")
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

it("distinguishes direction requests from failures on task and subtask cards", async () => {
    const direction = {
        ...group(1, "running"),
        assistance_requested: true,
        assistance_kind: "direction" as const,
        assistance_question: "Should discount codes combine with sale prices?",
    };
    const failure = {
        ...group(2, "reviewing"),
        assistance_requested: true,
        assistance_kind: "failure" as const,
        assistance_reason: "The check failed.",
    };
    const resolved = {
        ...direction,
        id: 3,
        title: "Resolved request",
        assistance_requested: false,
    };
    direction.tasks = [
        { ...direction.tasks[0]!, assistance_requested: true, assistance_kind: "direction" },
        {
            ...direction.tasks[0]!,
            id: 2,
            title: "Failed check",
            assistance_requested: true,
            assistance_kind: "failure",
        },
    ];
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: {
            data: path === "/api/v1/task-groups" ? [direction, failure, resolved] : direction,
        },
    }));
    const card = (title: string) =>
        page.getByRole("link", { name: `Open task: ${title}`, exact: true });
    await expect.element(card(direction.title)).toHaveTextContent("Needs your direction");
    await expect.element(card(direction.title)).not.toHaveTextContent("Needs attention");
    await expect.element(card(failure.title)).toHaveTextContent("Needs attention");
    await expect.element(card(failure.title)).not.toHaveTextContent("Needs your direction");
    await expect.element(card(resolved.title)).not.toHaveTextContent("Needs your direction");
    await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });
    await expect
        .element(pane("Todo").getByRole("link", { name: "Open subtask: First step" }))
        .toHaveTextContent("Needs your direction");
    await expect
        .element(pane("Todo").getByRole("link", { name: "Open subtask: Failed check" }))
        .toHaveTextContent("Needs attention");
});

it("leads a direction request with its question and shows question and escalation counts", async () => {
    const task = {
        ...group(1, "running"),
        assistance_requested: true,
        assistance_kind: "direction" as const,
        assistance_question: "Should discount codes combine with sale prices?",
        questions: 4,
        escalations: 2,
    };
    task.tasks[0] = { ...task.tasks[0]!, questions: 3, escalations: 1 };
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });
    await expect.element(pane("Needs your direction")).toHaveTextContent(task.assistance_question);
    const content = document.querySelector('[aria-label="Needs your direction"]')!.parentElement!;
    const frames = [...content.querySelectorAll("section.frame")];
    expect(frames[0]?.getAttribute("aria-label")).toBe("Needs your direction");
    expect(frames.indexOf(document.querySelector('[aria-label="Task"]')!)).toBeGreaterThan(0);
    expect(frames.indexOf(document.querySelector('[aria-label="Description"]')!)).toBeGreaterThan(
        0,
    );
    await expect.element(pane("Task").getByTitle("4", { exact: true })).toBeVisible();
    await expect.element(pane("Task")).toHaveTextContent(/Questions\s*4/);
    await expect.element(pane("Task")).toHaveTextContent(/Escalations\s*2/);
    // Visibility assertions alone do not detect rows clipped by a scrolling frame body.
    const properties = document.querySelector('[aria-label="Task"] .frame-body')!;
    expect(properties.scrollHeight).toBeLessThanOrEqual(properties.clientHeight);
    const bodyBounds = properties.getBoundingClientRect();
    for (const row of properties.querySelectorAll(".row")) {
        const bounds = row.getBoundingClientRect();
        expect(bounds.top).toBeGreaterThanOrEqual(bodyBounds.top);
        expect(bounds.bottom).toBeLessThanOrEqual(bodyBounds.bottom);
    }
    await pane("Todo").getByRole("link").click();
    await expect.element(pane("Task")).toHaveTextContent(/Questions\s*3/);
    await expect.element(pane("Task")).toHaveTextContent(/Escalations\s*1/);
    expect(document.querySelector('[aria-label="Needs your direction"]')).toBeNull();
});

it.each(["failure", "direction"] as const)(
    "does not lead with a stale question for %s without an open direction request",
    async (kind) => {
        const task = {
            ...group(1, "running"),
            assistance_requested: kind === "failure",
            assistance_kind: kind,
            assistance_question: "This old question must not lead the page.",
        };
        const app = await openTasks(async (_, path) => ({
            status: 200,
            payload: { data: path === "/api/v1/task-groups" ? [task] : task },
        }));
        await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });
        await expect.element(pane("Task")).toBeVisible();
        expect(document.querySelector('[aria-label="Needs your direction"]')).toBeNull();
        expect(document.body.textContent).not.toContain(task.assistance_question);
        await expect.element(pane("Task")).toHaveTextContent(/Questions\s*0/);
        await expect.element(pane("Task")).toHaveTextContent(/Escalations\s*0/);
    },
);

it("keeps annotation task properties without the retired T3 execution label", async () => {
    const task = group(1, "running");
    task.execution_mode = "existing_thread";
    task.tasks[0]!.type = "annotation";
    task.tasks[0]!.target_thread_id = "operator-thread";
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });
    await expect.element(pane("Task")).toHaveTextContent("Annotation");
    await expect.element(pane("Task")).toHaveTextContent("operator-thread");
    await expect.element(pane("Task")).not.toHaveTextContent("Existing T3 thread");
    await expect.element(pane("Task")).not.toHaveTextContent("Execution");
    await pane("Todo").getByRole("link", { name: "Open subtask: First step" }).click();
    await expect.element(pane("Task")).toHaveTextContent("First step");
    await expect.element(pane("Task")).toHaveTextContent("Annotation");
    await expect.element(pane("Task")).toHaveTextContent("operator-thread");
    await expect.element(pane("Task")).not.toHaveTextContent("Existing T3 thread");
    await expect.element(pane("Task")).not.toHaveTextContent("Execution");
});

it("shows an empty task board without lanes only after a successful response", async () => {
    await openTasks(async () => ({ status: 200, payload: { data: [] } }));
    await expect.element(page.getByText("No tasks yet.", { exact: true })).toBeVisible();
    expect(screenText()).toContain("Tasks │ 0");
    expect(document.querySelector(".kanban-board")).toBeNull();
    for (const column of ["Backlog", "Todo", "In progress", "Done"]) {
        expect(document.querySelector(`[aria-label="${column}"]`)).toBeNull();
    }
});

function expectFilledLanes(board: Element, titles: string[]): void {
    const lanes = [...board.querySelectorAll<HTMLElement>(":scope > .frame")];
    expect(lanes.map((lane) => lane.getAttribute("aria-label"))).toEqual(titles);
    const widths = lanes.map((lane) => lane.getBoundingClientRect().width);
    for (const width of widths) {
        expect(Math.abs(width - widths[0]!)).toBeLessThan(1.5);
    }
    const tracks = getComputedStyle(board).gridTemplateColumns.split(" ");
    expect(tracks).toHaveLength(window.innerWidth >= 1024 ? titles.length : 1);
}

it("hides empty task lanes and shares the width as cards move between statuses", async () => {
    let groups = [group(1, "backlog"), group(2, "failed")];
    await openTasks(async () => ({ status: 200, payload: { data: groups } }));
    await expect.element(pane("Backlog")).toHaveTextContent("Feature 1");
    expectFilledLanes(document.querySelector(".kanban-board")!, ["Backlog", "Done"]);
    expect(document.querySelector('[aria-label="Todo"]')).toBeNull();
    expect(document.querySelector('[aria-label="In progress"]')).toBeNull();
    try {
        await page.viewport(390, 844);
        expectFilledLanes(document.querySelector(".kanban-board")!, ["Backlog", "Done"]);
    } finally {
        await page.viewport(1280, 800);
    }
    groups = [group(1, "todo")];
    await queryClient.invalidateQueries();
    await expect.element(pane("Todo")).toHaveTextContent("Feature 1");
    expectFilledLanes(document.querySelector(".kanban-board")!, ["Todo"]);
    groups = [];
    await queryClient.invalidateQueries();
    await expect.element(page.getByText("No tasks yet.", { exact: true })).toBeVisible();
    expect(document.querySelector(".kanban-board")).toBeNull();
});

it("hides empty subtask lanes, shares their width, and explains an empty board", async () => {
    let task = group(1, "running");
    task.tasks = [
        { ...task.tasks[0]!, status: "running" },
        { ...task.tasks[0]!, id: 2, position: 2, title: "Second step", status: "completed" },
    ];
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });
    await expect.element(pane("In progress")).toHaveTextContent("First step");
    expectFilledLanes(document.querySelector('[aria-label="Subtasks"] .kanban-board')!, [
        "In progress",
        "Done",
    ]);
    expect(document.querySelector('[aria-label="Todo"]')).toBeNull();
    task = { ...task, tasks: [task.tasks[1]!] };
    await queryClient.invalidateQueries();
    await expect.element(pane("In progress")).not.toBeInTheDocument();
    await expect.element(pane("Done")).toHaveTextContent("Second step");
    expectFilledLanes(document.querySelector('[aria-label="Subtasks"] .kanban-board')!, ["Done"]);
    task = { ...task, tasks: [] };
    await queryClient.invalidateQueries();
    await expect.element(pane("Subtasks")).toHaveTextContent("No subtasks yet.");
    expect(document.querySelector('[aria-label="Subtasks"] .kanban-board')).toBeNull();
});

it("explains a disabled extension and can retry a failed request", async () => {
    let disabled = true;
    await openTasks(async () =>
        disabled
            ? {
                  status: 409,
                  payload: { error: { code: "extension.disabled", message: "Disabled" } },
              }
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

it("links the group's pull request in the task properties", async () => {
    const task = { ...group(1, "settling"), pr_url: "https://github.com/nckrtl/orbit/pull/612" };
    const app = await openTasks(async (_, path) => ({
        status: 200,
        payload: { data: path === "/api/v1/task-groups" ? [task] : task },
    }));
    await app.router.navigate({ to: "/tasks/$id", params: { id: "1" } });

    const link = pane("Task").getByRole("link", { name: "nckrtl/orbit#612" });
    await expect.element(link).toBeVisible();
    await expect.element(link).toHaveAttribute("href", "https://github.com/nckrtl/orbit/pull/612");
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

it("hides the Instance Tasks tab and skips task queries when the extension starts disabled", async () => {
    const app = await openApp("/instances/1", { tasks: false });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: false,
            proxycli: false,
        });

    await expect
        .element(page.getByRole("tab", { name: "Overview", exact: true }))
        .toHaveAttribute("aria-selected", "true");
    await expect.element(page.getByRole("tab", { name: /^Tasks/ })).not.toBeInTheDocument();
    expect(queryClient.getQueryData(["task-groups"])).toBeUndefined();
    expect(app.gateway.requests.some((request) => request.path === "/api/v1/task-groups")).toBe(
        false,
    );
});

it("returns an Instance to Overview when tasks are disabled with task data cached", async () => {
    const owned = {
        ...group(90, "running"),
        title: "Cached Instance task",
        taskable_type: "instance",
        taskable_id: 1,
    };
    let taskRequests = 0;
    const app = await openApp("/instances/1", {
        wrapTransport: (inner) => (method, path, body) => {
            if (path === "/api/v1/task-groups") {
                taskRequests++;

                return Promise.resolve({ status: 200, payload: { data: [owned] } });
            }

            return inner(method, path, body);
        },
    });

    await expect.poll(() => queryClient.getQueryData(["task-groups"])).toEqual([owned]);
    await page.getByRole("tab", { name: /^Tasks/ }).click();
    await expect
        .element(page.getByRole("link", { name: "Open task: Cached Instance task" }))
        .toBeVisible();

    app.gateway.disableTasks();
    await queryClient.invalidateQueries({ queryKey: ["extensions"] });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: false,
            proxycli: false,
        });

    await expect.element(page.getByRole("tab", { name: /^Tasks/ })).not.toBeInTheDocument();
    await expect
        .element(page.getByRole("tab", { name: "Overview", exact: true }))
        .toHaveAttribute("aria-selected", "true");
    await expect.element(pane("Application log")).toBeVisible();
    expect(queryClient.getQueryData(["task-groups"])).toEqual([owned]);
    expect(document.body.textContent).not.toContain("Cached Instance task");
    expect(taskRequests).toBe(1);
});

it("does not load cached Tasks data on a direct route when the extension is disabled", async () => {
    const app = await openApp("/tasks", { tasks: false });
    await expect
        .poll(() => queryClient.getQueryData(["extensions"]))
        .toEqual({
            tasks: false,
            proxycli: false,
        });

    await expect
        .element(page.getByText("The tasks extension is disabled on this Gateway."))
        .toBeVisible();
    expect(queryClient.getQueryData(["task-groups"])).toBeUndefined();
    expect(app.gateway.requests.some((request) => request.path === "/api/v1/task-groups")).toBe(
        false,
    );
});
