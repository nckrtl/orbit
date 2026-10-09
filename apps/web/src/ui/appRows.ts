import { GatewayError } from "../api/client";
import type { Instance, Project, ProjectApp } from "../api/types";

/** The app types the Gateway accepts, in the order the select lists them. */
export const APP_TYPES = [
    "laravel-app",
    "symfony-app",
    "monorepo",
    "laravel-package",
    "node-package",
] as const;

export const APPS_LOCKED_NOTE =
    "Apps can't change while this Project has Instances. Remove the Instances, change the apps, then recreate them.";

/** One app as the editor holds it. Every field is text, so an empty web root means none. */
export type AppRow = { name: string; path: string; webRoot: string; type: string };

export const rowsFromApps = (apps: readonly ProjectApp[]): AppRow[] =>
    apps.map((app) => ({
        name: app.name,
        path: app.path,
        webRoot: app.web_root ?? "",
        type: app.type,
    }));

export const emptyRow = (): AppRow => ({ name: "", path: "", webRoot: "", type: APP_TYPES[0] });

/** The `PATCH /api/v1/projects/{id}` body: the complete list, trimmed, with an empty web root as null. */
export function appsPatchBody(rows: readonly AppRow[]): { apps: ProjectApp[] } {
    return {
        apps: rows.map((row) => ({
            name: row.name.trim(),
            path: row.path.trim(),
            web_root: row.webRoot.trim() === "" ? null : row.webRoot.trim(),
            type: row.type,
        })),
    };
}

/** The Gateway refuses an empty list, and every app needs a name and a path. */
export const rowsComplete = (rows: readonly AppRow[]): boolean =>
    rows.length > 0 && rows.every((row) => row.name.trim() !== "" && row.path.trim() !== "");

export const rowsChanged = (rows: readonly AppRow[], apps: readonly ProjectApp[]): boolean =>
    JSON.stringify(appsPatchBody(rows).apps) !==
    JSON.stringify(appsPatchBody(rowsFromApps(apps)).apps);

/**
 * Apps change only while the Project has no Instances. Until the Instances list has loaded, the
 * page cannot tell, so nothing is editable.
 */
export const appsEditable = (
    project: Project,
    instances: readonly Instance[],
    loaded: boolean,
): boolean => loaded && !instances.some((instance) => instance.project.id === project.id);

export const webRootLabel = (webRoot: string | null): string => webRoot ?? "none";

/** One app on a line: its path, web root, and type. */
export const appDetail = (app: ProjectApp): string =>
    `${app.path} · web root ${webRootLabel(app.web_root)} · ${app.type}`;

/** An Instance's effective apps as property rows. An app the Instance overrides says so. */
export function instanceAppProperties(
    instance: Pick<Instance, "apps" | "app_overrides">,
): { name: string; value: string | null }[] {
    const apps = instance.apps ?? [];
    const overrides = instance.app_overrides ?? {};

    if (apps.length === 0) {
        return [{ name: "Apps", value: null }];
    }

    return apps.map((app, index) => ({
        name: index === 0 ? "Apps" : "",
        value: `${app.name} · ${app.path} · web root ${webRootLabel(app.web_root)}${
            Object.hasOwn(overrides, app.name) ? " · override" : ""
        }`,
    }));
}

const FIELDS = { name: "name", path: "path", web_root: "webRoot", type: "type" } as const;

export type AppsFailure = {
    code: string | null;
    message: string;
    /** The row and field `error.details.field` names, such as `apps.1.path`. */
    row: number | null;
    field: keyof AppRow | null;
};

/** What the Gateway said about a refused list, and which input it points at. */
export function appsFailure(error: unknown): AppsFailure | null {
    if (error === null || error === undefined) {
        return null;
    }

    if (!(error instanceof GatewayError)) {
        return {
            code: null,
            message: error instanceof Error ? error.message : "The request failed.",
            row: null,
            field: null,
        };
    }

    const field = (error.details as { field?: unknown } | null)?.field;
    const match =
        typeof field === "string"
            ? /^apps\.(\d+)(?:\.(name|path|web_root|type))?$/.exec(field)
            : null;

    return {
        code: error.code,
        message: error.message,
        row: match === null ? null : Number(match[1]),
        field:
            match === null || match[2] === undefined
                ? null
                : FIELDS[match[2] as keyof typeof FIELDS],
    };
}
