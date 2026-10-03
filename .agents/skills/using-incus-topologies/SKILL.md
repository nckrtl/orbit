---
name: using-incus-topologies
description: Use when exploring or checking behavior on a task group's Incus topology with bin/e2e-topology, such as running Orbit commands on the Nodes, watching a live process, or reproducing a feature in review.
---

# Using Incus topologies

A task group's topology is a disposable three-Node fleet: `gateway`, `app-dev`, and `app-prod`. It is for discovery. The implementer uses it to explore and check behavior while building, and the reviewer reproduces the feature on it. Subtasks do not carry proofs or proof scripts; tests and the review accept the work. The [Incus topology registry](../../../docs/reference/incus-topologies.md) is the reference for every command.

## Use the allocated topology

Orbit acquires the group's topology, `TASK-<group>`, when it provisions an Orbit task workspace, and releases it when it removes the workspace. The implementer and the reviewer share it. Check it with `bin/e2e-topology status TASK-<group>`. Agents and reviewers never acquire or release topologies, receive no sudo, and do not touch the host firewall. When `status` shows no topology, acquiring failed; ask the operator instead of running `acquire`.

Run commands from the task workspace. The issue must appear in the branch name, so branch `task-58` uses `TASK-58`. A task workspace clone runs through its [bridge worktree](../../../docs/reference/incus-topologies.md#task-workspace-clones).

Leave the topology allocated while you wait for help and when you finish. Orbit or the operator owns teardown. Clean up only the disposable processes and fixtures you created within that allocation.

## Run commands on a Node

- `exec ISSUE NODE --argv='[...]'` runs one argument vector as `orbit` in `/home/orbit` and waits for it. It allows 60 seconds; pass `--timeout=SECONDS` for slower work such as a converge, up to 3600.
- Do not use sudo or perform root work. Request operator help when you need a privileged change. Wrap a pipeline in `["sh","-c","..."]`. Guests have no `incus` command.
- Run the Orbit CLI on `gateway`: `["orbit","node:list"]`. The CLI's command names use colons, such as `node:role:add`.
- Give your own Bash tool a timeout longer than the harness command. `sync` can take two minutes.

## Run a long-lived process

Never start a process in the background inside `exec`. Incus waits for every process of the session, so `nohup ... &` holds `exec` open until its timeout and reports a failure even though the process runs.

Use `spawn`, `logs`, and `kill`:

```bash
bin/e2e-topology spawn TASK-58 app-dev viewer --argv='["sh","-c","cd /home/orbit/orbit && bun apps/web/scripts/node-agent-viewer.ts --node=2"]'
bin/e2e-topology logs TASK-58 app-dev viewer --since=-2min
bin/e2e-topology kill TASK-58 app-dev viewer
```

`spawn` returns at once. `logs` prints the output with precise timestamps and keeps working after the process ends, so it doubles as timestamped evidence; add `--record=LABEL` to keep it in the evidence log. Stop a process with `kill`; `pkill -f` inside `exec` can match and kill its own shell.

## Change code on the topology

The worktree is mounted read-write into `gateway` and `app-dev`, so an edited file is live there at once. Run `sync` only after a migration or when a guest helper script changed. A full `sync` runs the readiness check and takes minutes. After a migration, run `sync ISSUE --quick` when you do not need full verification: it proves the mount, installs the guest helpers, and applies the migrations without the readiness probes. Run a full `sync` before you rely on `verify`.

A task workspace clone reaches the Nodes through its bridge worktree, and every harness command copies the clone's changes into the bridge first. Run any command, such as `status`, to push an edit.

## Converge instead of reprovisioning

- `orbit node:add NODE` refuses a Node that owns Instances, which includes `app-dev` and `app-prod`. Converge a role instead: `orbit node:role:add NODE ROLE --converge`.
- A converge rewrites the files Orbit owns on that Node. It undoes any manual patch to them, so reapply the patch or fix the cause in code.

## Keep notes honest

- Do not work around a product gap on the Nodes, for example with `/etc/hosts` entries or hand-installed packages. A workaround hides the failure you are looking for. Record the gap as a finding and ask for help when it blocks you.
- Record what you checked with `--record=LABEL` on `exec` and `logs`, for example `exec TASK-58 gateway --argv='["orbit","node:list"]' --record="node list after crash"`. The harness appends the command, its UTC start and end times in milliseconds, the exit code, and the redacted output to `<worktree>/.e2e/evidence.log`. Cite entries by label in your handoff or review instead of copying output by hand.
- Timing claims such as "within one second" need both ends. Use the recorded start and end times, or record `["date","-Ins"]` before and after an action that the harness does not run.
- Record every limitation, manual patch, and skipped check in your handoff or review.
