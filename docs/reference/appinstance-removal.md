---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, and how an interrupted removal resumes."
---

# Instance removal

This page tells an operator how Orbit removes one Instance, what `--force` changes for development source, how owned Processes and Schedules are removed, and how an interrupted removal resumes. [ADR 0027](/decisions/0027-adopt-local-git-sources-into-appinstance-ownership) owns development source-removal safety, [ADR 0031](/decisions/0031-clone-initial-production-source-during-provisioning) owns retained production content, [ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit) owns the production serving layout, [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user) owns dedicated production runtime cleanup, [ADR 0028](/decisions/0028-require-one-route-per-active-appinstance) owns the coordinated Route boundary, [ADR 0041](/decisions/0041-delete-an-empty-route-during-appinstance-removal) owns final-target Route deletion, and [ADR 0038](/decisions/0038-cascade-appinstance-removal-through-processes-and-schedules) owns child cleanup.

Registration transfers an adopted checkout or worktree into Instance ownership. Orbit removes that source through `instance:destroy`; it exposes no separate unregister command or lifecycle.

Completed transfer history is retained when an Instance is removed. Its `app_instance_id` is cleared so the Instance row can be deleted. A reserved, in-progress, or failed transfer remains attached and removal returns `instance.transfer_incomplete`; this preserves the transfer's recovery state and prevents silent discard. [ADR 0111](/decisions/0111-retain-transfer-history-through-instance-removal) owns this contract.

Removal requires a default-No confirmation naming the Instance and effect, or explicit `--yes`. JSON and noninteractive calls require `--yes`. The separate `--force` option permits the source overrides below and never supplies consent. Decline, cancellation and end of input stop before mutation.

Development removal runs the Project teardown list after preflight accepts the source and before it deletes the Route, source, or Instance record. A teardown command that exits non-zero stops removal and leaves the Instance in place. Production removal does not run that list. [Instance setup and teardown](/reference/instance-setup) owns the commands and the failure code.

Human output shows waiting feedback, the verified outcome and any remaining removal checkpoints. JSON keeps the bounded removal-progress contract.

## Choose normal or forced removal

Use normal removal when the development source is clean and its current commit is published:

```text
orbit instance:destroy <id>
```

Use forced removal only when you intend to lose dirty or unpublished work:

```text
orbit instance:destroy <id> --force
```

The two modes differ when development source is dirty or unpublished, or when a checkout has registered linked worktrees. Production removal retains application content, so `--force` does not change its source behavior.

| Mode | Source behavior |
| --- | --- |
| Normal | Refuses dirty source, a HEAD that no current advertised origin branch or tag contains, or a checkout with registered linked worktrees. |
| Forced | May delete dirty or unpublished source and may accept a complete registered checkout set after every other safety check passes. |

Forced removal waives only the dirty-source refusal, the publication refusal, and the registered linked-worktree refusal. It does not waive source-layout, repository-identity, ownership, path, containment, symlink, overlap, or linked-worktree inventory checks. Neither mode requires `HEAD` to descend from the recorded starting commit. The Gateway accepts a checkout whose `HEAD` was reset or rebased below that commit when its directory, Git directory, origin, branch, and worktree inventory still match the record. Normal removal still requires that `HEAD` to be clean and published.

Normal preflight reads current advertised origin refs into a temporary object store outside the requested repository. It does not fetch into, prune, refresh the index of, or otherwise change the requested repository. The containing origin ref does not need to match the local branch name.

Forced removal validates the configured origin identity locally and does not require origin reachability. Orbit does not delete remote branches. Removing a worktree retains its local branch, common repository, and usable siblings. Removing an independent checkout deletes that checkout's owned repository directory.

## Preflight the complete removal

The Gateway checks source ownership and the active Route before changing records, processes, schedules, runtime, Git, or files. A failed source check changes nothing. A development Instance must be its Route's only target. Production instances of the same Project can share an explicit Cluster Route across distinct active app-prod Nodes. Development removal holds the Node's source lock through inspection, Route checks, and acceptance. Source preparation and retries use the same lock.

A `monorepo` or `laravel-package` Instance has a Route only when an operator set one, as [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type) allows. Removal deletes that Route like any other. An Instance without a Route skips the Route checks and records the `none` Route outcome.

