import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import type { Transport } from "../../src/api/client";
import { openApp, pane, row } from "./app";

const inventoryGets = (requests: { method: string; path: string }[]) =>
    requests.filter(
        (request) => request.method === "GET" && request.path.startsWith("/api/v1/tool-inventory"),
    );

const mutations = (requests: { method: string; path: string }[]) =>
    requests.filter(
        (request) =>
            request.method !== "GET" &&
            (request.path.startsWith("/api/v1/tools") ||
                request.path.startsWith("/api/v1/tool-inventory")),
    );

it("shows managed tools, discoveries, a dependency, and an unsupported cask", async () => {
    const app = await openApp("/nodes/4/tools");

    await expect
        .element(page.getByTestId("node-tools-inspection"))
        .toHaveTextContent("Observed at 2026-04-26T12:00:00+00:00.");
    await expect.element(row("Tools", "jq")).toHaveTextContent("1.7.1");
    await expect.element(row("Tools", "jq")).toHaveTextContent("1.8.0");
    await expect.element(row("Tools", "git")).toHaveTextContent("absent");
    await expect.element(row("Tools", "visual-studio-code")).toHaveTextContent("brew-cask");
    await expect.element(row("Tools", "visual-studio-code")).toHaveTextContent("1.96.2");
    await expect
        .element(row("Detected packages", "visual-studio-code"))
        .toHaveTextContent("formula");
    await expect.element(row("Detected packages", "openssl@3")).toHaveTextContent("dependency");
    await expect.element(row("Detected packages", "docker")).toHaveTextContent("cask");
    await expect
        .element(row("Detected packages", "docker"))
        .toHaveTextContent("authorization_required");
    await expect
        .element(row("Detected packages", "font-hack"))
        .toHaveTextContent("unsupported_artifact");
    await expect
        .element(pane("Detected packages"))
        .toHaveTextContent("Informational. Discovery does not change Node health.");
    expect(
        row("Detected packages", "docker").getByRole("button", { name: "Adopt" }).query(),
    ).toBeNull();
    expect(
        row("Detected packages", "openssl@3").getByRole("button", { name: "Adopt" }).query(),
    ).toBeNull();
    expect(mutations(app.gateway.requests)).toEqual([]);
    expect(inventoryGets(app.gateway.requests).length).toBeGreaterThan(0);
});

it("adopts one package with the exact request and leaves the other discoveries", async () => {
    const app = await openApp("/nodes/4/tools");
    await page.getByTestId("node-tools-adopt-brew-delta").click();
    await expect
        .element(page.getByTestId("node-tools-adopt-prompt"))
        .toHaveTextContent(
            "Take ownership of package [delta] (brew on Node #4) and manage later updates and removal?",
        );
    await page.getByTestId("node-tools-adopt-constraint").fill("^0.18.0");
    await expect
        .element(page.getByTestId("node-tools-adopt-prompt"))
        .toHaveTextContent("constraint ^0.18.0");
    await page.getByTestId("node-tools-adopt-submit").click();

    await expect
        .element(page.getByTestId("node-tools-result"))
        .toHaveTextContent("Tool [delta] adopted with [brew].");
    await expect.element(row("Tools", "delta")).toHaveTextContent("^0.18.0");
    await expect.element(row("Tools", "delta")).toHaveTextContent("0.18.2");
    expect(row("Detected packages", /^delta/).query()).toBeNull();
    await expect.element(row("Detected packages", "openssl@3")).toBeVisible();
    await expect.element(row("Detected packages", "docker")).toBeVisible();
    await expect.element(row("Tools", "visual-studio-code")).toHaveTextContent("brew-cask");
    expect(
        app.gateway.requests.filter((request) => request.path === "/api/v1/tools/adopt"),
    ).toEqual([
        {
            method: "POST",
            path: "/api/v1/tools/adopt",
            body: {
                node_id: 4,
                manager: "brew",
                package: "delta",
                version_constraint: "^0.18.0",
            },
        },
    ]);
    expect(
        app.gateway.requests.some(
            (request) => request.method === "POST" && request.path.endsWith("/update"),
        ),
    ).toBe(false);
});

