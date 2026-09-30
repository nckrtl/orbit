import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import type { Transport } from "../../src/api/client";
import { openApp, pane, row } from "./app";

it("lists definitions on the tasks page and only that Project's definitions on a Project page", async () => {
    const tasks = await openApp("/tasks");
    await expect.element(pane("Definitions")).toBeVisible();
    await expect.element(row("Definitions", "Weekly maintenance")).toBeVisible();
    await expect.element(row("Definitions", "Publish a page")).toBeVisible();
    await expect.element(pane("Definitions")).toHaveTextContent("Weekly on Monday at 03:00 UTC");
    await expect.element(pane("Definitions")).toHaveTextContent("Charlie shop");
    await row("Definitions", "Weekly maintenance").click();
    await expect.poll(() => tasks.url()).toBe("/projects/3/task-definitions/maintenance");

    await openApp("/projects/3");
    await expect.element(row("Definitions", "Weekly maintenance")).toBeVisible();
    await expect.element(pane("Definitions")).not.toHaveTextContent("Publish a page");
    await expect.element(pane("Definitions")).not.toHaveTextContent("Charlie shop");
});

it("draws a definition, its engine stages, and a failure path", async () => {
    await openApp("/projects/3/task-definitions/maintenance");
    await expect
        .element(page.getByTestId("definition-title"))
        .toHaveTextContent("Weekly maintenance");
    await expect.element(page.getByTestId("definition-canvas")).toBeVisible();
    await expect.element(page.getByLabelText("Engine stage: Start workspace")).toBeVisible();
    await expect.element(page.getByLabelText("Engine stage: Open pull request")).toBeVisible();
    await expect.element(page.getByLabelText("Engine stage: Clean up")).toBeVisible();
    expect(page.getByLabelText("Engine stage: Merge").query()).toBeNull();
    await expect.element(page.getByLabelText("agent subtask: Update dependencies")).toBeVisible();
    await expect.element(page.getByLabelText("action subtask: Roll back production")).toBeVisible();
    await expect.element(page.getByTestId("definition-subtask")).toHaveTextContent("Implementer");
    await expect.element(pane("Definition")).toHaveTextContent("Weekly on Monday at 03:00 UTC");
});

it("draws the minor detour beside the review card, with its label in view", async () => {
    await openApp("/projects/3/task-definitions/maintenance");
    const label = page.getByTestId("definition-edge-label").filter({ hasText: "minor" });
    await expect.element(label).toBeVisible();
    await expect.poll(() => minorClearsReview()).toBe(true);
});

function minorClearsReview(): boolean {
    const label = [...document.querySelectorAll('[data-testid="definition-edge-label"]')].find(
        (element) => element.textContent?.trim() === "minor",
    );
    const review = document.querySelector('[aria-label="agent subtask: Adapt to major upgrades"]');
    const path = document.querySelector(
        '[data-testid="rf__edge-size:minor->browser"] .react-flow__edge-path',
    );
    const canvas = document.querySelector('[data-testid="definition-canvas"]');
    if (
        !(label instanceof HTMLElement) ||
        !(review instanceof HTMLElement) ||
        !(path instanceof SVGPathElement) ||
        !(canvas instanceof HTMLElement)
    ) {
        return false;
    }
    const labelBox = label.getBoundingClientRect();
    const reviewBox = review.getBoundingClientRect();
    const canvasBox = canvas.getBoundingClientRect();
    if (labelBox.width === 0 || overlaps(labelBox, reviewBox)) return false;
    if (!inside(labelBox, canvasBox)) return false;
    const length = path.getTotalLength();
    const matrix = path.getScreenCTM();
    if (matrix === null || length === 0) return false;
    let beside = false;
    for (let step = 0; step <= 24; step += 1) {
        const point = path.getPointAtLength((length * step) / 24);
        const screen = new DOMPoint(point.x, point.y).matrixTransform(matrix);
        if (screen.y < reviewBox.top || screen.y > reviewBox.bottom) continue;
        if (screen.x <= reviewBox.right) return false;
        beside = true;
    }
    return beside;
}

function overlaps(a: DOMRect, b: DOMRect): boolean {
    return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
}

