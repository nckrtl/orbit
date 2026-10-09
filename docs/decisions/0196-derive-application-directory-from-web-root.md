---
title: "Derive the application directory from the web root"
description: "Separate a Laravel application's directory from its repository, and serve several web roots of one checkout through its Routes."
---

# ADR 0196: Derive the application directory from the web root

Orbit derives one Laravel application directory from the effective web root. Repository operations keep their repository scope. A follow-up group lets an Instance serve more web roots of the same checkout through its Routes, without another Project for the same repository.

## Status

In progress.

Principle: [One way, one name and Deterministic first](/mission#principles). There is no exception to a mission principle.

## Context

A repository can contain a Laravel application in `apps/site` while its Git checkout starts two directories above it. Today, [Project root](/reference/projects#fields) names the web root, not the directory that holds `artisan`, `composer.json`, or `.env`. Treating the checkout as the application directory sends environment writes, source inspection, and runtime commands to the wrong place.

This decision spans two groups. Group #975 serves one Laravel app from a subfolder using the existing Project and Instance root fields. The follow-up group #982 serves several apps from the same checkout, one Route for each web root.

## Decision

Orbit separates application paths from repository paths now, and Routes with a web root extend that boundary in the follow-up group.

### Application directory for the single-app group

The effective web root is the Instance's root override, or the Project's root when there is no override. For a Laravel Instance, the application directory is that web root without its trailing `/public` segment. A root of `public` means the checkout root in development, or the release root in production. For example, `apps/site/public` means `apps/site`; the removal is a path-segment operation, not a string replacement of every occurrence of `public`.

One shared helper derives this directory. Source classification and every Laravel runtime consumer use it instead of repeating path arithmetic. It resolves against the development checkout or selected production release and keeps the existing relative-path and containment rules. Package roots such as `.` do not become Laravel applications through this rule. The helper does not search for an application, infer a nested web root, or change the stored root.

The application directory owns `composer.json`, `artisan`, development `.env` and `.env.testing`, Laravel cached configuration and `storage/logs`. Laravel source inspection, the working directory for PHP-FPM, automatic APP_URL updates, default Instance systemd Process working directories, and Instance Schedule working directories use it. Explicit Process working directories still override the default. Production runtime paths use `<production-home>/current/<application-directory>`, with no suffix for a root-level application.

Production keeps its durable environment at `<production-home>/.env`. Each release links `<release>/<application-directory>/.env` to that file, with a relative target computed from the link's actual depth. A nested application must not leave a spurious `.env` link at the release root. Another directory that a Route with a web root serves keeps its durable file at `<production-home>/env/<directory>/.env`, and each release links that directory's `.env` to it. Development defaults keep environment files in the application directory of their stable checkout home and copy them to the same relative directory in each candidate release; they do not link them back to the live seed.

Setup, teardown, development deploy steps, production deploy steps, and task-check commands keep running at the repository root (the release's repository root for deploy steps). They are repository-owned commands, not implicit Artisan commands. A step for a nested application must say, for example, `cd apps/site && php artisan migrate --force`. Git operations, checkout identity, release layout, and task metadata remain rooted at the repository.

Registration adopts the entire repository checkout or worktree and inherits the configured Project root unless the caller sends an explicit Instance root override. It does not scan for nested `artisan` or `public` directories. The operator configures `--root=apps/site/public`; registering a checkout is not a second discovery or configuration path.

### Dependency and lifecycle scope

For a Laravel Instance, dependency scans and constrained updates use the same derived application directory. They read only that directory's Composer and JavaScript manifests, lockfiles, and manager signals, not manifests at the repository root or in sibling apps. A nested app is one dependency tree; it is not an instruction to aggregate a monorepo. Existing refusals for workspaces, several importers, path repositories, and local links remain. Non-Laravel Instances keep their repository-root dependency scope.

The scan's `source.project_root` names the directory actually read: `<checkout>/apps/site` in development or `<home>/releases/<name>/apps/site` for a production root of `apps/site/public`. Production collection still validates and pins `current` to its selected release, then descends to the application directory; a release or source change during collection fails rather than mixing observations. Development updates inspect manager presence, run Composer and Vite+ commands, and perform the final scan in that same directory. Source snapshots retain the effective root and Laravel classification needed to select it. When the CLI selects by directory, it still identifies the owning Instance from the whole checkout or production home; the caller's current subdirectory does not select another dependency tree.

Hibernation inspects, prunes, and restores `vendor` and `node_modules` beside the application manifests, using that directory for install commands. Edits anywhere in the repository still protect the Instance from pruning, including edits to another subfolder. It does not prune sibling apps or the repository's dependency tree on behalf of a nested Laravel app.

Caddy access grants use the nested web root and validate Laravel's `public/storage` link against `storage/app/public` in the application directory. Registration and transfer close permissions on that application's `.env`; transfer still copies the whole checkout and relocates explicit Process paths, but rebuilds derived environment-file paths from the destination application directory. Neither operation guesses the directory from discovered manifests. Node agent converge independently closes permissions on the same application-directory `.env`; registration and transfer do not replace that converge step. It uses the shared helper, keeps the existing regular-file and containment checks, and logs a warning without failing convergence when it cannot close a file.

Task-workspace preparation and source inspection keep Git identity, metadata, checkout ownership, and recursive ACLs at the repository scope. `RemoteTaskWorkspaceStateReader` keeps its HEAD and branch inspection at the checkout root; these Git reads do not use the application directory. A visitable workspace inherits the configured web root and its Laravel consumers use the shared directory helper. An unrouted workspace still skips application classification. Doctor checks the same derived environment, cached APP_URL, PHP-FPM working directory, and release `.env` link as the runtime, while its Git and checkout-layout probes keep repository scope. There is no separate Doctor or task-workspace derivation rule.

### Target model for the follow-up multi-app group

This route-based model supersedes the earlier named-app target. The model is Project, then Instance, then one or more Routes. A Project owns one repository. An Instance is one copy of it. Each Route of the Instance serves one web root in that copy. There is no app record or app selector.

A Route's `web_root` is repository-relative, such as `apps/docs/public`. Null means the Instance's effective root, so every existing Route keeps today's behavior. The same helper derives each Route's application directory. Each distinct directory gets one PHP-FPM pool; the default directory keeps its pool name. Its `.env` takes the URL of the Instance's own Route when that Route serves it, and otherwise of the oldest Route that serves it. Processes and Schedules keep their working directory. [Routes](/reference/routes#serve-several-web-roots) owns the rules. Production Instances serve web roots from the selected release: each directory gets a pool under the Instance's dedicated master and a stable `.env` that each release links to.

## Rejected alternatives

- A second application-directory setting: it duplicates the information in a Laravel web root and can disagree with it.
- Searching for `artisan` during registration: a repository can contain several apps, so a scan cannot choose the operator's intended app.
- Changing the working directory of every command: repository-wide installs and task checks must still see every subproject. Commands that need an app directory can explicitly change into it.
- One Project or one Instance per app: it duplicates repository identity, source operations, and release selection instead of representing several apps in one copy.
- Named app records with their own paths: they add a second identity next to the Route that already serves each site.

## Consequences

- A nested Laravel application gets the same environment and runtime behavior as a root-level one, without moving its repository.
- Group #975 changes how paths resolve. It does not change Project identity, registration discovery, command scope, or public root fields.
- Routes with a web root add sites without changing Project or Instance identity, and without a data conversion.
- Production Instances now support a web root. This ADR stays in progress until a follow-up absorbs it into Projects and the owning runtime references, adds the redirect and retired-decision row, and deletes the ADR.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, packages/php-sdk, apps/web
- ADRs: none.
- Detail: [Projects: Application directory](/reference/projects#application-directory), [environment file placement](/reference/environment-variables#where-the-file-lives), [releases](/reference/deployments#the-production-home), [Processes](/reference/processes-and-schedules#runtimes), [Schedules](/reference/schedules#execution-context), [PHP runtimes](/reference/php-runtime), [Instance logs](/reference/instance-logs#know-which-file-the-gateway-reads), [dependency scope](/reference/instance-dependencies#what-the-inventory-holds), [dependency collection](/reference/instance-dependency-contracts#collection), [hibernation](/reference/app-dev-runtime-hibernation#dependency-prune), [transfer](/reference/instance-transfer), and [Doctor](/cli/doctor).
- Verify: `composer docs-lint`; the docs impact report against group #975's start commit and all planned paths; Gateway tests for path resolution and runtimes during implementation, and independent Incus review of root-level and nested Laravel apps in development and production. The follow-up group verifies several Routes with web roots on one Instance.
