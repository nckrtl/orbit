# ADR 0004: Define the verify-only Doctor boundary

## Status

Accepted on 2026-08-28.

## Context

Orbit stores managed intent in the Gateway database and projects parts of that
intent to registered nodes. Operators need one deterministic report that
compares the stored intent with live state. The report must identify drift and
failed observation without becoming a second convergence engine.

The old Orbit repository included a Doctor with repair, adoption, progress,
and persisted-run concepts. Those concepts do not fit the current synchronous
Gateway, closed Tool contract, or node-access boundary. Retaining raw remote
output or generic snapshots would also increase the risk of exposing paths,
configuration, credentials, or other sensitive values.

This decision records the architecture approved by the NCK-54 contract and the
[verify-only Doctor plan](file:///home/nckrtl/shared-knowledge/projects/orbit/superpowers/plans/2026-08-26-verify-only-doctor.md).

## Decision

### Keep Doctor verify-only

Doctor is a synchronous, in-memory comparison of managed intent with bounded
live observations. It may read the Gateway database and inspect registered
nodes. It must not converge, install, remove, start, stop, restart, adopt, or
otherwise mutate managed state.

Doctor must not mutate host state, settings, Tool or manager state, lifecycle
state, access policy, or locks with external side effects. It uses only
database reads and explicitly read-only local or remote commands.

Doctor does not create migrations, tables, report rows, snapshots, journals,
jobs, queues, progress streams, or persisted findings. The eventual HTTP
operation creates exactly one normal request-audit `Activity` row with only the
standard bounded operation metadata. This is the only Doctor-owned write.
Activity is not a Doctor family and never contains Doctor findings, report
history, or raw diagnostics.

Read-only inspectors are separate from mutating managers. Existing writers may
share extracted catalogs, parsers, renderers, and typed settings owners with an
inspector, but Doctor never depends on a mutating manager or action.

### Use eight ordered families

The closed Doctor family set and its canonical order are:

1. `node`
2. `role`
3. `app`
4. `instance`
5. `workspace`
6. `tool`
7. `process`
8. `firewall`

The Gateway owns an explicit probe for each family. Each probe owns its model
query, comparison policy, stable issue codes, resource ordering, and focused
tests. Orchestration uses the closed family enum and explicit dispatch. It does
not use a tagged service registry, plugin registry, or generic comparison
pipeline.

Requested filters do not change canonical family order. Nodes sort by name and
then ID. Resources sort by ID. Issues keep stable probe, resource, and field
order.

### Partition every persisted model

Every direct model in `apps/gateway/app/Models` has one explicit,
non-overlapping disposition:

| Persisted model | Doctor disposition |
|---|---|
| `Node` | `node` family |
| `NodeRole` | `role` family |
| `App` | `app` family |
| `Instance` | `instance` family |
| `Workspace` | `workspace` family |
| `Tool` | `tool` family |
| `Process` | `process` family |
| `FirewallRule` | `firewall` family |
| `ToolManagerRecord` | typed `tool` family prerequisite input |
| `Setting` | typed owner-family input |
| `NodeAccess` | excluded database-only authorization policy |
| `Activity` | excluded append-only operation history |

An architecture test discovers the direct model classes and requires three
maps to form this exact partition: checked family resources, typed owner or
prerequisite inputs, and excluded models. A new model fails the test until its
Doctor disposition is explicit. An input or excluded model cannot also be a
checked family resource.

Application defaults affect later projections. Doctor reports no app drift
until an instance or workspace projects that value on the selected node.

### Observe each node once

Doctor performs one bounded node observation before it runs selected family
probes for that node. This observation contains only:

- reachability;
- normalized platform;
- normalized architecture; and
- whether the managed WireGuard address is present.

The inspector uses the existing fixed SSH boundary, managed key, pinned known
hosts, WireGuard address, `orbit` user, port, and operation deadline. A command
failure or timeout returns an unreachable observation. A successful malformed
result produces the bounded `node.inspection_failed` unverifiable issue. It
also prevents dependent live inspectors from running for that node. The report
does not retain a command result, stdout, stderr, an exception message, or
connection material.

SQLite-only lifecycle and conflict checks may still run when a node is
unreachable. A selected live-state family with managed resources returns one
bounded `*.node_unreachable` issue and does not inspect those resources. An
empty family stays healthy with `checked=0`.

### Return bounded deterministic reports

A Doctor issue contains only:

- `code`;
- `kind`;
- `resource_type`;
- `resource_id`;
- `resource_name`;
- `summary`;
- `expected`; and
- `observed`.

Issue kind is `drift` or `unverifiable`. Family status is `healthy` when it has
no issues, `drift` when it has drift only, and `unverifiable` when any issue is
unverifiable. Unverifiable takes precedence over drift. `checked` counts
persisted family resources, not commands. Aggregate health is true only when
all selected node and family reports are healthy.

Expected and observed values contain only bounded booleans, enums, normalized
versions, fixed safe status labels, or fixed sentinels. Reports never include
raw output, commands, paths, URLs, branch names, repository origins,
credentials, environment variables, configuration contents, setting values,
private keys, or exception text. Settings are loaded only through typed owners
and are reduced to bounded comparisons.

Stable issue codes are part of the public contract. Each family owns a typed
code catalog. An unknown internal code becomes that family's bounded
`*.inspection_failed` unverifiable issue. The Gateway never returns the
unknown value. SDK validation of untrusted response members remains a separate
transport concern.

### Preserve the Tool contract

The `tool` family consumes the landed `Tool` intent model and the closed manager
registry defined by ADR 0001. The Tool domain owns a read-only `ToolInspector`
seam. Its implementation validates the row's node and manager ownership,
dispatches through the existing manager registry, and calls only the manager's
read-only installed-version probe. It returns only installed state and a
nullable normalized version. Doctor depends only on this bounded seam and does
not perform manager validation, dispatch, parsing, or command execution.

The Tool probe queries rows by node and ID. It reports an absent managed package
as drift. A null `version_constraint` requires only installed state. A non-null
constraint is desired safety intent, so Doctor reuses the existing
`VersionConstraint` policy to compare the normalized installed version without
selecting or changing a version. An invalid stored constraint or an installed
version that cannot be normalized is unverifiable and produces
`tool.inspection_failed`. A valid constraint that rejects the normalized
version produces `tool.version_mismatch` drift. Doctor does not compare the
observed version with `installed_version`, because that field records the last
known operation result rather than desired version intent.

The report does not expose the package coordinate, raw or normalized version,
constraint, manager output, or manager configuration in expected or observed
values.

Doctor does not scan for unmanaged packages, create Tool rows, inspect candidate
versions, or call installation, update, removal, manager materialization, or
removal-plan methods. `ToolManagerRecord` remains protected prerequisite input,
not a separate Doctor family or host-inventory source.

### Split component ownership

The Gateway owns model dispositions, comparison policy, stable issue codes,
read-only observation, deterministic aggregation, and the authenticated Doctor
endpoint. The PHP SDK transports the bounded typed report without policy. The
CLI selects filters, renders deterministic human or JSON output, and maps report
health to its exit code.

The eventual Gateway endpoint is one synchronous collection-scoped operation
under the existing active-WireGuard and serving-node access boundaries. With
no `node_id`, Doctor selects only nodes returned by the current caller's
`NodeAccessAuthorizer`, ordered by name and ID. Gateway callers retain their
existing fleet-wide authority. A filtered `node_id` must be allowed by the
same authorizer. A caller cannot use Doctor to inspect a node it cannot
otherwise address.

Completed reports use HTTP `200`, including drift and unverifiable reports.
Transport and authorization failures continue to use the normal error
envelope. The CLI exits `0` only for a healthy report and `1` for drift,
unverifiable results, or transport failure.

## Consequences

- Doctor can report complete, stable health without owning repair or adoption.
- One node observation limits remote work and gives every family the same
  reachability and identity evidence.
- The explicit family set and model partition make missing coverage fail during
  tests instead of at runtime.
- Bounded observation types and issue values reduce secret and diagnostic-data
  exposure.
- Adding a family requires an enum case, probe, dispatch branch, model
  disposition, issue catalog, tests, and public documentation.
- Existing mutating infrastructure may need read-only seams or shared catalogs,
  but its mutation behavior must not change.
- Doctor does not preserve reports or raw observations. Operators must use the
  returned report and request ID at request time.
- Restore, adoption, interactive repair, persisted runs, fleet concurrency,
  and untracked host-inventory discovery require separate decisions.
