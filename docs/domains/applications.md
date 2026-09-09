# Applications

This page tells an operator how Orbit creates, configures, and exposes an AppInstance on one manually selected application Node. An App stores shared source defaults, and each AppInstance owns one placement and one Route.

[ADR 0009](../decisions/0009-clustered-app-instance-routing.md) defines the development source boundary. [ADR 0025](../decisions/0025-stabilize-the-default-appinstance-identity.md) defines stable default identity, [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md) defines owned source layouts, and [ADR 0032](../decisions/0032-preserve-explicit-appinstance-branch-selection.md) defines explicit branch selection. [ADR 0011](../decisions/0011-clustered-production-ingress-and-app-prod-placement.md) defines the separate production placement contract.

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

Use another name for a named source, or use `--branch` when either identity must select a different existing remote branch:

```text
orbit instance:new <app-id> <node-id> feature-one [--branch=release] [--hostname=feature.example.test]
```

The Gateway derives placement from the requested identity and selects the branch independently.

| Creation input | Managed placement | Selected branch |
| --- | --- | --- |
| `default` without `--branch` | `<node-apps-root>/<app-slug>/default` | The App `default_branch` |
| Another name without `--branch` | `<node-apps-root>/<app-slug>/<instance-name>` | The matching remote branch, or a new branch from the exact fetched `default_branch` commit |
| Any name with `--branch=<branch>` | The placement for the requested name | The existing remote `<branch>` |

`instance:new` stores source layout `checkout`. The checkout has its own `.git` directory and does not use a Workspace or shared worktree administration. The `worktree` layout identifies a linked Git worktree under AppInstance ownership, but Orbit exposes no command that adopts or migrates an existing source.

The API and PHP SDK accept the optional `branch` input. API, SDK, and CLI JSON responses return the resolved branch as `selected_branch`. They return the explicit input as nullable `branch_override`, including when it equals `default_branch`; inherited selection returns null. An explicit branch that does not exist returns `instance.branch_resolution_failed` without a fallback or an active AppInstance or Route.

Orbit records the branch-selection intent, selected branch, and starting commit before it provisions the application endpoint.

Source preparation moves through three durable states:

```text
reserved -> checkout_prepared -> source_resolved
```

An identical retry verifies the recorded App, Node, source layout, root, path, repository, branch override, selected branch, hostname input, and pre-activation commit evidence. It then resumes the next incomplete source or provisioning boundary. Once active, the recorded starting commit stays unchanged while normal development advances `HEAD`. Adding, removing, or changing the branch override returns `instance.placement_conflict` before database, Git, filesystem, configuration, runtime, or Route mutation.

## Create a standalone production AppInstance

Select one active standalone Node with an active app-prod role. The same command creates a production placement when the selected Node carries that role:

```text
orbit instance:new <app-id> <app-prod-node-id> primary [--branch=release] [--root=current/public] [--hostname=app.example.test]
```

The Gateway records one dedicated system user and `/home/<app-user>` home for the App on that Node. It clones the App repository into that home before runtime or Route publication. An omitted branch selects the App `default_branch`, even when a remote branch matches the AppInstance name. An explicit branch must exist and remains independent from the AppInstance name. The response returns the recorded user, home, absolute effective root, selected initial branch, exact starting commit, nullable branch override, and sole Route.

A given App can have one production AppInstance per app-prod Node. The same App can use another app-prod Node, where it receives an independent user home and runtime. The recorded user and home do not change when the App slug changes.

Production source preparation retains the same `reserved`, `checkout_prepared`, and `source_resolved` checkpoints. The Gateway records the complete source profile before runtime work. A plain PHP source gets the selected PHP-FPM pool and socket. A non-PHP source gets no PHP runtime. Detected Laravel source stops at its safely recorded initial-source checkpoint until the separate Laravel production contract is available; Orbit does not change Laravel files or publish its Route in this state.

Production creation requires an explicit Route hostname or a TLD from the standalone Node. A Node in an active Cluster is outside this creation path. Both refusals happen before production source or runtime mutation.

A standalone Node cannot accept a private production AppInstance while it still serves a provisioning or active legacy public production Instance. The Gateway returns `instance.legacy_production_conflict` with HTTP 409 before it reserves an AppInstance or changes a Route, source checkout, runtime, certificate, or firewall. Mark or remove the legacy Instance through its existing lifecycle, then repeat the production creation request.

