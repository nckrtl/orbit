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

/** The phase hint is fully on screen, not under the React Flow credit, and clear of the Subtask title. */
function phaseHintIsClear(): boolean {
    const hint = [
        ...document.querySelectorAll(
            ".definition-frame .frame-edge[data-edge='bottom'] .frame-label",
        ),
    ].find((node) => node.textContent?.includes("phase"));
    const main = document.querySelector("main");
    if (!(hint instanceof HTMLElement) || !(main instanceof HTMLElement)) return false;
    const box = hint.getBoundingClientRect();
    if (box.height < 10 || !inside(box, main.getBoundingClientRect())) return false;
    const credit = document.querySelector(".react-flow__attribution");
    if (!(credit instanceof HTMLElement) || overlaps(box, credit.getBoundingClientRect())) {
        return false;
    }
    const title = document.querySelector('[data-testid="definition-subtask"] .frame-label');
    if (!(title instanceof HTMLElement)) return true;
    const titleBox = title.getBoundingClientRect();
    if (titleBox.top < box.top) return true;
    return !overlaps(box, titleBox) && titleBox.top - box.bottom >= 8;
}

it("opens a phase into a frame around its subtasks", async () => {
    await openApp("/projects/1/task-definitions/publish");
    const phase = page.getByTestId("definition-phase").filter({ hasText: "Draft" });
    await expect.element(phase).toBeVisible();
    expect(page.getByLabelText("agent subtask: Write the page").query()).toBeNull();
    await expect.element(page.getByLabelText("Engine stage: Merge")).toBeVisible();
    await expect.element(page.getByText("Open a phase to see its subtasks")).toBeVisible();
    await expect.poll(() => phaseHintIsClear()).toBe(true);
    await phase.click();
    await expect.element(page.getByText("Collapse a phase to hide its subtasks")).toBeVisible();
    expect(page.getByText("Open a phase to see its subtasks").query()).toBeNull();
    await expect.poll(() => phaseHintIsClear()).toBe(true);
    await expect.element(page.getByTestId("definition-phase-frame")).toHaveTextContent("Draft");
    await expect.element(page.getByLabelText("agent subtask: Write the page")).toBeVisible();
    await expect.element(page.getByLabelText("agent subtask: Edit the page")).toBeVisible();
    await expect.poll(() => phaseTitleClearsCardAbove()).toBe(true);
});

it("opens a phase with the keyboard", async () => {
    await openApp("/projects/1/task-definitions/publish");
    const phase = page.getByRole("button", { name: /Phase: Draft/ });
    await expect.element(phase).toBeVisible();
    phase.element().focus();
    await userEvent.keyboard("{Enter}");
    await expect.element(page.getByLabelText("agent subtask: Write the page")).toBeVisible();
});

it("shows that the model list is unavailable and reports no driver findings when ProxyCli is off", async () => {
    await openApp("/projects/3/task-definitions/maintenance");
    const findings = page.getByTestId("definition-findings");
    await expect.element(findings).toHaveTextContent("The model list is unavailable.");
    await expect.element(findings).not.toHaveTextContent("No driver can run");
    await expect.element(findings).not.toHaveTextContent("No path reaches");
    await expect
        .element(page.getByTestId("definition-subtask").getByText("gpt-5.6-luna"))
        .not.toHaveClass("text-yellow");
    await expect
        .element(page.getByTestId("definition-subtask").getByText("claude-opus-5"))
        .not.toHaveClass("text-yellow");
});

it("shows that the model list is unavailable when ProxyCli refuses it", async () => {
    await openApp("/projects/3/task-definitions/maintenance", { proxycliExtension: true });
    const findings = page.getByTestId("definition-findings");
    await expect.element(findings).toHaveTextContent("The model list is unavailable.");
    await expect.element(findings).not.toHaveTextContent("No driver can run");
});

