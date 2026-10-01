import { useQuery } from "@tanstack/react-query";
import { useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { api, GatewayError } from "../api/client";
import { queryClient } from "../api/queryClient";
import { toolInventoryQuery, toolsQuery } from "../api/queries";
import type { Node, Tool, ToolInventory, ToolInventoryPackage } from "../api/types";
import {
    adoptBody,
    adoptControlId,
    adoptPrompt,
    adoptResultMessage,
    canAdopt,
    managerScanNote,
    observedVersion,
    removePrompt,
    removeResultMessage,
    supportLabel,
    unmanagedEmpty,
    unmanagedPackages,
    updateResultMessage,
    withPackageRegistered,
    withoutPackage,
} from "../fleet/toolInventory";
import { Frame, Note } from "../ui/Frame";
import { type Column, Pane } from "../ui/Pane";
import { ui } from "../ui/store";

const actionButton = (phone: boolean) =>
    `cursor-pointer border-0 bg-transparent font-[inherit] text-cyan outline-none hover:underline focus:bg-fg focus:text-bg disabled:cursor-not-allowed disabled:text-dim ${
        phone ? "inline-flex min-h-[44px] items-center px-[1ch]" : "p-0"
    }`;

function usePhoneLayout(): boolean {
    const [phone, setPhone] = useState(
        () => typeof window !== "undefined" && window.matchMedia("(max-width: 767px)").matches,
    );

    useEffect(() => {
        const media = window.matchMedia("(max-width: 767px)");
        const apply = () => setPhone(media.matches);
        apply();
        media.addEventListener("change", apply);

        return () => media.removeEventListener("change", apply);
    }, []);

    return phone;
}

export function toolRecordedVersion(tool: Tool): string {
    return tool.installed_version ?? "—";
}

/** Phone detail. A dependency already states that block, so it is not repeated. */
function packageDetail(pkg: ToolInventoryPackage): string {
    const observed = pkg.installed_version ?? "unreadable";
    const support = supportLabel(pkg);

    if (pkg.dependency && support === "dependency") {
        return `observed ${observed} · dependency`;
    }

    return `observed ${observed} · ${pkg.dependency ? "dependency" : "root"} · ${support}`;
}

export function toolConstraint(tool: Tool): string {
    return tool.version_constraint ?? "—";
}

/** The failed operation and its code, or a dash when the stored tool has no failure. */
export function toolFailure(tool: Tool): string {
    const parts = [tool.failed_operation, tool.error_code].filter(
        (part): part is string => part !== null && part !== "",
    );

    return parts.length === 0 ? "—" : parts.join(" · ");
}

type Busy = "adopt" | "update" | "remove" | null;

type AdoptForm = { manager: string; package: string; version: string | null };

function ToolActions({
    tool,
    phone,
    busy,
    onUpdate,
    onRemove,
}: {
    tool: Tool;
    phone: boolean;
    busy: Busy;
    onUpdate: (tool: Tool) => void;
    onRemove: (tool: Tool) => void;
}) {
    return (
        <span className={phone ? "flex flex-wrap gap-x-[1ch]" : "flex justify-end gap-x-[1ch]"}>
            <button
                type="button"
                data-testid={`node-tools-update-${tool.id}`}
                className={actionButton(phone)}
                disabled={busy !== null}
                onMouseDown={(event) => event.stopPropagation()}
                onClick={(event) => {
                    event.stopPropagation();
                    onUpdate(tool);
                }}
            >
                Update
            </button>
            <button
                type="button"
                data-testid={`node-tools-remove-${tool.id}`}
                className={actionButton(phone)}
                disabled={busy !== null}
                onMouseDown={(event) => event.stopPropagation()}
                onClick={(event) => {
                    event.stopPropagation();
                    onRemove(tool);
                }}
            >
                Remove
            </button>
        </span>
    );
}

function Dialog({
    testId,
    title,
    label,
    children,
    onClose,
}: {
    testId: string;
    title: string;
    label: string;
    children: ReactNode;
    onClose: () => void;
}) {
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    useLayoutEffect(() => {
        const close = () => onCloseRef.current();
        ui.set({ dialog: close });

        return () => {
            if (ui.get().dialog === close) {
                ui.set({ dialog: null });
            }
        };
    }, []);

    return (
        <div
            className="fixed inset-0 z-20 bg-bg/70"
            role="dialog"
            aria-modal="true"
            aria-label={label}
            data-testid={testId}
            onMouseDown={onClose}
        >
            <div
                className="absolute top-[8vh] left-1/2 flex max-h-[80vh] w-[min(68ch,calc(100%-2ch))] -translate-x-1/2 flex-col bg-bg"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <Frame title={title} state="focused" bottomRight="Esc closes" className="w-full">
                    {children}
                </Frame>
            </div>
        </div>
    );
}

function rememberOwnership(nodeId: number, manager: string, packageName: string, toolId: number) {
    queryClient.setQueryData<ToolInventory>(["tool-inventory", nodeId], (current) =>
        current === undefined
            ? current
            : withPackageRegistered(current, manager, packageName, toolId),
    );
}

function forgetPackage(nodeId: number, manager: string, packageName: string) {
    queryClient.setQueryData<ToolInventory>(["tool-inventory", nodeId], (current) =>
        current === undefined ? current : withoutPackage(current, manager, packageName),
    );
}

export function NodeTools({ node }: { node: Node }) {
    const phone = usePhoneLayout();
    const tools = useQuery(toolsQuery(node.id));
    const active = node.status === "active";
    const inventory = useQuery({ ...toolInventoryQuery(node.id), enabled: active });
    const [adopt, setAdopt] = useState<AdoptForm | null>(null);
    const [constraint, setConstraint] = useState("");
    const [adoptError, setAdoptError] = useState<string | null>(null);
    const [remove, setRemove] = useState<Tool | null>(null);
    const [removeError, setRemoveError] = useState<string | null>(null);
    const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);
    const [busy, setBusy] = useState<Busy>(null);
    const lock = useRef(false);
    const resultRef = useRef<HTMLParagraphElement>(null);

    useEffect(() => {
        resultRef.current?.scrollIntoView({ block: "nearest" });
    }, [result]);

    const scan = inventory.data;
    const unmanaged = useMemo(() => (scan === undefined ? [] : unmanagedPackages(scan)), [scan]);
    const notes = scan?.managers.map(managerScanNote).filter((note) => note !== null) ?? [];
    const stale = inventory.isRefetchError && scan !== undefined;
    const unavailable = !active || (inventory.isError && scan === undefined);

    const run = async (kind: Exclude<Busy, null>, task: () => Promise<void>) => {
        if (lock.current) {
            return;
        }

        lock.current = true;
        setBusy(kind);

        try {
            await task();
        } finally {
            lock.current = false;
            setBusy(null);
        }
    };

    const updateTool = (tool: Tool) => {
        void run("update", async () => {
            try {
                const updated = await api<Tool>("POST", `/api/v1/tools/${tool.id}/update`, {});
                await queryClient.invalidateQueries({ queryKey: ["tools", node.id] });
                setResult({
                    ok: updated.outcome !== "blocked_by_constraint",
                    text: updateResultMessage(updated),
                });
            } catch (error) {
                setResult({
                    ok: false,
                    text: error instanceof GatewayError ? error.message : String(error),
                });
            }
        });
    };

    const confirmRemove = () => {
        if (remove === null) {
            return;
        }

        const tool = remove;
        void run("remove", async () => {
            try {
                const removed = await api<Tool>("DELETE", `/api/v1/tools/${tool.id}`);
                forgetPackage(node.id, tool.manager, tool.package);
                await queryClient.invalidateQueries({ queryKey: ["tools", node.id] });
                setRemove(null);
                setRemoveError(null);
                setResult({ ok: true, text: removeResultMessage(removed) });
            } catch (error) {
                setRemoveError(error instanceof GatewayError ? error.message : String(error));
            }
        });
    };

    const submitAdopt = () => {
        if (adopt === null) {
            return;
        }

        const form = adopt;
        const draft = constraint.trim();
        void run("adopt", async () => {
            try {
                const created = await api<Tool>(
                    "POST",
                    "/api/v1/tools/adopt",
                    adoptBody(node.id, form.manager, form.package, draft),
                );
                rememberOwnership(node.id, form.manager, form.package, created.id);
                await queryClient.invalidateQueries({ queryKey: ["tools", node.id] });
                setAdopt(null);
                setAdoptError(null);
                setResult({ ok: true, text: adoptResultMessage(created) });
            } catch (error) {
                setAdoptError(error instanceof GatewayError ? error.message : String(error));
            }
        });
    };

    const openAdopt = (pkg: ToolInventoryPackage) => {
        if (!canAdopt(pkg) || busy !== null) {
            return;
        }

        setConstraint("");
        setAdoptError(null);
        setAdopt({ manager: pkg.manager, package: pkg.package, version: pkg.installed_version });
    };

    const toolColumns = useMemo<Column<Tool>[]>(() => {
        const columns: Column<Tool>[] = [
            { header: "Manager", width: 12, fit: true, value: (tool) => tool.manager },
            { header: "Package", width: 22, value: (tool) => tool.package },
            { header: "Recorded", width: 14, fit: true, value: toolRecordedVersion },
            {
                header: "Observed",
                width: 14,
                fit: true,
                hideOnMobile: true,
                value: (tool) => observedVersion(tool, scan),
            },
            {
                header: "Constraint",
                width: 14,
                fit: true,
                hideOnMobile: true,
                value: toolConstraint,
            },
            { header: "Status", width: 12, fit: true, value: (tool) => tool.status },
            {
                header: "Failure",
                width: 28,
                hideOnMobile: true,
                value: toolFailure,
            },
        ];

        if (!phone) {
            columns.push({
                header: "Actions",
                width: 16,
                fit: true,
                align: "right",
                value: () => "Update Remove",
                cell: (tool) => (
                    <ToolActions
                        tool={tool}
                        phone={false}
                        busy={busy}
                        onUpdate={updateTool}
                        onRemove={(next) => {
                            setRemoveError(null);
                            setRemove(next);
                        }}
                    />
                ),
            });
        }

        return columns;
        // The action callbacks close over the latest busy flag. Recreating columns is cheap.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phone, busy, scan]);

    const detectedColumns = useMemo<Column<ToolInventoryPackage>[]>(() => {
        const columns: Column<ToolInventoryPackage>[] = [
            { header: "Manager", width: 12, fit: true, value: (pkg) => pkg.manager },
            { header: "Package", width: 22, value: (pkg) => pkg.package },
            { header: "Kind", width: 10, fit: true, value: (pkg) => pkg.package_kind },
            {
                header: "Observed",
                width: 14,
                fit: true,
                hideOnMobile: true,
                value: (pkg) => pkg.installed_version ?? "unreadable",
            },
            {
                header: "Dependency",
                width: 12,
                fit: true,
                hideOnMobile: true,
                value: (pkg) => (pkg.dependency ? "dependency" : "—"),
            },
            {
                header: "Support",
                width: 22,
                hideOnMobile: true,
                value: supportLabel,
                cell: (pkg) => (
                    <span className={pkg.adoption === "supported" ? "" : "text-dim"}>
                        {supportLabel(pkg)}
                    </span>
                ),
            },
        ];

        if (!phone) {
            columns.push({
                header: "",
                width: 10,
                fit: true,
                align: "right",
                value: (pkg) => (canAdopt(pkg) ? "Adopt" : ""),
                cell: (pkg) =>
                    canAdopt(pkg) ? (
                        <button
                            type="button"
                            data-testid={adoptControlId(pkg)}
                            className={actionButton(false)}
                            disabled={busy !== null}
                            onMouseDown={(event) => event.stopPropagation()}
                            onClick={(event) => {
                                event.stopPropagation();
                                openAdopt(pkg);
                            }}
                        >
                            Adopt
                        </button>
                    ) : (
                        <span className="text-dim"> </span>
                    ),
            });
        }

        return columns;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [phone, busy]);

    if (tools.isPending) {
        return (
            <Frame title="Tools">
                <Note>Loading…</Note>
            </Frame>
        );
    }

    if (tools.isError) {
        return (
            <Frame title="Tools" state="warn" testId="node-tools-error">
                <Note>{tools.error.message}</Note>
            </Frame>
        );
    }

    const inspectionText = !active
        ? "Inspection unavailable. This Node is unreachable, so no scan was started."
        : inventory.isLoading
          ? "Reading installed packages…"
          : stale && scan !== undefined
            ? `Inspection failed. Showing packages observed at ${scan.observed_at}.`
            : unavailable
              ? "Inspection unavailable."
              : scan !== undefined
                ? `Observed at ${scan.observed_at}.`
                : "Inspection unavailable.";

    const failureMessage = (error: unknown): string =>
        error instanceof GatewayError ? error.message : "The scan could not be read.";

    return (
        <div className="flex h-full min-h-0 min-w-0 flex-col gap-y-[var(--panel-gap)]">
            {node.status !== "active" && (
                <p className="text-dim" data-testid="node-tools-offline">
                    This Node is unreachable. These registered tools are stored records.
                </p>
            )}
            <div
                className="flex min-h-[44px] items-center justify-between gap-x-[1ch]"
                data-testid="node-tools-inspection"
            >
                <p className="min-w-0 text-dim">
                    {inspectionText}
                    {stale
                        ? ` ${failureMessage(inventory.error)} Registered tools are unchanged.`
                        : ""}
                    {unavailable && active && inventory.isError
                        ? ` ${failureMessage(inventory.error)} Registered tools are unchanged.`
                        : ""}
                    {!active ? " Registered tools are unchanged." : ""}
                </p>
                <button
                    type="button"
                    data-testid="node-tools-refresh"
                    className={`${actionButton(true)} shrink-0`}
                    disabled={!active || inventory.isFetching || busy !== null}
                    onClick={() => {
                        if (active) {
                            void inventory.refetch();
                        }
                    }}
                >
                    {inventory.isFetching ? "Refreshing…" : "Refresh"}
                </button>
            </div>
            {stale && (
                <p className="text-yellow" data-testid="node-tools-scan-stale">
                    This observation is stale. Refresh did not adopt or update anything.
                </p>
            )}
            {unavailable && (
                <p className="text-yellow" data-testid="node-tools-scan-unavailable">
                    {active
                        ? "The scan is unavailable. This is not an empty inventory."
                        : "No scan was started. This is not an empty inventory."}
                </p>
            )}
            {notes.length > 0 && scan !== undefined && (
                <div data-testid="node-tools-scan-states" className="text-dim">
                    {notes.map((note) => (
                        <p key={note}>{note}</p>
                    ))}
                </div>
            )}
            {result !== null && (
                <p
                    ref={resultRef}
                    data-testid="node-tools-result"
                    className={result.ok ? "text-fg" : "text-red"}
                >
                    {result.text}
                </p>
            )}
            <Pane
                name="tools"
                order={1}
                title="Tools"
                testId="node-tools-list"
                className="min-h-[160px] w-full flex-1"
                columns={toolColumns}
                rows={tools.data}
                rowId={(tool) => String(tool.id)}
                warn={(tool) => tool.status === "failed"}
                detail={(tool) => (
                    <span className="block">
                        <span className="block">
                            {`observed ${observedVersion(tool, scan)} · constraint ${toolConstraint(tool)} · ${toolFailure(tool)}`}
                        </span>
                        {phone && (
                            <ToolActions
                                tool={tool}
                                phone
                                busy={busy}
                                onUpdate={updateTool}
                                onRemove={(next) => {
                                    setRemoveError(null);
                                    setRemove(next);
                                }}
                            />
                        )}
                    </span>
                )}
                empty="No registered tools."
            />
            {scan === undefined ? (
                <Frame
                    title="Detected packages"
                    testId="node-tools-unmanaged"
                    className="min-h-[80px]"
                >
                    <Note>
                        {inventory.isLoading
                            ? "Reading installed packages…"
                            : "Installed packages were not read."}
                    </Note>
                </Frame>
            ) : (
                <Pane
                    name="detected-packages"
                    order={2}
                    title="Detected packages"
                    testId="node-tools-unmanaged"
                    className="min-h-[120px] w-full flex-1"
                    columns={detectedColumns}
                    rows={unmanaged}
                    rowId={(pkg) => `${pkg.manager}:${pkg.package}`}
                    detail={(pkg) => (
                        <span className="block text-dim">
                            <span className="block">{packageDetail(pkg)}</span>
                            {phone && canAdopt(pkg) && (
                                <button
                                    type="button"
                                    data-testid={adoptControlId(pkg)}
                                    className={actionButton(true)}
                                    disabled={busy !== null}
                                    onMouseDown={(event) => event.stopPropagation()}
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        openAdopt(pkg);
                                    }}
                                >
                                    Adopt
                                </button>
                            )}
                        </span>
                    )}
                    empty={unmanagedEmpty(scan)}
                    bottomLeft="Informational. Discovery does not change Node health."
                />
            )}
            {adopt !== null && (
                <Dialog
                    testId="node-tools-adopt-form"
                    title="Adopt package"
                    label="Adopt package"
                    onClose={() => {
                        if (busy === null) {
                            setAdopt(null);
                        }
                    }}
                >
                    <form
                        className="flex flex-col gap-y-[10px]"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submitAdopt();
                        }}
                    >
                        <p data-testid="node-tools-adopt-prompt" className="whitespace-normal">
                            {adoptPrompt(node.id, adopt.manager, adopt.package, constraint.trim())}
                        </p>
                        <p className="text-dim whitespace-normal">
                            Observed version {adopt.version ?? "unreadable"}. Adoption does not
                            install or update the package.
                        </p>
                        <label className="flex flex-col gap-y-[4px]">
                            <span className="text-dim">Version constraint</span>
                            <input
                                data-testid="node-tools-adopt-constraint"
                                aria-label="Version constraint"
                                value={constraint}
                                placeholder="optional, such as ^1.0"
                                className="border border-line bg-transparent px-[1ch] text-fg outline-none placeholder:text-dim focus:border-cyan"
                                onChange={(event) => setConstraint(event.target.value)}
                            />
                        </label>
                        {adoptError !== null && (
                            <p
                                data-testid="node-tools-adopt-error"
                                className="whitespace-normal text-red"
                            >
                                {adoptError}
                            </p>
                        )}
                        <div className="flex min-h-[44px] items-center gap-x-[2ch]">
                            <button
                                type="submit"
                                data-testid="node-tools-adopt-submit"
                                className={actionButton(true)}
                                disabled={busy !== null}
                            >
                                {busy === "adopt" ? "Adopting…" : "Adopt"}
                            </button>
                            <button
                                type="button"
                                data-testid="node-tools-adopt-cancel"
                                className={`${actionButton(true)} text-dim`}
                                disabled={busy !== null}
                                onClick={() => setAdopt(null)}
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </Dialog>
            )}
            {remove !== null && (
                <Dialog
                    testId="node-tools-remove-form"
                    title="Remove tool"
                    label="Remove tool"
                    onClose={() => {
                        if (busy === null) {
                            setRemove(null);
                        }
                    }}
                >
                    <p data-testid="node-tools-remove-prompt" className="whitespace-normal">
                        {removePrompt(remove)}
                    </p>
                    {removeError !== null && (
                        <p
                            data-testid="node-tools-remove-error"
                            className="mt-[10px] whitespace-normal text-red"
                        >
                            {removeError}
                        </p>
                    )}
                    <div className="mt-[10px] flex min-h-[44px] items-center gap-x-[2ch]">
                        <button
                            type="button"
                            data-testid="node-tools-remove-confirm"
                            className={`${actionButton(true)} text-red`}
                            disabled={busy !== null}
                            onClick={confirmRemove}
                        >
                            {busy === "remove" ? "Removing…" : "Remove"}
                        </button>
                        <button
                            type="button"
                            data-testid="node-tools-remove-cancel"
                            className={`${actionButton(true)} text-dim`}
                            disabled={busy !== null}
                            onClick={() => setRemove(null)}
                        >
                            Cancel
                        </button>
                    </div>
                </Dialog>
            )}
        </div>
    );
}
