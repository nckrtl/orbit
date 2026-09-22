import { afterEach, expect, it, vi } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import type { Transport } from "../../src/api/client";
import { ui } from "../../src/ui/store";
import { footer, openApp, pane, row } from "./app";

afterEach(() => vi.restoreAllMocks());

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((onResolve, onReject) => {
        resolve = onResolve;
        reject = onReject;
    });
    return { promise, resolve, reject };
}

async function profile() {
    await page.getByRole("button", { name: "Actions", exact: true }).click();
    await page.getByRole("menuitem", { name: "profile", exact: true }).click();
    await expect.element(page.getByRole("dialog")).toHaveTextContent("Running…");
}

async function completeReport(
    request: ReturnType<typeof deferred<Response>>,
    name: string,
    ok = true,
) {
    if (ok) {
        request.resolve(Response.json({ ok: true, output: name }));
    } else {
        request.reject(new Error(name));
    }
    await request.promise.catch(() => undefined);
    // Drain the report's response parsing and completion callbacks before checking unchanged UI.
    await new Promise<void>((resolve) => window.setTimeout(resolve, 0));
}

it.each([
    ["old first", true],
    ["old first", false],
    ["new first", true],
    ["new first", false],
] as const)(
    "keeps the reopened report when %s completes and old success is %s",
    async (order, oldOk) => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        const fetched = vi
            .spyOn(window, "fetch")
            .mockReturnValueOnce(first.promise)
            .mockReturnValueOnce(second.promise);
        await openApp("/instances/1");
        await profile();
        await userEvent.keyboard("{Escape}");
        await profile();
        const current = ui.get().modal;
        expect(fetched).toHaveBeenCalledTimes(2);

        if (order === "old first") {
            await completeReport(first, "old result", oldOk);
            expect(ui.get().modal).toBe(current);
            await expect.element(page.getByRole("dialog")).toHaveTextContent("Running…");
            await completeReport(second, "new result");
        } else {
            await completeReport(second, "new result");
            await expect.element(page.getByRole("dialog")).toHaveTextContent("new result");
            const completed = ui.get().modal;
            await completeReport(first, "old result", oldOk);
            expect(ui.get().modal).toBe(completed);
        }

        await expect.element(page.getByRole("dialog")).toHaveTextContent("new result");
        expect(ui.get().modal?.failed).toBe(false);
    },
);

it.each([true, false])("leaves a report closed after its delayed success is %s", async (ok) => {
    const request = deferred<Response>();
    vi.spyOn(window, "fetch").mockReturnValueOnce(request.promise);
    await openApp("/instances/1");
    await profile();
    await userEvent.keyboard("{Escape}");

    await completeReport(request, "closed result", ok);

    expect(ui.get().modal).toBeNull();
    await expect.element(page.getByRole("dialog")).not.toBeInTheDocument();
});

async function delayedRestart() {
    const request = deferred<Awaited<ReturnType<Transport>>>();
    const app = await openApp("/processes", {
        wrapTransport: (inner) => (method, path, body) =>
            path === "/api/v1/processes/2/restart" ? request.promise : inner(method, path, body),
    });
    await row("Processes", "vite").click({ button: "right" });
    await pane("vite").getByRole("menuitem", { name: "restart", exact: true }).click();
    await expect.element(pane("vite")).toHaveTextContent("Running…");

    return {
        async complete(ok: boolean) {
            request.resolve(
                ok
                    ? await app.gateway.transport("POST", "/api/v1/processes/2/restart", {})
                    : { status: 503, payload: { error: { message: "mock restart failed" } } },
            );
            await expect
                .element(footer())
                .toHaveTextContent(ok ? "Process [vite] restarted." : "mock restart failed");
        },
    };
}

it.each([true, false])(
    "keeps a newer menu open when an older request success is %s",
    async (ok) => {
        const request = await delayedRestart();
        const old = ui.get().menu;
        await userEvent.keyboard("{Escape}");
        await row("Processes", "vite").click({ button: "right" });
        await userEvent.keyboard("{ArrowDown}");
        const current = ui.get().menu;
        expect(current?.invocation).not.toBe(old?.invocation);

        await request.complete(ok);

        expect(ui.get().menu).toBe(current);
        await expect.element(pane("vite")).toBeVisible();
        expect(ui.get().menu?.running).toBe(false);
    },
);

it.each([true, false])(
    "closes its own menu after selection changes when request success is %s",
    async (ok) => {
        const request = await delayedRestart();
        const running = ui.get().menu;
        await userEvent.keyboard("{ArrowDown}");
        expect(ui.get().menu).not.toBe(running);
        expect(ui.get().menu?.invocation).toBe(running?.invocation);

        await request.complete(ok);

        expect(ui.get().menu).toBeNull();
        await expect.element(pane("vite")).not.toBeInTheDocument();
    },
);

it("keeps a dismissed request menu closed while reporting its completion", async () => {
    const request = await delayedRestart();
    await userEvent.keyboard("{Escape}");

    await request.complete(true);

    expect(ui.get().menu).toBeNull();
    await expect.element(pane("vite")).not.toBeInTheDocument();
});