it("shows that the model list is unavailable when the list is empty", async () => {
    await openApp("/projects/3/task-definitions/maintenance", {
        proxycli: true,
        wrapTransport: (inner) => (method, path, body) =>
            path === "/api/v1/proxycli/models"
                ? Promise.resolve({ status: 200, payload: { data: [] } })
                : inner(method, path, body),
    });
    const findings = page.getByTestId("definition-findings");
    await expect.element(findings).toHaveTextContent("The model list is unavailable.");
    await expect.element(findings).not.toHaveTextContent("No driver can run");
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
    await expect.element(findings).not.toHaveTextContent("The model list is unavailable.");
    const finding = findings.getByRole("button", {
        name: "update No driver can run gpt-5.6-luna.",
    });
    expect(
        Number.parseFloat(getComputedStyle(finding.element()).paddingTop),
    ).toBeGreaterThanOrEqual(8);
    expect(
        Number.parseFloat(getComputedStyle(finding.element()).paddingLeft),
    ).toBeGreaterThanOrEqual(8);
    await finding.click();
    await expect.element(page.getByTestId("definition-subtask")).toHaveTextContent("gpt-5.6-luna");
});

it("keeps a definition with side paths readable on a phone", async () => {
    await page.viewport(390, 800);
    try {
        await openApp("/projects/3/task-definitions/maintenance");
        const canvas = page.getByTestId("definition-canvas");
        await expect.element(canvas).toBeVisible();
        const update = page.getByLabelText("agent subtask: Update dependencies");
        await expect.element(update).toBeVisible();
        await expect.poll(() => renderedTextPx(update.element())).toBeGreaterThanOrEqual(12);
        await expect.poll(() => controlsMiss(update.element())).toBe(true);
        await expect.poll(() => pastTheRightEdge(rollbackCard(), canvas.element())).toBe(true);

        const shift = shiftIntoView(rollbackCard(), canvas.element());
        panCanvas(shift.dx, shift.dy);
        await expect.poll(() => insideCanvas(rollbackCard(), canvas.element())).toBe(true);
        expect(renderedTextPx(update.element())).toBeGreaterThanOrEqual(12);
        const controls = controlButtons();
        expect(controls.length).toBeGreaterThanOrEqual(3);
        for (const control of controls) {
            const box = control.getBoundingClientRect();
            expect(box.width).toBeGreaterThanOrEqual(44);
            expect(box.height).toBeGreaterThanOrEqual(44);
        }

        await openApp("/projects/1/task-definitions/publish");
        await expect.element(page.getByText("Open a phase to see its subtasks")).toBeVisible();
        await expect.poll(() => phaseHintIsClear()).toBe(true);
        await page.getByRole("button", { name: /Phase: Draft/ }).click();
        await expect.element(page.getByText("Collapse a phase to hide its subtasks")).toBeVisible();
        await expect.poll(() => phaseHintIsClear()).toBe(true);
        const title = page.getByRole("button", { name: "Collapse phase Draft" });
        await expect.element(title).toBeVisible();
        const titleBox = title.element().getBoundingClientRect();
        expect(titleBox.width).toBeGreaterThanOrEqual(44);
        expect(titleBox.height).toBeGreaterThanOrEqual(44);
        await expect.poll(() => phaseTitleClearsCardAbove()).toBe(true);
        const phaseControls = controlButtons();
        expect(phaseControls.length).toBeGreaterThanOrEqual(3);
        for (const control of phaseControls) {
            const box = control.getBoundingClientRect();
            expect(box.width).toBeGreaterThanOrEqual(44);
            expect(box.height).toBeGreaterThanOrEqual(44);
        }
    } finally {
        await page.viewport(1280, 800);
    }
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

function rollbackCard(): HTMLElement | null {
    const card = document.querySelector('[aria-label="action subtask: Roll back production"]');
    return card instanceof HTMLElement ? card : null;
}

/** Rendered card text, after the canvas zoom. */
function renderedTextPx(card: Element): number {
    if (!(card instanceof HTMLElement) || card.offsetHeight === 0) return 0;
    const font = Number.parseFloat(getComputedStyle(card).fontSize);
    return font * (card.getBoundingClientRect().height / card.offsetHeight);
}

function pastTheRightEdge(card: HTMLElement | null, canvas: Element): boolean {
    if (card === null) return false;
    return card.getBoundingClientRect().left > canvas.getBoundingClientRect().right - 1;
}

function insideCanvas(card: HTMLElement | null, canvas: Element): boolean {
    if (card === null) return false;
    const inner = card.getBoundingClientRect();
    const outer = canvas.getBoundingClientRect();
    return (
        inner.width > 0 &&
        inner.left >= outer.left - 1 &&
        inner.right <= outer.right + 1 &&
        inner.top >= outer.top - 1 &&
        inner.bottom <= outer.bottom + 1
    );
}

/** How far to drag so the card sits just inside the canvas. */
function shiftIntoView(card: HTMLElement | null, canvas: Element): { dx: number; dy: number } {
    if (card === null) return { dx: 0, dy: 0 };
    const inner = card.getBoundingClientRect();
    const outer = canvas.getBoundingClientRect();
    return {
        dx: -(inner.left - outer.left - 24),
        dy: -(inner.top - outer.top - 24),
    };
}

function panCanvas(dx: number, dy: number): void {
    const pane = document.querySelector(".react-flow__pane");
    if (!(pane instanceof HTMLElement)) throw new Error("canvas pane missing");
    const box = pane.getBoundingClientRect();
    const startX = box.left + Math.min(80, box.width / 3);
    const startY = box.top + 28;
    const pointer = (type: string, clientX: number, clientY: number, buttons: number) =>
        new MouseEvent(type, {
            bubbles: true,
            cancelable: true,
            view: window,
            clientX,
            clientY,
            button: 0,
            buttons,
        });
    pane.dispatchEvent(pointer("mousedown", startX, startY, 1));
    window.dispatchEvent(pointer("mousemove", startX + dx, startY + dy, 1));
    window.dispatchEvent(pointer("mouseup", startX + dx, startY + dy, 0));
}

function controlButtons(): HTMLElement[] {
    return [...document.querySelectorAll(".definition-canvas .react-flow__controls-button")].filter(
        (element): element is HTMLElement => element instanceof HTMLElement,
    );
}

/** The opening controls stay off the first main-path card. */
function controlsMiss(card: Element): boolean {
    const cardBox = card.getBoundingClientRect();
    const controls = controlButtons();
    if (controls.length < 3 || cardBox.height === 0) return false;
    return controls.every((control) => separated(cardBox, control.getBoundingClientRect()));
}

function separated(a: DOMRect, b: DOMRect): boolean {
    return (
        a.right <= b.left - 1 ||
        b.right <= a.left - 1 ||
        a.bottom <= b.top - 1 ||
        b.bottom <= a.top - 1
    );
}

/** The phase frame, including the top of its title, sits clear of the card above it. */
function phaseTitleClearsCardAbove(): boolean {
    const title = document.querySelector(".definition-phase-title");
    const frame = document.querySelector('[data-testid="definition-phase-frame"]');
    const above = document.querySelector('[aria-label="Engine stage: Start workspace"]');
    if (
        !(title instanceof HTMLElement) ||
        !(frame instanceof HTMLElement) ||
        !(above instanceof HTMLElement)
    ) {
        return false;
    }
    const titleBox = title.getBoundingClientRect();
    const frameBox = frame.getBoundingClientRect();
    const aboveBox = above.getBoundingClientRect();
    if (titleBox.height < 44 || frameBox.top < aboveBox.bottom + 4) return false;
    const hit = document.elementFromPoint(
        titleBox.left + Math.min(22, titleBox.width / 2),
        titleBox.top + 2,
    );
    return hit instanceof Node && title.contains(hit);
}
