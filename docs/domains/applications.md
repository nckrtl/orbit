# Applications

Create an App instance on a Node you choose, or register an existing checkout. The App stores shared source defaults. Each App instance has its own location and Route.

[ADR 0009](/decisions/0009-clustered-app-instance-routing) defines the development source boundary. [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) defines stable default identity, [ADR 0027](/decisions/0027-adopt-local-git-sources-into-appinstance-ownership) defines owned source layouts, and [ADR 0032](/decisions/0032-preserve-explicit-appinstance-branch-selection) defines explicit branch selection. [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement) defines production placement, and [ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit) defines its release layout.

## Create an App

New Apps require a repository URL and a normalized relative web root. The `app:create` command accepts an optional default branch:

```text
orbit app:create \
  acme \
  git@github.com:acme/site.git \
  --default-branch=main \
  --root=public
```

When you omit the default branch, the Gateway reads the remote default branch once and stores it. A later remote default change does not rewrite the App.

An App can return null for `default_branch` and root when its source defaults are incomplete. Existing legacy Instance and Workspace records continue to use that App. New App instance creation fails with `app.source_defaults_incomplete` until a separate conversion lifecycle supplies the missing values. Orbit has no command that updates or backfills them.

## Create a development App instance

Select an active Node with an active `app-dev` role. Use `default` for the App's default development source:

```text
orbit instance:create <app-id> <node-id> default
```

Use another name for a named source, or use `--branch` when either identity must select a different existing remote branch:

```text
orbit instance:create <app-id> <node-id> feature-one [--branch=release] [--hostname=feature.example.test]
```

The instance name and Node's [apps root](/reference/node-settings) determine the checkout path. Branch selection is separate.

| Creation input | Managed placement | Selected b…1171 tokens truncated… App instances, Routes, and managed paths; conflicting input preserves the accepted registration.

## Create a production App instance

The Gateway refuses new production placement on `instance:create` with `instance.candidate_required` before it changes a user, home, source, environment, or Route. The CLI reports that error and directs the caller to `instance:clone`. Clone from an eligible development or production candidate, as [App instance cloning](/reference/appinstance-cloning) describes.

```text
orbit instance:clone CANDIDATE NODE NAME --preview-name=shop.com
```

A given App can have one production App instance per app-prod Node. The same App can use another app-prod Node, where it receives an independent user home and runtime. The recorded user and home do not change when the App slug changes.

### Keep existing production App instances

When an App instance is already active in production, the Gateway still shows, deploys, routes, inspects, and removes it without candidate metadata. When the same `instance:create` request matches that completed production App instance, the Gateway returns it without fetching or overwriting it.

