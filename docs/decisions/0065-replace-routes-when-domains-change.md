---
title: "ADR 0065: Replace Routes when domains change"
sidebarTitle: "0065 Replace Routes when domains change"
description: "Accepted on 2026-09-13. Extends ADR 0064."
---

# ADR 0065: Replace Routes when domains change

In the context of Route domain changes that must coordinate database and machine state, facing duplicated transition values on one mutable Route, we decided for immutable domains and replacement Route records and against in-place domain mutation or one generic inactive state, to make authority and recovery explicit, accepting new Route identities and temporary non-authoritative associations.

## Status

Accepted on 2026-09-13. Extends [ADR 0064](0064-name-application-endpoints-as-domains.md). Supersedes [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md), [ADR 0024](0024-follow-generated-route-targets.md), and [ADR 0028](0028-require-one-route-per-active-appinstance.md) only where they require domain mutation on one Route, preserve that Route identity, or prohibit a temporary non-authoritative Route association.

## Context

A domain change keeps the authoritative, previous, and candidate endpoint values on one Route beside durable direction and progress fields. The old domain must remain authoritative while Orbit prepares the replacement, and the new domain must remain authoritative after cutover even when cleanup fails. Separate Route records can own those two endpoint values, but Orbit must still expose exactly one authoritative application endpoint and distinguish preparation from retirement.

## Decision

- A Route owns an immutable domain.
- Orbit must create a replacement Route when a Route domain changes.
- Orbit must reserve the replacement domain while the existing Route remains authoritative.
- Orbit must distinguish replacement preparation, activation, authority, retirement, and recoverable failure.
- Orbit must expose replacement lifecycle state for operator inspection.
- An AppInstance must have exactly one authoritative Route while it may have a non-authoritative replacement association.
- Orbit must replace one shared production Route for its complete target pool.
- Orbit must make the replacement authoritative and make the old Route retiring in one database transition.
- Orbit must leave the old Route authoritative when failure occurs before cutover.
- Orbit must recover only forward when failure occurs after cutover.
- Orbit must delete the retiring Route after cleanup and release its domain.
- Orbit must retain a Route when its target, scope, or publication changes without a domain change.

## Rejected alternatives

- Mutate one Route domain in place: rejected because the record must duplicate old and candidate domains and transition progress during recovery.
- Use one inactive state for every non-authoritative Route: rejected because preparation, recoverable failure, and retirement require different retry and cleanup behavior.
- Retain retired Routes permanently: rejected because their domains would remain reserved after cleanup.
- Replace each target of a shared production Route separately: rejected because one canonical application domain cannot cut over independently per target.

## Consequences

- A successful domain change produces a new Route ID.
- Route inspection can show a pending or failed replacement while AppInstance output continues to expose one authoritative Route.
- Persistence no longer needs duplicate domain-change values on the authoritative Route.
- Replacement preparation and retirement add temporary Route records and associations.
- Existing generated-domain reconciliation and explicit domain-change work must adopt replacement Routes before claiming conformance.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0064](0064-name-application-endpoints-as-domains.md); supersedes [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md), [ADR 0024](0024-follow-generated-route-targets.md), and [ADR 0028](0028-require-one-route-per-active-appinstance.md) for in-place domain changes, Route identity preservation, and temporary replacement associations
- Detail: [Routes](../reference/routes.md)
- Verify: Gateway replacement lifecycle and failure-injection tests, affected CLI and PHP SDK tests, and domain cutover checks on disposable Nodes
