# AppInstance removal

This page tells an operator when Orbit removes development AppInstance source, what `--force` changes, and how an interrupted removal resumes. [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md) owns source-removal safety, and [ADR 0028](../decisions/0028-require-one-route-per-active-appinstance.md) owns the coordinated Route boundary.

## Choose normal or forced removal

Use normal removal when every source is clean and published:

```text
orbit instance:remove <id>
```

Use forced removal only when you intend to lose dirty or unpublished work or remove a checkout with its complete registered worktree set:

```text
orbit instance:remove <id> --force
```

The two modes differ only at the destructive source boundary.

| Mode | Source behavior |
| --- | --- |
| Normal | Refuses dirty or unpublished source and refuses a checkout while an Orbit-owned linked-worktree AppInstance remains. |
| Forced | May delete dirty or unpublished source and may include every Orbit-owned linked-worktree AppInstance in one fixed cascade. |

Forced removal does not waive source-layout, repository-identity, ownership, path, containment, symlink, overlap, common-repository, or linked-worktree inventory checks. Orbit never deletes a remote branch. Removing a worktree retains its local branch and common repository.

## Preflight the complete deletion set

The Gateway validates the complete requested set before it changes an AppInstance, Route, runtime, Git repository, directory, or database row.

Preflight compares each recorded source with its source layout, App repository identity, Node ownership, canonical path, allowed root, symlink-free parent chain, and Git directory. It also compares every requested source with other Orbit-managed source paths. For a linked worktree, the common repository and worktree administration must be available and consistent.

Removing a checkout inventories every linked worktree. Normal removal refuses an Orbit-owned linked worktree and names `--force`. Forced removal includes every registered linked-worktree AppInstance. Either mode refuses an unregistered linked worktree, an unsafe or overlapping source, or an inventory that cannot identify one complete set.

An AppInstance that still needs manual source migration returns `instance.migration_required` before this preflight can accept removal.

## Complete the accepted removal

After preflight succeeds, the Gateway records one fixed deletion set and marks every member `removing`. It then completes each member in this order.

| Step | Result |
| --- | --- |
| Source preparation | Record the verified source and finalization identity without deleting it. |
| Route target clear | Remove the AppInstance target and converge the retained Route to its unavailable response. |
| Source finalization | Delete the exact owned source and record the matching outcome. |
| Runtime cleanup | Remove AppInstance runtime artifacts while preserving the unavailable Route. |
| Row deletion | Delete the completed AppInstance row. |

Source finalization never starts before the Route stops forwarding requests to that source. The retained Route keeps its ID, hostname, Node-or-Cluster scope, generated-name basis, private DNS record, and trusted HTTPS boundary. It returns the deterministic unavailable response after target clearing and after runtime cleanup.

A forced checkout cascade finalizes linked worktrees and their administration entries before it finalizes the common checkout. It never expands the recorded set when a later inspection finds another path or worktree.

## Resume an interrupted removal

An accepted removal keeps every unfinished member `removing`. Orbit does not restore an unfinished member to `active`, including when Route work fails before target clearing.

The API, PHP SDK, CLI human output, CLI JSON output, and activity report whether removal is forced, the current step, the fixed member count, completed and remaining counts, and a bounded failure code. List and show operations expose the same progress while the AppInstance remains.

Repeating the same removal request resumes the first unfinished step. Before deleting more source, the Gateway revalidates every unfinished member against the recorded inventory. It refuses a changed force value, replacement directory, changed repository identity, changed cascade, unsafe path, or request for a member owned by another removal.

Durable finalization evidence binds the source identity to its outcome. A retry accepts an absent source only when matching completion evidence proves that the same removal finalized it. An absent, ambiguous, or mismatched source remains a refusal.

## Limits

AppInstance removal does not change App source settings, migrate a default source, adopt a local source, delete remote branches, delete a worktree branch, remove a common repository for one worktree, or reconcile unrelated Route changes. It does not remove operator-deployed production placement.
