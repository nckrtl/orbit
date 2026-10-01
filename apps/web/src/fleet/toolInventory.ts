import type { Tool, ToolInventory, ToolInventoryManager, ToolInventoryPackage } from "../api/types";

/** Packages a completed scan found that Orbit does not own yet. Incomplete scans contribute nothing. */
export function unmanagedPackages(inventory: ToolInventory): ToolInventoryPackage[] {
    return inventory.managers.flatMap((manager) =>
        manager.scan_state === "complete" ? manager.packages.filter((pkg) => !pkg.registered) : [],
    );
}

/** Why a manager's package array must not be read as an inventory. Null when the read completed. */
export function managerScanNote(manager: ToolInventoryManager): string | null {
    if (manager.scan_state === "complete") {
        return null;
    }

    return `${manager.manager} is ${manager.scan_state}, so its package list is not an inventory.`;
}

export function unmanagedEmpty(inventory: ToolInventory): string {
    const everyManagerCompleted = inventory.managers.every(
        (manager) => manager.scan_state === "complete",
    );

    return everyManagerCompleted
        ? "No unregistered packages."
        : "No packages in the completed scans.";
}

/**
 * The live reading for one stored Tool.
 * A manager the scan does not cover is "not scanned", not "absent".
 */
export function observedVersion(tool: Tool, inventory: ToolInventory | undefined): string {
    if (inventory === undefined) {
        return "unavailable";
    }

    const manager = inventory.managers.find((entry) => entry.manager === tool.manager);

    if (manager === undefined || manager.scan_state !== "complete") {
        return "not scanned";
    }

    const found = manager.packages.find((pkg) => pkg.package === tool.package);

    if (found === undefined) {
        return "absent";
    }

    return found.installed_version ?? "unreadable";
}

export function supportLabel(pkg: ToolInventoryPackage): string {
    return pkg.adoption === "supported" ? "supported" : (pkg.adoption_block ?? "unsupported");
}

/** True only when the scan says this package may be adopted. The button is absent otherwise. */
export function canAdopt(pkg: ToolInventoryPackage): boolean {
    return pkg.adoption === "supported" && !pkg.registered && !pkg.dependency;
}

/** The adopt control id. Unsupported packages never get a control. */
export function adoptControlId(pkg: Pick<ToolInventoryPackage, "manager" | "package">): string {
    const slug = pkg.package
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-|-$/g, "");

    return `node-tools-adopt-${pkg.manager}-${slug}`;
}

/** Demo packages the feature map names, so a reviewer can click them. */
export const ADOPT_DELTA = "node-tools-adopt-brew-delta";
export const ADOPT_GHOST = "node-tools-adopt-brew-ghost";

/**
 * The adopt body. An empty constraint is omitted, so the Gateway stores none.
 * No other field is sent.
 */
export function adoptBody(
    nodeId: number,
    manager: string,
    packageName: string,
    constraint: string,
): { node_id: number; manager: string; package: string; version_constraint?: string } {
    const body: {
        node_id: number;
        manager: string;
        package: string;
        version_constraint?: string;
    } = {
        node_id: nodeId,
        manager,
        package: packageName,
    };

    if (constraint !== "") {
        body.version_constraint = constraint;
    }

    return body;
}

export function adoptPrompt(
    nodeId: number,
    manager: string,
    packageName: string,
    constraint: string,
): string {
    const constraintText = constraint === "" ? "" : `, constraint ${constraint}`;

    return `Take ownership of package [${packageName}] (${manager} on Node #${nodeId}${constraintText}) and manage later updates and removal?`;
}

export function removePrompt(tool: Tool): string {
    return `Remove Tool [${tool.package}] (${tool.manager} on Node #${tool.node_id}) by uninstalling it and deleting its record?`;
}

export function adoptResultMessage(tool: Tool): string {
    return tool.outcome === "unchanged"
        ? `Tool [${tool.package}] already has this ownership with [${tool.manager}].`
        : `Tool [${tool.package}] adopted with [${tool.manager}].`;
}

export function updateResultMessage(tool: Tool): string {
    if (tool.outcome === "unchanged") {
        return `Tool [${tool.package}] is already current.`;
    }

    if (tool.outcome === "blocked_by_constraint") {
        return `Tool [${tool.package}] update blocked by constraint [${tool.version_constraint ?? ""}].`;
    }

    return `Tool [${tool.package}] updated.`;
}

export function removeResultMessage(tool: Tool): string {
    return `Tool [${tool.package}] removed.`;
}

/** Mark one discovered package owned. Every other package stays as it was. */
export function withPackageRegistered(
    inventory: ToolInventory,
    manager: string,
    packageName: string,
    toolId: number,
): ToolInventory {
    return {
        ...inventory,
        managers: inventory.managers.map((entry) => ({
            ...entry,
            packages: entry.packages.map((pkg) =>
                pkg.manager === manager && pkg.package === packageName
                    ? { ...pkg, registered: true, tool_id: toolId }
                    : pkg,
            ),
        })),
    };
}

/** Drop one package after removal uninstalls it. Other discoveries stay. */
export function withoutPackage(
    inventory: ToolInventory,
    manager: string,
    packageName: string,
): ToolInventory {
    return {
        ...inventory,
        managers: inventory.managers.map((entry) => ({
            ...entry,
            packages: entry.packages.filter(
                (pkg) => !(pkg.manager === manager && pkg.package === packageName),
            ),
        })),
    };
}
