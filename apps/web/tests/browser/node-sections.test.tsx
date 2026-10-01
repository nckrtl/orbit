import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import type { Transport } from "../../src/api/client";
import { footer, openApp, pane, row } from "./app";

const linuxFirewall = (nodeId: number) => (path: string) =>
    path === `/api/v1/nodes/${nodeId}/live-firewall-rules` ||
    path === `/api/v1/nodes/${nodeId}/managed-firewall-rules`;

it("moves among three node sections with the keyboard and the address", async () => {
    const app = await openApp("/nodes/2");
    const overview = page.getByTestId("node-overview");
    await expect.element(overview).toHaveAttribute("aria-selected", "true");
    await expect.element(pane("Firewall")).not.toBeInTheDocument();
    expect(app.gateway.requests.some((request) => linuxFirewall(2)(request.path))).toBe(false);

    await overview.click();
    await userEvent.keyboard("{ArrowDown}");
    await expect.poll(app.url).toBe("/nodes/2/tools");
    await expect.element(page.getByTestId("node-tools")).toHaveAttribute("aria-selected", "true");
    await expect.element(row("Tools", "ripgrep")).toHaveTextContent("failed");
    await expect.element(row("Tools", "ripgrep")).toHaveTextContent("tool.manager_failed");
    await expect.element(row("Tools", "jq")).toHaveTextContent("1.7.1-1");
    await expect.element(row("Tools", "vite-plus")).toHaveTextContent("^0.2.0");

    await userEvent.keyboard("{ArrowDown}");
    await expect.poll(app.url).toBe("/nodes/2/firewall");
    await expect.element(row("Firewall", "443/tcp")).toBeVisible();
    await expect.element(row("Firewall", "orbit:wireguard-members")).toBeVisible();
    await expect
        .poll(() => app.gateway.requests.some((request) => linuxFirewall(2)(request.path)))
        .toBe(true);

    await userEvent.keyboard("{ArrowDown}");
    await expect.poll(app.url).toBe("/nodes/2");
    await userEvent.keyboard("{End}");
    await expect.poll(app.url).toBe("/nodes/2/firewall");
    await userEvent.keyboard("{Home}");
    await expect.poll(app.url).toBe("/nodes/2");
});

it("loads a section from its address and keeps firewall actions", async () => {
    const app = await openApp("/nodes/2/firewall");
    await expect
        .element(page.getByTestId("node-firewall"))
        .toHaveAttribute("aria-selected", "true");
    await row("Firewall", "443/tcp").click({ button: "right" });
    await expect.element(pane("https-public")).toBeVisible();
    await userEvent.keyboard("{Enter}");
    await expect
        .element(pane("https-public"))
        .toHaveTextContent("Confirm? Remove firewall rule [https-public] on node [beast]?");
    await userEvent.keyboard("{Enter}");
    await expect.element(footer()).toHaveTextContent("Firewall rule [https-public] removed.");
    expect(app.gateway.requests.at(-1)).toMatchObject({
        method: "DELETE",
        path: "/api/v1/nodes/2/firewall-rules/https-public",
    });
});

/** Live inventory and Linux firewall reads. The stored tool list must not call them. */
const liveInventory = (path: string) =>
    path.startsWith("/api/v1/tool-inventory") ||
    path.includes("/live-firewall-rules") ||
    path.includes("/managed-firewall-rules") ||
    path.startsWith("/api/v1/tool-managers");

it("shows stored tools for an unreachable node without a live inventory", async () => {
    const app = await openApp("/nodes/3/tools", {
        wrapTransport(inner: Transport) {
            return (method, path, body, options) => {
                if (!liveInventory(path)) {
                    return inner(method, path, body, options);
                }

                // Record the call, then fail it. A page that needs the live read cannot show rows.
                return inner(method, path, body, options).then(() => ({
                    status: 503,
                    payload: {
                        error: {
                            code: "tool.manager_failed",
                            message: "The live check could not be read.",
                        },
                    },
                }));
            };
        },
    });

    await expect.element(page.getByTestId("node-tools-offline")).toBeVisible();
    await expect.element(row("Tools", "nginx")).toBeVisible();
    await expect.element(row("Tools", "nginx")).toHaveTextContent("install · tool.manager_failed");
    await expect.element(row("Tools", "gh")).toHaveTextContent("2.62.0");
    expect(app.gateway.requests.some((request) => request.path.startsWith("/api/v1/tools?"))).toBe(
        true,
    );
    expect(app.gateway.requests.filter((request) => liveInventory(request.path))).toEqual([]);
});

