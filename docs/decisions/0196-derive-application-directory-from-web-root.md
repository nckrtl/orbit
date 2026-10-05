---
title: "Derive the application directory from the web root"
description: "Separate a Laravel application's directory from its repository, and keep the path contract ready for several named apps per Project."
---

# ADR 0196: Derive the application directory from the web root

Orbit represents applications as named apps within one Project repository. Repository operations keep their repository scope. Group #975 established application-directory resolution; group #982 replaces the retired single web-root setting with the named-app contract below.

## Status

In progress.

Principle: [One way, one name and Deterministic first](/mission#principles). There is no exception to a mission principle.

## Context

A repository can contain a Laravel application in `apps/site` while its Git checkout starts two directories above it. The retired Project `root` field named the web root, not the directory that holds `artisan`, `composer.json`, or `.env`. Treating the checkout as the application directory sends environment writes, source inspection, and runtime commands to the wrong place.

This decision spans two groups. Group #975 served one Laravel app from a subfolder using the now-retired Project and Instance `root` fields. The follow-up multi-app group serves several apps from the same repository. The first group does not add app records, app selectors, or several Routes per Instance.

## Decision

Orbit separates application paths from repository paths now and uses named apps to extend that boundary in the follow-up group.

### Application directory for the single-app group

Group #975 derived one Laravel application directory by removing a trailing `public` segment from the now-retired `root` setting. Group #982 replaces that derivation with an explicit app `path` and relative `web_root`; the worked conversion preserves the old effective paths, including overrides and package `.` values. No root-setting flag remains supported.

One shared resolver uses the selected app's effective path against the checkout or selected release, keeping containment checks. It does not search for an application or infer a nested web root.

The application directory owns `composer.json`, `artisan`, development `.env` and `.env.testing`, Laravel cached configuration and `storage/logs`. Laravel source inspection, the working directory for PHP-FPM, automatic APP_URL updates, default Instance systemd Process working directories, and Instance Schedule working directories use it. Explicit Process working directories still override the default. Production runtime paths use `<production-home>/current/<application-directory>`, with no suffix for a root-level application.

Production keeps its durable environment at `<production-home>/.env`. Each release links `<release>/<application-directory>/.env` to that file, with a relative target computed from the link's actual depth. A nested application must not leave a spurious `.env` link at the release root. Development defaults keep environment files in the application directory of their stable checkout home and copy them to the same relative directory in each candidate release; they do not link them back to the live seed.

Setup, teardown, development deploy steps, production deploy steps, and task-check commands keep running at the repository root (the release's repository root for deploy steps). They are repository-owned commands, not implicit Artisan commands. A step for a nested application must say, for example, `cd apps/site && php artisan migrate --force`. Git operations, checkout identity, release layout, and task metadata remain rooted at the repository.

Registration adopts the whole checkout or worktree and inherits every Project app unless the caller supplies `app_overrides`. The `app_domains` map chooses explicit domains for named apps; omitted serving apps get generated domains. Registration is not a discovery path for `artisan` or `public` directories.

### Dependency and lifecycle scope

Dependency scans and constrained updates use the selected app's effective application directory for every type. They read only its manifests, lockfiles and manager signals. Workspace, multi-importer, path-repository and local-link refusals remain; configuring several apps is not permission to combine their dependency graphs.

The scan's `source.project_root` names the selected app directory actually read. Production pins the selected release before descending; source changes fail collection. Snapshots retain app identity and effective configuration. Observations and attempts are keyed by Instance, app and ecosystem; existing rows migrate to `web` without losing history. Directory selection identifies an Instance, not an app. `--all` enumerates every app of every accessible Instance, with the exact aggregate response owned by [dependency scans](/reference/instance-dependencies#scan).

Hibernation remains Instance-wide and enumerates every app's manifest-owned `vendor` and `node_modules` for prune and restore. Install commands run in that app directory. Edits anywhere in the repository protect the whole Instance; unrelated directories are never pruned.

Caddy grants access to each app's composed web root and validates Laravel storage links within that app. Registration, transfer and Node agent convergence close permissions on every app's environment file with the shared resolver and regular-file checks. Transfer retains whole-repository scope and relocates derived runtime paths per app. The agent logs a warning without failing convergence when it cannot close a file.

Task workspaces retain repository-wide Git identity, ownership, metadata and ACLs. They inherit the complete app list and recorded overrides. Visitable workspaces project every serving app; unrouted workspaces skip serving classification. Doctor uses the same effective app paths for environment, cached APP_URL, FPM and release-link observations while Git and checkout-layout probes run once per Instance.

### Target model for the follow-up multi-app group

This is the implementation contract for group #982, not a list of choices for implementers. [Projects](/reference/projects#application-directory) owns the fields, validation, conversion, interface shapes, and new request errors. [Routes](/reference/routes#select-a-domain-and-scope) owns app Route identity and generated domains.

A Project owns one repository and a non-empty `apps` list. An app has exactly `name`, `path`, `web_root`, and `type`. Names are unique within the Project, lowercase DNS labels of 1 through 63 characters, starting and ending with a letter or digit and allowing hyphens internally. Names are case-sensitive; Orbit refuses uppercase rather than normalizing it. `default` is a valid app name, not a selector alias. App order has no meaning; responses sort by name. App identity is its Project and name, not a new Project or an Instance.

`path` is the application directory relative to the repository, with `.` for its top level. `web_root` is relative to that directory, or null for a non-serving package. The four existing type values move from the Project to each app; `monorepo` remains a generic source type, not a second repository. Paths must be canonical, contained relative paths with no absolute prefix, traversal, empty segments, backslashes, or symlink escape. Serving apps cannot use `.` as their web root. Two apps cannot share an application path: environment files and manifests must have one owner. No manifest search or implicit app discovery is introduced.

Each development Instance is one copy of the whole repository and serves every app whose web root is non-null, with exactly one authoritative app Route per Instance/app pair. Package apps with null web roots and unrouted task workspaces have no Routes or FPM pools. Source, branch, release, setup, teardown, deploy steps, task checks, transfer, hibernation state, and operation locks remain Instance- or repository-wide. Removing an Instance removes all its app Routes and runtimes. No consumer may select an app or Route by list order. Dependency inspection and updates use the selected app's manifests for every app type. Hibernation still sleeps the Instance as a whole, but pruning and restoration enumerate every app's own dependency directories; repository edits protect all apps. Transfer and Node agent convergence close permissions on every app's environment file.

#### Stored conversion and domain cutover

Every existing Project gets one app named `web`, even a package. Its app type is the retired Project type. Convert the retired Project `root` and each retired Instance `root` that is non-null using the same rule: a trailing `public` segment becomes web root `public` and its parent becomes the app path; any other non-dot root splits into its parent path and final segment as web root. A retired package root `.` becomes path `.` and web root null. Keep an explicit Instance override as `app_overrides.web` with both `path` and `web_root`, even if it equals the Project values. A missing override stays absent and inherits the Project app. Instance overrides never change app names or types or omit an app. Worked examples and the complete override shape are in [Projects: Convert retired fields](/reference/projects#convert-removed-fields).

Generated domains change from `drift.test` to `web.drift.test` on `default`, and from `main.drift.test` to `web.main.drift.test` on `main`. Explicit domains stay unchanged. Migration records the app association on existing Routes, targets, environment values, Processes, Schedules, and Project definitions; it creates missing development Routes for serving apps except unrouted task workspaces and replaces generated Routes through the existing retryable domain-change lifecycle, not by mutating an immutable domain in place. Preflight every new domain before publication; a conflict stops without discarding the old serving association. Certificates, DNS, Caddy, APP_URL and Process-derived origins converge to the replacement. Old generated names are withdrawn; there is no old-domain alias. Interrupted conversion resumes from recorded state and must not expose an active Instance without every required app Route.

Remove the retired `root`/`--root`/SDK `$root` field everywhere and remove the retired Project-level `type`/`--type`/SDK `$type` field. There is no alias, dual write, fallback, or compatibility endpoint. Old requests fail as unknown fields with `validation.failed`; an old CLI option fails option parsing before a request.

#### Runtime and environment ownership

Each routed PHP app has one FPM pool and socket, keyed by Instance ID and app name; source inspection and PHP version selection run in that app's directory. Development retains one shared service per PHP version, not one master per app. Pool names are `orbit-instance-{id}-{app}` and sockets are `/run/php/orbit-{id}-{app}.sock`. Caddy composes the effective app path and web root and uses that app's socket. Packages have no pool. Each app has its own encrypted environment configuration and development `.env` and `.env.testing` in its effective application directory. APP_URL and `{{instance.domain}}` resolve only against that app's authoritative Route. A replacement for one app must not rewrite a sibling's environment or cached URL.

Processes, Schedules and their Project definitions store an `app` name. Omission resolves and stores the only app when the Project has one; it fails when several exist. Node-owned Processes and Schedules have no app and reject a supplied selector. Default Instance working directories, environment files, presets and derived Route origins belong to the selected app. Explicit Process working directories still win. Names stay unique within the existing owner and kind, not within an app; no service naming changes are needed. Project definition copies carry their stored app. Migration assigns `web` to existing Instance-owned records and definitions and leaves Node-owned records null. [Processes](/reference/processes-and-schedules#app-target) and [Schedules](/reference/schedules#execution-context) own these rules.

Doctor checks every app's path, source profile, expected Route association, document root, FPM pool/socket/working directory, environment, cached APP_URL and release environment link on single-app production. It keeps repository probes once per Instance and existing issue codes and families. App-scoped findings carry `app` and identify the Instance and app; checked resource counts do not multiply the Instance count. [Doctor](/cli/doctor#named-app-checks) owns report details.

#### Interfaces and errors

API, CLI, PHP SDK, MCP and web app use the same app names and validation rules. `apps` is the API/MCP list field, `--apps` is the CLI JSON-list flag, and `$apps` is the SDK property and request parameter. `app` is the Process/Schedule and definition API/MCP selector, `--app` is the CLI flag, and `$app` is the SDK property and request parameter. The same selector applies to environment, logs, dependency and app-specific source operations; selecting by Route domain already identifies the app and must agree with any explicit selector. Instance responses expose four-field effective `apps`, stored `app_overrides` and a separate name-keyed `app_runtime` map. [Instance output](/reference/projects#instance-app-runtime-output) defines the exact Route/domain/URL, source-profile and port shapes and the removal of scalar Instance runtime fields. Source profiles, ports and their unfinished reservations migrate to `web`. There is no primary-app fallback. App Route responses expose `app`. The [interface contract](/reference/projects#named-app-interfaces) fixes payloads, defaults, update semantics, web controls and the exhaustive new error-code list.

#### App-qualified mutations and runtime identities

[Instance override updates](/reference/projects#instance-override-update-lifecycle) are recorded Instance-owned operations, not plain database saves. Preflight checks effective paths, source evidence, runtime compatibility and synchronized environment files. App-list or override edits cannot change a retained app between serving and non-serving in either direction, including override clearing; `app.serving_state_change_unsupported` refuses before mutation. Initial provisioning can supply a serving package override and creates its required Route before activation. Preparation reconciles each affected app's FPM, Caddy, Processes, presets and Schedules. Publication atomically installs the map and profiles. Before publication recovery restores old projections; after publication it continues forward. Journals define crash and lost-response retry, and old-path environment files are not deleted. Production override mutation is refused, A change to a Project's app list is also refused when it changes effective paths or types of an existing production app.

[Instance rename](/reference/routes#change-an-instance-route-domain) keeps the Instance name, checkout and placement. `app` selects the one domain to replace; branch-only recording needs no selector and changes no Routes. API, CLI, SDK and MCP use the same app/domain/branch identity. A combined request validates everything before mutation and records the branch only after domain convergence completes; an unfinished request cannot change its app, domain or branch identity. Registration uses the same domain map as creation. [Production cloning](/reference/instance-cloning#preview-domain) resolves its sole app with optional `app` and scalar `preview_name`; it rejects several apps before remote work.

Development certificates use live `app-instance-{id}-app-{app}` and staging `app-instance-{id}-hostname-change-app-{app}` scopes, with matching Process and Caddy paths. [Certificate migration and cleanup](/reference/routes#change-an-explicit-domain) switch stored references before retiring the former scopes and never use an Instance-prefix deletion glob. Production scopes remain Instance-only and Router scopes remain Route-ID keyed.

Every Instance/app pair has at most one Process of each preset. A watcher stores the ID of its own app's HTTP Process, never a sibling's. Ports are app-keyed and unique across endpoint families per Node. Vite runtime files use `/etc/orbit/vite/app-instance-{id}-{app}.env`; annotator stores use `/var/lib/orbit/annotator/instance-{id}-{app}`. Watchers, queued annotations and pending withdrawal/transfer ownership migrate to `web`. [Port migration](/reference/assigned-vite-ports#migrate-port-reservations) preserves unconflicted ports, prioritizes retained reservations and journals safe ordinary reallocation for legitimate legacy cross-family collisions. Conflicting retained owners stop migration with `app.port_migration_conflict` until their existing operations complete. All allocators exclude other endpoint kinds in the same Instance/app pair. [Vite](/reference/assigned-vite-ports) and [Agentation](/reference/agentation#runtime-identity-and-migration) own replacement, migration and cleanup; units retain their globally unique Process-ID identities.

Transfer validates the required Route of every serving app, preserves package/unrouted exceptions, reserves all destination endpoint kinds and uses app-prefixed generated domains. [Transfer](/reference/instance-transfer#what-moves) journals separate per-app annotator archives, restored stores and rollback/cleanup ownership; no one-app staging directory or store fallback remains.

[Tracking and stats](/reference/analytics#single-app-boundary) remain single-app only. Tracking configuration migrates to the app name `web`, while tracking Route `app` remains null. Analytics derives hosts, CNAMEs, snippets and Plausible sites from that recorded sole app's authoritative Route, never an Instance primary domain. Adding a second app to a Project with tracked Instances and enable/show/stats on a multi-app Project return `app.analytics_multi_app_unsupported`. Disable remains a domain-free cleanup operation. Converting a generated app domain preserves tracking hosts but changes the snippet/site domain; the operator creates the new Plausible site and updates CNAMEs/snippets. The web app hides unsupported stats/enable controls and exposes cleanup for inconsistent stored hosts.

#### Group boundary

Group #982 delivers named apps and per-app development Routes, pools, environments and selectors. It migrates existing single-app production Instances without losing their effective paths, explicit domains, durable environment file, dedicated FPM identity, release receipts or production pools. Their environment configuration is associated with `web`, but their physical production `.env` remains `<home>/.env` and release links retain their existing targets. Production Route pools remain single-app pools; every target and reassignment must match both Project and app.

Multi-app production releases, per-app production masters, durable environment layout for production apps and app-scoped database attachments are not in this group. A Project with production Instances cannot add a second app; production provisioning or cloning a Project with several apps fails before remote work. Existing database attachments on Instances require a Project with one app; adding a second app while attachments exist also fails. This is an explicit refusal, not a silent first-app fallback. Multi-app development apps may configure database keys independently in their own stored environments. These limits keep deployment and database redesign out of the four implementation subtasks. If those subtasks omit any named-app development consumer or migration named here, the reviewer must adjust their scope before implementation rather than leave selection to an implementer.

## Rejected alternatives

- A second application-directory setting now: it duplicates the information in a Laravel web root and can disagree with it. Named app paths belong to the follow-up model.
- Searching for `artisan` during registration: a repository can contain several apps, so a scan cannot choose the operator's intended app.
- Changing the working directory of every command: repository-wide installs and task checks must still see every subproject. Commands that need an app directory can explicitly change into it.
- One Project or one Instance per app: it duplicates repository identity, source operations, and release selection instead of representing several apps in one copy.
- Several apps served through one arbitrarily selected Route: APP_URL and app selection would depend on ordering instead of a named app's Route.

## Consequences

- A nested Laravel application gets the same environment and runtime behavior as a root-level one, without moving its repository.
- Repository identity, registration without discovery, and repository-owned commands keep the boundary established in group #975.
- Group #982 implements the contract above across the Gateway, CLI, SDK, MCP and web app; the documentation subtask does not change code or migrations.
- Existing generated domains change once to include `web`; explicit domains and runtime identities are preserved in production for the sole app.
- Multi-app production releases and database attachments require a separate group. This ADR remains In progress while those boundaries remain unbuilt; completing only the development contract does not retire it.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, packages/php-sdk, apps/web
- ADRs: none.
- Detail: [Projects: Application directory](/reference/projects#application-directory), [environment file placement](/reference/environment-variables#where-the-file-lives), [releases](/reference/deployments#the-production-home), [Processes](/reference/processes-and-schedules#runtimes), [Schedules](/reference/schedules#execution-context), [PHP runtimes](/reference/php-runtime), [Instance logs](/reference/instance-logs#know-which-file-the-gateway-reads), [dependency scope](/reference/instance-dependencies#what-the-inventory-holds), [dependency collection](/reference/instance-dependency-contracts#collection), [hibernation](/reference/app-dev-runtime-hibernation#dependency-prune), [transfer](/reference/instance-transfer), and [Doctor](/cli/doctor).
- Verify: `composer docs-lint`; the docs-impact report against group #982's start commit `4f1924d857cf82658935cb6b875602924a93c77e` and all planned paths; Gateway tests for path resolution and runtimes during implementation, and independent Incus review of root-level and nested Laravel apps in development and production. The follow-up group verifies named apps and per-app Routes on one Instance.
