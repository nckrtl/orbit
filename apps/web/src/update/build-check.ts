/**
 * Moves an open page to a newer release of the web app, as Inertia does on a version mismatch.
 *
 * Each build embeds its id and publishes it as `/version.json`. On a client-side navigation, and
 * when a hidden page becomes visible again, the page reads that file, at most once a minute. When the
 * Gateway serves another build, a navigation loads its target URL in full, and a resumed page
 * reloads. A home-screen app on iOS stays in memory and only navigates client-side, so without this
 * it would keep running the build it started with. [Web app](/reference/web-app#updates-to-open-pages)
 * documents the behavior.
 */

/** The file each build writes at its top: `{"build": "<id>"}`. */
export const VERSION_URL = "/version.json";

/** The sessionStorage key that remembers the last newer build this tab loaded in full. */
export const RELOAD_KEY = "orbit.build-reload";

/** The sessionStorage key that remembers the last build whose failed chunk reloaded this tab. */
export const CHUNK_RELOAD_KEY = "orbit.chunk-reload";

const DEFAULT_INTERVAL_MS = 60_000;

/** Only what the loop guard needs, so a blocked or missing sessionStorage still works. */
export type ReloadMemory = {
    getItem: (key: string) => string | null;
    setItem: (key: string, value: string) => void;
};

export type BuildCheckOptions = {
    /** The id this page was built with. */
    build: string;
    /** Reads the served build id, or null when it is unknown. */
    served?: () => Promise<string | null>;
    /** The least time between two reads. */
    intervalMs?: number;
    now?: () => number;
    /** A full page load of a URL, `window.location.assign`. */
    assign: (href: string) => void;
    /** `window.location.reload`. */
    reload: () => void;
    memory?: ReloadMemory | null;
    /** Whether the page holds input that a navigation would ask about, such as a document draft. */
    hasUnsavedInput?: () => boolean;
    /** Whether the user is typing in a field, such as a filter that changes the URL. */
    isEditing?: () => boolean;
};

export type BuildCheck = {
    /** A client-side navigation to `href` starts. */
    navigated: (href: string) => void;
    /** That navigation finished and the page it left is gone, with any draft it held. */
    settled: () => void;
    /** The page became visible again, or came back from the back-forward cache. */
    resumed: () => void;
    /** A script or style of this build failed to load. Returns whether the page reloads. */
    chunkFailed: () => boolean;
};

/** Reads `/version.json` past every cache. Network errors, failed responses, and non-JSON give null. */
export async function fetchServedBuild(fetcher: typeof fetch = fetch): Promise<string | null> {
    try {
        const response = await fetcher(VERSION_URL, {
            cache: "no-store",
            headers: { Accept: "application/json" },
        });
        if (!response.ok) return null;
        const body: unknown = await response.json();
        if (typeof body !== "object" || body === null) return null;
        const build = (body as { build?: unknown }).build;

        return typeof build === "string" && build !== "" ? build : null;
    } catch {
        return null;
    }
}

/** Keeps the loop guard in memory when sessionStorage throws, as it can in a locked-down browser. */
function guarded(memory: ReloadMemory | null | undefined): ReloadMemory {
    let fallback: string | null = null;

    return {
        getItem(key) {
            try {
                return memory?.getItem(key) ?? fallback;
            } catch {
                return fallback;
            }
        },
        setItem(key, value) {
            fallback = value;
            try {
                memory?.setItem(key, value);
            } catch {
                // The in-memory copy still stops a second reload in this page.
            }
        },
    };
}

export function createBuildCheck(options: BuildCheckOptions): BuildCheck {
    const served = options.served ?? (() => fetchServedBuild());
    const interval = options.intervalMs ?? DEFAULT_INTERVAL_MS;
    const now = options.now ?? Date.now;
    const memory = guarded(options.memory);
    const hasUnsavedInput = options.hasUnsavedInput ?? (() => false);
    const isEditing = options.isEditing ?? (() => false);

    // The page has just loaded index.html, which is never cached, so the first read waits a full interval.
    let lastRead = now();
    let reading = false;
    let target: string | null = null;
    // A newer build that is not loaded yet, because the page held unsaved input. A navigation loads it.
    let waiting: string | null = null;

    /** Loads the page once per remembered build. A second attempt for the same id would be a loop. */
    const once = (key: string, build: string, go: () => void): boolean => {
        if (memory.getItem(key) === build) return false;
        memory.setItem(key, build);
        go();

        return true;
    };

    /** A full load would lose a draft, or what the user is typing. */
    const busy = () => hasUnsavedInput() || isEditing();

    /** Loads the navigation target in full, unless the page is busy. */
    const navigateToWaiting = () => {
        if (waiting === null || target === null || busy()) return;
        const href = target;
        once(RELOAD_KEY, waiting, () => options.assign(href));
    };

    const navigateTo = (newer: string) => {
        waiting = newer;
        navigateToWaiting();
    };

    const resumeTo = (newer: string) => {
        waiting = newer;
        if (busy()) return;
        once(RELOAD_KEY, newer, options.reload);
    };

    const read = (then: (newer: string) => void) => {
        if (reading || now() - lastRead < interval) return;
        reading = true;
        lastRead = now();
        void served()
            .then((build) => {
                if (build !== null && build !== options.build) then(build);
            })
            .finally(() => {
                reading = false;
            });
    };

    return {
        navigated(href) {
            target = href;
            if (waiting !== null) {
                navigateToWaiting();

                return;
            }
            read(navigateTo);
        },
        settled() {
            // A navigation away from a draft starts while the draft's blocker is still registered.
            navigateToWaiting();
        },
        resumed() {
            read(resumeTo);
        },
        chunkFailed() {
            // The failed file belongs to this build, so the guard names this build: one reload per build.
            return once(CHUNK_RELOAD_KEY, options.build, options.reload);
        },
    };
}

