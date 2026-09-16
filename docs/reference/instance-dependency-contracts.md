---
title: "Gateway dependency contracts"
description: "Immutable graph and operation values for App instance dependency inventory."
---

# Gateway dependency contracts

The contracts in `App\Domain\AppInstances\Dependencies` define graph and operation results without persistence, transport, or package execution. [ADR 0078](/decisions/0078-index-appinstance-dependencies) owns the architecture. The [instance dependency guide](/reference/instance-dependencies) defines supported root formats and operator behavior.

## Graph values

Parsers return these values to preserve identities, distinct resolutions, and requirement paths.

| Value | Contract |
| --- | --- |
| `DependencyIdentity` | Ecosystem (`composer` or `npm`) and canonical package name. Parsers supply canonical names; aliases remain requirement names. |
| `DependencyResolution` | Graph-local opaque ID, package identity, opaque version, independent regular and development reachability, and optional credential-free source reference and integrity. |
| `DependencyRequirement` | Root or resolution source, nullable resolved target, declared name and constraint, dependency or peer kind, declaration scope, and optional flag. |
| `DependencyGraph` | One ecosystem's resolutions and requirements. Resolution IDs are nonempty and unique. Every non-null endpoint refers to a resolution in that graph. |

Resolution IDs preserve installation paths and peer contexts, including two resolutions with the same package version. A null requirement source means direct; a resolution source means a transitive relationship. Multiple edges preserve overlapping paths. A null target does not assert package presence.

Parsers compute regular and development reachability from actual root paths. An edge's declaration scope alone cannot establish all target scopes. A package can have both direct and transitive paths, both regular and development reachability, and peer relationships.

## Composer reader

`ReadComposerDependencyGraphAction` accepts the contents of root `composer.json` and `composer.lock` and returns a Composer graph. It reads JSON data only, without files, Composer execution, project autoloading, plugins, scripts, or network requests. Source collection, file absence, hashes, and publication belong to the caller.

The reader includes `packages` and `packages-dev`. Root `require` and `require-dev` establish separate reachability paths; locked package `require` edges carry those paths through cycles. A dependency's own `require-dev` does not install its development tools. Package names are canonical lowercase names. Versions remain opaque, including branches. Inline alias constraints remain intact, and lock aliases refer to the underlying locked resolution rather than creating invented versions. Source or distribution revisions are retained without repository URLs.

Platform requirements such as `php`, `ext-*`, `lib-*`, and Composer runtime APIs remain unresolved edges, never catalog packages. A unique locked provider or replacement can satisfy a virtual requirement. Requirements on the root package's canonical `name`, or on its explicit `provide` and `replace` names, have null targets because the root is not a package resolution. The root does not need a redundant `provide` declaration for its own name. Missing package targets, ambiguous providers, duplicate locked names, malformed records, and packages without a root path fail explicitly. Path repositories and local path distributions are unsupported layouts. Repository-disable entries are accepted in both named form (`{"packagist.org": false}`) and anonymous form (`[{"packagist.org": false}]`).

The reader throws `DependencyParseException` with a stable error code and no input text. Invalid JSON or graph records use `dependencies.invalid_composer_input`; unsupported layouts use `dependencies.unsupported_layout`. It rejects unsafe retained values rather than exposing credentials. It does not resolve version constraints or contact registries to repair input. Collection must still ensure the manifest and lockfile belong to one stable source observation.

## npm reader

`ReadNpmDependencyGraphAction` accepts root `package.json` and npm lockfile contents as JSON strings. It supports `lockfileVersion` 2 and 3 through the `packages` map. The caller selects `npm-shrinkwrap.json` before `package-lock.json` when both exist. The reader does not read files, run npm or package scripts, or contact registries. Version 2's legacy `dependencies` tree is not a second inventory source.

Installation locations identify resolutions, including nested and scoped packages and repeated versions. Requirement lookup follows the nearest package location and then its ancestors. Peer lookup uses the containing installation context. Aliases retain the declared name and constraint while their target uses the locked package identity. Names preserve mixed-case and numeric npm identities.

Root regular and development paths establish independent reachability through dependencies and resolved peers; lockfile `dev` flags do not replace those paths. A package's own development requirements do not introduce installed dependencies. Optional dependencies override regular declarations of the same name, while development paths remain separate. Missing optional and peer targets remain null; missing required dependency targets fail.

