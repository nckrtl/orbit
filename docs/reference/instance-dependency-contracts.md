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
