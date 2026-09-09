# ADR 0026: Identify each App by one repository

In the context of App records that store transport-specific Git URLs, facing automatic App resolution from an existing checkout, we decided for one canonical repository identity per App and against exact-URL matching or duplicate repository ownership, to make source registration deterministic, accepting explicit migration failures for existing conflicts.

## Status

Accepted on 2026-09-05. Extends [ADR 0009](0009-clustered-app-instance-routing.md) and [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md).

## Context

The current App slug is unique, but its repository URL is not. Supported SSH and HTTPS URLs can identify the same repository while differing as strings. Registration cannot infer one App safely when several Apps can claim the same repository under equal or equivalent URLs.

## Decision

- An App owns one canonical repository identity and one supported repository access URL.
- Orbit must treat supported SSH and HTTPS forms of the same repository host and path as one repository identity.
- Orbit must not create an App when another App owns the same canonical repository identity.
- Orbit must not update an App to a canonical repository identity owned by another App.
- Orbit must resolve an existing checkout to at most one App by canonical repository identity.
- Orbit must refuse migration when existing Apps have duplicate canonical repository identities.
- Orbit must not merge, delete, or choose between conflicting Apps during repository-identity migration.

## Rejected alternatives

- Compare repository URLs as exact strings: rejected because a transport change permits a second App for the same repository.
- Allow several Apps to share one repository and select the first match: rejected because registration and subsequent repository reconciliation would depend on database ordering.
- Merge duplicate Apps automatically: rejected because their AppInstances, Routes, roots, and operator intent can differ.

## Consequences

- Registration can resolve an App from a checkout origin without an ambiguous first match.
- Repository URL changes retain transport flexibility while preserving one App owner.
- Existing equivalent repository URLs need preflight before a uniqueness constraint can become authoritative.
- An existing duplicate blocks migration until an operator resolves it.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0009](0009-clustered-app-instance-routing.md) and [ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md)
- Detail: docs/reference/apps.md
- Verify: `bin/test`
