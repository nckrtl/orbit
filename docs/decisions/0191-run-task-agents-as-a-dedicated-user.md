---
title: "ADR 0191: Run task agents as a dedicated user"
sidebarTitle: "0191 Task agents as orbit-worker"
description: "In progress. Task agents run as the Linux user orbit-worker. The managed user keeps the GitHub token, and Gateway git disables hooks and fsmonitor."
---

# ADR 0191: Run task agents as a dedicated user

Task agents run as the Linux user `orbit-worker`. The Node's managed user keeps the GitHub token, and Gateway `git` disables hooks and filesystem monitors so the checkout cannot read that token.

## Status

In progress.

Principle: this decision serves [security fits the real threat model](/mission#principles).

## Context

The Pi server runs on the Node as a systemd Process, and its sessions are the task agents. The managed user is the account that SSHs in, owns development checkouts, and runs `git` with a GitHub token in its environment. A hook or `fsmonitor` program in the checkout is a child of that `git` and sees the token. Running the agent as that user would hand it the token. The [GitHub App reference](/reference/github-app#what-the-app-does-not-cover) describes the enforced boundary.

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

When `id orbit-worker` succeeds, prepare and inspect of a development checkout run `setfacl` on that checkout, including `.git`:

- An access ACL of `u:orbit-worker:rwX` and `u:{managed user}:rwX`.
- A default ACL with those two entries.

`rwX` grants execute on directories and on files that already have execute. Group write and other write stay off. The ACL is not applied to the apps root or to the managed home. When the account is absent, prepare sets no ACL and succeeds. When `setfacl` fails, prepare fails with `instance.clone_failed` and inspect fails with `instance.source_identity_invalid`. Neither records a new checkout.

Files either user creates stay readable and deletable by the other user through the default ACL. Tool-output directories are mode `0770` and tool-output files are mode `0660`, so the ACL mask stays open. The server does not chmod a directory to `0700` or a file to `0600`, and it does not chmod a directory it does not own. A symlink is still refused.

### Checks

The task check, its status and cancel, the workspace snapshot, and baseline setup commands run as `orbit-worker`. The Gateway still connects as the managed user, writes `.git/orbit/check` as that user, and starts the process with `sudo -n -u orbit-worker -H`. The check command runs in a login shell at the workspace root.

When the account is missing, the check does not start. The task asks for assistance with the reason `The Node has no orbit-worker user.` When sudo cannot switch, the reason is `The managed user cannot run commands as orbit-worker.`

Create-time `instance:setup` stays the managed user. Task workspaces do not use that path. Teardown stays the managed user, including on a task workspace, because the installed helper is under that user's home and removal deletes the tree as that user.

### Gateway git

Every `git` the Gateway runs in a development checkout, and every `git_read` command, disables hooks and filesystem monitors:

- `core.hooksPath` is `/dev/null`.
- `core.fsmonitor` is empty.

`GitReadEnvironment` carries both keys in `GIT_CONFIG_*` for every `git_read`, with or without a token. With a token the count is 5: the authorization header, the two `insteadOf` rewrites, then these two keys. Without a token the count is 2 and the preamble exports them. A command that calls `git` directly passes the same two `-c` options. That includes checkout, commit, rev-parse, push, fetch, and the check script.

The token stays in the environment of that one `git` process. It is not written to `.git/config`, a credential helper, or a file on the Node. Clone uses `--no-checkout`. Fetch and push do not check out file contents. The node agent's libgit2 reads stay as they are.

### Host setup and beast

[Pi server](/reference/pi-server#host-setup) is the host procedure: create the user, set home modes, install `acl`, and install the binary and token as `orbit-worker`. [Roll out orbit-worker on beast](/reference/pi-server#roll-out-orbit-worker-on-beast) is the cutover for the Node that already runs Pi as the managed user. Deploy the Gateway that implements this decision before switching the Process user. Apply the ACL to existing checkouts before the new Process starts.

`/home/orbit-worker` is mode `0700`. When the apps root is outside the managed home, that home is mode `0700`. When the apps root is inside it, that home is mode `0711`, so `orbit-worker` can traverse to the apps root, and private directories such as `.ssh`, `.config`, and `.pi` stay mode `0700`.

On a host where task agents run `incus`, add `orbit-worker` to `incus-admin`. beast is such a host.

### Limits

`incus-admin` is root-equivalent. A member can start a privileged container, read any home, and observe another user's process. Mode `0700` homes are the policy for a worker who is not in that group. They are not a hard wall on beast, where `orbit-worker` is in the group so agents can run `incus`.

An agent is a process of the Pi server and shares its user. It can read the server token at `/home/orbit-worker/.pi/agent/orbit-token` and the provider sign-in in that home. This decision does not give each agent a separate user.

A clean or smudge filter named in the checkout runs as the user who invoked `git`. `git checkout` and the approval commit run as the managed user, without the token in the environment. A filter can read the managed home. Git has no single key that disables every named filter. Hooks and `fsmonitor` do not have this gap.

## Rejected alternatives

- Keep the agent as the managed user and rely on the prompt. The agent can read the home and can install a hook that runs as that user with the token.
- One Unix user per task. Each account needs its own Pi sign-in, and the Node runs one Pi server Process per task.
- Run the Pi server as root and drop to `orbit-worker` inside each session. A fault in that path is root. The Process `user` is the drop.
- Deny the agent write access to `.git`. Tool output and the agent's commits write there. The Gateway turns hooks off instead.
- Store the token in a credential helper on the Node. The helper remains after the command, and the agent can call it.
- A user namespace that makes the agent the owner of the tree. Every checkout needs a second mount. The ACL keeps one tree both users can edit.

## Consequences

- The operator creates `orbit-worker` before task agents run. A Node without the account serves development Instances and cannot start a task check.
- An existing Pi Process is destroyed and created again with `--user=orbit-worker` after the Gateway change is deployed. Create has no update.
- `orbit-worker` on beast is in `incus-admin`, so the home boundary there is policy.
- Agents can read the Pi server token and the provider sign-in.
- Checkout filters can run as the managed user. The token is not in that command's environment.
- The unit file is mode `0644`. `User=` is not a secret.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk
- ADRs: none
- Detail: [Pi server](/reference/pi-server#install-on-a-node), [Tasks](/reference/tasks#shared-instance), [GitHub App](/reference/github-app#what-the-app-does-not-cover), [Processes and schedules](/reference/processes-and-schedules#owners)
- Verify: Process `user` tests for the resolver, the systemd unit, the API, the CLI, and `CreateProcessRequest`; source lifecycle ACL tests; removal ownership tests; check runner and turn receipt tests; `GitReadEnvironment` preamble tests; `apps/pi-server/tests/tool-output.test.ts`
