# ADR 0047: Create production AppInstances from candidates

In the context of preparing a production AppInstance from a known application placement, facing configuration and data that a repository alone cannot supply, we decided for cloning a candidate AppInstance and against direct production creation from a repository or a complete filesystem copy, to reproduce selected application state without interrupting the source, accepting a required candidate and a separate first deployment.

## Status

Accepted on 2026-09-10. Supersedes [ADR 0031](0031-clone-initial-production-source-during-provisioning.md) for direct production creation and initial source selection. Extends [ADR 0032](0032-preserve-explicit-appinstance-branch-selection.md) for candidate branch inheritance and [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md) for configuration duplication. Retains [ADR 0028](0028-require-one-route-per-active-appinstance.md), [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md), and [ADR 0045](0045-isolate-production-php-fpm-by-unix-user.md) for Route, lifecycle, and runtime ownership.

## Context

A repository describes application code but does not supply an existing instance's stored environment configuration or database contents. A candidate can be a development or production AppInstance, and it can keep serving requests while Orbit prepares another placement. Copying its complete filesystem would also copy generated dependencies, logs, caches, and machine-specific runtime configuration. An application can use SQLite or operate without a database.

## Decision

- Orbit must require an existing candidate AppInstance when creating a production AppInstance.
- Orbit must create the target as another AppInstance of the candidate's App on the selected production Node.
- Orbit must permit an eligible development or production AppInstance to be the candidate.
- Orbit must refuse cloning when the candidate's Git working tree has uncommitted changes.
- Orbit must refuse cloning when the candidate's committed source is unavailable from the App repository.
- Orbit must inherit the candidate's configured branch unless the caller explicitly selects a target branch under ADR 0032.
- Orbit must prepare target source from the App repository rather than copying the candidate's working directory.
- Orbit must duplicate the candidate's stored environment configuration into independent target configuration under ADR 0044.
- Orbit must synchronize that configuration against the target's placement and Route before completing cloning.
- Orbit may seed one target SQLite database when the caller explicitly supplies the source database path.
- Orbit must obtain the SQLite seed through a consistent live snapshot.
- Orbit must support cloning without copying or creating a database.
- Orbit must leave source code, configuration, data, queues, processes, and schedules unchanged during cloning.
- Orbit must keep target managed application processes and schedules stopped until the operating agent explicitly starts them.
- Orbit must exclude development-only process definitions from production preparation.
- The operating agent owns target application-state cleanup, including removal of copied queue entries before starting target workers.
- Orbit must exclude the candidate's generated logs, caches, installed dependencies, and local PHP-FPM tuning from source transfer.
- Orbit must establish the target's sole Route with a preview hostname derived from the production Node's TLD.
- Orbit must refuse cloning when it cannot resolve an available preview hostname before target preparation begins.
- Orbit must require an explicit operator request to replace the preview hostname with the intended production hostname.
- Orbit must keep cloning separate from application deployment and deployment-step execution.
- Orbit must retain completed target configuration and data when an identical clone request is repeated.
- The operating agent owns external storage configuration and application data preparation outside the optional SQLite snapshot.

## Rejected alternatives

- Create production directly from a repository: rejected because it bypasses the candidate configuration and data that the operator intends to reproduce.
- Require a development candidate: rejected because an existing production or other eligible instance can supply the same source facts and configuration.
- Archive and extract the complete candidate directory: rejected because it carries generated files and machine-specific state into the target.
- Stop source workers and clear source queues: rejected because target preparation would interrupt and alter the source workload.
- Discover or provision database services during cloning: rejected because it adds database ownership and external service lifecycle to instance preparation.
- Deploy automatically after cloning: rejected because the agent must first configure and inspect the target's deployment procedure.

## Consequences

- Every new production AppInstance has a candidate; existing production instances remain managed without retroactively acquiring one.
- New production preparation has separate clone, deployment, and hostname-publication requests.
- Literal environment values, including the application key, remain copied values; the agent must update settings that need different values before using the target.
- The SQLite snapshot represents one point during live source operation and can contain pending jobs; it does not establish application readiness or coordinate a snapshot with external storage.
- Database registration, MySQL and PostgreSQL copying, external worker placement, and S3 provisioning or copying remain outside this cloning contract.
- A production Node needs a configured TLD for preview creation, even though existing production placement can operate without one.
- Preview and final publication follow existing private routing and public Ingress rules; changing the hostname preserves the single-Route boundary.
- Reusable process and schedule definitions retain a separate contract; cloning transfers no running process state.
- Production creation APIs, transport, provisioning retries, and topology samples must adopt candidate creation before the direct creation path is removed.
- Existing rules for source safety and retained production content continue to govern failure recovery and AppInstance removal.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli, apps/e2e
- ADRs: supersedes [ADR 0031](0031-clone-initial-production-source-during-provisioning.md) for direct production creation and initial source selection; extends [ADR 0032](0032-preserve-explicit-appinstance-branch-selection.md) and [ADR 0044](0044-own-appinstance-environment-configuration-in-orbit.md) for branch and configuration inheritance; retains [ADR 0028](0028-require-one-route-per-active-appinstance.md), [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md), and [ADR 0045](0045-isolate-production-php-fpm-by-unix-user.md) for Route, lifecycle, and runtime ownership
- Detail: docs/reference/appinstance-cloning.md
- Verify: `composer docs-lint`; implementation conformance through Gateway, PHP SDK, and CLI cloning tests and issue-specific Incus proof
