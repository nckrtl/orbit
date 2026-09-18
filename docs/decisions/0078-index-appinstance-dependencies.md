---
title: "ADR 0078: Index App instance dependencies"
description: "Proposed instance-owned lockfile inventory and development-only dependency updates."
---

# ADR 0078: Index App instance dependencies

Orbit records dependencies from each App instance's manifests and lockfiles, shares package identities across instances, and limits package updates to development source within its declared constraints.

## Status

Proposed.

## Context

An App identifies a repository, while its instances can select different source and dependency versions. A fleet inventory needs to identify which instances resolve a package and which dependency paths introduce it. Direct requirements alone omit transitive dependencies; regular requirements alone omit build and development tools.

[ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit) binds production serving content to the selected release. [ADR 0075](/decisions/0075-prune-idle-app-dev-checkout-dependencies) permits removal of reconstructable development dependency directories while retaining lockfiles. Installed directories therefore cannot supply a durable inventory for idle instances.

## Decision

- The Gateway owns observed dependency inventory per App instance, with shared identities keyed by ecosystem and canonical package name.
- Instance observations hold resolved versions, source provenance, requirement relationships, and development scope. One instance can contain multiple resolutions of one identity.
- Supported JavaScript managers are npm, pnpm, and Bun; Composer remains supported. The owner’s [2026-09-15 scope amendment](/reference/instance-dependencies#yarn-scope-amendment) excludes Yarn Classic and modern Yarn from scans and updates. Yarn selection fails explicitly without fallback or successful empty inventory, and before any update mutation.
- Inventory reads root manifests and lockfiles without package execution or registry requests. It describes resolved source, not verified installed state or exploitability.
- Composer and JavaScript dependencies include direct, transitive, regular, and development paths. Peer requirements remain distinct from evidence of a resolved package.
- Successful observations replace usage atomically per ecosystem. Failed or incomplete collection preserves the last successful observation and exposes stale or unknown state.
- Scan supports directory detection, a uniquely resolved full instance domain, and sequential all-instance execution. An all-instance scan continues after individual failures and reports partial failure.
- One operator-configured nightly Schedule on the Gateway Node runs the all-instance scan through the existing Schedule mechanism.
- Dependency update supports one development instance. The Gateway runs Composer and Vite+ updates in its project root and respects the declared version constraints.
- Package updates refresh the inventory after package work, including readable results after partial failure. They do not claim transaction-wide rollback across package managers.
- Production dependency-update requests fail before mutation. Operators verify and commit development changes, then explicitly deploy production with installation from the committed lockfiles.
- Constraint-changing upgrades, bulk updates, monorepos, advisory checks, and newer-version discovery are outside this decision.
- CLI policy stays in the Gateway; the CLI and PHP SDK select, transport, and render the typed contract. Domain selection never chooses an arbitrary member of a Route pool.
- This decision extends ADR 0046 with the development dependency update boundary, ADR 0071 with the `instance:dependencies:scan` and `instance:dependencies:update` commands, and ADR 0075 with lockfile observation during hibernation.

## Rejected alternatives

- Store one version per App: instances can run different source versions.
- Store one global version on each dependency identity: different instances and dependency paths can resolve different versions.
- Retain Yarn support from the earlier proposal: the owner explicitly narrowed the supported managers; historical task titles do not override that amendment.
- Inventory only direct regular dependencies: transitive packages and development tools remain unaccounted for.
- Require installed package directories for inventory: hibernated instances lose reconstructable directories while retaining their source requirements.
- Resolve dependency versions during production updates: this bypasses the tested source and explicit release deployment flow.
- Refresh inventory only after Orbit updates packages: external dependency changes remain undiscovered without explicit or nightly scans.
- Create a Schedule for every instance: one fleet scan discovers the registered targets on every run.

## Consequences

- Shared package identities support fleet queries without erasing per-instance versions.
- Package resolution graphs require more detail than one pivot row per instance and package.
- Consumers can inspect when a snapshot was collected. They cannot infer that its packages are installed.
- Lockfile adapters must reject unsupported formats and preserve package relationships without executing project code.
- npm lockfile v2/v3 adapters treat bundled dependency names without separate package entries as optional unresolved edges, and they ignore transitive `workspaces` package metadata while still rejecting root workspace layouts.
- A package update can partially succeed. Results and inventory must describe the resulting state.
- The scan and update commands require Gateway, SDK, CLI, and Incus verification before feature completion.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli, apps/e2e, apps/docs
- ADRs: extends [ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit), [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk), and [ADR 0075](/decisions/0075-prune-idle-app-dev-checkout-dependencies)
- Detail: [App instance dependencies](/reference/instance-dependencies), [Gateway dependency contracts](/reference/instance-dependency-contracts)
- Verify: `composer docs-build`; `composer docs-lint`; parser fixtures, Gateway inventory and mutation tests, SDK contracts, CLI selector and result tests, and Incus scan, update, production refusal, and nightly Schedule verification
