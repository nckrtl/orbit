import assert from "node:assert/strict";
import { spawn, execFileSync } from "node:child_process";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createServer } from "node:https";
import { createServer as createViteServer } from "vite";
import { chromium } from "playwright";
import { annotationServerProxy } from "../bin/vite.mjs";

const dir = await mkdtemp(join(tmpdir(), "annotate-proxy-test-"));
const children = [];
let browser, vite, web;
async function start() {
    const child = spawn(process.execPath, [
        "bin/serve.mjs",
        "serve",
        "--store",
        join(dir, "stores", String(children.length)),
    ]);
    children.push(child);
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error("Startup timeout")), 5000);
        let output = "";
        child.stdout.on("data", (data) => {
            output += data;
            const match = output.match(/Annotation server URL: (\S+)/);
            if (match) {
                clearTimeout(timer);
                resolve(match[1]);
            }
        });
    });
}
try {
    execFileSync(
        "openssl",
        [
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            join(dir, "key.pem"),
            "-out",
            join(dir, "cert.pem"),
            "-days",
            "1",
            "-subj",
            "/CN=localhost",
        ],
        { stdio: "ignore" },
    );
    vite = await createViteServer({
        configFile: false,
        root: dir,
        server: {
            middlewareMode: true,
            watch: { ignored: ["**/stores/**"] },
        },
        appType: "custom",
        plugins: [annotationServerProxy()],
    });
    web = createServer(
        { key: await readFile(join(dir, "key.pem")), cert: await readFile(join(dir, "cert.pem")) },
        (req, res) => {
            vite.middlewares(req, res, async () => {
                if (req.url === "/inject.js") {
                    res.setHeader("Content-Type", "text/javascript");
                    res.end(await readFile(new URL("../dist/inject.js", import.meta.url)));
                } else {
                    res.setHeader("Content-Type", "text/html");
                    res.end(
                        await vite.transformIndexHtml(
                            "/",
                            '<html><head></head><body><h1 style="padding:80px">Fresh annotation</h1><script>window.__AGENT_ANNOTATION__={dictation:{autoStart:false},thread:{id:"must-not-deliver"}}</script><script src="/inject.js"></script></body></html>',
                        ),
                    );
                }
            });
        },
    );
    await new Promise((resolve) => web.listen(0, "127.0.0.1", resolve));
    const origin = `https://127.0.0.1:${web.address().port}`;
    browser = await chromium.launch();
    const page = await browser.newPage({ ignoreHTTPSErrors: true });
    // Direct browser-to-server loopback calls are deliberately impossible in this test.
    await page.route("http://**", (route) => route.abort("connectionrefused"));
    await page.goto(origin);
    for (let run = 0; run < 2; run++) {
        const url = await start();
        const discovery = await page.evaluate(async (url) => {
            const prefix = `/__annotate/local/${new URL(url).port}/annotations`;
            const list = await (await fetch(prefix)).json();
            const skill = await fetch(list.meta.skillUrl);
            return {
                status: skill.status,
                type: skill.headers.get("content-type"),
                text: await skill.text(),
                count: list.data.length,
            };
        }, url);
        assert.equal(discovery.status, 200);
        assert.match(discovery.type, /^text\/markdown/);
        assert.ok(discovery.text.includes(`ANNOTATIONS_URL='${url}'`));
        assert.equal(discovery.count, 0);

        await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
        await page.getByLabel("Delivery mode").selectOption("server");
        await page.getByLabel("Annotation server URL", { exact: true }).fill(url);
        const serverInput = page.getByLabel("Annotation server URL", { exact: true });
        await serverInput.press("Enter");
        await page
            .getByRole("status")
            .filter({ hasText: "Server reachable" })
            .waitFor({ timeout: 5000 });
        assert.equal(
            await page.getByRole("form", { name: "Annotation settings" }).count(),
            1,
            "Enter checks without closing settings",
        );
        assert.equal(
            (await (await fetch(url)).json()).data.length,
            0,
            "Connection checks never create annotations",
        );
        await serverInput.fill("http://127.0.0.1:1/annotations");
        await serverInput.press("Tab");
        await page
            .getByRole("status")
            .filter({ hasText: "Server unavailable" })
            .waitFor({ timeout: 5000 });
        await serverInput.fill(url);
        await serverInput.press("Tab");
        await page
            .getByRole("status")
            .filter({ hasText: "Server reachable" })
            .waitFor({ timeout: 5000 });
        await page.getByRole("button", { name: "Save", exact: true }).click();
        if (
            await page
                .getByRole("button", { name: "Enter annotation mode", exact: true })
                .isVisible()
        )
            await page.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
        await page.locator("h1").click();
        await page.locator("textarea").fill(`Fresh comment ${run}`);
        const posted = page.waitForResponse(
            (r) => r.request().method() === "POST" && r.url().includes("/__annotate/local/"),
        );
        await page.locator("[data-annotation-submit]").click();
        assert.equal((await posted).status(), 201);
        const items = (await (await fetch(url)).json()).data;
        assert.equal(items.length, 1);
        assert.equal(items[0].comment, `Fresh comment ${run}`);
        assert.equal(items[0].threadId, undefined);
        await fetch(`${url}/claim`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({}),
        });
        await page
            .getByRole("button", { name: "In progress annotation 1", exact: true })
            .waitFor({ timeout: 5000 });
        await page.reload();
        await page.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
        await page
            .getByRole("button", { name: "In progress annotation 1", exact: true })
            .waitFor({ timeout: 5000 });
        await fetch(`${url}/complete`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: items[0].id, summary: "Verified" }),
        });
        await page
            .locator("[data-annotation-marker]")
            .waitFor({ state: "detached", timeout: 5000 });
        assert.equal(await page.locator("[data-annotation-count]").textContent(), "1");
        await page.reload();
        await page.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
        assert.equal(await page.locator("[data-annotation-count]").textContent(), "1");
        await page.locator("h1").click();
        await page.locator("textarea").fill("Keep counting after done");
        const nextPosted = page.waitForResponse(
            (r) => r.request().method() === "POST" && r.url().includes("/__annotate/local/"),
        );
        await page.locator("[data-annotation-submit]").click();
        const next = (await (await nextPosted).json()).data;
        assert.equal(next.number, 2);
        await page.getByRole("button", { name: "Edit annotation 2", exact: true }).waitFor();
        assert.equal(await page.locator("[data-annotation-count]").textContent(), "2");
        await page.reload();
        await page.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
        await page.getByRole("button", { name: "Edit annotation 2", exact: true }).waitFor();
        assert.equal(await page.locator("[data-annotation-count]").textContent(), "2");
        await page.getByRole("button", { name: "Edit annotation 2", exact: true }).click();
        await page.route("**/__annotate/local/**", (route) =>
            route.request().method() === "DELETE"
                ? route.abort("connectionrefused")
                : route.continue(),
        );
        await page.getByRole("button", { name: "Delete annotation", exact: true }).click();
        await page.getByRole("alert").filter({ hasText: "Could not delete annotation" }).waitFor();
        assert.equal(await page.locator("[data-annotation-marker]").count(), 1);
        await page.unroute("**/__annotate/local/**");
        const deleted = page.waitForResponse((r) => r.request().method() === "DELETE");
        await page.getByRole("button", { name: "Delete annotation", exact: true }).click();
        assert.equal((await deleted).status(), 200);
        await page
            .locator("[data-annotation-marker]")
            .waitFor({ state: "detached", timeout: 5000 });
        assert.equal(
            (await (await fetch(url)).json()).data.some((a) => a.id === next.id),
            false,
        );
        assert.equal(await page.locator("[data-annotation-count]").textContent(), "2");
        await page.locator("h1").click();
        await page.locator("textarea").fill("Clear this from both browsers");
        const thirdPosted = page.waitForResponse(
            (r) => r.request().method() === "POST" && r.url().includes("/__annotate/local/"),
        );
        await page.locator("[data-annotation-submit]").click();
        assert.equal((await (await thirdPosted).json()).data.number, 3);
        const other = await browser.newPage({ ignoreHTTPSErrors: true });
        await other.addInitScript(
            (url) =>
                sessionStorage.setItem(
                    "annotate:service",
                    JSON.stringify({ mode: "server", serviceUrl: url }),
                ),
            url,
        );
        await other.goto(origin);
        await other.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
        await other.getByRole("button", { name: "Edit annotation 3", exact: true }).waitFor();
        await page.route("**/__annotate/local/**", (route) =>
            route.request().method() === "DELETE"
                ? route.abort("connectionrefused")
                : route.continue(),
        );
        await page.getByRole("button", { name: "Clear all annotations", exact: true }).click();
        await page
            .getByRole("alert")
            .filter({ hasText: "Could not remove all annotations" })
            .waitFor();
        assert.equal(await page.locator("[data-annotation-marker]").count(), 1);
        await page.unroute("**/__annotate/local/**");
        const cleared = page.waitForResponse((r) => r.request().method() === "DELETE");
        await page.getByRole("button", { name: "Clear all annotations", exact: true }).click();
        assert.equal((await cleared).status(), 200);
        await page
            .locator("[data-annotation-marker]")
            .waitFor({ state: "detached", timeout: 5000 });
        await other
            .locator("[data-annotation-marker]")
            .waitFor({ state: "detached", timeout: 5000 });
        assert.deepEqual(
            (await (await fetch(url)).json()).data,
            [],
            "Bin removes completed and pending records from the server",
        );
        assert.equal(await page.locator("[data-annotation-count]").textContent(), "3");
        await other.reload();
        await other.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
        assert.equal(await other.locator("[data-annotation-marker]").count(), 0);
        assert.equal(await other.locator("[data-annotation-count]").textContent(), "3");
        await other.close();
        // Clear waits for this tab's pending write so it cannot reappear afterward.
        let releaseSave;
        const heldSave = new Promise((resolve) => {
            releaseSave = resolve;
        });
        let deleteRequests = 0;
        await page.route("**/__annotate/local/**", async (route) => {
            if (route.request().method() === "POST") await heldSave;
            if (route.request().method() === "DELETE") deleteRequests++;
            await route.continue();
        });
        await page.locator("h1").click();
        await page.locator("textarea").fill("Clear while saving");
        const writing = page.waitForRequest(
            (r) => r.method() === "POST" && r.url().includes("/__annotate/local/"),
        );
        await page.locator("[data-annotation-submit]").click();
        await writing;
        const clearedPending = page.waitForResponse((r) => r.request().method() === "DELETE");
        await page.getByRole("button", { name: "Clear all annotations", exact: true }).click();
        assert.equal(deleteRequests, 0, "Deletion queues after the pending save");
        releaseSave();
        assert.equal((await clearedPending).status(), 200);
        await page
            .locator("[data-annotation-marker]")
            .waitFor({ state: "detached", timeout: 5000 });
        assert.deepEqual((await (await fetch(url)).json()).data, []);
        await page.unroute("**/__annotate/local/**");
    }
    console.log(
        "HTTPS bridge: fresh annotations on two random ports, no direct browser loopback, no T3 metadata, live updates and refresh passed.",
    );
} finally {
    await browser?.close();
    web?.closeAllConnections();
    if (web) await new Promise((resolve) => web.close(resolve));
    await vite?.close();
    for (const child of children) child.kill("SIGTERM");
    await rm(dir, { recursive: true, force: true });
}
