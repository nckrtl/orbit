# ADR 0001: Adopt the closed Tool Management contract

## Status

Accepted on 2026-08-27.

## Context

Orbit needs to manage operator-selected packages without restoring the legacy
tool catalog, generic scripts, or arbitrary command execution. This capability
crosses the Gateway, PHP SDK, and CLI. It therefore needs one durable contract
for package-manager support, caller input, state ownership, removal safety, and
component responsibilities.

Package-manager state on a node is not sufficient proof that Orbit owns a
package. Treating host inventory as managed state would let Orbit adopt or
remove software that another actor installed. Accepting commands, options, or
repositories from callers would also turn package management into a remote
execution boundary.

This decision records the architecture approved in the
[Tool Management design](file:///home/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-26-simplified-orbit-tool-management-design.md)
and its
[implementation plan](file:///home/nckrtl/shared-knowledge/projects/orbit/superpowers/plans/2026-08-26-simplified-orbit-tool-management.md).

## Decision

### Model managed intent

A Tool is one manager-native package that Orbit manages on one node. Its
identity is the node, manager, and package. SQLite stores Orbit's managed intent
and the last known result of Orbit's operations. It does not store general host
inventory.

Orbit does not scan for, infer, or adopt arbitrary installed packages. Private
bootstrap prerequisites do not receive Tool rows. Manager availability is
tracked separately per node, and manager rows are protected infrastructure.

### Keep the manager registry closed

The Gateway owns a typed, code-defined manager registry. The initial registry
contains only APT, VP, and Composer. It does not contain npm, Bun, Brew, manual,
script, or observed managers. Orbit does not use per-tool definitions.

APT is available on managed Linux nodes. VP and Composer use one shared
Orbit-owned scope per node and are available only when an `app-dev` or
`app-prod` role is provisioning or active. Adding another manager requires a
new code-owned adapter, deterministic tests, and proportional live acceptance.

### Treat caller input as a security boundary

An install caller can supply only `node_id`, `manager`, `package`, and an
optional `version_constraint`. Callers cannot supply commands, argv, scripts,
executable paths, environment variables, repositories, manager options, or
privilege settings.

Each manager validates its own package grammar and constructs fixed commands
with the validated package in one defined argument position. The managers use
their shared Orbit-owned node scopes. Invalid or ambiguous input fails closed
before node mutation.

Orbit stores raw manager versions. A nullable version constraint is only a
pre-mutation SemVer safety gate against the manager's normal candidate. Each
manager owns conservative normalization of its version format. Orbit fails
closed when normalization is not safe, does not search for another matching
release, and does not downgrade automatically.

### Preserve package ownership during removal

Orbit rejects an install when the package already exists in the manager scope
without a matching Tool row. It does not silently adopt that package. A
user-directed Tool becomes removable only through Orbit's successful or
retriable installation flow.

Public operations cannot remove manager rows or protected Tools. APT must
produce a dry-run plan that removes only the exact recorded package, and Orbit
never runs `autoremove` as part of Tool removal. VP and Composer removal targets
only the exact recorded root package in their Orbit-owned scopes.

Orbit blocks removal of the last app role while non-protected VP or Composer
Tool intent remains. Role removal never removes packages or Tool intent
implicitly. After a successful last-app-role removal, Orbit retains the manager
rows and marks VP and Composer unavailable until app-role convergence activates
them again.

### Serialize mutations and retain recoverable state

Every mutation locks both the exact node-manager-package identity and the
shared node-manager scope. A retry probes live manager state before it acts and
cannot create a duplicate intent row.

Failed installs and removals retain one bounded failure record so the operator
can inspect and retry the operation. A successful removal deletes its Tool row.
A constraint-blocked update is a successful no-op that leaves the installed
Tool intact.

Manager output is an internal diagnostic boundary. The Gateway does not
persist or return raw stdout or stderr. Public failures and activity use stable
error codes, bounded redacted diagnostics, explicit outcomes, and request IDs.

### Split component ownership

The Gateway owns validation, SQLite state, manager selection, version policy,
locks, fixed remote operations, state transitions, activity, and safe error
translation. The PHP SDK owns typed transport and preserves the Gateway
contract without applying manager policy. The CLI owns prompts and
deterministic human or JSON output and remains HTTP-only.

Tool operations do not own runtime lifecycle. Process lifecycle stays under
the `process:*` command family.

## Consequences

- Operators can manage any package that passes an approved manager's grammar
  without adding package-specific Orbit code.
- The closed registry and fixed command construction keep arbitrary execution
  outside the public Tool surface.
- Managed intent and exact-removal rules prevent Orbit from claiming or
  deleting packages that it does not own.
- Shared manager scopes require manager-level locking and make package-manager
  availability a node prerequisite.
- Conservative version normalization rejects some valid manager-native
  versions when a constraint is present. Unconstrained operations can still
  preserve and use raw versions.
- The SDK and CLI stay small because all manager and safety policy remains in
  the Gateway.
- Brew support, manager plugins, package adoption, per-user scopes, constraint
  mutation, automatic downgrade, Tool definitions, and Tool-owned process
  lifecycle require separate decisions.
