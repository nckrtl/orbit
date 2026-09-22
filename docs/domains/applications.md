---
title: "Applications"
description: "How Orbit creates or adopts, configures, and exposes an Instance on one Node, from Project creation to removal."
---

# Applications

Create an Instance on a Node you choose, or register an existing checkout. The Project stores shared source defaults and a type that decides routing and PHP-FPM. Each Instance has its own location. A `laravel-app` Instance also has one Route. The supported Instance commands are `instance:create`, `instance:list`, `instance:show`, `instance:transfer`, and `instance:destroy`. Those commands resolve Instances owned by their Project and Node. The fleet operator prepares incompatible legacy deployments outside Orbit. Orbit provides no conversion command, API, or SDK operation.

[ADR 0105](/decisions/0105-name-applications-as-project-and-instance) names Project and Instance. [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type) owns type. [ADR 0009](/decisions/0009-clustered-app-instance-routing) defines the development source boundary. [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) defines stable default identity, [ADR 0027](/decisions/0027-adopt-local-git-sources-into-appinstance-ownership) defines owned source layouts, and [ADR 0032](/decisions/0032-preserve-explicit-appinstance-branch-selection) defines explicit branch selection. [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement) defines app-prod placement, and [ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit) defines its release layout.

## Create a Project

New Projects require a type, a repository URL, and a normalized relative web root. The `project:create` command accepts an optional default branch:

```text
orbit project:create \
  acme \
  laravel-app \
  git@github.com:acme/site.git \
  --default-branch=main \
  --root=public
```

When you omit the default branch, the Gateway reads the remote default branch once and stores it. A later remote default change does not rewrite the Project.

A Project can return null for `default_branch` and root when its source defaults are incomplete. New Instance creation fails with `app.source_defaults_incomplete`. Use `project:update` to set complete source defaults on an existing Project.

## Create an app-dev Instance

Select an active Node with an active `app-dev` role. Use `default` for the Project's default development source:

```text
orbit instance:create <project-id> <node-id> default
```

Use another name for a named source, or use `--branch` when either identity must select a different existing remote branch:

```text
orbit instance:create <app-id> <node-id> feature-one [--branch=release] [--domain=feature.example.test]
```

The instance name and Node's [apps root](/reference/node-settings) determine the checkout path. Branch selection is separate.

| Creation input | Managed placement | Selected branch |
| --- | --- | --- |
| `default` without `--branch` | `<node-apps-root>/<app-slug>/default` | The Project `default_branch` |
| Another name without `--branch` | `<node-apps-root>/<app-slug>/<instance-name>` | The matching remote branch, or a new branch from the exact fetched `default_branch` commit |
| Any name with `--branch=<branch>` | The placement for the requested name | The existing remote `<branch>`, or a new `<branch>` from `default_branch` when `<branch>` matches the instance name and the remote branch is missing |

`instance:create` stores the source layout as `checkout`. Each checkout has its own `.git` directory and no shared worktree metadata.

The API and PHP software development kit (SDK) accept optional `branch` input. API, SDK, and command-line interface (CLI) JSON responses return `selected_branch` and nullable `branch_override`. Explicit input stays in `branch_override`, even when it matches `default_branch`; inherited selection returns null. A missing explicit branch that differs from the instance name returns `instance.branch_resolution_failed`. Orbit selects no fallback and activates no Instance or Route. When the explicit branch matches the instance name and the remote branch is missing, Orbit creates that branch from the fetched `default_branch` commit.

Orbit records the branch-selection intent, selected branch, and starting commit before it provisions the application endpoint.

Source preparation moves through three durable states:

```text
reserved -> checkout_prepared -> source_resolved
```

A retry must match the recorded Project, Node, source layout, root, path, repository, branch override, selected branch, and domain input. Orbit also verifies the commit recorded before activation, then resumes the next incomplete step. After activation, development can advance `HEAD` without changing the recorded starting commit. Adding, removing, or changing the branch override returns `instance.placement_conflict` before any changes.

