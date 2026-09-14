---
title: "ADR 0064: Name application endpoints as domains"
sidebarTitle: "0064 Name application endpoints as domains"
description: "Accepted on 2026-09-13. Extends ADR 0044."
---

# ADR 0064: Name application endpoints as domains

In the context of application endpoints and machine identities both being called hostnames, facing a coordinated cross-component migration before AppInstance transfers, we decided for application domains and against hostname aliases, to give application routing one unambiguous canonical term, accepting a forward-only hard cutover.

## Status

Accepted on 2026-09-13. Extends [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md). Supersedes [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md) and [ADR 0063](0063-prefer-active-cluster-tlds-for-generated-routes.md) only where they name a Route-owned application endpoint a hostname.

## Context

A Route-owned application endpoint and a Node's machine identity share the term hostname across persistence, public clients, environment configuration, and operational records. An AppInstance transfer between Clusters needs to distinguish an application endpoint that may change from the hostname of each machine that participates in routing. Orbit controls the affected Gateway, SDK, and CLI contracts together, so retaining both names would preserve the ambiguity instead of resolving it.

## Decision

- A Route owns one application domain.
- Orbit must use domain for every Route-owned or application-serving endpoint.
- Orbit must retain hostname for machine and network identities.
- The Gateway must expose application domains without hostname aliases.
- The PHP SDK and CLI must expose application domains without hostname aliases.
- Orbit must migrate Legacy Instance and Workspace application endpoints to domains.
- Orbit must preserve each existing endpoint value when it migrates that value to the domain model.
- Orbit must migrate stored application configuration references to the domain model.
- Orbit must migrate structured operational application-domain data while preserving historical prose.
- Orbit must refuse the migration while an earlier Route hostname change is incomplete.
- Orbit must recover a failed migration by fixing and continuing it forward.
- Orbit must not support rollback to application hostname contracts after migration begins.

## Rejected alternatives

- Keep hostname as the application endpoint term: rejected because it remains indistinguishable from a machine hostname during placement and routing changes.
- Accept hostname as a compatibility alias: rejected because clients and stored configuration would continue producing both names indefinitely.
- Support rollback to the hostname schema: rejected because new domain-only state would need a second compatibility migration and would reopen the removed contract.

## Consequences

- Operators and clients can distinguish application domains from machine hostnames.
- Gateway, SDK, and CLI releases must cut over together, and older clients fail instead of receiving compatibility behavior.
- A failed migration can delay Gateway availability until its forward repair completes.
- Every maintained page and open issue that describes an application hostname needs reconciliation.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md); supersedes [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md) and [ADR 0063](0063-prefer-active-cluster-tlds-for-generated-routes.md) for the application endpoint term
- Detail: [Routes](../reference/routes.md)
- Verify: Gateway migration and domain-contract tests, affected CLI and PHP SDK tests, `composer docs-lint`, and application routing checks on disposable Nodes