When the root has no dependencies, npm can omit the root record and write `packages: {}`. Both supported versions accept this as a present empty graph after manifest validation. An omitted root still fails when the manifest declares requirements or the packages map contains records. An explicit malformed root record also fails.

The root lock record must agree with the manifest's dependency declarations. Workspace declarations, local links, and package locations outside the root installation tree fail with `dependencies.unsupported_layout`. Invalid JSON, duplicate keys, malformed records, conflicting identities, missing parents, and unreachable package records fail with `dependencies.invalid_npm_input`. Unsupported lockfile versions use `dependencies.unsupported_format`. Exceptions contain no input text. Locked versions remain opaque, integrity is retained when valid, and resolved download URLs and executable metadata are omitted. The reader does not solve version constraints; the collector must supply matching files from one stable source observation.

## pnpm reader

`ReadPnpmDependencyGraphAction` accepts root `package.json` JSON and `pnpm-lock.yaml` text. It reads pnpm lockfile version `9.0` with exactly one importer, `.`. The reader parses data in memory through Symfony YAML, which is a Gateway runtime dependency. It does not read files, execute package code, or contact registries.

Registry snapshot keys remain resolution IDs. Their peer suffixes remain intact, so the same package version can have separate peer contexts. Package metadata supplies identities, integrity, and peer constraints; snapshots supply resolved dependency links. Aliases keep the declared name while targeting the actual package identity. Root regular, optional, and development declarations establish separate reachability paths through cycles and resolved peers. Optional declarations override duplicate regular declarations. pnpm records each root package once, using optional, regular, then development precedence. The graph retains a development path when the manifest also declares that package for development.

The importer must agree with the manifest's dependency specifiers and scopes. Peer declarations remain distinct edges, including null targets when unresolved. Every recorded dependency link must identify an existing snapshot and package record. Unreachable records, duplicate keys, malformed YAML or JSON, and inconsistent records fail with `dependencies.invalid_pnpm_input`. Unsupported lockfile versions use `dependencies.unsupported_format`; multiple importers, workspaces, and local links use `dependencies.unsupported_layout`. Errors contain no source text. Download URLs and executable metadata are omitted from results. The collector must still select the lockfile and supply stable matching source contents.

Remote HTTP and HTTPS tarball locators use the package metadata's `version`; the URL is not a package version. The reader matches raw locators only in memory. Before returning the graph, it replaces every URL-bearing resolution ID, edge endpoint, and requirement reference with `pnpm:sha256:` plus a SHA-256 digest of that exact value. Different sources and peer contexts therefore stay distinct without retaining URLs, credentials, or query values. Registry locators and ordinary version constraints stay unchanged. A remote locator without a valid metadata version fails explicitly.

## Bun reader

`ReadBunDependencyGraphAction` accepts root `package.json` JSON and `bun.lock` JSONC text. It supports `lockfileVersion` 1 with exactly the root workspace, named `""`. Comments and trailing commas are data syntax; the reader never evaluates JavaScript, runs Bun, reads project files, or contacts registries. The caller selects the text lockfile and supplies stable matching source contents.

Package paths remain resolution IDs, including nested and scoped paths. Tuple descriptors supply actual package names, so aliases retain their declared edge names without changing catalog identities. Dependency and peer lookup starts below the requesting package and walks through containing package paths to the root, following Bun's text lock behavior. Root regular and development declarations establish independent reachability through cycles. Optional dependencies override duplicate regular declarations. Package-local development declarations do not install development tools.

When a name appears only in development and optional declarations, Bun saves the optional declaration in the root record. The reader validates that normalization and retains the manifest's development path to the optional target.

Bun's `optionalPeers` list marks optional peer edges. Missing optional dependencies and peers remain null targets without invented versions. Missing mandatory dependencies, unreachable records, missing parents, conflicting root declarations, malformed tuples, duplicate keys, and invalid retained fields fail with `dependencies.invalid_bun_input`. Unsupported versions and binary locks use `dependencies.unsupported_format`; extra workspaces and local package links use `dependencies.unsupported_layout`. Errors contain only the stable code.

