# Applications

This page tells an operator how Apps provide source defaults, how Orbit creates a development AppInstance, and when an existing source needs manual migration. [Routes](../reference/routes.md) describes the separate hostname, scope, and target contract.

This behavior implements the development source boundary from [ADR 0009](../decisions/0009-clustered-app-instance-routing.md), the stable default identity from [ADR 0025](../decisions/0025-stabilize-the-default-appinstance-identity.md), and the source-layout terms from [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md). Production placement has a separate contract in [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md).

## Create an App

New Apps require a repository URL and a normalized relative web root. The `app:new` command accepts an optional default branch:

```text
orbit app:new \
  acme \
  git@github.com:acme/site.git \
  --default-branch=main \
  --root=public
```

When you omit the default branch, the Gateway reads the remote default branch once and stores it. A later remote default change does not rewrite the App.

An App can return null for `default_branch` and root when its source defaults are incomplete. Existing legacy Instance and Workspace records continue to use that App. New AppInstance creation fails with `app.source_defaults_incomplete` until a separate conversion lifecycle supplies the missing values. Orbit has no command that updates or backfills them.

## Create a development AppInstance

Select one active Node with an active app-dev role. Use the reserved `default` name for the App's default development source:

```text
orbit instance:new <app-id> <node-id> default
```

Use another name for a branch-specific source:

```text
orbit instance:new <app-id> <node-id> feature-one [--hostname=feature.example.test]
```

The Gateway derives the placement and branch from the requested identity.

| AppInstance identity | Managed placement | Selected branch |
| --- | --- | --- |
| `default` | `<node-apps-root>/<app-slug>/default` | The App `default_branch` |
| Any other name | `<node-apps-root>/<app-slug>/<instance-name>` | The matching remote branch, or a new branch from the exact fetched `default_branch` commit |

`instance:new` stores source layout `checkout`. The checkout has its own `.git` directory and does not use a Workspace or shared worktree administration. An adopted linked worktree uses source layout `worktree`; the separate `instance:register` workflow owns adoption and manual migration.

Orbit records the selected branch and starting commit before it publishes the AppInstance as active. Creation moves through four durable states:

```text
reserved -> checkout_prepared -> source_resolved -> active
```

An identical retry verifies the recorded App, Node, source layout, root, path, repository, branch, and pre-activation commit evidence. It then resumes the next incomplete transition. Once active, the recorded starting commit stays unchanged while normal development advances HEAD. A conflicting retry fails without a second row or checkout.

## Complete a required source migration

An AppInstance can require manual migration when its stored name follows a branch-named default identity. Orbit keeps that name, checkout path, selected branch, source, and Route authoritative until `instance:register` completes the migration. List and show operations remain available, the existing Route continues to serve the same source path, and Route-only reconciliation can continue to use the AppInstance.

The Gateway returns `instance.migration_required` before any database, Git, runtime, or Route mutation when an operation would remove, rebind, or change this source. A `default` identity or destination-path collision returns a bounded migration conflict and preserves the existing AppInstance and source.

## Generate a development Route

After a development AppInstance becomes active, the Gateway creates its Route. The optional `--hostname` value requests an explicit Route; omission requests a generated Route. The [Route reference](../reference/routes.md) owns the exact hostname, generation basis, target, routing scope, lifecycle, and failure behavior.

## Set the effective web root

By default, an AppInstance inherits the App root. Use the root option to store a relative override:

```text
orbit instance:new <app-id> <node-id> feature-one \
  --root=site/public
```

The effective root is the AppInstance root when set and the App root otherwise. Orbit rejects absolute paths and parent traversal.

## Remove development source

Normal removal verifies the recorded checkout identity. It refuses a dirty checkout, unpublished commits, a changed origin, a symlinked or non-canonical path, an out-of-root path, the wrong owner, or invalid Git metadata.

Remove clean, published source with:

```text
orbit instance:remove <id>
```

Use destructive source discard only when you intend to lose dirty or unpublished work:

```text
orbit instance:remove <id> --discard-source
```

With `--discard-source`, Orbit waives the dirty-source check and the unpublished-commit check. It does not waive origin, symlink, canonical-path, containment, ownership, or repository-identity checks. Orbit removes only the exact recorded checkout. It does not remove sibling, legacy, or unrelated repositories.

## Source-only boundary

Development AppInstance creation and removal do not accept a repository, command, PHP version, process, or shell input. The App owns the repository. The Node application role owns PHP and runtime prerequisites.

The [Route reference](../reference/routes.md) defines the boundary between stored Route intent and traffic projections.

`instance:new` does not adopt caller-local Git sources. The `instance:register` workflow governed by [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md) owns checkout and worktree adoption, including manual migration of a branch-named default source.
