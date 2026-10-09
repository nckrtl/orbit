import { expect, it } from "vite-plus/test";
import { openApp, pane } from "./app";

const patches = (app: Awaited<ReturnType<typeof openApp>>) =>
    app.gateway.requests
        .filter((request) => request.method === "PATCH" && request.path === "/api/v1/projects/2")
        .map((request) => request.body);

it("adds app docs to a Project without Instances and sends the complete list", async () => {
    const app = await openApp("/projects/2");
    const apps = pane("Apps");

    await expect.element(apps.getByRole("textbox", { name: "App 1 name" })).toHaveValue("web");
    await apps.getByRole("button", { name: "Add app", exact: true }).click();
    await apps.getByRole("textbox", { name: "App 2 name" }).fill("docs");
    await apps.getByRole("textbox", { name: "App 2 path" }).fill("docs");
    await apps.getByRole("combobox", { name: "App 2 type" }).selectOptions("node-package");
    await apps.getByRole("button", { name: "Save apps", exact: true }).click();

    await expect
        .poll(() => patches(app))
        .toEqual([
            {
                apps: [
                    { name: "web", path: ".", web_root: "public", type: "laravel-app" },
                    { name: "docs", path: "docs", web_root: null, type: "node-package" },
                ],
            },
        ]);
    // The Gateway stores the apps in name order, and the editor starts again from that list.
    await expect.element(apps.getByRole("textbox", { name: "App 1 name" })).toHaveValue("docs");
    await expect.element(apps.getByRole("textbox", { name: "App 2 name" })).toHaveValue("web");
    await expect
        .element(apps.getByRole("button", { name: "Save apps", exact: true }))
        .not.toBeInTheDocument();
});

it("shows the Gateway's refusal of a duplicate app name inline", async () => {
    await openApp("/projects/2");
    const apps = pane("Apps");

    await apps.getByRole("button", { name: "Add app", exact: true }).click();
    await apps.getByRole("textbox", { name: "App 2 name" }).fill("web");
    await apps.getByRole("textbox", { name: "App 2 path" }).fill("docs");
    await apps.getByRole("button", { name: "Save apps", exact: true }).click();

    await expect
        .element(apps.getByRole("alert"))
        .toHaveTextContent(
            "project.app_name_conflict: App names must be unique within the Project.",
        );
});

it("shows a Project's apps read-only while it has Instances", async () => {
    await openApp("/projects/3");
    const apps = pane("Apps");

    await expect.element(apps).toHaveTextContent("storybook · web root dist · node-package");
    await expect.element(apps).toHaveTextContent("web · web root public · laravel-app");
    await expect
        .element(apps)
        .toHaveTextContent(
            "Apps can't change while this Project has Instances. Remove the Instances, change the apps, then recreate them.",
        );
    await expect.element(apps.getByRole("textbox")).not.toBeInTheDocument();
});
