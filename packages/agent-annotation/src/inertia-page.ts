export type InertiaPage = {
    component: string;
    props: Record<string, unknown>;
    sharedProps: string[];
    url?: string;
};

export type PropMatch = {
    path: string;
    source: "shared" | "page";
    preview: string;
    kind: "value" | "key";
};

let cachedPage: InertiaPage | null = null;

export function resetInertiaPage(): void {
    cachedPage = null;
}

export function cacheInertiaPage(input: unknown): InertiaPage | null {
    const page = normalizeInertiaPage(input);

    if (page) {
        cachedPage = page;
    }

    return page;
}

export function readInertiaPage(): InertiaPage | null {
    if (cachedPage) {
        return cachedPage;
    }

    return cacheInertiaPage(readRawInertiaPage());
}

export function inertiaPagePath(component: string): string {
    return `resources/js/pages/${component.replace(/^\/+|\/+$/g, "")}.tsx`;
}

export function classifyPropPath(path: string, page: InertiaPage): "shared" | "page" {
    const root = path.split(/[.[[]/, 1)[0] ?? path;

    return page.sharedProps.includes(root) ? "shared" : "page";
}

export function findPropMatch(
    needles: string[],
    page: InertiaPage | null = readInertiaPage(),
): PropMatch | null {
    if (!page) {
        return null;
    }

    const candidates = uniqueNeedles(needles);
    const hits: Array<PropMatch & { score: number }> = [];

    for (const needle of candidates) {
        searchProps(page.props, needle.toLowerCase(), "", hits);
        searchPropKeys(page.props, keyVariants(needle), "", hits);
    }

    hits.sort((left, right) => right.score - left.score || left.path.length - right.path.length);

    const best = hits[0];

    if (!best) {
        return null;
    }

    return {
        path: best.path,
        source: classifyPropPath(best.path, page),
        preview: best.preview,
        kind: best.kind,
    };
}

function readRawInertiaPage(): unknown {
    const script = document.querySelector("script[data-page]");

    if (script?.textContent) {
        try {
            return JSON.parse(script.textContent);
        } catch {
            // Fall through to the root dataset.
        }
    }

    const root = document.getElementById("app") ?? document.querySelector("[data-page]");
    const raw = root instanceof HTMLElement ? root.dataset.page : undefined;

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function normalizeInertiaPage(input: unknown): InertiaPage | null {
    if (!input || typeof input !== "object") {
        return null;
    }

    const record = input as {
        component?: unknown;
        props?: unknown;
        sharedProps?: unknown;
        url?: unknown;
    };

    if (typeof record.component !== "string" || record.component === "") {
        return null;
    }

    return {
        component: record.component,
        props:
            record.props && typeof record.props === "object" && !Array.isArray(record.props)
                ? (record.props as Record<string, unknown>)
                : {},
        sharedProps: Array.isArray(record.sharedProps)
            ? record.sharedProps.filter((value): value is string => typeof value === "string")
            : [],
        url: typeof record.url === "string" ? record.url : undefined,
    };
}

function uniqueNeedles(needles: string[]): string[] {
    return [
        ...new Set(needles.map((needle) => needle.trim()).filter((needle) => needle.length >= 4)),
    ];
}

function keyVariants(text: string): string[] {
    const slug = text
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_|_$/g, "");
    const camel = slug.replace(/_([a-z])/g, (_, letter: string) => letter.toUpperCase());

    return [...new Set([slug, camel].filter((value) => value.length >= 4))];
}

function searchProps(
    value: unknown,
    needle: string,
    path: string,
    hits: Array<PropMatch & { score: number }>,
    depth = 0,
): void {
    if (hits.length > 24 || depth > 8 || value == null) {
        return;
    }

    if (typeof value === "string") {
        const normalized = value.toLowerCase();

        if (normalized === needle || normalized.includes(needle)) {
            hits.push({
                path,
                source: "page",
                preview: value.slice(0, 120),
                kind: "value",
                score: (normalized === needle ? 200 : 100) + needle.length,
            });
        }

        return;
    }

    if (typeof value === "number" && String(value) === needle) {
        hits.push({ path, source: "page", preview: String(value), kind: "value", score: 160 });
        return;
    }

    if (typeof value !== "object") {
        return;
    }

    if (Array.isArray(value)) {
        value.slice(0, 40).forEach((item, index) => {
            searchProps(item, needle, `${path}[${index}]`, hits, depth + 1);
        });
        return;
    }

    Object.entries(value)
        .slice(0, 60)
        .forEach(([key, child]) => {
            searchProps(child, needle, path ? `${path}.${key}` : key, hits, depth + 1);
        });
}

function searchPropKeys(
    value: unknown,
    keys: string[],
    path: string,
    hits: Array<PropMatch & { score: number }>,
    depth = 0,
): void {
    if (hits.length > 24 || depth > 8 || !value || typeof value !== "object") {
        return;
    }

    if (Array.isArray(value)) {
        value.slice(0, 40).forEach((item, index) => {
            searchPropKeys(item, keys, `${path}[${index}]`, hits, depth + 1);
        });
        return;
    }

    Object.entries(value)
        .slice(0, 60)
        .forEach(([key, child]) => {
            const next = path ? `${path}.${key}` : key;

            if (keys.includes(key)) {
                hits.push({
                    path: next,
                    source: "page",
                    preview: typeof child === "string" ? child.slice(0, 120) : key,
                    kind: "key",
                    score: 80 + key.length,
                });
            }

            searchPropKeys(child, keys, next, hits, depth + 1);
        });
}
