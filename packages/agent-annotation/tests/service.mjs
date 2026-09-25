import { spawn } from "node:child_process";
import { WebSocketServer } from "ws";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { chromium } from "playwright";

let revision = 0;
let annotation;
const streams = new Set();
let subscriptions = 0;
let authorizations = 0;
const send = () => {
    for (const socket of streams)
        socket.send(
            JSON.stringify({
                event: "annotation.updated",
                channel: "private-orbit",
                data: JSON.stringify({
                    type: "annotation.updated",
                    data: { id: annotation?.id, instanceId: 107, revision },
                }),
            }),
        );
};
const update = (status) => {
    annotation = { ...annotation, status, revision: ++revision };
    send();
};
const server = createServer(async (request, response) => {
    const url = new URL(request.url, `http://${request.headers.host}`);
    if (url.pathname === "/index.js") {
        response.setHeader("Content-Type", "text/javascript");
        response.end(await readFile(new URL("../dist/index.js", import.meta.url)));
    } else if (url.pathname === "/api/v1/tasks/status") {
        response.setHeader("Content-Type", "application/json");
        response.end(JSON.stringify({ data: { enabled: true } }));
    } else if (url.pathname === "/realtime") {
        response.setHeader("Content-Type", "application/json");
        response.end(
            JSON.stringify({
                data: {
                    url: `ws://${request.headers.host}`,
                    key: "public-test-key",
                    channel: "orbit",
                },
            }),
        );
    } else if (url.pathname === "/broadcasting/auth") {
        authorizations++;
        response.setHeader("Content-Type", "application/json");
        response.end(JSON.stringify({ auth: "public-test-key:signed" }));
    } else if (url.pathname === "/annotations") {
        if (request.method === "POST") {
            const chunks = [];
            for await (const chunk of request) chunks.push(chunk);
            annotation = {
                ...JSON.parse(Buffer.concat(chunks)),
                revision: ++revision,
                status: "pending",
                delivery: "queued",
            };
            send();
            response.setHeader("Content-Type", "application/json");
            response.end(JSON.stringify({ data: annotation }));
        } else {
            response.setHeader("Content-Type", "application/json");
            response.end(JSON.stringify({ data: annotation ? [annotation] : [] }));
        }
    } else {
        response.setHeader("Content-Type", "text/html; charset=utf-8");
        response.end(
            '<h1 style="padding:80px">Test target</h1><script type="module">import { mountAnnotation } from "/index.js"; mountAnnotation({serviceUrl:"/annotations", realtime:{configUrl:"/realtime",authUrl:"/broadcasting/auth"}, thread:{id:"test-thread"},dictation:{autoStart:false}});</script>',
        );
    }
});
const wss = new WebSocketServer({ server });
wss.on("connection", (socket) => {
    socket.send(
        JSON.stringify({
            event: "pusher:connection_established",
            data: JSON.stringify({ socket_id: "1.2", activity_timeout: 120 }),
        }),
    );
    socket.on("message", (message) => {
        const event = JSON.parse(message);
        if (event.event === "pusher:subscribe") {
            assert.equal(event.data.auth, "public-test-key:signed");
            subscriptions++;
            streams.add(socket);
            socket.send(
                JSON.stringify({
                    event: "pusher_internal:subscription_succeeded",
                    channel: "private-orbit",
                    data: "{}",
                }),
            );
        }
    });
    socket.on("close", () => streams.delete(socket));
});
await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch();
let local;
try {
    // A live subscription replaces the 15-second poll; a hidden tab never polls.
    const quiet = await browser.newContext();
    await quiet.clock.install();
    const watcher = await quiet.newPage();
    let reads = 0;
    watcher.on("request", (request) => {
        if (new URL(request.url()).pathname === "/annotations" && request.method() === "GET")
            reads++;
    });
    const subscribed = subscriptions;
    await watcher.goto(origin);
    for (let tries = 0; subscriptions === subscribed && tries < 100; tries++)
        await new Promise((resolve) => setTimeout(resolve, 50));
    assert.ok(subscriptions > subscribed, "the watcher subscribes");
    await new Promise((resolve) => setTimeout(resolve, 200));
    const settled = reads;
    await watcher.clock.runFor(60_000);
    assert.equal(reads, settled, "a live subscription stops the periodic fetch");
    for (const socket of streams) socket.close();
    await new Promise((resolve) => setTimeout(resolve, 200));
    await watcher.clock.runFor(1_000);
    const reconnecting = reads;
    await watcher.evaluate(() => {
        Object.defineProperty(document, "visibilityState", { value: "hidden", configurable: true });
    });
    await watcher.clock.runFor(60_000);
    assert.ok(reads - reconnecting <= 1, "a hidden tab does not poll");
    await quiet.close();

    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(origin);
    await page.getByRole("button", { name: "Enter annotation mode" }).click();
    await page.locator("h1").click();
    await page.locator("textarea").fill("Make this title smaller");
    const delivered = page.waitForResponse(
        (response) =>
            response.url() === `${origin}/annotations` && response.request().method() === "POST",
    );
    await page.locator("[data-annotation-submit]").click();
    await delivered;
    await page.locator("[data-annotation-marker]").waitFor();
    assert.equal(annotation.threadId, "test-thread");
    const other = await context.newPage();
    await other.goto(origin);
    await other.getByRole("button", { name: "Enter annotation mode" }).click();
    await other.locator("[data-annotation-marker]").waitFor();
    update("in_progress");
    await page.getByRole("button", { name: "In progress annotation 1" }).waitFor();
    await other.getByRole("button", { name: "In progress annotation 1" }).waitFor();
    await page.reload();
    await page.getByRole("button", { name: "Enter annotation mode" }).click();
    await page.getByRole("button", { name: "In progress annotation 1" }).waitFor();
    assert.ok(
        authorizations >= 2,
        "private subscriptions authorize through the configured endpoint",
    );
    const before = subscriptions;
    for (const socket of streams) socket.close();
    annotation = { ...annotation, status: "pending", revision: ++revision };
    await page.getByRole("button", { name: "Edit annotation 1" }).waitFor();
    assert.ok(subscriptions > before, "socket reconnects and fetches missed state");
    update("resolved");
    await page.locator("[data-annotation-marker]").waitFor({ state: "detached" });
    await other.locator("[data-annotation-marker]").waitFor({ state: "detached" });
    await page.reload();
    await page.getByRole("button", { name: "Enter annotation mode" }).click();
    assert.equal(await page.locator("[data-annotation-marker]").count(), 0);
    annotation = { ...annotation, id: "cancelled-note", status: "pending", revision: ++revision };
    send();
    await page.locator("[data-annotation-marker]").waitFor();
    update("cancelled");
    await page.locator("[data-annotation-marker]").waitFor({ state: "detached" });
    await page.reload();
    await page.getByRole("button", { name: "Enter annotation mode" }).click();
    assert.equal(await page.locator("[data-annotation-marker]").count(), 0);
    await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
    await page.getByLabel("Delivery mode", { exact: true }).selectOption("server");
    await page.getByLabel("Annotation server URL", { exact: true }).fill("javascript:alert(1)");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await page.getByRole("alert").waitFor();
    await page.getByLabel("Annotation server URL", { exact: true }).fill("/annotations");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await page.reload();
    await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
    assert.equal(
        await page.getByLabel("Annotation server URL", { exact: true }).inputValue(),
        "/annotations",
    );
    assert.equal(await page.getByLabel("WebSocket configuration URL").count(), 0);
    await page.getByRole("button", { name: "Close settings", exact: true }).click();
    local = spawn(process.execPath, ["bin/serve.mjs", "serve"]);
    const localUrl = await new Promise((resolve, reject) => {
        let output = "";
        const timeout = setTimeout(() => reject(new Error("Local server startup timed out")), 5000);
        local.stdout.on("data", (data) => {
            output += data;
            const match = output.match(/Annotation server URL: (\S+)/);
            if (match) {
                clearTimeout(timeout);
                resolve(match[1]);
            }
        });
    });
    await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
    await page.getByLabel("Annotation server URL", { exact: true }).fill(localUrl);
    await page.getByRole("button", { name: "Save", exact: true }).click();
    // Older settings must not enable Orbit WebSockets in local server mode.
    await page.evaluate(() => {
        const saved = JSON.parse(sessionStorage.getItem("annotate:service"));
        sessionStorage.setItem(
            "annotate:service",
            JSON.stringify({ ...saved, configUrl: "/legacy-realtime" }),
        );
    });
    const realtimeRequests = [];
    page.on("request", (request) => {
        if (
            ["/legacy-realtime", "/realtime", "/broadcasting/auth"].includes(
                new URL(request.url()).pathname,
            )
        )
            realtimeRequests.push(request.url());
    });
    await page.reload();
    await page.locator("h1").click();
    if (
        await page.getByRole("button", { name: "Enter annotation mode", exact: true }).isVisible()
    ) {
        await page.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
        await page.locator("h1").click();
    }
    await page.route(localUrl, (route) =>
        route.request().method() === "POST" ? route.abort("connectionrefused") : route.continue(),
    );
    await page.locator("textarea").fill("Local only");
    await page.locator("[data-annotation-submit]").click();
    await page.locator("[data-annotation-marker]").waitFor();
    await page.getByRole("button", { name: "Edit annotation 1", exact: true }).click();
    await page
        .getByText("Could not save annotation to the local server.", { exact: false })
        .waitFor();
    assert.equal(
        await page.getByRole("button", { name: "Retry delivery", exact: true }).count(),
        0,
    );
    await page.unroute(localUrl);
    const saved = page.waitForResponse(
        (response) => response.url() === localUrl && response.request().method() === "POST",
    );
    await page.getByRole("button", { name: "Retry save", exact: true }).click();
    assert.equal((await saved).status(), 201);
    await page
        .getByRole("button", { name: "Retry save", exact: true })
        .waitFor({ state: "detached" });
    const list = await (await fetch(localUrl)).json();
    assert.equal(list.data.length, 1);
    assert.equal(list.data[0].comment, "Local only");
    assert.equal(list.data[0].threadId, undefined, "Server mode excludes T3 routing metadata");
    assert.notEqual(annotation.comment, "Local only", "Local submissions bypass Orbit");
    const status = async (value) =>
        fetch(`${localUrl}/${list.data[0].id}/status`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ status: value }),
        });
    await status("in_progress");
    await page.getByRole("button", { name: "In progress annotation 1" }).waitFor({ timeout: 5000 });
    await page.reload();
    await page.getByRole("button", { name: "Enter annotation mode" }).click();
    await page.getByRole("button", { name: "In progress annotation 1" }).waitFor();
    await status("resolved");
    await page.locator("[data-annotation-marker]").waitFor({ state: "detached", timeout: 5000 });
    assert.deepEqual(realtimeRequests, [], "Local mode ignores saved and host WebSocket settings");
    await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
    const stopped = new Promise((resolve) => local.once("exit", resolve));
    local.kill("SIGTERM");
    await stopped;
    await page.reload();
    await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
    await page.getByText(/Server unavailable/).waitFor({ timeout: 5000 });
    await page.getByLabel("Delivery mode", { exact: true }).selectOption("orbit");
    await page.getByLabel("T3 thread ID", { exact: true }).fill("");
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await page.reload();
    await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
    assert.equal(await page.getByLabel("Delivery mode", { exact: true }).inputValue(), "orbit");
    assert.equal(await page.getByLabel("T3 thread ID", { exact: true }).inputValue(), "");
    const oldAnnotation = JSON.stringify(annotation);
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await page.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
    await page.locator("h1").click();
    await page.locator("textarea").fill("No thread means no T3 delivery");
    await page.locator("[data-annotation-submit]").click();
    assert.equal(
        JSON.stringify(annotation),
        oldAnnotation,
        "An empty thread does not deliver to Orbit",
    );
    await page.evaluate(() => {
        sessionStorage.setItem(
            "annotate:service",
            JSON.stringify({ mode: "browser", serviceUrl: "" }),
        );
    });
    await page.reload();
    await page.getByRole("button", { name: "Annotation settings", exact: true }).click();
    assert.equal(await page.getByLabel("Delivery mode", { exact: true }).inputValue(), "server");
    assert.deepEqual(
        await page.getByLabel("Delivery mode", { exact: true }).locator("option").allTextContents(),
        ["Local server", "Orbit"],
    );
    await page.getByRole("button", { name: "Save", exact: true }).click();
    await page.getByRole("alert").filter({ hasText: "Enter the annotation server URL." }).waitFor();
    await page.getByRole("button", { name: "Close settings", exact: true }).click();
    const outboundPosts = [];
    page.on("request", (request) => {
        if (request.method() === "POST") outboundPosts.push(request.url());
    });
    await page.getByRole("button", { name: "Enter annotation mode", exact: true }).click();
    await page.locator("h1").click({ position: { x: 10, y: 10 } });
    await page.locator("textarea").fill("Needs a server URL");
    await page.locator("[data-annotation-submit]").click();
    await page.getByRole("button", { name: "Edit annotation 2", exact: true }).click();
    await page
        .getByText("Enter the annotation server URL in settings.", { exact: false })
        .waitFor();
    assert.deepEqual(outboundPosts, [], "Missing local URL never falls back to host delivery");
    console.log(
        "Service: submission, shared progress, WebSocket reconnect recovery, completion, and refresh passed",
    );
} finally {
    local?.kill("SIGTERM");
    await browser.close();
    for (const socket of streams) socket.terminate();
    wss.close();
    await new Promise((resolve) => server.close(resolve));
}
