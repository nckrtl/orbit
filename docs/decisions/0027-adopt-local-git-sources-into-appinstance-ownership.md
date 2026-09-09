# ADR 0027: Adopt local Git sources into AppInstance ownership

In the context of operators who already have usable Git checkouts and worktrees on app-dev Nodes, facing duplicate clones and a separate externally owned registration lifecycle, we decided for explicit adoption into the normal AppInstance lifecycle and against observation-only registration or a separate unregister command, to make creation, registration, and removal consistent, accepting relocation, Git repair, and cascade-removal complexity.

## Status

Accepted on 2026-09-05. Extends [ADR 0008](0008-typed-app-dev-node-storage-settings.md), [ADR 0009](0009-clustered-app-instance-routing.md), [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md), [ADR 0025](0025-stabilize-the-default-appinstance-identity.md), and [ADR 0026](0026-identify-each-app-by-one-repository.md). Supersedes [ADR 0018](0018-register-caller-local-development-worktrees.md) in full.

## Context

ADR 0018 limits registration to a linked worktree, keeps that source externally owned, and introduces unregister so Orbit cannot delete it. The confirmed product lifecycle instead treats registration as an explicit ownership transfer for either an existing checkout or worktree. One removal boundary must delete every AppInstance source safely without deleting shared Git state that the AppInstance does not own.

## Decision

- `instance:new` owns creation of a new AppInstance checkout.
- `instance:register` owns adoption of an existing caller-local Git checkout or worktree into an AppInstance.
- An active AppInstance owns one source directory with source layout `checkout` or `worktree`.
- A checkout AppInstance owns its independent Git repository directory.
- A worktree AppInstance owns its working directory and linked-worktree administration entry.
- A worktree AppInstance must not own its common Git repository or local branch.
- Orbit must move an adopted source into the AppInstance's managed placement before activation when it is not already there.
- Orbit must repair every retained linked worktree after moving its common checkout.
- Orbit may adopt all linked worktrees with their checkout when the operator explicitly requests the complete set.
- Orbit must preflight the complete requested adoption set before moving any source.
- Orbit must infer App, default branch, AppInstance, and web-root values only from unambiguous verified source evidence.
- Orbit must request unresolved values interactively and must refuse non-interactive registration when required values remain unresolved.
- Orbit may create the missing App during registration when the operator confirms the inferred identity and unresolved values.
- Orbit must retain a valid newly created App when AppInstance registration remains incomplete after App creation.
- Orbit must keep relocation and activation resumable with the source recoverable at one verified path.
- `instance:remove` owns removal of every AppInstance layout and its Orbit-owned runtime and Route projections.
- Orbit must not expose a separate unregister lifecycle.
- Forced AppInstance removal may delete dirty or unpublished source and may cascade through Orbit-owned linked-worktree AppInstances.
- Forced AppInstance removal must not bypass path, ownership, repository-identity, symlink, or unregistered-worktree safety boundaries.
- Worktree removal must retain its local branch in the common repository.
- Orbit must not delete a remote Git branch during AppInstance removal.

## Rejected alternatives

- Keep registered worktrees externally owned with a separate unregister command: rejected because registration would not enter the normal AppInstance ownership and removal lifecycle.
- Register only linked worktrees: rejected because an existing independent checkout is equally usable AppInstance source.
- Leave adopted source at an arbitrary path: rejected because that prevents Orbit from applying one bounded managed-placement and deletion policy.
- Delete a worktree branch with its directory: rejected because the branch belongs to the common repository rather than to the AppInstance working directory.

## Consequences

- Operators can create a new checkout or adopt an existing checkout or worktree through one AppInstance model.
- Registration becomes a destructive ownership transfer that moves source and permits its deletion through AppInstance removal.
- Moving a common checkout temporarily requires repair of linked-worktree administration before those worktrees are usable again.
- When adoption crosses filesystems, Orbit needs a durable staged relocation instead of one atomic rename.
- A linked worktree whose common repository is unavailable cannot pass normal source-identity verification.
- Removing a checkout can require an explicit cascade through every Orbit-owned linked-worktree AppInstance and must refuse when an unregistered worktree remains.
- Existing `managed_clone` state needs migration to `checkout`, while any persisted `registered_worktree` state needs explicit operator reconciliation before ownership changes.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0008](0008-typed-app-dev-node-storage-settings.md), [ADR 0009](0009-clustered-app-instance-routing.md), [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md), [ADR 0025](0025-stabilize-the-default-appinstance-identity.md), and [ADR 0026](0026-identify-each-app-by-one-repository.md); supersedes [ADR 0018](0018-register-caller-local-development-worktrees.md) in full
- Detail: docs/domains/applications.md
- Verify: `bin/test`
