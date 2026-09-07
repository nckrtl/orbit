# ADR 0041: Delete an empty Route during AppInstance removal

In the context of AppInstance removal from single-target development Routes and shared production Route pools, facing a requirement that a removed AppInstance must not leave a retained zero-target Route, we decided to delete a Route when removal leaves it with no targets and against retaining unavailable Routes after their final AppInstance is removed, to release the hostname and preserve Route ownership, accepting that a failure after Route deletion cannot restore the deleted Route.

## Status

Accepted on 2026-09-07. Extends [ADR 0039](0039-use-round-robin-for-production-route-pools.md). Supersedes [ADR 0028](0028-require-one-route-per-active-appinstance.md) and [ADR 0024](0024-follow-generated-route-targets.md) for Route retention after AppInstance removal.

## Context

ADR 0028 permits a Route to remain after its AppInstance is removed, and ADR 0024 defines its retained zero-target identity. That would retain a hostname without an AppInstance after removal. A shared production Route remains needed while it serves other AppInstances, so removal must distinguish departure from a pool and removal of its final target.

## Decision

- The Gateway must validate the complete removal set before it changes a Route, runtime, source, or AppInstance record.
- The Gateway must remove a departing AppInstance from a shared Route target set and retain the Route while one or more other AppInstances remain.
- The Gateway must delete a Route when AppInstance removal removes its final target, before source finalization.
- Orbit must not retain a zero-target Route solely because its final AppInstance is being removed.
- The Gateway must make a deleted Route hostname available for a new Route immediately.
- The Gateway must keep an AppInstance `removing` when failure follows a Route-target removal or Route deletion, and retry remaining cleanup without recreating that Route.

## Rejected alternatives

- Retain a zero-target Route: rejected because the removed AppInstance no longer needs its hostname.
- Delete a shared Route when one target is removed: rejected because it would stop traffic to AppInstances that remain in its pool.

## Consequences

- An AppInstance that has a single-target Route loses that Route before source cleanup.
- A shared production Route continues balancing requests after one target leaves.
- A retry after source cleanup failure cannot restore the old Route or hostname reservation.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0039](0039-use-round-robin-for-production-route-pools.md); supersedes [ADR 0028](0028-require-one-route-per-active-appinstance.md) and [ADR 0024](0024-follow-generated-route-targets.md) for Route retention after AppInstance removal
- Detail: [Routes](../reference/routes.md)
- Verify: `bin/test`
