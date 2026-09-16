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

### Single-instance CLI output

Directory detection and `--app=FULL_DOMAIN` scan one instance. The command shows target resolution and one scan step while the Gateway works. It never prompts.

Human results identify the instance, App, Node, and environment. Each ecosystem shows its freshness state, resolution and requirement counts, observation time, latest attempt time, and stable failure code. Counts for stale data describe the retained observation. Unknown counts appear as an em dash; verified absence has zero counts. Resolution counts include separate versions and contexts of the same package.

`--json` emits one SDK inventory object with `instance_id`, `succeeded`, `composer`, `javascript`, and `request_id`. The ecosystem objects retain full graphs, source provenance, timestamps, and failure codes as described in the [dependency contracts](/reference/instance-dependency-contracts). Selection and transport failures use the ordinary `error` envelope with nullable `error.request_id`. Exit status is zero only when both ecosystems succeed; partial, stale, unknown, invalid-target, and transport failures return one. No package installation or application file change occurs.

### All-instance CLI output

`orbit instance:dependencies:scan --all` captures the authorized instance list from the selected Gateway once, then scans those instances in list order. It does not inspect the current directory. `--all` with `--app` fails with `dependencies.target_conflict` before HTTP.

The listing envelope must be a JSON object whose `data` field is a JSON array. A valid empty array succeeds and reports zero attempted scans. A JSON object in `data`, including `{}` or numeric-key objects, is not an array and fails before any scan.

Each array member must be a JSON object with a positive instance ID, App ID, Node ID, non-empty name, and supported environment. Missing fields, empty objects, array members, and a malformed row after a valid row fail the entire listing. The command never treats those cases as an empty fleet or as a smaller authorized set.

Each instance uses the existing single-instance scan contract. A failed, incomplete, unreachable, or unavailable target is recorded and does not stop later captured targets. A listing transport or structured failure stops before any scan. Cancellation or an interrupt leaves unattempted targets unscanned and does not report them as complete.

Human output shows instance listing, then one scan step per target, each instance's identity and ecosystem counts or error, and a final summary of attempted, complete, failed, and skipped counts. `--json` emits one document with `succeeded`, `summary`, `instances`, and the listing `request_id`. Each attempted instance includes identity fields and either the typed inventory or a per-instance `error` object. Exit status is zero only when every captured target is scanned and both ecosystems succeed; empty fleets return zero. Partial, failed, cancelled, and listing failures return one.

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

The Composer step uses a fixed argv in the recorded project root, including roots that contain spaces. It includes `require-dev` and leaves declared constraints unchanged. It owns the remote Composer process group, enforces a remote deadline, terminates and waits for that owned work on cancellation or timeout, discards process text, and reports cancellation or failure without claiming rollback. Local cancellation still SIGKILLs the complete process group after a bounded grace period, even if the original process has already exited.

The npm step delegates through a verified Vite+ installation rather than raw npm. It accepts the Orbit-managed layouts used by Node prerequisites: `/opt/orbit/vite-plus`, `~/.vite-plus`, and `~/.local/share/vite-plus`. The `/opt/orbit/vite-plus` layout sets `VP_HOME=/opt/orbit/vite-plus`. The action also accepts the published launcher `/usr/local/bin/vp`. Vite+ 0.3.0 is verified for npm delegation; a missing, unreadable, or unverified Vite+ version fails with `dependencies.unsupported_delegation` before package mutation. The fixed argv runs `vp update --no-save` in the recorded root with no package names, pass-through arguments, or `--latest` flag.

`--no-save` is a verified Vite+ 0.3.0 option, not a raw npm substitution. It updates the lockfile and installed regular and development packages within the declared constraints. It keeps those constraints unchanged even when npm project, user, or environment configuration sets `save=true`.

Vite+ 0.3.0 selects npm from `packageManager`, `devEngines.packageManager`, or `package-lock.json`. `npm-shrinkwrap.json` is not a Vite+ 0.3.0 manager signal; npm still prefers it over `package-lock.json` once Vite+ has selected npm. A shrinkwrap-only root without an npm manager declaration is incomplete for this adapter, because Vite+ 0.3.0 would not select npm.

Vite+ can add `devEngines.packageManager` manager-selection metadata to the manifest; dependency constraints stay unchanged. Yarn signals, workspace files, and conflicting manager lockfiles fail the npm step before mutation. Duplicate `package.json` object keys, including escaped-equivalent keys, fail as an invalid manifest before manager selection, so a later npm declaration cannot conceal Yarn or a workspace. Its process ownership, deadline, termination, and result semantics match the Composer step.

The pnpm step reuses that same verified Vite+ supervisor and `vp update --no-save` argv. It accepts only a root pnpm project. Vite+ 0.3.0 selects pnpm from `packageManager`, `devEngines.packageManager`, or `pnpm-lock.yaml`. Vite+ defaults a project with no manager signal to pnpm, so a `package.json` without `pnpm-lock.yaml` is incomplete for this adapter rather than a successful skip. `.pnpmfile.cjs` and `pnpmfile.cjs` are pnpm family signals; they do not replace the supported lockfile.

`--no-save` is also a pnpm option: it updates the lockfile and installed regular and development packages within declared constraints, and it does not rewrite ranges in `package.json`. The action never substitutes raw pnpm for Vite+, never passes `--latest` or `-D`/`-P`, and refuses Yarn, workspaces, `pnpm-workspace.yaml`, and conflicting managers before mutation. Roots that contain only npm or only Bun remain absent for this adapter.

The bun step reuses that supervisor with a verified Bun pass-through. It accepts only a root Bun project that already has a text `bun.lock`. Vite+ 0.3.0 selects bun from `packageManager`, `devEngines.packageManager`, `bun.lock`, `bun.lockb`, or `bunfig.toml`. Binary-only `bun.lockb` fails with `dependencies.unsupported_format` before mutation. A bun declaration or `bunfig.toml` without `bun.lock` is incomplete. When both Bun locks exist, the text lock is required.

Bun's `--no-save` does not write a lockfile. The verified argv is `vp update --no-save -- --lockfile-only --save-text-lockfile`. That keeps declared ranges unchanged, writes an updated text `bun.lock`, and includes regular and development dependencies within those ranges. It does not pass `--latest` and never substitutes `/usr/bin/bun`. Yarn, workspaces, and conflicting managers fail before mutation. Roots that contain only npm or only pnpm remain absent for this adapter.

The Yarn step is a refusal adapter, not a Vite+ or Yarn updater. It inspects Classic `yarn.lock` files, modern metadata locks, `.yarnrc.yml`, `yarn.config.cjs`, and Yarn `packageManager` or `devEngines` declarations. Classic and modern roots fail with `dependencies.unsupported_format` before any `vp` or `yarn` command. The probe never maps those families onto `vp update`, raw Yarn, or `--latest`. Constraint-rewriting Yarn delegation is rejected rather than applied. Roots without Yarn signals remain absent for this adapter.

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
