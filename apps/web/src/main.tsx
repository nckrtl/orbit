import { QueryClientProvider } from "@tanstack/react-query";
import { RouterProvider } from "@tanstack/react-router";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { queryClient } from "./api/queryClient";
import { createAppRouter } from "./router";
import "./styles.css";

// Demo mode answers every request from the fixture fleet, so the page runs with no Gateway.
if (import.meta.env.VITE_ORBIT_DEMO) {
    (await import("./demo/install")).installDemo();
}

const router = createAppRouter();

createRoot(document.getElementById("app") as HTMLElement).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
        </QueryClientProvider>
    </StrictMode>,
);
