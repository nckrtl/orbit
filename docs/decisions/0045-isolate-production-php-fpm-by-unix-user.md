# ADR 0045: Isolate production PHP-FPM by Unix user

In the context of production AppInstances that share a PHP-FPM master, facing cache resets that affect other applications, we decided for one master per production Unix user with tuning kept on the Node and against shared production masters and Gateway-owned tuning, to isolate runtime operations, accepting separate cache allocations and configuration maintained on each Node.

## Status

Accepted on 2026-09-10. Extends [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for production runtime ownership. Supersedes [ADR 0021](0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) for production service scope, tuning ownership, and deployment cache refresh.

## Context

PHP-FPM pools under one Linux master share an OPcache instance. Selecting a pool socket does not limit a complete OPcache reset to that pool. Orbit already creates dedicated production Unix users, which provide an identity for independent runtime services. The operating agent needs to tune those services on the Node without Orbit overwriting its edits.

## Decision

- Orbit must run a separate PHP-FPM master and service for each production Unix user on a Node.
- Orbit must bind each production AppInstance to its dedicated user's service, pool, socket, and OPcache instance.
- Orbit must keep installed PHP packages shared by version.
- Orbit must refresh production cached PHP code through the owning service's OPcache without requiring a service reload.
- Orbit must verify cache refresh completion before reporting success.
- Orbit must not reset another production user's OPcache or reload another production user's service as a side effect of an AppInstance runtime operation.
- Orbit owns production runtime provisioning and the service, user, pool, and socket associations.
- The operating agent owns local PHP and PHP-FPM tuning on the Node.
- Orbit must seed each new production runtime from its defaults and preserve operator tuning during subsequent operations.
- Orbit must keep operator tuning separate from generated configuration that establishes runtime identity.
- Orbit must validate the effective configuration and required runtime associations before its own service activation or reload.
- Orbit must not store PHP or PHP-FPM tuning values on the App or AppInstance in the Gateway.
- Orbit must preserve application content and operator tuning when converting an existing production placement to this runtime model.

## Rejected alternatives

- Share one production master per PHP version: rejected because a complete OPcache reset affects every pool under that master.
- Reset only a pool by selecting its socket: rejected because the socket selects an execution pool, not a separate cache.
- Store tuning in the Gateway: rejected because it adds configuration storage and synchronization while the operating agent can maintain local files.
- Edit generated runtime configuration for local tuning: rejected because Orbit operations replace generated values.

## Consequences

- A production instance can refresh its cached PHP code without resetting another application's cache or reloading PHP-FPM.
- Each production master has its own cache allocation and service overhead, so Node capacity must account for the number of production instances.
- Separate masters remove shared OPcache state, but workloads still share the Node and its installed PHP packages.
- Custom tuning is not portable Gateway state. A new production instance starts from Orbit defaults, and the operating agent reapplies any required tuning.
- Existing production pool publication, runtime verification, and removal must support the dedicated services before the new contract can replace the shared production runtime.
- Cloning, deployment steps, release activation, and application health checks retain separate contracts.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for production runtime ownership; supersedes [ADR 0021](0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) for production service scope, tuning ownership, and deployment cache refresh
- Detail: [PHP runtimes](../reference/php-runtime.md)
- Verify: `composer docs-lint`; implementation conformance through Gateway runtime tests and issue-specific Incus proof
