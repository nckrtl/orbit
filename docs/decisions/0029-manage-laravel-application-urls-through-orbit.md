# ADR 0029: Manage Laravel application URLs through Orbit

In the context of Laravel AppInstances whose configured application URL can differ from their Route, facing inconsistent links and background application behavior, we decided for Orbit to manage the application URL in development and production and against leaving that setting to the operator, to keep the effective application URL aligned with routing, accepting framework configuration reconciliation on workload Nodes.

## Status

Accepted on 2026-09-05. Extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md) and [ADR 0028](0028-require-one-route-per-active-appinstance.md). Amends [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for Orbit ownership of Laravel's canonical application URL configuration on app-prod Nodes.

## Context

A Laravel application uses its configured application URL when it generates links outside an HTTP request. One Route per active AppInstance does not keep that configuration aligned when the hostname changes or deployment replaces configuration. Orbit must manage this setting for both development source and operator-deployed production content.

## Decision

- Orbit owns the canonical application URL configuration of Laravel AppInstances on app-dev and app-prod Nodes.
- Orbit must derive that URL from the AppInstance's sole Route.
- Orbit must apply and verify the effective application URL before it completes AppInstance activation or a change to the canonical URL.
- Orbit must refuse or roll back a URL change it initiates when the application configuration and Route cannot be made consistent.
- Orbit must preserve unrelated application settings when it reconciles the application URL.

## Rejected alternatives

- Leave application URL configuration entirely to the operator: rejected because a Route change would complete while Laravel still uses the previous URL.
- Manage the setting only in development: rejected because production applications need the same agreement between their configured URL and Route.
- Read an existing application URL as the routing authority: rejected because an explicitly selected or generated Route determines the AppInstance endpoint.

## Consequences

- Operators select the hostname through Orbit and do not need a separate application URL edit when Orbit changes the hostname.
- Production deployment ownership remains with the operator or deployment tool, while Orbit owns this application configuration setting.
- Reconciliation needs to verify the configuration Laravel actually uses, including runtime configuration retained after a file changes.
- A deployment that replaces application configuration can introduce drift and requires application URL reconciliation.
- An application configuration failure can prevent AppInstance activation or a hostname change.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md) and [ADR 0028](0028-require-one-route-per-active-appinstance.md); amends [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for Orbit ownership of Laravel's canonical application URL configuration on app-prod Nodes
- Detail: [Applications](../domains/applications.md)
- Verify: `bin/test`