function inside(inner: DOMRect, outer: DOMRect): boolean {
    return (
        inner.left >= outer.left - 1 &&
        inner.right <= outer.right + 1 &&
        inner.top >= outer.top - 1 &&
        inner.bottom <= outer.bottom + 1
    );
}

it("opens a phase into a frame around its subtasks", async () => {
    await openApp("/projects/1/task-definitions/publish");
    const phase = page.getByTestId("definition-phase").filter({ hasText: "Draft" });
    await expect.element(phase).toBeVisible();
    expect(page.getByLabelText("agent subtask: Write the page").query()).toBeNull();
    await expect.element(page.getByLabelText("Engine stage: Merge")).toBeVisible();
    await phase.click();
    await expect.element(page.getByTestId("definition-phase-frame")).toHaveTextContent("Draft");
    await expect.element(page.getByLabelText("agent subtask: Write the page")).toBeVisible();
    await expect.element(page.getByLabelText("agent subtask: Edit the page")).toBeVisible();
});

it("opens a phase with the keyboard", async () => {
    await openApp("/projects/1/task-definitions/publish");
    const phase = page.getByRole("button", { name: /Phase: Draft/ });
    await expect.element(phase).toBeVisible();
    phase.element().focus();
    await userEvent.keyboard("{Enter}");
    await expect.element(page.getByLabelText("agent subtask: Write the page")).toBeVisible();
});

it("reports every non-Claude model when ProxyCli is off", async () => {
    await openApp("/projects/3/task-definitions/maintenance");
    const findings = page.getByTestId("definition-findings");
    await expect.element(findings).toHaveTextContent("No driver can run gpt-5.6-luna.");
    await expect.element(findings).toHaveTextContent("No driver can run gpt-6-luna.");
    await expect.element(findings).toHaveTextContent("No driver can run gpt-6-sol.");
    await expect.element(findings).not.toHaveTextContent("claude-opus-5");
    await expect
        .element(page.getByTestId("definition-subtask").getByText("gpt-5.6-luna"))
        .toHaveClass("text-yellow");
    await expect
        .element(page.getByTestId("definition-subtask").getByText("claude-opus-5"))
        .not.toHaveClass("text-yellow");
});

it("reports every non-Claude model when ProxyCli refuses the model list", async () => {
    await openApp("/projects/3/task-definitions/maintenance", { proxycliExtension: true });
    const findings = page.getByTestId("definition-findings");
    await expect.element(findings).toHaveTextContent("No driver can run gpt-5.6-luna.");
    await expect.element(findings).toHaveTextContent("No driver can run gpt-6-sol.");
    await expect.element(findings).not.toHaveTextContent("claude-opus-5");
});

it("reports a model no driver can run, and not a Claude model", async () => {
    const models: Transport = async (_method, path) => {
        if (path === "/api/v1/proxycli/models") {
            return { status: 200, payload: { data: [{ id: "gemini-ultra", provider: "google" }] } };
        }
        return { status: 404, payload: { error: { code: "route.not_found", message: path } } };
    };
    await openApp("/projects/3/task-definitions/maintenance", {
        proxycli: true,
        wrapTransport: (inner) => (method, path, body) =>
            path === "/api/v1/proxycli/models"
                ? models(method, path, body)
                : inner(method, path, body),
    });
    const findings = page.getByTestId("definition-findings");
    await expect.element(findings).toHaveTextContent("No driver can run gpt-5.6-luna.");
    await expect.element(findings).not.toHaveTextContent("claude-opus-5");
    await findings.getByRole("button", { name: "update No driver can run gpt-5.6-luna." }).click();
    await expect.element(page.getByTestId("definition-subtask")).toHaveTextContent("gpt-5.6-luna");
});

it("hides definitions when the tasks extension is disabled", async () => {
    await openApp("/tasks", { tasks: false });
    expect(document.querySelector('[data-testid="task-definitions"]')).toBeNull();
    await expect
        .element(page.getByText("The tasks extension is disabled on this Gateway."))
        .toBeVisible();

    await openApp("/projects/3/task-definitions/maintenance", { tasks: false });
    expect(document.querySelector('[data-testid="definition-canvas"]')).toBeNull();
    await expect
        .element(page.getByText("The tasks extension is disabled on this Gateway."))
        .toBeVisible();
});
