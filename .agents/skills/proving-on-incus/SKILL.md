---
name: proving-on-incus
description: Use when a feature or review must be proved on an Incus topology with bin/e2e-topology, such as running Orbit commands on the Nodes, watching a live process, or recording evidence.
---

# Proving on Incus

Prove a feature on a disposable three-Node topology: `gateway`, `app-dev`, and `app-prod`. The [Incus topology registry](../../../docs/reference/incus-topologies.md) is the reference for every command. This skill covers the working habits that keep a proof fast and its evidence trustworthy.

## Lease the topology

Run `bin/e2e-topology acquire ISSUE .` from the task workspace. The issue must appear in the branch name, so branch `task-58` uses `TASK-58`. A task workspace clone runs through its [bridge worktree](../../../docs/reference/incus-topologies.md#task-workspace-clones). Acquisition takes about a minute.

Keep the lease while you wait for help. A new lease starts again from the promoted snapshot, so it drops every agent, role converge, and fix you applied. Release the topology only when the proof is complete.

## Run commands on a Node

- `exec ISSUE NODE --argv='[...]'` runs one argument vector as `orbit` in `/home/orbit` and waits for it. It allows 60 seconds; pass `--timeout=SECONDS` for slower work such as a converge, up to 3600.
- Prefix root work with `sudo`, for example `["sudo","systemctl","stop","orbit-agent"]`. Wrap a pipeline in `["sh","-c","..."]`. Guests have no `incus` command.
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

`spawn` returns at once. `logs` prints the output with precise timestamps and keeps working after the process ends, so it doubles as timestamped evidence. Stop a process with `kill`; `pkill -f` inside `exec` can match and kill its own shell.

## Change code on the topology

The worktree is mounted read-write into `gateway` and `app-dev`, so an edited file is live there at once. Run `sync` only after a migration or when a guest helper script changed. It runs the full readiness check and takes minutes.

A task workspace clone reaches the Nodes through its bridge worktree, and every harness command copies the clone's changes into the bridge first. Run any command, such as `status`, to push an edit.

## Converge instead of reprovisioning

- `orbit node:add NODE` refuses a Node that owns Instances, which includes `app-dev` and `app-prod`. Converge a role instead: `orbit node:role:add NODE ROLE --converge`.
- A converge rewrites the files Orbit owns on that Node. It undoes any manual patch to them, so reapply the patch or fix the cause in code.

## Keep the evidence honest

- Do not work around a product gap on the Nodes, for example with `/etc/hosts` entries or hand-installed packages. A workaround hides the failure the proof exists to catch. Record the gap as a finding and ask for help when it blocks you.
- Record the date and time before and after each action, for example with `["date","-Ins"]`. Timing claims such as "within one second" need both ends.
- Record every limitation, manual patch, and skipped check in the evidence summary.
