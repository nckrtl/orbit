---
title: "Instance setup and teardown"
description: "How a Project stores named setup and teardown commands, and when Orbit runs them for a development Instance."
covers:
  - apps/gateway/app/Domain/Projects/{LifecyclePhase,LifecycleStep,ProjectLifecycleRunner,ProjectLifecycleStepStore}.php
  - apps/gateway/app/Actions/*/{Create*InstanceAction,CopyInstanceDependenciesAction,RegisterInstanceAction,RunInstanceSetupAction}.php
  - apps/gateway/app/Infrastructure/{*/NativeDevelopment*Provisioner,Instances/{RemoteDevelopmentInstanceConfigurator,RemoteDevelopmentInstanceSourceLifecycle,RemoteRegistrationSourceManager,RemoteInstanceDestinationGuard,RemoteInstanceDependencyCopier},AppDev/DevelopmentSshExecutor,AppProd/ProductionSshExecutor}.php
  - apps/gateway/app/Domain/Instances/{DevelopmentInstanceProvisioner,InstanceSourceProfileGuard,DependencyCopy/InstanceDependencyCopier}.php
  - apps/gateway/app/Http/Controllers/Api/ProjectLifecycleStepsController.php
  - apps/gateway/app/Models/ProjectLifecycleStep.php
  - apps/gateway/resources/instances/lifecycle.py
  - apps/cli/app/Commands/Instances/{SetupInstanceCommand,*SetupStepCommand,*TeardownStepCommand}.php
---

# Instance setup and teardown

A Project stores two ordered lists of named commands: setup steps and teardown steps. Orbit runs the setup list after it activates a new development Instance, and the teardown list before it removes an active development Instance. Setup does not require a Route: an unrouted monorepo `default` becomes `active` and runs the same list. Its setup commands can install dependencies in each subproject without application metadata inspection at the repository root.

