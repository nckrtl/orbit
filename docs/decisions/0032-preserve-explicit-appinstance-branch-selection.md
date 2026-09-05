# ADR 0032: Preserve explicit AppInstance branch selection

In the context of AppInstances that need a source branch different from their App default, facing default-branch updates that would overwrite a deliberate instance choice, we decided for a persistent explicit branch selection and against inferring that choice from an instance name or replacing it during an App default change, to preserve source intent independently from instance identity, accepting branch-selection intent in AppInstance lifecycle records.

## Status

Accepted on 2026-09-05. Extends [ADR 0025](0025-stabilize-the-default-appinstance-identity.md). Supersedes ADR 0025 for unconditional reconciliation of a default AppInstance when the App default branch changes.

## Context

An operator can need a `default` AppInstance to use a release branch while its App retains another default branch. The instance name still determines its placement and generated hostname. Orbit must distinguish an explicit branch choice from inherited defaults so an App default change does not replace that choice.

## Decision

- An AppInstance creation request may select a source branch explicitly when the instance needs to override normal branch selection.
- Orbit must use an explicitly selected branch before any branch derived from the App default or AppInstance name.
- Orbit must record whether the creation request selected the branch explicitly.
- Orbit must retain explicit selection intent even when the selected branch equals the App default at creation.
- Orbit must refuse creation when the explicitly selected repository branch does not exist instead of falling back to another branch.
- Orbit must preserve an AppInstance's explicit branch selection when the App default branch changes.
- Orbit must keep AppInstance naming, placement identity, and Route identity independent from an explicit branch choice.
- Orbit must refuse an identical-instance creation request that changes its recorded explicit branch selection instead of using creation as a branch update.

## Rejected alternatives

- Use the instance name as the only branch override: rejected because selecting a release branch would also require a different instance identity.
- Reconcile every default instance to every App default change: rejected because it discards the explicit instance choice.
- Infer override intent by comparing branch values: rejected because an explicit selection can equal the App default when the instance is created.

## Consequences

- A `default` AppInstance can use a release branch without changing its name or generated hostname.
- A branch override selects a branch; it does not freeze its head commit.
- Development creation without an explicit selection retains the branch-selection rules from ADR 0025, including the matching remote branch for a non-default instance.
- App default changes continue to reconcile development default instances that have no explicit override under ADR 0025.
- Production source changes after provisioning remain owned by the operator or deployment agent.
- Persistence, API, SDK, CLI, creation retries, and App update reconciliation need to distinguish explicit selection from inherited selection.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0025](0025-stabilize-the-default-appinstance-identity.md); supersedes ADR 0025 for unconditional reconciliation of a default AppInstance when the App default branch changes
- Detail: [Applications](../reference/apps.md)
- Verify: `composer docs-lint`; implementation conformance through `bin/test`
