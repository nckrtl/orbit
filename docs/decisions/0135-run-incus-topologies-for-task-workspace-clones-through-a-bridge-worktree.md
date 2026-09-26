---
title: "ADR 0135: Run Incus topologies for task workspace clones through a bridge worktree"
sidebarTitle: "0135 Run topologies for clones through a bridge worktree"
description: "Proposed. The primary checkout that holds the topology snapshot registers itself for its origin. In an independent clone, bin/e2e-topology mirrors the clone into a linked bridge worktree of that primary and runs the command there. Removing the task workspace also removes that group's bridge."
---

# ADR 0135: Run Incus topologies for task workspace clones through a bridge worktree

The primary checkout that holds the promoted topology snapshot registers itself for its repository's origin. When `bin/e2e-topology` runs in an independent clone of that origin, such as a managed task workspace, it mirrors the clone into a linked worktree of the registered primary and runs the same command there. The harness itself is unchanged: every topology still belongs to a linked worktree of the primary checkout.

## Status

Proposed.

This extends [ADR 0131](/decisions/0131-seed-task-workspace-clones-from-the-registered-main-cache-store), which lets clones find the main cache store, to the Incus harness. It keeps [ADR 0005](/decisions/0005-rolling-incus-development-topology) and the topology snapshot ownership in [Topology snapshot](/reference/topology-snapshot).

## Context

The Gateway provisions each task workspace as an independent clone. An implementer that must prove a feature on Incus runs `bin/e2e-topology` in that clone. The harness finds host-wide state, the promoted topology snapshot and its locks, in the first entry of `git worktree list`. In a clone that is the clone itself, which holds no snapshot, so `acquire` fails with "No promoted topology snapshot generation is available". Running the primary's harness against the clone also fails, because acquisition, synchronization, proof, equivalence, and closeout require the worktree to share the primary's Git common directory, and the mount evidence requires a linked worktree's `.git` pointer file.

Task group 58 stopped on this: `acquire` refused to lease a topology for its Incus proof subtask.

## Decision

- A checkout that is not a linked worktree and holds `.e2e/topology-snapshot/promoted.json` is a snapshot primary. It registers itself at `$XDG_STATE_HOME/orbit/e2e-primary-checkouts/{key}`, a symbolic link to the checkout, where `$XDG_STATE_HOME` defaults to `~/.local/state`. `{key}` is the origin key from ADR 0131: the SHA-256 of the origin's host and path.
- Registration happens when `bin/e2e-topology` or `bin/e2e-topology-snapshot` runs in a snapshot primary or in one of its linked worktrees, and explicitly through `bin/e2e-topology-snapshot register`. In a linked worktree, both register the repository's primary checkout. The first live primary keeps the registration. Another replaces it only when the registered checkout is gone or no longer holds a promoted generation, or when `register --force` runs.
- `bin/e2e-topology` bridges when it runs in a checkout that is not a linked worktree, and a live primary other than itself is registered for its origin. It never bridges in a linked worktree or in the primary. `ORBIT_E2E_BRIDGE=0` turns bridging off.
- The bridge is the linked worktree `<worktree root>/<clone directory>-e2e` of the primary, on branch `<clone branch>-e2e`. The worktree root is the primary's `orbit.worktreeRoot`, as for `bin/worktree-create`. Before each command the bridge takes the clone's HEAD, then copies the clone's modified and untracked files and removes its deleted tracked files. It also mirrors each `vendor/` directory the clone has, so acquisition finds the autoloaders. It never touches other ignored files in the bridge, such as `.e2e/`, `.env`, or Gateway storage, which the harness and the guests write into the mount.
- The command runs from the bridge's own `bin/e2e-topology`, with every clone path in its arguments replaced by the bridge path, and `--worktree` set to the bridge.
- `bin/e2e-topology-snapshot` never bridges. Snapshot operations belong to the primary checkout.
- When the Gateway removes a task workspace, on complete, on cancel, and on the sweep that retries removal, it also removes that group's bridge from the registered primary checkout. The bridge path is `<worktree root>/task-{id}-e2e` and the branch is `task-{id}-e2e`. Both must match. A directory at that path on another branch stays, and a path that is not a worktree of this primary stays. A missing bridge is success. Removal deletes the worktree, including a registration whose directory is already gone, then deletes branch `task-{id}-e2e` when no worktree has it checked out, and deletes `refs/orbit/e2e-bridge/task-{id}`. It does not release an Incus topology.

## Rejected alternatives

- Teach the harness to accept clones directly: rejected because it changes about ten identity checks, the guest mount evidence, and the Git object assumptions of proof and closeout, all for a case that a linked worktree already satisfies.
- Provision task workspaces as linked worktrees of the primary checkout: rejected for the same reason as in ADR 0131; the Gateway provisions Instances over SSH and does not know the operator's primary checkout.
- Mirror the clone with `rsync --delete`: rejected because it would delete the Gateway environment, database, and logs that the harness and the guests keep in the mounted worktree.
- Find the primary through the main cache store registration: rejected because it would make topologies depend on whether test caches were ever published.
- Leave the bridge for `bin/worktree-remove`: rejected because completed groups on beast still had bridge branches and `refs/orbit/e2e-bridge/task-{id}` after the workspace clone was gone.
- Delete every worktree whose name ends in `-e2e`: rejected because that path can be a user's worktree.

## Consequences

- An implementer in a task workspace runs `bin/e2e-topology acquire ISSUE .` and the other topology commands without knowing about the primary checkout.
- The mounted source is the bridge, not the clone. A file that the harness or a guest writes into the mount appears in the bridge, and the clone does not see it.
- Each command copies the clone's changes first, so the topology always sees the clone's current work. A change made directly in the bridge's tracked files is overwritten by the next command.
- The issue ID must still match the branch. A task workspace on branch `task-58` uses issue `TASK-58`; the bridge branch `task-58-e2e` matches it.
- Completing or cancelling a group removes its bridge from the primary checkout. A bridge whose directory was already deleted still loses its branch and `refs/orbit/e2e-bridge/task-{id}` ref.
- Removal does not release an Incus topology the bridge still holds. Release that topology before the group ends, or the guests can outlive the worktree.
- `bin/worktree-remove` still removes a bridge like any other worktree once its topologies are released.

## Affects

- Components: apps/e2e, apps/gateway, apps/docs
- ADRs: extends [ADR 0131](/decisions/0131-seed-task-workspace-clones-from-the-registered-main-cache-store); keeps [ADR 0005](/decisions/0005-rolling-incus-development-topology); removal amends this record alongside [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone)
- Detail: [Incus topologies](/reference/incus-topologies#task-workspace-clones), [Topology snapshot](/reference/topology-snapshot#commands), [Tasks](/reference/tasks#complete-and-cleanup)
- Verify: `apps/e2e/tests/Fixtures/e2e-clone-bridge-tests.py` through `CloneBridgeTest`; `acquire` from a task workspace clone on beast; Gateway `CompleteTaskGroupActionTest` bridge removal