Removal accepts an `active` Instance. It also accepts a `source_resolved` Instance that no Route targets, such as a task workspace, so completing a task group removes its checkout. Removal records whether the Instance was active when it was accepted. A checkout that never became active has no PHP pool, site, certificate, or metrics target, so runtime cleanup removes only its Processes and Schedules. Other states return `instance.remove_refused`. A `laravel-app` Instance without exactly one Route returns `instance.remove_refused`. An Instance with more than one Route returns the same code.

Preflight compares the recorded checkout with its source layout, Project repository identity, Node ownership, canonical path, allowed root, symlink-free parent chain, physical directory identity, Git directory, branch, and linked-worktree inventory. It also compares the source path with other Orbit-managed source paths.

The Gateway answers a refused development source preflight with the code that names the failed check, in normal and forced mode alike.

| Code | Refused check |
| --- | --- |
| `instance.source_path_mismatch` | The recorded path is not the exact Orbit-owned directory: it lies outside the allowed root, a path segment is a symlink, or the directory is absent. |
| `instance.source_ownership_mismatch` | The directory or its parent is not owned by the managed Node account. |
| `instance.source_layout_mismatch` | The Git directory does not match the recorded checkout or worktree layout. |
| `instance.source_origin_mismatch` | The configured origin does not identify the Project repository. |
| `instance.source_branch_mismatch` | The checked-out branch differs from the recorded branch. |
| `instance.source_worktrees_mismatch` | The Git worktree inventory does not include the recorded checkout. |
| `instance.remove_refused` | Normal removal found dirty or unpublished source, or another removal rule refused the request; the message names the rule. |
| `instance.force_failed` | Forced inspection failed for a reason that no named check covers. |

### Development source sets

Orbit accepts one development worktree in normal or forced mode. Normal checkout removal refuses registered linked worktrees and tells the operator to use `--force`. Forced checkout removal discovers every linked source from Git inventory and accepts the checkout only when each source is an active Orbit-owned Instance on the same Node, with the same Project repository identity and safe Route and source ownership. An unregistered linked worktree refuses both modes before mutation.

Forced checkout removal sorts worktrees by checkout path and puts the common checkout last. The Gateway inspects every member against the same linked-worktree inventory before one transaction records the ordered set and marks every member `removing`. After acceptance, the Gateway refuses a new Process or Schedule for every Instance in that fixed deletion set. Force never waives source identity, ownership, path, repository, linked-worktree, overlap, migration, Process or Schedule artifact, or Route checks.

### Production target

Orbit accepts one production Instance after it verifies the complete Route target set. An Instance that still needs manual source migration returns `instance.migration_required` before removal preflight can accept it.

## Complete an accepted removal

After preflight succeeds, the Gateway records one immutable member for a worktree removal or the complete immutable ordered set for a forced checkout cascade. It then completes five ordered steps for each member before advancing to the next member.

| Step | Result |
| --- | --- |
| `source_preparation` | Record verified development source identity or the production content-retention boundary without deleting content. |
| `route_target_clear` | Stop the Route from forwarding to the Instance, republish an ordered surviving production set or delete the final-target Route after managed projection cleanup, and release a deleted Route's domain. |
| `source_finalization` | Delete the exact recorded development checkout or retain production application content, store matching completion evidence, and remove the Project slug grouping directory when that directory is empty. |
| `runtime_cleanup` | Remove every owned Process and Schedule with its exact artifacts and record, then remove Instance runtime artifacts after Route traffic stops. |
| `row_deletion` | Delete the member's Instance row. The final member's transaction also marks the operation completed. |

Development source finalization never starts before that member's Route deletion releases the domain. The Gateway removes managed workload and Router Caddy, certificate, Domain Name System (DNS), and development Route firewall projections before it deletes the Route. A projection failure keeps the Instance `removing` and keeps the unfinished checkpoint available for retry. The common checkout stays usable while Orbit removes its worktree members and their Git administration entries. Orbit deletes the common checkout only after every accepted worktree completes.

After that deletion leaves a Project slug grouping directory empty, the Gateway removes that directory. It leaves a grouping directory that still has entries, the apps root, and unrelated paths unchanged.

Production source finalization starts after Route target cleanup completes. Removing one member of a shared production Route keeps the Route active, compacts its ordered target positions, republishes the complete survivor set, and removes the departing workload's Caddy, PHP FastCGI Process Manager (PHP-FPM), and private certificate projections.

Process cleanup includes running, stopped, failed, and removing Process records for both systemd and Docker. Orbit checks every service unit, container, and recovery artifact for the exact Process owner before it stops or removes that artifact. An absent owned artifact is already complete. An ownership conflict stops the cascade and never authorizes Orbit to adopt or delete the conflicting artifact.

