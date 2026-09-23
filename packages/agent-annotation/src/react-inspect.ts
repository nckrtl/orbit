type DebugSource = {
    fileName?: string;
    lineNumber?: number;
};

type ReactFiber = {
    type?: unknown;
    return?: ReactFiber | null;
    pendingProps?: Record<string, unknown> | null;
    memoizedProps?: Record<string, unknown> | null;
    _debugSource?: DebugSource | null;
    _debugOwner?: ReactFiber | null;
    _debugStack?: { stack?: string } | Error | string | null;
};

export type ReactComponentInfo = {
    name: string;
    stack: string[];
    file?: string;
    line?: number;
    props: string[];
};

export type ComponentFrame = {
    name: string;
    file?: string;
    line?: number;
};

const SKIP_NAME =
    /^(Inertia|Head|Fragment|Suspense|StrictMode|Slot|Primitive|Presence|VisuallyHidden|SidebarInset|AppContent|AppShell|AppSidebarLayout)$|Context$|Provider$|^Card([A-Z]|$)/;
const SKIP_PROP =
    /^(children|className|class|style|id|key|ref|dangerouslySetInnerHTML)$|^on[A-Z]|^data-|^aria-/;

export function formatReactHoverPath(element: Element, page?: string | null): string | null {
    const info = inspectReactComponent(element);

    if (!info || info.stack.length === 0) {
        return null;
    }

    const stack = stopAtPageComponent(info.stack, page).slice(0, 3);

    if (stack.length === 0) {
        return null;
    }

    return [...stack]
        .reverse()
        .map((name) => `<${name}>`)
        .join(" ");
}

export function inspectReactStack(element: Element): ComponentFrame[] {
    const fiber = fiberFromNode(element);

    if (!fiber) {
        return [];
    }

    const frames: ComponentFrame[] = [];
    let current: ReactFiber | null | undefined = fiber;

    while (current && frames.length < 12) {
        const name = componentName(current.type);

        if (name && !SKIP_NAME.test(name)) {
            const source = sourceFromFiber(current);
            frames.push({
                name,
                file: source?.file,
                line: source?.line,
            });
        }

        current = current.return;
    }

    return frames;
}

export function resolveApplyTarget(element: Element): ComponentFrame | null {
    return (
        inspectReactStack(element).find((frame) => frame.file && !isPrimitiveSource(frame.file)) ??
        null
    );
}

export function inspectReactComponent(
    element: Element,
    pageProps: Record<string, unknown> = {},
): ReactComponentInfo | null {
    const fiber = fiberFromNode(element);

    if (!fiber) {
        return null;
    }

    const components: ReactComponentInfo[] = [];
    let current: ReactFiber | null | undefined = fiber;

    while (current && components.length < 12) {
        const name = componentName(current.type);

        if (name && !SKIP_NAME.test(name)) {
            const source = sourceFromFiber(current);
            components.push({
                name,
                stack: [],
                file: source?.file,
                line: source?.line,
                props: Object.keys(current.memoizedProps ?? {}).filter(
                    (key) => !SKIP_PROP.test(key),
                ),
            });
        }

        current = current.return;
    }

    if (components.length === 0) {
        return null;
    }

    const pageKeys = Object.keys(pageProps);
    const preferred =
        components.find((component) =>
            component.props.some((prop) => pageKeys.includes(prop) && prop !== "errors"),
        ) ?? components[0];

    return {
        ...preferred,
        stack: components.map((component) => component.name),
    } as ReactComponentInfo;
}

export function stopAtPageComponent(stack: string[], page?: string | null): string[] {
    if (!page) {
        return stack.slice(0, 6);
    }

    const index = stack.findIndex((name) => isPageComponentName(name, page));

    return (index === -1 ? stack : stack.slice(0, index + 1)).slice(0, 6);
}

export function isPageComponentName(name: string, page: string): boolean {
    const last = page.split("/").pop() ?? page;
    const compact = page.replace(/\W/g, "");
    const normalized = name.replace(/\W/g, "");

    return (
        normalized === last ||
        normalized === compact ||
        normalized === `${last}Page` ||
        normalized === `${compact}Page` ||
        (last.length > 3 && normalized.endsWith(last))
    );
}

