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

## Implementation boundaries

These values do not sanitize raw input or authorize publication. Parsers and collectors validate supported formats and layouts, remove credentials before constructing provenance, and reject incomplete graphs. Stable error codes carry failure information; raw process output and source contents do not belong in results.

The scan publisher owns atomic replacement, source and lifecycle checks, and retention of previous snapshots. Update orchestration owns preflight checks and Composer-then-Vite+ ordering. Focused value tests cover the contracts. Parser, persistence, transport, and execution tasks must verify their own behavior, including the feature's required Incus checks.
