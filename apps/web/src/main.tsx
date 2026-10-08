import { orbitTransport } from "./annotation/orbit-transport";
import { QueryClientProvider } from "@tanstack/react-query";
import { RouterProvider } from "@tanstack/react-router";
import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { queryClient } from "./api/queryClient";
import { mountAnnotation } from "@nckrtl/annotator";
import { createAppRouter } from "./router";
import { blocksUnload, installBuildCheck } from "./update/build-check";
import "./styles.css";

declare const __ORBIT_BUILD__: string;

// Demo mode answers every request from the fixture fleet, so the page runs with no Gateway.
if (import.meta.env.VITE_ORBIT_DEMO) {
    (await import("./demo/install")).installDemo();
}

// Mount annotation overlay + document listeners once for the app lifetime.
// Do not createRoot/teardown from AnnotationChrome (StrictMode double-mount races).
const annotationServiceUrl = import.meta.env.VITE_ANNOTATION_SERVICE_URL;
const annotation = mountAnnotation({
    transports: annotationServiceUrl
        ? [
              orbitTransport({
                  serviceUrl: annotationServiceUrl,
                  threadId: import.meta.env.VITE_ANNOTATION_THREAD_ID,
                  threadDiscoveryUrl: import.meta.env.DEV ? "/__annotate/thread" : undefined,
              }),
          ]
        : [],
    dictation: {
        provider: import.meta.env.VITE_ANNOTATION_DICTATION_PROVIDER,
        postUrl: import.meta.env.VITE_ANNOTATION_DICTATION_POST_URL,
        stopUrl: import.meta.env.VITE_ANNOTATION_DICTATION_STOP_URL,
        wsUrl: import.meta.env.VITE_ANNOTATION_TRANSCRIPTION_URL,
        autoStart: import.meta.env.VITE_ANNOTATION_AUTO_START !== "0",
    },
});
if (import.meta.hot) import.meta.hot.dispose(() => annotation.destroy());

const router = createAppRouter();

// A released build moves open pages to a newer release. The dev server reloads modules itself.
if (import.meta.env.PROD) {
    installBuildCheck({
        build: __ORBIT_BUILD__,
        router,
        // The app's drafts block navigation and unload through router blockers, such as a Project Document edit.
        hasUnsavedInput: () => blocksUnload(router.history._getBlockers()),
    });
}

createRoot(document.getElementById("app") as HTMLElement).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
        </QueryClientProvider>
    </StrictMode>,
);
