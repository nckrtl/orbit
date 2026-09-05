# Applications

This page tells an operator how Orbit creates, configures, and exposes a development AppInstance on one manually selected app-dev Node. An App stores shared source defaults, and each AppInstance uses an independent clone and one Route.

[ADR 0009](../decisions/0009-clustered-app-instance-routing.md) defines the development source boundary. [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md) defines the separate production placement contract.

## Create an App

New Apps require a repository URL and a normalized relative web root. The `app:new` command accepts an optional main branch:

```text
orbit app:new \
  acme \
  git@github.com:acme/site.git \
  --main-branch=main \
  --root=public
```

When you omit the main branch, the Gateway reads the remote default branch once and stores it. A later remote default change does not rewrite the App.

Apps that existed before this source contract can return null for the main branch and root. Existing legacy Instance and Workspace records continue to use those Apps. New AppInstance creation fails with `app.source_defaults_incomplete` until a separate conversion lifecycle supplies the missing values. Orbit has no command that updates or backfills them.

## Create a development AppInstance

Select one active Node with an active app-dev role:

```text
orbit instance:new <app-id> <node-id> feature-one [--hostname=feature.example.test]
```

The Gateway derives and records this immutable checkout path:

```text
<node-apps-root>/acme/feature-one
```

The AppInstance has the immutable source kind `managed_clone`. Its clone has its own `.git` directory. It does not use a Workspace, Git worktree metadata, or shared worktree administration.

Orbit fetches the App repository. It checks out `origin/feature-one` when that branch exists. Otherwise, it creates `feature-one` from the exact fetched `origin/<app.main_branch>` commit. Orbit records the branch and starting commit before it provisions the application endpoint.

Source preparation moves through three durable states:

```text
reserved -> checkout_prepared -> source_resolved
```

An identical retry verifies the recorded App, Node, source kind, root, path, repository, branch, and pre-activation commit evidence. It then resumes the next incomplete source or provisioning boundary. Once active, the recorded starting commit stays unchanged while normal development advances `HEAD`. A conflicting retry fails without a second row, checkout, or Route.

## Provision the application endpoint

Before source or runtime changes, the Gateway resolves the Route hostname. The optional `--hostname` value requests an explicit hostname and takes precedence over generated naming. Without it, the Gateway uses the Node or Cluster naming basis described in the [Route reference](../reference/routes.md). The request fails before source or runtime mutation when neither basis can produce a hostname.

After source resolution, the Gateway classifies the source, selects any required PHP runtime, associates the AppInstance with its sole Route, configures Laravel when detected, and prepares the runtime, certificates, Caddy, firewall, and private Domain Name System (DNS) projection. A Cluster-scoped Route also prepares the Router path. The [PHP runtime reference](../reference/php-runtime.md) describes source-driven runtime selection.

Orbit records each completed boundary. The same request can continue after a failure without duplicating source or Route records. Orbit returns the active AppInstance with its Route, hostname, and HTTPS URL when every provisioning step owned by Orbit succeeds.

## Select a PHP runtime

Orbit treats source with a valid `composer.json` file as a PHP project. A PHP platform constraint selects the highest PHP version that Orbit supports and that satisfies the constraint. A PHP project without a PHP platform constraint uses PHP 8.5. Source without Composer metadata receives no PHP runtime.

AppInstance create input, stored AppInstance state, API responses, the PHP SDK, and the CLI have no PHP-version field. An invalid or unsupported PHP constraint stops provisioning before runtime or DNS publication. Orbit reports the PHP-selection boundary and retains compatible source and Route evidence for an identical retry.

## Configure a Laravel URL

Orbit detects Laravel only when the source has both a regular, non-symlink `artisan` file and a valid `composer.json` file that declares `laravel/framework`. A source with neither marker is not Laravel. Partial, malformed, conflicting, duplicate, or unsafe marker evidence stops provisioning before configuration or publication.

Detection and URL configuration do not run Composer, Artisan, installed application code, or application bootstrap. Framework detection does not install dependencies or infer setup commands.

Orbit sets Laravel's canonical application URL to `https://<route-hostname>`. When `.env` exists, Orbit changes only `APP_URL` and preserves every unrelated byte. When `.env` is missing and an environment template exists, Orbit preserves the template's installation inputs and adds or replaces `APP_URL`. Orbit also replaces a static cached `app.url` without changing unrelated cached configuration. A symlinked, malformed, duplicate, or otherwise unsafe configuration file stops provisioning before publication.

## Handle provisioning and application errors

The Gateway reports a failed source, PHP selection, Laravel URL, runtime, certificate, firewall, or publication boundary and does not return a provisioned AppInstance. Secret environment values, certificate material, and private keys do not appear in command arguments, errors, API responses, activity data, or debug output.

Active state means that Orbit prepared the source, selected any required PHP runtime, aligned Laravel configuration when applicable, and prepared the Route. It does not promise that the application is healthy. Missing dependencies, an application key, or a database can make a new Laravel application return an error, including HTTP 500, without making the AppInstance or Route inactive.

The endpoint is available for an agent or operator to inspect and finish application setup. App setup-step configuration and execution belong to a separate contract.

## Set the effective web root

By default, an AppInstance inherits the App root. Use the root option to store a relative override:

```text
orbit instance:new <app-id> <node-id> feature-one \
  --root=site/public
```

The effective root is the AppInstance root when set and the App root otherwise. Orbit rejects absolute paths and parent traversal.

## Remove development source

Orbit refuses to remove an active AppInstance while coordinated Route and source removal is unavailable. It returns `route.reconciliation_required` before it changes the Route, source, or AppInstance record.

For an AppInstance that is eligible for removal, normal removal verifies the recorded checkout identity. It refuses a dirty checkout, unpublished commits, a changed origin, a symlinked or non-canonical path, an out-of-root path, the wrong owner, or invalid Git metadata.

Remove clean, published source with:

```text
orbit instance:remove <id>
```

Use destructive source discard only when you intend to lose dirty or unpublished work:

```text
orbit instance:remove <id> --discard-source
```

With `--discard-source`, Orbit waives the dirty-source and unpublished-commit checks after the Route guard passes. It does not waive origin, symlink, canonical-path, containment, ownership, or repository-identity checks. Orbit removes only the exact recorded checkout. It does not remove sibling, legacy, or unrelated repositories.

## Input boundary

Development AppInstance creation and removal do not accept a repository, command, PHP version, process, or shell input. The App owns the repository. The Node application role owns installation and configuration of selected PHP runtimes. Orbit does not install application dependencies as part of framework detection.

The [Route reference](../reference/routes.md) defines initial private traffic projection and the temporary refusal boundary for Route, Node, Cluster, and access changes that need coordinated runtime and Laravel URL reconciliation.

Caller-local Git worktrees are a separate, externally owned source kind. They are not adopted by `instance:new`; [ADR 0018](../decisions/0018-register-caller-local-development-worktrees.md) defines that registration lifecycle.
