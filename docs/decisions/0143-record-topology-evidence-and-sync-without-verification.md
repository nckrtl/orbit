---
title: "ADR 0143: Record topology evidence and sync without verification"
sidebarTitle: "0143 Record topology evidence and sync without verification"
description: "Proposed. exec --record and logs --record append a redacted, timestamped entry to <worktree>/.e2e/evidence.log. sync --quick proves the mount, installs guest helpers, and applies Gateway migrations without readiness verification or a new source binding."
---

# ADR 0143: Record topology evidence and sync without verification

`bin/e2e-topology exec --record=LABEL` and `logs --record=LABEL` append one redacted entry to `<worktree>/.e2e/evidence.log`. The entry holds the label, the Node key, the start and end times in UTC with milliseconds, the command, the exit code, and the output. `sync --quick` proves the mount, installs the current guest helper scripts, and applies pending Gateway migrations. It skips readiness verification and leaves the recorded source binding unchanged.

## Status

Proposed.

This extends the discovery commands in [Incus topologies](/reference/incus-topologies#commands) and the commands of [ADR 0136](/decisions/0136-run-long-lived-topology-processes-as-transient-units).

## Context

Task group 58's Incus proof had two slow manual steps.

First, the implementers recorded proof evidence by hand. For each action they ran `date` before and after the command, copied the command and its output, and pasted all of it into the evidence summary. The harness already knew each value but kept only an exit code and the argv in `<worktree>/.e2e/log`. Hand copies can drop a timestamp, reorder output, or leak a secret that the harness log redacts.

Second, the implementers ran `sync` to push a file edit, although the worktree is mounted live and a file edit needs no `sync`. When a change did need `sync`, such as a new migration, `sync` also ran the full readiness verification. That took 32 to 127 seconds, and one run failed when the unrelated `sample.fixtures` probe timed out. The migration had already applied, but the command reported a failure.

## Decision

- `exec ISSUE NODE --argv=JSON --record=LABEL` runs exactly like `exec`. It then appends one entry to `<worktree>/.e2e/evidence.log`.
- `logs ISSUE NODE NAME --record=LABEL` appends the fetched journal output in the same way. A spawned viewer's output then becomes evidence without a copy.
- An entry is plain text:

  ```text
  === 2026-09-24T06:40:00.123Z viewer after crash node=app-dev exit=0 duration=1234ms end=2026-09-24T06:40:01.357Z
  $ orbit node:list --json
  --- stdout
  ...
  --- stderr
  ...
  ```

  The argv is written as shell-quoted words. The harness redacts the argv, stdout, and stderr with the same `SecretRedactor` that the command log uses.
- The log is append-only. The harness creates it with mode `0600` in the private `.e2e/` directory and refuses a symbolic link in its place.
- A label has 1 to 80 letters, digits, spaces, dots, dashes, or underscores and is not blank. The harness checks it before it touches the worktree or Incus.
- Recording never changes the command's output or exit code. If the append fails, the harness prints a warning on stderr.
- `exec` refuses `--record` together with `--review-action`, because a proof review action has its own record.
- `sync ISSUE --quick` proves the mount, installs the current guest helper scripts on every Node, and applies pending Gateway migrations from mounted source. It skips the readiness probes and, on an extended attempt, the extension converge.
- A quick sync leaves `topology.json` and the guest source marker at the last successful full sync. It prints a note that readiness was not verified. Standalone `verify` therefore still refuses a mount that differs from the recorded binding.
- `verify` and a full `sync` keep their behavior.

## Rejected alternatives

- Record every `exec` automatically: rejected because most commands are exploration, and a proof summary needs chosen, labelled entries.
- Store evidence as JSON: rejected because people read and grep the log during a proof, and a JSON string hides line breaks in output.
- Let `sync --quick` record the new source as an unverified binding: rejected because `status`, `verify`, and the guest source marker would then describe a binding that no readiness check has seen. Keeping the last verified binding is simpler and fails closed.
- Make the readiness probes faster or retry `sample.fixtures`: rejected here because a full `sync` must stay a complete readiness check. Probe reliability is a separate change.

## Consequences

- A proof agent gets timestamped, redacted evidence from the harness and can cite entries by label.
- The evidence log stays with the worktree. `bin/worktree-remove` removes it, so an agent copies what the proof summary needs first. In a task workspace clone, the log is in the bridge worktree.
- The log can hold output that the redactor does not recognize as secret. Treat it like the rest of `.e2e/`.
- After `sync --quick`, readiness is unknown until a full `sync`. A migration that breaks a readiness probe shows only then.

## Affects

- Components: apps/e2e, apps/docs
- ADRs: extends [ADR 0136](/decisions/0136-run-long-lived-topology-processes-as-transient-units) and the discovery commands of [ADR 0005](/decisions/0005-rolling-incus-development-topology)
- Detail: [Incus topologies](/reference/incus-topologies#evidence-log), [proving-on-incus](https://github.com/nckrtl/orbit/blob/main/.agents/skills/proving-on-incus/SKILL.md)
- Verify: `EvidenceLogTest`, `TopologyCommandsTest`, and the quick sync case in `TopologyAcquirerTest`; `exec --record`, `logs --record`, and `sync --quick` on a discovery topology