it("keeps a revalidation error and does not take ownership", async () => {
    const app = await openApp("/nodes/4/tools");
    await page.getByTestId("node-tools-adopt-brew-ghost").click();
    await page.getByTestId("node-tools-adopt-submit").click();

    await expect
        .element(page.getByTestId("node-tools-adopt-error"))
        .toHaveTextContent("The package is not installed.");
    await expect.element(page.getByTestId("node-tools-adopt-form")).toBeVisible();
    await expect.element(row("Detected packages", "ghost")).toBeVisible();
    expect(row("Tools", /^ghost/).query()).toBeNull();
    expect(
        app.gateway.requests.filter((request) => request.path === "/api/v1/tools/adopt"),
    ).toHaveLength(1);
});

it("sends one adopt when the form is submitted twice", async () => {
    let release: (() => void) | undefined;
    const gate = new Promise<void>((resolve) => {
        release = resolve;
    });
    const app = await openApp("/nodes/4/tools", {
        wrapTransport(inner: Transport) {
            return (method, path, body, options) =>
                method === "POST" && path === "/api/v1/tools/adopt"
                    ? gate.then(() => inner(method, path, body, options))
                    : inner(method, path, body, options);
        },
    });

    await page.getByTestId("node-tools-adopt-brew-delta").click();
    await expect.element(page.getByTestId("node-tools-adopt-submit")).toBeVisible();
    const submit = page.getByTestId("node-tools-adopt-submit").element();

    if (!(submit instanceof HTMLButtonElement)) {
        throw new Error("missing adopt submit");
    }

    submit.click();
    submit.click();
    await expect.element(page.getByTestId("node-tools-adopt-submit")).toBeDisabled();
    release?.();

    await expect
        .element(page.getByTestId("node-tools-result"))
        .toHaveTextContent("Tool [delta] adopted with [brew].");
    expect(
        app.gateway.requests.filter((request) => request.path === "/api/v1/tools/adopt"),
    ).toHaveLength(1);
    expect(
        app.gateway.requests.filter((request) => request.path === "/api/v1/tools/adopt")[0],
    ).toMatchObject({
        method: "POST",
        body: { node_id: 4, manager: "brew", package: "delta" },
    });
});

it("closes the adopt form with Escape and stays on Tools", async () => {
    const app = await openApp("/nodes/4/tools");
    const requests = () => app.gateway.requests.length;

    await page.getByTestId("node-tools-adopt-brew-delta").click();
    await expect.element(page.getByTestId("node-tools-adopt-form")).toBeVisible();
    const before = requests();
    await userEvent.keyboard("{Escape}");
    await expect.element(page.getByTestId("node-tools-adopt-form")).not.toBeInTheDocument();
    await expect.poll(app.url).toBe("/nodes/4/tools");
    expect(requests()).toBe(before);
    expect(mutations(app.gateway.requests)).toEqual([]);

    await page.getByTestId("node-tools-adopt-brew-delta").click();
    await page.getByTestId("node-tools-adopt-constraint").click();
    const focused = requests();
    await userEvent.keyboard("{Escape}");
    await expect.element(page.getByTestId("node-tools-adopt-form")).not.toBeInTheDocument();
    await expect.poll(app.url).toBe("/nodes/4/tools");
    expect(requests()).toBe(focused);
    expect(mutations(app.gateway.requests)).toEqual([]);
});

it("refreshes the inventory without adopting or updating", async () => {
    const app = await openApp("/nodes/4/tools");
    await expect.element(page.getByTestId("node-tools-refresh")).toBeEnabled();
    const before = inventoryGets(app.gateway.requests).length;
    await page.getByTestId("node-tools-refresh").click();
    await expect.poll(() => inventoryGets(app.gateway.requests).length).toBeGreaterThan(before);
    expect(mutations(app.gateway.requests)).toEqual([]);
    await expect.element(row("Detected packages", "delta")).toBeVisible();
    await expect.element(row("Tools", "jq")).toHaveTextContent("1.7.1");
});

