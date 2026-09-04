# Schedules

This page tells operators how the Gateway stores a recurring command, derives its execution context, and projects it to systemd on a managed Node.

## Schedule intent

The Gateway stores each Schedule as intent for exactly one Node or AppInstance target.

| Value | Contract |
| --- | --- |
| Identity | One universally unique identifier (UUID) identifies the Schedule and all three artifacts. Numeric database keys are not public identities. |
| Name | A bounded lowercase name is unique within the target. |
| Target | Exactly one Node or AppInstance owns the purpose of the Schedule. Workspace is not a Schedule target. |
| Calendar | One bounded native systemd calendar controls the timer. The installed Node validates it with `systemd-analyze calendar`. |
| Command | One bounded command runs from a protected script. The Gateway does not put it in an infrastructure argument. |
| Timeout | One bounded duration limits the oneshot service. |
| Installation | The Schedule records the installed Node and an opaque generation for its current projection. |
| Completion | The Schedule keeps only bounded metadata for the latest accepted completion. It stores no output or run history. |

An identical add retry resumes the same Schedule when its prior operation can continue. A different specification with the same target and name returns a conflict. The Gateway does not edit, rename, enable, disable, or move a Schedule. An operator removes the old Schedule and adds the replacement.

## Execution context

The Gateway derives the installed Node, operating-system user, and working directory from current authoritative placement.

| Target | Installed Node | User | Working directory |
| --- | --- | --- | --- |
| Node | The target Node | The Node's managed Orbit user | The managed user's home |
| AppInstance on an app-dev Node | The AppInstance's current Node | The managed app-dev runtime user | The immutable recorded checkout path |
| AppInstance on an app-prod Node | The AppInstance's current Node | The App's dedicated production user | The operator-owned production home |

A caller supplies none of these derived values. The Gateway rejects a target whose placement, state, role, runtime, or Cluster context cannot run the Schedule. The Gateway also refuses target deletion, installed-Node removal, and changes to the target Node or execution identity while the Schedule exists.

## Systemd artifacts

One active Schedule generation owns exactly three artifacts whose paths and names derive only from its UUID.

| Artifact | Behavior |
| --- | --- |
| Script | A protected root-owned script contains the command. The runtime user can read and execute it but cannot write it. The parent directory denies access to other unprivileged users. |
| Service | A root-owned oneshot service sets the derived `User`, derived `WorkingDirectory`, stored timeout, and one fixed `ExecStart` that invokes the protected script. |
| Timer | A root-owned persistent timer uses the validated calendar, remains enabled and active, and triggers the oneshot service. |

The service and timer contain no caller command text. The Gateway never places that text in an SSH, `sudo`, `systemctl`, `journalctl`, or `systemd-analyze` argument. systemd prevents overlapping runs of the same service. A manual run asks systemd to start the service without waiting for it to finish. A log read uses fixed `journalctl` arguments and bounded line, byte, and time limits.

## Installation and rollback

The Gateway treats all three artifacts as one recoverable projection. It holds a database lock for the Schedule and a Node-local operating-system lock for the artifact transaction. It renders the complete candidate, verifies its units with fixed arguments, and snapshots only artifacts already owned by the same Schedule UUID.

An unexpected file, unit, owner, mode, or identity at an owned name causes a conflict. The Gateway does not adopt, overwrite, or delete that object.

After installation, the Gateway reloads systemd, enables and starts the timer, and verifies the unit, calendar, execution context, and timer state. A failure restores the exact prior owned artifacts and timer state. A first-install failure removes only artifacts that attempt created. A rollback failure leaves bounded non-active state and enough ownership evidence for a safe retry. Errors never contain the command, calendar, log, path, user, unit content, credential, raw output, or exception text.

## Completion and removal

The protected script sends a completion with only the Schedule UUID, installation generation, and a bounded success or failure classification. The Gateway accepts it only from the recorded installed Node for the current generation. Stale, duplicate, foreign-Node, and post-replacement completions do not change stored state.

Removal first marks the Schedule as removing, disables and stops only its timer, and prevents later triggers. It lets an active service finish. The Gateway then removes the exact owned timer, service, and script, reloads systemd, verifies absence, and removes the intent. A failed removal keeps resumable state and never deletes an unrecognized artifact.

## Doctor

Doctor checks Schedule intent and performs bounded read-only inspection on the installed Node. It never installs, reloads, enables, starts, stops, adopts, repairs, completes, or removes a Schedule.

The `schedule` family reports stable bounded issues for these conditions.

| Condition | Doctor result |
| --- | --- |
| Required artifact is absent | Drift |
| Artifact owner or mode differs | Drift |
| Service or timer specification differs | Drift |
| Timer state is inactive or incorrectly enabled | Drift |
| Calendar differs | Drift |
| Runtime user or working directory differs | Drift |
| Completion callback differs | Drift |
| Installed Node placement differs | Drift |
| An artifact exists in the exact Orbit Schedule namespace without intent | Drift |
| The installed Node is unreachable | Unverifiable |
| Inspection is malformed or fails | Unverifiable |

Doctor returns only bounded booleans, enums, identifiers, fingerprints, and fixed sentinels in expected and observed values. It returns no command, calendar, log, path, user, unit content, credential, raw output, or exception text. Doctor changes nothing on the Node. [ADR 0004](../decisions/0004-verify-only-doctor-boundary.md) defines the verify-only report boundary, and [ADR 0013](../decisions/0013-native-systemd-schedule-management.md) defines the Schedule family.

## Limits

The Gateway uses native systemd timing on the installed Node. It does not provide a central scheduler, queue, worker, run-history table, replay, catch-up queue, automatic movement, failover, rebalancing, or high-availability timer replica. The Schedule runtime does not install the public Orbit command-line interface on workload Nodes and does not deploy application code.
