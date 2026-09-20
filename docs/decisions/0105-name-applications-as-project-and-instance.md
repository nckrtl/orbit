---
title: "ADR 0105: Name applications as Project and Instance"
sidebarTitle: "0105 Name applications as Project and Instance"
description: "Proposed. Orbit's repository record is a Project. A managed placement of that Project is an Instance. The public App surface becomes Project during a dual-read window so a mixed fleet does not lose access."
---

# ADR 0105: Name applications as Project and Instance

Orbit's repository-owned record is a Project. A managed placement of that Project on a Node is an Instance. The public App HTTP, CLI, MCP, and OpenAPI surface becomes Project. Instance paths and commands that already exist stay. A dual-read window keeps `/api/v1/apps` and generated `app-*` MCP tools working so Ops can migrate the fleet without a mid-cutover brick.

## Status

Proposed. Amends [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) for Project and Instance terminology and the reserved `default` identity. Amends [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk) for one compatibility window that keeps the previous App HTTP and MCP names. Extends [ADR 0036](/decisions/0036-support-only-appinstances) and [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools).

## Context

Operators and agents say "app" for a Laravel application, a desktop binary, and Orbit's repository record. The Gateway already publishes Instances at `/api/v1/instances` and `instance:*`. The remaining App surface (`/api/v1/apps`, `app:*`, `app-*` MCP tools, OpenAPI `App`) still names the repository record. A hard cutover of that surface would brick Ops MCP and older CLI binaries while a Gateway that has not upgraded still serves only `/apps`.

The default Instance identity is already `default` and already tracks the repository record's `default_branch` ([ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity)). This record does not change that identity. It names the owner a Project.

GitHub App, `app-dev`, `app-prod`, `APP_ENV`, `APP_DEBUG`, and the monorepo directory `apps/` are not this record.

## Decision

- The Gateway model for one Git repository and its shared source defaults is `Project`. The persisted table remains `apps` so this upgrade copies no rows.
- The Gateway model for one managed placement is `Instance`. The persisted table remains `app_instances`. Foreign keys stay `app_id` and `app_instance_id`.
- Morph writers store the alias `instance` for Process owners, Schedule targets, and TaskGroup `taskable` values. The upgrade rewrites stored `App\Models\AppInstance` class names to `instance` and leaves every id in place.
- Canonical HTTP for the repository record is `/api/v1/projects` with route names `project:*`. `/api/v1/apps` and route names `app:*` remain a dual-read and dual-write compatibility surface for the same controllers and records.
- Canonical CLI for the repository record is `project:*`. This repository's command surface drops `app:*`. Older CLI binaries keep working because they still call `/api/v1/apps`.
- MCP keeps generated `app-*` tools from the compatibility routes and adds `project-*` tools from the canonical routes ([ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools)).
- Instance JSON uses `project_id` and `project` as the canonical nested owner. During the compatibility window the payload also includes `app_id` and `app` with the same values. Create and update input accepts either `project_id` or `app_id`.
- Process and Schedule definition paths exist under both `/api/v1/projects/{project}/…` and `/api/v1/apps/{app}/…`. `--project` is the canonical CLI option; `--app` remains accepted as the same identifier.
- Error codes that already use the `app.` and `instance.` prefixes stay. New Project-type errors use `project.`.
- A follow-up cleanup PR may drop the `/apps` routes, `app-*` MCP tools, and compatibility JSON keys after Ops verifies the fleet. That cleanup is not this decision.

## Rejected alternatives

- Rename the tables and foreign keys in the same upgrade: rejected because a table rename is not required to change the public contract and adds a second data-loss surface while Ops must prove zero row loss.
- Hard-cut `/apps` and `app-*` MCP tools in this PR: rejected because a mixed fleet would lose the Ops MCP catalogue mid-upgrade.
- Keep CLI `app:*` aliases beside `project:*`: rejected because [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk) forbids command aliases; the Gateway compatibility window already covers older binaries.
- Rename `app-dev`, `app-prod`, or GitHub App: rejected because those names are Node roles and an external product, not the Orbit repository record.

## Consequences

- Documentation, OpenAPI, MCP, and the PHP SDK can say Project and Instance without waiting for a table rewrite.
- Ops can deploy the Gateway first. Old CLI and MCP callers keep using `/apps`. New callers use `/projects`.
- Internal action and satellite model names may still say App or AppInstance until a focused follow-up.
- Activity rows written before this change keep their `app:*` command names.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: amends [ADR 0025](/decisions/0025-stabilize-the-default-appinstance-identity) and [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk); extends [ADR 0036](/decisions/0036-support-only-appinstances) and [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools)
- Detail: [Projects](/reference/apps), [Applications](/domains/applications), [Concepts](/concepts)
- Verify: `composer docs-lint`; Gateway dual-read tests; CLI command-surface test; PHP SDK transport tests
