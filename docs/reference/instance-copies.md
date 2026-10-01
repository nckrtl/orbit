---
title: "Instance copies"
description: "How instance:create --from copies a warm development Instance on the same Node, with a reflink or a plain copy."
covers:
  - apps/gateway/app/Actions/Instances/{CreateInstanceAction,IsolateCopiedInstanceAction,RemoveInstanceAction}.php
  - apps/gateway/app/Http/Requests/Instances/StoreInstanceRequest.php
  - apps/gateway/app/Data/Instances/{CreateInstanceData,InstanceData,InstanceSharedDatabaseData}.php
  - apps/gateway/app/Models/Instance.php
  - apps/cli/app/Commands/Instances/CreateInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/CreateInstanceRequest.php
  - apps/gateway/app/Infrastructure/Tasks/TaskWorkspaceProvisioner.php
  - apps/gateway/{app/Domain/Instances/Copy/{InstanceCopyNames,InstanceCopyReferenceRewriter}.php,app/Infrastructure/Instances/{RemoteInstanceSqliteSeeder,RemoteInstanceDestinationGuard,RemoteDevelopmentInstanceCheckoutCopier,DevelopmentInstanceCopyIsolationProgram}.php,database/migrations/{2026_09_12_000000_add_clone_evidence_to_app_instances.php,2026_10_06_000000_add_instance_copy_evidence.php}}
---

# Instance copies

`instance:create --from` creates a development Instance by copying another development Instance's checkout on the same Node. The Gateway tries a reflink and falls back to a plain copy. [Instance cloning](/reference/instance-cloning) is the production command and does not do this.

The copy is an independent Git checkout with its own branch, Route, Vite port, and SQLite snapshots. It then runs the Project [setup steps](/reference/instance-setup). The source keeps running.

## Request

Send `source_instance_id` to copy a development Instance. Omit it to clone the repository, which is unchanged.

```bash
orbit instance:create <project> <node> <name> --from=<instance> [--branch=BRANCH] [--domain=HOST] [--root=PATH]
```

The CLI calls `POST /api/v1/instances`. `--from` is optional. An interactive call does not prompt for it. A value that is not a positive integer returns `instance.id_invalid` and sends no request.

| Field | Meaning |
| --- | --- |
| `project_id` | Numeric Project id. Required. It must be the source Project. |
| `node_id` | Numeric Node id. Required. It must be the source Node. |
| `name` | Instance name. Required. The usual name and path rules apply. |
| `source_instance_id` | Numeric source Instance id. This is `--from`. |
| `branch` | Branch name on the copy. Omit it to use the new Instance name. |
| `domain` | Explicit Route domain. Omit it to generate one. |
| `root` | Omit it to keep the source root. A passed value must equal the source `root`. |

`instance:clone` does not accept this shape. An `app-prod` Node still returns `instance.candidate_required`.

## Source eligibility

The Gateway checks the source before it changes the Node. Every row must hold.

| Check | Required state |
| --- | --- |
| Identity | The source exists, is in `project_id`, and runs on `node_id`. |
| Node | Active Linux `app-dev`, and the Project does not exclude it. |
| Status | `active`. `source_resolved` is not enough. |
| Warmth | No cold marker at `app-instance-{id}.cold`. |
| Layout | Independent `checkout`. A worktree, or a checkout with linked worktrees, is refused. |
| Branch | `HEAD` is the recorded branch. |
| Tree | No staged or unstaged change to a tracked file. |
| Git | `.git` is a directory, `core.worktree` is unset, and alternates are absent. |
| SQLite | No SQLite database path inside the checkout is a symlink. |

Untracked files and ignored files are eligible. They are part of the copy. An awake marker, a running Process, and a running Schedule do not refuse the source. Orbit does not stop them.

The cold marker lives in `/data/caddy/orbit/hibernation`. A cold source has had `vendor` and `node_modules` removed. Copying it would not be warm.

