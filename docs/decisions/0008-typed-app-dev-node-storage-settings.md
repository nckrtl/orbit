# ADR 0008: Define typed app-dev node storage settings

## Status

Accepted on 2026-08-30.

## Context

Orbit creates an app-dev instance checkout under
`<managed-user-home>/apps/<app>` and a default workspace checkout under
`<managed-user-home>/.orbit/worktrees/<app>/<workspace>`. These locations are
safe defaults, but they cannot place source on a separate disk or mounted
volume. A per-workspace checkout override exists, but it does not give a node
one consistent storage policy and there is no equivalent instance override.

This configuration crosses the Gateway database and remote filesystem
boundary, the PHP SDK transport, and CLI input. A generic settings map would
make arbitrary keys part of the public contract. Dedicated columns would make
each later node setting a schema concern. Persisting computed paths would also
turn a managed-user home change into stale configuration. Orbit instead needs
a small typed contract that stores only operator intent and continues to
derive the current defaults when no intent exists.

Configured roots can be outside the managed user's home. This broadens the
filesystem ownership, Caddy traversal, overlap, and deletion boundaries.
Those boundaries must be part of the contract before product code accepts the
paths.

## Decision

### Store one closed, nullable settings value

The nullable JSON `nodes.settings` column stores a `NodeSettings` DTO.
`NodeSettings` has nullable `instance` and `worktree` properties of type
`InstanceSettings` and `WorktreeSettings`. Each nested DTO has one nullable
string property named `path`.

The public JSON shape is:

```json
{
  "settings": {
    "instance": {"path": "/srv/orbit/instances"},
    "worktree": {"path": "/srv/orbit/worktrees"}
  }
}
```

The key set is closed. Orbit rejects unknown top-level and nested keys. It
normalizes a nested DTO with a null `path` to no override. When neither path is
configured, it stores SQL `null`, not an empty object. Node responses return
the raw override state through the same DTOs. They return `settings: null`
when no override exists and never replace a null with an effective default.

### Derive roots when a checkout is created

For an app-dev node, Orbit resolves the effective roots as follows:

- `settings.instance.path`, or `<managed-user-home>/apps` when it is absent;
- `settings.worktree.path`, or
  `<managed-user-home>/.orbit/worktrees` when it is absent.

A null `nodes.settings` value, an absent nested DTO, and a null nested `path`
all select the matching managed-home default. Orbit computes the default when
it is needed and does not persist it.

A new instance checkout is `<instance-root>/<app>`. A new default workspace
checkout is `<worktree-root>/<app>/<workspace>`. `app` is the app slug and
`workspace` is the validated workspace name. The existing explicit
per-workspace `checkout_path` request remains a separate override and keeps
its existing validation rules. App-prod placement does not use these settings
and remains unchanged.

Orbit stores the resulting checkout path on the instance or workspace. That
path is immutable. A later node setting change affects only checkouts created
after the change. It never moves, rewrites, or deletes an existing checkout.

### Expose provisioning and partial updates

`POST /api/v1/nodes` accepts an optional `settings` member with the complete
nullable shape. An absent or null member provisions the node without storage
overrides.

`PATCH /api/v1/nodes/{node}/settings` accepts a partial `NodeSettings` object.
An omitted `instance` or `worktree` member preserves that stored value. A
nested object with `path: null`, or an explicit null nested member, removes
that override and restores the derived default. The request must contain at
least one known member. The response is the updated node response, including
raw settings.

The update boundary keeps omission distinct from null until it has merged the
patch with the stored DTO. It then normalizes the result and collapses a value
without either path to SQL `null`.

### Keep CLI setting input closed and repeatable

`node:provision` and the node-settings update command accept repeatable
`--setting=<setting-path>:<value>` options. The initial known setting paths are
only `instance.path` and `worktree.path`.

The CLI splits each option at its first colon. This preserves later colons in
the value. It rejects a missing colon, an empty key, an unknown key, and the
same key supplied more than once before it sends a request. An empty value is
the explicit unset form. For example,
`--setting=instance.path:` sends a null instance path. The CLI does not trim or
otherwise reinterpret a non-empty path.

