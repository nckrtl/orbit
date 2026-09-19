import { captureAppearance } from "@/annotation/appearance";
import { inertiaPagePath, readInertiaPage } from "@/annotation/inertia-page";
import {
    formatComponentLocation,
    formatReactHoverPath,
    inspectReactStack,
    resolveApplyTarget,
    resolveReactSource,
} from "@/annotation/react-inspect";
import type { Annotation } from "@/annotation/types";
import { getActiveToolbarData } from "@/core/request-history";

export function resolveAnnotationContext(
    element: HTMLElement,
): Pick<Annotation, "component" | "controller" | "route" | "react" | "components" | "appearance"> {
    const request = getActiveToolbarData().request;
    const page = readInertiaPage();
    const apply = resolveApplyTarget(element);
    const source = apply ?? resolveReactSource(element);
    const inertiaFile = page?.component ? inertiaPagePath(page.component) : undefined;

    return omitEmpty({
        component: formatComponentLocation(source?.file, source?.line) ?? inertiaFile,
        controller: cleanValue(request?.controller_action),
        route: cleanValue(request?.route_name),
        react: formatReactHoverPath(element, page?.component) ?? undefined,
        components: inspectReactStack(element),
        appearance: captureAppearance(element),
    });
}

function cleanValue(value?: string | null): string | undefined {
    if (!value || value === "-") {
        return undefined;
    }

    return value;
}

function omitEmpty<T extends Record<string, unknown>>(source: T): T {
    return Object.fromEntries(
        Object.entries(source).filter(([, value]) => {
            if (value == null || value === "") {
                return false;
            }

            return !(Array.isArray(value) && value.length === 0);
        }),
    ) as T;
}
