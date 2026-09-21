import { useQuery } from "@tanstack/react-query";
import { useParams } from "@tanstack/react-router";
import { useEffect, useMemo, useState } from "react";
import { api } from "../api/client";
import {
    proxycliProviderQuery,
    proxycliProvidersQuery,
    proxycliStatusQuery,
    tasksStatusQuery,
} from "../api/queries";
import { queryClient } from "../api/queryClient";
import type { QuotaAccount, QuotaProvider, QuotaWindow } from "../api/types";
import { quotaPace } from "../quota/pace";
import { Frame, Note } from "../ui/Frame";
import { useGo } from "../ui/go";
import { PageHeader } from "../ui/PageHeader";
import { type Column, Pane } from "../ui/Pane";
import { Properties } from "../ui/Properties";
import { Status } from "../ui/Status";

const providerNames: Record<string, string> = {
    antigravity: "Antigravity",
    claude: "Claude",
    codex: "Codex",
    grok: "Grok",
    kimi: "Kimi",
};

function providerName(provider: string): string {
    return providerNames[provider] ?? provider.charAt(0).toUpperCase() + provider.slice(1);
}

function ProviderLabel({ provider }: { provider: string }) {
    return (
        <span className="inline-flex items-center gap-2">
            {providerNames[provider] && (
                <span
                    aria-hidden="true"
                    className="inline-block size-4 shrink-0 bg-current"
                    style={{ mask: `url(/providers/${provider}.svg) center / contain no-repeat` }}
                />
            )}
            {providerName(provider)}
        </span>
    );
}

function trimPercent(value: number): string {
    return `${Math.round(value * 10) / 10}`.replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1");
}

function windowRemaining(window: QuotaWindow): string {
    return `${window.label} ${trimPercent(window.remaining_percent)}%`;
}

function resetIn(reset: string | null, now: number): string {
    if (!reset || !Number.isFinite(Date.parse(reset))) return "—";
    const remaining = Date.parse(reset) - now;
    if (remaining <= 0) return "Awaiting reset";
    if (remaining < 60_000) return "<1m";
    const minutes = Math.floor(remaining / 60_000);
    const parts = [
        [Math.floor(minutes / 1440), "d"],
        [Math.floor((minutes % 1440) / 60), "h"],
        [minutes % 60, "m"],
    ] as const;
    return parts
        .filter(([value]) => value > 0)
        .map(([value, unit]) => `${value}${unit}`)
        .join(" ");
}

function useQuotaClock(): number {
    const [now, setNow] = useState(Date.now);
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(timer);
    }, []);
    return now;
}

function QuotaResets({ windows }: { windows: QuotaWindow[] }) {
    const now = useQuotaClock();
    if (!windows.length) return <span className="text-dim">—</span>;
    return (
        <div className="grid gap-2 whitespace-normal">
            {windows.map((window) => (
                <div
                    key={window.label}
                    className="pb-2"
                    title={`${window.label}: ${window.resets_at ? new Date(window.resets_at).toLocaleString() : "Reset unknown"}`}
                >
                    {resetIn(window.resets_at, now)}
                </div>
            ))}
        </div>
    );
}

