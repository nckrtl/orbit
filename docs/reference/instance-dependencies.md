---
title: "App instance dependencies"
description: "Index resolved dependencies and update development instances within their declared version constraints."
---

# App instance dependencies

Scan an App instance to record its resolved Composer and JavaScript dependencies in the Gateway. Update a development instance to resolve newer versions within its declared constraints, then refresh its inventory. [ADR 0078](/decisions/0078-index-appinstance-dependencies) owns the inventory and update boundaries.

## What the inventory describes

The inventory belongs to an App instance. Two instances of the same App can use different versions. An App summary combines its instances without assigning one version to the whole App.

Orbit reads the root manifests and lockfiles. A development scan reads the recorded checkout; a production scan reads the selected release. The root is the project directory, not its public web directory. Nested projects and monorepo workspaces are unsupported.

| Source | Meaning |
| --- | --- |
| `composer.json` and `package.json` | Direct requirements, version constraints, and development scope. |
| Composer and JavaScript lockfiles | Exact resolved versions and package relationships. |
| Selected checkout or release | The source represented by the observation. |

An inventory records locked versions. It does not prove that packages are installed or that an instance is vulnerable. An idle development instance retains its dependency inventory when Orbit removes reconstructable directories. See [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation).

## Package identity and relationships

The Gateway keeps one dependency identity per ecosystem and canonical package name. The ecosystems are `composer` and `npm`; npm, pnpm, and Bun are supported JavaScript package managers, not separate package identities. Used versions belong to instance observations.

An instance can resolve several versions of one package. Orbit preserves distinct resolutions and the relationships that introduce them. Version strings and source references retain their meaning; a branch reference is not converted into a release version.

| Relationship or scope | Meaning |
| --- | --- |
| Direct | The root manifest declares the dependency. |
| Transitive | Another package requires the dependency. |
| Peer | A package expects a compatible dependency supplied by its consumer. |
| Development | The dependency is required for development, testing, or building. |
| Regular | The dependency is reachable from a regular root requirement. |

These properties can overlap. A package can have direct and transitive paths, or both regular and development paths. A peer requirement alone does not prove a resolved package exists. Optional requirements and unresolved peers retain their relationship without inventing an installed version.

The inventory includes development dependencies and preserves requirement paths. Those paths explain which direct requirement introduces a transitive package. Scan results retain source provenance when available, without credentials or authenticated source URLs.

## Supported root lockfiles

Each parser reads data only. It does not load project code, run a package manager, or query a registry. The supported formats are:

| Ecosystem | Root files | Supported format |
| --- | --- | --- |
| Composer | `composer.json`, `composer.lock` | Composer 1 and 2 JSON lock structure with `packages` and `packages-dev`; Composer has no lockfile format version field. Preserve aliases, source references, and platform or virtual requirements without inventing package resolutions. |
| npm | `package.json`, `package-lock.json` or `npm-shrinkwrap.json` | `lockfileVersion` 2 and 3, using the `packages` map and installation paths to distinguish resolutions. Shrinkwrap takes precedence over package-lock within npm. Version 1 is unsupported. |
| pnpm | `package.json`, `pnpm-lock.yaml` | `lockfileVersion` 9.0, one root importer (`.`), package records and snapshots, including peer context. Earlier versions are unsupported. |
| Bun | `package.json`, `bun.lock` | Text JSONC lock with `lockfileVersion` 1 and only its root workspace. Binary `bun.lockb` is unsupported. |

JavaScript file selection follows the project's Vite+ package-manager selection signals, limited to npm, pnpm, and Bun. Yarn Classic and modern Yarn are unsupported for both scans and updates. Yarn manager or lockfile selection produces an explicit failure; it never becomes an empty successful inventory or a fallback to another manager.

Conflicting signals or an ambiguous selection fail explicitly. A selected manager must have a supported root lockfile; another manager's lockfile cannot silently substitute for it. Workspace declarations, non-root importers or workspace entries, and local package links that require scanning another project produce an unsupported-layout error. The root records used by npm, pnpm, and Bun do not by themselves make a project a monorepo.

Parsers reject malformed or unrecognized records that prevent a complete graph. They preserve opaque versions, aliases, optional requirements, and unresolved peer or virtual requirements. They do not guess a resolution from a version constraint. Unsupported data produces a failed scan with retained stale or unknown inventory, never a successful empty graph.

## Select and scan an instance

Use the current directory or an explicit instance domain. The domain selects an instance, not every instance of an App.

| Command | Result |
| --- | --- |
| `orbit instance:dependencies:scan` | Detect the managed instance containing the current directory and scan it. |
| `orbit instance:dependencies:scan --app=commander.test` | Scan the instance uniquely selected by its full Route domain. |
| `orbit instance:dependencies:scan --all` | Scan registered instances visible through the selected Gateway, one by one. |

An explicit domain takes precedence over directory detection. No match or more than one matching instance produces an error before work starts. A domain serving multiple instances does not select one arbitrarily. `--all` and `--app` are mutually exclusive. `--all` does not require an instance working directory.

The Gateway authorizes each target and reads source on its owning Node. A scan does not install packages, run package scripts, contact package registries, or change application files. It records observation data in the Gateway.

