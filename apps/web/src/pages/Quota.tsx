import { useQuery } from "@tanstack/react-query";
import { useParams } from "@tanstack/react-router";
import { useMemo } from "react";
import { api } from "../api/client";
import {
    proxycliProviderQuery,
    proxycliProvidersQuery,
    proxycliStatusQuery,
} from "../api/queries";
import { queryClient } from "../api/queryClient";
import type { QuotaAccount, QuotaProvider, QuotaWindow } from "../api/types";
import { Frame, Note } from "../ui/Frame";
import { useGo } from "../ui/go";
import { PageHeader } from "../ui/PageHeader";
import { type Column, Pane } from "../ui/Pane";
import { Properties } from "../ui/Properties";
import { Status } from "../ui/Status";

function trimPercent(value: number): string {
    return `${value}`.replace(/\.0+$/, "").replace(/(\.\d*?)0+$/, "$1");
}

function windowRemaining(window: QuotaWindow): string {
    return `${window.label} ${trimPercent(window.remaining_percent)}%`;
}

function windowReset(window: QuotaWindow): string | null {
    return window.resets_at === null ? null : `${window.label} ${window.resets_at}`;
}

async function toggleAccount(account: QuotaAccount): Promise<void> {
    await api<QuotaAccount>("PATCH", `/api/v1/proxycli/accounts/${encodeURIComponent(account.id)}`, {
        disabled: !account.disabled,
    });
    await queryClient.invalidateQueries({ queryKey: ["proxycli-providers"] });
}

const providerColumns: Column<QuotaProvider>[] = [
    { header: "Provider", width: 22, value: (row) => row.provider },
    {
        header: "Windows",
        width: 46,
        value: (row) => row.windows.map(windowRemaining).join("  ") || "—",
    },
    { header: "Accounts", width: 14, value: (row) => String(row.accounts.length) },
];

/** Provider pools from the snapshot. Hidden from the sidebar until the fleet feature is enabled. */
export function QuotaList() {
    const go = useGo();
    const status = useQuery(proxycliStatusQuery);
    const providers = useQuery({
        ...proxycliProvidersQuery,
        enabled: status.data?.enabled === true,
    });

    if (status.data?.enabled !== true) {
        return (
            <Frame title="Quota" state="warn">
                <Note>proxycli is disabled. Enable the fleet feature to collect CLIProxyAPI quota.</Note>
            </Frame>
        );
    }

    return (
        <div className="grid h-full grid-rows-[auto_minmax(0,1fr)] gap-y-[16px]">
            <PageHeader trail={[{ label: "Quota" }]} />
            <Pane
                name="list"
                order={1}
                title="Quota"
                columns={providerColumns}
                rows={providers.data ?? []}
                rowId={(row) => row.provider}
                onRowClick={(row) => go.quota(row.provider)}
                empty={providers.isPending ? "Loading…" : "No provider pools in the snapshot."}
            />
        </div>
    );
}

export function QuotaProviderPage() {
    const { id } = useParams({ from: "/$section/$id" });
    const status = useQuery(proxycliStatusQuery);
    const provider = useQuery({
        ...proxycliProviderQuery(id),
        enabled: status.data?.enabled === true,
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
                header: "Windows",
                width: 28,
                value: (row) => row.windows.map(windowRemaining).join("  ") || "—",
            },
            {
                header: "Resets",
                width: 24,
                value: (row) =>
                    row.windows
                        .map(windowReset)
                        .filter((value): value is string => value !== null)
                        .join("  ") || "—",
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

    if (status.data?.enabled !== true) {
        return (
            <Frame title="Quota" state="warn">
                <Note>proxycli is disabled.</Note>
            </Frame>
        );
    }

    if (provider.data === undefined) {
        return (
            <Frame title={id}>
                <Note>{provider.isPending ? "Loading…" : `No provider ${id} in the snapshot.`}</Note>
            </Frame>
        );
    }

    const pool = provider.data;

    return (
        <div className="grid h-full grid-rows-[auto_auto_minmax(0,1fr)] gap-y-[16px]">
            <PageHeader trail={[{ label: "Quota" }, { label: pool.provider }]} />
            <Properties
                properties={[
                    { name: "Provider", value: pool.provider },
                    {
                        name: "Windows",
                        value: pool.windows.map((window) => window.label).join(", ") || null,
                    },
                ]}
            />
            <Pane
                name="accounts"
                order={1}
                title="Accounts"
                columns={accountColumns}
                rows={pool.accounts}
                rowId={(row) => row.id}
                warn={(row) => row.disabled || row.error !== null}
                empty={provider.isPending ? "Loading…" : "No accounts in this pool."}
            />
        </div>
    );
}
