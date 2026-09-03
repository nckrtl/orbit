# ADR 0018: Register caller-local development worktrees as AppInstances

## Status

Accepted on 2026-09-03.

If accepted, this decision extends
[ADR 0009](0009-clustered-app-instance-routing.md) with an externally owned
app-dev source mode and supersedes only its requirement that every new
development AppInstance use an Orbit-owned independent clone and never use a
Git worktree. It extends
[ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md) with
registered-source reconciliation rules. The remaining AppInstance, placement,
routing, source-safety, and update boundaries in those decisions and
[ADR 0017](0017-optional-cluster-placement-and-tld-precedence.md) stay in force.

## Context

`instance:new` gives Orbit ownership of a development AppInstance's source. It
creates an independent clone under the selected Node's apps root, selects a
branch and starting commit, and applies deletion rules appropriate to source
that Orbit created. That remains the safe default for persistent development
placements.

An operator can already have a separate working checkout on an app-dev Node.
For example, Codex creates Git worktrees so independent chats can edit and test
the same repository in parallel. Codex-managed worktrees are detached by
default, live under a configurable worktree root, and can be deleted by Codex
after their useful lifetime. Their files, Git state, and lifetime remain owned
by Codex and the operator rather than by Orbit. See the
[Codex worktree documentation](https://developers.openai.com/codex/app/worktrees/).

Creating another Orbit-owned clone for that work duplicates source and does not
serve the changes in the active worktree. Adopting the worktree as managed
source would be unsafe: Orbit must not fetch, check out, reset, move, prune, or
delete source owned by another tool. Inferring a target Node from an arbitrary
path would also be ambiguous when the CLI can address a remote Gateway.

Orbit needs an explicit registration operation that makes a caller-local Git
worktree available as a development AppInstance while preserving its external
source ownership. Registration must produce the same AppInstance and Route
behavior as other development placements without coupling Orbit to one Codex
installation path or claiming responsibility for worktree cleanup.

## Decision

### Keep managed clones and registered worktrees separate

An app-dev AppInstance has one immutable source kind:

- `managed_clone` is created by `instance:new`. Orbit owns the independent
  clone lifecycle defined by ADR 0009.
- `registered_worktree` is registered by `instance:register`. The operator or
  external tool owns the Git worktree and its complete source lifecycle.

An AppInstance never changes source kind or adopts another checkout in place.
Changing from one kind to the other requires removing or unregistering the old
AppInstance and creating or registering a distinct identity. App-prod remains
operator-deployed placement under ADR 0011 and is not a registered-worktree
source kind.

The Gateway, SDK, CLI, Doctor, and activity output expose the source kind so an
operator can distinguish source Orbit may delete from source it only observes.

### Register only a caller-local worktree

The CLI exposes:

```text
orbit instance:register --app=<app-slug> [--name=<name>] [--root=<root>]
```

The command can run from the worktree root or any directory beneath it. The CLI
resolves the canonical Git top level and sends its absolute path as bounded
registration input. The target Node is never caller-selectable input. The
Gateway derives it from the request's authenticated active WireGuard peer, so
the recorded placement and the filesystem containing the worktree are the same
Node. The caller Node must be active, supported for managed roles, and have an
active app-dev role. A role-less operator client cannot register its local path
as source on another Node.

The Gateway does not trust CLI-supplied Git observations. It independently
inspects the exact path on the caller Node as the managed app-dev user and
verifies all of the following before it creates an active registration:

- the path is normalized, absolute, canonical, and contains no symlinked
  component;
- the checkout and required existing parents have safe ownership and modes;
- the checkout is a linked Git worktree registered by its common repository,
  not the common repository's primary checkout or an independent clone;
- its exact top level equals the recorded path and its `origin` equals the
  App's stored repository URL;
- its effective web root is a real, non-symlink path contained by the
  checkout; and
- it does not equal, contain, or sit inside another Orbit-managed or registered
  checkout on that Node.

Registration can use a worktree below the managed user's hidden Codex control
directory because Orbit does not own or delete that source. This is a narrow
exception for a verified registered worktree, not a general storage-root or
managed-clone exception. Orbit continues to reject operating-system, Gateway,
Orbit-state, credential, and other code-owned protected paths. It grants Caddy
and the runtime only the traversal and site access required by the effective
web root, records every ACL it adds, and never grants access to unrelated
worktree or control-directory contents.

Orbit does not require a clean index, a named branch, or published commits for
registration. Dirty and untracked files are ordinary externally owned
development state. Orbit records the branch when one is checked out, otherwise
it records a null branch for detached HEAD, and records the exact HEAD commit
observed at registration. Those values describe initial evidence; later edits,
commits, branch creation, or HEAD movement do not rewrite the registration or
make an otherwise valid active worktree corrupt.

Registration never runs a source-mutating Git operation. In particular, Orbit
does not clone, fetch, pull, push, checkout, switch, reset, clean, add, commit,
create or delete a branch, add or remove a worktree, or prune shared Git state.

### Derive a stable default identity and development hostname

When `--name` is omitted, the CLI derives the AppInstance name from the final
directory name immediately above the Git top level. For a Codex worktree at
`<worktree-root>/dfb5/example`, the derived name is `dfb5`. Orbit does not
depend on `<worktree-root>` being `$CODEX_HOME/worktrees` and does not otherwise
interpret Codex's directory layout.

The derived or explicit name must satisfy the existing AppInstance-name and DNS
label grammar. Orbit fails without sanitizing or truncating an invalid derived
name; the operator can retry with `--name`. The App is selected by its unique
stored slug. App, name, caller Node, source kind, canonical checkout path, and
root override form immutable registration identity. An identical retry is
idempotent and re-verifies the external source without changing it. A conflict
fails without changing source, runtime, Route, or existing records.

Registration uses ADR 0017's effective development TLD:

```text
active Node Cluster TLD ?? Node TLD
```

It then uses ADR 0009's generated main and feature hostname shapes. A derived
feature name therefore produces:

```text
<worktree-parent>.<app-slug>.<effective-tld>
```

For the example above, App `example` and effective TLD `test` produce
`dfb5.example.test`. If neither routing scope supplies a TLD, Orbit generates no
hostname and requires an explicit Route for publication, as ADR 0017 already
requires. Registration does not introduce a separate Codex hostname type or
DNS namespace.

### Observe external source without taking ownership

An active registered worktree serves its current files. Orbit may converge the
AppInstance runtime, Route, certificate, Caddy, firewall, health, and narrowly
recorded ACL state around the checkout, but it never edits a file inside the
checkout. ADR 0016's best-effort `.env` URL replacement therefore excludes
registered worktrees.

An App repository URL update includes every registered worktree in preflight
but never changes its remote. The update can proceed only when each registered
worktree already reports an `origin` equal to the proposed repository URL. The
operator must reconcile an external remote before retrying an otherwise blocked
App update. Slug, main-branch, and root updates retain ADR 0016's behavior where
compatible, but no update moves a registered checkout or rewrites its Git
state.

Doctor verifies the recorded path, ownership, worktree registration, origin,
effective root, required ACLs, runtime, and Route without changing them. A
missing or invalid worktree makes the AppInstance unavailable and produces
bounded Doctor evidence. It does not automatically delete the AppInstance or
Route, because Doctor remains verify-only and Codex or the operator may restore
the worktree.

### Unregister without deleting the worktree

`instance:unregister` is the destructive-intent boundary for a
`registered_worktree`. It removes or disables the AppInstance's Route, runtime,
certificate, firewall, and other Orbit-owned projections before deleting its
registration record. It removes only ACL entries that Orbit recorded and only
when their current identity is safe. It succeeds when the worktree has already
disappeared as long as Orbit can safely remove the remaining projections and
registration state.

Unregister never removes the checkout directory, a parent directory, a branch,
the common Git directory, or Git worktree administration. Dirty files,
untracked files, detached HEAD, and unpublished commits never require a force
or discard option because Orbit does not own them.

`instance:remove` continues to own managed-clone deletion and refuses a
registered worktree with an instruction to use `instance:unregister`.
Conversely, `instance:unregister` refuses a managed clone. App, Node, role, and
routing-scope mutations retain guards against orphaning either source kind.

Codex or another owner can delete a worktree without notifying Orbit. Orbit
does not add a Codex hook, poll Codex state, or infer that a missing path grants
permission to remove durable registration or routing state. Operators should
unregister temporary previews before their external owner removes the
worktree; otherwise they can unregister the unavailable AppInstance afterward.

## Consequences

- `instance:new` remains the persistent, Orbit-owned development source path;
  registration does not weaken its clone, branch, commit, retry, or safe
  deletion rules.
- A Codex chat can serve and test the exact files in its existing worktree at a
  predictable development hostname without a duplicate clone.
- Registration works with detached HEAD and in-progress uncommitted changes,
  matching the normal Codex worktree lifecycle.
- Caller identity closes Node placement: registration cannot claim that a local
  path exists on an independently selected remote Node.
- Orbit records and operates around external source but cannot guarantee its
  availability, history, cleanup, or restoration.
- A missing externally owned worktree can leave an intentionally unavailable
  AppInstance and Route until the operator restores or unregisters it.
- AppInstance persistence, public transport, Doctor, mutation guards, and App
  update reconciliation must distinguish managed clones from registered
  worktrees before registration can ship.
- The feature depends only on verified Git worktree behavior. It does not
  depend on a fixed Codex worktree root, token format, archive hook, or cleanup
  implementation.
