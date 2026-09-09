# ADR 0046: Own production release deployment in Orbit

In the context of production AppInstances deployed by an operating agent, facing repeated release preparation and runtime coordination outside Orbit, we decided for Orbit-owned branch deployments with instance-owned application steps and against an external-only deployment lifecycle, to make release activation consistent, accepting that the agent retains application compatibility and recovery decisions.

## Status

Accepted on 2026-09-10. Supersedes [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for production release ownership and serving layout, [ADR 0031](0031-clone-initial-production-source-during-provisioning.md) and [ADR 0032](0032-preserve-explicit-appinstance-branch-selection.md) for ongoing production source ownership, and [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md) for production setup-step ownership and automatic execution after provisioning. Extends [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md) and [ADR 0045](0045-isolate-production-php-fpm-by-unix-user.md) for deployment coordination.

## Context

Production provisioning prepares initial source under ADR 0031, while the agent must arrange subsequent releases and runtime refreshes. An AppInstance already selects a branch, and the agent needs to deploy its latest contents without supplying a commit identifier. Application commands vary between projects, but release preparation and activation follow the same placement and runtime boundaries. Persistent configuration and an optional SQLite database must survive code replacement.

## Decision

- Orbit owns production release preparation, activation, and explicit code rollback within the AppInstance's recorded home.
- A production AppInstance owns its configured deployment branch and ordered application deployment steps.
- Orbit must require an explicit deployment request, including for the first deployment after provisioning or cloning.
- Orbit must fetch the latest contents of the configured branch into a new release for each deployment.
- Orbit must use that fetched checkout throughout the deployment even if the remote branch advances.
- Orbit must select deployment source by branch without requiring a caller-supplied commit identifier.
- Orbit must keep persistent environment configuration and the optional SQLite database outside replaceable releases.
- Orbit must resolve the production web root within the active release.
- Orbit must synchronize stored environment configuration before running the new release's application steps.
- The operating agent owns application command selection and ordering in the steps before and after release activation.
- Orbit must not insert application commands that the agent has not configured.
- Orbit must execute configured application steps as the AppInstance's Unix user with bounded execution time and streamed output.
- Orbit must activate the prepared release only after every step before activation succeeds.
- Orbit must switch the active release atomically before running steps after activation.
- Orbit must refresh a PHP AppInstance's runtime cache under ADR 0045 after the switch and before running steps after activation.
- Orbit must leave the active release unchanged when deployment fails before the switch.
- Orbit must retain the selected new release when cache refresh or a step after activation fails.
- Orbit must report a failed deployment boundary without automatically rolling back or retrying application commands.
- Orbit must limit an explicitly requested rollback to selecting retained code and refreshing its runtime cache.
- The operating agent owns database schema compatibility, application-state recovery, and any commands needed after a code rollback.
- Orbit must not replace production data from a source AppInstance during deployment or code rollback.
- Orbit must not store deployment runs, deployment history, or historical step snapshots.
- Orbit must preserve existing production content and local runtime tuning when adopting the release layout.

## Rejected alternatives

- Keep all production release work outside Orbit: rejected because each agent must reproduce placement, activation, and runtime coordination.
- Select every deployment through an exact commit input: rejected because the intended operation is to deploy the instance's configured branch.
- Infer application commands from the framework: rejected because framework identity does not establish the application's deployment procedure.
- Couple code rollback to database rollback: rejected because an older release can require application-specific data recovery.
- Persist deployment runs and historical step snapshots: rejected because retained releases, the active pointer, and operation output provide the requested code state without another lifecycle model.

## Consequences

- One production deployment flow serves both the first release and subsequent updates, while development keeps its existing source layout.
- Provisioning or cloning leaves application deployment to a separate explicit request; it does not execute production setup commands automatically.
- A failed step before activation can already have changed persistent data or configuration; leaving the code pointer unchanged does not undo those effects.
- A failed operation after the switch leaves new code selected for agent inspection and explicit recovery.
- Branch names remain stable inputs, but two deployments of the same branch can produce different code.
- Retained releases consume disk space, and rollback requires the selected release to remain available.
- Orbit has no historical deployment report or automatic resumption of application steps after an interrupted request.
- Health checks, maintenance mode, application process restarts, migrations, dependency installation, and asset builds are not inferred by Orbit.
- Source preparation, runtime projection, removal, and verification must recognize the production release layout before deployment can use it; production content retention continues under ADR 0031.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli, apps/e2e
- ADRs: supersedes [ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) for production release ownership and serving layout, [ADR 0031](0031-clone-initial-production-source-during-provisioning.md) and [ADR 0032](0032-preserve-explicit-appinstance-branch-selection.md) for ongoing production source ownership, and [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md) for production setup-step ownership and automatic execution; extends [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md) and [ADR 0045](0045-isolate-production-php-fpm-by-unix-user.md) for deployment coordination
- Detail: docs/reference/deployments.md
- Verify: `composer docs-lint`; implementation conformance through Gateway, PHP SDK, and CLI deployment tests and issue-specific Incus proof