An identical retry resumes only incomplete Orbit-owned preparation. After creation succeeds, the same request returns the recorded result without running Git or changing source, refs, releases, deployment symlinks, or other operator content. The operator or deployment agent owns every later production source change and must follow the [production PHP reload contract](../reference/php-runtime.md#production-deploy-contract).

## Complete a required source migration

An AppInstance can require manual migration when its stored name follows the earlier branch-named default identity. Orbit keeps that name, checkout path, selected branch, source, and Route authoritative until an operator completes migration outside the current command set. List and show responses return `migration_required: true`, Doctor reports the same bounded condition, and the existing Route continues to serve the same source path.

The Gateway returns `instance.migration_required` before database, Git, filesystem, runtime, or Route mutation when an operation would remove, rebind, or change this source. Read-only inspection remains available, and Orbit can still reconcile its Route without changing the source. An occupied `default` identity, an overlapping Orbit-managed destination, or an occupied unmanaged destination returns `instance.migration_conflict` with a bounded message that identifies the cause and preserves every existing AppInstance, source, and Route.

## Provision the application endpoint

Before source or runtime changes, the Gateway resolves the Route hostname. The optional `--hostname` value requests an explicit hostname and takes precedence over generated naming. Without it, the Gateway uses the Node or Cluster naming basis described in the [Route reference](../reference/routes.md). The request fails before source or runtime mutation when neither basis can produce a hostname.

After source resolution, the Gateway classifies the source and selects any required PHP runtime. It associates the AppInstance with its sole Route and prepares certificates, Caddy, firewall, and private Domain Name System (DNS) projection. Supported creation paths also configure Laravel. A Cluster-scoped development Route prepares the Router path. The [PHP runtime reference](../reference/php-runtime.md) describes source-driven runtime selection.

At the first retained provisioning checkpoint, the Gateway records the complete development source profile in one database update: the selected PHP version, including no PHP runtime, and whether the source is Laravel. A retry at the `php-selected` or `url-configured` checkpoint inspects the source again and requires the exact same pair before it changes Laravel URL configuration or Route projection. A changed PHP version, a change between PHP and non-PHP, or a change between Laravel and plain PHP returns `app-dev.source_evidence_changed`.

An AppInstance created before complete profiles were recorded can have a non-active `php-selected` or `url-configured` checkpoint with missing Laravel evidence. An ordinary retry returns `app-dev.source_evidence_changed` before URL, runtime, or Route projection changes. Orbit does not infer or backfill the missing classification during migration.

To recover that legacy checkpoint, repeat the same creation request with `--recover-source-profile`. The Gateway API and PHP SDK accept the optional boolean field `recover_source_profile`; the CLI omits that field unless the option is present. Recovery still verifies the recorded request identity, source ownership, selected Git branch, and starting commit. It then adopts the currently inspected complete profile, restarts only the incomplete provisioning checkpoint, and continues normal provisioning without replacing the source, AppInstance, placement, or Route.

For a recovered Laravel profile, the option permits Orbit to reconcile the canonical URL through its existing idempotent operation. If a request stops after the remote URL write and before checkpoint persistence, another identical retry safely performs the reconciliation and continues. A complete profile that later drifts remains a refusal even when the recovery option is present.

Orbit records each completed boundary. The same request can continue after a failure without duplicating source or Route records. Database rollback refuses to discard a complete profile while a non-active AppInstance retains the `php-selected` or `url-configured` checkpoint. Orbit returns the active AppInstance with its Route, hostname, and HTTPS URL when every provisioning step owned by Orbit succeeds.

## Configure a Laravel URL

Orbit detects Laravel only when the source has both a regular, non-symlink `artisan` file and a valid `composer.json` file that declares `laravel/framework`. A source with neither marker is not Laravel. Partial, malformed, conflicting, duplicate, or unsafe marker evidence stops provisioning before configuration or publication.

Detection and URL configuration do not run Composer, Artisan, installed application code, or application bootstrap. Framework detection does not install dependencies or infer setup commands.

Orbit sets Laravel's canonical application URL to `https://<route-hostname>`. When `.env` exists, Orbit changes only `APP_URL` and preserves every unrelated byte. When `.env` is missing and an environment template exists, Orbit preserves the template's installation inputs and adds or replaces `APP_URL`. Orbit also replaces a static cached `app.url` without changing unrelated cached configuration. A symlinked, malformed, duplicate, or otherwise unsafe configuration file stops provisioning before publication.

## Handle provisioning and application errors

The Gateway reports a failed source, PHP selection, Laravel URL, runtime, certificate, firewall, or publication boundary and does not return a provisioned AppInstance. Secret environment values, certificate material, and private keys do not appear in command arguments, errors, API responses, activity data, or debug output.

Active state means that Orbit prepared the source, selected any required PHP runtime, aligned Laravel configuration when applicable, and prepared the Route. It does not promise that the application is healthy. Missing dependencies, an application key, or a database can make a new Laravel application return an error, including HTTP 500, without making the AppInstance or Route inactive.

An active AppInstance is terminal for creation retry. The Gateway does not inspect its source profile again, including when `recover_source_profile` is true. The existing removal operation and its source and Route checks remain unchanged.

The endpoint is available for an agent or operator to inspect and finish application setup. App setup-step configuration and execution belong to a separate contract.

## Set the effective web root

By default, an AppInstance inherits the App root. Use the root option to store a relative override:

```text
orbit instance:new <app-id> <node-id> feature-one \
  --root=site/public
```

The effective root is the AppInstance root when set and the App root otherwise. Development output returns this relative value. Production output resolves it below the recorded home. Orbit rejects empty values, absolute paths, and parent traversal. A production path such as `current/public` can remain unresolved until deployment; when it resolves, every component must stay in the recorded home and have the expected ownership.

## Remove an AppInstance

Remove clean, published source with:

```text
orbit instance:remove <id>
```

Use forced removal only when you intend to lose dirty or unpublished work:

```text
orbit instance:remove <id> --force
```

Production removal uses the same command without deleting application content. It retains a shared Route and republishes its surviving production targets, or deletes a final-target Route and releases its hostname. The [AppInstance removal reference](../reference/appinstance-removal.md) describes development source preflight, retained production content, Route cleanup, the `removing` state, bounded progress, refusals, and safe retry.

The removal reference also describes worktree preflight, forced fixed-set cascades, retained branches, ordered cleanup, and transient unavailable traffic.

## Input boundary

AppInstance creation and removal do not accept a repository, command, process, or shell input. The App owns the repository, and the optional branch input selects source without changing placement or Route identity. Orbit does not install application dependencies as part of framework detection.

The [Route reference](../reference/routes.md) defines initial private traffic projection and the refusal boundary for Route, Node, Cluster, and access changes that still need coordinated runtime and Laravel URL reconciliation.

`instance:new` does not adopt caller-local Git sources. Orbit exposes no adoption or manual migration command. [ADR 0027](../decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md) defines the ownership and safety boundary for that separate contract.
