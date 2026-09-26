import { afterEach, expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import { rebuildActivityList, type Activity } from "../../src/api/activities";
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

    const limitParam = Number(params.get("limit"));
    const limit = Number.isInteger(limitParam) && limitParam >= 1 ? limitParam : 25;

    return rows
        .filter((row) => !Number.isInteger(before) || before < 1 || row.id < before)
        .filter((row) => status === null || row.status === status)
        .filter((row) => command === null || row.command === command)
        .filter((row) => !Number.isInteger(caller) || caller < 1 || row.caller_node_id === caller)
        .filter((row) => !Number.isInteger(target) || target < 1 || row.target_node_id === target)
        .sort((left, right) => right.id - left.id)
        .slice(0, limit);
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
    expect(page.getByRole("button", { name: "Filters, 1 active" }).query()).toBeNull();
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

function activityLog(): HTMLElement {
    const found = document.querySelector("[data-activity-log]");
    if (!(found instanceof HTMLElement)) throw new Error("Activity log does not scroll.");

    return found;
}

/** Rows a reader can actually see in the log, not ones the virtualizer keeps just off screen. */
function visibleLogRows(): HTMLElement[] {
    const log = activityLog();
    const logBox = log.getBoundingClientRect();
    const top = Math.max(logBox.top, 0);
    const bottom = Math.min(logBox.bottom, window.innerHeight);

    return [...log.querySelectorAll<HTMLElement>("[data-activity-id]")].filter((row) => {
        const rect = row.getBoundingClientRect();
        const visible = Math.min(rect.bottom, bottom) - Math.max(rect.top, top);

        return visible >= 20;
    });
}

function shellContentBox(): { top: number; right: number; bottom: number; left: number } {
    const element = document.querySelector("[data-app-shell]");
    if (!(element instanceof HTMLElement)) throw new Error("app shell missing");
    const rect = element.getBoundingClientRect();
    const style = getComputedStyle(element);

    return {
        top: rect.top + Number.parseFloat(style.paddingTop),
        right: rect.right - Number.parseFloat(style.paddingRight),
        bottom: rect.bottom - Number.parseFloat(style.paddingBottom),
        left: rect.left + Number.parseFloat(style.paddingLeft),
    };
}

function expectInside(
    element: HTMLElement,
    bounds: { top: number; right: number; bottom: number; left: number },
): void {
    const rect = element.getBoundingClientRect();
    expect(rect.top).toBeGreaterThanOrEqual(bounds.top - 0.5);
    expect(rect.left).toBeGreaterThanOrEqual(bounds.left - 0.5);
    expect(rect.right).toBeLessThanOrEqual(bounds.right + 0.5);
    expect(rect.bottom).toBeLessThanOrEqual(bounds.bottom + 0.5);
}

function visibleAnchor(): HTMLElement {
    const log = activityLog();
    const head = log.querySelector("[data-head]");
    const top =
        log.getBoundingClientRect().top + (head instanceof HTMLElement ? head.offsetHeight : 0);
    const found = [...log.querySelectorAll<HTMLElement>("[data-activity-id]")].find(
        (row) => row.getBoundingClientRect().bottom > top + 1,
    );
    if (found === undefined) throw new Error("No activity row is visible.");

    return found;
}

/** Scrolls until the log has loaded its last page. */
async function scrollToEnd(): Promise<void> {
    await expect
        .poll(() => {
            const log = activityLog();
            log.scrollTop = log.scrollHeight;
            log.dispatchEvent(new Event("scroll"));

            return document.querySelector("[data-activity-end]") !== null;
        })
        .toBe(true);
}

it("loads older rows as the log scrolls and then shows the end", async () => {
    const app = await openApp("/activity");
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");
    expect(document.querySelector("[data-activity-end]")).toBeNull();
    expect(page.getByRole("button", { name: "Older rows" }).query()).toBeNull();

    const log = activityLog();
    expect(log.scrollHeight).toBeGreaterThan(log.clientHeight);
    log.scrollTop = Math.max(0, log.scrollHeight - log.clientHeight - 40);
    log.dispatchEvent(new Event("scroll"));
    await expect
        .poll(() => app.gateway.requests.some((request) => request.path.includes("before_id=101")))
        .toBe(true);
    await expect.poll(app.url).not.toContain("before_id");

    await scrollToEnd();
    await expect.poll(() => document.querySelector('[data-activity-id="1"]') !== null).toBe(true);
    const reads = () =>
        app.gateway.requests.filter((request) => request.path.startsWith("/api/v1/activities"))
            .length;
    const done = reads();
    log.scrollTop = log.scrollHeight;
    log.dispatchEvent(new Event("scroll"));
    await expect.poll(reads).toBe(done);
    await expect.poll(app.url).not.toContain("before_id");
    await page.screenshot({ path: "expected/activity-end.png" });
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
    expect(document.querySelector("[data-activity-new]")).toBeNull();
    expect(activityPaths).toHaveLength(before);
    await page.screenshot({ path: "expected/activity-live.png" });
});

it("keeps the visible rows still and offers N new when a row arrives below the top", async () => {
    const rows = {
        current: Array.from({ length: 60 }, (_, index) =>
            activity(60 - index, { command: index === 0 ? "node:list" : "app:list" }),
        ),
    };
    await openApp("/activity", {
        wrapTransport: (inner) => (method, path, body) => {
            if (path.startsWith("/api/v1/activities")) {
                return activityTransport(rows)(method, path, body);
            }

            return inner(method, path, body);
        },
    });
    await expect.element(pane("Activity")).toHaveTextContent("node:list");
    const log = activityLog();
    log.scrollTop = 240;
    log.dispatchEvent(new Event("scroll"));
    await expect.poll(() => log.scrollTop).toBeGreaterThan(200);
    await settle();
    const anchor = visibleAnchor();
    const anchorId = anchor.dataset.activityId ?? "";
    const top = anchor.getBoundingClientRect().top;

    rows.current = [
        activity(62, { command: "review:arrived-later" }),
        activity(61, { command: "review:arrived" }),
        ...rows.current,
    ];
    applyEvent(queryClient, {
        type: "activity.created",
        id: 61,
        at: "2026-09-20T12:00:04Z",
        data: { id: 61, command: "review:arrived", status: "succeeded" },
    });
    applyEvent(queryClient, {
        type: "activity.created",
        id: 62,
        at: "2026-09-20T12:00:05Z",
        data: { id: 62, command: "review:arrived-later", status: "succeeded" },
    });
    await expect.element(page.getByRole("button", { name: "2 new" })).toBeVisible();
    await expect
        .poll(() => {
            const again = document.querySelector(`[data-activity-id="${anchorId}"]`);

            return again instanceof HTMLElement
                ? Math.abs(again.getBoundingClientRect().top - top)
                : 999;
        })
        .toBeLessThan(2);
    expect(activityLog().scrollTop).toBeGreaterThan(200);
    await page.screenshot({ path: "expected/activity-new.png" });

    log.scrollTop = 0;
    log.dispatchEvent(new Event("scroll"));
    await expect.poll(() => document.querySelector("[data-activity-new]")).toBeNull();
    await expect.element(pane("Activity")).toHaveTextContent("review:arrived-later");

    log.scrollTop = 240;
    log.dispatchEvent(new Event("scroll"));
    await expect.poll(() => log.scrollTop).toBeGreaterThan(200);
    rows.current = [activity(63, { command: "review:one-more" }), ...rows.current];
    applyEvent(queryClient, {
        type: "activity.created",
        id: 63,
        at: "2026-09-20T12:00:06Z",
        data: { id: 63, command: "review:one-more", status: "succeeded" },
    });
    await page.getByRole("button", { name: "1 new" }).click();
    await expect.poll(() => activityLog().scrollTop).toBeLessThan(2);
    await expect.element(pane("Activity")).toHaveTextContent("review:one-more");
    await expect.poll(() => document.querySelector("[data-activity-new]")).toBeNull();
});

it("returns to the same scroll position after opening a row", async () => {
    const app = await openApp("/activity");
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");
    const log = activityLog();
    log.scrollTop = 320;
    log.dispatchEvent(new Event("scroll"));
    await expect.poll(() => log.scrollTop).toBeGreaterThan(280);
    const anchor = visibleAnchor();
    const id = anchor.dataset.activityId ?? "";
    const top = anchor.getBoundingClientRect().top;
    const scrollTop = log.scrollTop;
    anchor.dispatchEvent(new MouseEvent("mousedown", { bubbles: true, button: 0 }));

    await expect.element(pane("Properties")).toBeVisible();
    app.router.history.back();
    await expect.poll(() => app.url().split("?")[0]).toBe("/activity");
    await expect.poll(() => Math.abs(activityLog().scrollTop - scrollTop)).toBeLessThan(2);
    await expect
        .poll(() => {
            const again = document.querySelector(`[data-activity-id="${id}"]`);

            return again instanceof HTMLElement
                ? Math.abs(again.getBoundingClientRect().top - top)
                : 999;
        })
        .toBeLessThan(2);
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

    await expect.element(page.getByRole("button", { name: "Filters", exact: true })).toBeVisible();
    expect(document.querySelector("[data-activity-filters]")).toBeNull();
    const crumb = document.querySelector("[aria-label='Breadcrumb']");
    const firstRow = document.querySelector("[data-activity-id]");
    const filtersButton = page.getByRole("button", { name: "Filters", exact: true }).query();
    if (
        !(crumb instanceof HTMLElement) ||
        !(firstRow instanceof HTMLElement) ||
        !(filtersButton instanceof HTMLElement)
    ) {
        throw new Error("Activity header or log is missing.");
    }
    expect(
        Math.abs(filtersButton.getBoundingClientRect().top - crumb.getBoundingClientRect().top),
    ).toBeLessThan(12);
    const gap = firstRow.getBoundingClientRect().top - crumb.getBoundingClientRect().bottom;
    expect(gap).toBeGreaterThan(0);
    expect(gap).toBeLessThan(80);
    expect(page.getByRole("button", { name: "Older rows" }).query()).toBeNull();
    await expect.element(page.getByRole("button", { name: "Open activity 150" })).toBeVisible();
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
    await expect.element(page.getByRole("button", { name: "Open activity 150" })).toBeVisible();
});

it("scrolls, marks the end, and keeps its place on a phone", async () => {
    await page.viewport(390, 800);
    const rows = {
        current: Array.from({ length: 80 }, (_, index) =>
            activity(80 - index, { command: index === 79 ? "review:oldest" : "node:list" }),
        ),
    };
    const activityPaths: string[] = [];
    const app = await openApp("/activity", {
        wrapTransport: (inner) => (method, path, body) => {
            if (path.startsWith("/api/v1/activities")) {
                activityPaths.push(path);

                return activityTransport(rows)(method, path, body);
            }

            return inner(method, path, body);
        },
    });
    await expect.element(page.getByRole("button", { name: "Open activity 80" })).toBeVisible();
    const log = activityLog();
    expect(log.scrollHeight).toBeGreaterThan(log.clientHeight);
    await expect
        .poll(() => {
            const scroller = activityLog();
            scroller.scrollTop = Math.max(0, scroller.scrollHeight - scroller.clientHeight - 30);
            scroller.dispatchEvent(new Event("scroll"));

            return activityPaths.some((path) => path.includes("before_id="));
        })
        .toBe(true);
    await scrollToEnd();
    await expect.poll(() => document.querySelector('[data-activity-id="1"]') !== null).toBe(true);
    await expect.element(page.getByText("End of the log.")).toBeVisible();
    await expect.poll(app.url).not.toContain("before_id");

    log.scrollTop = 0;
    log.dispatchEvent(new Event("scroll"));
    rows.current = [
        activity(81, { command: "review:phone-live", status: "running" }),
        ...rows.current,
    ];
    applyEvent(queryClient, {
        type: "activity.created",
        id: 81,
        at: "2026-09-20T12:01:00Z",
        data: { id: 81, command: "review:phone-live", status: "running" },
    });
    await expect.element(page.getByRole("button", { name: "Open activity 81" })).toBeVisible();
    expect(document.querySelector("[data-activity-new]")).toBeNull();

    log.scrollTop = 360;
    log.dispatchEvent(new Event("scroll"));
    await expect.poll(() => log.scrollTop).toBeGreaterThan(300);
    await settle();
    const anchor = visibleAnchor();
    const anchorId = anchor.dataset.activityId ?? "";
    const top = anchor.getBoundingClientRect().top;
    const scrollTop = log.scrollTop;
    rows.current = [activity(82, { command: "review:phone-new" }), ...rows.current];
    applyEvent(queryClient, {
        type: "activity.created",
        id: 82,
        at: "2026-09-20T12:01:01Z",
        data: { id: 82, command: "review:phone-new", status: "succeeded" },
    });
    await expect.element(page.getByRole("button", { name: "1 new" })).toBeVisible();
    await expect
        .poll(() => {
            const row = document.querySelector(`[data-activity-id="${anchorId}"]`);

            return row instanceof HTMLElement
                ? Math.abs(row.getBoundingClientRect().top - top)
                : 999;
        })
        .toBeLessThan(2);
    await page.screenshot({ path: "expected/activity-phone-new.png" });
    await page.getByRole("button", { name: "1 new" }).click();
    await expect.poll(() => activityLog().scrollTop).toBeLessThan(2);
    await expect.element(page.getByRole("button", { name: "Open activity 82" })).toBeVisible();

    const again = activityLog();
    again.scrollTop = scrollTop;
    again.dispatchEvent(new Event("scroll"));
    await expect.poll(() => again.scrollTop).toBeGreaterThan(scrollTop - 40);
    const card = visibleAnchor();
    const cardId = card.dataset.activityId ?? "";
    const cardTop = card.getBoundingClientRect().top;
    const cardScroll = again.scrollTop;
    card.dispatchEvent(new MouseEvent("click", { bubbles: true, button: 0 }));
    await expect.element(pane("Properties")).toBeVisible();
    app.router.history.back();
    await expect.poll(() => app.url().split("?")[0]).toBe("/activity");
    await expect.poll(() => Math.abs(activityLog().scrollTop - cardScroll)).toBeLessThan(2);
    await expect
        .poll(() => {
            const restored = document.querySelector(`[data-activity-id="${cardId}"]`);

            return restored instanceof HTMLElement
                ? Math.abs(restored.getBoundingClientRect().top - cardTop)
                : 999;
        })
        .toBeLessThan(2);
});

const settle = async () => {
    for (let frame = 0; frame < 4; frame += 1) await new Promise(requestAnimationFrame);
};

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

it("retries an older page when the reader asks again", async () => {
    let failing = true;
    let attempts = 0;
    await openApp("/activity", {
        wrapTransport: (inner) => (method, path, body) => {
            if (path.includes("before_id=")) {
                attempts += 1;
                if (failing) {
                    return Promise.resolve({
                        status: 503,
                        payload: { error: { code: "temporary", message: "Temporary failure" } },
                    });
                }
            }

            return inner(method, path, body);
        },
    });
    await expect.element(pane("Activity")).toHaveTextContent("instance:deploy");
    activityLog().scrollTop = activityLog().scrollHeight;
    activityLog().dispatchEvent(new Event("scroll"));
    const retry = page.getByRole("button", { name: "Could not load older activity. Try again" });
    await expect.element(retry).toBeVisible();
    const before = attempts;
    failing = false;
    await retry.click();
    await expect.poll(() => attempts).toBeGreaterThan(before);
    await expect
        .poll(() => document.querySelector("[data-activity-end], [data-activity-id]"))
        .not.toBeNull();
});

it("keeps phone filters when nothing matches, and clearing them shows rows", async () => {
    await page.viewport(390, 800);
    const app = await openApp("/activity?command=review:does-not-exist");
    await expect.element(pane("Activity")).toHaveTextContent("No activity.");
    await expect.element(page.getByRole("button", { name: "Filters, 1 active" })).toBeVisible();
    expect(document.querySelector("[data-activity-filters]")).toBeNull();
    await page.getByRole("button", { name: "Filters, 1 active" }).click();
    expect(document.querySelector("[data-activity-filters]")).not.toBeNull();
    const command = page.getByRole("textbox", { name: "Command" });
    await command.fill("");
    await userEvent.keyboard("{Enter}");
    await expect.poll(app.url).toBe("/activity");
    await expect.element(page.getByRole("button", { name: "Filters", exact: true })).toBeVisible();
    await expect.element(pane("Activity")).not.toHaveTextContent("No activity.");
    await expect.element(page.getByRole("button", { name: "Open activity 150" })).toBeVisible();
});

it("opens the phone filter sheet from the header and keeps the desktop bar", async () => {
    await page.viewport(390, 800);
    const app = await openApp("/activity");
    await expect.element(page.getByRole("button", { name: "Open activity 150" })).toBeVisible();
    await expect.poll(() => visibleLogRows().length).toBeGreaterThanOrEqual(8);
    expect(document.querySelector("[data-activity-filters]")).toBeNull();
    const crumb = document.querySelector("[aria-label='Breadcrumb']");
    const firstRow = document.querySelector("[data-activity-id]");
    if (!(crumb instanceof HTMLElement) || !(firstRow instanceof HTMLElement)) {
        throw new Error("Activity header or log is missing.");
    }
    const gap = firstRow.getBoundingClientRect().top - crumb.getBoundingClientRect().bottom;
    expect(gap).toBeGreaterThan(0);
    expect(gap).toBeLessThan(80);

    await page.getByRole("button", { name: "Filters", exact: true }).click();
    const sheet = document.querySelector("[data-activity-filter-sheet]");
    if (!(sheet instanceof HTMLElement)) throw new Error("Filter sheet is missing.");
    await expect.element(page.getByRole("dialog", { name: "Filters" })).toBeVisible();
    const filters = sheet.querySelector("[data-activity-filters]");
    if (!(filters instanceof HTMLElement)) throw new Error("Sheet filters are missing.");
    const buttons = [...filters.querySelectorAll("button")];
    const tops = buttons.map((button) => button.getBoundingClientRect().top);
    expect(tops.length).toBeGreaterThanOrEqual(3);
    expect(tops[1] ?? 0).toBeGreaterThan(tops[0] ?? 0);
    expect(buttons[0]?.getBoundingClientRect().width ?? 0).toBeGreaterThan(200);
    await expect.element(page.getByRole("button", { name: "status: all ▾" })).toBeVisible();
    await expect.element(page.getByRole("textbox", { name: "Command" })).toBeVisible();
    await expect.element(page.getByRole("button", { name: "caller: all ▾" })).toBeVisible();
    await expect.element(page.getByRole("button", { name: "target: all ▾" })).toBeVisible();
    await expect.element(page.getByRole("button", { name: "Clear", exact: true })).toBeVisible();
    const done = page.getByRole("button", { name: "Done" }).query();
    if (!(done instanceof HTMLElement)) throw new Error("Done is missing.");
    expect(done.getBoundingClientRect().top).toBeGreaterThan(tops.at(-1) ?? 0);
    await page.screenshot({ path: "expected/activity-phone-filters.png" });

    await page.getByRole("button", { name: "status: all ▾" }).click();
    await expect.poll(app.url).toContain("status=running");
    await expect.element(page.getByRole("button", { name: "Filters, 1 active" })).toBeVisible();

    await page.getByRole("textbox", { name: "Command" }).fill("node:add");
    await userEvent.keyboard("{Enter}");
    await expect.poll(app.url).toContain("command=node");
    await expect.element(page.getByRole("button", { name: "Filters, 2 active" })).toBeVisible();

    await page.getByRole("button", { name: "caller: all ▾" }).click();
    await expect.element(page.getByRole("button", { name: "caller: gateway ▾" })).toBeVisible();
    await expect.poll(app.url).toContain("caller_node_id=1");
    await expect.element(page.getByRole("button", { name: "Filters, 3 active" })).toBeVisible();

    await page.getByRole("button", { name: "target: all ▾" }).click();
    await expect.element(page.getByRole("button", { name: "target: gateway ▾" })).toBeVisible();
    await expect.poll(app.url).toContain("target_node_id=1");
    await expect.element(page.getByRole("button", { name: "Filters, 4 active" })).toBeVisible();

    await page.getByRole("button", { name: "Done" }).click();
    await expect.poll(() => document.querySelector("[data-activity-filter-sheet]")).toBeNull();
    await expect.poll(app.url).toContain("status=running");
    await expect.poll(app.url).toContain("command=node");
    await expect.element(page.getByRole("button", { name: "Filters, 4 active" })).toBeVisible();

    await page.getByRole("button", { name: "Filters, 4 active" }).click();
    await expect.element(page.getByRole("button", { name: "status: running ▾" })).toBeVisible();
    await page.getByRole("button", { name: "Clear", exact: true }).click();
    await expect.poll(app.url).toBe("/activity");
    await expect.element(page.getByRole("button", { name: "Filters", exact: true })).toBeVisible();
    await expect.element(page.getByRole("button", { name: "status: all ▾" })).toBeVisible();
    await expect.element(page.getByRole("button", { name: "caller: all ▾" })).toBeVisible();
    await expect.element(page.getByRole("button", { name: "target: all ▾" })).toBeVisible();

    await userEvent.keyboard("{Escape}");
    await expect.poll(() => document.querySelector("[data-activity-filter-sheet]")).toBeNull();

    document.documentElement.style.setProperty("--safe-area-inset-top", "47px");
    document.documentElement.style.setProperty("--safe-area-inset-right", "21px");
    document.documentElement.style.setProperty("--safe-area-inset-bottom", "34px");
    document.documentElement.style.setProperty("--safe-area-inset-left", "18px");
    try {
        await expect
            .poll(() => {
                const shell = document.querySelector("[data-app-shell]");

                return shell instanceof HTMLElement ? getComputedStyle(shell).paddingTop : "";
            })
            .toBe("47px");
        await page.getByRole("button", { name: "Filters", exact: true }).click();
        const padded = document.querySelector("[data-activity-filter-sheet]");
        const frame = padded?.querySelector("section");
        if (!(padded instanceof HTMLElement) || !(frame instanceof HTMLElement)) {
            throw new Error("Filter sheet is missing inside the safe area.");
        }
        const safe = shellContentBox();
        expectInside(padded, safe);
        expectInside(frame, safe);
        await page.getByRole("button", { name: "Done" }).click();
    } finally {
        for (const edge of ["top", "right", "bottom", "left"]) {
            document.documentElement.style.removeProperty(`--safe-area-inset-${edge}`);
        }
    }

    await page.viewport(1280, 800);
    await expect.element(page.getByRole("button", { name: "status: all ▾" })).toBeVisible();
    expect(page.getByRole("button", { name: "Filters", exact: true }).query()).toBeNull();
    expect(document.querySelector("[data-activity-filter-sheet]")).toBeNull();
    expect(document.querySelector("[data-activity-filters]")).not.toBeNull();
});

function commandDraft(): string {
    const input = page.getByRole("textbox", { name: "Command" }).query();
    if (!(input instanceof HTMLInputElement)) throw new Error("Command field is missing.");

    return input.value;
}

function pressTab(shift = false): void {
    document.activeElement?.dispatchEvent(
        new KeyboardEvent("keydown", {
            key: "Tab",
            code: "Tab",
            shiftKey: shift,
            bubbles: true,
            cancelable: true,
        }),
    );
}

it("discards an unfinished command when Clear is used", async () => {
    await page.viewport(390, 800);
    const app = await openApp("/activity");
    await expect.element(page.getByRole("button", { name: "Filters", exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Filters", exact: true }).click();
    await page.getByRole("textbox", { name: "Command" }).fill("node:add");
    expect(commandDraft()).toBe("node:add");
    expect(app.url()).toBe("/activity");

    await page.getByRole("button", { name: "Clear", exact: true }).click();
    expect(commandDraft()).toBe("");
    expect(app.url()).toBe("/activity");
    await page.getByRole("button", { name: "Done" }).click();
    await expect.poll(() => document.querySelector("[data-activity-filter-sheet]")).toBeNull();
    expect(app.url()).toBe("/activity");

    await app.router.navigate({ to: "/activity", search: { status: "failed" } });
    await expect.element(page.getByRole("button", { name: "Filters, 1 active" })).toBeVisible();
    await page.getByRole("button", { name: "Filters, 1 active" }).click();
    await page.getByRole("textbox", { name: "Command" }).fill("node:add");
    expect(commandDraft()).toBe("node:add");
    await page.getByRole("button", { name: "Clear", exact: true }).click();
    expect(commandDraft()).toBe("");
    await expect.poll(app.url).toBe("/activity");
    await page.getByRole("button", { name: "Done" }).click();
    await expect.poll(() => document.querySelector("[data-activity-filter-sheet]")).toBeNull();
    expect(app.url()).toBe("/activity");
});

it("keeps Tab inside the phone filter sheet and restores the Filters button", async () => {
    await page.viewport(390, 800);
    await openApp("/activity");
    const openFilters = page.getByRole("button", { name: "Filters", exact: true });
    await openFilters.click();
    const sheet = document.querySelector("[data-activity-filter-sheet]");
    const trigger = openFilters.query();
    const done = page.getByRole("button", { name: "Done" }).query();
    const first = page.getByRole("button", { name: "status: all \u25be" }).query();
    if (
        !(sheet instanceof HTMLElement) ||
        !(trigger instanceof HTMLElement) ||
        !(done instanceof HTMLElement) ||
        !(first instanceof HTMLElement)
    ) {
        throw new Error("Filter sheet controls are missing.");
    }
    expect(trigger.closest("[inert]")).not.toBeNull();

    done.focus();
    pressTab();
    expect(document.activeElement).toBe(first);
    pressTab(true);
    expect(document.activeElement).toBe(done);

    done.focus();
    await userEvent.keyboard("{Tab}");
    expect(sheet.contains(document.activeElement)).toBe(true);
    expect(document.activeElement).not.toBe(document.body);

    await page.getByRole("button", { name: "Done" }).click();
    await expect.poll(() => document.activeElement).toBe(trigger);
    expect(document.querySelector("[data-activity-filter-sheet]")).toBeNull();
    expect(trigger.closest("[inert]")).toBeNull();

    await openFilters.click();
    await userEvent.keyboard("{Escape}");
    await expect.poll(() => document.activeElement).toBe(trigger);
    expect(document.querySelector("[data-activity-filter-sheet]")).toBeNull();

    await openFilters.click();
    const backdrop = document.querySelector("[data-activity-filter-sheet]");
    if (!(backdrop instanceof HTMLElement)) throw new Error("Filter sheet is missing.");
    backdrop.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
    await expect.poll(() => document.activeElement).toBe(trigger);
    expect(document.querySelector("[data-activity-filter-sheet]")).toBeNull();
});

it("restores a deep phone position when cards are taller than the estimate", async () => {
    await page.viewport(390, 800);
    const rows = {
        current: Array.from({ length: 49 }, (_, index) =>
            activity(49 - index, {
                status: "failed",
                error_code: "instance.provisioning_health_check_failed",
            }),
        ),
    };
    const app = await openApp("/activity", {
        wrapTransport: (inner) => (method, path, body) =>
            path.startsWith("/api/v1/activities")
                ? activityTransport(rows)(method, path, body)
                : inner(method, path, body),
    });
    await expect.element(page.getByRole("button", { name: "Open activity 49" })).toBeVisible();
    for (let step = 1; step <= 10; step += 1) {
        activityLog().scrollTop = step * 300;
        activityLog().dispatchEvent(new Event("scroll"));
        await settle();
    }
    const anchor = visibleAnchor();
    const id = anchor.dataset.activityId ?? "";
    const top = anchor.getBoundingClientRect().top;
    anchor.dispatchEvent(new MouseEvent("click", { bubbles: true, button: 0 }));
    await expect.element(pane("Properties")).toBeVisible();
    app.router.history.back();
    await expect.poll(() => app.url().split("?")[0]).toBe("/activity");
    await expect
        .poll(() => {
            const row = document.querySelector(`[data-activity-id="${id}"]`);

            return row instanceof HTMLElement
                ? Math.abs(row.getBoundingClientRect().top - top)
                : 999;
        })
        .toBeLessThan(2);
});

it("restores a phone anchor after rows arrive while its detail is open", async () => {
    await page.viewport(390, 800);
    const rows = { current: Array.from({ length: 80 }, (_, index) => activity(80 - index)) };
    const app = await openApp("/activity", {
        wrapTransport: (inner) => (method, path, body) =>
            path.startsWith("/api/v1/activities")
                ? activityTransport(rows)(method, path, body)
                : inner(method, path, body),
    });
    await expect.element(pane("Activity")).toHaveTextContent("node:add");
    activityLog().scrollTop = 1500;
    activityLog().dispatchEvent(new Event("scroll"));
    await settle();
    const anchor = visibleAnchor();
    const id = anchor.dataset.activityId ?? "";
    const top = anchor.getBoundingClientRect().top;
    anchor.dispatchEvent(new MouseEvent("click", { bubbles: true, button: 0 }));
    await expect.element(pane("Properties")).toBeVisible();
    for (let next = 81; next <= 100; next += 1) {
        const row = activity(next);
        rows.current.unshift(row);
        applyEvent(queryClient, {
            type: "activity.created",
            id: next,
            at: row.occurred_at,
            data: row,
        });
    }
    await settle();
    app.router.history.back();
    await expect.poll(() => app.url().split("?")[0]).toBe("/activity");
    await expect.element(page.getByRole("button", { name: "20 new" })).toBeVisible();
    await expect
        .poll(() => {
            const row = document.querySelector(`[data-activity-id="${id}"]`);

            return row instanceof HTMLElement
                ? Math.abs(row.getBoundingClientRect().top - top)
                : 999;
        })
        .toBeLessThan(2);
});

it("gives a removed anchor's place to the next older row", async () => {
    const rows = { current: Array.from({ length: 49 }, (_, index) => activity(49 - index)) };
    await openApp("/activity?status=succeeded", {
        wrapTransport: (inner) => (method, path, body) =>
            path.startsWith("/api/v1/activities")
                ? activityTransport(rows)(method, path, body)
                : inner(method, path, body),
    });
    await expect.element(pane("Activity")).toHaveTextContent("node:add");
    activityLog().scrollTop = 240;
    activityLog().dispatchEvent(new Event("scroll"));
    await settle();
    const anchor = visibleAnchor();
    const id = Number(anchor.dataset.activityId);
    const top = anchor.getBoundingClientRect().top;
    rows.current = rows.current.map((row) => (row.id === id ? { ...row, status: "failed" } : row));
    await rebuildActivityList(queryClient, { status: "succeeded" });
    await expect
        .poll(() => {
            const next = document.querySelector(`[data-activity-id="${id - 1}"]`);

            return next instanceof HTMLElement
                ? Math.abs(next.getBoundingClientRect().top - top)
                : 999;
        })
        .toBeLessThan(2);
});

it("gives a removed anchor's place to the next newer row when no older row remains", async () => {
    const rows = { current: Array.from({ length: 40 }, (_, index) => activity(40 - index)) };
    await openApp("/activity?status=succeeded", {
        wrapTransport: (inner) => (method, path, body) =>
            path.startsWith("/api/v1/activities")
                ? activityTransport(rows)(method, path, body)
                : inner(method, path, body),
    });
    await expect.element(pane("Activity")).toHaveTextContent("node:add");
    const log = activityLog();
    log.style.flex = "none";
    log.style.height = "80px";
    log.style.maxHeight = "80px";
    log.scrollTop = log.scrollHeight;
    log.dispatchEvent(new Event("scroll"));
    await settle();
    const anchor = visibleAnchor();
    const id = Number(anchor.dataset.activityId);
    const top = anchor.getBoundingClientRect().top;
    rows.current = rows.current.filter((row) => row.id > id);
    await rebuildActivityList(queryClient, { status: "succeeded" });
    await expect
        .poll(() => {
            const next = document.querySelector(`[data-activity-id="${id + 1}"]`);

            return next instanceof HTMLElement
                ? Math.abs(next.getBoundingClientRect().top - top)
                : 999;
        })
        .toBeLessThan(2);
});
