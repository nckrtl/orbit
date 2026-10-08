import { expect, it } from "vite-plus/test";
import { queryClient } from "../../src/api/queryClient";
import { applyEvent } from "../../src/realtime/apply";
import { openApp, row } from "./app";

// The demo fleet has beast in a fleet rollout visit; every other Node is not updating.
const rolloutLabel = "updating — fleet rollout";

const nodeUpdated = (data: Record<string, unknown>) =>
    applyEvent(queryClient, {
        type: "node.updated",
        id: Number(data.id),
        at: "2026-01-02T10:00:00+00:00",
        data,
    });

it.each(["/nodes", "/"])("draws an updating node on %s as a pulsing blue dot", async (path) => {
    await openApp(path);

    const dot = row("Worker nodes", "beast").getByLabelText(rolloutLabel);
    await expect.element(dot).toBeVisible();
    await expect.element(dot).toHaveAttribute("title", rolloutLabel);

    const style = getComputedStyle(dot.element());
    expect(style.animationName).toBe("status-pulse");
    expect(style.animationDuration).toBe("2s");
    expect(style.color).toBe("rgb(97, 175, 239)");

    // Nodes that nothing updates keep their dot and do not pulse.
    const failed = row("Worker nodes", "app-prod").getByLabelText("failed");
    await expect.element(failed).toBeVisible();
    expect(getComputedStyle(failed.element()).animationName).toBe("none");
});

it("flips the dot when node.updated sets or clears the update", async () => {
    await openApp("/nodes");
    await expect.element(row("Worker nodes", "beast").getByLabelText(rolloutLabel)).toBeVisible();

    nodeUpdated({ id: 2, updating: null });
    await expect
        .element(row("Worker nodes", "beast").getByLabelText(/^updating/))
        .not.toBeInTheDocument();

    nodeUpdated({
        id: 1,
        updating: {
            kind: "gateway_release",
            since: "2026-01-02T10:00:00+00:00",
            rollout: null,
            release: 31,
        },
    });
    await expect
        .element(row("Worker nodes", "gateway").getByLabelText("updating — Gateway release"))
        .toBeVisible();
});

it("shows updating in blue where the status is text", async () => {
    await openApp("/nodes");
    nodeUpdated({
        id: 4,
        updating: {
            kind: "fleet_rollout",
            since: "2026-01-02T10:00:00+00:00",
            rollout: 7,
            release: null,
        },
    });

    const status = row("Client nodes", "studio").getByText("updating", { exact: true });
    await expect.element(status).toBeVisible();
    await expect.element(status).toHaveAttribute("title", rolloutLabel);
    expect(getComputedStyle(status.element()).color).toBe("rgb(97, 175, 239)");
});

it("holds the dot still when the system asks for reduced motion", () => {
    const reduced = [...document.styleSheets]
        .flatMap((sheet) => [...sheet.cssRules])
        .filter(
            (rule): rule is CSSMediaRule =>
                rule instanceof CSSMediaRule &&
                rule.conditionText.includes("prefers-reduced-motion: reduce"),
        )
        .flatMap((rule) => [...rule.cssRules])
        .filter(
            (rule): rule is CSSStyleRule =>
                rule instanceof CSSStyleRule && rule.selectorText === ".status-pulse",
        );

    expect(reduced.map((rule) => rule.style.animationName)).toEqual(["none"]);
});
