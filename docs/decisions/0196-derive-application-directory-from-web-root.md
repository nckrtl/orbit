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

Production keeps its durable environment at `<production-home>/.env`. For apps with a non-null web root, each release links `<release>/<application-directory>/.env` to that file, with a relative target computed from the link's actual depth. Apps with null web root retain `<release>/.env` with target `../../.env`, including converted non-public roots with nested application paths; neither migration nor runtime preparation relocates that link. A trailing-public nested application must not leave a spurious `.env` link at the release root. Development defaults keep environment files in the application directory of their stable checkout home and copy them to the same relative directory in each candidate release; they do not link them back to the live seed.

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

`path` is the application directory relative to the repository, with `.` for its top level. `web_root` is relative to that directory, or null to serve the app directory itself. Only a package with path `.` and null web root is non-serving; a non-dot package path with null web root remains serving. The four existing type values move from the Project to each app; `monorepo` remains a generic source type, not a second repository. Paths must be canonical, contained relative paths with no absolute prefix, traversal, empty segments, backslashes, or symlink escape. Serving apps cannot use `.` as their web root. Two apps cannot share an application path: environment files and manifests must have one owner. No manifest search or implicit app discovery is introduced.

Each development Instance is one copy of the whole repository and serves every app except a package with path `.` and null web root, with exactly one authoritative app Route per Instance/app pair. Package apps with path `.` and null web roots and unrouted task workspaces have no Routes or FPM pools. Source, branch, release, setup, teardown, deploy steps, task checks, transfer, hibernation state, and operation locks remain Instance- or repository-wide. Removing an Instance removes all its app Routes and runtimes. No consumer may select an app or Route by list order. Dependency inspection and updates use the selected app's manifests for every app type. Hibernation still sleeps the Instance as a whole, but pruning and restoration enumerate every app's own dependency directories; repository edits protect all apps. Transfer and Node agent convergence close permissions on every app's environment file.

#### Stored conversion and domain cutover