Human output identifies each instance, its scan result, and counts by ecosystem. `--json` returns machine-readable results without prompts or terminal decoration. The all-instance result includes every attempted target and a final summary.

## Refresh and failures

Each ecosystem has a last successful observation and a latest scan outcome. A successful scan replaces that ecosystem's observation atomically, including removal of dependencies absent from the new result. Repeated scans do not accumulate duplicate usage records.

| Condition | Result |
| --- | --- |
| Manifest and lockfile are valid | Replace the ecosystem observation and record its source and scan time. |
| Both manifest and lockfile are absent | Record that the ecosystem is absent and clear its previous usage. |
| Manifest exists but its lockfile is missing | Report an incomplete scan; preserve the last successful observation. |
| Lockfile exists without its manifest | Report an incomplete scan; preserve the last successful observation. |
| Format is unsupported, files are invalid, or source is unreadable | Report failure and preserve the last successful observation. |
| No production release is selected | Report unavailable source, not an empty inventory. |
| Source changes during collection | Refuse to publish the mixed observation and report a retryable conflict. |

A failed attempt marks retained data as stale and exposes the failure time. An instance never scanned has unknown inventory. A scan time describes an observation, not a guarantee that the checkout remains unchanged.

An all-instance scan captures its target list when it starts and continues after individual failures. It returns a nonzero status if any instance scan fails or is incomplete. A successful ecosystem result remains recorded when the other ecosystem fails; the instance result still reports partial failure.

Scans coordinate with Orbit-owned updates, deployment, rollback, and removal. An operation cannot publish inventory for a removed instance or a release that changed during collection. Removing an instance removes its usage and observation records without deleting another instance's package identities.

## Update development dependencies

The update command uses the same directory detection and domain selector as scan. It accepts one development instance and rejects production targets before running package commands.

```bash
orbit instance:dependencies:update
orbit instance:dependencies:update --app=commander.test
```

Orbit runs `composer update` for a root Composer project, then `vp update` for a root JavaScript project, as the instance's runtime user. Vite+ selects the project's supported package manager: npm, pnpm, or Bun. Both regular and development dependencies are included. An absent ecosystem is skipped.

An update respects the existing declared version constraints. Changing those constraints is an upgrade and is outside this feature. The command has no `--latest` or `--all` update mode. A successful update can retain a package version when its constraints prevent movement.

Orbit validates target, source layout, and package-manager support before package mutation. Yarn selection rejects the entire update before either Composer or JavaScript package work starts. It serializes managed updates for the same instance, bounds command execution, and reports verified progress. It does not discard existing source changes, commit, push, test, or deploy automatically.

Composer and JavaScript updates are separate steps. If a step fails, Orbit stops package mutation and reports any completed work. It does not claim an automatic rollback. After completed or failed package work, Orbit scans the resulting readable files and reports the inventory outcome separately. If collection fails, the last successful inventory remains visible as stale. `--json` reports the same step outcomes without human output.

Test the development changes, commit the updated manifests and lockfiles, and make that commit available on the production instance's deployment branch. Then trigger an explicit [deployment](/reference/deployments). Configure dependency installation to use those lockfiles, rather than resolve new versions in production.

## Schedule one nightly scan

Create one Node [Schedule](/reference/schedules) on the Gateway host with `orbit instance:dependencies:scan --all` as its command. Select the nightly execution time and timezone when configuring the Schedule. Its runtime user needs the Orbit executable and an active Gateway profile that can address the target fleet.

Each run discovers the current instance set. New instances need no separate Schedule. The existing Schedule mechanism records the latest success or error, retains command logs, and prevents overlap of the same scheduled service. Set its execution timeout for the fleet size; a timeout must not appear as a completed fleet scan.

Nightly scanning refreshes inventory. It does not check advisories, query registries for newer versions, or update packages. The update command refreshes inventory after its own package work. Changes made outside that command appear after an explicit scan or the next nightly run.

## Limits

The index covers root package projects and their locked dependency graphs. Monorepos, installed-file verification, security advisory checks, outdated counts, constraint-changing upgrades, and bulk package updates are outside this feature. Unsupported lockfile formats produce a visible failure instead of a complete-looking inventory.

## Yarn scope amendment

On 2026-09-15, the owner instructed: “Lets exclude yarn, only support npm, pnpm and bun”. Composer remains supported. This amendment supersedes earlier Yarn requirements throughout this feature, including parser, collection, update, scheduling, and integrated verification plans. Yarn scan failures preserve earlier inventory as stale; a first failed scan remains unknown. Yarn updates must fail before package mutation.

Commander child 9 retains the original title “08. Parse modern Yarn lockfiles”, but its replacement outcome removes the partial modern reader and the accepted Classic reader, with their dedicated tests and fixtures. The shared supported parsers and contracts remain. Earlier commits and frozen task records remain intact; handoffs and reviews assess this replacement outcome rather than claiming the original Yarn criteria passed.

Commander child 22 retains the original title “21. Enforce constrained Vite+ updates for Yarn”, but now verifies Yarn refusal. It checks manager and lockfile rejection before mutation, human and machine failure output, and stale inventory after unsupported scans. Actual refusal checks use fixtures on the app-dev machine in the feature’s discovery clone. It does not implement Yarn updates. Other children retain their outcomes within the narrowed scope, and integrated verification still gates feature completion.
