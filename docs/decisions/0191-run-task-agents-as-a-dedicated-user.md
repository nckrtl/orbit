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

The managed home also holds SSH keys. When the Pi server runs as `orbit-worker`, the provider sign-in moves to that user's home. The [node agent](/reference/node-agent#reading-git) already reads task checkouts with libgit2, which starts no hook, filter, or `fsmonitor` program. Gateway `git` follows the same rule for hooks and `fsmonitor`.

## Decision

The Gateway owns the account boundary. The operator creates `orbit-worker` on the host. Orbit does not create the account during `node:add`.

### Account

`orbit-worker` is a normal login user with home `/home/orbit-worker`, shell `/bin/bash`, and no sudo. The Pi server Process runs as this user. Every Pi session on that Node runs as this user, including a session in a development Instance. The server token and the provider sign-in live in that home.

The managed user remains the SSH account. Node bootstrap gives it passwordless sudo. The check runner uses `sudo -n -u orbit-worker -H --`. `orbit-worker` has no sudo.

### Process user

A Node systemd Process accepts `user`. The CLI flag is `--user`. The API field and the `CreateProcessRequest` argument are `user`. The name matches a managed user: one letter or underscore, then at most 31 letters, digits, underscores, or hyphens. The Process stores it, and the unit's `User=` is that name. When `user` is omitted, `User=` stays the derived account.

Instance Processes, Docker Processes, presets, and Project definitions reject `user`. The CLI returns `process.option_invalid` and sends no request. A name that fails the pattern returns `process.user_invalid` and sends no request. The API returns HTTP 422 and names the field `user`.

The default working directory stays `/home/{managed user}`. It does not follow `user`. The `pi-server` Process sets `--working-directory=/home/orbit-worker`. JSON for a Process includes `user`, and it is null when the Process uses the derived account. A missing account does not fail create. Start fails with `process.start_failed`.

### Workspace ACL

Development checkouts stay owned by the managed user and group. Removal's ownership check reads that directory and its parent, not each file inside it.

When `id orbit-worker` succeeds, prepare and inspect grant `orbit-worker` and the managed user `rwX` on the work tree. `rwX` grants execute on directories and on files that already have execute. Group write and other write stay off. The same ACL covers `.git/objects`, `.git/refs`, `.git/logs`, and `.git/orbit`, including as the default ACL on those directories. It does not cover the `.git` directory itself, `.git/config`, or `.git/hooks`, so the agent cannot replace Git configuration or hooks. The Gateway creates `.git/orbit` while it prepares the checkout. The ACL is not applied to the apps root or to the managed home. When the account is absent, prepare sets no ACL and succeeds. When `setfacl` fails, prepare fails with `instance.clone_failed` and inspect fails with `instance.source_identity_invalid`. Neither records a new checkout.

Files either user creates stay readable and deletable by the other user through the default ACL. Tool-output directories are mode `0770` and tool-output files are mode `0660`, so the ACL mask stays open. The server does not chmod a directory to `0700` or a file to `0600`, and it does not chmod a directory it does not own. A symlink is still refused.

### Checks

The task check, its status and cancel, the workspace snapshot, and baseline setup commands run as `orbit-worker`. The Gateway still connects as the managed user, writes `.git/orbit/check` as that user, and starts the process with `sudo -n -u orbit-worker -H`. The check command runs in a login shell at the workspace root.

When the account is missing, the check does not start. The task asks for assistance with the reason `The Node has no orbit-worker user.` When sudo cannot switch, the reason is `The managed user cannot run commands as orbit-worker.`

Create-time `instance:setup` stays the managed user. Task workspaces do not use that path. That setup runs before any agent has written the checkout.

Task teardown runs as `orbit-worker`. Its command is `/usr/local/lib/orbit/e2e-task-cleanup`, owned by root, mode `0755`. `orbit-worker` can execute that file and cannot write it. A teardown command inside the checkout, or on a path `orbit-worker` can write, also runs as `orbit-worker`. The managed user does not run it.

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
- Deny the agent write access to all of `.git`. Tool output and commits write under `.git/objects`, `.git/refs`, and `.git/orbit`. Configuration and hooks stay unwritable.
- Clear only hooks and `fsmonitor`. Git 2.55 executes `core.alternateRefsCommand` during fetch, and that program inherits the token. The private git directory is the isolation.
- Store the token in a credential helper on the Node. The helper remains after the command, and the agent can call it.
- A user namespace that makes the agent the owner of the tree. Every checkout needs a second mount. The ACL keeps one tree both users can edit.

## Consequences

- The operator creates `orbit-worker` before task agents run. A Node without the account serves development Instances and cannot start a task check.
- An existing Pi Process is destroyed and created again with `--user=orbit-worker` after the Gateway change is deployed. Create has no update.
- `orbit-worker` on beast is in `incus-admin`, so the home boundary there is policy.
- Agents can read the Pi server token and the provider sign-in.
- Token-bearing `git` uses a private git directory. Checkout programs, including filters and teardown, run as `orbit-worker`.
- The Pi cutover copies `<id>.orbit.json` and `*_<id>.jsonl` into the new session directory and checks each open thread's `external_id` before the scheduler starts. The server does not migrate those files.
- The unit file is mode `0644`. `User=` is not a secret.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: none
- Detail: [Pi server](/reference/pi-server#install-on-a-node), [Tasks](/reference/tasks#shared-instance), [GitHub App](/reference/github-app#what-the-app-does-not-cover), [Processes and schedules](/reference/processes-and-schedules#owners)
- Verify: Process `user` tests for the resolver, the systemd unit, the API, the CLI, and `CreateProcessRequest`; source lifecycle ACL tests; removal ownership tests; check runner and turn receipt tests; `GitReadEnvironment` preamble tests; `apps/pi-server/tests/tool-output.test.ts`
