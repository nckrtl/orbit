# Applications

This page tells an operator how Orbit creates, configures, and exposes a development AppInstance on one manually selected app-dev Node. An App stores the shared source defaults, and each AppInstance uses an independent clone and one Route.

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
missing values. Orbit has no command that updates or backfills them.

## Create a development AppInstance

Select one active Node with an active app-dev role:

```text
orbit instance:new <app-id> <node-id> feature-one [--hostname=feature.example.test]
```

The Gateway derives and records this immutable checkout path:

```text
<node-apps-root>/acme/feature-one
```

The AppInstance has the immutable source kind `managed_clone`. Its clone has
its own .git directory. It does not use a Workspace, Git worktree metadata, or
shared worktree administration.

Orbit fetches the App repository. It checks out origin/feature-one when that branch exists. Otherwise, it creates feature-one from the exact fetched origin/<app.main_branch> commit. Orbit records the branch and starting commit before it provisions the application endpoint.

Source preparation moves through three durable states:

```text
reserved -> checkout_prepared -> source_resolved
```

An identical retry verifies the recorded App, Node, source kind, root, path, repository, branch, and pre-activation commit evidence. It then resumes the next incomplete source or provisioning boundary. Once active, the recorded starting commit stays unchanged while normal development advances HEAD. A conflicting retry fails without a second row, checkout, or Route.

## Provision the application endpoint

Before source or runtime changes, the Gateway resolves the Route hostname. The optional `--hostname` value requests an explicit hostname and takes precedence over generated naming. Without it, the Gateway uses the Node or Cluster naming basis described in the [Route reference](../reference/routes.md). The request fails before source or runtime mutation when neither basis can produce a hostname.

After source resolution, the Gateway associates the AppInstance with its sole Route and prepares the application endpoint. It aligns Laravel configuration when it detects Laravel, prepares the workload runtime, certificates, Caddy, firewall, and private Domain Name System (DNS) record, and prepares the Router path for a Cluster-scoped Route. It records completed boundaries so the same request can continue after a failure without duplicating source or Route records.

Orbit returns the active AppInstance with its Route and hostname when every Orbit-owned boundary succeeds. The endpoint is then available for an agent or operator to inspect and finish application setup.

## Configure a Laravel URL

Orbit detects Laravel from source files. Detection and URL configuration do not run Composer, Artisan, installed application code, or application bootstrap.

Orbit sets Laravel's canonical application URL to `https://<route-hostname>`. When `.env` exists, Orbit changes only `APP_URL` and preserves every unrelated line and value. When `.env` is missing and an environment template exists, Orbit keeps the template's installation inputs and adds or replaces `APP_URL`. Orbit also replaces a stale cached application URL without changing unrelated cached configuration.

Framework detection does not install dependencies or infer setup commands. App setup-step configuration and execution belong to a separate contract.

## Handle application errors

Active state means that Orbit prepared the source, Laravel URL when applicable, runtime, and Route. It does not promise that the application is healthy. Missing dependencies, an application key, or a database can make a new Laravel application return an error, including HTTP 500, without making the AppInstance or Route inactive.

Orbit fails provisioning when it cannot complete an Orbit-owned source, configuration, runtime, certificate, firewall, or publication boundary. The response identifies the failed boundary. An identical creation request verifies recorded evidence and resumes from that boundary.

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

Orbit refuses to remove an active AppInstance while coordinated Route and source removal is unavailable. It returns `route.reconciliation_required` before it changes the Route, source, or AppInstance record.

For an AppInstance that is eligible for removal, normal removal verifies the recorded checkout identity. It refuses a dirty checkout, unpublished commits, a changed origin, a symlinked or non-canonical path, an out-of-root path, the wrong owner, or invalid Git metadata.

Remove clean, published source with:

```text
orbit instance:remove <id>
```

Use destructive source discard only when you intend to lose dirty or
unpublished work:

```text
orbit instance:remove <id> --discard-source
```

With `--discard-source`, Orbit waives the dirty-source check and the unpublished-commit check after the Route guard passes. It does not waive origin, symlink, canonical-path, containment, ownership, or repository-identity checks. Orbit removes only the exact recorded checkout. It does not remove sibling, legacy, or unrelated repositories.

## Input boundary

Development AppInstance creation and removal do not accept a repository, command, PHP version, process, or shell input. The App owns the repository. The Node application role owns PHP and runtime prerequisites. Orbit does not install application dependencies as part of framework detection.

The [Route reference](../reference/routes.md) defines initial private traffic projection and the temporary refusal boundary for Route, Node, and Cluster changes that need coordinated runtime and Laravel URL reconciliation.

Caller-local Git worktrees are a separate, externally owned source kind. They are not adopted by `instance:new`; the registration lifecycle governed by [ADR 0018](../decisions/0018-register-caller-local-development-worktrees.md) owns that behavior.
