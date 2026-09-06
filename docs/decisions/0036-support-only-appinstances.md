# ADR 0036: Support only AppInstances

In the context of replacing legacy Instances and Workspaces with AppInstances, facing continued product and harness dependencies on the old structure, we decided for AppInstance-only support and operator-owned fleet transitions and against Orbit-provided migration tooling or legacy compatibility, to keep one supported application model, accepting incompatible upgrades for fleets that still use legacy state.

## Status

Accepted on 2026-09-06. Supersedes [ADR 0009](0009-clustered-app-instance-routing.md) for staged legacy conversion and legacy compatibility during the transition.

## Context

Orbit's supported application model is AppInstance with App-owned Routes. The existing conversion contracts require Orbit to migrate legacy workloads and retain their product surfaces until conversion completes. Fleet deployment and any transition from the old structure belong to the operator who manages that fleet.

## Decision

- Orbit must support AppInstance as its only runnable application model and Route as its hostname and traffic contract.
- Orbit must remove legacy Instance and Workspace models, schemas, APIs, SDK operations, CLI commands, runtime behavior, and compatibility fallbacks.
- Orbit must not provide or maintain scripts, commands, APIs, SDK operations, or other tooling to migrate legacy Instances or Workspaces.
- The fleet operator owns any migration, data handling, traffic cutover, recovery, and deployment preparation outside Orbit.
- Orbit must not make removal of legacy support depend on an Orbit migration facility or completion of operator fleet conversions.
- The Incus harness must construct, refresh, and verify its supported sample workloads through AppInstance and Route contracts only.
- Orbit's documentation must describe the supported AppInstance model and the upgrade incompatibility without defining a migration procedure supported by Orbit.

## Rejected alternatives

- Orbit-provided operator scripts: rejected because supplying and maintaining a script still makes migration an Orbit responsibility.
- Product migration APIs, SDK operations, or CLI commands: rejected because fleet transition is outside Orbit's product scope.
- Legacy compatibility until every workload converts: rejected because product removal would depend on deployment state controlled by fleet operators.

## Consequences

- Product behavior and routine topology verification use one application model.
- Fleet operators must prepare their deployments for the incompatible change and supply any conversion tools they need.
- Orbit provides no supported conversion path for legacy fleet data.
- The conversion issues become obsolete; legacy removal and topology work must drop migration-tooling and legacy-preservation requirements.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/e2e
- ADRs: supersedes [ADR 0009](0009-clustered-app-instance-routing.md) for staged legacy conversion and legacy compatibility during the transition
- Detail: [Apps](../reference/apps.md)
- Verify: `composer docs-lint`; implementation conformance through `bin/test`
