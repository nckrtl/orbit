# ADR 0034: Select AppInstance PHP from Composer constraints

In the context of development AppInstances whose source may declare a PHP platform constraint, facing a fixed role default that can be incompatible with an application, we decided for Orbit selecting the highest supported PHP version allowed by a PHP project's Composer constraint and against a fixed version or an AppInstance PHP setting, to run each PHP project on a compatible managed runtime, accepting that the available Orbit runtime set limits selection.

## Status

Accepted on 2026-09-05. Extends [ADR 0021](0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md). Supersedes [ADR 0009](0009-clustered-app-instance-routing.md) only where it prevents Orbit from selecting an AppInstance runtime from its source.

## Context

ADR 0009 puts application-runtime prerequisites on the Node role and prevents an AppInstance from selecting a private runtime. A PHP project's `composer.json` can instead state the PHP versions the application supports, while a non-PHP source needs no PHP runtime. Orbit needs one source-derived selection rule that preserves role-owned package management without asking operators to maintain a second AppInstance setting.

## Decision

- Orbit must treat a source with a valid `composer.json` as a PHP project.
- Orbit must select the highest Orbit-supported PHP version that satisfies the PHP project's Composer platform constraint.
- Orbit must use PHP 8.5 when a PHP project's Composer metadata has no PHP platform constraint.
- Orbit must not select, persist, or expose a user-supplied PHP version on an AppInstance.
- Orbit must not select or prepare PHP for a source that is not a PHP project.
- The Node application role owns installation, configuration, and removal of every selected PHP runtime.

## Rejected alternatives

- Use PHP 8.5 for every PHP project: rejected because a project can declare a higher compatible PHP version.
- Store a PHP version on AppInstance: rejected because it duplicates source compatibility intent and creates a separate update lifecycle.

## Consequences

- A PHP project uses the newest compatible runtime that Orbit supports.
- A source without Composer metadata receives no PHP runtime.
- An unsupported Composer constraint prevents a compatible PHP runtime from being selected.
- The supported PHP version set remains an Orbit operational responsibility.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0021](0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md); supersedes [ADR 0009](0009-clustered-app-instance-routing.md) where Orbit selects an AppInstance runtime from its source
- Detail: docs/reference/php-runtime.md
- Verify: `bin/test` and the development AppInstance provisioning Incus proof actions