## Register an existing development source

Run registration from an independent Git checkout or a linked worktree on the caller's app-dev Node:

```text
orbit instance:register
```

The CLI rejects directories outside a Git checkout or worktree. It also rejects origins containing credentials without displaying them or contacting the Gateway. The Gateway verifies the source on the authenticated caller's Node before changing Git, files, runtime, Routes, or records.

The Gateway resolves an existing Project by the source's canonical repository identity. The [Projects reference](/reference/apps#resolve-an-app-during-registration) owns Project lookup, inference, confirmation, and missing-Project creation.

`--json` confirms the ownership transfer and disables prompts and the source summary. The CLI returns one JSON document containing the result or an error.

Registration infers Instance placement from verified source facts.

| Verified source | Instance identity | Managed placement |
| --- | --- | --- |
| Top-level directory matches the Project slug and the checked-out branch matches `default_branch` | `default` | `<node-apps-root>/<app-slug>/default` |
| Any other accepted checkout or worktree | The Git top-level directory name | `<node-apps-root>/<app-slug>/<instance-name>` |

An explicit valid value can fill an unresolved or optional value. It cannot replace conflicting verified source identity. Registration infers `public` as the web root only when Laravel detection is unambiguous.

Orbit records `checkout` for an independent repository and `worktree` for a linked worktree. It moves the complete source to the managed path. HEAD, branch or detached state, index, dirty and untracked files, refs, commits, and unrelated settings stay intact. A source already at the correct path stays there.

Registration adopts only the caller's source by default. After moving a shared checkout, Orbit repairs links so other worktrees remain usable and unregistered. Use `--include-worktrees` to adopt the checkout and all linked worktrees together. Before moving anything, the Gateway checks each source's Git identity, metadata ownership and permissions, instance name, and destination. It also checks for overlap with managed Instances. If any check fails, nothing moves.

The registration API preserves its accepted boolean forms for `include_worktrees`: `true`, `1`, and `"1"` select the complete source set; `false`, `0`, `"0"`, or omission select only the caller's source. Equivalent accepted forms keep the same source-set intent on retry.

For a cross-filesystem move, Orbit stages and verifies the complete source at the destination before it removes the original. Durable progress binds original cleanup to the verified source directory identity and keeps one verified authoritative copy after interruption. An identical retry revalidates the canonical authoritative path, repository identity, checkout or worktree layout, and provisioning safety without requiring an unchanged source digest. After relocation, the CLI can retry from the managed primary source path while Orbit retains the original primary and complete requested set. It resumes the same Project, Instances, Routes, and managed paths; conflicting input preserves the accepted registration.

Each relocation stage has a private receipt bound to the registration, Instance, source, destination, parent directories, and exact stage directory. Orbit saves an attempt ID, initializes only metadata, and saves its scope identity in the Gateway before it records and fills an empty stage. It claims and rechecks an owned stage before cleanup. The Gateway retains the current scope identity. Each native attempt retains one bounded receipt outside source content for interrupted retries and lost acknowledgments. Reverse moves and later attempts use separate identities; operator review owns retirement of the retained metadata.

A replaced parent, conflicting receipt, or unknown stage stops relocation without removing that content. This includes legacy `.orbit-stage-<Instance ID>` paths: even matching Git content or another verified copy does not prove ownership. The fixed-size receipt keeps two checksummed checkpoints so an interrupted later write can resume from the previous valid phase. An ambiguous first receipt write or an empty stage without its saved identity still requires operator review. Preserve these paths and the registration evidence; do not remove them merely to force a retry.

Private receipt directories use mode `0700`; receipt files use mode `0600`. This boundary trusts the Node's execution user to keep its private metadata intact. It does not protect against hostile code running as that same user and forging receipts. Directory creation and opening are separate native operations; Orbit checks observed identities before copying but does not claim an atomic create-and-open operation.

