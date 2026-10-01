import { describe, expect, it } from "vite-plus/test";
import type { Tool, ToolInventory } from "../api/types";
import {
    ADOPT_DELTA,
    ADOPT_GHOST,
    adoptBody,
    adoptControlId,
    adoptPrompt,
    canAdopt,
    managerScanNote,
    observedVersion,
    removePrompt,
    unmanagedEmpty,
    unmanagedPackages,
    withPackageRegistered,
    withoutPackage,
} from "./toolInventory";

const tool = (overrides: Partial<Tool> = {}): Tool => ({
    id: 6,
    node_id: 4,
    manager: "brew",
    package: "jq",
    version_constraint: null,
    status: "installed",
    installed_version: "1.7.1",
    failed_operation: null,
    error_code: null,
    outcome: null,
    ...overrides,
});

const inventory = (): ToolInventory => ({
    node_id: 4,
    observed_at: "2026-04-26T12:00:00+00:00",
    managers: [
        {
            manager: "brew",
            scan_state: "complete",
            packages: [
                {
                    manager: "brew",
                    package: "delta",
                    package_kind: "formula",
                    installed_version: "0.18.2",
                    dependency: false,
                    registered: false,
                    tool_id: null,
                    adoption: "supported",
                    adoption_block: null,
                },
                {
                    manager: "brew",
                    package: "jq",
                    package_kind: "formula",
                    installed_version: "1.8.0",
                    dependency: false,
                    registered: true,
                    tool_id: 6,
                    adoption: "supported",
                    adoption_block: null,
                },
                {
                    manager: "brew",
                    package: "openssl@3",
                    package_kind: "formula",
                    installed_version: "3.4.1",
                    dependency: true,
                    registered: false,
                    tool_id: null,
                    adoption: "unsupported",
                    adoption_block: "dependency",
                },
                {
                    manager: "brew",
                    package: "visual-studio-code",
                    package_kind: "formula",
                    installed_version: "1.96.0",
                    dependency: false,
                    registered: false,
                    tool_id: null,
                    adoption: "supported",
                    adoption_block: null,
                },
            ],
        },
        {
            manager: "brew-cask",
            scan_state: "complete",
            packages: [
                {
                    manager: "brew-cask",
                    package: "docker",
                    package_kind: "cask",
                    installed_version: "4.39.0",
                    dependency: false,
                    registered: false,
                    tool_id: null,
                    adoption: "unsupported",
                    adoption_block: "authorization_required",
                },
                {
                    manager: "brew-cask",
                    package: "visual-studio-code",
                    package_kind: "cask",
                    installed_version: "1.97.0",
                    dependency: false,
                    registered: true,
                    tool_id: 14,
                    adoption: "supported",
                    adoption_block: null,
                },
            ],
        },
        {
            manager: "vp",
            scan_state: "incomplete",
            packages: [],
        },
    ],
});

describe("tool inventory", () => {
    it("keeps unregistered packages and a formula that shares a cask name", () => {
        const names = unmanagedPackages(inventory()).map((pkg) => [pkg.manager, pkg.package]);

        expect(names).toEqual([
            ["brew", "delta"],
            ["brew", "openssl@3"],
            ["brew", "visual-studio-code"],
            ["brew-cask", "docker"],
        ]);
    });

    it("does not treat an incomplete manager as an empty inventory", () => {
        const scan = inventory();
        scan.managers[0] = { manager: "brew", scan_state: "incomplete", packages: [] };

        expect(unmanagedPackages(scan).map((pkg) => pkg.package)).toEqual(["docker"]);
        expect(managerScanNote(scan.managers[0])).toBe(
            "brew is incomplete, so its package list is not an inventory.",
        );
        expect(unmanagedEmpty(scan)).toBe("No packages in the completed scans.");
    });

    it("distinguishes the observed version from a missing or unscanned package", () => {
        const scan = inventory();

        expect(observedVersion(tool(), scan)).toBe("1.8.0");
        expect(observedVersion(tool({ package: "git", installed_version: "2.48.1" }), scan)).toBe(
            "absent",
        );
        expect(observedVersion(tool({ manager: "apt", package: "jq" }), scan)).toBe("not scanned");
        expect(observedVersion(tool({ manager: "vp", package: "vite-plus" }), scan)).toBe(
            "not scanned",
        );
        expect(observedVersion(tool(), undefined)).toBe("unavailable");
    });

    it("offers adoption only for a supported unregistered root package", () => {
        const [delta, openssl, formula, docker] = unmanagedPackages(inventory());

        expect(delta && canAdopt(delta)).toBe(true);
        expect(openssl && canAdopt(openssl)).toBe(false);
        expect(formula && canAdopt(formula)).toBe(true);
        expect(docker && canAdopt(docker)).toBe(false);
        expect(adoptControlId(delta!)).toBe(ADOPT_DELTA);
        expect(adoptControlId({ manager: "brew", package: "ghost" })).toBe(ADOPT_GHOST);
    });

    it("sends the node, manager, and package, and omits an empty constraint", () => {
        expect(adoptBody(4, "brew", "delta", "")).toEqual({
            node_id: 4,
            manager: "brew",
            package: "delta",
        });
        expect(adoptBody(4, "brew", "delta", "^0.18.0")).toEqual({
            node_id: 4,
            manager: "brew",
            package: "delta",
            version_constraint: "^0.18.0",
        });
        expect(adoptPrompt(4, "brew", "delta", "")).toBe(
            "Take ownership of package [delta] (brew on Node #4) and manage later updates and removal?",
        );
        expect(adoptPrompt(4, "brew", "delta", "^0.18.0")).toContain("constraint ^0.18.0");
    });

    it("names the package, manager, and node in the removal question", () => {
        expect(removePrompt(tool())).toBe(
            "Remove Tool [jq] (brew on Node #4) by uninstalling it and deleting its record?",
        );
    });

    it("changes only the adopted or removed package", () => {
        const adopted = withPackageRegistered(inventory(), "brew", "delta", 20);
        const removed = withoutPackage(inventory(), "brew", "jq");

        expect(unmanagedPackages(adopted).map((pkg) => pkg.package)).not.toContain("delta");
        expect(unmanagedPackages(adopted).map((pkg) => pkg.package)).toContain("openssl@3");
        expect(removed.managers[0]?.packages.map((pkg) => pkg.package).includes("jq")).toBe(false);
        expect(removed.managers[0]?.packages.map((pkg) => pkg.package)).toContain("delta");
    });
});