it("keeps the last observation when a refresh fails", async () => {
    let scans = 0;
    const app = await openApp("/nodes/4/tools", {
        wrapTransport(inner: Transport) {
            return (method, path, body, options) => {
                if (!path.startsWith("/api/v1/tool-inventory")) {
                    return inner(method, path, body, options);
                }

                scans += 1;

                if (scans === 1) {
                    return inner(method, path, body, options);
                }

                return Promise.resolve({
                    status: 503,
                    payload: {
                        error: {
                            code: "tool.manager_failed",
                            message: "The scan could not be read.",
                        },
                    },
                });
            };
        },
    });

    await expect.element(row("Detected packages", "delta")).toBeVisible();
    await page.getByTestId("node-tools-refresh").click();
    await expect.element(page.getByTestId("node-tools-scan-stale")).toBeVisible();
    await expect
        .element(page.getByTestId("node-tools-inspection"))
        .toHaveTextContent("Showing packages observed at 2026-04-26T12:00:00+00:00.");
    await expect
        .element(page.getByTestId("node-tools-inspection"))
        .toHaveTextContent("The scan could not be read.");
    await expect.element(row("Detected packages", "delta")).toBeVisible();
    await expect.element(row("Tools", "jq")).toBeVisible();
    expect(page.getByText("No unregistered packages.").query()).toBeNull();
    expect(mutations(app.gateway.requests)).toEqual([]);
});

it("does not present a failed scan as an empty inventory", async () => {
    await openApp("/nodes/4/tools", {
        wrapTransport(inner: Transport) {
            return (method, path, body, options) =>
                path.startsWith("/api/v1/tool-inventory")
                    ? Promise.resolve({
                          status: 503,
                          payload: {
                              error: {
                                  code: "tool.manager_failed",
                                  message: "The scan could not be read.",
                              },
                          },
                      })
                    : inner(method, path, body, options);
        },
    });

    await expect.element(page.getByTestId("node-tools-scan-unavailable")).toBeVisible();
    await expect
        .element(page.getByTestId("node-tools-unmanaged"))
        .toHaveTextContent("Installed packages were not read.");
    await expect.element(row("Tools", "jq")).toBeVisible();
    expect(page.getByText("No unregistered packages.").query()).toBeNull();
    expect(page.getByText("No packages in the completed scans.").query()).toBeNull();
});

it("shows a partial scan without hiding registered tools", async () => {
    const app = await openApp("/nodes/2/tools");

    await expect
        .element(page.getByTestId("node-tools-scan-states"))
        .toHaveTextContent("brew is incomplete, so its package list is not an inventory.");
    await expect
        .element(page.getByTestId("node-tools-scan-states"))
        .toHaveTextContent("vp is conflicting, so its package list is not an inventory.");
    await expect
        .element(pane("Detected packages"))
        .toHaveTextContent("No packages in the completed scans.");
    expect(page.getByText("No unregistered packages.").query()).toBeNull();
    await expect.element(row("Tools", "jq")).toHaveTextContent("1.7.1-1");
    await expect.element(row("Tools", "jq")).toHaveTextContent("not scanned");
    await expect.element(row("Tools", "ripgrep")).toHaveTextContent("tool.manager_failed");
    expect(mutations(app.gateway.requests)).toEqual([]);
});

it("keeps stored tools for an unreachable node and does not scan", async () => {
    const app = await openApp("/nodes/3/tools");

    await expect.element(page.getByTestId("node-tools-offline")).toBeVisible();
    await expect
        .element(page.getByTestId("node-tools-scan-unavailable"))
        .toHaveTextContent("No scan was started.");
    await expect.element(row("Tools", "nginx")).toHaveTextContent("install · tool.manager_failed");
    await expect.element(row("Tools", "gh")).toHaveTextContent("2.62.0");
    await expect.element(row("Tools", "gh")).toHaveTextContent("unavailable");
    await expect.element(page.getByTestId("node-tools-refresh")).toBeDisabled();
    expect(inventoryGets(app.gateway.requests)).toEqual([]);
    expect(mutations(app.gateway.requests)).toEqual([]);
});