/** The messages browsers give a failed `import()`: Chromium, WebKit, and Firefox. */
const IMPORT_FAILURE =
    /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i;

export function isImportFailure(reason: unknown): boolean {
    return reason instanceof Error && IMPORT_FAILURE.test(reason.message);
}

type UnloadBlocker = { enableBeforeUnload?: (() => boolean) | boolean };

/**
 * Whether a router blocker would ask before the page unloads, as TanStack's history does: a blocker
 * without `enableBeforeUnload` asks. The app registers one for each unsaved draft.
 */
export function blocksUnload(blockers: readonly UnloadBlocker[]): boolean {
    return blockers.some((blocker) => {
        const enabled = blocker.enableBeforeUnload ?? true;

        return typeof enabled === "function" ? enabled() : enabled;
    });
}

type NavigationEvent = { toLocation: { href: string }; hrefChanged: boolean };

type Navigations = {
    subscribe: (
        event: "onBeforeLoad" | "onResolved",
        listener: (event: NavigationEvent) => void,
    ) => () => void;
};

type InstallOptions = {
    build: string;
    router: Navigations;
    hasUnsavedInput: () => boolean;
    window?: Window;
};

const NON_TEXT_INPUTS = new Set([
    "button",
    "checkbox",
    "color",
    "file",
    "hidden",
    "image",
    "radio",
    "range",
    "reset",
    "submit",
]);

/** A focused text field, where a reload would drop what the user is typing. */
export function isEditingField(element: Element | null): boolean {
    if (element === null) return false;
    const field = element as Partial<HTMLInputElement>;
    const writable = field.readOnly !== true && field.disabled !== true;
    if (element.tagName === "TEXTAREA") return writable;
    if (element.tagName === "INPUT") return writable && !NON_TEXT_INPUTS.has(field.type ?? "text");

    return (element as Partial<HTMLElement>).isContentEditable === true;
}

/** Wires the check to the router, page visibility, the back-forward cache, and failed chunk loads. */
export function installBuildCheck({
    build,
    router,
    hasUnsavedInput,
    window: win = window,
}: InstallOptions): () => void {
    let memory: ReloadMemory | null = null;
    try {
        memory = win.sessionStorage;
    } catch {
        memory = null;
    }
    const check = createBuildCheck({
        build,
        assign: (href) => win.location.assign(href),
        reload: () => win.location.reload(),
        memory,
        hasUnsavedInput,
        isEditing: () => isEditingField(win.document.activeElement),
    });

    const unsubscribers = [
        router.subscribe("onBeforeLoad", (event) => {
            if (event.hrefChanged) check.navigated(event.toLocation.href);
        }),
        // React removes the left page's blockers in an effect after the router resolves.
        router.subscribe("onResolved", () => void win.setTimeout(() => check.settled(), 0)),
    ];
    const onVisibility = () => {
        if (win.document.visibilityState === "visible") check.resumed();
    };
    const onPageShow = (event: PageTransitionEvent) => {
        if (event.persisted) check.resumed();
    };
    const onPreloadError = (event: Event) => {
        if (check.chunkFailed()) event.preventDefault();
    };
    const onRejection = (event: PromiseRejectionEvent) => {
        if (isImportFailure(event.reason) && check.chunkFailed()) event.preventDefault();
    };

    win.document.addEventListener("visibilitychange", onVisibility);
    win.addEventListener("pageshow", onPageShow);
    win.addEventListener("vite:preloadError", onPreloadError);
    win.addEventListener("unhandledrejection", onRejection);

    return () => {
        for (const unsubscribe of unsubscribers) unsubscribe();
        win.document.removeEventListener("visibilitychange", onVisibility);
        win.removeEventListener("pageshow", onPageShow);
        win.removeEventListener("vite:preloadError", onPreloadError);
        win.removeEventListener("unhandledrejection", onRejection);
    };
}
