# ADR 0013: Manage schedules with native systemd timers

## Status

Accepted on 2026-08-31.

If accepted, this ADR extends
[ADR 0004](0004-verify-only-doctor-boundary.md) with the Schedule model and
Doctor family. It uses the AppInstance, Node, placement, and runtime ownership
defined by [ADR 0009](0009-clustered-app-instance-routing.md) and
[ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md).

## Context

Orbit needs recurring commands for managed Nodes and application runtimes.
The timing and execution must continue when the Gateway is temporarily
unavailable, must use the operating-system identity and working directory of
the selected target, and must leave enough local evidence for bounded logs and
verify-only Doctor inspection.

A central scheduler, queue, worker, or run-history service would make the
Gateway responsible for dispatch timing and would introduce another durable
execution system. Managed Ubuntu Nodes already provide systemd timers,
services, locking, timeouts, restart-independent timing, and journald. Orbit
can store desired Schedule intent in the Gateway while projecting execution to
that native runtime.

The projection crosses a sensitive boundary. An operator-supplied command is
intentionally executable application input, but it must never become part of
an infrastructure command line. AppInstance placement and operating-system
identity are authoritative Gateway state and cannot be supplied by a Schedule
caller. Logs and commands can contain sensitive application data and must not
leak into Activity, Doctor, errors, or generic diagnostics.

Workspace is ending as a product concept under ADR 0009. Schedule therefore
needs exactly two target forms: Node and AppInstance. App-dev and app-prod
AppInstances have different source and runtime ownership, and Schedule must
derive the correct execution context without taking ownership of source,
deployment, or placement.

## Decision

### Store intent in the Gateway and execute on the installed Node

A **Schedule** is persisted Gateway intent with:

- a UUID used as its public and artifact identity;
- a bounded lowercase name unique within its target;
- exactly one Node or AppInstance target;
- one bounded native systemd calendar expression;
- one bounded command string;
- a bounded execution timeout;
- lifecycle state;
- the Node on which its current generation is installed;
- an opaque installation generation; and
- bounded latest-completion metadata.

The target owns the purpose of the Schedule. The installed Node owns the
current systemd projection. These are separate relationships. A Node target is
installed on that Node. An AppInstance target is installed on the Node that
currently owns the AppInstance placement.

The UUID is the only public lookup identity. Numeric database keys are not API
identities. A name collision within the same target is idempotent only when
the complete desired specification is identical and the prior operation is
resumable. A different specification with the same target and name is a
conflict. Orbit does not edit or rename a Schedule in place; a changed
Schedule is removed and added explicitly.

Schedule does not introduce an Orbit-wide target or a Workspace target. It
does not create a queue, central dispatcher, run-history table, or generic
execution framework.

### Derive placement, user, and working directory from the target

Callers choose the target but never provide an installed Node, operating-
system user, home, or working directory. The Gateway derives the complete
execution context from current authoritative placement:

- a Node target runs as that Node's managed `orbit` user from its managed
  home;
- an app-dev AppInstance runs as the managed app-dev runtime user from the
  AppInstance's immutable recorded checkout path; and
- an app-prod AppInstance runs as the App's dedicated production user from
  its operator-owned production home defined by ADR 0011.

The target and installed Node must be active, belong to one compatible
Cluster, and have the role and runtime prerequisites required by that target.
An AppInstance's effective web root is not a caller-selectable Schedule
working directory.

An installed Schedule does not follow a placement change automatically.
Deleting its target, removing its installed Node, or changing the target's
Node or execution identity is refused while the Schedule exists. The operator
removes the Schedule, changes placement, and adds it again. Automatic
reinstallation, movement, failover, and rebalancing require a later decision.

### Project one protected script, service, and timer

Each Schedule generation owns exactly three executable artifacts on its
installed Node:

1. one protected command script at an Orbit-owned path derived only from the
   Schedule UUID;
2. one oneshot systemd service whose unit name is derived only from that UUID;
   and
3. one systemd timer for that service, also named only from the UUID.

The script is root-owned, readable and executable by the derived runtime user,
and not writable by that user. Its parent directory and mode prevent another
unprivileged user from reading or replacing it. The service and timer units
are root-owned and contain no caller command text.

