# ADR 0030: Complete AppInstance provisioning without application health gates

In the context of creating an AppInstance before its application dependencies and services are configured, facing active-state requirements that depend on application health, we decided for automatic completion after Orbit provisions the instance and Route and aligns Laravel URL configuration and against a separate activation step, to expose the endpoint for application setup, accepting that an active instance can serve application errors.

## Status

Accepted on 2026-09-05. Extends [ADR 0028](0028-require-one-route-per-active-appinstance.md). Supersedes [ADR 0009](0009-clustered-app-instance-routing.md) for application-health requirements before Route publication, and [ADR 0029](0029-manage-laravel-application-urls-through-orbit.md) for effective Laravel runtime verification before provisioning completes. Amends [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) only to permit explicitly configured App setup steps after provisioning.

## Context

A newly cloned Laravel application can lack Composer dependencies, an application key, or a database while Orbit can configure its document root and Route. The agent or operator needs that endpoint to inspect the application and finish its setup. Requiring Laravel to boot or return a successful HTTP response makes Orbit provisioning depend on application configuration that Orbit does not own. App setup steps express requested application work, but their result does not determine whether the instance and Route exist and are configured.

## Decision

- Orbit must complete AppInstance provisioning automatically once source or placement preparation, Route configuration, and detected Laravel URL alignment succeed.
- Orbit must expose the provisioned AppInstance and its Route as active without a separate operator activation operation.
- Orbit must align a detected Laravel application's canonical URL through configuration without requiring framework boot or installed application dependencies.
- Orbit must retain the source-safety, runtime, network, TLS, and firewall validation required by the governing placement and routing decisions.
- Orbit must not require an application health check or a successful application HTTP response to complete provisioning or publish its Route.
- Orbit must not make a provisioned AppInstance or Route inactive because application dependencies, an application key, or a database are missing, or because the application returns an error.
- Orbit must run App setup steps after provisioning when those steps are explicitly configured for the App.
- Orbit must not infer dependency installation or other application setup steps from framework detection.
- Orbit must report a configured setup-step failure while retaining the provisioned AppInstance and Route as active.
- The agent or operator owns inspection of the application response and any application setup not expressed in configured App setup steps.

## Rejected alternatives

- Require a healthy application before completing provisioning: rejected because missing dependencies or a database would prevent access to the endpoint used to finish application setup.
- Require manual activation after application setup: rejected because it adds an operator action after Orbit has already configured the instance and Route.
- Infer setup commands from framework detection: rejected because detection establishes the Laravel URL convention but does not establish the application's installation or deployment procedure.

## Consequences

- Active state describes Orbit provisioning and routing configuration; it does not promise application health, and Orbit does not need to track application health for this lifecycle.
- Provisioning fails when Orbit cannot prepare source or placement, configure routing, or align the Laravel URL; application errors do not cause it to fail.
- Laravel URL reconciliation must work before dependencies are installed and preserve unrelated settings, including when configuration is absent or cached.
- Configured setup steps can fail after the instance and Route become active, so operation output must distinguish that failure from provisioning failure.
- App setup-step configuration and execution need a separate implementation contract; this record defines only their ordering and failure boundary.
- ADR 0011 still governs production source and deployment ownership, except for the configured setup steps permitted here; ADR 0029 still governs URL ownership.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/e2e
- ADRs: extends [ADR 0028](0028-require-one-route-per-active-appinstance.md); supersedes [ADR 0009](0009-clustered-app-instance-routing.md) for application-health requirements before Route publication, and [ADR 0029](0029-manage-laravel-application-urls-through-orbit.md) for effective Laravel runtime verification before provisioning completes; amends [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) only to permit explicitly configured App setup steps after provisioning
- Detail: [Applications](../reference/apps.md) and [Routes](../reference/routes.md)
- Verify: `composer docs-lint`; implementation conformance through `bin/test`