Schedule cleanup includes active, failed, and removing records. Orbit disables each owned timer and removes its exact timer, oneshot service, protected script, and Schedule record without waiting for an active command. A running command may continue or fail while source and artifacts disappear. Its late completion callback cannot recreate the Schedule, restore an artifact, bypass removal state, or delay the Instance operation.

Dedicated PHP cleanup disables and removes only the Instance's recorded service, generated identity files, pool, and socket after traffic stops. It leaves every other service PID and cache unchanged, including Gateway PHP, development PHP, another dedicated production runtime, and an existing shared service. Removing the final member clears the workload and Router Caddy, certificate, and Domain Name System (DNS) projections before it deletes the Route.

During normal and forced development removal, an HTTPS GET in the brief interval after target clearing and before Route deletion propagates returns `503 Service Unavailable`, `Content-Type: text/plain; charset=utf-8`, `Cache-Control: no-store`, and the exact body `Orbit Route unavailable\n`. That request does not contact the former target. A shared production Route continues to serve only its surviving targets. A completed operation retains no targetless Route.

## Read progress and resume

The API, PHP SDK, CLI human output, CLI JSON output, and activity use one bounded removal-progress shape. DELETE returns it as response data, list and show include nullable `removal` data while the Instance remains, and a failure after acceptance includes it under `error.details.removal`.

| Progress value | Meaning |
| --- | --- |
| `operation_id`, `id`, `name` | The immutable operation and requested Instance identity. |
| `force` | Whether the accepted request permits dirty or unpublished source deletion. |
| `status` | `removing`, `failed`, or `completed`. |
| `current_step` | The first unfinished step, or null after completion. |
| `total`, `completed`, `remaining` | Immutable fixed-set member counts; completed counts row deletion, and remaining equals total minus completed. |
| `failed_step`, `error_code` | Null outside failure; on failure, the unfinished step and a bounded safe error token or null. |

Repeating the same removal request resumes the first unfinished member and step. The Gateway refuses a changed force value or a request owned by another operation. Before further source deletion, it revalidates every unfinished source under the same Node lock. A retry after Route deletion continues without recreating the Route or reclaiming its domain.

Process and Schedule cleanup retry only records and exact-owned runtime artifacts that remain unfinished. A cleanup failure keeps the Instance and its removal progress, reports no completed removal, and permits the same request to continue after the Node or artifact conflict is repaired. Retry leaves Node-owned Schedules, other Instances' children, and unrecognized artifacts unchanged. If final cascade completion cannot commit, the same transaction restores the final member checkpoint and requested checkout row, so the identical public request remains model-bindable.

A production retry after shared target removal preserves the survivor set without restoring the departing target. If final completion cannot commit, the same transaction restores the final member checkpoint and Instance row, so the identical public request remains model-bindable.

Durable finalization evidence binds each recorded physical source identity to its outcome. Orbit resumes a matching operation-owned quarantine before a receipt, after a receipt, or after deletion before the database checkpoint. It accepts a smaller linked-worktree inventory only when completed member checkpoints or authenticated finalizer evidence explain every absent source. A new, replaced, unregistered, differently owned, unrelated absent, ambiguous, or mismatched source returns `instance.removal_conflict` before another member's Route or source changes. Retry never appends a source to the accepted set.

Production finalization records deterministic retained-content evidence and does not inspect, rewrite, or delete operator-owned release, environment, or database bytes. It removes the owned `current` serving link after Route traffic stops, then retains `releases/`, `.env`, an existing `database.sqlite`, and `/etc/orbit/php-fpm/<production-user>/local.conf`. A retry after partial serving-link or dedicated runtime cleanup verifies the recorded association, completes only its remaining generated projections, and does not recreate a removed link, service, or socket. Conflicting removal, Instance creation at the same managed placement, and Route retargeting cannot revive an unfinished member. The [production release-layout reference](/reference/deployments) describes the retained paths.

## Limits

Instance removal does not change Project source settings, migrate a default source, adopt local source, delete branches, clean retained releases automatically, create production target pools, select a balancing policy, or reconcile unrelated Route changes. It leaves other Instances' Processes and Schedules, unrelated services and containers, Node-owned state, unrecognized runtime artifacts, and the apps root unchanged. It also leaves a grouping directory that still contains entries. It retains production application content, persistent environment and SQLite files, the dedicated production user, and local PHP-FPM tuning.
