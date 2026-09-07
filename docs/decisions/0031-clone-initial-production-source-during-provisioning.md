# ADR 0031: Clone initial production source during provisioning

In the context of production AppInstances created before their application files exist, facing a second provisioning call to align Laravel configuration after deployment, we decided for Orbit to clone the initial production source and against completing creation with an empty placement, to complete source, URL, and Route preparation in one flow, accepting repository access during production provisioning.

## Status

Accepted on 2026-09-05. Extends [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md) and [ADR 0032](0032-preserve-explicit-appinstance-branch-selection.md). Supersedes [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for initial production source preparation and recording initial branch and commit evidence.

## Context

Creating an empty production placement leaves Orbit unable to detect Laravel or align its URL until a deployment supplies the files. Repeating creation after that deployment splits one provisioning flow across two operations. Orbit can prepare the initial repository while the operator or deployment agent retains ownership of subsequent deployments under ADR 0011.

## Decision

- Orbit must clone the App repository into a new production AppInstance's placement before Laravel URL reconciliation and Route publication.
- Orbit must use the App's default branch for initial production source unless the creation request explicitly selects another branch.
- Orbit must record the selected initial branch and exact starting commit as provisioning evidence.
- Orbit must preserve unrelated existing content when it prepares or resumes initial production source.
- Orbit must limit production Git mutations to initial source preparation and recovery of incomplete initial preparation.
- Orbit must not reset or redeploy production source when an identical creation request addresses an already provisioned AppInstance.
- The operator or deployment agent owns production source changes after initial provisioning completes.
- Orbit must retain production application content when it removes the AppInstance's placement and runtime records.

## Rejected alternatives

- Complete creation with an empty production placement: rejected because the source needed for Laravel detection and URL alignment is absent.
- Require a second creation call after deployment: rejected because it turns completion of one provisioning flow into a separate operator action.
- Extend initial cloning into ongoing deployment ownership: rejected because operators and deployment agents already own production releases and their source changes.

## Consequences

- Production provisioning can align Laravel configuration and expose its Route in one creation operation.
- A missing repository, inaccessible branch, or unsafe destination can prevent initial production provisioning.
- Initial branch and commit evidence describes how Orbit prepared the source; it does not assert the contents of a subsequent production deployment.
- Production user, home, relative-root, runtime, routing, and deployment boundaries from ADR 0011 remain in force outside this amendment.
- Conversion of an existing production deployment preserves that deployment rather than cloning over it.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/e2e
- ADRs: extends [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md) and [ADR 0032](0032-preserve-explicit-appinstance-branch-selection.md); supersedes [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for initial production source preparation and recording initial branch and commit evidence
- Detail: [Applications](../reference/apps.md)
- Verify: `composer docs-lint`; implementation conformance through `bin/test`
