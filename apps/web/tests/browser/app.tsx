import { QueryClientProvider } from "@tanstack/react-query";
import { createMemoryHistory, RouterProvider } from "@tanstack/react-router";
import { page } from "vite-plus/test/browser";
import { render } from "vitest-browser-react";
import { setTransport, type Transport } from "../../src/api/client";
import { queryClient } from "../../src/api/queryClient";
import { configureCommander } from "../../src/annotation/commander";
import { ensureAnnotationRuntime, teardownAnnotationRuntime } from "../../src/annotation/runtime";
import { installDemo } from "../../src/demo/install";
import { createAppRouter } from "../../src/router";
import { ui } from "../../src/ui/store";
import "../../src/styles.css";

/**
 * Mounts the whole app at a URL against a fresh demo Gateway. Each test gets its own fleet, its
 * own URL history, and an empty query cache, so no test sees what another one changed.
 */
export async function openApp(
    path = "/",
    options: {
        proxycli?: boolean;
        tasks?: boolean;
        wrapTransport?: (inner: Transport) => Transport;
    } = {},
) {
    document.getElementById("app")?.remove();
    queryClient.clear();
    ui.reset();

    // Annotation runtime is owned by app entry (main.tsx). Tests bypass main, so mount here
    // once per openApp — never from AnnotationChrome (StrictMode teardown races).
    teardownAnnotationRuntime();
    configureCommander({
        enabled: import.meta.env.VITE_COMMANDER_ENABLED !== "0",
        project: import.meta.env.VITE_COMMANDER_PROJECT || "commander",
    });
    ensureAnnotationRuntime();

    const gateway = installDemo();

    if (options.proxycli === true) {
        gateway.enableProxyCli();
    }
    if (options.tasks === false) {
        gateway.disableTasks();
    }

    if (options.wrapTransport !== undefined) {
        setTransport(options.wrapTransport(gateway.transport), "demo fleet");
    }

    const container = document.createElement("div");
    container.id = "app";
    document.body.append(container);

    const router = createAppRouter(createMemoryHistory({ initialEntries: [path] }));
    await render(
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
        </QueryClientProvider>,
        { container },
    );

    return { gateway, router, url: () => router.state.location.href };
}

/** A framed pane by its title, and a row inside it by any text the row shows. */
export const pane = (title: string) => page.getByRole("region", { name: title, exact: true });
export const row = (title: string, text: string | RegExp) =>
    pane(title).getByRole("row").filter({ hasText: text });
export const footer = () => page.getByRole("contentinfo");