Production Instances run neither list. They use [deploy steps](/reference/deployments#deploy-steps). A Project's [development deploy steps](/reference/deployments#development-deploy-steps) are a third, independent list. Setup and teardown do not run that list.

Each step is one database row with a name, a command string, a timeout, and a position. Orbit writes no script into the checkout. The lists belong to the Project, and no Instance keeps a copy. The next run uses the lists as they are at that moment.

## Record a step

Record the steps on the Project before you create an Instance. `--project` selects the Project by its numeric ID.

```bash
orbit instance:setup-step:create install-php --project=4 --command='composer install --no-interaction'
orbit instance:setup-step:create install-js --project=4 --command='vp install' --after=install-php
orbit instance:teardown-step:create drop-sqlite --project=4 --command='rm -f database/database.sqlite'
```

| Command | Result |
| --- | --- |
| `instance:setup-step:create NAME --project=ID --command=COMMAND` | Add a setup step at the end, or at `--before=NAME` or `--after=NAME`. |
| `instance:setup-step:list --project=ID` | List the setup steps in order. |
| `instance:setup-step:update NAME --project=ID` | Change `--command`, `--timeout`, or the position. |
| `instance:setup-step:destroy NAME --project=ID` | Remove a setup step. The others keep their order. |
| `instance:teardown-step:*` | The same four commands for the teardown list. |

`destroy` asks for confirmation with No selected. `--yes` confirms without a prompt and is required for JSON and noninteractive calls.

The Gateway checks each change against these limits and stores nothing when one fails.

| Field | Rule |
| --- | --- |
| `name` | 1 to 63 lowercase letters, digits, or hyphens. It starts and ends with a letter or digit. Unique within the list. |
| `command` | Nonempty UTF-8, at most 16 KiB, no NUL byte. |
| `timeout_seconds` | 1 to 540. The default is 240. |
| `before`, `after` | The name of a step in the same list. Use at most one. |

A list holds at most 32 steps. The timeouts of one list add up to at most 540 seconds, so a whole list fits in one API request. The Gateway stores a step only when its timeout is inside that limit, and a later read returns the stored timeout.

Authorized reads return the commands. [Activity](/cli/activity) records no input for the step commands and `instance:setup`, so it never holds command text or command output.

## Checkout access

The Gateway prepares a development checkout as the Node's managed user. The checkout directory stays owned by that user and group. Production releases are unchanged.

When `ORBIT_TASKS_WORKER_USER` names an account on the Node, normally `orbit-worker`, prepare grants recursive access and default ACLs on the checkout, including `.git`. Both ACLs name the worker and the managed user with `rwX`. Prepare and inspect finish the default ACLs before granting worker write access. A partial access grant then still lets the managed user write and remove entries the worker creates. Execute is granted on directories and on files that already have it. Other write stays off.

Inspection and retries repair entries the managed user owns; entries the worker owns keep their inherited ACLs. They exclude [task temp directories](/reference/tasks#project-check) from all grants, including populated caches in the common repository and other linked-worktree administration directories. A pruned traversal replaces the recursive access-grant fast path so private caches never acquire sharing ACLs during reinspection. Linked worktrees also grant access to their administration directory and the shared repository's refs and objects, while its configuration and hooks remain read-only for the worker. The Gateway creates `.git/orbit` during prepare with mode `0775`. `.git/config` and `.git/hooks` are read-only for the worker. See the [beast checkout grant](/reference/pi-server#roll-out-orbit-worker-on-beast).

`git add`, `git checkout`, and `git commit` create `index.lock` in `.git` and rename it to `index`. They also create `HEAD.lock`, `packed-refs.lock`, `ORIG_HEAD`, `FETCH_HEAD`, and `COMMIT_EDITMSG` there. The `.git` directory has to be writable. The checkout root is writable too, so `orbit-worker` can rename `.git` and replace it. An ACL that skips `.git/config` or `.git/hooks` does not keep those files.

Git 2.55 also refuses the checkout because the managed user owns it. The ACL does not change that owner, so a worker `git` command fails closed until the path is trusted. Prepare and inspect add the checkout's absolute path to `safe.directory` in `orbit-worker`'s global Git config. Git reads that key only from protected config, so a value in `.git/config` does not count. The value is that path, not `*`. Removal deletes that one value and preserves entries for other checkouts.

If deletion finishes before Git trust cleanup, completion revalidation authenticates the removal journal and receipt, then retries that exact-path cleanup before reporting success. A config write failure keeps removal incomplete until the retry succeeds.

The ACL is not applied to the apps root or to either home. New files in the checkout stay readable and deletable by the other user. When the setting is unset or the account does not exist, prepare sets no ACL and succeeds. When `setfacl` fails, prepare fails with `instance.clone_failed` and inspect fails with `instance.source_identity_invalid`. Neither records a new checkout. The managed user writes task metadata under `$(git rev-parse --git-path orbit)`. In a linked worktree this is its private administration directory, not a directory under the `.git` pointer file. The Gateway validates both the pointer and its return path, and pins owned directories before publishing metadata.

[Tasks](/reference/tasks#shared-instance) uses this ACL so task agents can write the workspace. [Host setup](/reference/pi-server#host-setup) creates the account. [Instance removal](/reference/instance-removal#checks-before-removal) still requires the managed user to own the directory.

## Run setup

A create that fails before activation cleans up its owned resources without running setup or teardown, and keeps the original failure code. If cleanup cannot finish or the process is interrupted, removal accepts the pre-activation states `reserved`, `checkout_prepared`, and `source_resolved` and skips teardown. See [pre-activation removal](/reference/instance-removal#pre-activation-removal).

`instance:create` runs the setup list after the Instance and its Route are active, and after the [database clone](/domains/applications#database-clone) when it applies. Setup steps receive `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT`, naming the successful default release selected on the same Node. Project steps own the [dependency copy](/domains/applications#dependency-copy), including nested monorepo folders. When the Project has no release at source preparation, Orbit records an empty seed decision and setup installs from lock files. Retries keep that decision. Registration records a seed only when the adopted source commit matches the selected default release; it never pairs an older source with newer seed dependencies.

Activation records `failed_step: setup` in the same transaction, so a Gateway interruption before or during setup cannot make an identical create retry report success without setup. Orbit clears the marker only after setup completes.

Each command runs with `bash -eu` at the repository root, even when the Laravel [application directory](/reference/projects#application-directory) is nested. For example, a setup command for root `apps/site/public` must use `cd apps/site && composer install` to install that application's dependencies. Teardown and task-check commands also keep their repository-root scope. Each setup command runs on the Instance's Node, as the Node's managed user. This is the `instance:create` and `instance:setup` path. A task workspace does not use it. The task baseline runs the same commands, also as the managed user, inside the task check. [Project check](/reference/tasks#project-check) describes that run.

Commands read no input. Orbit discards their stdout and retains only a bounded 1 KiB stderr tail internally; public errors do not include command output. When a step ends, for any reason, Orbit kills its process group, so background processes do not survive the step. Each run holds a lifecycle lock on the Instance. If another operation holds that lock during `instance:create`, Orbit keeps the active Instance and records `error_code: instance.lifecycle_busy`.

An identical create retry then reports that setup must run; use `instance:setup` to retry the list. A busy lock never removes the Instance.

The first command that exits non-zero or times out stops the list. Then Orbit rolls back the new Instance:

1. It runs every teardown step.
2. It removes the Instance with forced removal, which also deletes a dirty checkout.
3. It returns `instance.setup_step_failed` with the failed setup step, or `instance.setup_step_unavailable` with `outcome: missing` for a missing or non-executable command (exit 127 or 126).

An unavailable setup error keeps its step, Node, and not-found diagnostic and says the Instance was removed. A failed teardown step is named too.

A teardown failure during this rollback does not keep the Instance. Rollback never removes another Instance.

Orbit keeps the Instance, with its setup marked failed, in three cases:

| Case | Result |
| --- | --- |
| Orbit cannot confirm the setup step's outcome, for example after a lost SSH connection. | No rollback runs. The error is the step's own `instance.setup_step_failed` with `outcome: unconfirmed`. |
| Orbit cannot confirm a teardown step's outcome. | The error adds `cleanup: unconfirmed`. |
| The removal starts but does not finish. | The error adds `cleanup: incomplete` and names `orbit instance:destroy <id> --force`. |

Incomplete or unconfirmed cleanup keeps the setup error's classification and details. When setup was unavailable, the error also keeps its step, Node, and not-found diagnostic alongside the cleanup annotation. Inspect the Instance before you retry.

With `ORBIT_TASKS_WORKER_USER` configured, registration inspection, in-place adoption, and relocation verification run Git content-status checks as that worker. Clean and process filters receive no Gateway credential environment. The managed account checks ownership and moves the source only when its path differs from the managed destination.

[Registration](/domains/applications#register-an-existing-checkout) verifies the source's own state, not unrelated refs in its shared repository. In-place adoption records the verified destination before it closes the environment file's permissions. For a nested Laravel root such as `apps/site/public`, that file is `apps/site/.env`; checks for cached Laravel configuration also use `apps/site/bootstrap/cache/config.php`. Git identity and recursive checkout ACLs keep their repository-wide scope. A retry after that checkpoint checks the destination's Git identity rather than the pre-adoption digest, so Orbit's own permission change does not prevent recovery. Setup still runs only after adoption completes.

It pins the source and Git directory before discovering the common directory, then checks those identities before every read grant. It never resolves a replacement link as a new grant target. Read grants preserve the worker's existing effective permissions, including workspace edits and Git locks. They do not follow links or grant access to either user's home. Parent directories must already be traversable by the worker. Git trusts only the exact checked path for that command. Registration stops if the worker is missing, sudo fails, or Git cannot read the content. It never falls back to the managed account.

`instance:register` runs no setup. `instance:register --setup` runs the setup list after adoption. `instance:setup` runs the list again on an active development Instance. Both keep the Instance when a command fails and return `instance.setup_step_failed`, or `instance.setup_step_unavailable` when the command is missing or not executable. Every run starts at the first step.

```bash
orbit instance:setup <instance>
```

`instance:clone` runs neither list.

## Deadlines

One API request runs a whole list. The request's remote work ends after 570 seconds, and its forward work stops 20 seconds earlier to leave time for cleanup. Each step's timeout is cut to the time that remains.

`instance:create` keeps 150 seconds back from its setup list for rollback: up to 60 seconds for the teardown list and 90 seconds for the removal. `instance:setup` and `instance:register --setup` roll nothing back, so their setup list can use the whole request.

A step that the request deadline stops, or that has no time left to start, is not a failed command. The request returns `command.deadline_exceeded` (HTTP 504) with `outcome: deadline` and the step name. `instance:create` still rolls back and keeps that code. Lower the step timeouts until the list fits.

## Run teardown

`instance:destroy` of an active development Instance runs the teardown list after the [removal checks](/reference/instance-removal) accept the source. Then Orbit checks the source again and deletes the Route, the source, and the record. In a forced removal of a checkout with worktrees, each member runs its own teardown list.

Teardown may delete ignored files. It must keep the checkout, its Git identity, and its worktrees. When teardown changes tracked files, normal removal refuses. Retry with `--force` to discard them.

The first teardown command that exits non-zero or times out stops the removal. The Route, source, and record stay, and the command returns `instance.teardown_step_failed` with the step name. A missing or non-executable command (exit 127 or 126) returns `instance.teardown_step_unavailable` instead, with `outcome: missing`. The message names the step and its Node and says the command was not found or is not executable. Setup uses the same distinction with `instance.setup_step_unavailable`. Orbit retains a bounded stderr tail internally, without including command output in the public error. Fix or destroy the step, then run `instance:destroy` again.

## Bootstrap the Orbit repository

The Orbit Project can record `bin/bootstrap` as a setup step, or record its locked dependency installs as separate steps. It installs the locked dependencies, seeds caches, and runs the checks in all five Composer projects. A cold bootstrap can exceed the request deadline.

```bash
orbit instance:setup-step:create bootstrap --project=PROJECT_ID --command='bin/bootstrap' --timeout=540
```

Task workspaces are not created with `instance:create`, so this create-time run does not happen for them. The task baseline check runs the Project setup steps before the task check instead. See [Project check](/reference/tasks#project-check) and [Implementation loop](/reference/implementation-loop).

## Beast setup changes after deployment

These are operator steps on shared beast, not task fixtures. Re-read Projects 33 (`orbit-website`) and 46 (`orbit`) and their ordered setup and development deploy lists before changing them. Preserve their Instance-specific environment, key and database setup. Finish initializing Orbit's default Instance 303 without a Route, and deploy the default of each Project successfully before using it as a seed.

### Project 33

Record ordered [development deploy steps](/reference/deployments#development-deploy-steps) for the website's locked Composer and Bun installs, migrations, and asset build. Keep installs, migrations, and builds required. Do not put Instance-only environment, key, or database-copy setup in this list. Read the default Instance's ID from `orbit project:show 33 --json`, then run `orbit instance:deploy ID --json` and check its final result before enabling seed copies.

Add a first setup step that copies the website's `vendor` and `node_modules` from `ORBIT_SEED_PATH` when it is nonempty. Use `cp -a --reflink=auto` into absent destinations. Keep the locked incremental install afterward. An install that deletes the destination first discards the copy.

### Project 46

Record ordered development deploy steps for every locked dependency tree: the repository root, `apps/cli`, `apps/gateway`, `apps/docs`, `apps/web`, `packages/php-sdk`, `apps/e2e`, and `packages/agent-annotation`. Run the Gateway migrations and the builds required by the deployed applications after their installs. Keep these steps required, and put cache warm-up last. Preserve Instance-only setup in the setup list. Retry `orbit instance:create 46 9 default --json` to finish the existing SourceResolved Instance 303 without a Route; confirm its identity and active status, then run `orbit instance:deploy 303 --json` and check the final result.

Add a first setup step that copies present `vendor` and `node_modules` folders at the repository root and under `apps/cli`, `apps/gateway`, `apps/docs`, `apps/web`, `packages/php-sdk`, `apps/e2e`, and `packages/agent-annotation`. Include each Composer project's `.orbit-tia`. Pint's `vendor/pint.cache` and PHPStan's `vendor/phpstan/cache` travel with `vendor`. Keep locked installs for every dependency tree as the empty-seed fallback. Never copy `.env`, databases, logs, build runtime files or symlinks that point outside the seed.

### Cache ownership

Register Orbit's stable default store with `bin/tia-cache register --repository=/fast/apps/orbit/default --development-instance=303`. Add `bin/tia-cache warm` as the final Project 46 development deploy step, with an explicit timeout and required or best-effort policy. Provision Gateway access for the managed user's `orbit instance:deploy 303 --json`, which the cache worker uses for background refresh.

Deploy 303 again. Inspect all five cache publications and create a disposable new Instance to check nested dependency copies. Test the empty-seed fallback separately. Point any `ORBIT_MAIN_CACHE_STORE` override at `/fast/apps/orbit/default/.git/orbit-tia/v1`.

Stop the old ext4 cache worker before retiring `/home/nckrtl/orbit/.git/orbit-tia/v1/checkout` and its separate `repository`. Preserve unresolved failure logs. Do not remove the old main repository or any unrelated linked worktree.

A setup copy step must be retryable: keep an existing destination, stage a missing directory, then rename it into place only when `cp` succeeds. A copy failure fails that step rather than leaving a partial dependency tree. On ZFS, `--reflink=always` can enforce shared blocks; `--reflink=auto` provides the ordinary-copy fallback. Verify shared blocks on beast and write isolation by changing a copied dependency or cache in a disposable Instance and checking that the selected seed stays unchanged. Remove only that disposable Instance after the check.

## Configure Orbit's task policy

Orbit's task check is explicitly `composer check`; new Project defaults do not supply it. Record setup steps for each dependency tree that this repository check needs. The baseline runs only those steps. The live Orbit Project is id 46. Re-read it before every deployment, and stop if the id, check, or lists differ from the handoff.

### Preflight

These reads change nothing. Run them against the Gateway that will receive the deployment.

```bash
orbit project:show 46 --json
orbit instance:setup-step:list --project=46 --json
orbit instance:teardown-step:list --project=46 --json
orbit node:list --json
orbit project:excluded-node:list --project=46 --json
orbit node:excluded-project:list --node=NODE --json
```

Replace `NODE` with each candidate. An eligible Node is active, has the `app-dev` role, is not in the Project exclusion list, and does not exclude Project 46. Install the helper on every eligible Node, including one that has no task checkout yet. A later workspace can land there.

Confirm `task_check` is `composer check`. Confirm each stored `timeout_seconds` is an integer from 1 to 540. A new list totals at most 540 seconds. A list stored before that cap may total more, and a later change may not raise its total.

The task baseline runs each setup step with that step's own timeout, outside the API request deadline. `instance:create`, `instance:setup`, and `instance:destroy` run one whole list inside one request. Remote work ends at 570 seconds, and forward work stops 20 seconds earlier. `instance:create` holds 150 seconds back: 60 for teardown and 90 for removal.

A setup list whose timeouts cannot fit is cut with `command.deadline_exceeded`. Lower those timeouts before relying on `instance:create` or `instance:setup` for this Project. Do not raise the total.

### Install the helper

Install the reviewed `bin/e2e-task-cleanup` as `$HOME/.local/lib/orbit/e2e-task-cleanup` for the managed user on every eligible Node. Copy that blob from the reviewed commit. Do not copy it from an old checkout: clones created before this change do not contain the file. Create-time rollback runs teardown as the managed user, before any agent has written the checkout. Task-workspace teardown runs as `orbit-worker`. Its command is the absolute path below, not `$HOME` and not a file in the checkout.

Save the reviewed blob first. A failed `git show` must not start the copy. Stage that blob, check that it is non-empty and that its digest matches, and only then rename it onto the destination in the same directory. `set -o pipefail` makes a failed producer fail the copy.

A short or empty stream fails the remote checks. The rename does not run, and the trap removes the stage. The previous helper stays in place. A rename in the same directory is one replacement, so a crash does not leave a half-written destination. Replacing the destination before the digest check was rejected because an interrupted copy can destroy a helper that was already valid.

```bash
set -o pipefail
rev=REVIEWED_SHA
blob=$(mktemp)
trap 'rm -f -- "$blob"' EXIT
git show "$rev:bin/e2e-task-cleanup" > "$blob" || exit 1
test -s "$blob"
expected=$(sha256sum "$blob" | awk '{print $1}')
ssh MANAGED_USER@NODE "EXPECTED=$expected bash -eu -c 'install -d -m 0755 -- \"\$HOME/.local/lib/orbit\"
dir=\$HOME/.local/lib/orbit
stage=\$dir/e2e-task-cleanup.stage
dest=\$dir/e2e-task-cleanup
rm -f -- \"\$stage\"
trap \"rm -f -- \\\"\$stage\\\"\" EXIT INT TERM HUP
cat > \"\$stage\"
test -s \"\$stage\"
digest=\$(sha256sum \"\$stage\" | awk \"{print \\\$1}\")
test \"\$digest\" = \"\$EXPECTED\"
chmod 0755 -- \"\$stage\"
mv -f -- \"\$stage\" \"\$dest\"
trap - EXIT'" < "$blob"
```

If the client loses the response, do not delete the destination and do not treat the loss as a failed install. Read the file back and compare it with the reviewed blob:

```bash
ssh MANAGED_USER@NODE 'sha256sum "$HOME/.local/lib/orbit/e2e-task-cleanup"'
git show "$rev:bin/e2e-task-cleanup" | sha256sum
```

A match means the home copy finished. It does not prove the root path. Publish by staging in the root directory, checking that stage, and renaming onto the live path. `orbit-worker` must be able to execute the live file and must not be able to write it. `install` onto the live path was rejected: an interrupted `install` can replace a valid helper with a short file.

```bash
ssh MANAGED_USER@NODE "sudo env EXPECTED=$expected bash -eu -c '
install -d -o root -g root -m 0755 -- /usr/local/lib/orbit
dir=/usr/local/lib/orbit
stage=\$dir/e2e-task-cleanup.stage
dest=\$dir/e2e-task-cleanup
rm -f -- \"\$stage\"
trap \"rm -f -- \\\"\$stage\\\"\" EXIT INT TERM HUP
cat > \"\$stage\"
test -s \"\$stage\"
digest=\$(sha256sum \"\$stage\" | awk \"{print \\\$1}\")
test \"\$digest\" = \"\$EXPECTED\"
chown root:root -- \"\$stage\"
chmod 0755 -- \"\$stage\"
mv -f -- \"\$stage\" \"\$dest\"
trap - EXIT'" < "$blob"
```

The stage and the live file are in one directory, so `mv` is one replacement. A failed digest check does not run `mv`. The trap removes the stage. The previous live helper stays in place.

If the client loses the response, do not delete the live path and do not treat the home digest as success. Read the live file back and compare it with the reviewed blob:

```bash
ssh MANAGED_USER@NODE 'sudo sha256sum /usr/local/lib/orbit/e2e-task-cleanup'
git show "$rev:bin/e2e-task-cleanup" | sha256sum
```

A match on that live path means the publication finished. A missing live file, a different live digest, or a leftover `/usr/local/lib/orbit/e2e-task-cleanup.stage` is not a completed handoff. Remove the leftover stage and run the publication again. A missing home file is the same for the home copy: remove `$HOME/.local/lib/orbit/e2e-task-cleanup.stage` and run that install again. Keep the old Gateway until the live-path readback matches on every eligible Node.

The helper returns success without changing anything for an ordinary checkout. For a task checkout it removes only that task's matching bridge, unused bridge branch, and staging ref. It preserves the checkout and its own Git identity. See [Task workspace clones](/reference/incus-topologies#task-workspace-clones) for ownership and retry rules. Clones created before deployment use this installed copy too. The helper and the old Gateway hook may coexist during the handoff because both are idempotent.

### Primary registration

The installed helper and `bin/e2e-clone-bridge` look up the primary in three places, in order. First is `$XDG_STATE_HOME/orbit/e2e-primary-checkouts/{origin key}` when `XDG_STATE_HOME` is set. Second is `$HOME/.local/state/orbit/e2e-primary-checkouts/{origin key}`. Third is `/var/lib/orbit/e2e-primary-checkouts/{origin key}`.

The third directory is root-owned and mode `0755`. `orbit-worker` can read the symlinks and cannot replace them. The primary's owner must be the invoking user, or the owner of the invoking checkout. A primary owned by neither user is ignored. A missing registration exits successfully and changes nothing. That success is only for a checkout with no primary. A registration copied to the shared directory must be found.

Copy the managed user's links before task teardown runs as `orbit-worker`. Do not change the owner of the primary checkout. On the same filesystem, copy into a staging directory, check every link, and rename the directory into place. When `/var/lib/orbit/e2e-primary-checkouts` already exists, stop and do not merge over it.

```bash
src=$HOME/.local/state/orbit/e2e-primary-checkouts
sudo install -d -o root -g root -m 0755 -- /var/lib/orbit/e2e-primary-checkouts.migrate
if [ -d "$src" ]; then
  find "$src" -maxdepth 1 -type l -exec sudo cp -P {} /var/lib/orbit/e2e-primary-checkouts.migrate/ \;
fi
```

For each staged link, `readlink` equals the source link, and the target directory exists. A broken link is not copied. `sudo mv` the staging directory to `/var/lib/orbit/e2e-primary-checkouts` only after that check. A rename on the same filesystem is one replacement. When the copy is interrupted, delete the staging directory and copy again. The source directory stays in place.

The copied link is not enough. `orbit-worker` must walk every parent of the primary and of its worktree root. That root is the primary's `orbit.worktreeRoot`, and the default is `/fast/worktrees/orbit`. Grant `orbit-worker` and the managed user `rwX` on the primary's `.git`, including the directory root, with the same default ACL a task checkout gets. Grant write and execute on the worktree root, and the same recursive ACL on each existing bridge directory under it. Set the default ACL on the worktree root so a new bridge stays removable. Do not grant this on the managed home or on `.ssh`, `.config`, or `.pi`.

Add the primary's absolute path, and the absolute path of each bridge, to `safe.directory` in `orbit-worker`'s global Git config. Use the checkout path itself, not `*`. Teardown and `bin/e2e-clone-bridge` run `git` in those trees as `orbit-worker`, and Git 2.55 rejects them while the managed user owns them.

### Record teardown

Record the step only after the helper digest matches. Create stores the default timeout of 240 seconds. That is inside the 1 to 540 limit, and it is the whole teardown list, so the list total fits. `instance:create` rollback still has only 60 seconds for teardown; the runner cuts the step to the time that remains. The helper is a short Git operation. Do not run these commands against the live Project until the disposable proof has been repeated there on purpose.

```bash
orbit instance:teardown-step:create task-e2e-bridge --project=46 --command='/usr/local/lib/orbit/e2e-task-cleanup' --json
```

When that name already exists, update it instead. Update stores the same command and an explicit 240 second timeout.

```bash
orbit instance:teardown-step:update task-e2e-bridge --project=46 --command='/usr/local/lib/orbit/e2e-task-cleanup' --timeout=240 --json
```

Read the list after either command:

```bash
orbit instance:teardown-step:list --project=46 --json
```

If the client loses the response, run that list again. Do not guess from the lost call. Retry create only when the step is absent. A second create of an existing name fails and leaves the stored row unchanged. Run update when the step is present but the command or timeout differs. Stop when the name is `task-e2e-bridge`, the command is `/usr/local/lib/orbit/e2e-task-cleanup`, and `timeout_seconds` is 240. Do not deploy the Gateway release that deletes this hook until that read matches.

### After deployment

Verify the Orbit Project's explicit check and install steps on a cold fixture, and verify teardown on a task fixture, including a checkout that has no `bin/e2e-task-cleanup` of its own. Retry a retained failed-removal fixture and confirm its bridge and checkout are removed in order. Release its topology before removal. Keep CI, merge approval, and this deployment handoff as separate gates. Do not change live Project configuration as part of task planning.

### Rollback

If the new Gateway is not deployed yet, remove the step and then the installed file. The old hook remains.

```bash
orbit instance:teardown-step:destroy task-e2e-bridge --project=46 --yes --json
orbit instance:teardown-step:list --project=46 --json
ssh MANAGED_USER@NODE 'rm -f -- "$HOME/.local/lib/orbit/e2e-task-cleanup"'
```

If the destroy response is lost, list again. Retry destroy only when the step is still present. The list is empty when the rollback of the step finished. If the new Gateway is already deployed, keep the helper and the step until the previous Gateway is restored. The new Gateway has no built-in bridge hook, so removing them leaves task bridges behind. Restore the previous Gateway first, re-read the teardown list, and only then destroy the step and delete the file.

## Failure codes

These codes name the step that failed. The sections above say whether the Instance stays.

| Code | Cause |
| --- | --- |
| `instance.setup_step_failed` | A setup command failed or timed out. |
| `instance.teardown_step_failed` | A teardown command failed or timed out during `instance:destroy`. |
| `instance.setup_step_unavailable` | A setup command was not found or is not executable on the Node; `outcome: missing`. |
| `instance.teardown_step_unavailable` | A teardown command was not found or is not executable on the Node; `outcome: missing`. |
| `instance.setup_unavailable` | `instance:setup` targets an Instance that is not an active development Instance. |
| `command.deadline_exceeded` | The request deadline stopped a step. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Named rows, not a script

Each command is its own row, so a failure names one step and you can reorder steps without rewriting a script. One script body and a path in the repository were rejected: the failure report could name no step, and the operator records the command on the Project.

### Lists on the Project

The commands run for every Instance of a repository, so they live on the Project. Per-Instance copies were rejected because they would drift from what the operator recorded. The command family is `instance:` because the commands run for an Instance.

### A failed setup removes the new Instance

A half-set-up Instance is not a useful result of `instance:create`. So a confirmed failure tears it down and removes it, and the next create starts clean. Resuming a create after a failed setup was rejected.

### Registration skips setup by default

Registration adopts a checkout that is usually set up already. Running setup on every registration was rejected. `--setup` runs it on request.

### Teardown failure stops removal

Teardown is the operator's cleanup. Removal continues only after that cleanup succeeds.

### The Project removes its own bridge

Orbit's task bridge is this repository's cleanup, so the Orbit Project runs `bin/e2e-task-cleanup` as a teardown step. A Gateway hook for that bridge was rejected, because another Project would inherit Orbit's worktree layout. The installed helper, not the Gateway, performs the ownership checks in [Task workspace clones](/reference/incus-topologies#task-workspace-clones).

### Task setup runs as orbit-worker

Create-time setup runs as the managed user, before an agent uses the Instance. A task workspace skips that path. Its baseline runs the setup list as the managed user inside the task check, so host-dependent setup and tests keep that user's access. [The candidate gate runs as the managed user](/reference/pi-server#the-candidate-gate-runs-as-the-managed-user) records the cost. Task teardown runs as `orbit-worker`, and its program is the root-owned helper. Privileged removal then deletes the tree as the managed user and runs no checkout program.
