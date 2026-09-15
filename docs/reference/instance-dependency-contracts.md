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

Publication reloads and locks the instance inside the transaction. An instance marked for removal, including a retained removal member after failure, receives `dependencies.instance_unavailable` and keeps its previous snapshot. A deleted instance receives that error without recreating observations or attempts. Database foreign keys also prevent orphan publication. Source collection and coordination with updates, deployment, rollback, and source changes remain the collector's responsibility; these actions do not read files or acquire remote operation locks.

`ReadInstanceDependencyScanAction` reconstructs the latest stored outcome and graph in a read transaction. No observation or attempt returns null, meaning never scanned. A first failed attempt returns unknown inventory. A later failure returns stale inventory with the original observation time and source, including an observation of verified absence. Latest means the last recorded attempt ID, even when attempt timestamps match. Callers must serialize collection for an instance so an older collection cannot finish after a newer one. Successful publication for one ecosystem is independent of failure in the other.

## Implementation boundaries

These values do not sanitize raw input or authorize publication. Parsers and collectors validate supported formats and layouts, remove credentials before constructing provenance, and reject incomplete graphs. Stable error codes carry failure information; raw process output and source contents do not belong in results.

The publisher owns atomic replacement, database removal checks, and retention of previous snapshots. Scan orchestration owns source validation and operation locking. Update orchestration owns preflight checks and Composer-then-Vite+ ordering. Focused value and database tests cover the contracts and publication. Parser, transport, and execution tasks must verify their own behavior, including the feature's required Incus checks.
