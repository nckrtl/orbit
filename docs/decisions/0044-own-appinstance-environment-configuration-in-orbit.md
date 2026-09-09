# ADR 0044: Own AppInstance environment configuration in Orbit

In the context of AppInstances whose environment configuration exists only on workload Nodes, facing configuration that cannot be regenerated for another placement, we decided for encrypted Gateway-owned values and instance-bound references and against treating local environment files as configuration authority, to make configuration reproducible, accepting explicit import and synchronization operations.

## Status

Accepted on 2026-09-09. Extends [ADR 0029](0029-manage-laravel-application-urls-through-orbit.md) from Laravel URL ownership to complete AppInstance environment configuration. Supersedes its preservation requirement for local environment-file values that differ from the stored configuration. The Route remains the authority for the Laravel canonical URL, and [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md) retains provisioning without application bootstrap.

## Context

An environment file combines application settings, credentials, and values that depend on one AppInstance's placement and Route. Copying that file can retain the source instance's hostname or filesystem paths. Storing only selected non-secret overrides leaves the complete configuration dependent on files outside the Gateway. The same stored configuration must support file generation before application dependencies or services are available.

## Decision

- The Gateway owns the authoritative environment configuration of each AppInstance.
- The Gateway must store at most one value for each environment key within an AppInstance.
- The Gateway must encrypt every stored environment value, including literal values and reference expressions, using its own encryption keys, which are separate from application keys.
- The Gateway must omit environment values from normal command responses, activity records, and diagnostics.
- The Gateway must validate environment keys, values, and supported reference expressions before storing an import or update.
- The Gateway must import existing file values only through an explicit import operation.
- The Gateway must refuse import conflicts with stored keys unless the caller explicitly requests replacement.
- The Gateway must keep stored configuration changes separate from synchronization to a workload Node.
- The Gateway must resolve supported references against the destination AppInstance when synchronizing its configuration.
- The Gateway must refuse unresolved references before replacing the environment file.
- The Gateway must preserve stored literal values during synchronization, including application encryption keys.
- The Gateway must derive the environment-file location and execution identity from the AppInstance's recorded placement.
- The Gateway must validate remote access, execution identity, path boundaries, write permissions, and storage capacity before decrypting values for synchronization.
- The Gateway must install the complete generated environment file through atomic replacement after validation and rendering succeed.
- The Gateway must leave the existing environment file unchanged when preflight, rendering, or replacement fails.
- The Gateway must not import local file edits during synchronization.
- The Gateway must not run application commands, refresh framework caches, or restart application processes as part of environment synchronization.
- App setup and deployment own the framework-cache and process changes that make generated configuration effective in the application.

## Rejected alternatives

- Treat the workload environment file as authoritative: rejected because another placement cannot regenerate its configuration from Gateway state.
- Encrypt only values marked as secrets: rejected because a missed classification leaves credentials in plaintext storage.
- Merge local edits into configuration during synchronization: rejected because a write operation would replace stored intent with unrequested changes from the Node.
- Require framework execution during synchronization: rejected because generating configuration must work before application dependencies are installed.

## Consequences

- Configuration can be duplicated as stored values and resolved for another AppInstance without copying source-specific hostnames or paths.
- Existing environment files need explicit import before their values become part of the authoritative configuration.
- Synchronization replaces local-only edits; operators must import those edits or update the stored values to retain them.
- Reading values for synchronization requires decryption, and recovery of stored configuration depends on retaining the Gateway encryption key material.
- A synchronized file does not prove that running processes or framework caches use its values.
- Placement, cloning, release construction, database copying, and deployment execution retain separate contracts.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli
- ADRs: extends [ADR 0029](0029-manage-laravel-application-urls-through-orbit.md); supersedes its preservation requirement for local environment-file values that differ from stored configuration; retains [ADR 0030](0030-complete-appinstance-provisioning-without-application-health-gates.md) for provisioning without application bootstrap
- Detail: docs/reference/environment-variables.md
- Verify: `composer docs-lint`; implementation conformance through Gateway, PHP SDK, and CLI environment tests and issue-specific Incus proof
