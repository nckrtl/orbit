import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { beforeEach, describe, expect, it } from "vite-plus/test";
import { api, GatewayError, get } from "../api/client";
import type { Fleet } from "../api/queries";
import type { Project } from "../api/types";
import { demoFleet } from "../demo/fleet";
import { ProjectApps } from "./ProjectApps";
import {
    APPS_LOCKED_NOTE,
    appsEditable,
    appsFailure,
    appsPatchBody,
    emptyRow,
    instanceAppProperties,
    rowsChanged,
    rowsComplete,
    rowsFromApps,
} from "./appRows";

let fleet: Fleet;

// A fresh demo Gateway each time, so a saved list does not leak into the next test.
beforeEach(async () => {
    fleet = await demoFleet();
});

const project = (slug: string): Project => {
    const found = fleet.projects.find((row) => row.slug === slug);

    if (found === undefined) {
        throw new Error(`No fixture Project ${slug}.`);
    }

    return found;
};

const render = (row: Project, editable: boolean): string =>
    renderToStaticMarkup(
        createElement(
            QueryClientProvider,
            { client: new QueryClient() },
            createElement(ProjectApps, { project: row, editable }),
        ),
    );

describe("the apps editor", () => {
    it("sends the complete list, trimmed, with an empty web root as null", () => {
        const rows = [
            ...rowsFromApps(project("bravo-docs").apps),
            { name: " docs ", path: "docs ", webRoot: " ", type: "node-package" },
        ];

        expect(appsPatchBody(rows)).toEqual({
            apps: [
                { name: "web", path: ".", web_root: "public", type: "laravel-app" },
                { name: "docs", path: "docs", web_root: null, type: "node-package" },
            ],
        });
    });

    it("saves only a changed list in which every app has a name and a path", () => {
        const apps = project("bravo-docs").apps;
        const rows = rowsFromApps(apps);

        expect(rowsChanged(rows, apps)).toBe(false);
        expect(rowsChanged([...rows, emptyRow()], apps)).toBe(true);
        expect(rowsComplete([...rows, emptyRow()])).toBe(false);
        expect(rowsComplete([...rows, { ...emptyRow(), name: "docs", path: "docs" }])).toBe(true);
        expect(rowsComplete([])).toBe(false);
    });

    it("is editable only while the Project has no Instances and the Instances have loaded", () => {
        expect(appsEditable(project("bravo-docs"), fleet.instances, true)).toBe(true);
        expect(appsEditable(project("bravo-docs"), fleet.instances, false)).toBe(false);
        expect(appsEditable(project("charlie-shop"), fleet.instances, true)).toBe(false);
    });

    it("shows both apps of a two-app Project read-only while it has Instances", () => {
        const html = render(project("charlie-shop"), false);

        expect(html).toContain("storybook");
        expect(html).toContain("storybook · web root dist · node-package");
        expect(html).toContain("web · web root public · laravel-app");
        expect(html).toContain(APPS_LOCKED_NOTE.replace("'", "&#x27;"));
        expect(html).not.toContain("<input");
        expect(html).not.toContain("Add app");
    });

    it("shows a Project without Instances as one editable row per app", () => {
        const html = render(project("bravo-docs"), true);

        expect(html).toContain('aria-label="App 1 name"');
        expect(html).toContain('value="web"');
        expect(html).toContain('value="public"');
        expect(html).not.toContain('aria-label="App 2 name"');
        expect(html).toContain("Add app");
        expect(html).not.toContain("Apps can");
    });

    it("adds app docs to a Project without Instances through the Gateway", async () => {
        const bravo = project("bravo-docs");
        const body = appsPatchBody([
            ...rowsFromApps(bravo.apps),
            { name: "docs", path: "docs", webRoot: "dist", type: "node-package" },
        ]);

        const saved = await api<Project>("PATCH", `/api/v1/projects/${bravo.id}`, body);
        const listed = (await get<Project[]>("/api/v1/projects")).find(
            (row) => row.id === bravo.id,
        );

        // The Gateway stores the apps in name order.
        expect(saved.apps.map((app) => app.name)).toEqual(["docs", "web"]);
        expect(listed?.apps).toEqual(saved.apps);

        const html = render(listed as Project, true);
        expect(html).toContain('aria-label="App 2 name"');
        expect(html).toContain('value="docs"');
    });

    it("names the refusal and the input it points at", async () => {
        const charlie = project("charlie-shop");
        const locked = await api(
            "PATCH",
            `/api/v1/projects/${charlie.id}`,
            appsPatchBody(rowsFromApps(charlie.apps)),
        ).catch((error: unknown) => error);
        const conflict = await api(
            "PATCH",
            `/api/v1/projects/${project("bravo-docs").id}`,
            appsPatchBody([
                { name: "web", path: ".", webRoot: "public", type: "laravel-app" },
                { name: "web", path: "docs", webRoot: "", type: "node-package" },
            ]),
        ).catch((error: unknown) => error);

        expect(appsFailure(locked)).toEqual({
            code: "project.apps_locked_by_instances",
            message:
                "Project [charlie-shop] has Instances, so its apps cannot change. Remove its Instances, change the apps, then recreate the Instances.",
            row: null,
            field: null,
        });
        expect(appsFailure(conflict)).toMatchObject({
            code: "project.app_name_conflict",
            row: 1,
            field: "name",
        });
        expect(
            appsFailure(
                new GatewayError("bad", 422, "project.apps_invalid", { field: "apps.0.web_root" }),
            ),
        ).toMatchObject({ row: 0, field: "webRoot" });
        expect(appsFailure(null)).toBeNull();
    });
});

describe("an Instance's apps", () => {
    it("lists the effective apps and marks an override", () => {
        const staging = fleet.instances.find(
            (instance) => instance.project.slug === "charlie-shop" && instance.name === "staging",
        );

        expect(instanceAppProperties(staging!)).toEqual([
            { name: "Apps", value: "storybook · storybook · web root storybook-static · override" },
            { name: "", value: "web · web · web root public" },
        ]);
        expect(instanceAppProperties({ apps: [], app_overrides: {} })).toEqual([
            { name: "Apps", value: null },
        ]);
    });
});
