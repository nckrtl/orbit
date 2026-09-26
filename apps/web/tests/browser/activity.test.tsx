import { afterEach, expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import type { Activity } from "../../src/api/activities";
import type { Transport } from "../../src/api/client";
import { queryClient } from "../../src/api/queryClient";
import { applyEvent } from "../../src/realtime/apply";
import { footer, openApp, pane } from "./app";

afterEach(async () => {
    await page.viewport(1280, 800);
});

function activity(id: number, patch: Partial<Activity> = {}): Activity {
    return {
        id,
        request_id: `0198e15c-bf97-7c23-8f1f-${id.toString(16).padStart(12, "0")}`,
        command: "node:add",
        caller_node_id: 1,
        target_node_id: 2,
        caller_ip: "10.44.0.1",
        status: "succeeded",
        duration_ms: 120,
        exit_code: 0,
        error_code: null,
        subject_type: null,
        subject_id: null,
        properties: { name: "spare" },
        occurred_at: new Date(Date.UTC(2026, 8, 20, 12, id % 60, id % 60)).toISOString(),
        ...patch,
    };
}

/** The page of rows `activity:list` would return for this request. */
function pageActivities(rows: readonly Activity[], path: string): Activity[] {
    const params = new URLSearchParams(path.split("?")[1] ?? "");
    const before = Number(params.get("before_id"));
    const caller = Number(params.get("caller_node_id"));
    const target = Number(params.get("target_node_id"));
    const status = params.get("status");
    const command = params.get("command");

    return rows
        .filter((row) => !Number.isInteger(before) || before < 1 || row.id < before)
        .filter((row) => status === null || row.status === status)
        .filter((row) => command === null || row.command === command)
        .filter((row) => !Number.isInteger(caller) || caller < 1 || row.caller_node_id === caller)
        .filter((row) => !Number.isInteger(target) || target < 1 || row.target_node_id === target)
        .sort((left, right) => right.id - left.id)
        .slice(0, 25);
}

function activityTransport(rows: { current: Activity[] }): Transport {
    return async (_method, path) => {
        const id = /^\/api\/v1\/activities\/(\d+)$/.exec(path)?.[1];
        if (id !== undefined) {
            const row = rows.current.find((candidate) => String(candidate.id) === id);

            return row === undefined
                ? {
                      status: 404,
                      payload: {
                          error: { code: "resource.not_found", message: "Activity was not found." },
                      },
                  }
                : { status: 200, payload: { data: row } };
        }

        return { status: 200, payload: { data: pageActivities(rows.current, path) } };
    };
}

it("lists Activity after Tasks and opens the newest rows", async () => {
    const app = await openApp("/activity", { proxycli: true });
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");

    await expect
        .poll(() => {
            const nav = (document.querySelector('[aria-label="Nav"]')?.textContent ?? "").replace(
                /\s+/g,
                " ",
            );

            return (
                nav.indexOf("Tasks") < nav.indexOf("Activity") &&
                nav.indexOf("Activity") < nav.indexOf("Quota")
            );
        })
        .toBe(true);
    await expect.element(footer()).toHaveTextContent("1-7 jump");
    await expect.element(pane("Activity")).toHaveTextContent("gateway");
    await expect.element(pane("Activity")).toHaveTextContent("beast");
    await expect.element(pane("Activity")).toHaveTextContent("8s");
    await page.screenshot({ path: "expected/activity-desktop.png" });

    await userEvent.keyboard("{ArrowRight}{Enter}{Enter}");
    await expect.poll(app.url).toContain("/activity/150");
    app.router.history.back();
    await expect.element(pane("Activity")).toBeVisible();
});

it("filters by status, command and node, and a new filter clears the older page", async () => {
    const app = await openApp("/activity?status=failed");
    await expect.element(page.getByRole("button", { name: "status: failed ▾" })).toBeVisible();
    await expect.element(pane("Activity")).toHaveTextContent("node:add");
    await expect.element(pane("Activity")).not.toHaveTextContent("instance:deploy");

    await page.getByRole("button", { name: "status: failed ▾" }).click();
    await expect.poll(app.url).toBe("/activity");
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");

    await page.getByRole("textbox", { name: "Command" }).fill("node:add");
    await userEvent.keyboard("{Enter}");
    await expect.poll(app.url).toContain("command=node");
    await expect.element(pane("Activity")).toHaveTextContent("node:add");
    await expect.element(pane("Activity")).not.toHaveTextContent("instance:deploy");

    await expect
        .poll(() =>
            [...document.querySelectorAll(".nav-row")].some(
                (row) => row.textContent?.includes("Nodes") && row.textContent?.includes("3"),
            ),
        )
        .toBe(true);
    await page.getByRole("button", { name: "caller: all ▾" }).click();
    await expect.element(page.getByRole("button", { name: "caller: gateway ▾" })).toBeVisible();
    await expect.poll(app.url).toContain("caller_node_id=1");
    await expect
        .poll(
            () =>
                app.gateway.requests
                    .filter((request) => request.path.startsWith("/api/v1/activities"))
                    .at(-1)?.path ?? "",
        )
        .toContain("caller_node_id=1");
    const filtered = app.gateway.requests
        .filter((request) => request.path.startsWith("/api/v1/activities"))
        .at(-1)?.path;
    expect(filtered).toContain("command=node");
    expect(filtered).not.toContain("before_id");

    await page.getByRole("button", { name: "target: all ▾" }).click();
    await expect.element(page.getByRole("button", { name: "target: gateway ▾" })).toBeVisible();
    await expect.poll(app.url).toContain("target_node_id=1");
    await expect.poll(app.url).not.toContain("before_id");
});

it("loads the older page from its control and from scrolling the list", async () => {
    const app = await openApp("/activity");
    await expect.element(page.getByRole("button", { name: "Older rows" })).toBeVisible();
    await page.getByRole("button", { name: "Older rows" }).click();

    await expect
        .poll(() => app.gateway.requests.some((request) => request.path.includes("before_id=101")))
        .toBe(true);
    await expect.element(pane("Activity")).toHaveTextContent("node:list");
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");
    await expect.poll(app.url).not.toContain("before_id");

    await page.viewport(1280, 420);
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");
    const body = document.querySelector('[data-pane="activity"] .frame-body');
    if (!(body instanceof HTMLElement)) throw new Error("The activity list does not scroll.");
    expect(body.scrollHeight).toBeGreaterThan(body.clientHeight);
    body.scrollTop = body.scrollHeight;
    body.dispatchEvent(new Event("scroll"));
    await expect
        .poll(() => app.gateway.requests.some((request) => request.path.includes("before_id=51")))
        .toBe(true);
    await expect.poll(app.url).not.toContain("before_id");
});

it("shows a new row and a finished row when their notices arrive", async () => {
    const rows = {
        current: [
            activity(3, {
                command: "process:start",
                status: "running",
                duration_ms: null,
                exit_code: null,
            }),
            activity(2, { command: "node:add", status: "succeeded" }),
            activity(1, {
                command: "app:list",
                status: "failed",
                error_code: "validation.failed",
                exit_code: 1,
            }),
        ],
    };
    const activityPaths: string[] = [];
    await openApp("/activity?status=running", {
        wrapTransport: (inner) => (method, path, body) => {
            if (path.startsWith("/api/v1/activities")) {
                activityPaths.push(path);

                return activityTransport(rows)(method, path, body);
            }

            return inner(method, path, body);
        },
    });
    await expect.element(pane("Activity")).toHaveTextContent("process:start");
    await expect.element(pane("Activity")).not.toHaveTextContent("node:add");

    rows.current = rows.current.map((row) =>
        row.id === 3
            ? {
                  ...row,
                  status: "failed",
                  error_code: "activity.interrupted",
                  duration_ms: null,
                  exit_code: null,
              }
            : row,
    );
    applyEvent(queryClient, {
        type: "activity.updated",
        id: 3,
        at: "2026-09-20T12:00:03Z",
        data: {
            id: 3,
            command: "process:start",
            status: "failed",
            error_code: "activity.interrupted",
        },
    });
    await expect.element(pane("Activity")).toHaveTextContent("process:start");
    await expect.element(pane("Activity")).toHaveTextContent("activity.interrupted");
    const before = activityPaths.length;

    rows.current = [
        activity(4, {
            command: "instance:deploy",
            status: "running",
            duration_ms: null,
            exit_code: null,
        }),
        ...rows.current,
    ];
    applyEvent(queryClient, {
        type: "activity.created",
        id: 4,
        at: "2026-09-20T12:00:04Z",
        data: { id: 4, command: "instance:deploy", status: "running" },
    });
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");
    expect(activityPaths).toHaveLength(before);
    await page.screenshot({ path: "expected/activity-live.png" });
});

it("opens a row on its own route, including the stored properties, and back keeps the filters", async () => {
    const app = await openApp("/activity?status=failed&command=node:add");
    await expect.element(pane("Activity")).toHaveTextContent("node.ssh_host_fingerprint_required");
    await pane("Activity")
        .getByRole("row", { name: /node.ssh_host_fingerprint_required/ })
        .click();

    await expect.poll(app.url).toContain("/activity/148");
    await expect.poll(app.url).toContain("status=failed");
    await expect.element(pane("Activity")).toHaveTextContent("node.ssh_host_fingerprint_required");
    await expect.element(pane("Activity")).toHaveTextContent("10.44.0.1");
    await expect.element(pane("Properties")).toHaveTextContent("[REDACTED]");
    await expect.element(pane("Properties")).toHaveTextContent("spare");
    expect(app.gateway.requests.some((request) => request.path === "/api/v1/activities/148")).toBe(
        true,
    );

    app.router.history.back();
    await expect.poll(app.url).toContain("/activity?");
    await expect.poll(app.url).toContain("status=failed");
    await expect.poll(app.url).toContain("command=");
    await expect.element(pane("Activity")).toHaveTextContent("node:add");
});

it("keeps the menu, filters, older rows and detail usable on a phone", async () => {
    await page.viewport(390, 800);
    const app = await openApp("/", { proxycli: true });
    await expect
        .element(page.getByRole("button", { name: "Toggle navigation menu" }))
        .toBeVisible();
    await page.getByRole("button", { name: "Toggle navigation menu" }).click();

    await expect
        .poll(() => {
            const menu = document.querySelector("[data-mobile-menu]")?.textContent ?? "";

            return (
                menu.indexOf("Tasks") < menu.indexOf("Activity") &&
                menu.indexOf("Activity") < menu.indexOf("Quota")
            );
        })
        .toBe(true);
    await page
        .getByRole("region", { name: "Nav" })
        .first()
        .getByText("Activity", { exact: true })
        .click();
    await expect.poll(app.url).toBe("/activity");

    const filters = document.querySelector("[data-activity-filters]");
    if (!(filters instanceof HTMLElement)) throw new Error("Activity filters are missing.");
    const buttons = [...filters.querySelectorAll("button")];
    const tops = buttons.map((button) => button.getBoundingClientRect().top);
    expect(tops.length).toBeGreaterThanOrEqual(3);
    expect(tops[1] ?? 0).toBeGreaterThan(tops[0] ?? 0);
    expect(buttons[0]?.getBoundingClientRect().width ?? 0).toBeGreaterThan(200);
    await expect.element(page.getByRole("button", { name: "Older rows" })).toBeVisible();
    await page.screenshot({ path: "expected/activity-phone.png" });

    await expect.element(page.getByRole("button", { name: "Open activity 150" })).toBeVisible();
    await page.getByRole("button", { name: "Open activity 150" }).click();
    await expect.poll(app.url).toContain("/activity/150");
    const frames = [...document.querySelectorAll("main .frame")].map((frame) =>
        frame.getBoundingClientRect(),
    );
    expect(frames.length).toBeGreaterThanOrEqual(2);
    expect((frames[1]?.top ?? 0) > (frames[0]?.top ?? 0) + (frames[0]?.height ?? 0) - 8).toBe(true);
    await expect.element(pane("Properties")).toBeVisible();
    await page.screenshot({ path: "expected/activity-phone-detail.png" });

    app.router.history.back();
    await expect.poll(app.url).toBe("/activity");
    await expect.element(page.getByRole("button", { name: "Older rows" })).toBeVisible();
});

function fits(name: string): HTMLElement {
    const value = document.querySelector(`[data-activity-value="${name}"]`);
    if (!(value instanceof HTMLElement)) throw new Error(`${name} is not on the detail.`);

    return value;
}

it("shows a long path, the request id, and empty collections on a phone", async () => {
    await page.viewport(390, 800);
    const path =
        "/srv/app/storage/logs/review160-long-property-that-an-operator-needs-to-read-in-full.log";
    const requestId = "48f4f397-e965-494f-a980-5a672b94f75e";
    const app = await openApp("/activity/1", {
        wrapTransport: (inner) => (method, requestPath, body) => {
            if (requestPath === "/api/v1/activities/1") {
                return Promise.resolve({
                    status: 200,
                    payload: {
                        data: activity(1, {
                            command: "review160:live-final",
                            request_id: requestId,
                            properties: {
                                token: "[REDACTED]",
                                path,
                                nested: { empty: [] },
                                meta: {},
                            },
                        }),
                    },
                });
            }
            if (requestPath === "/api/v1/activities/2") {
                return Promise.resolve({
                    status: 200,
                    payload: {
                        data: activity(2, { properties: { items: [], meta: {} } }),
                    },
                });
            }

            return inner(method, requestPath, body);
        },
    });
    await expect.element(pane("Properties")).toHaveTextContent(path);
    await expect.element(pane("Activity")).toHaveTextContent(requestId);
    await expect.element(pane("Properties")).toHaveTextContent("[REDACTED]");

    const pathValue = fits("path");
    const requestValue = fits("Request");
    expect(pathValue.textContent).toBe(path);
    expect(requestValue.textContent).toBe(requestId);
    expect(pathValue.scrollWidth).toBeLessThanOrEqual(pathValue.clientWidth + 1);
    expect(requestValue.scrollWidth).toBeLessThanOrEqual(requestValue.clientWidth + 1);
    expect(pathValue.getBoundingClientRect().right).toBeLessThanOrEqual(390);
    expect(requestValue.getBoundingClientRect().right).toBeLessThanOrEqual(390);
    expect(pathValue.clientHeight).toBeGreaterThan(28);
    expect(fits("nested.empty").textContent).toBe("[]");
    expect(fits("meta").textContent).toBe("{}");

    await app.router.navigate({ to: "/activity/$id", params: { id: "2" } });
    await expect
        .poll(() => document.querySelector('[data-activity-value="items"]')?.textContent)
        .toBe("[]");
    await expect.element(pane("Properties")).not.toHaveTextContent("None");
    expect(fits("meta").textContent).toBe("{}");
});
