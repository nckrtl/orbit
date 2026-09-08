# AppInstance removal

This page tells an operator how Orbit removes one AppInstance, what `--force` changes for development source, and how an interrupted removal resumes. [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md) owns development source-removal safety, [ADR 0031](../decisions/0031-clone-initial-production-source-during-provisioning.md) owns retained production content, [ADR 0028](../decisions/0028-require-one-route-per-active-appinstance.md) owns the coordinated Route boundary, and [ADR 0041](../decisions/0041-delete-an-empty-route-during-appinstance-removal.md) owns final-target Route deletion.

## Choose normal or forced removal

Use normal removal when the development source is clean and its current commit is published:

```text
orbit instance:remove <id>
```

Use forced removal only when you intend to lose dirty or unpublished work:

```text
orbit instance:remove <id> --force
```

The two modes differ only when development source is dirty or unpublished. Production removal retains application content, so `--force` does not change its source behavior.

| Mode | Source behavior |
| --- | --- |
| Normal | Refuses dirty source or a HEAD that no current advertised origin branch or tag contains. |
| Forced | May delete dirty or unpublished source after every other safety check passes. |

Forced removal does not waive source-layout, repository-identity, ownership, path, containment, symlink, overlap, or linked-worktree inventory checks. Normal preflight reads current advertised origin refs into a temporary object store outside the requested repository. It does not fetch into, prune, refresh the index of, or otherwise change the requested repository. The containing origin ref does not need to match the local branch name.

Forced removal validates the configured origin identity locally and does not require origin reachability. Orbit does not delete remote branches. Removing a worktree retains its local branch, common repository, and usable siblings. Removing an independent checkout deletes that checkout's owned repository directory.

## Preflight the complete removal

The Gateway validates the source boundary and active Route before it changes an AppInstance, Route, runtime, Git repository, directory, or database row. A development AppInstance must own its singleton Route. A production AppInstance can be one member of an explicit Cluster Route on distinct active app-prod Nodes for the same App. For development source, the Gateway holds the Node source-operation lock continuously through inspection, Route preflight, and removal acceptance. Source preparation and each retry revalidation acquire the same Node lock.

Preflight compares the recorded checkout with its source layout, App repository identity, Node ownership, canonical path, allowed root, symlink-free parent chain, physical directory identity, Git directory, branch, starting commit ancestry, and linked-worktree inventory. It also compares the source path with other Orbit-managed source paths.

### Development source sets

Orbit accepts one development worktree in normal or forced mode. Normal checkout removal refuses registered linked worktrees and tells the operator to use `--force`. Forced checkout removal discovers every linked source from Git inventory and accepts the checkout only when each source is an active Orbit-owned AppInstance on the same Node, with the same App repository identity and safe Route and source ownership. An unregistered linked worktree refuses both modes before mutation.

Forced checkout removal sorts worktrees by checkout path and puts the common checkout last. The Gateway inspects every member against the same linked-worktree inventory before one transaction records the ordered set and marks every member `removing`. Force never waives source identity, ownership, path, repository, linked-worktree, overlap, migration, or Route checks.

### Production target

Orbit accepts one production AppInstance after it verifies the complete Route target set. An AppInstance that still needs manual source migration returns `instance.migration_required` before removal preflight can accept it.

## Complete an accepted removal

After preflight succeeds, the Gateway records one immutable member for a worktree removal or the complete immutable ordered set for a forced checkout cascade. It then completes five ordered steps for each member before advancing to the next member.

| Step | Result |
| --- | --- |
| `source_preparation` | Record verified development source identity or the production content-retention boundary without deleting content. |
| `route_target_clear` | Stop the Route from forwarding to the AppInstance, republish an ordered surviving production set or delete the final-target Route after managed projection cleanup, and release a deleted Route's hostname. |
| `source_finalization` | Delete the exact recorded development checkout or retain production application content, then store matching completion evidence. |
| `runtime_cleanup` | Remove the AppInstance runtime artifacts after Route traffic stops reaching the checkout. |
| `row_deletion` | Delete the member's AppInstance row. The final member's transaction also marks the operation completed. |

Development source finalization never starts before that member's Route deletion releases the hostname. The Gateway removes managed workload and Router Caddy, certificate, Domain Name System (DNS), and development Route firewall projections before it deletes the Route. A projection failure keeps the AppInstance `removing` and keeps the unfinished checkpoint available for retry. The common checkout stays usable while Orbit removes its worktree members and their Git administration entries. Orbit deletes the common checkout only after every accepted worktree completes.

Production source finalization starts after Route target cleanup completes. Removing one member of a shared production Route keeps the Route active, compacts its ordered target positions, republishes the complete survivor set, and removes the departing workload's Caddy, PHP FastCGI Process Manager (PHP-FPM), and private certificate projections. Removing the final member clears the workload and Router Caddy, certificate, and Domain Name System (DNS) projections before it deletes the Route.

During normal and forced development removal, an HTTPS GET in the brief interval after target clearing and before Route deletion propagates returns `503 Service Unavailable`, `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store`, and the exact body `Orbit Route unavailable\n`. That request does not contact the former target. A shared production Route continues to serve only its surviving targets. A completed operation retains no targetless Route.

## Read progress and resume

The API, PHP SDK, CLI human output, CLI JSON output, and activity use one bounded removal-progress shape. DELETE returns it as response data, list and show include nullable `removal` data while the AppInstance remains, and a failure after acceptance includes it under `error.details.removal`.

| Progress value | Meaning |
| --- | --- |
| `operation_id`, `id`, `name` | The immutable operation and requested AppInstance identity. |
| `force` | Whether the accepted request permits dirty or unpublished source deletion. |
| `status` | `removing`, `failed`, or `completed`. |
| `current_step` | The first unfinished step, or null after completion. |
| `total`, `completed`, `remaining` | Immutable fixed-set member counts; completed counts row deletion, and remaining equals total minus completed. |
| `failed_step`, `error_code` | Null outside failure; on failure, the unfinished step and a bounded safe error token or null. |

Repeating the same removal request resumes the first unfinished member and step. The Gateway refuses a changed force value or a request owned by another operation. Before further source deletion, it revalidates every unfinished source under the same Node lock. A retry after Route deletion continues without recreating the Route or reclaiming its hostname. If final cascade completion cannot commit, the same transaction restores the final member checkpoint and requested checkout row, so the identical public request remains model-bindable.

A production retry after shared target removal preserves the survivor set without restoring the departing target. If final completion cannot commit, the same transaction restores the final member checkpoint and AppInstance row, so the identical public request remains model-bindable.

Durable finalization evidence binds each recorded physical source identity to its outcome. Orbit resumes a matching operation-owned quarantine before a receipt, after a receipt, or after deletion before the database checkpoint. It accepts a smaller linked-worktree inventory only when completed member checkpoints or authenticated finalizer evidence explain every absent source. A new, replaced, unregistered, differently owned, unrelated absent, ambiguous, or mismatched source returns `instance.removal_conflict` before another member's Route or source changes. Retry never appends a source to the accepted set.

Production finalization records deterministic retained-content evidence and does not inspect, rewrite, or delete operator-owned application bytes. Conflicting removal, AppInstance creation at the same managed placement, and Route retargeting cannot revive an unfinished member.

## Limits

AppInstance removal does not change App source settings, migrate a default source, adopt local source, delete branches, create production target pools, select a balancing policy, or reconcile unrelated Route changes. It retains production application content and the dedicated production user.