Registry tuples preserve exact versions and valid integrity. Download URLs and executable metadata are omitted. Remote tarball and Git tuples do not contain a package release version: their opaque locked reference represents the resolution. Source-bearing references are replaced by deterministic `bun:sha256:` values before output, preserving distinctions without retaining URLs or credentials. A hexadecimal Git revision is retained separately when available. No version constraint is solved during parsing.

## Unsupported Yarn inputs

Yarn Classic and modern Yarn are excluded from dependency inventory and updates. There is no Yarn reader. The [scope amendment](/reference/instance-dependencies#yarn-scope-amendment) supersedes the earlier Yarn parser contracts. Composer, npm, and pnpm readers retain their existing contracts; Bun text uses the reader described above.

The npm and pnpm readers reject Yarn lock contents with a parse error instead of returning an empty graph. These pure readers do not select package managers. The collector must reject Yarn manager or lockfile selection explicitly, without trying another manager's lockfile. A failed scan uses the existing failure contract to retain stale inventory, or unknown state when no successful observation exists. Update preflight must reject Yarn before any package mutation, including Composer work in a mixed project.

Collector, updater, output, and discovery refusal checks belong to their assigned tasks. Parser rejection alone does not verify those operations. The common graph, error, and publication contracts remain unchanged.

## Observation values

Scan results use these values to distinguish observed source from a failed collection attempt.

| Value | Contract |
| --- | --- |
| `DependencySource` | Project root, optional source revision or release reference, parser format, and SHA-256 hashes of root input files. A null file hash means verified absence. |
| `DependencySnapshot` | Ecosystem, source, observation time, and graph. A null graph means verified ecosystem absence. An empty graph means a present project with no resolved packages. |
| `DependencyScanResult` | Attempt time and error code, separate from the last successful snapshot. A failure retains that snapshot as stale; a first failure leaves inventory unknown. |

Collectors include every selection and inventory input in the source hashes. They compare source identity and hashes before publication. A failed scan retains the previous observation time and source; its attempt time describes the failure. An absent ecosystem also becomes stale when its next scan fails.

## Operation values

Operation callers use these values to report package work and inventory refresh separately.

| Value | Contract |
| --- | --- |
| `DependencyUpdateStepResult` | Per-ecosystem succeeded, absent, failed, or not-run outcome, error code, and whether mutation may have occurred. |
| `InstanceDependencyScanResult` | Both named ecosystem outcomes for one instance. Any failed ecosystem makes the instance result fail. |
| `InstanceDependencyUpdateResult` | Composer and JavaScript step outcomes, post-update inventory for that instance, and a separate preflight or operation error code. |

Update success requires completed package steps and a successful post-update inventory refresh. Successful commands can leave versions unchanged. A failed command can have changed files. An absent ecosystem completes without mutation; a step not run does not count as completed. A preflight refusal has no package mutation or post-update inventory.

## Persisted inventory

Gateway models store the catalog, each instance's latest successful ecosystem observation, its graph, and separate scan attempts.

| Table | Ownership and identity |
| --- | --- |
| `dependency_packages` | One canonical name per ecosystem. Package versions do not belong to this shared catalog. |
| `app_instance_dependency_observations` | One successful observation per instance and ecosystem, with observation time, verified presence or absence, project root, source reference, format, and input hashes. No row means unknown inventory. |
| `app_instance_dependency_resolutions` | One opaque locator per observation, referencing a shared package. Versions, peer contexts, independent regular and development reachability, source references, and integrity remain distinct. |
| `app_instance_dependency_edges` | Requirements within one observation. Null source means the root; null target means unresolved. Names, constraints, kind, scope, and optional status distinguish edges. |
| `app_instance_dependency_scan_attempts` | Instance, ecosystem, attempt time, and nullable stable error code. Null error means success. Attempts do not own or replace successful observations. |

Foreign keys prevent resolutions from using another ecosystem's catalog and prevent edges from crossing observations. Duplicate catalog identities, observation identities, resolution locators, and identical edges are rejected, including edges with null endpoints. Deleting an instance cascades its observations, resolutions, edges, and attempts. Shared package identities remain; deleting a package still in use is refused.

Provenance has named fields rather than arbitrary metadata or process output. Source references accept revisions, not URLs or credentials. Input hashes contain root filenames and SHA-256 values, or null for verified absence. Models reject malformed provenance hashes and non-code attempt errors. Parsers and collectors must still remove credentials from every opaque lockfile field before persistence.

Attempt error codes preserve the scan result's code, including dotted codes such as `dependencies.source_changed`. A code contains at most 128 characters. Each dot-separated segment starts with a lowercase letter and contains lowercase letters, digits, or underscores. Null means success; empty codes, URLs, credentials, and process text are rejected.

`PublishInstanceDependencyScanAction` accepts one instance ID and one validated ecosystem result. It replaces that ecosystem's observation, resolutions, edges, and successful attempt in one database transaction. Replacement clears previous usage, including for verified absence or a present empty graph. Shared package identities remain available to other instances. Each invocation records an attempt; repeated scans do not accumulate usage.

A failed or incomplete result records its stable code and retains the stored snapshot, ignoring any previous snapshot supplied by the caller. If a database write or provenance validation fails during replacement, the transaction rolls back before a separate attempt records `dependencies.persistence_failed`. If that failure record cannot be saved, the action throws; it does not claim a recorded outcome. Error results do not expose database exceptions or source text.

Publication reloads and locks the instance inside the transaction. An instance marked for removal, including a retained removal member after failure, receives `dependencies.instance_unavailable` and keeps its previous snapshot. A deleted instance receives that error without recreating observations or attempts. Database foreign keys also prevent orphan publication. The coordinated scan owns source collection and locking against updates, deployment, rollback, and removal. The publisher itself does not read files or acquire operation locks.

`ReadInstanceDependencyScanAction` reconstructs the latest stored outcome and graph in a read transaction. No observation or attempt returns null, meaning never scanned. A first failed attempt returns unknown inventory. A later failure returns stale inventory with the original observation time and source, including an observation of verified absence. Latest means the last recorded attempt ID, even when attempt timestamps match. Callers must serialize collection for an instance so an older collection cannot finish after a newer one. Successful publication for one ecosystem is independent of failure in the other.

## Implementation boundaries

These values do not sanitize raw input or authorize publication. Parsers and collectors validate supported formats and layouts, remove credentials before constructing provenance, and reject incomplete graphs. Stable error codes carry failure information; raw process output and source contents do not belong in results.

The publisher owns atomic replacement, database removal checks, and retention of previous snapshots. Scan orchestration owns source validation and operation locking. The Composer update executor owns bounded development Composer mutation and absent-ecosystem skips. Update orchestration owns preflight checks and Composer-then-Vite+ ordering. Focused value and database tests cover the contracts and publication. Parser, transport, and execution tasks must verify their own behavior, including the feature's required Incus checks.

## Managed source collection

`CollectInstanceDependencyFilesAction` reads the recorded development checkout or the production home's selected `current` release through pinned Gateway SSH. It ignores the public web root and installed dependency directories. Production without a selected release fails with `dependencies.source_unavailable`. The caller must authorize the instance and coordinate collection with managed source operations before publishing.

The collector runs one fixed Python reader as the Node user for development or the recorded runtime user for production. It reads only root manifests, supported lockfiles, and package-manager signals. It never runs Git, package managers, project scripts, or registry requests. File contents remain transient parser input; callers must not log or serialize them into API responses.

Each manifest or configuration file is limited to 1 MiB, each lockfile to 8 MiB, and all contents to 32 MiB. SSH has a 30-second deadline and a 48 MiB output limit. Nonregular files, unreadable files, symlinks, and noncanonical source paths fail explicitly. The reader compares directory identity, release selection, file metadata, and content hashes across two passes. A changed source produces `dependencies.source_changed`; no mixed collection is returned. This check does not replace operation locking or a final source check before publication.

### Select parser input

`SelectDependencyInputAction` returns one ecosystem's selected manifest, lockfile, and source hashes, or throws a stable error. Both files absent means absent; either file missing means incomplete. Composer and JavaScript selection are independent, so callers can preserve a successful ecosystem when the other fails. File errors remain distinct from verified absence.

JavaScript selection examines `packageManager`, then `devEngines.packageManager`, then lockfile and configuration signals. Conflicting families and ambiguous locks fail instead of guessing. npm shrinkwrap wins over package-lock within npm; Bun text wins over a coexisting binary lock. Yarn selection and binary-only Bun fail as unsupported. Workspace declarations and `pnpm-workspace.yaml` fail as unsupported layouts. Configuration files are presence signals only and are never evaluated. No manager signal defaults to pnpm, whose supported lockfile is still required for a JavaScript project.

The collection identity covers every inspected file and the selected directory. Stored provenance includes hashes for nonhidden root inputs; hidden manager signals remain covered by the transient identity. The collector leaves parser format unset and does not claim a checkout Git revision. The coordinated scan preserves that nullable format; the readers return graphs without a format label. Production provenance includes the selected release name.

## Coordinated instance scans

`ScanInstanceDependenciesAction` reloads the recorded instance under the existing instance operation lock. It holds that lock through collection, parsing, source reinspection, and publication. Development scans also hold the Node development source lock, after the instance lock. Deploy, rollback, environment changes, and removal already use this coordination. A dependency updater must use the same locks and can call the scan within its owned operation.

The action selects and parses Composer and JavaScript independently. Each successful ecosystem replaces its graph atomically; a failed ecosystem retains its last observation as stale. Verified absence clears usage only after both source inspections succeed. The action compares the complete collection identity before publication and checks the instance source fields again inside each publication transaction. A changed release, checkout, Node placement, migration state, or removal cannot publish the earlier graph.

An unavailable instance fails before collection. Instance lock contention returns `dependencies.operation_busy` with retained observations and does not append an attempt outside the lock. Other collection and parse failures record their stable codes. Source changes between inspections fail both ecosystems with `dependencies.source_changed`. These checks observe source at a point in time; they do not prevent external edits after the final inspection. Target authorization and HTTP or CLI adapters remain caller responsibilities.

## Composer update executor

`UpdateComposerDependenciesAction` runs a bounded Composer update for one development App instance root. It returns a Composer `DependencyUpdateStepResult`. Shared update preflight, instance locks, JavaScript mutation, and post-update scanning belong to the later coordinator.

The action refuses production before SSH with `dependencies.production_update_forbidden` and `mayHaveMutated=false`. It uses the recorded checkout path and the owning Node user, never the public web root. Invalid environment, source layout, migration-required state, or identity fails with `dependencies.unsafe_source` and does not start Composer.

A fixed Python probe inspects `composer.json` and `composer.lock` as regular, non-symlink root files. Verified absence of both files returns `absent` without Composer. One file without the other returns `dependencies.incomplete_source`. Unreadable or unsafe source uses the same stable collection codes. The probe is limited to 30 seconds and 64 KiB.

When both files are present, the action runs a fixed supervisor: `/usr/bin/setsid --wait /usr/bin/bash` with a code-owned program, the recorded root, and a 600-second deadline. That program starts `/usr/bin/composer --working-dir ROOT update --no-interaction --no-ansi --no-progress --no-audit` and owns Composer plus its child processes. Recorded roots may contain spaces; the supervisor keeps the root as one argument. It does not pass `--no-dev`, `--latest`, package names, or constraint rewrites. Regular and `require-dev` packages therefore update together within the declared ranges.

The supervisor owns Composer and its children and enforces a 600-second remote deadline. It discards process text. Local SSH is limited to 610 seconds and 8 MiB. On cancellation or timeout, the supervisor terminates the owned process group, including when the SSH client disconnects, and waits for those processes to exit. Local SSH then sends SIGTERM to its process group, waits up to two seconds while any member of that group remains, and SIGKILLs the group even if the original process has already exited before the step returns.

A successful Composer exit returns `succeeded` with `mayHaveMutated=true`. That flag allows mutation; it does not prove files changed, and it does not claim rollback. Nonzero exit, truncation, or unexpected transport failure returns `dependencies.update_failed` with `mayHaveMutated=true`. Cancellation and timeout before Composer starts keep `mayHaveMutated=false`; after Composer starts they return `dependencies.update_cancelled` or `dependencies.update_timeout` with `mayHaveMutated=true`. Results never claim that source was restored.

## Single-instance HTTP API

Both endpoints under `/api/v1` require an active WireGuard peer with access to the instance's owning Node. Gateway authority remains fleet-wide. Targets use numeric instance IDs; neither endpoint accepts a source path, package manager, command, or fleet selector.

| Method and path | Route name | Result |
| --- | --- | --- |
| `GET /instances/{instance}/dependencies` | `instance:dependencies:show` | Read stored inventory without SSH or a new attempt. |
| `POST /instances/{instance}/dependencies/scan` | `instance:dependencies:scan` | Scan both ecosystems synchronously and return their outcomes. |

POST requires an empty JSON object. GET accepts no body, and neither endpoint accepts query parameters. Invalid input returns `422 validation.failed`. Missing targets return `404 http.404`; unknown peers and denied access return the existing `403` errors. Inactive, removing, migration-required, or retained-removal targets return `409 dependencies.instance_unavailable` without inventory. Lock contention returns `409 dependencies.operation_busy`. The boundary reloads and checks authorization and availability under the instance operation lock, including after collection.

### Inventory response

A completed read or scan returns HTTP 200 with `data` and `meta.request_id`. Scan failures remain explicit in the typed result: HTTP 200 does not mean both ecosystems refreshed. `data` contains `instance_id`, nullable `succeeded`, and named `composer` and `javascript` results. Overall success is null until both ecosystems have attempts; otherwise it requires both to succeed. JavaScript identities use ecosystem `npm` for npm, pnpm, and Bun.

Each ecosystem contains `ecosystem`, `state`, nullable `succeeded`, `attempted_at`, `error_code`, and `snapshot`. Never scanned means unknown with null attempt, success, error, and snapshot. First failure remains unknown but includes a failed attempt. A failed scan with a prior snapshot is stale and retains its original observation time. Verified absence has a snapshot with null graph; a dependency-free project has a graph with empty arrays. Mixed success returns each ecosystem's actual outcome and overall false.

Snapshots contain `observed_at`, source provenance, and nullable graph. Source contains `project_root`, `reference`, `file_hashes`, and nullable `format`. Graphs contain `resolutions` and `requirements`. Resolutions expose graph-local `id`, package `ecosystem` and `name`, opaque `version`, independent `regular` and `development` flags, `source_reference`, and `integrity`. Requirements expose nullable `from` and `to`, declared `name`, `constraint`, `kind`, `scope`, and `optional`. Root and unresolved endpoints remain null. Timestamps use UTC RFC 3339.

Only one instance's latest observations and attempts are returned, with no attempt history or installed-file audit. Input collection retains its documented byte and time bounds. Raw source contents, configuration values, download URLs, credentials, and process output are excluded. API access does not update packages, select another instance, or deploy source.

## PHP SDK transport

`ShowInstanceDependenciesRequest` reads stored inventory by numeric instance ID. `ScanInstanceDependenciesRequest` sends an empty JSON object to scan that instance. Both return `InstanceDependencyInventoryResponse`, with typed ecosystem, snapshot, source, resolution, and requirement values. The SDK preserves null attempts, verified absence, empty graphs, partial results, and stale observations. Callers must inspect `succeeded`, including HTTP 200 responses.

The SDK rejects an entire invalid response instead of dropping graph records or supplying success defaults. It checks envelope shape, request correlation, instance identity, field types, state consistency, unique resolution IDs, and graph endpoints. It accepts at most 32 MiB of response JSON, 50,000 resolutions and 200,000 requirements per ecosystem, 64 source hashes, and 16 KiB per text field. Oversized or malformed results raise a safe `GatewayApiException`; valid error-envelope codes, redacted details, and request IDs use the shared transport boundary.

These are transport limits, not package-selection policy. The SDK does not parse lockfiles, resolve domains, run packages, render CLI output, or retry scans. Focused Saloon fixtures verify this contract; CLI and integrated discovery checks exercise real transport.

## Full-domain target resolution

`GET /api/v1/instances/resolve?domain=FULL_DOMAIN` resolves one dependency target without collecting source or changing inventory. The request has no body and accepts only `domain`. It normalizes case and surrounding whitespace using the Route domain rules, requires a full dotted domain, and rejects URLs, ports, paths and wildcard selectors.

The Gateway reads authoritative Routes and their complete target pools in one database transaction. Every target must be accessible through its owning Node. Missing domains and inaccessible matches return the same `dependencies.target_not_found` error without candidate identities. More than one authoritative Route or target returns `dependencies.target_ambiguous` only after access checks. Empty pools fail; the resolver never filters a pool into an apparent unique match. Pending, failed and retiring Routes do not select instances.

A unique target must be active, outside removal and free of a required source migration. Otherwise resolution returns `dependencies.instance_unavailable`. Success contains only `domain`, `instance_id`, `app_id`, `node_id` and `environment`, with the usual request ID. This result describes current selection; later operations must authorize and check the instance again.

### SDK and CLI selection

The SDK exposes `ResolveAppInstanceRequest` and `ResolvedAppInstanceResponse`. It accepts at most 4096 bytes of response JSON and requires the exact success envelope and ownership fields. Duplicate JSON keys, including escaped equivalents, are rejected. The body must contain a valid request ID that agrees with a valid response-header request ID when present. Malformed responses raise a safe `GatewayApiException` without returning a target or raw response data.

The CLI's `DependencyInstanceSelector::resolveDomain` provides a shared SDK call for subsequent scan and update commands. It preserves the domain input and structured errors, without local Route filtering or directory detection. No dependency command or package mutation is added by this resolver.

## Current-directory selection

`GET /api/v1/instances/resolve-directory?directory=CANONICAL_PATH` selects one registered instance on the authenticated caller Node. The request has no body and accepts no Node override. The CLI resolves the current directory with `realpath` before sending it. Symlinked directories therefore use their physical location. A missing or unreadable local directory fails before HTTP.

The Gateway compares the canonical absolute path with registered development checkout roots and production homes. A root and its descendants match at directory boundaries. Matching paths on other Nodes never qualify, even with fleet access. Multiple matching roots, including nested roots, return `dependencies.target_ambiguous`; the resolver never chooses the longest prefix. A missing or inaccessible match returns `dependencies.target_not_found`. Unavailable or migrating instances cannot be selected.

The response contains only `instance_id`, `app_id`, `node_id`, and `environment`, with standard request correlation. The SDK validates the bounded raw envelope, duplicate keys, ownership types, and correlation. Directory selection asserts the caller's canonical path; it does not inspect remote files or prove the process working directory. Later operations recheck authorization, lifecycle, and source state.

`DependencyInstanceSelector::select` gives an explicit App domain precedence over local discovery. All-instance selection returns no local target and does not inspect the working directory. Combining an explicit App with all-instance selection fails before HTTP.

## All-instance CLI scanning

`instance:dependencies:scan --all` uses `ListAppInstancesRequest` to capture the authorized instance set, then `ScanInstanceDependenciesRequest` for each captured ID in that list order. The CLI does not filter, reorder, or invent targets locally. Listing authorization remains the Gateway list contract; each scan still rechecks access and lifecycle.

### Listing envelope

The fleet scan requires a complete listing envelope before it captures targets. The envelope must be a JSON object. `data` must be present as a JSON array, not a JSON object. A missing `data` field, `null`, a scalar, `{}`, or a numeric-key object raises a safe `GatewayApiException` and starts no scans.

Each array member must be a JSON object with a positive `id`, `app_id`, `node_id`, non-empty `name`, and supported `environment`. Empty objects, array members, missing or invalid identity fields, and a malformed row after a valid row fail the entire listing. The command does not skip invalid rows or default a missing collection to an empty authorized set. A valid empty `data` array is a successful zero-target result.

A listing transport or structured error envelope returns the ordinary CLI `error` envelope and starts no scans. Each scan outcome is recorded even when that instance fails. Later captured targets still run. HTTP 200 inventory results keep the typed composer and JavaScript graphs. Authorization, unavailability, timeout, and other request failures become a per-instance error without a successful empty graph. SIGINT or SIGTERM during a scan stops remaining targets, preserves attempted outcomes, and marks unattempted IDs as skipped rather than complete.

`--json` returns `{succeeded, summary, instances, request_id}`. `summary` counts attempted, succeeded, failed, and skipped targets. `request_id` is the listing correlation. Instance rows include `instance_id`, `app_id`, `node_id`, `name`, `environment`, and `domain`. The command does not update packages or deploy.
