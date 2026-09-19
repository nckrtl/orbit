import type { Annotation, AnnotationDraft } from "@/annotation/types";

type ContextFields = Pick<
    Annotation,
    | "component"
    | "controller"
    | "route"
    | "url"
    | "pathname"
    | "screenSize"
    | "scrollPosition"
    | "breakpoint"
    | "screenshot"
    | "boundingBox"
    | "elementPath"
    | "isFixed"
    | "react"
    | "components"
    | "appearance"
>;

export function annotationContextFields(current: AnnotationDraft | Annotation): ContextFields {
    const fields: ContextFields = {
        component: current.component,
        controller: current.controller,
        route: current.route,
        url: current.url,
        pathname: current.pathname,
        screenSize: current.screenSize,
        scrollPosition: current.scrollPosition,
        breakpoint: current.breakpoint,
        screenshot: current.screenshot,
        boundingBox: current.boundingBox,
        elementPath: current.elementPath,
        isFixed: current.isFixed,
        react: current.react,
        components: current.components,
        appearance: current.appearance,
    };

    return Object.fromEntries(
        Object.entries(fields).filter(([, value]) => value != null && value !== ""),
    ) as ContextFields;
}

export function annotationPayload(
    current: AnnotationDraft,
    comment: string,
): Omit<Annotation, "id" | "timestamp"> {
    return {
        ...annotationContextFields(current),
        x: current.x,
        y: current.y,
        comment: comment.trim(),
        element: current.element,
        url: current.url ?? (typeof window === "undefined" ? undefined : window.location.href),
        pathname:
            current.pathname ??
            (typeof window === "undefined" ? undefined : window.location.pathname),
    };
}

export function annotationMetadataRows(
    current: AnnotationDraft | Annotation,
): { key: string; value: string }[] {
    const payload =
        "comment" in current
            ? annotationPayload(current as AnnotationDraft, current.comment)
            : current;
    const rows: { key: string; value: string }[] = [];

    const add = (key: string, value: unknown) => {
        if (value == null || value === "") {
            return;
        }

        rows.push({
            key,
            value:
                typeof value === "object" && value !== null
                    ? JSON.stringify(value)
                    : value == null
                      ? ""
                      : String(value as string | number | boolean),
        });
    };

    add("URL", payload.url);
    add("Route", payload.route);
    add("Controller", payload.controller);
    add("Component", payload.component);
    add("Element path", payload.elementPath);
    add("Screen size", payload.screenSize);
    add("Bounding box", payload.boundingBox);
    add("Breakpoint", payload.breakpoint);
    add("Scroll position", payload.scrollPosition);

    return rows;
}

export function annotationMetadataDisplayValue(key: string, value: string): string {
    if (key === "URL") {
        return compactUrl(value);
    }

    if (key === "Controller") {
        return compactPhpFilename(value);
    }

    if (key === "Component") {
        return compactComponent(value);
    }

    if (key === "Element path") {
        return lastSegment(value, " > ");
    }

    return value;
}

function compactUrl(value: string): string {
    try {
        const path = new URL(value).pathname || "/";

        if (path === "/") {
            return "/";
        }

        return lastSegment(path.replace(/\/+$/, ""), "/");
    } catch {
        return lastSegment(value.replace(/\/+$/, ""), "/") || "/";
    }
}

function compactPhpFilename(value: string): string {
    const file = value.match(/([^/\\]+\.php)/i)?.[1];

    if (file) {
        return file;
    }

    const className = (value.split("\\").at(-1) ?? value).split("@")[0];

    if (!className) {
        return "";
    }

    return className.endsWith(".php") ? className : `${className}.php`;
}

function compactComponent(value: string): string {
    const match = value.match(/([^/\\]+\.(?:tsx|ts|jsx|js|vue))(?::(\d+))?$/i);

    if (match) {
        return match[2] ? `${match[1]}:${match[2]}` : (match[1] ?? "");
    }

    return lastSegment(value, /[\\/]/);
}

function lastSegment(value: string, separator: string | RegExp): string {
    const parts = value.split(separator).filter(Boolean);

    return parts.at(-1) ?? value;
}
