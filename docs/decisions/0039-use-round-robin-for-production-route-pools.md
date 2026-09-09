# ADR 0039: Use round-robin for production Route pools

In the context of one production Route serving AppInstances on several Nodes, facing an unspecified backend-selection policy, we decided for equal-priority round-robin with temporary transport-failure exclusion and against backend affinity or automatic request replay, to distribute traffic without changing the public endpoint, accepting that requests can fail and application session state must be available across targets.

## Status

Accepted on 2026-09-06. Extends [ADR 0028](0028-require-one-route-per-active-appinstance.md) and [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md). Supersedes [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for its deferral of production pool balancing, backend health, affinity, retry, and target-removal policy.

## Context

An explicit production Route can identify placements of one App on several app-prod Nodes, but ADR 0011 leaves request selection undefined. Application HTTP responses do not establish AppInstance provisioning state. Shared database or Redis session storage lets requests served by different AppInstances access the same session, provided the application uses compatible session, cookie, and encryption configuration; this does not require backend affinity.

## Decision

- The Cluster Router must select eligible targets of an explicit production Route by round-robin with equal priority.
- Orbit must keep generated Routes and app-dev Routes single-target.
- The Router must temporarily exclude a target after a connection or TLS failure and restore its eligibility after a bounded cooldown without requiring an operator mutation.
- The Router must not treat application HTTP error responses as transport failures or change AppInstance provisioning state because of request results.
- The Router must not automatically replay a failed request against another target.
- The Router must return an unavailable response when the pool is empty or no target is eligible, without deleting the Route or changing its hostname or Ingress identity.
- The Gateway must publish complete target-set changes while preserving ADR 0028's sole-Route constraint through same-operation Route reassignment or AppInstance removal.
- The Router must stop assigning new requests to a removed target when the replacement pool takes effect, without waiting for requests already assigned to finish.
- Orbit must not require in-flight requests to succeed after their target or its runtime is removed.
- Orbit must not expose configurable balancing algorithms, weights, backend affinity, active health checks, or drain timers for these pools.
- Application operators own shared session storage and compatible application configuration across targets; Orbit must not configure or convert session storage as part of Route management.

## Rejected alternatives

- Backend affinity: rejected because it binds session continuity to one placement and is unnecessary when all targets can access the same session store.
- Application-health-gated pool membership: rejected because an application HTTP error does not establish whether its Orbit-managed placement is provisioned.
- Automatic request replay: rejected because a failed response does not prove that the application performed no side effects.
- Wait for every request before removing a target: rejected because application execution would control when target removal completes.

## Consequences

- A production pool can distribute requests while preserving the Route hostname and Ingress projection.
- Shared database or Redis sessions can preserve login and session data across requests to different targets; local session files require application-side changes for this behavior.
- Round-robin does not guarantee identical completed-request counts, session locking, or backend affinity.
- A request that encounters a transport failure can fail, and a recovered target can remain unused until its cooldown expires.
- The implementation contract must specify the fixed cooldown and unavailable response and prove exclusion, recovery, no replay, and sole-Route preservation.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli
- ADRs: extends [ADR 0028](0028-require-one-route-per-active-appinstance.md) and [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md); supersedes [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for production pool policy
- Detail: [Routes](../reference/routes.md)
- Verify: `composer docs-lint`; implementation conformance through Gateway Route tests and declared Incus multi-target routing acceptance
