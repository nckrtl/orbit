import { afterEach, expect, it, vi } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { QueryClientProvider } from "@tanstack/react-query";
import { render } from "vitest-browser-react";
import { AnnotationsPanel } from "../../src/pages/AnnotationsPanel";
import { queryClient } from "../../src/api/queryClient";
import { setTransport } from "../../src/api/client";
import "../../src/styles.css";

afterEach(() => {
    setTransport(null);
    vi.unstubAllGlobals();
    queryClient.clear();
});
it("shows annotation progress, completion summaries, and delivery retry", async () => {
    let retried = false;
    let state = "pending";
    setTransport(async (method) => {
        if (method === "POST") retried = true;
        return {
            status: 200,
            payload: {
                data: [
                    {
                        id: "one",
                        comment: "Reduce title size",
                        element: "h1",
                        pathname: "/",
                        status: state,
                        delivery: retried ? "sent" : "error",
                        threadId: "thread-one",
                        syncError: retried ? undefined : "T3 unavailable",
                        summary:
                            state === "resolved"
                                ? "Reduced title and checked the layout"
                                : undefined,
                    },
                ],
            },
        };
    });
    await render(
        <QueryClientProvider client={queryClient}>
            <AnnotationsPanel instanceId={107} />
        </QueryClientProvider>,
    );
    await expect.element(page.getByText("Delivery failed", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Retry delivery" }).click();
    await expect.element(page.getByText("Delivered", { exact: true })).toBeVisible();
    state = "in_progress";
    await queryClient.invalidateQueries({ queryKey: ["instance-annotations"] });
    await expect.element(page.getByText("In progress", { exact: true })).toBeVisible();
    state = "resolved";
    await queryClient.invalidateQueries({ queryKey: ["instance-annotations"] });
    await expect.element(page.getByText("Done", { exact: true })).toBeVisible();
    await expect.element(page.getByText("Reduced title and checked the layout")).toBeVisible();
});
