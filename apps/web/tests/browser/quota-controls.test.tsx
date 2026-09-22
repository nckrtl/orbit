import { expect, it } from "vite-plus/test";
import { page, userEvent } from "vite-plus/test/browser";
import type { Transport } from "../../src/api/client";
import { proxycliProviderQuery, proxycliProvidersQuery } from "../../src/api/queries";
import { queryClient } from "../../src/api/queryClient";
import type { QuotaProvider } from "../../src/api/types";
import { ui } from "../../src/ui/store";
import { openApp, pane, row } from "./app";

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

const detailPath = "/api/v1/proxycli/providers/codex";
const listPath = "/api/v1/proxycli/providers";
const detailQuery = proxycliProviderQuery("codex");
const refusal = (message: string) => ({ status: 409, payload: { error: { message } } });

it.each([false, true])(
    "owns one account request and refresh when initially disabled is %s",
    async (initiallyDisabled) => {
        const id = "plus /#?.json";
        let disabled = initiallyDisabled;
        const patch = deferred<void>();
        const refresh = deferred<void>();
        const requests: { method: string; path: string; body: unknown }[] = [];
        const patches = () => requests.filter((request) => request.method === "PATCH");
        let refreshing = false;
        const app = await openApp("/quota", {
            proxycli: true,
            wrapTransport: (inner) => async (method, path, body) => {
                requests.push({ method, path, body });
                if (method === "PATCH") {
                    await patch.promise;
                    disabled = (body as { disabled: boolean }).disabled;
                    return { status: 200, payload: { data: { id, disabled } } };
                }
                const response = await inner(method, path, body);
                if (path !== listPath && path !== detailPath) return response;
                if (path === detailPath && patches().length > 0) {
                    refreshing = true;
                    await refresh.promise;
                }
                const withAccounts = (provider: QuotaProvider): QuotaProvider => ({
                    ...provider,
                    accounts: [
                        { ...provider.accounts[0]!, id, disabled },
                        { ...provider.accounts[0]!, id: "second.json", label: "second" },
                    ],
                });
                const data = (response.payload as { data: QuotaProvider | QuotaProvider[] }).data;
                return {
                    ...response,
                    payload: {
                        data: Array.isArray(data) ? data.map(withAccounts) : withAccounts(data),
                    },
                };
            },
        });
        await row("Quota", "Codex").click();
        const control = page.getByRole("button", { name: `Toggle account ${id}`, exact: true });
        await expect.element(control).toHaveTextContent(initiallyDisabled ? "enable" : "disable");
        const order = () =>
            [...pane("Accounts").element().querySelectorAll('[role="row"]:not([data-head])')].map(
                (account) => account.querySelector('[role="cell"]')?.textContent,
            );
        expect(order()).toEqual([id, "second.json"]);
        const previousFocus = ui.get().focus;
        if (initiallyDisabled) {
            (control.element() as HTMLButtonElement).focus();
            await userEvent.keyboard("{Enter}");
        } else {
            await control.click();
        }
        await expect.element(control).toBeDisabled();
        await expect.element(control).toHaveTextContent("Saving…");
        expect(ui.get().focus).toBe(previousFocus);
        (control.element() as HTMLButtonElement).click();
        (control.element() as HTMLButtonElement).click();
        expect(patches()).toEqual([
            {
                method: "PATCH",
                path: `/api/v1/proxycli/accounts/${encodeURIComponent(id)}`,
                body: { disabled: !initiallyDisabled },
            },
        ]);
        expect(queryClient.getQueryData(detailQuery.queryKey)?.accounts[0]?.disabled).toBe(
            initiallyDisabled,
        );
        await expect
            .element(page.getByRole("button", { name: "Toggle account second.json", exact: true }))
            .toBeEnabled();

        patch.resolve();
        await expect.poll(() => refreshing).toBe(true);
        await expect.element(control).toBeDisabled();
        expect(queryClient.getQueryState(proxycliProvidersQuery.queryKey)?.isInvalidated).toBe(
            true,
        );
        (control.element() as HTMLButtonElement).click();
        expect(patches()).toHaveLength(1);
        refresh.resolve();
        await expect.element(control).toBeEnabled();
        await expect.element(control).toHaveTextContent(initiallyDisabled ? "disable" : "enable");
        expect(queryClient.getQueryData(detailQuery.queryKey)?.accounts[0]?.disabled).toBe(
            !initiallyDisabled,
        );
        expect(order()).toEqual([id, "second.json"]);
        expect(requests.filter((request) => request.path === detailPath)).toHaveLength(2);

        await app.router.navigate({ to: "/$section", params: { section: "quota" } });
        await expect
            .poll(
                () =>
                    queryClient.getQueryData(proxycliProvidersQuery.queryKey)?.[0]?.accounts[0]
                        ?.disabled,
            )
            .toBe(!initiallyDisabled);
        expect(requests.filter((request) => request.path === listPath)).toHaveLength(2);
        expect(patches()).toHaveLength(1);
    },
);