Every existing Project gets one app named `web`, even a package. Its app type is the retired Project type. Convert the retired Project `root` and each retired Instance `root` that is non-null using the same rule: a trailing `public` segment becomes web root `public` and its parent becomes the app path; any other non-dot root stays in full as the app path, with null web root serving that directory. This preserves both the application directory and document root. A retired package root `.` becomes path `.` and web root null. Keep an explicit Instance override as `app_overrides.web` with both `path` and `web_root`, even if it equals the Project values. A missing override stays absent and inherits the Project app. Instance overrides never change app names or types or omit an app. Worked examples and the complete override shape are in [Projects: Convert retired fields](/reference/projects#convert-removed-fields).

Generated domains change from `drift.test` to `web.drift.test` on `default`, and from `main.drift.test` to `web.main.drift.test` on `main`. Explicit domains stay unchanged. Migration records the app association on existing Routes, targets, environment values, Processes, Schedules, and Project definitions; it creates missing development Routes for serving apps except unrouted task workspaces and replaces generated Routes through the existing retryable domain-change lifecycle, not by mutating an immutable domain in place. Preflight every new domain before publication; a conflict stops without discarding the old serving association. Certificates, DNS, Caddy, APP_URL and Process-derived origins converge to the replacement. Old generated names are withdrawn; there is no old-domain alias. Interrupted conversion resumes from recorded state and must not expose an active Instance without every required app Route.

Remove the retired `root`/`--root`/SDK `$root` field everywhere and remove the retired Project-level `type`/`--type`/SDK `$type` field. There is no alias, dual write, fallback, or compatibility endpoint. Old requests fail as unknown fields with `validation.failed`; an old CLI option fails option parsing before a request.

#### Runtime and environment ownership

Each routed PHP app has one FPM pool and socket, keyed by Instance ID and app name; source inspection and PHP version selection run in that app's directory. Development retains one shared service per PHP version, not one master per app. Pool names are `orbit-instance-{id}-{app}` and sockets are `/run/php/orbit-{id}-{app}.sock`. Caddy composes the effective app path and web root and uses that app's socket. Packages have no pool. Each app has its own encrypted environment configuration and development `.env` and `.env.testing` in its effective application directory. APP_URL and `{{instance.domain}}` resolve only against that app's authoritative Route. A replacement for one app must not rewrite a sibling's environment or cached URL.

Processes, Schedules and their Project definitions store an `app` name. Omission resolves and stores the only app when the Project has one; it fails when several exist. Node-owned Processes and Schedules have no app and reject a supplied selector. Default Instance working directories, environment files, presets and derived Route origins belong to the selected app. Explicit Process working directories still win. Names stay unique within the existing owner and kind, not within an app; no service naming changes are needed. Project definition copies carry their stored app. Migration assigns `web` to existing Instance-owned records and definitions and leaves Node-owned records null. [Processes](/reference/processes-and-schedules#app-target) and [Schedules](/reference/schedules#execution-context) own these rules.

Doctor checks every app's path, source profile, expected Route association, document root, FPM pool/socket/working directory, environment, cached APP_URL and release environment link on single-app production. It keeps repository probes once per Instance and existing issue codes and families. App-scoped findings carry `app` and identify the Instance and app; checked resource counts do not multiply the Instance count. [Doctor](/cli/doctor#named-app-checks) owns report details.

#### Interfaces and errors

API, CLI, PHP SDK, MCP and web app use the same app names and validation rules. `apps` is the API/MCP list field, `--apps` is the CLI JSON-list flag, and `$apps` is the SDK property and request parameter. `app` is the Process/Schedule and definition API/MCP selector, `--app` is the CLI flag, and `$app` is the SDK property and request parameter. The same selector applies to environment, logs, dependency and app-specific source operations; selecting by Route domain already identifies the app and must agree with any explicit selector. Instance responses expose four-field effective `apps`, stored `app_overrides` and a separate name-keyed `app_runtime` map. [Instance output](/reference/projects#instance-app-runtime-output) defines the exact Route/domain/URL, source-profile and port shapes and the removal of scalar Instance runtime fields. Source profiles, ports and their unfinished reservations migrate to `web`. There is no primary-app fallback. App Route responses expose `app`. The [interface contract](/reference/projects#named-app-interfaces) fixes payloads, defaults, update semantics, web controls and the exhaustive new error-code list.

#### App-qualified mutations and runtime identities

[Instance override updates](/reference/projects#instance-override-update-lifecycle) are recorded Instance-owned operations, not plain database saves. Preflight checks effective paths, source evidence, runtime compatibility and synchronized environment files. App-list or override edits cannot change a retained app between serving and non-serving in either direction, including override clearing; serving state uses type, path and web root together, not nullability alone; `app.serving_state_change_unsupported` refuses before mutation. Initial provisioning can supply a serving package override and creates its required Route before activation. Preparation reconciles each affected app's FPM, Caddy, Processes, presets and Schedules. Publication atomically installs the map and profiles. Before publication recovery restores old projections; after publication it continues forward. Journals define crash and lost-response retry, and old-path environment files are not deleted. Production override mutation is refused, A change to a Project's app list is also refused when it changes effective paths or types of an existing production app.

[Instance rename](/reference/routes#change-an-instance-route-domain) keeps the Instance name, checkout and placement. `app` selects the one domain to replace; branch-only recording needs no selector and changes no Routes. API, CLI, SDK and MCP use the same app/domain/branch identity. A combined request validates everything before mutation and records the branch only after domain convergence completes; an unfinished request cannot change its app, domain or branch identity. Registration uses the same domain map as creation. [Production cloning](/reference/instance-cloning#preview-domain) resolves its sole app with optional `app` and scalar `preview_name`; it rejects several apps before remote work.

Development certificates use live `app-instance-{id}-app-{app}` and staging `app-instance-{id}-hostname-change-app-{app}` scopes, with matching Process and Caddy paths. [Certificate migration and cleanup](/reference/routes#change-an-explicit-domain) switch stored references before retiring the former scopes and never use an Instance-prefix deletion glob. Production scopes remain Instance-only and Router scopes remain Route-ID keyed.

Every Instance/app pair has at most one Process of each preset. A watcher stores the ID of its own app's HTTP Process, never a sibling's. Ports are app-keyed and unique across endpoint families per Node. Vite runtime files use `/etc/orbit/vite/app-instance-{id}-{app}.env`; annotator stores use `/var/lib/orbit/annotator/instance-{id}-{app}`. Watchers, queued annotations and pending withdrawal/transfer ownership migrate to `web`. [Port migration](/reference/assigned-vite-ports#migrate-port-reservations) preserves unconflicted ports, prioritizes retained reservations and journals safe ordinary reallocation for legitimate legacy cross-family collisions. Conflicting retained owners stop migration with `app.port_migration_conflict` until their existing operations complete. All allocators exclude other endpoint kinds in the same Instance/app pair. [Vite](/reference/assigned-vite-ports) and [Agentation](/reference/agentation#runtime-identity-and-migration) own replacement, migration and cleanup; units retain their globally unique Process-ID identities.

Transfer validates the required Route of every serving app, preserves package/unrouted exceptions, reserves all destination endpoint kinds and uses app-prefixed generated domains. [Transfer](/reference/instance-transfer#what-moves) journals separate per-app annotator archives, restored stores and rollback/cleanup ownership; no one-app staging directory or store fallback remains.

[Tracking and stats](/reference/analytics#single-app-boundary) remain single-app only. Tracking configuration migrates to the app name `web`, while tracking Route `app` remains null. Analytics derives hosts, CNAMEs, snippets and Plausible sites from that recorded sole app's authoritative Route, never an Instance primary domain. Adding a second app to a Project with tracked Instances and enable/show/stats on a multi-app Project return `app.analytics_multi_app_unsupported`. Disable remains a domain-free cleanup operation. Converting a generated app domain preserves tracking hosts but changes the snippet/site domain; the operator creates the new Plausible site and updates CNAMEs/snippets. The web app hides unsupported stats/enable controls and exposes cleanup for inconsistent stored hosts.

#### Shared candidate projection and durable recovery

Project app-list and Instance override mutations share one projection foundation. `ProjectUpdate` remains the Project lifecycle owner; `InstanceAppUpdate` is the Instance override lifecycle owner. Each `InstanceAppProjection` links exactly one parent operation to one Instance and freezes before/candidate app configuration, profiles, placement, selected release and resource fingerprints. Shared adapters prepare, restore and clean remote artifacts; only the parent publishes public configuration. [Projects](/reference/projects#shared-app-projection-ownership) owns the journal, step/receipt schema, normalized retry identity and error precedence.

Keep existing Instance operation locks, ordering and reentrancy, projection locks and Node service locks. A Project reserves all affected Instances under their locks before preflight, atomically or not at all. Durable ownership outlives the executing process and OS lock. Deploy/rollback, transfer, removal, rename, environment, Process, Schedule and dependency entrypoints refuse foreign persisted owners before consuming effective app paths or mutating resources. Both parent kinds use the same admission rule. Existing unrelated lock callers retain their errors; app-mutation contention uses `instance.lifecycle_busy`, different unfinished requests use their documented Project/Instance in-progress codes, and more specific infrastructure errors survive.

Candidate serving state comes from committed transitional journals through existing whole-Node Caddy and shared-version FPM build paths. Independent builds select the same before/candidate side. Public configuration readers stay on published maps and profiles. Explicit internal candidate Process/Schedule targets carry owner/app/resource fingerprints and retained Route/domain/provenance/port identities. Never temporarily rewrite a public row, hold a database transaction during remote work, use an in-memory renderer override, or introduce another Caddy publisher or unmanaged Node fragment.

Commit a stable intent before every mutating step, including receipt/snapshot creation. Protected receipts bind old artifact snapshots and results to parent owner, Instance, app, step and plan digest. Receipt creation itself has a stable retry identity. A lost response inspects the receipt and actual owned artifacts; it never assumes failure or recaptures modified prior state. Environment bytes stay in encrypted storage or protected remote snapshots/transport, not journal plaintext, argv, logs or public output. Verify canonical containment, regular files, owners, links, protections, free space and synchronized source configuration. Matching destination files still need snapshots; stable-home and selected-release targets are separate. Old-path environments remain in place.

Before publication, restore only recorded owned private artifacts and exact protections; delete only files proven created by this operation. Restore shared Node serving state by reconciling current committed desired state, including unrelated apps, never a stale whole-Node snapshot. Desired Process state, observed running/stopped/sleeping state and Schedule enabled/active timer state are separate facts. Restoration and retries cannot start sleeping Processes, enable disabled timers or wake cold dependencies. Instance map/profile publication is one transaction recording the published journal boundary; after it, verification/cleanup goes forward. Project recovery retains its existing boundary when `publishing` starts. Failed restoration keeps durable ownership; identical retries finish recovery. Completed/lost-success retries do not restart runtimes.

#### Delivery and planned path inventory

The OpsBot-approved #1237 replan replaces that scope with seven ordered subtasks on the unreleased #982 branch. Operator/PlanBot owns the recorded Tasks order; this contract does not invent another split. Completed #1023–#1025 and #1166 remain complete, and cancelled #1026 remains cancelled. The approved exceptional group order is:

| Order | Slice | Must be complete at its review |
| --- | --- | --- |
| 1 | Shared architecture and documentation (#1240) | Candidate/owner/receipt decision, admission and planned-path inventory, clean docs impact and architecture review before product code. |
| 2 | Durable ownership, checkpoints and recovery admission (#1241) | All-or-nothing reservation, both parent kinds, crash/lost-response/completion semantics and all entrypoint guards, independently tested after worker/lock exit. |
| 3 | Native protected cross-path environments (#1242) | Complete protected snapshot/stage/restore/cleanup adapter, source/destination conflicts, file-free case, stable/release independence, secret redaction and real remote-program tests. |
| 4 | Native committed serving projection (#1243) | Complete source/access/profile/APP_URL, Caddy/FPM and existing certificate/DNS/Route owner integration, app add/change/remove, independent Node rebuild and native recovery tests with old public configuration. |
| 5 | Native Process and Schedule candidates (#1244) | Complete native render/install/state preservation and recovery, explicit directories, preset identities, sleeping workers/disabled timers/cold dependency tests. |
| 6 | Full Project app-list lifecycle (#1245) | Complete safety preflight, Route/runtime reconciliation, publication/recovery and scalar compatibility using the complete foundation; modify the existing native Project mutator. |
| 7 | Full Instance override lifecycle (#1246) | Complete normalized map integration, safety/error/state matrix, atomic map/profile publication, identical/different/completed retries and cross-parent regressions. |

Each code slice independently runs its focused native behavior/recovery tests, `composer test:affected` and `composer check` in `apps/gateway`, Pint and `git diff --check`, plus the root handoff gate. Tests must fail when the corresponding guard, adapter or checkpoint is removed. Each foundation slice completes its guards, adapters and receipts before the Project and Instance integrations begin. Independent review confirms native behavior; database-only/fake-only acceptance is insufficient. No unavailable Incus result is claimed. Topology discovery follows the allocated-environment consult rule.

The recorded order continues with #1238, which owns public API/CLI/SDK/MCP interfaces and root removal, then #1239, which owns web app delivery, ADR absorption and group acceptance. Both follow #1240–#1246 without changing the split. #1238 consumes both completed lifecycle actions rather than implementing runtime behavior. #1239 completes Project/Instance web app editors, app selectors and phone/desktop screenshot review. No UI requirement is silently restored from #1026 or deferred from named-app acceptance.

ADR 0196 remains In progress during #1240. After this group's named-app delivery is complete, #1239 absorbs the built behavior and lasting rationale into the owning reference pages, deletes this ADR, adds its redirect and retired-decision entry, and updates inbound links under the [decision lifecycle](/decisions/overview#decision-lifecycle). Excluded multi-app production and database work does not delay that absorption; it requires a separate group and its own architectural contract.

No partial foundation release or merge is authorized: original two-app development acceptance, migrations, every interface, CI, independent code/Incus review and maintainer approval remain final release gates. This group does not deliver multi-app production.

Admission inventory below is part of slice 2, not work for the Project and Instance integrations. Check the persisted owner under existing locks before preflight/path consumption, re-read frozen ownership before mutation, and permit only the same parent to recover its internal steps. Preserve current lock order and reentrant internal calls; do not introduce an independent lock hierarchy.

| Boundary | Gateway entrypoints and shared lock/target paths |
| --- | --- |
| Deploy and rollback | `Actions/Instances/{DeployInstanceAction,DeployDefaultInstanceAction,RollbackInstanceAction,InstanceDeploymentConfigResolver}.php` |
| Transfer, removal and rename | `Actions/Instances/{TransferInstanceAction,RemoveInstanceAction,RenameInstanceAction}.php`, `Models/InstanceRename.php` |
| Environment | `Actions/Instances/{ImportInstanceEnvironmentAction,UpdateInstanceEnvironmentAction,SynchronizeInstanceEnvironmentAction}.php`, `Infrastructure/Instances/{NativeInstanceEnvironmentOperationLock,RemoteInstanceEnvironmentAccess}.php`, `Domain/Instances/Environment/{InstanceEnvironmentContextResolver,InstanceEnvironmentStore}.php` |
| Processes | `Actions/Processes/{AddProcessAction,StartProcessAction,RestartProcessAction,StopProcessAction,RemoveProcessAction,CascadeInstanceProcessesAction}.php`, `Infrastructure/Processes/{NativeProcessAdmissionLock,NativeProcessRuntimeLease}.php`, `Domain/Processes/ProcessTargetResolver.php` |
| Schedules | `Actions/Schedules/{AddScheduleAction,RunScheduleAction,ActivateScheduleAction,RemoveScheduleAction,CascadeInstanceSchedulesAction}.php`, `Domain/Schedules/ScheduleTargetResolver.php` |
| Dependencies | `Actions/Instances/Dependencies/{UpdateInstanceDependenciesAction,ScanInstanceDependenciesAction,AccessInstanceDependenciesAction}.php` |
| Both app mutations | `Actions/Projects/UpdateProjectAction.php`, `Actions/Instances/UpdateInstanceAppOverridesAction.php`, both parent journals and shared projection ownership/recovery |
| Route/serving projection | `Domain/Routes/{RouteAssociationGuard,RouteStateResolver}.php`, `Actions/Routes/{CreateRouteAction,RemoveRouteAction,ConvergeRouteAction}.php`, existing development projector and site repository; internal candidate intents carry parent ownership |

All paths in the tables below are relative to `apps/gateway/`. Brace notation enumerates exact planned files, not permission to widen scope. These are the finalized #1237 replan foundation/integration impact paths. The impact check also includes this ADR, Projects, Caddy configuration, Environment, PHP runtime, Processes, Schedules and generated context. Scoped `covers:` entries on the owning pages resolve new surfaces before they exist. Re-run against group start `4f1924d857cf82658935cb6b875602924a93c77e` with each expanded path; no unresolved error is waived. An implementer who changes a planned path repeats the check before coding.

| Planned path | Documentation owner / purpose |
| --- | --- |
| `app/Domain/Instances/AppProjection{Owner,Plan,Runtime,Recovery}.php` | Projects: typed reservation, immutable plan, runtime orchestration and publication-side recovery. |
| `app/Models/{InstanceAppProjection,InstanceAppUpdate,ProjectUpdate}.php` | Projects: shared child journal, Instance parent and existing Project parent. |
| `database/migrations/2026_10_12_000004_create_app_projection_journals.php` | Projects: durable owner/child/step evidence. |
| `database/migrations/2026_10_12_000005_add_apps_to_project_update_journals.php` | Projects: requested/prior app-list identity in the existing parent. |
| `app/Infrastructure/Instances/NativeAppProjectionRuntime.php` | Projects: orchestrates complete adapters, never publishes maps itself. |
| `app/Domain/Instances/AppProjectionEnvironment.php`, `app/Infrastructure/Instances/{NativeAppProjectionEnvironment,AppProjectionEnvironmentProgram}.php` | Environment: typed protected remote file adapter/program. |
| `app/Infrastructure/Instances/NativeAppProjectionServingRuntime.php` | Caddy and PHP runtime: native committed candidate serving adapter. |
| `app/Infrastructure/Instances/{NativeAppProjectionWorkerRuntime,AppProjectionWorkerProgram}.php` | Processes and Schedules: native protected worker adapter/program. |
| `app/Actions/Instances/UpdateInstanceAppOverridesAction.php` | Projects: internal Instance lifecycle action, never a plain save. |
| `app/Actions/Projects/UpdateProjectAction.php`, `app/Data/Projects/UpdateProjectData.php`, `app/Domain/Projects/ProjectUpdateProjectionMutator.php`, `app/Infrastructure/Projects/NativeProjectUpdateProjectionMutator.php` | Projects: internal full app-list integration. The native implementation path is existing; do not use the nonexistent `Infrastructure/Projects/ProjectUpdateProjectionMutator.php`. |
| `app/Providers/ApplicationServiceProvider.php` | Existing tools page owns dependency wiring. |
| `tests/Feature/{AppProjectionDurableOwnerTest,AppProjectionStepRecoveryTest,AppProjectionEnvironmentTest,AppProjectionServingTest,AppProjectionWorkersTest,ProjectAppListUpdateTest,InstanceAppOverridesUpdateTest}.php` | Focused independent foundation/integration behavior and recovery tests. |
| `tests/Feature/Domain/UpdateProjectActionTest.php`, `tests/Support/{FakeProjectUpdateProjectionMutator,LocalAppProjectionTransport}.php` | Existing scalar regressions and native-program transport support; fakes alone are not runtime proof. |

In addition to every admission path above, the planned native adapter integration inventory is:

| Existing paths | Owner |
| --- | --- |
| `app/Infrastructure/Instances/{RemoteDevelopmentInstanceConfigurator,NativeDevelopmentRouteProjector,DevelopmentCaddyAccessCommand}.php` | Instance setup, Routes and Caddy access. |
| `app/Infrastructure/AppDev/{DevelopmentSiteRepository,RemoteAppDevPhpFpmManager,RemoteAppDevCaddyManager,RemoteAppDevCertificateManager}.php` | Routes/Caddy and PHP runtime; reuse existing publishers. |
| `app/Infrastructure/Processes/{RemoteProcessRuntimeManager,SystemdProcessRenderer}.php` | Processes: existing native render/install. |
| `app/Infrastructure/Schedules/RemoteScheduleRuntimeManager.php`, `app/Domain/Schedules/ScheduleRenderer.php` | Schedules: existing native render/install. |

Deployments, transfer, removal, dependency and Route pages retain their existing ownership of the admission surfaces. Their existing refusal/recovery contracts are preserved; Projects owns the additional shared persisted-owner rule. The complete deterministic impact report, with all expanded planned paths and base-to-HEAD changes, is handoff evidence under `.orbit-artifacts/`, not a committed proof file. Reviewer confirmation covers the adapter/guard inventory, impact paths, absence of public-row staging and recorded release/task coverage before implementation begins.

#### Group boundary

Group #982 delivers named apps and per-app development Routes, pools, environments and selectors. It migrates existing single-app production Instances without losing their effective paths, explicit domains, durable environment file, dedicated FPM identity, release receipts or production pools. Their environment configuration is associated with `web`, but their physical production `.env` remains `<home>/.env` and release links retain their existing targets. Production Route pools remain single-app pools; every target and reassignment must match both Project and app.

Multi-app production releases, per-app production masters, durable environment layout for production apps and app-scoped database attachments are not in this group. A Project with production Instances cannot add a second app; production provisioning or cloning a Project with several apps fails before remote work. Existing database attachments on Instances require a Project with one app; adding a second app while attachments exist also fails. This is an explicit refusal, not a silent first-app fallback. Multi-app development apps may configure database keys independently in their own stored environments. These limits keep deployment and database redesign out of the approved delivery contract. The seven replacement subtasks above complete the internal mutation lifecycle, not the remaining public interface and root-removal work. If the recorded group omits any named-app development consumer or migration named here, resolve its ownership before implementation rather than leave selection to an implementer.

## Rejected alternatives

- A second application-directory setting now: it duplicates the information in a Laravel web root and can disagree with it. Named app paths belong to the follow-up model.
- Searching for `artisan` during registration: a repository can contain several apps, so a scan cannot choose the operator's intended app.
- Changing the working directory of every command: repository-wide installs and task checks must still see every subproject. Commands that need an app directory can explicitly change into it.
- One Project or one Instance per app: it duplicates repository identity, source operations, and release selection instead of representing several apps in one copy.
- Several apps served through one arbitrarily selected Route: APP_URL and app selection would depend on ordering instead of a named app's Route.
- Temporary public-row staging or worker-local renderer overrides: other readers/builds would see an uncommitted configuration or a different candidate. Committed journals give every renderer the same desired state without publishing maps early.
- A second Caddy publisher or restoring whole-Node snapshots: it bypasses existing publication locks or overwrites unrelated changes. Reconcile current committed desired state through the existing publisher instead.
- OS locks alone or recapturing snapshots after a lost response: worker exit would admit a different operation, or modified candidate files would become the supposed before-state. Durable owners and stable protected receipts preserve recovery identity.

## Consequences

- A nested Laravel application gets the same environment and runtime behavior as a root-level one, without moving its repository.
- Repository identity, registration without discovery, and repository-owned commands keep the boundary established in group #975.
- Group #982 implements the contract above across the Gateway, CLI, SDK, MCP and web app; the documentation subtask does not change code or migrations.
- Existing generated domains change once to include `web`; explicit domains and runtime identities are preserved in production for the sole app.
- This group excludes multi-app production releases and database attachments scoped to an app; they require a separate group. ADR 0196 stays In progress for this documentation subtask; #1239 absorbs and retires it after group #982 completes its named-app delivery, including #1238 public interfaces/root removal and #1239 web app/group acceptance. Retirement does not require implementing the excluded production or database features.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e, packages/php-sdk, apps/web
- ADRs: none.
- Detail: [Projects: Application directory](/reference/projects#application-directory), [environment file placement](/reference/environment-variables#where-the-file-lives), [releases](/reference/deployments#the-production-home), [Processes](/reference/processes-and-schedules#runtimes), [Schedules](/reference/schedules#execution-context), [PHP runtimes](/reference/php-runtime), [Instance logs](/reference/instance-logs#know-which-file-the-gateway-reads), [dependency scope](/reference/instance-dependencies#what-the-inventory-holds), [dependency collection](/reference/instance-dependency-contracts#collection), [hibernation](/reference/app-dev-runtime-hibernation#dependency-prune), [transfer](/reference/instance-transfer), and [Doctor](/cli/doctor).
- Verify: `composer docs-lint`; the docs-impact report against group #982's start commit `4f1924d857cf82658935cb6b875602924a93c77e` and all planned paths; Gateway tests for path resolution and runtimes during implementation, and independent Incus review of root-level and nested Laravel apps in development and production. The follow-up group verifies named apps and per-app Routes on one Instance.