The service sets the derived `User` and `WorkingDirectory`, applies the stored
timeout, and invokes only the protected script through a fixed `ExecStart`.
The operator command exists only as script content. It is never interpolated
into SSH, `sudo`, `systemctl`, `journalctl`, `systemd-analyze`, or another
infrastructure argument vector.

The timer uses the validated calendar, is enabled and started, and is
persistent so one missed calendar boundary can be recovered after Node
downtime. systemd owns trigger timing and prevents overlapping executions of
the same oneshot service. A manual run asks systemd to start that same service
non-blockingly; it does not create another execution path.

The protected wrapper records no command output in the Gateway. On exit it
sends a node-authenticated completion callback containing only the Schedule
UUID, installation generation, and bounded success or failure classification.
The callback transport is a fixed part of the Schedule projection and does
not require the public Orbit CLI on workload Nodes.

### Validate and converge as one recoverable projection

The Gateway validates names, UUIDs, command size, timeout, target, placement,
and authorization before remote mutation. It validates the calendar with a
fixed `systemd-analyze calendar` argument vector on the installed Node so the
acceptance decision matches the target systemd version.

Schedule convergence holds a database lock for the Schedule and a Node-local
operating-system lock for the unit transaction. It renders all expected
artifacts before installation, validates the candidate units with fixed
`systemd-analyze verify` arguments, and snapshots only exact artifacts already
owned by the same Schedule UUID.

An unexpected file, unit, owner, mode, or identity at an owned name is a
conflict. Orbit does not adopt, overwrite, or delete it. After installing a
candidate, Orbit performs `daemon-reload`, enables and starts the timer, and
verifies the exact unit, calendar, context, and active timer state before the
Schedule becomes active.

If any step fails, Orbit restores the exact prior owned artifacts and timer
state, reloads systemd, and returns a bounded error. A first installation
removes only the new artifacts it created. A matching retry resumes the same
intent without creating a duplicate Schedule or unit set. A failed rollback
leaves the Schedule explicitly non-active and preserves the evidence required
for a safe retry; it never reports the candidate as installed.

Infrastructure execution uses closed fixed argument vectors. The Schedule
UUID selects owned paths and unit names; caller values never select arbitrary
paths, users, units, or commands.

### Keep the public lifecycle small

The closed Schedule operation set is:

- **list** authorized Schedule summaries;
- **add** one validated Schedule and converge its native projection;
- **show** one authorized Schedule by UUID;
- **run** the installed oneshot service non-blockingly;
- **logs** read bounded journald output for the exact service unit;
- **complete** accept a generation-bound completion from the installed Node;
  and
- **remove** stop future triggers and remove owned intent and artifacts safely.

There is no edit, rename, enable, disable, generic command, or numeric lookup
operation. Strict request parsing distinguishes omitted input from explicit
invalid input and rejects duplicate, escaped-duplicate, unknown, or wrongly
typed members before mutation.

Manual run and timer execution share the same service, timeout, user, working
directory, journald unit, and completion path. Logs use a fixed `journalctl`
argument vector and are bounded by accepted line, byte, and time limits. The
Gateway does not persist logs or raw command output.

Removal first marks the Schedule as removing, disables and stops only its
timer, and prevents any later trigger. It does not kill an already active
service. When no service is active, Orbit removes the exact owned timer,
service, and script, reloads systemd, verifies their absence, and removes the
intent. If a service is active, its script and service remain until the
generation-bound completion arrives or a later removal retry observes it
finished; cleanup then completes. Failure leaves explicit resumable removal
state and never deletes an unrecognized artifact.

Completion is monotonic and generation-bound. Only a callback whose active
peer is the recorded installed Node and whose generation equals the installed
generation can update completion state. A stale, duplicate, foreign-Node, or
post-replacement callback cannot overwrite newer state. Completion stores no
command, output, or run history.

### Authorize by the target and contain sensitive values

Every public Schedule operation uses the existing active-peer boundary and
explicit Node access policy. A collection returns only Schedules whose target
Node the caller may address. AppInstance authorization resolves through its
current authoritative Node and Cluster placement. Add, run, logs, and remove
require their explicit Schedule permissions; access to one Node does not grant
fleet-wide Schedule visibility.

