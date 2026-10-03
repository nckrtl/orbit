---
title: "Derive the application directory from the web root"
description: "Separate a Laravel application's directory from its repository, and keep the path contract ready for several named apps per Project."
---

# ADR 0195: Derive the application directory from the web root

Orbit derives one Laravel application directory from the effective web root. Repository operations keep their repository scope. A follow-up group replaces the single root with named apps without creating another Project for the same repository.

## Status

In progress.

Principle: [One way, one name and Deterministic first](/mission#principles). There is no exception to a mission principle.

## Context

A repository can contain a Laravel application in `apps/site` while its Git checkout starts two directories above it. Today, [Project root](/reference/projects#fields) names the web root, not the directory that holds `artisan`, `composer.json`, or `.env`. Treating the checkout as the application directory sends environment writes, source inspection, and runtime commands to the wrong place.

This decision spans two groups. Group #975 serves one Laravel app from a subfolder using the existing Project and Instance root fields. The follow-up multi-app group serves several apps from the same repository. The first group does not add app records, app selectors, or several Routes per Instance.

## Decision

Orbit separates application paths from repository paths now and uses named apps to extend that boundary in the follow-up group.

### Application directory for the single-app group

The effective web root is the Instance's root override, or the Project's root when there is no override. For a Laravel Instance, the application directory is that web root without its trailing `/public` segment. A root of `public` means the checkout root in development, or the release root in production. For example, `apps/site/public` means `apps/site`; the removal is a path-segment operation, not a string replacement of every occurrence of `public`.

One shared helper derives this directory. Source classification and every Laravel runtime consumer use it instead of repeating path arithmetic. It resolves against the development checkout or selected production release and keeps the existing relative-path and containment rules. Package roots such as `.` do not become Laravel applications through this rule. The helper does not search for an application, infer a nested web root, or change the stored root.

The application directory owns `composer.json`, `artisan`, development `.env` and `.env.testing`, Laravel cached configuration and `storage/logs`. Laravel source inspection, the working directory for PHP-FPM, automatic APP_URL updates, default Instance systemd Process working directories, and Instance Schedule working directories use it. Explicit Process working directories still override the default. Production runtime paths use `<production-home>/current/<application-directory>`, with no suffix for a root-level application.

Production keeps its durable environment at `<production-home>/.env`. Each release links `<release>/<application-directory>/.env` to that file, with a relative target computed from the link's actual depth. A nested application must not leave a spurious `.env` link at the release root. Development defaults keep environment files in the application directory of their stable checkout home and copy them to the same relative directory in each candidate release; they do not link them back to the live seed.

Setup, teardown, development deploy steps, production deploy steps, and task-check commands keep running at the repository root (the release's repository root for deploy steps). They are repository-owned commands, not implicit Artisan commands. A step for a nested application must say, for example, `cd apps/site && php artisan migrate --force`. Git operations, checkout identity, release layout, and task metadata remain rooted at the repository.

Registration adopts the entire repository checkout or worktree and inherits the configured Project root unless the caller sends an explicit Instance root override. It does not scan for nested `artisan` or `public` directories. The operator configures `--root=apps/site/public`; registering a checkout is not a second discovery or configuration path.

### Dependency and lifecycle scope

For a Laravel Instance, dependency scans and constrained updates use the same derived application directory. They read only that directory's Composer and JavaScript manifests, lockfiles, and manager signals, not manifests at the repository root or in sibling apps. A nested app is one dependency tree; it is not an instruction to aggregate a monorepo. Existing refusals for workspaces, several importers, path repositories, and local links remain. Non-Laravel Instances keep their repository-root dependency scope.

The scan's `source.project_root` names the directory actually read: `<checkout>/apps/site` in development or `<home>/releases/<name>/apps/site` for a production root of `apps/site/public`. Production collection still validates and pins `current` to its selected release, then descends to the application directory; a release or source change during collection fails rather than mixing observations. Development updates inspect manager presence, run Composer and Vite+ commands, and perform the final scan in that same directory. Source snapshots retain the effective root and Laravel classification needed to select it. When the CLI selects by directory, it still identifies the owning Instance from the whole checkout or production home; the caller's current subdirectory does not select another dependency tree.

Hibernation inspects, prunes, and restores `vendor` and `node_modules` beside the application manifests, using that directory for install commands. Edits anywhere in the repository still protect the Instance from pruning, including edits to another subfolder. It does not prune sibling apps or the repository's dependency tree on behalf of a nested Laravel app.

Caddy access grants use the nested web root and validate Laravel's `public/storage` link against `storage/app/public` in the application directory. Registration and transfer close permissions on that application's `.env`; transfer still copies the whole checkout and relocates explicit Process paths, but rebuilds derived environment-file paths from the destination application directory. Neither operation guesses the directory from discovered manifests. Node agent converge independently closes permissions on the same application-directory `.env`; registration and transfer do not replace that converge step. It uses the shared helper, keeps the existing regular-file and containment checks, and logs a warning without failing convergence when it cannot close a file.

Task-workspace preparation and source inspection keep Git identity, metadata, checkout ownership, and recursive ACLs at the repository scope. `RemoteTaskWorkspaceStateReader` keeps its HEAD and branch inspection at the checkout root; these Git reads do not use the application directory. A visitable workspace inherits the configured web root and its Laravel consumers use the shared directory helper. An unrouted workspace still skips application classification. Doctor checks the same derived environment, cached APP_URL, PHP-FPM working directory, and release `.env` link as the runtime, while its Git and checkout-layout probes keep repository scope. There is no separate Doctor or task-workspace derivation rule.

### Target model for the follow-up multi-app group

A Project still owns exactly one repository. It has one or more named apps. Each app has an application path relative to the repository and a web root relative to that path. For example, an app named `site` can have path `apps/site` and web root `public`; their composition is today's `apps/site/public`. A root-level app has path `.` and web root `public`.

Each Instance is one copy of the whole Project and serves every app. Each app has its own Route for that Instance. An Instance is not split into one Instance per app, and an app does not become another Project. Source and release selection remain Instance-wide; app-specific environment and runtime consumers resolve the named app's path and Route rather than choosing the first Route or scanning the repository.

Today's effective `root` becomes the Project's single app: the application-directory portion becomes its path and the trailing `public` becomes its web root for Laravel. The conversion must represent existing Instance root overrides; it must not discard them. The follow-up group defines the conversion for other supported web roots, app names, Route naming, app selection in public interfaces, environment ownership, and per-app PHP and Process configuration before implementing them. It removes the old root field; it does not retain that field as a permanent alias.

The named-app target is a contract for the follow-up group, not an API introduced by group #975. Today's Project type and single-Route rules remain in effect until that group replaces them. [Projects](/reference/projects#application-directory) owns the single-app path contract.

## Rejected alternatives

- A second application-directory setting now: it duplicates the information in a Laravel web root and can disagree with it. Named app paths belong to the follow-up model.
- Searching for `artisan` during registration: a repository can contain several apps, so a scan cannot choose the operator's intended app.
- Changing the working directory of every command: repository-wide installs and task checks must still see every subproject. Commands that need an app directory can explicitly change into it.
- One Project or one Instance per app: it duplicates repository identity, source operations, and release selection instead of representing several apps in one copy.
- Several apps served through one arbitrarily selected Route: APP_URL and app selection would depend on ordering instead of a named app's Route.

## Consequences

- A nested Laravel application gets the same environment and runtime behavior as a root-level one, without moving its repository.
- Group #975 changes how paths resolve. It does not change Project identity, registration discovery, command scope, or public root fields.
- The follow-up group must define app-specific configuration and conversion before it changes the single-app API.
- This ADR stays in progress after group #975. The follow-up group that completes the named-app model absorbs it into Projects and the owning runtime references, adds the redirect and retired-decision row, and deletes the ADR.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, packages/php-sdk, apps/web
- ADRs: none.
- Detail: [Projects: Application directory](/reference/projects#application-directory), [environment file placement](/reference/environment-variables#where-the-file-lives), [releases](/reference/deployments#the-production-home), [Processes](/reference/processes-and-schedules#runtimes), [Schedules](/reference/schedules#execution-context), [PHP runtimes](/reference/php-runtime), [Instance logs](/reference/instance-logs#know-which-file-the-gateway-reads), [dependency scope](/reference/instance-dependencies#what-the-inventory-holds), [dependency collection](/reference/instance-dependency-contracts#collection), [hibernation](/reference/app-dev-runtime-hibernation#dependency-prune), [transfer](/reference/instance-transfer), and [Doctor](/cli/doctor).
- Verify: `composer docs-lint`; the docs impact report against group #975's start commit and all planned paths; Gateway tests for path resolution and runtimes during implementation, and independent Incus review of root-level and nested Laravel apps in development and production. The follow-up group verifies named apps and per-app Routes on one Instance.