## Create a production Instance

The Gateway refuses new production placement on `instance:create` with `instance.candidate_required` before it changes a user, home, source, environment, or Route. The CLI reports that error and directs the caller to `instance:clone`. Clone from an eligible development or production candidate, as [Instance cloning](/reference/appinstance-cloning) describes.

```text
orbit instance:clone CANDIDATE NODE NAME --preview-name=shop.com
```

A given Project can have one production Instance per app-prod Node. The same Project can use another app-prod Node, where it receives an independent user home and runtime. The recorded user and home do not change when the Project slug changes.

### Keep existing production Instances

When an Instance is already active in production, the Gateway still shows, deploys, routes, inspects, and removes it without candidate metadata. When the same `instance:create` request matches that completed production Instance, the Gateway returns it without fetching or overwriting it.

Cloning produces each production Instance, and the first deployment produces the release layout. See [Instance cloning](/reference/appinstance-cloning) and [Production release layout](/reference/deployments).

Orbit owns later release preparation, activation, and explicit code rollback. The operating agent configures application steps and owns compatibility and recovery decisions. The [PHP runtime reference](/reference/php-runtime#production-cache-boundary) defines the separate cache boundary.

## Complete a required source migration

An Instance can require manual migration when its stored name follows the earlier branch-named default identity. Orbit keeps that name, checkout path, selected branch, source, and Route authoritative until an operator runs `instance:register` from its recorded source. List and show responses return `migration_required: true`, Doctor reports the same bounded condition, and the existing Route continues to serve the same source path.

Registration verifies the recorded source, moves it to the managed `default` placement, and updates its identity and runtime while it preserves the Route domain. Before it publishes the new record, Orbit stores the original Instance state and Route intent as durable recovery evidence. An identical retry resumes the same migration even after process interruption. A failed migration keeps the old record, path, runtime, Route, and original Laravel URL configuration authoritative. Database rollback refuses to discard registration or source-cleanup evidence while the related operation is incomplete.

An occupied `default` identity, an overlapping Orbit-managed destination, or an occupied unmanaged destination returns `instance.migration_conflict` with a bounded message that identifies the cause and preserves every existing Instance, source, and Route.

## Provision the application endpoint

Before source or runtime changes, the Gateway resolves the Route domain. The optional `--domain` value requests an explicit domain for the caller's primary source and takes precedence over its generated name. Other members of an included worktree set use their generated Route names. Without an explicit primary domain, the Gateway uses the Node or Cluster naming basis described in the [Route reference](/reference/routes). The request fails before source or runtime mutation when neither basis can produce a domain.

After creating or adopting source, the Gateway identifies its type and selects any required [PHP runtime](/reference/php-runtime). It connects the Instance to one Route and prepares certificates, Caddy, firewall rules, and private Domain Name System (DNS) records. Supported development sources also receive Laravel configuration. Cluster development Routes include Router setup.

At the first retained provisioning checkpoint, the Gateway records the complete development source profile in one database update: the selected PHP version, including no PHP runtime, and whether the source is Laravel. A retry at the `php-selected` or `url-configured` checkpoint inspects the source again and requires the exact same pair before it changes Laravel URL configuration or Route projection. A changed PHP version, a change between PHP and non-PHP, or a change between Laravel and plain PHP returns `app-dev.source_evidence_changed`.

An Instance created before complete profiles were recorded can have a non-active `php-selected` or `url-configured` checkpoint with missing Laravel evidence. An ordinary retry returns `app-dev.source_evidence_changed` before URL, runtime, or Route projection changes. Orbit does not infer or backfill the missing classification during migration.

To recover that legacy checkpoint, repeat the same creation request with `--recover-source-profile`. The Gateway API and PHP SDK accept the optional boolean field `recover_source_profile`; the CLI omits that field unless the option is present. Recovery still verifies the recorded request identity, source ownership, selected Git branch, and starting commit. It then adopts the currently inspected complete profile, restarts only the incomplete provisioning checkpoint, and continues normal provisioning without replacing the source, Instance, placement, or Route.

The same option recovers an active development or production Instance whose recorded source profile is missing. The Gateway inspects the recorded source once, stores the complete profile, and returns the unchanged active Instance and Route without reprovisioning. An identical retry against an active Instance that already has a profile returns that Instance without inspecting the source again or changing records.

For a recovered Laravel profile, the option permits Orbit to reconcile the canonical URL through its existing idempotent operation. If a request stops after the remote URL write and before checkpoint persistence, another identical retry safely performs the reconciliation and continues. A complete profile that later drifts remains a refusal even when the recovery option is present.

Orbit records each completed step so retries do not duplicate source or Routes. Database rollback preserves complete profiles at non-active `php-selected` or `url-configured` checkpoints. Once every Orbit setup step succeeds, the response returns the active Instance, Route, domain, and HTTPS URL.

## Configure a Laravel URL

Orbit detects Laravel only when the source has both a regular, non-symlink `artisan` file and a valid `composer.json` file that declares `laravel/framework`. A source with neither marker is not Laravel. Partial, malformed, conflicting, duplicate, or unsafe marker evidence stops provisioning before configuration or publication.

Detection and URL configuration do not run Composer, Artisan, installed application code, or application bootstrap. Framework detection does not install dependencies or infer setup commands.

Orbit sets Laravel's canonical application URL to `https://<route-domain>`. When `.env` exists, Orbit changes only `APP_URL` and preserves every unrelated byte. When `.env` is missing and an environment template exists, Orbit preserves the template's installation inputs and adds or replaces `APP_URL`. Orbit also replaces a static cached `app.url` without changing unrelated cached configuration. A symlinked, malformed, duplicate, or otherwise unsafe configuration file stops provisioning before publication.

After activation, an operator can explicitly import the recorded `.env` or update one encrypted Gateway-owned value without changing the workload file. The [Instance environment-variable reference](/reference/environment-variables) describes the API, selectors, replacement behavior, limits, placeholders, encryption recovery, and stored-only effects.

## Handle provisioning and application errors

The Gateway reports a failed source, PHP selection, Laravel URL, runtime, certificate, firewall, or publication boundary and does not return a provisioned Instance. Secret environment values, certificate material, and private keys do not appear in command arguments, errors, API responses, activity data, or debug output.

Active means Orbit prepared the source, PHP runtime if needed, supported Laravel configuration, and Route. The application can still fail. Missing dependencies, an application key, or a database can cause HTTP 500 while the Instance and Route remain active.

Retrying creation for an active Instance with a recorded profile returns it unchanged. Use the endpoint to inspect the application and finish setup. Application setup commands run separately from provisioning.

## Reconcile a Project update

`app:update` keeps one Project identity while it reconciles source defaults that Instances already inherit. Creation stays a separate idempotent operation. [Projects](/reference/apps#update-an-app) owns the command fields, failure codes, and retry contract.

### Inherited source and Route effects

When `default_branch` changes, Orbit switches the development `default` checkout or worktree that inherits the Project default. The instance name, managed path, and Route stay the same. An instance with `branch_override` keeps that branch even when the override equals the old default. [ADR 0032](/decisions/0032-preserve-explicit-appinstance-branch-selection) owns that inheritance boundary.

When the repository access URL changes, Orbit updates Orbit-owned development checkouts. A checkout owns its `.git` directory. A worktree owns its working directory and uses the checkout's common repository. The Gateway changes `origin` on the checkout once and leaves the worktree's common repository untouched. It refuses the update when a worktree points at a common repository no Orbit-owned checkout owns.

When the slug changes, Orbit replaces each generated development Route domain. The replacement keeps the Project, Cluster, target, and publication intent. Explicit domains, including production domains, do not change. Checkout paths, production users, and homes stay as recorded. [Routes](/reference/routes#generated-domains-after-an-app-slug-update) owns the replacement contract.

Orbit owns the Laravel canonical application URL on development and production Instances. The URL comes from the Instance's authoritative Route domain as `https://<domain>`. During a slug update the Gateway updates stored environment configuration and the workload `.env` projection for that URL, then updates cached `app.url` when a Laravel config cache exists. Environment synchronization does not run Artisan, refresh an application cache, or restart a process. [Environment variables](/reference/environment-variables#app-update-boundary) owns that split.

When the web root changes, Orbit applies it to every Instance whose own root override is null. Production serves the new root inside the active release through `current`. The update does not fetch a branch, run deploy steps, or replace a release. [Production release layout](/reference/deployments#app-updates) owns that production boundary.

When a slug or web root change reprojects production PHP FastCGI Process Manager (PHP-FPM), Orbit keeps operator `local.conf` tuning, validates the effective runtime configuration before an activation or reload, and leaves other production users' services and caches unchanged. [PHP runtimes](/reference/php-runtime#app-updates) owns that runtime boundary.

## Set the web root

By default, an Instance inherits the Project root. Use the root option to store a relative override:

```text
orbit instance:create <app-id> <node-id> feature-one \
  --root=site/public
```

The web root uses the instance override when set, or the Project default otherwise. Development output returns the relative path. Production output resolves it inside the selected release through the home's `current` link. Orbit rejects empty or absolute paths, parent traversal, and paths that escape the production release. A missing `current` link means the home is ready but no code is selected.

## Remove an Instance

Remove clean, published source with:

```text
orbit instance:destroy <id>
```

Use forced removal only when you intend to lose dirty or unpublished work:

```text
orbit instance:destroy <id> --force
```

Production removal uses the same command without deleting application content. It retains a shared Route and republishes its surviving production targets, or deletes a final-target Route and releases its domain. The [Instance removal reference](/reference/appinstance-removal) describes development source preflight, retained production content, Route cleanup, the `removing` state, bounded progress, refusals, and safe retry.

The removal reference also describes worktree preflight, forced fixed-set cascades, retained branches, ordered cleanup, and transient unavailable traffic.

`instance:destroy` removes an Instance owned by its Project and Node. Development removal deletes the recorded source checkout or worktree. Production removal retains application content in the production home.

## Move an Instance

`instance:transfer` moves one active development Instance to a distinct active app-dev Node in an active Cluster and keeps the Instance ID. The destination may be in the same Cluster or another Cluster.

```text
orbit instance:transfer INSTANCE NODE [--name=NAME] [--sqlite-source-path=PATH] [--force]
```

The Gateway reserves `<destination-apps-root>/<app-slug>/<instance-name>`, copies the source into an independent destination checkout, stops source execution for the downtime window, and deletes the old managed placement after cutover. The [Instance transfer reference](/reference/appinstance-transfer) describes eligibility, destination naming, preserved state, domain behavior, failure recovery, retry, and cleanup. [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes) owns the transfer decision.

## Input boundary

Instance creation and removal do not accept a repository, command, process, or shell input. Registration accepts bounded source facts for independent Gateway verification; it does not accept a command, process, shell input, or caller-selected Node. The Project owns the repository, and the optional creation branch selects source without changing placement or Route identity. Orbit does not install application dependencies as part of framework detection.

The [Route reference](/reference/routes) defines initial private traffic projection, Node and Cluster TLD reconciliation, and Cluster activation and deactivation reconciliation. It also states the refusal boundary for Route, membership, and access changes that still need coordinated runtime and Laravel URL work.

`instance:create` creates a new checkout. `instance:register` adopts a caller-local checkout or worktree and can complete the manual default-source migration. Both commands end in the same Instance provisioning and removal lifecycle.