The completion operation is not an operator operation. It is authorized only
for the recorded installed Node and creates no Activity. The other six
operations create one normal sanitized Activity with operation identity,
Schedule UUID, target identity, result, and request ID as applicable. Activity
never contains command text, calendar contents, logs, output, paths, users,
unit contents, credentials, or raw remote results.

Command text and journald output are sensitive operator data. List omits
command text. Show and logs return only bounded values to an explicitly
authorized caller. They never appear in validation errors, exception text,
Doctor, Activity, or generic debug metadata. Transport validation rejects
malformed nested values and applies the repository's credential redaction
boundary, but Orbit does not claim it can reliably redact arbitrary secrets
that an application writes to its own journal. Operators remain responsible
for keeping secrets out of command output.

### Extend verify-only Doctor with Schedule

Schedule is an explicit checked model in ADR 0004's persisted-model partition.
It belongs to one `schedule` Doctor family. The family reads Schedule intent
and performs bounded read-only inspection of the installed Node. It never
installs, reloads, enables, starts, stops, adopts, repairs, completes, or
removes a Schedule.

During the compatibility period in which Workspace still exists, the ordered
family set is:

1. `node`
2. `role`
3. `app`
4. `instance`
5. `workspace`
6. `schedule`
7. `tool`
8. `process`
9. `firewall`

After Workspace removal, the canonical final family set is:

1. `node`
2. `role`
3. `app`
4. `instance`
5. `schedule`
6. `tool`
7. `process`
8. `firewall`

Enums, dispatch, filters, SDK types, and CLI rendering use family tokens and
must not rely on the transitional or final numeric count.

The Schedule probe reports bounded stable issues for:

- required artifact absence;
- unexpected owner or mode;
- service or timer specification drift;
- inactive or incorrectly enabled timer state;
- calendar drift;
- runtime-user or working-directory context drift;
- completion-callback drift;
- installed-Node placement drift;
- UUID-named orphan artifacts;
- installed-Node unreachability; and
- malformed or failed inspection.

Expected and observed values use only booleans, bounded enums, identifiers,
fingerprints, and fixed safe sentinels. They contain no commands, calendar
contents, logs, paths, users, unit contents, credentials, raw output, or
exception text. Orphan discovery is limited to the exact Orbit Schedule name
and directory namespace. An unknown artifact outside that namespace is not
Schedule-owned inventory.

### Separate feature proof from production release

The implementation is proved on a disposable registered Incus topology with
real Ubuntu 26.04 systemd, SSH, users, files, timers, services, journald,
timeouts, callbacks, failure injection, rollback, completion, and removal.
The proof topology is not production and is never promoted as a production
release.

A production rollout, recovery point, deployment, and post-deploy
verification remain a separate release issue under
[ADR 0002](0002-candidate-deployment-proof-boundary.md).

## Consequences

- Schedule timing and execution continue on the installed Node while the
  Gateway is unavailable; add, run, logs, completion recording, removal, and
  reconciliation still require the Gateway control plane.
- Native systemd replaces a central Orbit scheduler, queue, worker, and run
  ledger. Orbit stores only intent, installation identity, lifecycle, and
  bounded latest-completion metadata.
- Root-owned artifacts and fixed infrastructure arguments isolate system
  mutation from caller command text, while the command intentionally retains
  the privileges of its derived target runtime user.
- App-dev and app-prod Schedules use their existing runtime ownership without
  giving Orbit deployment or source-control ownership.
- Placement changes require explicit Schedule removal and re-creation. This is
  less automatic but prevents stale timers from silently running on an old
  Node.
- `Persistent=true` can execute one missed timer occurrence after Node
  downtime. Orbit does not provide replay, catch-up queues, concurrency,
  backfill, or run-history policy.
- Journald retention and availability follow the installed Node's policy.
  Authorized logs are bounded but are not centralized or guaranteed to remain
  after host retention removes them.
- An active service may outlive Schedule removal initiation, but no new timer
  trigger is accepted and final cleanup remains generation-safe.
- Doctor makes Schedule drift visible without becoming a repair path and keeps
  the final model partition closed after Workspace removal.
- Schedule requires a managed systemd Node. Role-less operator clients,
  non-systemd hosts, automatic target movement, HA timer replication, editing,
  disabling, run history, and generic remote execution require later
  decisions.