it("shows the constraint and failure on a phone", async () => {
    await page.viewport(390, 800);
    await openApp("/nodes/4/tools");
    await expect.element(row("Tools", "eza")).toBeVisible();

    const read = (packageName: string) => {
        const node = row("Tools", packageName).element().querySelector(".row-detail");

        if (!(node instanceof HTMLElement)) {
            throw new Error(`missing detail for ${packageName}`);
        }

        const style = getComputedStyle(node);
        const rect = node.getBoundingClientRect();

        return {
            text: node.textContent ?? "",
            display: style.display,
            visibility: style.visibility,
            whiteSpace: style.whiteSpace,
            scrollWidth: node.scrollWidth,
            clientWidth: node.clientWidth,
            height: rect.height,
            width: rect.width,
            top: rect.top,
            bottom: rect.bottom,
        };
    };

    const failure = read("eza");
    expect(failure.text).toContain("constraint — · update · tool.manager_failed");
    expect(failure.display).not.toBe("none");
    expect(failure.visibility).not.toBe("hidden");
    expect(failure.whiteSpace).not.toBe("nowrap");
    expect(failure.height).toBeGreaterThan(8);
    expect(failure.width).toBeGreaterThan(8);
    expect(failure.scrollWidth).toBeLessThanOrEqual(failure.clientWidth + 1);
    expect(failure.top).toBeGreaterThanOrEqual(0);
    expect(failure.bottom).toBeLessThanOrEqual(window.innerHeight);

    const constraint = read("fd");
    expect(constraint.text).toContain("constraint ^10 · —");
    expect(constraint.display).not.toBe("none");
    expect(constraint.height).toBeGreaterThan(8);
});

it("shows the tool list error instead of an empty list", async () => {
    await openApp("/nodes/2/tools", {
        wrapTransport(inner: Transport) {
            return (method, path, body, options) =>
                path.startsWith("/api/v1/tools")
                    ? Promise.resolve({
                          status: 503,
                          payload: {
                              error: {
                                  code: "tool.node_inactive",
                                  message: "The tool list could not be read.",
                              },
                          },
                      })
                    : inner(method, path, body, options);
        },
    });

    await expect
        .element(page.getByTestId("node-tools-error"))
        .toHaveTextContent("The tool list could not be read.");
    await expect.element(pane("Tools")).not.toHaveTextContent("No registered tools.");
});

it("does not request linux firewall rules for a mac", async () => {
    const app = await openApp("/nodes/4/firewall");
    await expect.poll(app.url).toBe("/nodes/4");
    await expect.element(page.getByTestId("node-firewall")).not.toBeInTheDocument();
    await expect.element(page.getByTestId("node-tools")).toBeVisible();
    await expect
        .element(pane("studio · active"))
        .toHaveTextContent("Metrics are not available on this Node.");
    expect(app.gateway.requests.some((request) => linuxFirewall(4)(request.path))).toBe(false);

    await page.getByTestId("node-tools").click();
    await expect.poll(app.url).toBe("/nodes/4/tools");
    await expect.element(row("Tools", "firefox")).toHaveTextContent("failed");
    await expect.element(row("Tools", "visual-studio-code")).toHaveTextContent("brew-cask");
    expect(app.gateway.requests.some((request) => linuxFirewall(4)(request.path))).toBe(false);
});

it("lays three sections in one row on a narrow screen", async () => {
    await page.viewport(390, 800);
    const app = await openApp("/nodes/2/tools");
    const menu = page.getByRole("tablist", { name: "Node sections" });
    await expect.element(menu).toHaveAttribute("aria-orientation", "horizontal");

    const boxes = {
        tabs: [
            ...document.querySelectorAll<HTMLElement>('[aria-label="Node sections"] [role="tab"]'),
        ].map((tab) => tab.getBoundingClientRect()),
        list: document.querySelector("[data-testid=node-tools-list]")?.getBoundingClientRect(),
        width: window.innerWidth,
    };

    expect(boxes.tabs).toHaveLength(3);
    expect(boxes.tabs[0]?.top).toBe(boxes.tabs[2]?.top);
    expect(boxes.tabs[2]?.right ?? 0).toBeLessThanOrEqual(boxes.width);
    expect(boxes.list?.top ?? 0).toBeGreaterThan(boxes.tabs[0]?.bottom ?? 0);
    expect(boxes.list?.left ?? 999).toBeLessThan(40);
    expect((boxes.list?.width ?? 0) / boxes.width).toBeGreaterThan(0.8);

    await page.getByTestId("node-overview").click();
    await expect.poll(app.url).toBe("/nodes/2");
    document.querySelector<HTMLButtonElement>('[data-testid="node-overview"]')?.focus();
    await userEvent.keyboard("{ArrowRight}");
    await expect.poll(app.url).toBe("/nodes/2/tools");
});
