import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const browser = await chromium.launch();
try {
    for (const scenario of [
        "available",
        "disabled",
        "forbidden",
        "missing",
        "offline",
        "invalid",
        "legacy",
    ]) {
        const page = await browser.newPage();
        let enabled = scenario !== "disabled";
        let posts = 0;
        const requests = [];
        if (scenario === "legacy") {
            await page.addInitScript(() =>
                sessionStorage.setItem(
                    "annotate:service",
                    JSON.stringify({ mode: "t3", serviceUrl: "" }),
                ),
            );
        }
        await page.route("https://annotation.test/**", async (route) => {
            const path = new URL(route.request().url()).pathname;
            requests.push(path);
            if (route.request().method() === "POST") posts++;
            if (path === "/index.js") {
                return route.fulfill({
                    contentType: "text/javascript",
                    body: await readFile(new URL("../dist/index.js", import.meta.url)),
                });
            }
            if (path === "/custom/tasks/status") {
                if (scenario === "offline") return route.abort("connectionrefused");
                return route.fulfill({
                    json: scenario === "invalid" ? { data: {} } : { data: { enabled } },
                });
            }
            if (path === "/annotations")
                return route.fulfill({
                    status: scenario === "forbidden" ? 403 : 200,
                    json: { data: [] },
                });
            const options = {
                serviceUrl: scenario === "missing" ? undefined : "/annotations",
                orbit: { tasksStatusUrl: "/custom/tasks/status" },
                thread: { id: "test-thread" },
                dictation: { autoStart: false },
            };
            return route.fulfill({
                contentType: "text/html",
                body: `<h1 style="padding:80px">Target</h1><script type="module">import {mountAnnotation} from "/index.js"; mountAnnotation(${JSON.stringify(options)});</script>`,
            });
        });
        await page.goto("https://annotation.test");
        await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
        const option = page
            .getByLabel("Delivery mode", { exact: true })
            .locator('option[value="orbit"]');
        const available = ["available", "legacy"].includes(scenario);
        if (available) {
            await page.waitForFunction(
                () =>
                    !document
                        .querySelector("#laravel-toolbar-annotation-host")
                        .shadowRoot.querySelector('option[value="orbit"]').disabled,
            );
            assert.equal(await option.evaluate((element) => element.disabled), false, scenario);
            assert.equal(
                await page.getByLabel("Delivery mode", { exact: true }).inputValue(),
                "orbit",
            );
            assert.equal(
                requests.includes("/api/v1/tasks/status"),
                false,
                "Uses the custom tasks status endpoint",
            );
            if (scenario === "available") {
                // Recheck immediately before sending, even if settings previously passed.
                enabled = false;
                await page.getByRole("button", { name: "Close settings", exact: true }).click();
                await page
                    .getByRole("button", { name: "Enter annotation mode", exact: true })
                    .click();
                await page.locator("h1").click();
                await page.locator("textarea").fill("Blocked when tasks become disabled");
                await page.locator("[data-annotation-submit]").click();
                await page.getByRole("button", { name: "Edit annotation 1", exact: true }).click();
                await page
                    .getByRole("alert")
                    .filter({ hasText: "Enable the tasks extension in Orbit." })
                    .waitFor();
                assert.equal(posts, 0);
                await page.getByRole("button", { name: "Retry delivery", exact: true }).click();
                await page.getByRole("button", { name: "Retry delivery", exact: true }).waitFor();
                assert.equal(posts, 0);
            }
        } else {
            const reasons = {
                disabled: "Enable the tasks extension in Orbit.",
                forbidden: "Cannot access Orbit annotations (HTTP 403).",
                missing: "No Orbit annotation service configured.",
                offline: "Cannot reach Orbit. Check the connection.",
                invalid: "Invalid Orbit tasks status response.",
            };
            await page.getByText(reasons[scenario], { exact: true }).waitFor();
            assert.equal(await option.evaluate((element) => element.disabled), true, scenario);
            assert.equal(posts, 0);
            if (scenario === "missing") {
                assert.equal(
                    await page.getByLabel("Delivery mode", { exact: true }).inputValue(),
                    "server",
                );
                assert.equal(requests.includes("/custom/tasks/status"), false);
            }
        }
        await page.close();
    }
    console.log(
        "Orbit: availability, disabled tasks, denied access, missing configuration, offline, invalid responses, legacy mode, and submission rechecks passed",
    );
} finally {
    await browser.close();
}
