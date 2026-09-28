---
title: "ADR 0181: Name the domain Project and Instance everywhere"
sidebarTitle: "0181 Project and Instance"
description: "In progress. The repository record is a Project and a running copy is an Instance, in code, database, API, CLI, and documentation, with no alias."
---

# ADR 0181: Name the domain Project and Instance everywhere

Orbit uses one name for the repository record and one name for a running copy: Project and Instance. Those names are the code, the database, the API, the CLI, and the documentation. There is no second name and no alias.

## Status

In progress.

Principle: [One way, one name](/mission#principles).

## Context

The public surface already says Project and Instance. [ADR 0177](/decisions/0177-remove-the-app-compatibility-surface) removed the compatibility routes, tools, and fields, and left the stored names alone. The code and the database still use another name. The model is `App`, the running copy is `AppInstance`, the tables are `apps` and `app_instances`, and the foreign keys are `app_id` and `app_instance_id`. Classes and namespaces repeat that split (`Actions/Apps`, `App*` types) in the Gateway, the CLI, the PHP SDK, and the web app.

A reader, an agent, and a migration each have to know which name is real. The [mission](/mission#principles) requires one term in the CLI, the API, the database, and the documentation.

Other uses of "app" are not this domain. The monorepo folders `apps/cli`, `apps/gateway`, and the rest are application folders. Laravel's root namespace `App\` stays, so `Actions/Apps` becomes `App\Actions\Projects`. The `app/` source folders, `config/app.php`, and the `APP_*` variables stay. Node roles `app-dev` and `app-prod`, the GitHub App, the project type `laravel-app`, the Route kind `app`, and the node storage setting member `apps` stay. None of these is the Project or Instance record.

## Decision

Project is the repository record. Instance is one running copy of a Project on a Node. Those are the only names for this domain. Nothing keeps the old name as an alias, a route, a JSON field, a class, a table, or a column.

- The `App` model is `Project`. The `AppInstance` model is `Instance`.
- Tables are `projects`, `instances`, and `instance_*`. Examples are `instance_deployments`, `instance_transfers`, and `instance_dependency_edges`. `app_updates` is `project_updates`.
- `app_id` is `project_id`. `app_instance_id` is `instance_id`. A path parameter that identifies a Project is `{project}`.
- `Actions/Apps` is `Actions/Projects`. An `App*` class is `Project*` or `Instance*` according to whether it belongs to the repository record or the running copy. The Gateway, the CLI, the PHP SDK, and the web app follow the same rule.
- Environment placeholders are `{{instance.domain}}` and `{{instance.environment}}`. The migration rewrites those strings inside stored Instance environment values. The value column is encrypted. `{{app_instance.domain}}` becomes `{{instance.domain}}`, and `{{app_instance.environment}}` becomes `{{instance.environment}}`. A stored value that contains any other `{{...}}` is invalid. Synchronization returns `env.reference_unavailable`. A write that fails placeholder validation returns `env.configuration_invalid`.
- The `App` model has no morph map entry, so Eloquent stores the class name `App\Models\App` in `activity_log.subject_type` and `activity_log.causer_type`. `CommandActivityTargetResolver` stores `getMorphClass()`, and the Activity API maps that stored class to `subject_type`. The migration rewrites `App\Models\App` to `App\Models\Project` in both columns. Project has no morph alias. The public subject type for that record is `project`. The Instance morph alias stays `instance`, and rows already stored as `instance` are not rewritten.
- Realtime types `app.created`, `app.updated`, and `app.deleted` are `project.created`, `project.updated`, and `project.deleted`. Payload classes follow the class rule: `ProjectData`, `InstanceData`, and `InstanceDeploymentData`.
- Project error codes use the `project.` prefix. `app.has_app_instances` is `project.has_instances`. `node.has_app_instances` is `node.has_instances`. `app_instance.placement_unavailable` is `instance.placement_unavailable`. Doctor's repository family is `project`.

The documentation lint fails when an App-domain name returns in a maintained page outside `docs/decisions/`: the `App` model, `AppInstance`, the tables `apps` and `app_instances`, or the columns `app_id` and `app_instance_id`. It does not treat these as that domain: monorepo paths under `apps/`, the Laravel root namespace `App\`, `app/` source folders, `config/app.php`, `APP_*` variables, Node roles `app-dev` and `app-prod`, the GitHub App, the type `laravel-app`, the Route kind `app`, or the node storage setting member `apps`. `covers:` globs name the repository files that exist. Those globs change in the same change that renames the files, and the lint then rejects an App-domain name left in a glob.

This amends [ADR 0177](/decisions/0177-remove-the-app-compatibility-surface), which removed the public compatibility surface and did not rename stored records.

## Rejected alternatives

- Keep the stored names: two names for one concept force every reader to translate.
- Keep aliases that accept the old name: an alias is a second supported path.
- Rename the monorepo folders, the Laravel root namespace `App\`, the `app/` source folders, `config/app.php`, the `APP_*` variables, Node roles, the GitHub App, the type `laravel-app`, the Route kind `app`, or the node storage setting: those names are not the Project or Instance record.
- Leave stored environment placeholders or `App\Models\App` activity types in place and read them as the new names: that is an alias, and synchronization and activity resolution then fail on existing rows.
- Update the documentation only after the code moves: the documentation is the contract, so it uses Project and Instance while this decision is in progress.

## Consequences

- One concept has one name in the CLI, API, database, SDK, web app, and documentation.
- A migration renames the tables and columns. In the same change it rewrites stored Instance environment placeholders and rewrites `activity_log.subject_type` and `activity_log.causer_type` from `App\Models\App` to `App\Models\Project`. There is no compatibility view and no dual-write.
- A stored environment value that contains `{{app_instance.domain}}` or `{{app_instance.environment}}` makes synchronization return `env.reference_unavailable`. An `activity_log` row that contains `App\Models\App` does not resolve as a Project subject.
- Callers that send the old fields, or code that still imports the old classes, fail. There is no compatibility period.
- The documentation lint rejects a maintained page that brings an App-domain name back.
- Monorepo folders stay `apps/*`. Laravel's root namespace `App\`, `app/` source folders, `config/app.php`, `APP_*` variables, Node roles, the GitHub App, the type `laravel-app`, the Route kind `app`, and the node storage setting stay.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, apps/web, packages/php-sdk
- ADRs: amends [ADR 0177](/decisions/0177-remove-the-app-compatibility-surface)
- Detail: [Projects](/reference/projects#project-and-instance)
- Verify: `composer docs-lint`; Gateway, CLI, SDK, and web tests for Project and Instance names
