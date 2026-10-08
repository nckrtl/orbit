---
title: "Instance removal"
description: "How Orbit removes an Instance, what --force changes for development source, how owned Processes and Schedules go with it, and how an interrupted removal resumes."
covers:
  - apps/gateway/app/Actions/{Instances/RemoveInstanceAction,DatabaseConnections/DropOwnedDatabasesAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceRemover.php,InstanceRemovalStatus.php,InstanceRemovalStep.php,InstanceCreationRecovery.php,Removal/**}
  - apps/gateway/app/Infrastructure/{*/RecordedProduction*ContentRetention,Instances/NativeInstanceRemovalProjector,Instances/RemoteDevelopmentInstanceSourceRemoval}.php
  - apps/gateway/app/Http/Requests/Instances/RemoveInstanceRequest.php
  - apps/gateway/app/Models/{InstanceRemoval,InstanceRemovalMember}.php
  - apps/gateway/database/migrations/*_{allow_failed_creation_removal,allow_pre_activation_instance_removal,add_instance_source_prepare_id,allow_owned_interrupted_creation_removal,allow_force_takeover_of_failed_instance_removal,allow_reserved_task_worktree_removal,allow_reserved_worktree_null_prepare_removal}.php
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

`--force` waives only those three refusals. The identity checks below apply in both modes. Neither mode needs `HEAD` to descend from the starting commit. An active Instance keeps these rules; [removing a failed create](#failed-creation) does not weaken them.

Normal removal reads the current origin refs into a temporary store outside the checkout. It does not fetch into, prune, or change the checkout. Forced removal checks the origin locally and needs no network.

Orbit never deletes a remote branch. Removing a worktree keeps its local branch, the common repository, and the other worktrees.

## Checks before removal

The Gateway checks everything before it changes anything. A failed check changes nothing.

The Instance must be `active`, `source_resolved` with no Route (such as a task workspace), or an [interrupted or failed development create](#pre-activation-removal) that never became active. An Instance already `removing` resumes its recorded removal. An active `laravel-app` Instance must have exactly one Route. A pre-activation Instance can have no Route or its own pending or failed Route. A development Instance must be the only target of its Route. A production Instance may share a Cluster Route with production Instances on other Nodes.

The Gateway also refuses these Instances:

| Code | Cause |
| --- | --- |
| `instance.transfer_incomplete` | A [transfer](/reference/instance-transfer) is still open: it is unfinished, failed before cutover with incomplete rollback, or failed after cutover. Retry the identical transfer request first. |
| `instance.clone_in_progress` | The Instance is the candidate of an incomplete [clone](/reference/instance-cloning). |
| `analytics.tracking_hosts_exist` | The Instance still has [tracking hosts](/cli/instance#orbit-instanceanalyticsdisable). |
| `instance.remove_refused` | The Instance is in another state, its Route is not removable, or normal mode found dirty or unpublished source. The message names the rule. |

The Gateway permits removal after a transfer fails before cutover and finishes rollback, including cleanup of its owned SQLite seed files. Failed or unconfirmed seed cleanup keeps the transfer open. Normal and forced removal follow this rule. `--force` cannot bypass an open transfer, and the other removal checks still apply.

For a completed development checkout, the Gateway compares the checkout with its record. Each origin check reads the `remote.origin.url` stored in the checkout and ignores `insteadOf` rewrites. A [failed create](#failed-creation) can leave no checkout or an incomplete one.

| Code | Refused check |
| --- | --- |
| `instance.source_path_mismatch` | The path is not the recorded directory: it is outside the apps root, has a symlink, or is missing. |
| `instance.source_ownership_mismatch` | The managed Node user does not own the directory or its parent. |
| `instance.source_layout_mismatch` | The Git directory does not match the recorded checkout or worktree layout. |
| `instance.source_origin_mismatch` | The origin is not the Project repository. |
| `instance.source_branch_mismatch` | Resolved source differs from the recorded branch, including detached `HEAD` when a branch was recorded. This check also applies with `--force`. |
| `instance.source_worktrees_mismatch` | Git's worktree list does not include the recorded checkout. |
| `instance.checkout_path_unsafe` | The path overlaps another managed Instance. |
| `instance.force_failed` | A forced check failed for another reason. |

After a caller renames a branch locally, [`instance:rename --branch=BRANCH`](/cli/instance#orbit-instancerename) records the current branch before removal. Force does not bypass this reconciliation. Recording the branch does not waive normal removal's dirty or unpublished-source checks.

### Pre-activation removal

A failed create normally cleans up its new Instance before returning the original error. It removes only the attempt's owned checkout, Route and projections, runtime, dependency-copy staging paths, and database copies, with no teardown and no cascade into another Instance. Once cleanup completes, the name, path, and domain are free for a fresh create, including a different branch. See [creation recovery](/domains/applications#create-a-development-instance).

If the process is interrupted or cleanup cannot finish, `instance:destroy` accepts development Instances in `reserved`, `checkout_prepared`, and `source_resolved` when failure is recorded or the create attempt has a recorded source preparation ID. A process can die after committing a creation state but before recording a failure, so null `failed_step` and `error_code` do not block removal of that attempt's owned checkout. Registration still requires its own recorded evidence.

Removal uses the same recorded steps and resumable resource cleanup as active removal. Teardown is skipped because setup has not run. An incomplete transfer or clone candidate still refuses removal.

A task workspace that is still `reserved` with no starting commit is also pre-activation when its layout is `worktree`, `task_workspace_routed` is set (including `false`), and no registration request is recorded. Older reservations can lack a source preparation ID; the task workspace evidence still permits removal. Removal and group cancellation accept this interrupted state even without `failed_step` or `error_code`; `instance.remove_refused` does not apply merely because the layout changed. A reservation with neither a preparation ID nor task workspace evidence still refuses removal, even with `--force`. The ownership checks above still apply, and teardown is skipped.

An unrouted task workspace is different once resolved: `task_workspace_routed=false` makes `source_resolved` its healthy settled state, so its normal removal still runs Project teardown. It is not a failed create.

A reserved Instance may have no checkout directory, including a task reservation whose layout changed to `worktree` before its directory was created. Removal records the absent source and leaves its seed repository and sibling worktrees untouched. A prepared repository may contain only `.git`, without a resolved branch or commit. These absences are accepted in pre-activation removal and do not require `--force`. A missing directory for active source still returns `instance.source_path_mismatch`.

For new reservations, preparation writes a receipt in Git metadata with the recorded preparation ID and the directory's device and inode. Removal requires that receipt whenever the directory exists, even with `--force`. A matching origin and account owner do not prove that the create attempt owns a pre-existing checkout. A lost prepare response with a valid receipt can be cleaned up. If preparation stops before recording ownership, cleanup retains the unconfirmed directory for inspection rather than deleting it. Do not bypass an ownership refusal to finish cleanup.

Orbit checks the recorded path, managed ownership, repository layout, and Project origin for every artifact that exists. It refuses an unsafe path, foreign repository, or foreign worktree instead of deleting it. When no source was resolved, removal does not require a nonexistent recorded branch or `HEAD` to pass the branch or publication checks. Once source has been resolved, the recorded-branch check still applies before activation. Interrupted or failed creation can leave dirty or unpublished partial source; removing that owned partial checkout needs no `--force`. Adopted source from registration still follows the normal dirty and unpublished-source checks.

Cleanup that cannot finish retains the Instance and removal progress. A failed create reports its original error with `details.cleanup = "incomplete"`, the Instance identity, and a recovery command. Follow that command to finish removal; `--yes` supplies consent and `--force` waives only the normal dirty, unpublished-source, and linked-worktree refusals. Cleanup never deletes the Instance row before its owned resources have been handled.

When a worker is configured, Git checks that inspect file contents run as that worker without a credential environment. A clean filter triggered by the dirty-source check cannot run as the managed account. Privileged ownership checks and deletion still run as the managed account.

The ownership check reads the owner of the checkout directory and its parent. It does not read the owner of every file inside. A development checkout can hold an ACL for `orbit-worker` and files that user created. [Checkout access](/reference/instance-setup#checkout-access) grants that ACL. Removal still refuses a directory the managed user does not own.

### Failed creation

Failed creation uses the [pre-activation removal](#pre-activation-removal) rules above. Removal releases the reserved Vite port along with the owned checkout and Route.

A failed in-place registration is different from a failed clone: registration records the existing source before reserving the Instance. A `reserved` registration can be removed when its recorded original and authoritative paths both equal its managed checkout path, and its recorded repository, branch, detached state, and commit still match. The normal path, ownership, layout, and worktree checks still apply. Unlike a partial clone, an adopted source still needs `--force` when it is dirty or unpublished. Removal keeps a worktree's local branch and common repository.

A reservation for a move that has not verified its destination cannot authorize deleting an existing checkout; retry registration first.

A non-active state alone does not prove source ownership. Removal holds the same lifecycle and source locks as creation, so it cannot delete a checkout while create is running. It rechecks the state after acquiring those locks. `--force` does not bypass the locks or ownership checks. An Instance that already became `active` uses the normal or forced removal rules above, even if a later setup step failed.

### Worktree sets

A task workspace seeded from `default` is a worktree Instance. Removal checks its creation receipt in the worktree's private Git administration directory, not under its `.git` pointer file. A worktree Instance is removed alone; its seed repository and other Instances remain. A linked worktree whose directory is gone, which Git calls prunable, does not count.

A checkout with registered worktrees needs `--force`. Then Orbit removes every worktree first, sorted by path, and the checkout last. Each worktree must be an active Instance of the same Project on the same Node. An unregistered worktree refuses both modes. After the Gateway accepts the set, it refuses new Processes and Schedules for its members.

### Teardown

Before it accepts removal of an active development Instance, the Gateway runs the Project [teardown steps](/reference/instance-setup#run-teardown). A failed step stops the removal and keeps the Instance. Then the Gateway checks the source again. A teardown that changed the source identity returns `instance.remove_refused`. Production removal runs no teardown.

## Removal steps

Once accepted, the Gateway marks each member `removing` and completes five steps per member, one member after another.

| Step | Work |
| --- | --- |
| `source_preparation` | Record the source identity, or the production content to keep. |
| `route_target_clear` | Stop the Route from sending traffic to the Instance. See [Route cleanup](#route-cleanup). |
| `source_finalization` | Delete the development checkout, or keep the production content. Remove the `<apps-root>/<project-slug>` directory when it is empty. |
| `runtime_cleanup` | Remove every owned Process and Schedule, then the PHP-FPM pool or service, Caddy site, and other runtime files. Drop every [owned database](#owned-databases). See [Runtime cleanup](#runtime-cleanup). |
| `row_deletion` | Cancel the Instance's open annotation tasks, and their tasks when nothing else is open, and mark those annotations cancelled. Then delete the Instance record. |

### Runtime cleanup

`runtime_cleanup` converges PHP-FPM and Caddy on the Instance's Node from stored state. By then the Instance has no Route target, so stored state renders neither its PHP-FPM pool nor its Caddy site, and convergence removes both. A development Instance runs this step even when it never became active and has no recorded Route.

A pending Route can publish a pool before activation, and `route:destroy` can delete that Route before the Instance is removed. So neither the Route nor the Instance state proves that no pool is left. A production Instance that never published a runtime and has no Route skips the step's runtime work. The cost is one PHP-FPM convergence and one Caddy build on the Node for each removed development Instance.

Removal ignores `app-dev.php_pool_directory_missing` from that convergence. The error names another site's pool, and the convergence has already removed the removed Instance's pool. Doctor keeps reporting the skipped pool.

`source_finalization` deletes the checkout before `runtime_cleanup`, so for a moment the live pool names a missing directory. [PHP-FPM convergence](/reference/php-runtime#development-runtime) never renders a pool for a missing directory and does not need PHP-FPM to start first, so a later convergence still repairs the Node if `runtime_cleanup` fails. A failed step keeps the removal open for retry, and Doctor reports the leftover pool as `role.php_pool_directory_missing`.

### Route cleanup

A Route that loses its last target is deleted, with its Caddy, certificate, DNS, and firewall projections, and its domain is released. A shared production Route keeps serving its other targets, and Orbit republishes it.

Between `route_target_clear` and Route deletion, a request to a development domain gets `503 Service Unavailable` with the body `Orbit Route unavailable`. It never reaches the old target.

### Processes and Schedules

Removal cleans up every Process and Schedule the Instance owns, in any state, for systemd and Docker. Orbit checks that each unit, container, or timer belongs to that owner before it removes it. A missing artifact counts as done. An artifact with another owner stops the removal, and Orbit never adopts or deletes it.

Orbit does not wait for a running Schedule command. The command may finish or fail while its source disappears. Its completion callback cannot bring the Schedule back. Node Schedules and the children of other Instances stay.

### Owned databases

`runtime_cleanup` drops each [database the Instance owns](/reference/database-connections#owned-databases): the database and its test databases on the [Database server](/reference/database-servers), or the SQLite file. When the Instance owns no database on a server any more, its user there goes too. Then it deletes the connection record and every attachment of it. A database the Instance only has attached stays, and only the attachment goes. A failed drop keeps the member in `runtime_cleanup`, and a retry drops again. A database that is already gone counts as done.

### Transfer history

Closed transfer records stay after removal, with their Instance references cleared. This includes completed transfers and transfers that failed before cutover and finished rollback. A failed record keeps its status and failure details.

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

Repeat the same command to resume at the first unfinished step. To take over a failed normal removal, repeat it with `--force`. Orbit upgrades the same operation, keeps its accepted member set and completed steps, and rechecks the remaining source identities before continuing. This also works when source finalization already moved a linked worktree into authenticated quarantine; completion removes its Git worktree entry but keeps the common repository, local branch, and sibling worktrees. You cannot downgrade a forced operation or change the mode of an operation that is still running; those requests return `instance.removal_conflict`.

Before it deletes more source, the Gateway checks each remaining source again. A retry after Route deletion does not recreate the Route. A cleanup failure keeps the Instance and its progress until you repair the Node or the artifact and retry. When a failed create has no checkout, finalization records completion only after it has cleaned up the empty Project directory. An interrupted directory cleanup stays unfinished and resumes on retry.

Before deleting source, the Gateway runs `find -P` as the task worker on directories that worker owns. It clears setgid and sticky bits and gives the group `rwx`, which also sets the ACL mask. This lets the managed user remove the worker's entries without changing ownership or following symlinks. The managed user enters the validated tree before switching to the worker, so the quarantine's parent stays private.

The worker's `find` does not descend into directories it cannot enter; it skips them instead of failing the mode fix. This is safe because the worker cannot have created entries inside those directories, so skipping them cannot leave worker-owned entries that need the mode fix behind. The managed user then deletes the whole tree, including the skipped directories.

Two things otherwise block that removal. On Ubuntu 26.04, uutils `mkdir` 0.8.0 can set setgid and sticky bits on directories created under a default ACL; GNU `mkdir` and `os.mkdir` do not. The ACL alone does not override the sticky bit. A directory created with an explicit mode such as `0755`, as Pest does for its graph, narrows the mask to `r-x` and hides the managed user's ACL entry.

Source finalization checks dirty and unpublished content before moving the tree into quarantine. A normal refusal leaves the source at its original path and does not change Git's worktree entry. It then moves the validated tree into quarantine and writes an authenticated receipt before deletion.

After that receipt exists, a checkout retry checks the journal, receipt, quarantine path, owner, and recorded device and inode, then deletes the remaining tree. It does not require the quarantined checkout to remain a valid Git repository: a partial deletion may leave `.git` missing or damaged. Worktree recovery also checks the recorded common repository and worktree administration before cleanup. If its quarantine directory is already gone, the matching journal, receipt, and recovery record authorize removal of only its recorded Git administration entry. A replaced quarantine or mismatched receipt still stops removal.

Forced removal of a linked-worktree Instance, without a checkout in the removal's accepted member set, checks its own path, owner, repository, branch, and accepted commit, not the membership of unrelated sibling worktrees. Siblings may be added, removed, or moved into another removal's quarantine without blocking it. Those siblings, their Git administration entries, the common repository, and local branches stay untouched. Checkout removal still checks the accepted dependency inventory before cascading. A changed commit, branch, origin, or replaced source directory still stops a forced linked-worktree retry.

### Post-deploy operator step: Instances 298 and 302 on beast

After deploying this fix, the operator checks Instances 298 and 302 on beast and their recorded removal progress, confirms that each source belongs to its Instance, and retries `orbit instance:destroy 298 --yes --force` and `orbit instance:destroy 302 --yes --force`. For each Instance, check that the operation completes, its quarantined directory and Git worktree entry are gone, and the common repository and sibling Instances remain. This is a live-resource operator step, not part of the disposable Incus proof.

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
