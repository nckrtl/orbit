---
title: "ADR 0191: Run task agents as a dedicated user"
sidebarTitle: "0191 Task agents as orbit-worker"
description: "In progress. Task agents run as orbit-worker. Token-bearing git uses a private git directory, and checkout programs do not run as the managed user."
---

# ADR 0191: Run task agents as a dedicated user

Task agents run as the Linux user `orbit-worker`. Token-bearing `git` uses a private git directory the agent cannot write, and programs named by the checkout run as `orbit-worker`, not as the managed user.

## Status

In progress.

Principle: this decision serves [security fits the real threat model](/mission#principles).

## Context

The Pi server runs on the Node as a systemd Process, and its sessions are the task agents. The managed user is the account that SSHs in, owns development checkouts, and runs `git` with a GitHub token in its environment. Git 2.55 executes `core.alternateRefsCommand` from the shell during fetch, and that child inherits `GIT_CONFIG_VALUE_0`, the `Authorization` header. Hooks and `fsmonitor` are the same class of program. Running the agent as the managed user would hand it the token. The [GitHub App reference](/reference/github-app#what-the-app-does-not-cover) describes the enforced boundary.

The managed home also holds SSH keys. When the Pi server runs as `orbit-worker`, the provider sign-in moves to that user's home. The [node agent](/reference/node-agent#reading-git) already reads task checkouts with libgit2, which starts no program. Token-bearing Gateway `git` does not use the checkout as its git directory.

## Decision

The Gateway owns the account boundary. The operator creates `orbit-worker` on the host. Orbit does not create the account during `node:add`.

### Account

`orbit-worker` is a normal login user with home `/home/orbit-worker`, shell `/bin/bash`, and no sudo. The Pi server Process runs as this user. Every Pi session on that Node runs as this user, including a session in a development Instance. The server token and the provider sign-in live in that home.

The managed user remains the SSH account. Node bootstrap gives it passwordless sudo. The check runner uses `sudo -n -u orbit-worker -H --`. `orbit-worker` has no sudo.

### Process user

A Node systemd Process accepts `user`. The CLI flag is `--user`. The API field and the `CreateProcessRequest` argument are `user`. The name matches a managed user: one letter or underscore, then at most 31 letters, digits, underscores, or hyphens. The Process stores it, and the unit's `User=` is that name. When `user` is omitted, `User=` stays the derived account.

Instance Processes, Docker Processes, presets, and Project definitions reject `user`. The CLI returns `process.option_invalid` and sends no request. A name that fails the pattern returns `process.user_invalid` and sends no request. The API returns HTTP 422 and names the field `user`.

The Gateway checks the named account over SSH with `getent passwd`. It refuses `root` and accounts with UID zero. An absent account or an invalid account home fails create with `process.user_unavailable`. The default working directory is the named account's home. An explicit working directory overrides that default. When `user` is omitted, the default stays `/home/{managed user}`. JSON for a Process includes `user`, and it is null when the Process uses the derived account. The user is stored in `runtime_config` and is part of identity matching.

### Workspace ACL

Development checkouts stay owned by the managed user and group. Removal's ownership check reads that directory and its parent, not each file inside it.

`ORBIT_TASKS_WORKER_USER` selects the worker account, normally `orbit-worker`. When it is unset, checkout access stays unchanged so Nodes can roll over one at a time. When `id` finds the configured account, prepare runs `setfacl -R` on the checkout, including `.git`. The access ACL and the default ACL each name the worker and the managed user with `rwX`. Default ACLs are applied in a separate pass before worker write access. A combined `setfacl` call writes access before inheritance. If it stops between the writes, the worker can create a private subtree that the managed user cannot remove. `rwX` grants execute on directories and on files that already have execute. Inspection and retries repair managed-user-owned entries; worker-owned entries keep their inherited ACLs because only their owner can change them. `.git/config` and `.git/hooks` are read-only for the worker. Other write stays off. The Gateway creates `.git/orbit` during prepare with mode `0775`. The ACL is not applied to the apps root or to either home. When the account is absent, prepare sets no ACL and succeeds. When `setfacl` fails, prepare fails with `instance.clone_failed` and inspect fails with `instance.source_identity_invalid`. Neither records a new checkout.

`git add`, `git checkout`, and `git commit` create `index.lock` in the `.git` directory and rename it to `index`. They also create `HEAD.lock`, `packed-refs.lock`, `ORIG_HEAD`, `FETCH_HEAD`, and `COMMIT_EDITMSG` there. A grant that skips the `.git` directory fails those commands. The checkout root is writable, so `orbit-worker` can rename `.git` and replace it. Leaving `.git/config` or `.git/hooks` out of the ACL does not keep those files. The ACL is not the trust boundary.

`orbit-worker` runs `git` in a checkout the managed user owns. Git 2.55 refuses that repository before it reads config or runs a command. The check compares the directory owner. The ACL does not change the owner, so the baseline fails without a trust entry.

Prepare and inspect add the checkout's absolute path to `safe.directory` in `orbit-worker`'s global Git config. Git 2.55 reads that key only from protected config: system, global, or the command line. A value in the checkout's `.git/config` is ignored. The value is that path, not `*`. Removal deletes that one value. The primary checkout and each bridge worktree get the same entry, because teardown and the harness run `git` there as `orbit-worker`.

The managed user writes under `.git/orbit` only when `.git` and `.git/orbit` are directories owned by that user and are not symbolic links. A replaced `.git` fails that write, and the task asks for assistance. The managed user does not follow a symlink in that tree. Token-bearing `git` uses the private git directory and does not read the checkout's `.git`.

Files either user creates stay readable and deletable by the other user through the default ACL. Tool-output directories are mode `0770` and tool-output files are mode `0660`, so the ACL mask stays open. The server does not chmod a directory to `0700` or a file to `0600`, and it does not chmod a directory it does not own. A symlink is still refused.

### Checks

The task check, its status and cancel, the workspace snapshot, and baseline setup commands run as `orbit-worker`. The Gateway still connects as the managed user. It writes `.git/orbit/check` as that user only when `.git` and `.git/orbit` are directories that user owns and not symbolic links, and it starts the process with `sudo -n -u orbit-worker -H`. The check command runs in a login shell at the workspace root.

When the account is missing, the check does not start. The task asks for assistance with the reason `The Node has no orbit-worker user.` When sudo cannot switch, the reason is `The managed user cannot run commands as orbit-worker.`

Create-time `instance:setup` stays the managed user. Task workspaces do not use that path. That setup runs before any agent has written the checkout.

Task teardown runs as `orbit-worker`. Its command is `/usr/local/lib/orbit/e2e-task-cleanup`, owned by root, mode `0755`. `orbit-worker` can execute that file and cannot write it. A teardown command inside the checkout, or on a path `orbit-worker` can write, also runs as `orbit-worker`. The managed user does not run it.

The helper and `bin/e2e-clone-bridge` read the invoking user's registry, then `/var/lib/orbit/e2e-primary-checkouts/`. That directory is root-owned and mode `0755`. `orbit-worker` can read its symlinks and cannot replace them. Before teardown runs as `orbit-worker`, copy each symlink from the managed user's `$HOME/.local/state/orbit/e2e-primary-checkouts/` into that directory. Do not change the primary's owner.

A primary is accepted when its owner is the invoking user, or the owner of the invoking checkout. The second case is a primary the managed user registered. A missing registration still exits without changes. A copied registration must not take that path. A primary owned by neither user stays ignored. [Primary registration](/reference/instance-setup#primary-registration) is the procedure.

Reading the registry is not enough to remove a bridge. `orbit-worker` must traverse every parent of the primary checkout and of its worktree root. That root is `orbit.worktreeRoot`, and it defaults to `/fast/worktrees/orbit`. The primary's `.git` gets the same `rwX` ACL as a task checkout, including the directory root, so `worktree remove` can create locks. The worktree root is writable by `orbit-worker`, and each existing bridge under it has that recursive ACL. The default ACL on the worktree root keeps a new bridge removable. The grant does not cover the managed home or its private directories.

Privileged removal is separate. After teardown returns, the managed user deletes the tree, checks directory ownership, and removes routes. That path runs no program from the checkout.

### Gateway git

A `git` command that carries a GitHub token does not use the checkout as its git directory. The Gateway writes a private git directory, owned by the managed user, mode `0700`, with no ACL for `orbit-worker`. It copies `objects` and `refs` from the checkout as files. It does not copy `config` or `hooks`, and it does not run `git` with the checkout as `--git-dir` to make that copy. The private config contains only the remote URL the Gateway writes.

The same command sets these keys, so a checkout config opened by mistake cannot name a program. Git 2.55 runs `core.alternateRefsCommand` from the shell during fetch, and the child inherits `GIT_CONFIG_VALUE_0`. An empty value replaces a value from the checkout:

- `core.hooksPath` is `/dev/null`.
- `core.fsmonitor`, `core.alternateRefsCommand`, `core.sshCommand`, `core.askPass`, `core.gitProxy`, `credential.helper`, and `uploadpack.packObjectsHook` are empty.

`GitReadEnvironment` exports the credential keys and then these isolation keys for every `git_read`, with or without a token. A direct `git` passes the same `-c` options. The token stays in that one process. It is not written to `.git/config`, a credential helper, or a file on the Node. Clone uses `--no-checkout`. Fetch and push do not check out file contents.

When the private config contains any other key whose value Git executes, the Gateway does not run `git`. The task asks for assistance. The check reads the config file as text.

`git checkout`, `git commit`, and other commands that do not carry the token run as `orbit-worker` once the workspace ACL exists. A filter or other program they start runs as `orbit-worker`. Create-time source resolution, before any agent has run in the checkout, may run as the managed user. The node agent's libgit2 reads stay as they are and start no program.

### Host setup and beast

[Pi server](/reference/pi-server#host-setup) is the host procedure: create the user, set home modes, install `acl`, and install the binary and token as `orbit-worker`. [Roll out orbit-worker on beast](/reference/pi-server#roll-out-orbit-worker-on-beast) is the cutover for the Node that already runs Pi as the managed user. Deploy the Gateway that implements this decision before switching the Process user. Apply the ACL to existing checkouts before the new Process starts.

`/home/orbit-worker` is mode `0700`. When the apps root is outside the managed home, that home is mode `0700`. When the apps root is inside it, that home is mode `0711`, so `orbit-worker` can traverse to the apps root, and private directories such as `.ssh`, `.config`, and `.pi` stay mode `0700`.

On a host where task agents run `incus`, add `orbit-worker` to `incus-admin`. beast is such a host.

### Limits

`incus-admin` is root-equivalent. A member can start a privileged container, read any home, and observe another user's process. Mode `0700` homes are the policy for a worker who is not in that group. They are not a hard wall on beast, where `orbit-worker` is in the group so agents can run `incus`.

An agent is a process of the Pi server and shares its user. It can read the server token at `/home/orbit-worker/.pi/agent/orbit-token` and the provider sign-in in that home. This decision does not give each agent a separate user.

## Rejected alternatives

- Keep the agent as the managed user and rely on the prompt. The agent can read the home and can install a hook that runs as that user with the token.
- One Unix user per task. Each account needs its own Pi sign-in, and the Node runs one Pi server Process per task.
- Run the Pi server as root and drop to `orbit-worker` inside each session. A fault in that path is root. The Process `user` is the drop.
- Leave `.git` unwritable, or omit `.git/config` and `.git/hooks`. `git commit` cannot create `index.lock`, and a writable checkout root can rename `.git` anyway. Trust is who runs the program.
- Clear only hooks and `fsmonitor`. Git 2.55 executes `core.alternateRefsCommand` during fetch, and that program inherits the token. The private git directory is the isolation.
- Store the token in a credential helper on the Node. The helper remains after the command, and the agent can call it.
- A user namespace that makes the agent the owner of the tree. Every checkout needs a second mount. The ACL keeps one tree both users can edit.
- Treat the ACL as Git's ownership check. Git 2.55 compares the directory owner, and an ACL does not change it. A scoped `safe.directory` entry is the trust.

## Consequences

- The operator creates `orbit-worker` before task agents run. A Node without the account serves development Instances and cannot start a task check.
- An existing Pi Process is destroyed and created again with `--user=orbit-worker` after the Gateway change is deployed. Create has no update.
- `orbit-worker` on beast is in `incus-admin`, so the home boundary there is policy.
- Agents can read the Pi server token and the provider sign-in.
- Token-bearing `git` uses a private git directory. Checkout programs, including filters and teardown, run as `orbit-worker`.
- The Pi cutover saves `process:list` and `ORBIT_PI_TOKEN` before the new token file is installed. It copies session files and checks each open `external_id` before the scheduler starts. Rollback before destroy starts the stopped Process. Rollback after destroy lists the Node and removes every `pi-server` row, including `failed` and `provisioning`, then confirms the name is absent, restores the saved token, and creates the saved spec.
- `orbit-worker`'s global Git config lists each task checkout, the primary, and each bridge as `safe.directory`. The value is not `*`.
- Existing primary-checkout registrations are copied to `/var/lib/orbit/e2e-primary-checkouts/` before teardown runs as `orbit-worker`.
- The unit file is mode `0644`. `User=` is not a secret.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, packages/php-sdk
- ADRs: none
- Detail: [Pi server](/reference/pi-server#install-on-a-node), [Tasks](/reference/tasks#shared-instance), [GitHub App](/reference/github-app#what-the-app-does-not-cover), [Processes and schedules](/reference/processes-and-schedules#owners)
- Verify: Process `user` tests for the resolver, the systemd unit, the API, the CLI, and `CreateProcessRequest`; source lifecycle ACL tests, including a managed-owned checkout that `orbit-worker` cannot use until its path is in global `safe.directory`; removal ownership tests; check runner and turn receipt tests; `GitReadEnvironment` preamble tests; `apps/pi-server/tests/tool-output.test.ts`; `bin/e2e-task-cleanup` and `bin/e2e-clone-bridge` tests for a shared registration whose primary is owned by the checkout owner
