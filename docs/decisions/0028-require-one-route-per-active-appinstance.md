# ADR 0028: Require one Route per active AppInstance

In the context of AppInstances that can be targeted by several Routes or remain active without a Route, facing applications that need one canonical URL, we decided for exactly one Route per active AppInstance and against multiple hostnames or unrouted active instances, to keep application identity and routing consistent, accepting coordinated activation, replacement, and removal.

## Status

Accepted on 2026-09-05. Extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md). Supersedes [ADR 0009](0009-clustered-app-instance-routing.md) for AppInstance activation and independent target detachment, and [ADR 0024](0024-follow-generated-route-targets.md) for target replacement and clearing that would leave an active AppInstance without a Route.

## Context

The current Route model permits several Routes to target one AppInstance and permits target clearing or Route removal while that AppInstance stays active. Orbit needs one application URL per active AppInstance; Laravel's configured application URL must agree with that endpoint. The explicit or generated hostname selection defined by ADR 0023 must therefore select the AppInstance's sole Route.

## Decision

- An AppInstance must be targeted by at most one Route.
- An active AppInstance must be targeted by exactly one Route.
- Orbit must establish the Route association before it exposes an AppInstance as active.
- Orbit may retain an AppInstance without a Route only during creation, failed activation, or removal.
- Orbit must refuse a Route target replacement, target clearing, or Route removal that would leave an active AppInstance without a Route unless the same operation replaces its Route or removes the AppInstance.

## Rejected alternatives

- Permit several Routes per AppInstance: rejected because they expose several hostnames for an application that needs one canonical URL.
- Select a primary Route from several associated Routes: rejected because the other Routes would still publish the AppInstance under additional hostnames.
- Allow an active AppInstance without a Route: rejected because active state would no longer promise a configured application endpoint.

## Consequences

- AppInstance output can identify one Route and derive one hostname without a primary-Route selection policy.
- AppInstance activation and Route changes need coordinated lifecycle transitions instead of independent source activation and target mutation.
- A Route can still retain its hostname after its AppInstance is removed, under the existing zero-target Route contract.
- Production target pools remain compatible: several AppInstances can share one Route while each has only that Route.
- Existing data and mutation paths need validation before the new association and activation rules become authoritative.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0023](0023-separate-hostname-selection-from-cluster-routing.md); supersedes [ADR 0009](0009-clustered-app-instance-routing.md) for AppInstance activation and independent target detachment, and [ADR 0024](0024-follow-generated-route-targets.md) for target replacement and clearing that would leave an active AppInstance without a Route
- Detail: [Routes](../reference/routes.md)
- Verify: `bin/test`