function fiberFromNode(element: Element): ReactFiber | null {
    const key = Object.getOwnPropertyNames(element).find(
        (name) => name.startsWith("__reactFiber$") || name.startsWith("__reactInternalInstance$"),
    );

    return key ? ((element as unknown as Record<string, ReactFiber>)[key] ?? null) : null;
}

function componentName(type: unknown): string | null {
    if (typeof type === "function") {
        const fn = type as Function & { displayName?: string };
        return fn.displayName || fn.name || null;
    }

    if (type && typeof type === "object") {
        const record = type as {
            displayName?: string;
            name?: string;
            render?: { displayName?: string; name?: string };
        };

        return (
            record.displayName ||
            record.name ||
            record.render?.displayName ||
            record.render?.name ||
            null
        );
    }

    return null;
}

export function resolveReactSource(element: Element): { file: string; line?: number } | null {
    const fiber = fiberFromNode(element);

    if (!fiber) {
        return null;
    }

    const sources: { file: string; line?: number }[] = [];
    let current: ReactFiber | null | undefined = fiber;
    let depth = 0;

    while (current && depth < 16) {
        const source = sourceFromFiber(current);

        if (
            source &&
            !sources.some((item) => item.file === source.file && item.line === source.line)
        ) {
            sources.push(source);
        }

        current = current.return ?? current._debugOwner ?? null;
        depth += 1;
    }

    return (
        sources.find((source) => isAppSource(source.file) && !isPrimitiveSource(source.file)) ??
        sources.find((source) => isAppSource(source.file)) ??
        sources[0] ??
        null
    );
}

export function formatComponentLocation(file?: string, line?: number): string | undefined {
    if (!file) {
        return undefined;
    }

    return line ? `${file}:${line}` : file;
}

export function parseDebugStack(stack?: string | null): { file: string; line: number } | null {
    if (!stack) {
        return null;
    }

    for (const frame of stack.split("\n")) {
        if (/node_modules|jsx-dev-runtime|jsx-runtime|react-stack-bottom-frame/.test(frame)) {
            continue;
        }

        const match = frame.match(
            /((?:resources\/js|app)\/[^\s:?)]+)(?:\?[^:)\s]*)?:(\d+)(?::\d+)?/,
        );

        if (!match?.[1]) {
            continue;
        }

        const file = normalizeSourceFile(match[1].replace(/\?.*$/, ""));

        if (file) {
            return { file, line: Number(match[2]) };
        }
    }

    return null;
}

function sourceFromFiber(fiber: ReactFiber): { file: string; line?: number } | null {
    const debug = fiber._debugSource;
    const propSource = (fiber.pendingProps?.__source ?? fiber.memoizedProps?.__source) as
        | DebugSource
        | undefined;
    const named = debug?.fileName ? debug : propSource?.fileName ? propSource : null;

    if (named?.fileName) {
        const file = normalizeSourceFile(named.fileName);

        if (file) {
            return { file, line: named.lineNumber };
        }
    }

    return parseDebugStack(stackFromFiber(fiber));
}

function stackFromFiber(fiber: ReactFiber): string | undefined {
    const stack = fiber._debugStack;

    if (typeof stack === "string") {
        return stack;
    }

    if (stack && typeof stack === "object" && "stack" in stack) {
        return stack.stack;
    }

    return undefined;
}

function isAppSource(file: string): boolean {
    return file.startsWith("resources/js/") || file.startsWith("app/");
}

export function isPrimitiveSource(file: string): boolean {
    return /(?:^|\/)components\/ui\//.test(file);
}

function normalizeSourceFile(fileName?: string): string | undefined {
    if (!fileName) {
        return undefined;
    }

    const clean = fileName.replace(/^file:\/\//, "").replace(/\?.*$/, "");
    const resources = clean.match(/\/(resources\/js\/.+)$/);

    if (resources?.[1]) {
        return resources[1];
    }

    const app = clean.match(/\/(app\/.+)$/);

    return app?.[1] ?? clean;
}
