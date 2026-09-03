# Applications

Orbit stores shared source defaults on an App. It creates each development
AppInstance as an independent clone on one manually selected app-dev Node. The
AppInstance stores no Cluster identity. The selected Node can be standalone or
in an inactive, active TLD-less, or active TLD-bearing Cluster. Any later
routing scope derives from the Node at routing time.

This behavior implements the development source boundary from
[ADR 0009](../decisions/0009-clustered-app-instance-routing.md). Production
placement has a separate contract in
[ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md).

## Create an App

New Apps require a repository URL and a normalized relative web root. The
app:new command accepts an optional main branch:

```text
orbit app:new \
  acme \
  git@github.com:acme/site.git \
  --main-branch=main \
  --root=public
```

When you omit the main branch, the Gateway reads the remote default branch once
and stores it. A later remote default change does not rewrite the App.

Apps that existed before this source contract can return null for the main
branch and root. Existing legacy Instance and Workspace records continue to
use those Apps. New AppInstance creation fails with
app.source_defaults_incomplete until a later conversion lifecycle supplies the
missing values. ORB-76 does not add an update or backfill command.

## Create a development AppInstance

Select one active Node with an active app-dev role:

```text
orbit instance:new <app-id> <node-id> feature-one
```

The Gateway derives and records this immutable checkout path:

```text
<node-apps-root>/acme/feature-one
```

The AppInstance has the immutable source kind `managed_clone`. Its clone has
its own .git directory. It does not use a Workspace, Git worktree metadata, or
shared worktree administration.

Orbit fetches the App repository. It checks out origin/feature-one when that
branch exists. Otherwise, it creates feature-one from the exact fetched
origin/<app.main_branch> commit. Orbit records the branch and starting commit
before it publishes the AppInstance as active.

Creation moves through four durable states:

```text
reserved -> checkout_prepared -> source_resolved -> active
```

An identical retry verifies the recorded App, Node, source kind, root, path,
repository, branch, and pre-activation commit evidence. It then resumes the
next incomplete transition. Once active, the recorded starting commit stays
unchanged while normal development advances HEAD. A conflicting retry fails
without a second row or checkout.

## Set the effective web root

By default, an AppInstance inherits the App root. Use the root option to store
a relative override:

```text
orbit instance:new <app-id> <node-id> feature-one \
  --root=site/public
```

The effective root is the AppInstance root when set and the App root otherwise.
Orbit rejects absolute paths and parent traversal.

## Remove development source

Normal removal verifies the recorded checkout identity. It refuses a dirty
checkout, unpublished commits, a changed origin, a symlinked or non-canonical
path, an out-of-root path, the wrong owner, or invalid Git metadata.

Remove clean, published source with:

```text
orbit instance:remove <id>
```

Use destructive source discard only when you intend to lose dirty or
unpublished work:

```text
orbit instance:remove <id> --discard-source
```

With `--discard-source`, Orbit waives the dirty-source check and the
unpublished-commit check. It does not waive origin, symlink, canonical-path, containment,
ownership, or repository-identity checks. Orbit removes only the exact
recorded checkout. It does not remove sibling, legacy, or unrelated
repositories.

## Source-only boundary

Development AppInstance creation and removal do not accept a repository,
command, PHP version, process, or shell input. The App owns the repository.
The Node application role owns PHP and runtime prerequisites.

This lifecycle does not change Caddy, certificates, DNS, hostnames, Routes,
Routers, or runtime services. Those operations belong to later runtime and
publication lifecycles.

Caller-local Git worktrees are a separate, externally owned source kind. They
are not adopted by `instance:new`; the later registration lifecycle governed
by [ADR 0018](../decisions/0018-register-caller-local-development-worktrees.md)
owns that behavior.