When an existing active production App instance still uses a recorded flat source and shared PHP runtime, the operator converts that placement with `orbit instance:prepare-deployment`. The [production release-layout reference](/reference/deployments#convert-an-existing-production-home) describes its preflight, retained content, optional SQLite move, dedicated runtime, and retry boundary. Conversion does not require a candidate and does not run an application deployment.

Orbit owns later release preparation, activation, and explicit code rollback. The operating agent configures application steps and owns compatibility and recovery decisions. The [PHP runtime reference](/reference/php-runtime#production-cache-boundary) defines the separate cache boundary.

## Complete a required source migration

An App instance can require manual migration when its stored name follows the earlier branch-named default identity. Orbit keeps that name, checkout path, selected branch, source, and Route authoritative until an operator runs `instance:register` from its recorded source. List and show responses return `migration_required: true`, Doctor reports the same bounded condition, and the existing Route continues to serve the same source path.

Registration verifies the recorded source, moves it to the managed `default` placement, and updates its identity and runtime while it preserves the Route hostname. Before it publishes the new record, Orbit stores the original App instance state and Route intent as durable recovery evidence. An identical retry resumes the same migration even after process interruption. A failed migration keeps the old record, path, runtime, Route, and original Laravel URL configuration authoritative. Database rollback refuses to discard registration or source-cleanup evidence while the related operation is incomplete.

An occupied `default` identity, an overlapping Orbit-managed destination, or an occupied unmanaged destination returns `instance.migration_conflict` with a bounded message that identifies the cause and preserves every existing App instance, source, and Route.

## Provision the application endpoint

Before source or runtime changes, the Gateway resolves the Route hostname. The optional `--hostname` value requests an explicit hostname for the caller's primary source and takes precedence over its generated name. Other members of an included worktree set use their generated Route names. Without an explicit primary hostname, the Gateway uses the Node or Cluster naming basis described in the [Route reference](/reference/routes). The request fails before source or runtime mutation when neither basis can produce a hostname.

After creating or adopting source, the Gateway identifies its type and selects any required [PHP runtime](/reference/php-runtime). It connects the App instance to one Route and prepares certificates, Caddy, firewall rules, and private Domain Name System (DNS) records. Supported development sources also receive Laravel configuration. Cluster development Routes include Router setup.

At the first retained provisioning checkpoint, the Gateway records the complete development source profile in one database update: the selected PHP version, including no PHP runtime, and whether the source is Laravel. A retry at the `php-selected` or `url-configured` checkpoint inspects the source again and requires the exact same pair before it changes Laravel URL configuration or Route projection. A changed PHP version, a change between PHP and non-PHP, or a change between Laravel and plain PHP returns `app-dev.source_evidence_changed`.

An App instance created before complete profiles were recorded can have a non-active `php-selected` or `url-configured` checkpoint with missing Laravel evidence. An ordinary retry returns `app-dev.source_evidence_changed` before URL, runtime, or Route projection changes. Orbit does not infer or backfill the missing classification during migration.

To recover that legacy checkpoint, repeat the same creation request with `--recover-source-profile`. The Gateway API and PHP SDK accept the optional boolean field `recover_source_profile`; the CLI omits that field unless the option is present. Recovery still verifies the recorded request identity, source ownership, selected Git branch, and starting commit. It then adopts the currently inspected complete profile, restarts only the incomplete provisioning checkpoint, and continues normal provisioning without replacing the source, App instance, placement, or Route.

The same option recovers an active development or production App instance whose recorded source profile is missing. The Gateway inspects the recorded source once, stores the complete profile, and returns the unchanged active App instance and Route without reprovisioning. An identical retry against an active App instance that already has a profile returns that App instance without inspecting the source again or changing records.

For a recovered Laravel profile, the option permits Orbit to reconcile the canonical URL through its existing idempotent operation. If a request stops after the remote URL write and before checkpoint persistence, another identical retry safely performs the reconciliation and continues. A complete profile that later drifts remains a refusal even when the recovery option is present.

Orbit records each completed step so retries do not duplicate source or Routes. Database rollback preserves complete profiles at non-active `php-selected` or `url-configured` checkpoints. Once every Orbit setup step succeeds, the response returns the active App instance, Route, hostname, and HTTPS URL.

## Configure a Laravel URL

Orbit detects Laravel only when the source has both a regular, non-symlink `artisan` file and a valid `composer.json` file that declares `laravel/framework`. A source with neither marker is not Laravel. Partial, malformed, conflicting, duplicate, or unsafe marker evidence stops provisioning before configuration or publication.

Detection and URL configuration do not run Composer, Artisan, installed application code, or application bootstrap. Framework detection does not install dependencies or infer setup commands.

Orbit sets Laravel's canonical application URL to `https://<route-hostname>`. When `.env` exists, Orbit changes only `APP_URL` and preserves every unrelated byte. When `.env` is missing and an environment template exists, Orbit preserves the template's installation inputs and adds or replaces `APP_URL`. Orbit also replaces a static cached `app.url` without changing unrelated cached configuration. A symlinked, malformed, duplicate, or otherwise unsafe configuration file stops provisioning before publication.

After activation, an operator can explicitly import the recorded `.env` or update one encrypted Gateway-owned value without changing the workload file. The [App instance environment-variable reference](/reference/environment-variables) describes the API, selectors, replacement behavior, limits, placeholders, encryption recovery, and stored-only effects.

## Handle provisioning and application errors

The Gateway reports a failed source, PHP selection, Laravel URL, runtime, certificate, firewall, or publication boundary and does not return a provisioned App instance. Secret environment values, certificate material, and private keys do not appear in command arguments, errors, API responses, activity data, or debug output.

Active means Orbit prepared the source, PHP runtime if needed, supported Laravel configuration, and Route. The application can still fail. Missing dependencies, an application key, or a database can cause HTTP 500 while the App instance and Route remain active.

Retrying creation for an active App instance with a recorded profile returns it unchanged. Use the endpoint to inspect the application and finish setup. Application setup commands run separately from provisioning.

## Set the web root

By default, an App instance inherits the App root. Use the root option to store a relative override:

```text
orbit instance:create <app-id> <node-id> feature-one \
  --root=site/public
```

The web root uses the instance override when set, or the App default otherwise. Development output returns the relative path. Production output resolves it inside the selected release through the home's `current` link. Orbit rejects empty or absolute paths, parent traversal, and paths that escape the production release. A missing `current` link means the home is ready but no code is selected.

## Remove an App instance

Remove clean, published source with:

```text
orbit instance:destroy <id>
```

Use forced removal only when you intend to lose dirty or unpublished work:

```text
orbit instance:destroy <id> --force
```

Production removal uses the same command without deleting application content. It retains a shared Route and republishes its surviving production targets, or deletes a final-target Route and releases its hostname. The [App instance removal reference](/reference/appinstance-removal) describes development source preflight, retained production content, Route cleanup, the `removing` state, bounded progress, refusals, and safe retry.

The removal reference also describes worktree preflight, forced fixed-set cascades, retained branches, ordered cleanup, and transient unavailable traffic.

## Move an App instance

Orbit exposes no HTTP route, CLI command, or PHP SDK method that moves an App instance to another Node while preserving its ID. [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes) records the Gateway obligations for that move.

## Input boundary

App instance creation and removal do not accept a repository, command, process, or shell input. Registration accepts bounded source facts for independent Gateway verification; it does not accept a command, process, shell input, or caller-selected Node. The App owns the repository, and the optional creation branch selects source without changing placement or Route identity. Orbit does not install application dependencies as part of framework detection.

The [Route reference](/reference/routes) defines initial private traffic projection and the refusal boundary for Route, Node, Cluster, and access changes that still need coordinated runtime and Laravel URL reconciliation.

`instance:create` creates a new checkout. `instance:register` adopts a caller-local checkout or worktree and can complete the manual default-source migration. Both commands end in the same App instance provisioning and removal lifecycle.
