# AppInstance removal

This page tells an operator how Orbit removes an AppInstance, what `--force` changes for development source, and how an interrupted removal resumes. [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md) owns source-removal safety, [ADR 0028](../decisions/0028-require-one-route-per-active-appinstance.md) owns the coordinated Route boundary, and [ADR 0041](../decisions/0041-delete-an-empty-route-during-appinstance-removal.md) owns final-target Route deletion.

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
| Route target clear | Remove the AppInstance target, keep a shared production Route serving its remaining targets, or delete a final-target Route and release its hostname. |
| Source finalization | Delete the exact owned source and record the matching outcome. |
| Runtime cleanup | Remove the AppInstance runtime artifacts after Route traffic stops reaching that source. |
| Row deletion | Delete the completed AppInstance row. |

Source finalization never starts before the Route stops forwarding requests to that source. A shared production Route keeps its identity and serves its remaining targets. For every final target, the Gateway deletes the Route and releases its hostname before source finalization. During coordinated normal or forced development removal only, HTTPS GET returns `503 Service Unavailable` in the brief interval after target clearing and before Route deletion propagates. The response has `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store`, and the exact body `Orbit Route unavailable\n`; it never contacts the former target. Orbit does not define that exact response for production removal, and it does not retain a targetless Route in either environment.

A forced checkout cascade finalizes linked worktrees and their administration entries before it finalizes the common checkout. It never expands the recorded set when a later inspection finds another path or worktree.

## Resume an interrupted removal

An accepted removal keeps every unfinished member `removing`. Orbit does not restore an unfinished member to `active`, including when Route work fails before target clearing.

The API, PHP SDK, CLI human output, CLI JSON output, and activity use one bounded removal-progress shape. DELETE returns it as response data, list and show include nullable `removal` data while the AppInstance remains, and a failure after acceptance includes it under `error.details.removal`.

| Progress value | Meaning |
| --- | --- |
| `operation_id`, `id`, `name` | The immutable removal and originally requested AppInstance identity. |
| `force` | Whether the accepted request permits dirty or unpublished source deletion. |
| `status` | `removing`, `failed`, or `completed`. |
| `current_step` | The first unfinished `source_preparation`, `route_target_clear`, `source_finalization`, `runtime_cleanup`, or `row_deletion` step; null only when completed. |
| `total`, `completed`, `remaining` | Fixed-set member counts; completed counts row deletions and remaining equals total minus completed. |
| `failed_step`, `error_code` | Null unless status is failed; otherwise the bounded failed step and error code. |

Repeating the same removal request resumes the first unfinished step. Before deleting more source, the Gateway revalidates every unfinished member against the recorded inventory. It refuses a changed force value, replacement directory, changed repository identity, changed cascade, unsafe path, or request for a member owned by another removal. A retry after final-target Route deletion continues cleanup without recreating the Route or reclaiming its hostname.

Durable finalization evidence binds the source identity to its outcome. A retry accepts an absent source only when matching completion evidence proves that the same removal finalized it. An absent, ambiguous, or mismatched source remains a refusal.

## Limits

AppInstance removal does not change App source settings, migrate a default source, adopt a local source, delete remote branches, delete a worktree branch, remove a common repository for one worktree, or reconcile unrelated Route changes. It retains production application content that the operator deployed.