function QuotaWindows({
    windows,
    accounts,
}: {
    windows: QuotaWindow[];
    accounts?: QuotaAccount[];
}) {
    const now = useQuotaClock();
    if (!windows.length) return <span className="text-dim">No quota reported</span>;
    return (
        <div className="grid gap-2 whitespace-normal">
            {windows.map((window) => {
                const pace = quotaPace(window, now, accounts);
                const hint =
                    pace === null
                        ? undefined
                        : `Red line: ${trimPercent(pace)}% remaining at an even pace. ${window.remaining_percent >= pace ? "On pace to last until reset." : "Usage is ahead of pace; quota may run out before reset."}`;
                return (
                    <div key={window.label}>
                        <div>{windowRemaining(window)}</div>
                        <div
                            className="relative mt-1 h-1 bg-dim/20"
                            title={hint}
                            role="meter"
                            aria-label={`${window.label} remaining`}
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={window.remaining_percent}
                            aria-valuetext={
                                hint
                                    ? `${trimPercent(window.remaining_percent)}% remaining. ${hint}`
                                    : undefined
                            }
                        >
                            <div
                                className="h-full bg-cyan"
                                style={{
                                    width: `${Math.max(0, Math.min(100, window.remaining_percent))}%`,
                                }}
                            />
                            {pace !== null && (
                                <span
                                    aria-hidden="true"
                                    data-quota-pace
                                    className="absolute top-0 h-full w-[6px] -translate-x-1/2 border-x-2 border-black bg-[#ff3b30]"
                                    style={{ left: `${pace}%` }}
                                />
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

async function toggleAccount(account: QuotaAccount): Promise<void> {
    await api<QuotaAccount>(
        "PATCH",
        `/api/v1/proxycli/accounts/${encodeURIComponent(account.id)}`,
        {
            disabled: !account.disabled,
        },
    );
    await queryClient.invalidateQueries({ queryKey: ["proxycli-providers"] });
}

const providerColumns: Column<QuotaProvider>[] = [
    {
        header: "Provider",
        width: 22,
        value: (row) => providerName(row.provider),
        cell: (row) => <ProviderLabel provider={row.provider} />,
    },
    {
        header: "Quota remaining",
        width: 46,
        value: (row) => row.windows.map(windowRemaining).join("  ") || "—",
        cell: (row) => <QuotaWindows windows={row.windows} accounts={row.accounts} />,
    },
    {
        header: "Token spend",
        width: 24,
        value: () => "Unavailable",
        cell: () => <span className="text-dim">Not reported by Gateway</span>,
    },
    { header: "Accounts", width: 14, align: "right", value: (row) => String(row.accounts.length) },
    {
        header: "Resets in",
        width: 28,
        value: (row) =>
            row.windows.map((window) => resetIn(window.resets_at, Date.now())).join("  ") || "—",
        cell: (row) => <QuotaResets windows={row.windows} />,
    },
];

/** Provider pools from the snapshot. Hidden from the sidebar until the fleet feature is enabled. */
export function QuotaList() {
    const go = useGo();
    const status = useQuery(proxycliStatusQuery);
    const tasks = useQuery(tasksStatusQuery);
    const providers = useQuery({
        ...proxycliProvidersQuery,
        enabled: status.data?.enabled === true && tasks.data?.enabled === true,
    });

    if (status.data?.enabled !== true || tasks.data?.enabled !== true) {
        return (
            <Frame title="Quota" state="warn">
                <Note>
                    Provider quota and token spend require both the CLIProxyAPI collector and the
                    tasks extension. Enable both to view this data.
                </Note>
            </Frame>
        );
    }

    return (
        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] md:grid-rows-[minmax(0,1fr)] gap-y-[var(--panel-gap)]">
            <PageHeader trail={[{ label: "Quota" }]} />
            <Pane
                name="list"
                order={1}
                title="Quota"
                className="quota-pane"
                columns={providerColumns}
                rows={providers.data ?? []}
                rowId={(row) => row.provider}
                onRowClick={(row) => go.quota(row.provider)}
                warn={(row) =>
                    row.accounts.some((account) => !account.disabled && account.error !== null)
                }
                bottomLeft={
                    status.data.collected_at
                        ? `Cache updated ${new Date(status.data.collected_at).toLocaleString()}`
                        : "Waiting for first collection"
                }
                bottomRight="Red line: even pace · Bar past line = on track"
                empty={
                    providers.error
                        ? providers.error.message
                        : providers.isPending
                          ? "Loading…"
                          : "No provider pools in the snapshot."
                }
            />
        </div>
    );
}

export function QuotaProviderPage() {
    const { id } = useParams({ from: "/$section/$id" });
    const status = useQuery(proxycliStatusQuery);
    const tasks = useQuery(tasksStatusQuery);
    const provider = useQuery({
        ...proxycliProviderQuery(id),
        enabled: status.data?.enabled === true && tasks.data?.enabled === true,
    });
    const accountColumns = useMemo<Column<QuotaAccount>[]>(
        () => [
            { header: "Account", width: 22, value: (row) => row.id },
            { header: "Label", width: 16, value: (row) => row.label },
            {
                header: "State",
                width: 14,
                value: (row) => (row.disabled ? "disabled" : (row.status ?? "ok")),
                cell: (row) => <Status value={row.disabled ? "disabled" : (row.status ?? "ok")} />,
            },
            {
                header: "Quota remaining",
                width: 28,
                value: (row) => row.windows.map(windowRemaining).join("  ") || "—",
                cell: (row) => <QuotaWindows windows={row.windows} />,
            },
            {
                header: "Resets in",
                width: 24,
                value: (row) =>
                    row.windows.map((window) => resetIn(window.resets_at, Date.now())).join("  ") ||
                    "—",
                cell: (row) => <QuotaResets windows={row.windows} />,
            },
            {
                header: "Collection",
                width: 28,
                value: (row) =>
                    row.error ??
                    (row.disabled
                        ? "Disabled"
                        : row.windows.length
                          ? "Cached"
                          : "No quota reported"),
            },
            {
                header: "Control",
                width: 12,
                value: (row) => (row.disabled ? "enable" : "disable"),
                cell: (row) => (
                    <span
                        className="cursor-pointer text-cyan"
                        onClick={(event) => {
                            event.stopPropagation();
                            void toggleAccount(row);
                        }}
                    >
                        {row.disabled ? "enable" : "disable"}
                    </span>
                ),
            },
        ],
        [],
    );

    if (status.data?.enabled !== true || tasks.data?.enabled !== true) {
        return (
            <Frame title="Quota" state="warn">
                <Note>
                    Provider quota and token spend require both the CLIProxyAPI collector and the
                    tasks extension.
                </Note>
            </Frame>
        );
    }

    if (provider.data === undefined) {
        return (
            <Frame title={id}>
                <Note>
                    {provider.isPending ? "Loading…" : `No provider ${id} in the snapshot.`}
                </Note>
            </Frame>
        );
    }

    const pool = provider.data;

    return (
        <div className="grid h-full grid-rows-[auto_auto_minmax(0,1fr)] md:grid-rows-[auto_minmax(0,1fr)] gap-y-[var(--panel-gap)]">
            <PageHeader trail={[{ label: "Quota" }, { label: providerName(pool.provider) }]} />
            <Properties
                properties={[
                    { name: "Provider", value: providerName(pool.provider) },
                    {
                        name: "Cache updated",
                        value: status.data.collected_at
                            ? new Date(status.data.collected_at).toLocaleString()
                            : null,
                    },
                    {
                        name: "Windows",
                        value: pool.windows.map(windowRemaining).join("  ") || null,
                    },
                ]}
            />
            <Pane
                name="accounts"
                order={1}
                title="Accounts"
                bottomLeft="Red line: even pace · Bar past line = on track"
                className="quota-pane"
                columns={accountColumns}
                rows={pool.accounts}
                rowId={(row) => row.id}
                warn={(row) => row.disabled || row.error !== null}
                empty={provider.isPending ? "Loading…" : "No accounts in this pool."}
            />
        </div>
    );
}
