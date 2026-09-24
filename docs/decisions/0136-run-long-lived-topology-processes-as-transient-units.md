---
title: "ADR 0136: Run long-lived topology processes as transient units"
sidebarTitle: "0136 Run long-lived topology processes as transient units"
description: "Proposed. bin/e2e-topology spawn starts a process on a discovery Node as a transient systemd unit and returns at once, logs reads its journal, and kill stops it. exec gains a timeout."
---

# ADR 0136: Run long-lived topology processes as transient units

`bin/e2e-topology spawn` starts a process on a discovery Node as the transient systemd unit `orbit-e2e-{name}.service` and returns at once. `logs` reads the unit's journal with precise timestamps, and `kill` stops the unit. `exec` gains `--timeout`, from 1 to 3600 seconds. A proof agent gets one supported way to keep a watcher running while it acts on the Nodes.

## Status

Proposed.

This extends the discovery commands in [Incus topologies](/reference/incus-topologies#commands). It keeps the exact-argument, orbit-user execution model of `exec`.

## Context

Task group 58's Incus proof needed a viewer that stayed subscribed to a presence channel while the implementer crashed Processes and stopped the agent. The implementer started it with `nohup ... </dev/null >log 2>&1 &` inside `exec`. Incus waits for every process an exec session starts, so each start held `exec` for its full 60 seconds and reported a timeout failure, although the viewer ran. It happened six times in one proof and cost six minutes and six false failures. `exec` also allows only 60 seconds, which is too short for a slow converge or image pull.

## Decision

- `spawn ISSUE NODE NAME --argv=JSON` runs `sudo systemd-run --collect --unit=orbit-e2e-NAME.service` with the orbit user, group, home directory, and environment that `exec` uses, then the given argument vector. It works on discovery topologies only. A name has 1 to 40 lowercase letters, digits, or hyphens.
- `logs ISSUE NODE NAME [--since=TIME] [--lines=N]` prints `journalctl --unit` output in `short-iso-precise` format. The journal outlives the unit, so the output stays readable after the process ends.
- `kill ISSUE NODE NAME` runs `systemctl stop` on the unit.
- `exec --timeout=SECONDS` sets the guest command timeout. The default stays 60. A recorded review action keeps its existing timeout.
- The harness log records every `spawn` and `kill` like an `exec`.

## Rejected alternatives

- Detach inside `exec` with `setsid` or a double fork: rejected because it depends on the caller's shell skill and leaves no record of the output.
- A general process supervisor on the guests: rejected because systemd already supervises, logs, and stops processes.

## Consequences

- A proof can keep watchers, publishers, and load generators running and read their timestamped output as evidence.
- Spawned units end with the topology's VMs at release. A unit with the same name must be killed before it is spawned again.

## Affects

- Components: apps/e2e, apps/docs
- ADRs: extends the discovery commands of [ADR 0005](/decisions/0005-rolling-incus-development-topology)
- Detail: [Incus topologies](/reference/incus-topologies#commands), [proving-on-incus](https://github.com/nckrtl/orbit/blob/main/.agents/skills/proving-on-incus/SKILL.md)
- Verify: `GuestProcessTest` and `TopologyCommandsTest`; spawn, logs, and kill on a discovery topology
