import { expect, it } from "vite-plus/test";
import { page } from "vite-plus/test/browser";
import { queryClient } from "../../src/api/queryClient";
import { setTransport } from "../../src/api/client";
import { openApp } from "./app";

it("saves an uppercase project code and refreshes it from the Gateway", async () => {
    const app = await openApp("/");
    let code = "OLD";
    let saved: unknown;
    setTransport(async (method, path, body) => {
        if (method === "PATCH" && path === "/api/v1/projects/1") {
            saved = body;
            code = (body as { code: string }).code;
            return { status: 200, payload: { data: { id: 1, code } } };
        }
        const response = await app.gateway.transport(method, path, body);
        if (path === "/api/v1/projects") {
            const payload = response.payload as { data: { id: number; code?: string }[] };
            return {
                ...response,
                payload: {
                    ...payload,
                    data: payload.data.map((project) => ({ ...project, code })),
                },
            };
        }
        return response;
    });
    await queryClient.invalidateQueries({ queryKey: ["projects"] });
    await app.router.navigate({ to: "/$section/$id", params: { section: "projects", id: "1" } });
    const input = page.getByRole("textbox", { name: "Project code" });
    await expect.element(input).toHaveValue("OLD");
    await input.fill("orb");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await expect.poll(() => saved).toEqual({ code: "ORB" });
    await expect.element(input).toHaveValue("ORB");
    await expect
        .element(page.getByRole("button", { name: "Save", exact: true }))
        .not.toBeInTheDocument();
});
