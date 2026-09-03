# Applications

Orbit stores shared source defaults on an App. A development AppInstance uses
one of two explicit source-ownership modes: an independent clone managed by
Orbit, or an externally owned linked Git worktree registered from its caller
Node. AppInstances belong to a Node and do not store Cluster ownership.

This behavior implements the development source boundary from
[ADR 0009](../decisions/0009-clustered-app-instance-routing.md). Production
placement has a separate contract in
[ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md).

## Create an App

New Apps require a repository URL and a normalized relative web root. The
app:new command accepts an optional main branch:

    orbit app:new \
      --slug=acme \
      --repository-url=git@github.com:acme/site.git \
      --main-branch=main \
      --root=public

When you omit the main branch, the Gateway reads the remote default branch once
and stores it. A later remote default change does not rewrite the App.

Apps that existed before this source contract can return null for the main
branch and root. Existing legacy Instance and Workspace records continue to
use those Apps. New AppInstance creation fails with
app.source_defaults_incomplete until a later conversion lifecycle supplies the
missing values. ORB-76 does not add an update or backfill command.

## Create a development AppInstance

Select one active Node with an active app-dev role:

    orbit instance:new --app=acme --name=feature-one --node=app-dev

The Gateway derives and records this immutable checkout path:

    <node-apps-root>/acme/feature-one

The clone has its own .git directory. It does not use a Workspace, Git
worktree metadata, or shared worktree administration.

Orbit fetches the App repository. It checks out origin/feature-one when that
branch exists. Otherwise, it creates feature-one from the exact fetched
origin/<app.main_branch> commit. Orbit records the branch and starting commit
before it publishes the AppInstance as active.

Creation moves through four durable states:

    reserved -> checkout_prepared -> source_resolved -> active

An identical retry verifies the recorded App, Node, root, path,
repository, branch, and commit evidence. It then resumes the next incomplete
transition. A conflicting retry fails without a second row or checkout.

## Set the effective web root

By default, an AppInstance inherits the App root. Use the root option to store
a relative override:

    orbit instance:new \
      --app=acme \
      --name=feature-one \
      --node=app-dev \
      --root=site/public

The effective root is the AppInstance root when set and the App root otherwise.
Orbit rejects absolute paths and parent traversal.

## Remove development source

Normal removal verifies the recorded checkout identity. It refuses a dirty
checkout, unpublished commits, a changed origin, a symlinked or non-canonical
path, an out-of-root path, the wrong owner, or invalid Git metadata.

Remove clean, published source with:

    orbit instance:remove --instance=<id>

Use destructive source discard only when you intend to lose dirty or
unpublished work:

    orbit instance:remove --instance=<id> --discard-source

The discard-source option waives only the dirty-source and unpublished-commit
checks. It does not waive origin, symlink, canonical-path, containment,
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

## Register an externally owned worktree

Run registration from the Git top level or any directory below it:

    orbit instance:register --app=acme

The CLI resolves the canonical Git top level with one fixed, read-only Git
query. By default, the AppInstance name is the directory immediately above the
top level. For a Codex worktree at
`/home/orbit/.codex/worktrees/dfb5/acme`, the name is `dfb5`. Orbit validates
that value without sanitizing or truncating it. Use explicit valid overrides
when needed:

    orbit instance:register \
      --app=acme \
      --name=preview \
      --root=site/public

The Gateway derives placement only from the authenticated active WireGuard
caller. The caller must be a supported active Node with an active app-dev role.
Registration has no Node or Cluster option.

Before recording anything, the Gateway verifies the checkout as the managed
app-dev user. The path must be canonical and symlink-free, have safe ownership
and modes, be the exact top level of a non-primary linked worktree, remain in
the common repository's worktree inventory, match the App origin, contain the
effective web root, and not overlap another managed or registered checkout. A
worktree below the managed user's `.codex` directory is allowed after those
checks. This does not make `.orbit`, `.ssh`, system paths, Gateway files, or
other hidden control paths valid source roots.

Registered worktrees have source kind `registered_worktree`. Orbit records the
canonical checkout path, nullable initial branch, initial HEAD commit, and a
bounded immutable worktree identity. Detached HEAD, dirty files, untracked
files, later commits, branch creation, and HEAD movement are all valid. An
identical retry verifies the current path, origin, root, overlap, and worktree
identity without rewriting the initial branch or commit observations.

When an effective development TLD exists, the ordinary generated development
hostname is:

    <instance-name>.<app-slug>.<effective-tld>

The effective TLD is the active Cluster TLD when present, otherwise the Node
TLD. Without either TLD, Orbit creates no generated hostname; publication
requires an explicit Route.

Orbit never clones, fetches, checks out, resets, cleans, commits, prunes, or
removes Git state for a registered worktree. It may converge only Orbit-owned
runtime and routing projections around it and never edits a file inside the
checkout. Doctor verifies the registered path and identity read-only. Missing
or invalid external source is reported as bounded unavailable drift and does
not delete the registration.

Unregister the worktree explicitly:

    orbit instance:unregister <instance-id>

Unregister removes Orbit-owned projections and registration state but never
removes the checkout, its parent, branch, commits, dirty files, common Git
directory, or worktree registration. It remains valid after the external
checkout disappears. Use `instance:remove` only for `managed_clone` source;
use `instance:unregister` only for `registered_worktree` source.
