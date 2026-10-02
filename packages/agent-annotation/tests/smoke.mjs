import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

const browser = await chromium.launch({
    headless: true,
    args: ["--use-fake-device-for-media-stream", "--use-fake-ui-for-media-stream"],
});
try {
    for (const mode of ["inject", "module"]) {
        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        await page.addInitScript(() => {
            window.microphoneRequests = 0;
            navigator.mediaDevices.getUserMedia = async () => {
                window.microphoneRequests += 1;
                throw new Error("Unexpected microphone request");
            };
        });
        page.on("pageerror", (error) => errors.push(error.message));
        await page.context().route("https://annotation.test/**", async (route) => {
            const url = new URL(route.request().url());
            if (url.pathname === "/thread") {
                await route.fulfill({ json: { id: "detected-thread", title: "Annotation" } });
            } else if (url.pathname === "/api/v1/tasks/status") {
                await route.fulfill({ json: { data: { enabled: true } } });
            } else if (url.pathname === "/annotations") {
                await route.fulfill({ json: { data: [] } });
            } else if (url.pathname.endsWith(".js")) {
                await route.fulfill({
                    contentType: "text/javascript; charset=utf-8",
                    body: await readFile(new URL(`../dist${url.pathname}`, import.meta.url)),
                });
            } else {
                const script =
                    mode === "inject"
                        ? '<script>window.__AGENT_ANNOTATION__ = {serviceUrl:"/annotations",thread:{discoveryUrl:"/thread"}}</script><script src="/inject.js"></script>'
                        : '<script type="module">import { mountAnnotation } from "/index.js"; window.annotation = mountAnnotation({serviceUrl:"/annotations",thread:{discoveryUrl:"/thread"}});</script>';
                await route.fulfill({
                    contentType: "text/html; charset=utf-8",
                    body: `<!doctype html><html><body><h1>Plain host</h1>${script}</body></html>`,
                });
            }
        });
        await page.goto("https://annotation.test/");
        const button = page.getByRole("button", { name: "Enter annotation mode" });
        await button.waitFor();
        const size = await button.boundingBox();
        assert.ok(size.width >= 20 && size.height >= 20, "bundle includes control styles");
        await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
        await page.getByLabel("Delivery mode", { exact: true }).selectOption("orbit");
        await page.getByText("Detected: Annotation", { exact: true }).waitFor();
        assert.equal(
            await page.getByLabel("T3 thread ID", { exact: true }).inputValue(),
            "detected-thread",
        );
        await page.getByLabel("T3 thread ID", { exact: true }).fill("manual-thread");
        await page.getByRole("button", { name: "Save", exact: true }).click();
        await page.reload();
        await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
        assert.equal(
            await page.getByLabel("T3 thread ID", { exact: true }).inputValue(),
            "manual-thread",
        );
        const sibling = await page.context().newPage();
        await sibling.goto("https://annotation.test/");
        await sibling.getByRole("button", { name: "Annotation settings", exact: true }).click();
        await sibling.getByLabel("Delivery mode", { exact: true }).selectOption("orbit");
        await sibling.getByText("Detected: Annotation", { exact: true }).waitFor();
        assert.equal(
            await sibling.getByLabel("T3 thread ID", { exact: true }).inputValue(),
            "detected-thread",
        );
        await sibling.close();
        await page.getByLabel("T3 thread ID", { exact: true }).fill("");
        await page.getByRole("button", { name: "Save", exact: true }).click();
        await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
        assert.equal(await page.evaluate(() => sessionStorage.getItem("annotate:t3-thread")), "");
        assert.equal(await page.getByLabel("T3 thread ID", { exact: true }).inputValue(), "");
        await page.getByRole("button", { name: "Save", exact: true }).click();
        assert.equal(await page.evaluate(() => sessionStorage.getItem("annotate:t3-thread")), "");
        await page.reload();
        await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
        await page.getByRole("button", { name: "Close settings" }).click();
        await button.click();
        assert.equal(
            await page
                .locator("html")
                .evaluate((el) => el.classList.contains("laravel-toolbar-annotating")),
            true,
        );
        assert.equal(await page.evaluate(() => window.microphoneRequests), 0);
        await page.keyboard.press("Escape");
        await page.evaluate((mode) => {
            const handle =
                mode === "module" ? window.annotation : window.AgentAnnotation.mountAnnotation();
            handle.destroy();
        }, mode);
        assert.equal(await page.locator("#laravel-toolbar-annotation-host").count(), 0);
        assert.equal(await page.locator("#laravel-toolbar-annotation-page-styles").count(), 0);
        assert.deepEqual(errors, []);
        await page.close();
        console.log(`${mode}: plain-page mount, styles, interaction, and teardown passed`);
    }
    for (const origin of ["https://annotation.test", "http://annotation.test"]) {
        const page = await browser.newPage();
        await page.route(`${origin}/**`, async (route) => {
            if (route.request().url().endsWith("/inject.js")) {
                await route.fulfill({
                    contentType: "text/javascript; charset=utf-8",
                    body: await readFile(new URL("../dist/inject.js", import.meta.url)),
                });
            } else {
                await route.fulfill({
                    contentType: "text/html; charset=utf-8",
                    body: `<!doctype html><html><body><h1>Annotate this heading</h1><script>window.__AGENT_ANNOTATION__ = { dictation: { wsUrl: "wss://speech.test/stream", codec: "pcm" } };</script><script src="/inject.js"></script></body></html>`,
                });
            }
        });
        let connected = false;
        await page.routeWebSocket("wss://speech.test/**", (socket) => {
            connected = true;
            socket.onMessage((message) => {
                if (typeof message === "string" && message.includes('"done"'))
                    socket.send(JSON.stringify({ text: "Make this heading smaller" }));
            });
        });
        await page.goto(origin);
        await page.getByRole("button", { name: "Enter annotation mode" }).click();
        await page.getByRole("heading").click();
        if (origin.startsWith("https:")) {
            const stop = page.getByRole("button", { name: "Stop dictation" });
            await stop.waitFor();
            // Wait for the audio connection before asking for the transcript.
            await page.waitForTimeout(250);
            assert.equal(
                connected,
                true,
                "new annotation starts the speech connection automatically",
            );
            await stop.click();
            await page.waitForFunction(
                () =>
                    document
                        .querySelector("#laravel-toolbar-annotation-host")
                        .shadowRoot.querySelector("textarea").value === "Make this heading smaller",
            );
            await page.getByRole("button", { name: "Show annotation details" }).click();
            const edges = await page.locator("[data-annotation-metadata]").evaluate((panel) => {
                const p = panel.getBoundingClientRect();
                const row = panel.querySelector("dl > div").getBoundingClientRect();
                return { left: row.left - p.left, right: p.right - row.right };
            });
            assert.ok(
                edges.left <= 1.1 && edges.right <= 1.1,
                "property dividers reach panel borders",
            );
        } else {
            const alert = page.getByRole("alert");
            await alert.waitFor();
            assert.match(await alert.textContent(), /requires HTTPS or localhost/);
            assert.equal(connected, false);
        }
        await page.close();
        console.log(`${origin}: automatic recording or actionable failure passed`);
    }
    for (const status of [204, 503]) {
        const page = await browser.newPage();
        let posts = 0;
        let focused = false;
        await page.addInitScript(() => {
            window.microphoneRequests = 0;
            navigator.mediaDevices.getUserMedia = async () => {
                window.microphoneRequests++;
                throw new Error("Microphone must not be used in POST mode");
            };
        });
        await page.route("https://annotation.test/**", async (route) => {
            if (route.request().url().endsWith("inject.js")) {
                await route.fulfill({
                    contentType: "text/javascript; charset=utf-8",
                    body: await readFile(new URL("../dist/inject.js", import.meta.url)),
                });
            } else {
                await route.fulfill({
                    contentType: "text/html; charset=utf-8",
                    body: '<h1>Post dictation</h1><script>window.__AGENT_ANNOTATION__ = { dictation: { provider: "post", postUrl: "http://127.0.0.1:12321/dictate" } };</script><script src="/inject.js"></script>',
                });
            }
        });
        await page.route("http://127.0.0.1:12321/dictate", async (route) => {
            assert.equal(route.request().method(), "POST");
            assert.equal(route.request().postData(), null);
            posts++;
            focused = await page.evaluate(
                () =>
                    document.querySelector("#laravel-toolbar-annotation-host").shadowRoot
                        .activeElement?.tagName === "TEXTAREA",
            );
            await route.fulfill({ status, headers: { "Access-Control-Allow-Origin": "*" } });
        });
        await page.goto("https://annotation.test/");
        await page.getByRole("button", { name: "Enter annotation mode" }).click();
        await page.getByRole("heading").click();
        await page.getByRole("button", { name: "Dictate comment", exact: true }).waitFor();
        assert.equal(posts, 1);
        assert.equal(focused, true);
        assert.equal(await page.evaluate(() => window.microphoneRequests), 0);
        const field = page.locator("[data-annotation-field]");
        await field.click();
        await page.keyboard.type("Make this clearer");
        assert.equal(await field.inputValue(), "Make this clearer");
        await page.keyboard.insertText(" with pasted text");
        assert.equal(await field.inputValue(), "Make this clearer with pasted text");
        await page.getByRole("button", { name: "Show annotation details" }).click();
        await page.locator("[data-annotation-metadata]").waitFor();
        await field.click();
        await page.keyboard.press("End");
        await page.keyboard.type(".");
        assert.equal(await field.inputValue(), "Make this clearer with pasted text.");
        if (status === 503) {
            assert.match(await page.getByRole("alert").textContent(), /HTTP 503/);
        } else {
            assert.equal(await page.getByRole("alert").count(), 0);
        }
        await page.close();
        console.log(
            `POST ${status}: auto trigger, focused field, no microphone, and response handling passed`,
        );
    }
    for (const scenario of ["paste-before-response", "paste-after-response", "failure", "cancel"]) {
        const page = await browser.newPage();
        let starts = 0;
        let stops = 0;
        let finishStop;
        const stopGate = new Promise((resolve) => {
            finishStop = resolve;
        });
        await page.route("https://annotation.test/**", async (route) => {
            if (route.request().url().endsWith("inject.js")) {
                await route.fulfill({
                    contentType: "text/javascript; charset=utf-8",
                    body: await readFile(new URL("../dist/inject.js", import.meta.url)),
                });
            } else {
                await route.fulfill({
                    contentType: "text/html; charset=utf-8",
                    body: '<h1>First target</h1><button id="next" style="position:absolute;left:600px;top:200px" onclick="window.hostClicked=true">Next target</button><button id="third" style="position:absolute;left:900px;top:300px">Ignored target</button><script>window.__AGENT_ANNOTATION__ = {dictation:{provider:"post",postUrl:"http://127.0.0.1:12321/dictate",stopUrl:"http://127.0.0.1:12321/dictate-stop"}};</script><script src="/inject.js"></script>',
                });
            }
        });
        await page.route("http://127.0.0.1:12321/*", async (route) => {
            assert.equal(route.request().method(), "POST");
            if (route.request().url().endsWith("dictate-stop")) {
                stops++;
                await stopGate;
                await route.fulfill({
                    status: scenario === "failure" ? 503 : 204,
                    headers: { "Access-Control-Allow-Origin": "*" },
                });
            } else {
                starts++;
                await route.fulfill({
                    status: 204,
                    headers: { "Access-Control-Allow-Origin": "*" },
                });
            }
        });
        await page.goto("https://annotation.test/");
        await page.getByRole("button", { name: "Enter annotation mode" }).click();
        await page.getByRole("heading").click();
        await page.getByRole("button", { name: "Dictate comment", exact: true }).waitFor();
        await page.locator("#next").click();
        await page.getByPlaceholder("Waiting for paste…").waitFor();
        assert.equal(await page.getByRole("status").count(), 0);
        await page.locator("#third").click();
        if (scenario === "paste-before-response")
            await page.keyboard.insertText("Final dictated comment");
        finishStop();
        if (scenario === "paste-after-response") {
            await page.waitForTimeout(150);
            await page.keyboard.insertText("Final dictated comment");
        }
        if (scenario.startsWith("paste-")) {
            await page.waitForFunction(() => {
                const value = JSON.parse(localStorage.getItem("toolbar-annotations-/") ?? "[]");
                return value.some((item) => item.comment === "Final dictated comment");
            });
            await page.waitForFunction(
                () =>
                    document
                        .querySelector("#laravel-toolbar-annotation-host")
                        .shadowRoot.querySelector("textarea")?.value === "",
            );
            await page.getByRole("button", { name: "Dictate comment", exact: true }).waitFor();
            assert.equal(starts, 2);
        } else if (scenario === "failure") {
            await page.getByRole("alert").waitFor();
            assert.match(await page.getByRole("alert").textContent(), /503/);
            assert.equal(starts, 1);
        } else {
            await page.keyboard.press("Escape");
            await page.waitForFunction(
                () =>
                    !document
                        .querySelector("#laravel-toolbar-annotation-host")
                        .shadowRoot.querySelector("textarea"),
            );
            assert.equal(starts, 1);
        }
        assert.equal(stops, 1);
        assert.equal(await page.evaluate(() => Boolean(window.hostClicked)), false);
        await page.close();
        console.log(`outside click ${scenario}: passed`);
    }
    for (const control of ["button", "Meta", "Control"]) {
        const page = await browser.newPage();
        await page.route("https://annotation.test/**", async (route) => {
            if (route.request().url().endsWith("inject.js")) {
                await route.fulfill({
                    contentType: "text/javascript; charset=utf-8",
                    body: await readFile(new URL("../dist/inject.js", import.meta.url)),
                });
            } else {
                await route.fulfill({
                    contentType: "text/html; charset=utf-8",
                    body: "<h1>Annotate this page</h1>",
                });
            }
        });
        await page.goto("https://annotation.test/");
        await page.evaluate(() => {
            const pins = Array.from({ length: 15 }, (_, i) => ({
                id: `saved-${i}`,
                x: 50,
                y: 150,
                comment: `Saved pin ${i}`,
                element: "h1",
                elementPath: "h1",
                timestamp: Date.now(),
            }));
            localStorage.setItem("toolbar-annotations-/", JSON.stringify(pins));
            localStorage.setItem("toolbar-annotations-/other", JSON.stringify(pins));
            localStorage.setItem("unrelated-setting", "keep");
        });
        await page.addScriptTag({ url: "https://annotation.test/inject.js" });
        const enter = page.getByRole("button", { name: "Enter annotation mode", exact: true });
        await enter.waitFor();
        if (control === "button") await enter.click();
        else await page.keyboard.press(`${control}+Shift+A`);
        await page.getByRole("button", { name: "Exit annotation mode", exact: true }).waitFor();
        await page.getByRole("heading").click();
        const field = page.locator("[data-annotation-field]");
        await field.click();
        await page.keyboard.type("Open draft");
        if (control === "button")
            await page.getByRole("button", { name: "Clear all annotations" }).click();
        else await page.keyboard.press(`${control}+Shift+R`);
        await page.waitForFunction(
            () =>
                !document
                    .querySelector("#laravel-toolbar-annotation-host")
                    .shadowRoot.querySelector("textarea"),
        );
        assert.equal(await page.locator("[data-annotation-marker]").count(), 0);
        assert.deepEqual(
            await page.evaluate(() =>
                Object.keys(localStorage).filter((key) => key.startsWith("toolbar-annotations-")),
            ),
            [],
        );
        assert.equal(await page.evaluate(() => localStorage.getItem("unrelated-setting")), "keep");
        await page.getByRole("button", { name: "Exit annotation mode", exact: true }).waitFor();
        if (control !== "button") {
            await page.keyboard.press(`${control}+Shift+A`);
            await enter.waitFor();
            await page.keyboard.press(`${control}+Shift+A`);
            await page.getByRole("button", { name: "Exit annotation mode", exact: true }).waitFor();
        }
        await page.reload();
        await page.addScriptTag({ url: "https://annotation.test/inject.js" });
        await enter.waitFor();
        assert.equal(await page.locator("[data-annotation-marker]").count(), 0);
        await page.close();
        console.log(
            `clear-all ${control}: removes saved paths and draft, preserves other storage, survives reload`,
        );
    }

    {
        const page = await browser.newPage();
        await page.addInitScript(() => {
            sessionStorage.setItem(
                "annotate:service",
                JSON.stringify({ mode: "t3", serviceUrl: "", configUrl: "" }),
            );
            sessionStorage.setItem("annotate:t3-thread", "test-thread");
        });
        let completeSubmission;
        const submission = new Promise((resolve) => {
            completeSubmission = resolve;
        });
        await page.route("https://annotation.test/**", async (route) => {
            if (route.request().url().endsWith("/api/v1/tasks/status")) {
                await route.fulfill({ json: { data: { enabled: true } } });
            } else if (route.request().url().endsWith("/submit")) {
                if (route.request().method() === "POST") {
                    await submission;
                    await route.fulfill({
                        json: { data: { ...route.request().postDataJSON(), revision: 1 } },
                    });
                } else {
                    await route.fulfill({ json: { data: [] } });
                }
            } else if (route.request().url().endsWith("inject.js")) {
                await route.fulfill({
                    contentType: "text/javascript; charset=utf-8",
                    body: await readFile(new URL("../dist/inject.js", import.meta.url)),
                });
            } else {
                await route.fulfill({
                    contentType: "text/html; charset=utf-8",
                    body: '<h1>Delayed submission</h1><script>window.__AGENT_ANNOTATION__={serviceUrl:"/submit"};</script><script src="/inject.js"></script>',
                });
            }
        });
        await page.goto("https://annotation.test/");
        await page.getByRole("button", { name: "Enter annotation mode" }).click();
        await page.getByRole("heading").click();
        await page.locator("[data-annotation-field]").fill("Clear before the agent responds");
        const pendingRequest = page.waitForRequest(
            (request) =>
                request.url() === "https://annotation.test/submit" && request.method() === "POST",
        );
        await page.getByRole("button", { name: "Add annotation", exact: true }).click();
        await pendingRequest;
        await page.getByRole("button", { name: "Clear all annotations" }).click();
        const response = page.waitForResponse(
            (response) =>
                response.url() === "https://annotation.test/submit" &&
                response.request().method() === "POST",
        );
        completeSubmission();
        await response;
        await page.waitForTimeout(100);
        assert.equal(await page.locator("[data-annotation-marker]").count(), 0);
        assert.deepEqual(
            await page.evaluate(() =>
                Object.keys(localStorage).filter((key) => key.startsWith("toolbar-annotations-")),
            ),
            [],
        );
        await page.close();
        console.log("clear-all: late agent response cannot restore removed pins");
    }
} finally {
    await browser.close();
}