The SDK owns the same DTOs for typed request and response transport. Its
settings update request preserves the difference between an omitted member
and an explicit null. The CLI contains parsing and presentation only; it does
not apply filesystem policy.

### Validate roots before they become effective

The Gateway validates every configured path at the API boundary and again on
the target node before it persists a provisioning or settings mutation. A
configured root must:

- be a non-empty absolute path in normalized form, with no trailing or
  repeated separator, null byte, control character, `.` segment, or `..`
  segment;
- not be `/`, the managed user's home, or an ancestor of that home;
- not equal or be inside an existing managed checkout;
- not equal, contain, or be contained by an Orbit state, Gateway deployment,
  app-prod checkout, or operating-system protected path; and
- resolve without symlinks after preparation, with every existing component
  being a real directory.

The protected-path catalog is closed and code-owned. It includes the virtual
and system trees `/boot`, `/dev`, `/etc`, `/proc`, `/run`, `/sys`, and `/usr`,
plus Orbit-owned paths under `/opt/orbit`, `/var/lib/orbit`, `/var/www`, and
the managed user's hidden control directories. The normal worktree default is
the explicit exception for `<managed-user-home>/.orbit/worktrees`.

Orbit compares paths by directory boundary, not by string prefix. The two
effective roots must not be equal and neither can contain the other. Before a
checkout is created, its exact derived path must not equal, contain, or be
contained by any other managed instance or workspace checkout on that node.

### Prepare ownership and web traversal narrowly

Provisioning or convergence of an app-dev role prepares both effective roots.
A settings update on an active app-dev node prepares a new root before that
root becomes stored intent. Orbit can create missing directories in the
configured path, but it changes ownership and mode only on directories that
it creates and on the configured root itself. The root is a real directory
owned by the managed user and group with mode `0755`. Orbit never recursively
changes ownership of pre-existing contents.

Orbit gives Caddy traverse-only access to each required ancestor and gives it
the existing site-specific access inside a checkout. It records whether an
ancestor ACL existed before Orbit changed it, shares traversal grants between
all dependent sites, and removes only an Orbit-added grant after the last
dependent site is removed. It does not grant Caddy read access to unrelated
ancestor contents.

If validation, directory preparation, ownership, or ACL preparation fails,
the mutation fails without changing `nodes.settings`. A prepared empty
directory can remain after a failed mutation; Orbit does not delete an
operator-selected root as rollback.

### Contain checkout removal to recorded source

Removal uses the immutable checkout path recorded when the checkout was
created. It does not compare that path with the node's current effective root.
For an instance, Orbit strips and verifies the exact `<app>` suffix. For a
workspace, it strips and verifies the exact `<app>/<workspace>` suffix. The
remaining historical root must pass the same protected-path boundary, and the
checkout must be a strict descendant of it.

Before deletion, Orbit proves that the checkout and its existing parents are
not symlinks, their canonical paths match the recorded paths, and the Git
repository or worktree identity matches the managed record. It deletes only
the exact checkout. Workspace removal can also remove its now-empty `<app>`
grouping directory. Orbit never recursively deletes a configured root, an
ancestor, an unexpected sibling, or unrecognized content. Ambiguous ownership
or containment fails closed.

## Consequences

- One typed JSON value can grow through reviewed DTO changes without exposing
  an arbitrary settings namespace.
- Existing nodes keep their current managed-home behavior because null values
  derive the old paths.
- Operators can place later app-dev source on mounted storage without changing
  app-prod, Orbit state, certificates, tools, packages, or the Gateway source.
- Sparse raw configuration stays distinct from effective values in API and
  CLI output.
- Settings changes are non-migrating. Operators must remove or move old
  checkouts through separate explicit workflows.
- External roots require privileged preparation and ACL accounting, but the
  closed path and containment rules keep that privilege narrow.
- The dependent Gateway, SDK, and CLI implementation needs live proof for
  filesystem ownership, traversal, overlap rejection, settings changes, and
  safe removal. Its Linear issue is created only after this ADR is on `main`
  and can link the canonical accepted document.