it("shows a node with no tools and an empty completed scan", async () => {
    await openApp("/nodes/1/tools");

    await expect.element(pane("Tools")).toHaveTextContent("No registered tools.");
    await expect
        .element(page.getByTestId("node-tools-scan-states"))
        .toHaveTextContent("brew-cask is unsupported, so its package list is not an inventory.");
    await expect
        .element(page.getByTestId("node-tools-scan-states"))
        .toHaveTextContent("vp is absent, so its package list is not an inventory.");
    await expect
        .element(pane("Detected packages"))
        .toHaveTextContent("No packages in the completed scans.");
});

it("updates one tool and shows a failed update without losing the row", async () => {
    const app = await openApp("/nodes/4/tools");
    await row("Tools", "jq").getByRole("button", { name: "Update" }).click();
    await expect
        .element(page.getByTestId("node-tools-result"))
        .toHaveTextContent("Tool [jq] updated.");
    await expect.element(row("Tools", "jq")).toHaveTextContent("1.8.0");
    expect(
        app.gateway.requests.filter((request) => request.path === "/api/v1/tools/6/update"),
    ).toEqual([{ method: "POST", path: "/api/v1/tools/6/update", body: {} }]);

    const failed = await openApp("/nodes/4/tools", {
        wrapTransport(inner: Transport) {
            return (method, path, body, options) => {
                const answer = inner(method, path, body, options);

                return method === "POST" && path.endsWith("/update")
                    ? answer.then(() => ({
                          status: 409,
                          payload: {
                              error: {
                                  code: "tool.manager_unavailable",
                                  message: "The Tool manager is unavailable.",
                              },
                          },
                      }))
                    : answer;
            };
        },
    });
    await row("Tools", "fd").getByRole("button", { name: "Update" }).click();
    await expect
        .element(page.getByTestId("node-tools-result"))
        .toHaveTextContent("The Tool manager is unavailable.");
    await expect.element(row("Tools", "fd")).toHaveTextContent("^10");
    expect(
        failed.gateway.requests.filter((request) => request.path === "/api/v1/tools/8/update"),
    ).toHaveLength(1);
});

it("confirms removal with the package and node, and keeps the row when removal fails", async () => {
    const app = await openApp("/nodes/4/tools");
    await row("Tools", "wget").getByRole("button", { name: "Remove" }).click();
    await expect
        .element(page.getByTestId("node-tools-remove-prompt"))
        .toHaveTextContent(
            "Remove Tool [wget] (brew on Node #4) by uninstalling it and deleting its record?",
        );
    await page.getByTestId("node-tools-remove-cancel").click();
    expect(app.gateway.requests.some((request) => request.method === "DELETE")).toBe(false);
    await expect.element(row("Tools", "wget")).toBeVisible();

    await row("Tools", "wget").getByRole("button", { name: "Remove" }).click();
    await page.getByTestId("node-tools-remove-confirm").click();
    await expect
        .element(page.getByTestId("node-tools-result"))
        .toHaveTextContent("Tool [wget] removed.");
    expect(row("Tools", "wget").query()).toBeNull();
    await expect.element(row("Tools", "jq")).toBeVisible();
    expect(app.gateway.requests.filter((request) => request.method === "DELETE")).toEqual([
        { method: "DELETE", path: "/api/v1/tools/13", body: undefined },
    ]);

    const failed = await openApp("/nodes/4/tools", {
        wrapTransport(inner: Transport) {
            return (method, path, body, options) => {
                const answer = inner(method, path, body, options);

                return method === "DELETE" && /^\/api\/v1\/tools\/\d+$/.test(path)
                    ? answer.then(() => ({
                          status: 409,
                          payload: {
                              error: {
                                  code: "tool.remove_failed",
                                  message: "The package is still installed.",
                              },
                          },
                      }))
                    : answer;
            };
        },
    });
    await row("Tools", "bat").getByRole("button", { name: "Remove" }).click();
    await page.getByTestId("node-tools-remove-confirm").click();
    await expect
        .element(page.getByTestId("node-tools-remove-error"))
        .toHaveTextContent("The package is still installed.");
    await expect.element(page.getByTestId("node-tools-remove-form")).toBeVisible();
    await expect.element(row("Tools", "bat")).toBeVisible();
    expect(failed.gateway.requests.filter((request) => request.method === "DELETE")).toHaveLength(
        1,
    );
});
