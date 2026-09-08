# AppInstance removal

This page tells an operator how Orbit removes one development checkout, what `--force` changes, and how an interrupted removal resumes. [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md) owns source-removal safety, [ADR 0028](../decisions/0028-require-one-route-per-active-appinstance.md) owns the coordinated Route boundary, and [ADR 0041](../decisions/0041-delete-an-empty-route-during-appinstance-removal.md) owns final-target Route deletion.

## Choose normal or forced removal

Use normal removal when the development checkout is clean and its current commit is published:

```text
orbit instance:remove <id>
```

Use forced removal only when you intend to lose dirty or unpublished work:

```text
orbit instance:remove <id> --force
```

The two modes differ only at the dirty-or-unpublished source check.

| Mode | Source behavior |
| --- | --- |
| Normal | Refuses dirty source or a HEAD that no current advertised origin branch or tag contains. |
| Forced | May delete dirty or unpublished source after every other safety check passes. |

Forced removal does not waive source-layout, repository-identity, ownership, path, containment, symlink, overlap, or linked-worktree inventory checks. Normal preflight reads current advertised origin refs into a temporary object store outside the requested repository. It does not fetch into, prune, refresh the index of, or otherwise change the requested repository. The containing origin ref does not need to match the local branch name.

Forced removal validates the configured origin identity locally and does not require origin reachability. Orbit does not delete remote branches. Removing a worktree in a supported removal flow must retain its local branch and common repository. Removing an independent checkout deletes that checkout's owned repository directory.

## Preflight the complete removal

The Gateway validates the source and its sole active Route before it changes an AppInstance, Route, runtime, Git repository, directory, or database row. For development source, it holds the Node source-operation lock continuously through inspection, Route preflight, and removal acceptance. Source preparation and each retry revalidation acquire the same Node lock.

Preflight compares the recorded checkout with its source layout, App repository identity, Node ownership, canonical path, allowed root, symlink-free parent chain, physical directory identity, Git directory, branch, starting commit ancestry, and linked-worktree inventory. It also compares the source path with other Orbit-managed source paths.

Orbit accepts removal of one independent development checkout. The Gateway refuses a checkout with linked worktrees before mutation, including when `--force` is present. It also refuses a worktree AppInstance and every production AppInstance before mutation. An AppInstance that still needs manual source migration returns `instance.migration_required` before removal preflight can accept it.

## Complete an accepted removal

After preflight succeeds, the Gateway records one immutable removal member and marks it `removing`. It then completes five ordered steps.

| Step | Result |
| --- | --- |
| `source_preparation` | Record verified source, physical directory identity, and finalization identity without deleting it. |
| `route_target_clear` | Stop the Route from forwarding to the checkout, remove its managed projections, delete the final-target Route, and release its hostname. |
| `source_finalization` | Delete the exact recorded checkout and store matching completion evidence. |
| `runtime_cleanup` | Remove the AppInstance runtime artifacts after Route traffic stops reaching the checkout. |
| `row_deletion` | Delete the AppInstance row and mark the operation completed in one database transaction. |

Source finalization never starts before Route deletion releases the hostname. The Gateway removes managed workload and Router Caddy, certificate, Domain Name System (DNS), and development Route firewall projections before it deletes the Route. A projection failure keeps the AppInstance `removing` and keeps the unfinished checkpoint available for retry.

During normal and forced removal, an HTTPS GET in the brief interval after target clearing and before Route deletion propagates returns `503 Service Unavailable`, `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store`, and the exact body `Orbit Route unavailable\n`. That request does not contact the former target. A completed operation retains no targetless Route.

## Read progress and resume

The API, PHP SDK, CLI human output, CLI JSON output, and activity use one bounded removal-progress shape. DELETE returns it as response data, list and show include nullable `removal` data while the AppInstance remains, and a failure after acceptance includes it under `error.details.removal`.

| Progress value | Meaning |
| --- | --- |
| `operation_id`, `id`, `name` | The immutable operation and requested AppInstance identity. |
| `force` | Whether the accepted request permits dirty or unpublished source deletion. |
| `status` | `removing`, `failed`, or `completed`. |
| `current_step` | The first unfinished step, or null after completion. |
| `total`, `completed`, `remaining` | Fixed-set member counts; total is one, completed counts row deletion, and remaining equals total minus completed. |
| `failed_step`, `error_code` | Null outside failure; on failure, the unfinished step and a bounded safe error token or null. |

Repeating the same removal request resumes the first unfinished step. The Gateway refuses a changed force value or a request owned by another operation. Before further source deletion, it revalidates the recorded checkout under the same Node lock. A retry after Route deletion continues without recreating the Route or reclaiming its hostname. If final completion cannot commit, the same transaction restores the final member checkpoint and AppInstance row, so the identical public request remains model-bindable.

Durable finalization evidence binds the recorded physical source identity to its outcome. Orbit resumes a matching operation-owned quarantine before a receipt, after a receipt, or after deletion before the database checkpoint. It accepts an absent source only when matching completion evidence proves that the same removal finalized it. An unrelated absence, ambiguous source, mismatched evidence, or replacement source remains a refusal.

Conflicting removal, AppInstance creation at the same managed placement, and Route retargeting cannot revive an unfinished member.

## Limits

AppInstance removal does not activate worktree or linked-worktree cascade removal, remove a production AppInstance, change App source settings, migrate a default source, adopt local source, delete branches, or reconcile unrelated Route changes. It retains production application content.