## Copy mode

The Gateway does not probe reflink with a temporary file. On ZFS 2.2 and 2.3, `zfs_bclone_wait_dirty` defaults to 0, so cloning a newly written file can fail while cloning clean checkout files succeeds. ZFS 2.4 defaults that parameter to 1.

The copy runs under the Node source lock, after the [row and marker](#copy-steps) exist:

1. Run `sync -f` on the apps root. A failure does not stop the copy.
2. Run `cp -a --reflink=always` with `LC_ALL=C`.
3. Exit 0 stores `copy_mode` `reflink`.
4. On a fallback errno, delete the owned partial tree and run `cp -a` without `--reflink`.
5. Exit 0 stores `copy_mode` `full`. Any other `cp` failure is `instance.copy_failed`.

`cp` exits 1 for every error. The errno is not in the exit status. The Gateway reads stderr and matches the `LC_ALL=C` strerror text:

| Errno | stderr text |
| --- | --- |
| `EOPNOTSUPP` | `Operation not supported` |
| `EXDEV` | `Invalid cross-device link` |
| `EAGAIN` | `Resource temporarily unavailable` |
| `EINVAL` | `Invalid argument` |

OpenZFS returns `EAGAIN` for a clone of a dirty block when `zfs_bclone_wait_dirty` is 0, and `EINVAL` for a short clone. Its `zpl_copy_file_range` falls back on those four errnos. A fallback applies only when every error line names one of those strings.

`ENOENT` (`No such file or directory`) is not a fallback. When every missing path is under a [reset path](#what-the-copy-resets), the Gateway deletes the owned partial tree and runs that same `cp` once more. Another such failure, or an `ENOENT` outside those paths, is `instance.copy_failed`.

The Gateway does not follow symlinks during `cp`. It [retargets](#values-that-name-the-source) absolute links afterward. `--reflink=auto` is not used. Its exit status is 0 when the filesystem copies a file in full, so it cannot report the mode.

A plain `cp` on OpenZFS can still share blocks through `copy_file_range`. `copy_mode` `full` means the required reflink command was not available. It is not a count of shared blocks after the plain copy.

`reflink` includes OpenZFS block cloning when `FICLONE` succeeds, and the same operation on XFS with reflink or on btrfs.

## Copy steps

An identical request for a complete copy returns that Instance and does not copy again. A retry that changes the source, Node, name, root, domain, or branch returns `instance.placement_conflict`.

1. Re-check [eligibility](#source-eligibility) under the Node source lock.
2. Reserve the Instance row.
3. Inspect the destination against the ownership rule below.
4. Write the ownership marker for this Instance id.
5. Copy the checkout with the [copy mode](#copy-mode) commands.

The check in step 3 happens before any marker is written. A new marker is not proof that a directory already on disk belongs to this request. The marker is `<apps-root>/.orbit/copies/instance-{id}`, and its contents are the destination path. It sits outside the destination, so `cp` cannot replace it. It is proof of ownership only for a directory created after that write. A path that shares a prefix does not match.

An occupied destination with no matching marker returns `instance.path_taken` or `instance.default_path_occupied`, the same codes as a repository create. The directory stays, and no marker is written. A reservation made for that request is released: the Instance row, its Route, and its Vite port are removed. A marker for this Instance id that names another path returns `instance.placement_conflict`, and neither path is deleted.

Activation deletes the marker when the checkout is recorded on the active Instance. Setup that fails after that uses the Instance row to remove the checkout. A transfer finds no marker to carry or to leave behind.

The source repository is not modified. The copied `.git` directory is the new repository. Orbit does not rewrite a worktree path. `core.worktree` is unset, and `.git` is a directory, so there is no separate worktree file to retarget.

6. Create the [branch](#branch) at the source `HEAD`. This happens before the snapshot and the rewrite, because `git checkout --force` would replace those files with the commit.
7. Replace each SQLite database with a [snapshot](#sqlite-snapshots).
8. Delete the [reset paths](#what-the-copy-resets) in the new checkout.
9. [Rewrite](#values-that-name-the-source) stored values, `.env`, `bootstrap/cache`, and absolute symlinks.
10. Import file-only `.env` keys, then run completion and the Project setup steps.

Completion is the same path as a repository create: source profile, Laravel `APP_URL` including cached config, Route publication, and activation. The copied repository must pass the same prepared-checkout inspection. The origin URL stays the URL from the source.

A failure after the copy starts uses the same removal `instance:create` already uses after a failed setup step. Teardown runs when setup has started. Forced removal then drops the checkout, the PHP-FPM pool, the Route, and the Route projection. Before activation, removal deletes the checkout only when the marker was written before that directory and still names this exact path, and it deletes the marker with the row. A failure before completion has reserved a pool or Route deletes the owned checkout and the row, and does not invent a pool or a projection to remove.

An identical retry returns the Instance when it is active and setup succeeded. When another request still holds the Instance lifecycle lock, including during setup, the retry returns `instance.lifecycle_busy` and does not remove the row. When the row is incomplete and that lock is free, the retry removes it through the same removal path, then copies again.

The copy uses the same request deadline as `instance:create`. A deadline is a failure: the owned partial target is removed through that same path, and the response is `command.deadline_exceeded`. The remote `cp` runs under `timeout` for the remaining budget plus a short backstop, and its process group id is recorded beside the ownership marker. When the deadline or a lost connection cuts the local SSH client, cleanup signals that process group before it deletes the partial checkout, so the remote copy does not keep writing.

## Branch

The copy checks out its own branch at the source `HEAD` and stores that name as `branch_override`.

| Input | Result on the copy |
| --- | --- |
| `branch` omitted | Create the Instance name at the source `HEAD`. |
| `branch` passed | Create that name at the source `HEAD`. |
| The name exists at that same commit | Check it out. |
| A local or remote-tracking ref of the name points elsewhere | `instance.copy_branch_diverged`. The partial target is removed. |

The Gateway does not fetch during `instance:create --from`. A task workspace fetches, and [that section](#task-workspaces) is the exception. The source repository is not modified.

A branch that exists only on the copy is unpublished. [Normal removal](/reference/instance-removal#normal-and-forced-removal) then refuses the checkout until the branch is on the origin or the caller passes `--force`.

## What the copy keeps

The new checkout is the source tree after the reset, rewrite, and SQLite snapshots below. It keeps:

- Git objects, refs, remotes, and the origin URL.
- Ignored dependency directories such as `vendor` and `node_modules`.
- Untracked files, including built assets and `.env`.
- The source's stored environment values, and its MySQL, PostgreSQL, and Redis attachment rows.

`APP_KEY` stays so a snapshotted SQLite file can still be decrypted. MySQL, PostgreSQL, and Redis attachments are copied as rows and keep pointing at the same servers. Those servers are not copied. The create result lists them in `shared_databases` as `{slug, driver}`. A SQLite attachment is not copied when its path is the source checkout or a file inside it. The snapshot and the rewritten environment key name the file in the new checkout. The source connection record is left unchanged. A SQLite path outside that checkout is still copied, including a longer name such as `default-two`. That attachment is not listed in `shared_databases`.

[Transfer](/reference/instance-transfer) keeps `creation`, `source_instance_id`, and `copy_mode`. It does not take a new reflink and does not change those fields.

## What the copy resets

These paths are deleted in the new checkout only. A symlink is removed as a symlink and is not followed.

| Path | What is deleted |
| --- | --- |
| `public/hot` | The file, so the copy does not use the source dev server. |
| `storage/logs/` | Files inside it. The directory and `.gitignore` stay. |
| `storage/framework/cache/`, `sessions/`, `views/` | Cache, session, and view files inside each directory, including nested directories. The directory and every `.gitignore` stay, so the copy does not record a tracked deletion. |
| `node_modules/.vite/`, `node_modules/.cache/` | Those directories. |

These resources are created for the new Instance, not copied:

| Resource | Result |
| --- | --- |
| Route and domain | New Route, or none when the Project type has none. |
| Vite port | A new port on the Node. The source port stays. |
| Vite environment file | Not copied. It appears when a `vp-dev` Process starts. |
| PHP-FPM pool and socket | A new development pool for the new id. |
| Hibernation markers and logs | None. The copy does not receive the source cold marker. |
| Processes, Schedules, analytics, annotations | None. |

## SQLite snapshots

After the tree copy, the Gateway scans the source checkout for regular files whose header is `SQLite format 3`. The scan skips `.git`, `vendor`, and `node_modules`, and it does not follow symlinks. A symlink at such a path fails the copy with `instance.copy_source_unsafe`.

Each snapshot reads the source Instance's file at that relative path. It does not read the copied bytes, which can be torn. The read is the transfer seeder's single-step `sqlite3` backup on a `mode=ro` connection: `PRAGMA query_only`, then `backup` into a new file. That backup is safe while writers keep using the source. The snapshot replaces the file at the same relative path in the target. Sibling `-wal` and `-shm` files in the target are deleted. A snapshot failure removes the owned partial target and returns `instance.copy_failed`.

## Values that name the source

The copied `.env` stays. The Gateway also copies stored environment rows. It then rewrites both, and every file under `bootstrap/cache`:

- A matched source checkout path becomes the target checkout path.
- A matched source domain becomes the target domain.
- A matched absolute symlink is retargeted to the same path under the target.

A path matches when the next character is `/` or the value ends. A letter, digit, `.`, `_`, or `-` continues that value, so the path does not match. `/apps/p/feature` matches `/apps/p/feature/app` and a quoted `/apps/p/feature`. It does not match `/apps/p/feature-two` or `/apps/p/feature.sqlite`.

A domain matches as a whole host. The character before it is the start of the value, or it is outside `A-Z`, `a-z`, `0-9`, `-`, and `.`. The character after it is the end of the value, or it is outside that set. A following `.` does not match. `https://feature.example.test/path` matches. `feature.example.test.other` does not. `notfeature.example.test` does not. `api.feature.example.test` does not.

An absolute symlink uses the path rule. The link text is the source checkout path, or that path followed by `/`.

`DB_DATABASE`, `APP_URL`, and `AGENTATION_URL` are covered by the text replacements. They are not a separate list. The normal Laravel URL step still sets `APP_URL` and the cached `url` for a Laravel Instance.

The Gateway reads each symlink in the new checkout and does not follow it. An absolute link is retargeted only when its text is the source checkout path, or that path plus `/` and a suffix. The source prefix becomes the target checkout path. `/apps/p/feature-two` is not a match. Laravel's `storage:link` writes `public/storage` as an absolute link to `storage/app/public` unless `--relative` was used. After `cp -a`, that link still names the source tree, so this rewrite is required. A relative link is left as it is, because its target is already inside the copied tree.

`https://feature.example.test/path` and `feature.example.test:443` match the domain `feature.example.test`. `feature.example.test.other`, `notfeature.example.test`, and `api.feature.example.test` do not.

A key that exists only in the copied `.env` is imported into stored configuration. A sync then keeps it. The key is not dropped.

MySQL, PostgreSQL, and Redis connection records stay shared. Their hosts are rewritten only when the stored value actually contains the source checkout path or the source domain.

## Reporting

`creation` is stored on every Instance. `copy_mode` is stored only for a copy.

| `creation` | When it is set |
| --- | --- |
| `repository` | `instance:create` without `--from`, including a task workspace that used a fresh clone. |
| `register` | `instance:register` completed. |
| `copy` | This operation reserved the Instance. |
| `clone` | The Instance is a production clone. |

`source_instance_id` is stored for a development copy only. Production clones keep `clone_candidate_id` and do not copy that id into `source_instance_id`. Deleting the source sets `source_instance_id` to null. `creation` and `copy_mode` stay.

`copy_mode` is `reflink` or `full` for a copy, and null otherwise.

Rows that already exist are backfilled. A row with registration completion is `register`. A row with a clone candidate is `clone`. Every other existing row is `repository`. Existing rows have a null `copy_mode` and a null `source_instance_id`.

The Instance JSON used by create, show, and list includes:

```json
{
  "creation": "copy",
  "copy_mode": "reflink",
  "source_instance": {"id": 12, "name": "default"},
  "shared_databases": [{"slug": "app", "driver": "mysql"}, {"slug": "cache", "driver": "redis"}]
}
```

`source_instance` is null when the id is null. `shared_databases` is empty when the copy has no MySQL, PostgreSQL, or Redis attachment. Human create and show trees add `Creation`, `Copy mode`, `Copied from`, and `URL`. `Copied from` is the source name, or an em dash when the source is null. The human list table does not add these columns.

Human progress for `--from` is `Copy Instance`, `Copying Instance`, and `Copied Instance`. JSON prints the object and no progress text.

## Task workspaces

A [task workspace](/reference/tasks#shared-instance) uses a copy only after the claim has selected a Node. That selection does not change. The source is the Instance named `default` for the Project when it is on that Node and passes [eligibility](#source-eligibility).

The copy fetches `origin` inside the new checkout. It does not fetch in the source. When `refs/remotes/origin/task-{id}` exists after that fetch, the workspace checks it out. Otherwise it points `task-{id}` at `origin/{default_branch}`. It never uses the source's local `HEAD`. The recorded starting commit is that chosen tip. A missing default ref, when the task ref is also absent, or a failed fetch fails the copy. After the checkout, the copy removes untracked files that are not ignored. Ignored files, including `vendor`, `node_modules`, and `.env`, stay. The checkout must then have an empty `git status --porcelain --untracked-files=all` before the copy is accepted.

When `default` is missing or ineligible, or the copy fails before the workspace exists, the provisioner removes an owned partial target and creates the workspace with the current fresh clone. It does not delete a directory the marker does not name. A second removal is a no-op when the marker and the tree are already gone. If the tree is still there and the marker does not name it, removal fails. The task stores:

| Field | Meaning |
| --- | --- |
| `workspace_creation` | `copy` or `repository`. Null before provisioning. |
| `workspace_copy_mode` | `reflink`, `full`, or null. |
| `workspace_fallback_reason` | Null after a copy. Otherwise a stable code. |

The fallback codes are `tasks.workspace_source_unavailable` and `tasks.workspace_copy_failed`. The second is `tasks.workspace_copy_failed: ` followed by the copy error code, for example `tasks.workspace_copy_failed: instance.copy_failed`. `tasks:show` returns all three fields.

A visitable Project still gets its Route. The `orbit` slug still stays `source_resolved` and has no Route. A workspace that already has a checkout is resumed in place and is not copied again.

A reserved copy that stopped before the checkout existed is removed with the marker guard and then copied again, or replaced by a fresh clone on that same row. The clone returns the row to `reserved`, clears its branch and starting commit, drops copied environment values and database attachments, and resets `creation` and `source_instance_id`. When the owned tree cannot be removed, the claim records `tasks.workspace_copy_failed: ` and the error code, leaves the reserved row, and does not create another Instance on top of it.

A copied row already in `checkout_prepared` or `source_resolved` whose checkout is gone takes that same fresh clone. A checkout that is still present is resumed in place, and the resume fills `workspace_creation` and `workspace_copy_mode` when the task does not have them yet. A transient inspection failure while that checkout is still present does not replace those recorded fields.

The [baseline check](/reference/tasks#baseline-check) still runs setup and installs only dependencies that are missing. Cancellation and cleanup are unchanged.

## Errors

The [create refusals](/domains/applications#create-a-development-instance) still apply. A copy also returns:

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.id_invalid` | CLI | `--from` is not a positive integer. |
| `instance.copy_source_missing` | 404 | The source id does not exist. |
| `instance.copy_project_mismatch` | 409 | The source is another Project. |
| `instance.copy_node_mismatch` | 409 | `node_id` is not the source Node. |
| `instance.copy_source_not_development` | 409 | The source is not a development Instance. |
| `instance.copy_source_inactive` | 409 | The source is not `active`. |
| `instance.copy_source_cold` | 409 | The source cold marker exists. |
| `instance.copy_source_layout_invalid` | 409 | The source is a worktree or has linked worktrees. |
| `instance.copy_source_unsafe` | 409 | Git metadata or a SQLite path is unsafe. |
| `instance.copy_source_branch_invalid` | 409 | `HEAD` is detached or is not the recorded branch. |
| `instance.copy_source_dirty` | 409 | A tracked file has an uncommitted change. |
| `instance.copy_root_mismatch` | 409 | `root` does not match the source. |
| `instance.copy_branch_diverged` | 409 | The branch name points at another commit. |
| `instance.copy_source_changed` | 409 | The source `HEAD` moved during the copy. |
| `instance.copy_failed` | 409 | The copy, snapshot, rewrite, or completion step failed. |
| `instance.placement_conflict` | 409 | The retry changes the copy identity. |
| `instance.lifecycle_busy` | 409 | Another request holds this Instance's lifecycle lock. |
| `instance.candidate_required` | 409 | The Node has the active `app-prod` role. |

A failure after the copy starts does not leave the row reserved, unless the lifecycle lock is still held. Cleanup uses the [removal path](#copy-steps) above. An unmanaged directory at the destination is never deleted.

## Limits

A copy stays on one Node. The source checkout is not modified.

- Reflink is attempted first. A plain copy runs only after `EOPNOTSUPP`, `EXDEV`, `EAGAIN`, or `EINVAL`.
- The Node source lock covers the copy. The dependency prune takes that same lock before it deletes `vendor` or `node_modules`.
- Setup runs. A copied `vendor` or `node_modules` makes the matching step cheap.
- The source is not stopped. SQLite consistency comes from snapshots.
- MySQL, PostgreSQL, and Redis servers are shared. Their attachment rows are copied. A SQLite attachment inside the source checkout is not.
- Processes and Schedules on the source are not copied.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One create command

`instance:clone` builds a production home from committed source. A development copy keeps the working tree. Using clone for both would make the dirty-source rule and the production Node rule depend on a flag. So `--from` is an input of `instance:create`.

### Report the path that ran

`--reflink=auto` can copy some files by reflink and the rest in full, and still exit 0. Callers cannot see which happened. `cp -a --reflink=always` either clones every file or fails. `EOPNOTSUPP`, `EXDEV`, `EAGAIN`, and `EINVAL` are the failures that mean a plain copy is the right second attempt. Other failures are not a fallback. `sync -f` on the apps root runs first so a dirty ZFS block is less likely to return `EAGAIN`.

### A cold tree is not a warm tree

The copy exists so `vendor` and `node_modules` are already present. The cold marker means the prune has deleted them. An awake Instance, and an asleep Instance that is not cold, still have those directories. Refusing every awake source would block the tree the copy is for.

### The default Instance on the selected Node

`default` is already the Project's development source. The claim already chose a Node. Moving the claim to chase `default` would change scheduling. A selected Node without an eligible `default` gets a fresh clone, and the task shows why.

### Snapshots, not a quiet source

The source pool stays up, and an awake app can write SQLite during the copy. Replacing the copied database with a snapshot gives the new Instance one consistent file. Stopping the source to get that file was rejected.
