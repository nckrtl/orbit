import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";
const browser = await chromium.launch();
try {
    const page = await browser.newPage();
    const errors = [];
    let stored = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("https://host.test/**", async (route) => {
        if (route.request().url().endsWith("/index.js")) {
            await route.fulfill({
                contentType: "text/javascript",
                body: await readFile(new URL("../dist/index.js", import.meta.url)),
            });
        } else if (new URL(route.request().url()).pathname === "/annotations") {
            const pathname = new URL(route.request().url()).searchParams.get("pathname");
            if (route.request().method() === "DELETE")
                stored = stored.filter((a) => pathname !== null && a.pathname !== pathname);
            await route.fulfill({ json: { data: stored } });
        } else {
            await route.fulfill({
                contentType: "text/html",
                body: '<h1>Host toolbar</h1><script type="module">import * as api from "/index.js"; window.api=api; window.handle=api.mountAnnotation({floatingControl:false,dictation:{provider:"none"},thread:{id:"host-thread"}});</script>',
            });
        }
    });
    await page.goto("https://host.test");
    await page.waitForFunction(() => window.api);
    assert.equal(await page.locator("[data-orbit-annotation-fab]").count(), 0);
    const result = await page.evaluate(() => {
        const api = window.api;
        const first = api.getAnnotationState();
        const stable = first === api.getAnnotationState();
        let notifications = 0;
        const stop = api.subscribeAnnotationState(() => notifications++);
        api.setAnnotationMode(true);
        const active = api.getAnnotationState().active;
        stop();
        const before = notifications;
        api.setAnnotationMode(false);
        api.saveAnnotationSettings({ mode: "server", serviceUrl: "/annotations", threadId: "" });
        window.handle.destroy();
        window.handle = api.mountAnnotation({
            floatingControl: false,
            dictation: { provider: "none" },
            thread: { id: "host-thread" },
        });
        return {
            stable,
            active,
            notified: before > 0,
            unsubscribed: notifications === before,
            settings: api.getAnnotationSettings(),
        };
    });
    assert.deepEqual(result, {
        stable: true,
        active: true,
        notified: true,
        unsubscribed: true,
        settings: { mode: "server", serviceUrl: "/annotations", threadId: "" },
    });
    // Removing the page's annotations keeps the annotations of other pages.
    stored = [
        { id: "here", comment: "Fix the title", status: "todo", revision: 1, pathname: "/" },
        {
            id: "other-page",
            comment: "Fix the menu",
            status: "todo",
            revision: 1,
            pathname: "/other",
        },
    ];
    await page.evaluate(() =>
        window.api.saveAnnotationSettings({ mode: "server", serviceUrl: "/annotations" }),
    );
    await page.waitForFunction(() => window.api.getAnnotationState().pageCount === 1);
    await page.evaluate(() => window.api.clearPageAnnotations());
    await page.waitForFunction(() => window.api.getAnnotationState().pageCount === 0);
    assert.deepEqual(
        stored.map((a) => a.id),
        ["other-page"],
    );
    await page.evaluate(() => window.handle.destroy());
    assert.equal(await page.locator("#laravel-toolbar-annotation-host").count(), 0);
    assert.deepEqual(errors, []);
    console.log(
        "Host controls: hidden pill, stable snapshots, subscriptions, saved settings, cleared thread, page count, remove page, remount and teardown passed.",
    );
} finally {
    await browser.close();
}
