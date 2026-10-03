---
title: "Dependency contracts"
description: "The graph values, lockfile reader rules, collection limits, stored tables, and response shapes behind Instance dependency inventory."
covers:
  - apps/gateway/app/Domain/Instances/Dependencies/**
  - apps/gateway/app/Actions/Instances/Dependencies/**
  - apps/gateway/app/Infrastructure/Instances/{*DependencyUpdate*Program,DependencyFilesProgram,DependencyUpdateSupervisorHost}.php
  - apps/gateway/app/Models/{DependencyPackage,InstanceDependencyObservation,InstanceDependencyResolution,InstanceDependencyEdge,InstanceDependencyScanAttempt}.php
  - apps/gateway/database/migrations/2026_09_15_200000_create_instance_dependency_inventory.php
  - packages/php-sdk/src/Support/{DependencyInventoryDecoder,InstanceResolutionDecoder}.php
  - packages/php-sdk/src/Requests/Instances/{Show,Scan,Update}InstanceDependenciesRequest.php
  - packages/php-sdk/src/Requests/Instances/{ResolveInstanceRequest,ResolveDirectoryInstanceRequest}.php
---

# Dependency contracts

This page holds the details behind [Instance dependencies](/reference/instance-dependencies): how the readers build a graph, how collection reads files, what the Gateway stores, and what the API returns. The code lives in `App\Domain\Instances\Dependencies` and `App\Actions\Instances\Dependencies`.

## Graph values

Each reader returns one `DependencyGraph` per ecosystem.

| Value | Contract |
| --- | --- |
| `DependencyIdentity` | Ecosystem, `composer` or `npm`, and the canonical package name. |
| `DependencyResolution` | A graph-local ID, the identity, the locked version as an opaque string, separate `regular` and `development` flags, and an optional source reference and integrity hash. |
| `DependencyRequirement` | Source (null for the root), target (null when unresolved), declared name and constraint, kind (`dependency` or `peer`), scope, and `optional`. |
| `DependencyGraph` | The resolutions and requirements. IDs are unique, and every endpoint points into the same graph. |

The resolution ID keeps installation paths and peer contexts apart, so one version can appear twice. Reachability comes from actual paths from the root. A package's own development requirements install nothing. A null target never claims that a package is present.

## Reader rules

Every reader parses data in memory. None reads files, runs a package manager or script, or contacts a registry. Errors carry a stable code and no input text. Versions stay opaque, and URLs and credentials never reach the result.

This table sums up each reader.

| Reader | Reads | Resolution ID | Mismatch with `package.json` | Invalid data |
| --- | --- | --- | --- | --- |
| Composer | `packages` and `packages-dev` | Package name | Not checked | `dependencies.invalid_composer_input` |
| npm | `lockfileVersion` 2 and 3, `packages` map | Installation path | `dependencies.stale_npm_lockfile` | `dependencies.invalid_npm_input` |
| pnpm | `lockfileVersion` 9.0, importer `.` | Snapshot key | `dependencies.stale_pnpm_lockfile` | `dependencies.invalid_pnpm_input` |
| Bun | Text `bun.lock`, `lockfileVersion` 1 or 2, workspace `""` | Package path | `dependencies.stale_bun_lockfile` | `dependencies.invalid_bun_input` |

A root `workspaces` layout, several importers, path repositories, and local links return `dependencies.unsupported_layout`. The npm reader ignores `workspaces` metadata on transitive package records. An unsupported lockfile version, Bun version 3, and binary `bun.lockb` return `dependencies.unsupported_format`.

### Composer

Root `require` and `require-dev` set the two reachability paths. Platform requirements such as `php` and `ext-*` stay unresolved edges. A unique locked provider or replacement satisfies a virtual requirement. Aliases point at the locked resolution. Repository URLs are dropped, and source revisions stay.

### npm

The caller picks `npm-shrinkwrap.json` before `package-lock.json`. Lookup walks from the nearest package location to the root, as npm does. An alias edge keeps its declared name. Its range must match the locked version under npm's loose semver rules. A dist-tag alias needs a registry tarball. Bundled dependency names without a lock entry stay optional edges with no target. An empty `packages` map is a valid empty graph when the manifest declares nothing.

### pnpm

Snapshot keys keep peer contexts apart. A remote tarball key becomes `pnpm:sha256:<digest>`, so no URL is kept. The importer must match the manifest's specifiers and scopes.

### Bun

Comments and trailing commas are allowed. Git and remote tarball references become `bun:sha256:<digest>`. `optionalPeers` marks optional peer edges, and an empty specifier means any version.

### Local packages

A `file:` or `link:` dependency returns `dependencies.unsupported_layout`. Support would need a second read of files at lock-supplied paths, with the same path safety, in the same source observation.

## Package manager selection

The JavaScript manager comes from `packageManager`, then `devEngines.packageManager`, then lockfiles and configuration files. Configuration files are only presence signals. They are never evaluated.

| Case | Result |
| --- | --- |
| Two manager families | `dependencies.ambiguous_manager` |
| Any Yarn signal | `dependencies.unsupported_format` |
| `workspaces` or `pnpm-workspace.yaml` | `dependencies.unsupported_layout` |
| Duplicate keys in `package.json` | `dependencies.invalid_manifest` |
| A manager without its lockfile | `dependencies.incomplete_source` |

No signal at all means pnpm, which still needs `pnpm-lock.yaml`.

## Collection

`CollectInstanceDependencyFilesAction` reads the recorded checkout, or `current` of a production Instance, over SSH. A fixed Python program runs as the Node's user in development and as the production user in production. For a Laravel Instance, it reads manifests, lockfiles, and manager signals only in the [application directory](/reference/projects#application-directory) derived from the effective web root. Root `apps/site/public` selects `apps/site` inside the checkout or selected release, not the repository-root manifests. Non-Laravel Instances keep repository-root collection. Collection never recursively discovers or combines dependency trees.

| Limit | Value |
| --- | --- |
| A manifest or configuration file | 1 MiB |
| A lockfile | 8 MiB |
| All files | 32 MiB |
| SSH run | 30 seconds, 48 MiB of output |

Symlinks, non-regular files, and paths that are not canonical fail. Production collection validates and pins the selected release before opening its application directory. The program reads twice and compares the repository or release identity, application directory, file metadata, and hashes. A difference returns `dependencies.source_changed`.

`ScanInstanceDependenciesAction` holds the Instance operation lock, and for development the Node source lock, from collection through publication. It checks the Instance again inside each publication transaction. Its source snapshot retains the effective root and Laravel classification, so directory selection does not fall back to the repository root when the Instance is copied into a snapshot.

Development updates use the same directory for manager-presence inspection, source-safety checks, package commands, and the final scan. Collection receipt validation accepts only the derived directory within the recorded checkout or selected release, not an arbitrary caller-supplied path. Git identity and CLI directory-to-Instance resolution still use the whole repository. Reader rules, package-manager refusals, and collection limits stay unchanged; a nested Laravel app does not enable workspace or multi-importer support.

## Stored inventory

The Gateway stores the inventory in five tables.

| Table | Contents |
| --- | --- |
| `dependency_packages` | One row per ecosystem and name, shared by all Instances. |
| `instance_dependency_observations` | The last successful observation per Instance and ecosystem: time, presence, project root, source reference, format, and file hashes. No row means unknown. |
| `instance_dependency_resolutions` | The resolutions of one observation. |
| `instance_dependency_edges` | The requirements of one observation. |
| `instance_dependency_scan_attempts` | Every attempt with its time and error code, null on success. |

`PublishInstanceDependencyScanAction` replaces one ecosystem's observation, resolutions, edges, and attempt in one transaction. A failure records only the attempt and keeps the stored observation. A database failure records `dependencies.persistence_failed`. An Instance in removal gets `dependencies.instance_unavailable`. Removing an Instance deletes its rows but keeps shared package rows.

## Responses

A scan or read returns `data` with `instance_id`, `succeeded`, `composer`, and `javascript`. `succeeded` is null until both ecosystems have attempts.

Each ecosystem has `ecosystem`, `state`, `succeeded`, `attempted_at`, `error_code`, and `snapshot`. The state is `unknown` before the first success, and stale after a later failure. A snapshot has `observed_at`, a `source` with `project_root`, `reference`, `file_hashes`, and `format`. `project_root` is the absolute directory whose manifests were read; for Laravel root `apps/site/public`, it ends in `/apps/site`. The production `reference` still identifies the selected release. The snapshot also has a `graph`. A null graph means the ecosystem is absent. An empty graph means a project with no packages.

An update returns `instance_id`, `succeeded`, `error_code`, `may_have_mutated`, a `composer` and a `javascript` step, and `inventory`. A step has `ecosystem`, `status` (`succeeded`, `absent`, `failed`, or `not_run`), `may_have_mutated`, and `error_code`. A refused update has both steps `not_run` and no inventory.

| Update code | Cause |
| --- | --- |
| `dependencies.production_update_forbidden` | The target is a production Instance. |
| `dependencies.unsafe_source` | The recorded identity or layout is not safe to update. |
| `dependencies.unsupported_delegation` | Vite+ is missing or not a verified version. |
| `dependencies.update_failed` | A package command failed. |
| `dependencies.update_cancelled` | The command was cancelled after it started. |
| `dependencies.update_timeout` | The command passed its 600-second deadline. |

The SDK rejects a whole response that breaks the shape. It accepts at most 32 MiB of JSON, 50,000 resolutions and 200,000 requirements per ecosystem, 64 file hashes, and 16 KiB per text field.

## Target resolution

`GET /api/v1/instances/resolve?domain=DOMAIN` finds the one Instance behind an active Route domain. It returns `domain`, `instance_id`, `project_id`, `node_id`, and `environment`. A missing or inaccessible domain returns `dependencies.target_not_found`. A Route with more than one target returns `dependencies.target_ambiguous`. The SDK accepts at most 4,096 bytes of response.

`GET /api/v1/instances/resolve-directory?directory=PATH` finds the Instance whose checkout or production home holds a canonical path on the caller's Node. It returns `instance_id`, `project_id`, `node_id`, and `environment`. It never looks at other Nodes. Nested matches return `dependencies.target_ambiguous`; the resolver never picks the longest prefix.

Both return `dependencies.instance_unavailable` for an Instance that is not active or is in removal. Later operations check access and state again.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Opaque versions

A branch reference or alias is not a release version. Readers keep the locked value as it is and never solve constraints, so the graph shows what the lockfile says.

### Hashed source references

Tarball and Git URLs can carry credentials or tokens. A digest keeps two sources apart without storing the URL.

### Fail instead of an empty graph

An unsupported format that returned an empty graph would look like a project with no dependencies. So every unsupported case fails and keeps the last observation.
