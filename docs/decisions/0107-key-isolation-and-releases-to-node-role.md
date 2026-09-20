---
title: "ADR 0107: Key isolation and releases to node role"
sidebarTitle: "0107 Key isolation and releases to node role"
description: "Proposed. Candidate-only creation, release layout, and Unix-user isolation follow the Node role. Laravel mode is the Instance's APP_ENV value, not a Gateway development/production attribute."
---

# ADR 0107: Key isolation and releases to node role

Candidate-only creation, release layout, and app-prod isolation follow the Node role (`app-prod` or `app-dev`). Laravel application mode is the stored `APP_ENV` value. `APP_DEBUG` is independent. An Instance has no Gateway development/production mode attribute.

## Status

Proposed. Amends [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user), [ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit), [ADR 0047](/decisions/0047-create-production-appinstances-from-candidates), and [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes) to replace "production instance" language with node-role placement and configured Laravel mode. Extends [ADR 0044](/decisions/0044-own-appinstance-environment-configuration-in-orbit) and [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type).

## Context

The Gateway stores `app_instances.environment` as `development` or `production` and uses that column for isolation, release layout, candidate-only creation, hibernation, and transfer. Operators also store Laravel `APP_ENV` in the Instance environment. Those two facts drift. Changing `APP_ENV` must not move an Instance between checkout and release layouts or change its Unix user.

Candidate-only creation already keys off the destination Node's `app-prod` role in the create path. Clone still writes `environment = production`. Transfer still refuses a "production instance". The public Instance payload still exposes `environment` as if it were Laravel mode.

Nick confirmed that existing app-prod placements must also receive `APP_ENV=production` and `APP_DEBUG=false` during this upgrade.

## Decision

- Isolation, release layout, candidate-only creation, deploy, rollback, and transfer eligibility follow the destination or owning Node role.
- Creating an Instance on a Node with an active `app-prod` role still requires a candidate ([ADR 0047](/decisions/0047-create-production-appinstances-from-candidates)). Direct create on app-prod stays refused.
- An Instance on app-prod uses the release layout and a dedicated Unix user when it is a PHP Instance. Dedicated FPM still follows [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type).
- An Instance on app-dev uses the checkout or worktree layout. Transfer remains an app-dev to app-dev move ([ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes)).
- Laravel mode is the stored `APP_ENV` environment value. `APP_DEBUG` is a separate stored value.
- When `APP_ENV` is absent, readers use `development` on app-dev and `production` on app-prod. They do not invent other values.
- The placeholder `{{app_instance.environment}}` expands to that same node-role default so existing stored templates keep rendering.
- Moving an Instance preserves configured environment values, including `APP_ENV` and `APP_DEBUG`.
- Cloning onto app-prod copies the candidate configuration and then writes `APP_ENV=production` and `APP_DEBUG=false`. An operator may edit those keys afterward.
- Changing `APP_ENV` or `APP_DEBUG` does not change isolation, Unix user, FPM, or release layout.
- The upgrade writes `APP_ENV=production` and `APP_DEBUG=false` on every Instance whose Node has an active `app-prod` role. Other keys stay. Missing rows are created. Existing values are replaced for those two keys only.
- The Gateway may keep an internal placement column aligned with node role for query and removal evidence. That column is not Laravel mode and is not part of the public Instance contract after this change, except as a compatibility `environment` field derived from node role during the [ADR 0105](/decisions/0105-name-applications-as-project-and-instance) window.

## Rejected alternatives

- Treat stored `APP_ENV` as the isolation switch: rejected because an operator could disable release layout or Unix-user isolation by editing an environment value.
- Drop candidate-only creation and allow direct app-prod create: rejected because [ADR 0047](/decisions/0047-create-production-appinstances-from-candidates) still owns that contract; only its key changes from "production instance" to app-prod Node role.
- Leave existing app-prod `APP_ENV` and `APP_DEBUG` untouched: rejected because Nick confirmed those placements must be normalized in this upgrade.
- Infer Laravel mode from the Gateway placement column forever: rejected because application mode belongs in the application's environment.

## Consequences

- Release deploy, Doctor production checks, and FPM identity use node role even when `APP_ENV` is `local` or `staging`.
- A clone onto app-prod always starts with production Laravel flags, then stays editable.
- Transfer documentation says app-dev placement instead of "development instance".
- Tests that created a production placement without an app-prod role must place the Instance on an app-prod Node.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: amends [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user), [ADR 0046](/decisions/0046-own-production-release-deployment-in-orbit), [ADR 0047](/decisions/0047-create-production-appinstances-from-candidates), and [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes); extends [ADR 0044](/decisions/0044-own-appinstance-environment-configuration-in-orbit) and [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type)
- Detail: [Environment variables](/reference/environment-variables), [Deployments](/reference/deployments), [App instance cloning](/reference/appinstance-cloning)
- Verify: `composer docs-lint`; Gateway clone normalization, app-prod backfill, and node-role placement tests
