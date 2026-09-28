---
title: "ADR 0177: Remove the app compatibility surface"
sidebarTitle: "0177 Remove app compatibility"
description: "Proposed. Orbit removes the app-named compatibility surface and keeps Project as the only public repository-record surface."
---

# ADR 0177: Remove the app compatibility surface

Orbit exposes the repository record as a Project, not an App. Compatibility paths, aliases, inert commands, and conversion windows are removed by default; they do not remain while waiting for fleet migration or operator confirmation.

## Status

Proposed. Amends [ADR 0105](/reference/apps#project-and-instance), which established a temporary `/apps` dual-read and dual-write surface, and [ADR 0112](/reference/tasks#drivers) for the task agent driver environment variables.

## Context

ADR 0105 made Project the canonical name but retained `/api/v1/apps`, generated `app-*` MCP tools, duplicate process and Schedule paths, and Instance `app` fields during a compatibility window. It expected cleanup after fleet migration. Orbit has one user, and maintaining this extra surface has no value to that operator. A compatibility window adds code, documentation, tests, and ambiguity without protecting a supported user population.

The same default applies to CLI aliases and obsolete configuration names. The reference documentation names Project as the supported surface; implementation details such as database table names do not define a public compatibility contract.

## Decision

The operator's fixed rule is recorded verbatim:

```text
I do not value legacy support. I'm the only user of Orbit, so any legacy support should be removed by default.
```

Remove compatibility behavior without asking and without a conversion window. Project is the only public surface for the repository record.

This amends ADR 0105 as follows:

- Keep `/api/v1/projects` and `project:*`. Remove the `/api/v1/apps` routes, including `app:list`, `app:show`, `app:create`, `app:update`, and `app:destroy`, plus the App-nested process-definition and Schedule-definition routes. Keep the canonical Project-nested definition routes. Remove the 15 generated `app-*` MCP tools derived from those routes.
- Remove the optional `type` behavior from `StoreAppRequest` that supplies `laravel-app` only for `/apps`. Project creation requires an explicit `type`.
- Remove `app_id` and `app` from Instance JSON alongside the canonical `project_id` and `project`. Remove `app_id` as an Instance create or registration input; callers use `project_id`.
- Remove the `--app` alias for Project selection on process, Schedule, `instance:register`, and the dependency commands; use `--project`. On `instance:dependencies:scan` and `instance:dependencies:update`, `--project` takes the Route domain of one Instance.
- Remove `--wireguard-address`, the alias for `--wireguard-ip`, from `node:add` and Gateway console commands. `--wireguard-ip` is the supported name.
- Remove the `ORBIT_TASKS_AGENT_DRIVER` environment fallback. `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` each default directly to `t3`; a configured role-specific driver continues to select the driver for that role.

The database's existing table names and foreign keys are not public aliases and are not renamed by this decision. Nor does this decision rename unrelated names such as GitHub App or Node roles. There is no fleet conversion period or inert compatibility endpoint.

## Rejected alternatives

- Retain `/apps` until older clients have migrated: rejected because the operator does not value legacy support and there is no other user whose migration needs protection.
- Keep aliases that are cheap or harmless: rejected because duplicate names obscure the supported interface and still require maintenance.
- Add a conversion window or an inert endpoint: rejected because neither provides value to this operator.
- Rename persisted tables and foreign keys as part of this cleanup: rejected because storage identifiers are internal implementation details and a schema rename is unrelated to removing the public compatibility surface.

## Consequences

- The Project API, CLI, MCP tools, and Instance owner fields have one unambiguous public name.
- Older binaries or clients that depend on `/apps`, `app-*`, `app_id`, `app`, `--app` as a Project selector, or the removed environment fallback must be updated; no compatibility period is provided.
- Dependency scan and update select one Instance with `--project` and its Route domain. They have no `--app` option.
- Historical ADR 0105 retains its original rationale and records the prior window; this ADR supersedes only that compatibility commitment.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, packages/php-sdk
- ADRs: amends [ADR 0105](/reference/apps#project-and-instance) and [ADR 0112](/reference/tasks#drivers)
- Detail: [Projects](/reference/apps), [project commands](/cli/project), [Tasks](/reference/tasks)
- Verify: `composer docs-lint`; implementation and contract tests for Project routes, MCP tools, Instance payloads and inputs, CLI options, node WireGuard options, and task driver configuration
