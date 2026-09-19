import { QueryClientProvider } from "@tanstack/react-query";
import { RouterProvider } from "@tanstack/react-router";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { queryClient } from "./api/queryClient";
import { configureCommander } from "./annotation/commander";
import { ensureAnnotationRuntime } from "./annotation/runtime";
import { createAppRouter } from "./router";
import "./styles.css";

// Demo mode answers every request from the fixture fleet, so the page runs with no Gateway.
if (import.meta.env.VITE_ORBIT_DEMO) {
    (await import("./demo/install")).installDemo();
}

// Mount annotation overlay + document listeners once for the app lifetime.
// Do not createRoot/teardown from AnnotationChrome (StrictMode double-mount races).
configureCommander({
    enabled: import.meta.env.VITE_COMMANDER_ENABLED !== "0",
    project: import.meta.env.VITE_COMMANDER_PROJECT || "commander",
});
ensureAnnotationRuntime();

const router = createAppRouter();

createRoot(document.getElementById("app") as HTMLElement).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
        </QueryClientProvider>
    </StrictMode>,
);
