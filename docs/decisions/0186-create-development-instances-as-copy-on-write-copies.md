---
title: "ADR 0186: Create development Instances as copy-on-write copies"
sidebarTitle: "0186 Development Instance copies"
description: "In progress. instance:create --from copies a warm development Instance on the same Node, reports reflink or full, and falls back to a plain copy."
---

# ADR 0186: Create development Instances as copy-on-write copies

A development Instance may be created as a copy of another development Instance on the same Node. The command is `instance:create --from`. The Gateway tries a reflink first and falls back to a plain copy. `instance:clone` stays the only way to create a production Instance.

## Status

In progress.

Principle: this decision serves [one way, one name](/mission#principles). A development copy is `instance:create` with `source_instance_id`, and `instance:clone` stays the only production path. `copy_mode` records whether that copy used a reflink or a plain copy. It is not a second command.

## Context

Creating a development Instance clones the Git repository and then runs the Project setup list. A warm development Instance already holds installed dependencies, built assets, and SQLite data. Copying that checkout is faster than building it again. On a filesystem with block cloning, such as OpenZFS on beast, the copy shares blocks. On any other filesystem the same command still has to work.

[Instance cloning](/reference/instance-cloning) is the production path. It checks out committed source and refuses a dirty candidate. Reusing that command for a development working tree would give one name to two operations.

The group brief and the implementation subtasks already fix the fallback, the warm-source rule, SQLite snapshots, setup, the new branch, failure cleanup, and task-workspace placement. This record settles the names, the dirty-source rule, the reset list, where `copy_mode` is stored, and which Instance is the Project's copy source.

## Decision

The Gateway owns development copies. The contract is [Instance copies](/reference/instance-copies).

### Names

`orbit instance:create <project> <node> <name> --from=<id>` creates the copy. The API remains `POST /api/v1/instances` and adds `source_instance_id`. There is no `instance:copy` command and no clone route for this operation. `instance:clone` stays production-only. A copy onto an `app-prod` Node returns `instance.candidate_required`.

### Copy mode

The Gateway runs `sync -f` on the apps root, then `cp -a --reflink=always` with `LC_ALL=C`. Exit 0 stores `copy_mode` `reflink`. `cp` exits 1 for every error, so the Gateway reads stderr. Fallback errnos are `EOPNOTSUPP`, `EXDEV`, `EAGAIN`, and `EINVAL`, matched by their `LC_ALL=C` strerror text. OpenZFS returns `EAGAIN` on a dirty block when `zfs_bclone_wait_dirty` is 0, and `EINVAL` on a short clone. On a fallback errno the Gateway deletes the owned partial tree and runs `cp -a` without `--reflink`. Exit 0 stores `copy_mode` `full`. Any other `cp` failure is `instance.copy_failed`. `--reflink=auto` is not used, because its exit status stays 0 when a file is copied in full. `full` means the required reflink command was not available. Orbit does not write a probe file. A probe that clones a newly written file misreports on ZFS 2.2 and 2.3, where `zfs_bclone_wait_dirty` defaults to 0.

### Branch default

The copy's own branch is the new Instance name, created at the source `HEAD`. `--branch` selects another name and still points it at that `HEAD`. A local or remote-tracking ref of the chosen name at another commit is refused. The source repository is not modified. The branch is stored as `branch_override`. A task workspace is the exception: after the copy it fetches `origin` and points `task-{id}` at the Project's default branch tip.

### Dirty source and warmth

Untracked files and ignored files are copied, including `vendor`, `node_modules`, built assets, and `.env`. A staged or unstaged change to a tracked file is refused. The source must be an active development checkout on its recorded branch. A worktree layout is refused. A cold source, one whose cold marker exists, is refused. An awake source is accepted. Orbit does not stop its Processes or Schedules.

### Runtime files and snapshots

The copy deletes `public/hot`, log files, framework cache files, and the Vite caches under `node_modules`. It rewrites `bootstrap/cache` and every stored value whose source checkout path ends at `/` or at the end of the value, and whose source domain is a whole host. An absolute symlink uses that same path rule. Keys that exist only in the copied `.env` are imported into stored configuration so a sync keeps them. Each SQLite database is snapshotted from the source file at the same relative path, not from the copied bytes, using the transfer seeder's `mode=ro` backup. The scan skips `.git`, `vendor`, and `node_modules`. MySQL, PostgreSQL, and Redis attachments stay shared. A SQLite attachment whose path is inside the source checkout is not copied. The Project setup steps then run.

### Failure

The Gateway reserves the Instance row, then refuses an existing destination, and only then writes an ownership marker. The marker proves ownership only for a directory created after that write. An identical retry may reuse a marker that already names the same Instance id and path. Activation deletes the marker, so a transfer does not carry one. A failure after the copy starts uses the existing create removal path, including the PHP-FPM pool and the Route projection once completion has reserved them. A retry returns `instance.lifecycle_busy` while another request holds the Instance lifecycle lock. An identical retry returns the Instance only when that copy is already complete.

### Task workspace source

The copy source is the Project's Instance named `default` on the Node the claim already selected. Claim ordering does not change. The workspace fetches `origin` and sits on `task-{id}` at the default branch tip, not at the source's local `HEAD`. When that source is missing, ineligible, or the copy fails before the workspace exists, provisioning uses a fresh clone and stores `workspace_fallback_reason` on the task. `tasks:show` returns `workspace_creation`, `workspace_copy_mode`, and that reason.

## Rejected alternatives

- A new `instance:copy` or `instance:clone` path for development: `instance:clone` is production-only, and a second command is a second way to create a development Instance.
- Refuse a filesystem that cannot reflink: the group brief requires a normal copy everywhere else. A silent `--reflink=auto` was rejected because the exit status cannot show which path ran.
- A reflink probe before the copy: on ZFS 2.2 and 2.3 a clone of a newly written file can fail while a clone of clean checkout files succeeds.
- An overlay mount or a hardlink tree: a mount changes the checkout Doctor already treats as a directory. Hardlinks let an in-place write change the source.
- Keep the source branch name: the copy and the source would share one branch. The Instance name is a new branch at the source `HEAD`.
- Refuse an awake source, or accept a cold one: a cold checkout has lost `vendor` and `node_modules`. An awake checkout is the warm tree the copy exists to keep.
- Leave SQLite as the reflinked `db`, `-wal`, and `-shm` files: a write during the copy can tear them. Snapshots replace those files.
- Drop `.env` and sync stored keys only: keys that exist only in the file would disappear. The copy keeps the file and imports those keys.
- Skip setup: the create path runs the Project setup list, and a copied tree makes that list cheap.
- Delete any directory found at the destination on retry: a repository create never deletes an unmanaged checkout. The copy deletes a tree only when its ownership marker names that Instance id.
- Move a claim onto the Node that hosts `default`: node selection stays as it is. The copy runs only when the selected Node already holds that Instance.
- Point `task-{id}` at the source's local `HEAD`: the task base commit would follow unpublished commits on `default`. The workspace uses the fetched default branch tip.
- Store a development source in `clone_candidate_id`: that column is the production clone candidate. A development copy stores `source_instance_id`.

## Consequences

- `instance:create` gains `--from`. The API body gains `source_instance_id`. The Instance response gains `creation`, `copy_mode`, and `source_instance`.
- Implementation regenerates OpenAPI with `bin/docs-openapi`, MCP tools with `bin/mcp-tools`, SDK fixtures with `bin/api-fixtures`, and the CLI contract check with `bin/cli-contract`. This documentation change regenerates `docs/generated/context.json` only.
- Block cloning covers OpenZFS 2.2, 2.3, and 2.4, including ZFS 2.4 on beast, and other filesystems that implement `FICLONE`, such as XFS with reflink and btrfs. Orbit does not convert an existing disk. ZFS 2.4 defaults `zfs_bclone_wait_dirty` to 1. ZFS 2.2 and 2.3 default it to 0.
- Subtask 696 teaches the dependency prune to take the Node source lock before it deletes `vendor` or `node_modules`. The prune does not take that lock today. The copy holds it, so a sweep cannot empty the source during the copy.
- Task claims keep their current Node choice. A workspace records a copy or a fresh clone, and a fallback records why.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: none
- Detail: [Instance copies](/reference/instance-copies)
- Verify: Gateway tests for each refusal, the reflink and plain-copy paths, `copy_mode`, stderr errno matching, an absolute `public/storage` symlink retarget, SQLite snapshots read from the source, path and domain rewrites, setup, owned-tree removal, and a refused unmanaged directory; task tests for an unchanged claim, the fetched default branch tip, and each fallback reason; CLI and SDK contracts for `--from`; `composer docs-build` and `composer docs-lint`
