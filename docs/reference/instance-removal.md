---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, how owned Processes and Schedules go with it, and how an interrupted removal resumes."
covers:
  - apps/gateway/app/Actions/Instances/RemoveInstanceAction.php
  - apps/gateway/app/Domain/Instances/{InstanceRemover,InstanceRemovalStatus,InstanceRemovalStep}.php
  - apps/gateway/app/Domain/Instances/Removal/**
  - apps/gateway/app/Infrastructure/*/RecordedProduction*ContentRetention.php
  - apps/gateway/app/Infrastructure/Instances/{NativeInstanceRemovalProjector,RemoteDevelopmentInstanceSourceRemoval}.php
  - apps/gateway/app/Http/Requests/Instances/RemoveInstanceRequest.php
  - apps/gateway/app/Models/{InstanceRemoval,InstanceRemovalMember}.php
  - apps/cli/app/Commands/Instances/DestroyInstanceCommand.php
---

# Instance removal

`instance:destroy` removes one Instance with its Route, Processes, Schedules, and runtime. A development removal deletes the checkout or worktree. A production removal keeps the application content in the production home. The native removal projector also removes managed Route and runtime projections; a missing production PHP service is reported as a bounded resource failure.

```bash
orbit instance:destroy <instance> [--yes] [--force] [--json]
```

The CLI asks for default-No consent that names the Instance. `--yes` confirms without a prompt and is required for JSON and noninteractive calls. `--force` never gives consent.

## Normal and forced removal

The two modes differ only for development source.

| Mode | Development source |
| --- | --- |
| Normal | Refuses dirty source, a `HEAD` that no current origin branch or tag contains, and a checkout with registered linked worktrees. |
| Forced | Deletes dirty or unpublished source, and removes a checkout together with its registered worktrees. |

`--force` waives only those three refusals. The identity checks below apply in both modes. Neither mode needs `HEAD` to descend from the starting commit.

Normal removal reads the current origin refs into a temporary store outside the checkout. It does not fetch into, prune, or change the checkout. Forced removal checks the origin locally and needs no network.

Orbit never deletes a remote branch. Removing a worktree keeps its local branch, the common repository, and the other worktrees.

## Checks before removal

The Gateway checks everything before it changes anything. A failed check changes nothing.

The Instance must be `active`, or `source_resolved` with no Route, such as a task workspace. A `laravel-app` Instance must have exactly one Route. A development Instance must be the only target of its Route. A production Instance may share a Cluster Route with production Instances on other Nodes.

The Gateway also refuses these Instances:

| Code | Cause |
| --- | --- |
| `instance.transfer_incomplete` | A [transfer](/reference/instance-transfer) of the Instance is not complete. Recover it first. |
| `instance.clone_in_progress` | The Instance is the candidate of an incomplete [clone](/reference/instance-cloning). |
| `analytics.tracking_hosts_exist` | The Instance still has [tracking hosts](/cli/instance#orbit-instanceanalyticsdisable). |
| `instance.remove_refused` | The Instance is in another state, its Route is not removable, or normal mode found dirty or unpublished source. The message names the rule. |

For a development Instance, the Gateway compares the checkout with its record. Each origin check reads the `remote.origin.url` stored in the checkout and ignores `insteadOf` rewrites.

| Code | Refused check |
| --- | --- |
| `instance.source_path_mismatch` | The path is not the recorded directory: it is outside the apps root, has a symlink, or is missing. |
| `instance.source_ownership_mismatch` | The managed Node user does not own the directory or its parent. |
| `instance.source_layout_mismatch` | The Git directory does not match the recorded checkout or worktree layout. |
| `instance.source_origin_mismatch` | The origin is not the Project repository. |
| `instance.source_branch_mismatch` | The branch differs from the recorded branch. |
| `instance.source_worktrees_mismatch` | Git's worktree list does not include the recorded checkout. |
| `instance.checkout_path_unsafe` | The path overlaps another managed Instance. |
| `instance.force_failed` | A forced check failed for another reason. |

When a worker is configured, Git checks that inspect file contents run as that worker without a credential environment. A clean filter triggered by the dirty-source check cannot run as the managed account. Privileged ownership checks and deletion still run as the managed account.

The ownership check reads the owner of the checkout directory and its parent. It does not read the owner of every file inside. A development checkout can hold an ACL for `orbit-worker` and files that user created. [Checkout access](/reference/instance-setup#checkout-access) grants that ACL. Removal still refuses a directory the managed user does not own.

### Worktree sets

A worktree Instance is removed alone. A linked worktree whose directory is gone, which Git calls prunable, does not count.

A checkout with registered worktrees needs `--force`. Then Orbit removes every worktree first, sorted by path, and the checkout last. Each worktree must be an active Instance of the same Project on the same Node. An unregistered worktree refuses both modes. After the Gateway accepts the set, it refuses new Processes and Schedules for its members.

### Teardown

Before it accepts a development removal, the Gateway runs the Project [teardown steps](/reference/instance-setup#run-teardown). A failed step stops the removal and keeps the Instance. Then the Gateway checks the source again. A teardown that changed the source identity returns `instance.remove_refused`. Production removal runs no teardown.

## Removal steps

Once accepted, the Gateway marks each member `removing` and completes five steps per member, one member after another.

| Step | Work |
| --- | --- |
| `source_preparation` | Record the source identity, or the production content to keep. |
| `route_target_clear` | Stop the Route from sending traffic to the Instance. See [Route cleanup](#route-cleanup). |
| `source_finalization` | Delete the development checkout, or keep the production content. Remove the `<apps-root>/<project-slug>` directory when it is empty. |
| `runtime_cleanup` | Remove every owned Process and Schedule, then the PHP-FPM pool or service, Caddy site, and other runtime files. |
| `row_deletion` | Cancel the Instance's open annotation tasks, and their tasks when nothing else is open, and mark those annotations cancelled. Then delete the Instance record. |

### Route cleanup

A Route that loses its last target is deleted, with its Caddy, certificate, DNS, and firewall projections, and its domain is released. A shared production Route keeps serving its other targets, and Orbit republishes it.

Between `route_target_clear` and Route deletion, a request to a development domain gets `503 Service Unavailable` with the body `Orbit Route unavailable`. It never reaches the old target.

### Processes and Schedules

Removal cleans up every Process and Schedule the Instance owns, in any state, for systemd and Docker. Orbit checks that each unit, container, or timer belongs to that owner before it removes it. A missing artifact counts as done. An artifact with another owner stops the removal, and Orbit never adopts or deletes it.

Orbit does not wait for a running Schedule command. The command may finish or fail while its source disappears. Its completion callback cannot bring the Schedule back. Node Schedules and the children of other Instances stay.

### Transfer history

A completed transfer record stays after removal, with its Instance reference cleared.

### Production content

Production removal deletes the `current` link, the dedicated PHP-FPM service, pool, and socket, and the Caddy and certificate projections. It keeps `releases/`, `.env`, `database.sqlite`, the production user, and `/etc/orbit/php-fpm/<production-user>/local.conf`. It leaves every other PHP-FPM service and cache alone. See [Production release layout](/reference/deployments#retained-content).

## Progress and retry

The API, SDK, CLI, and Activity report removal progress in one shape. `DELETE` returns it. `instance:list` and `instance:show` include it as `removal` while the Instance exists. A failure after acceptance includes it in `error.details.removal`.

| Field | Meaning |
| --- | --- |
| `operation_id`, `id`, `name` | The removal and the requested Instance. |
| `force` | Whether the removal is forced. |
| `status` | `removing`, `failed`, or `completed`. |
| `current_step` | The first unfinished step, or null when done. |
| `total`, `completed`, `remaining` | Member counts. |
| `failed_step`, `error_code` | The step and code of a failure, or null. |

Repeat the same command to resume at the first unfinished step. A changed `--force` value returns `instance.removal_conflict`. Before it deletes more source, the Gateway checks each remaining source again. A retry after Route deletion does not recreate the Route. A cleanup failure keeps the Instance and its progress until you repair the Node or the artifact and retry.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One removal command

Registration transfers ownership to Orbit, so `instance:destroy` removes every source layout. A separate unregister command was rejected. A worktree Instance owns its directory, not its branch, so its local branch stays.

### Removal owns its children

Removing an Instance removes its Processes and Schedules in the same operation. Requiring you to remove each child first was rejected, because the cleanup belongs to the Instance. Waiting for running Schedule commands was rejected, because the application would then decide when removal ends.

### `--force` waives work loss, not safety

Forced removal accepts losing uncommitted or unpushed work. It never accepts a path, owner, or repository that does not match the record, because that could delete source Orbit does not own.

### Keep unfinished transfer history

A completed transfer is history, so it survives removal. An unfinished or failed transfer holds recovery state, so it blocks removal. Deleting all transfer rows or clearing every reference was rejected: both would drop that recovery state silently.

### Production keeps its content

Production data and releases are hard to rebuild. Removal stops serving the Instance but leaves its home for recovery.

### The owner check is the directory

The managed user must own the checkout directory and its parent. Files inside can belong to `orbit-worker` when that user has an ACL on the tree. Checking every file was rejected, because the agent creates files and the managed user can still delete them while the directory stays writable. An ACL does not change the owner the check reads.