it("shows a safe refusal beside its account and clears it only on explicit retry", async () => {
    const first = deferred<Awaited<ReturnType<Transport>>>();
    const retry = deferred<void>();
    const unhandled: unknown[] = [];
    const onUnhandled = (event: PromiseRejectionEvent) => unhandled.push(event.reason);
    window.addEventListener("unhandledrejection", onUnhandled);
    let attempts = 0;
    try {
        const app = await openApp("/quota/codex", {
            proxycli: true,
            wrapTransport: (inner) => async (method, path, body) => {
                if (method === "PATCH") {
                    attempts++;
                    if (attempts === 1) return first.promise;
                    await retry.promise;
                }
                return inner(method, path, body);
            },
        });
        const control = page.getByRole("button", { name: "Toggle account plus.json", exact: true });
        await control.click();
        first.resolve(refusal("The account change was refused."));
        await expect
            .element(row("Accounts", "plus.json").getByRole("alert"))
            .toHaveTextContent("The account change was refused.");
        await expect.element(control).toBeEnabled();
        await expect.element(control).toHaveTextContent("disable");
        expect(attempts).toBe(1);
        expect(app.gateway.requests.filter((request) => request.path === detailPath)).toHaveLength(
            1,
        );
        expect(unhandled).toEqual([]);
        await control.click();
        await expect.element(control).toBeDisabled();
        await expect.element(page.getByRole("alert")).not.toBeInTheDocument();
        expect(attempts).toBe(2);
        retry.resolve();
        await expect.element(control).toBeEnabled();
        await expect.element(control).toHaveTextContent("enable");
        expect(unhandled).toEqual([]);
    } finally {
        window.removeEventListener("unhandledrejection", onUnhandled);
    }
});

it("cannot attach an old account failure to a replacement account", async () => {
    const old = deferred<Awaited<ReturnType<Transport>>>();
    const current = deferred<Awaited<ReturnType<Transport>>>();
    await openApp("/quota/codex", {
        proxycli: true,
        wrapTransport: (inner) => (method, path, body) =>
            method === "PATCH"
                ? path.endsWith("/plus.json")
                    ? old.promise
                    : current.promise
                : inner(method, path, body),
    });
    await page.getByRole("button", { name: "Toggle account plus.json", exact: true }).click();
    queryClient.setQueryData(detailQuery.queryKey, (provider) => ({
        ...provider!,
        accounts: provider!.accounts.map((account) => ({ ...account, id: "next.json" })),
    }));
    const control = page.getByRole("button", { name: "Toggle account next.json", exact: true });
    await expect.element(control).toBeEnabled();
    await control.click();
    old.resolve(refusal("Old account refusal."));
    await expect.poll(() => queryClient.isMutating()).toBe(1);
    await expect.element(control).toBeDisabled();
    await expect.element(page.getByRole("alert")).not.toBeInTheDocument();
    current.resolve(refusal("Current account refusal."));
    await expect
        .element(row("Accounts", "next.json").getByRole("alert"))
        .toHaveTextContent("Current account refusal.");
    await expect.element(control).toBeEnabled();
    expect(pane("Accounts").element().textContent).not.toContain("Old account refusal.");
});
