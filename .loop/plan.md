# Feature plan

Issue: ORB-168
Review verdict: IMPLEMENTATION AUTHORIZED

## Outcome

Persist the selected PHP version and Laravel classification as one complete development source profile. Refuse all complete-profile drift at retained checkpoints, fail closed for legacy incomplete evidence, and allow an explicit bounded recovery that adopts the inspected complete profile and resumes normal provisioning.

## Code boundaries

In:
- Gateway AppInstance persistence, migration rollback guard, creation validation and provisioning retry state machine.
- PHP SDK AppInstance creation request transport.
- CLI `instance:new` recovery option transport.
- Focused Gateway, SDK, CLI, migration, documentation, and Incus proof evidence.

Out:
- Production AppInstance provisioning and historical profile inference or backfill.
- Source, AppInstance, placement, and Route identity changes.
- Active-state creation behavior, AppInstance removal behavior, and Incus harness implementation.

## Documentation

Audit scope: ORB-168; `docs/domains/applications.md`, `docs/reference/php-runtime.md`, and their higher-authority context.

Fixed:
- `docs/domains/applications.md`: provisioning retries lacked the complete-profile checkpoint, legacy refusal, explicit recovery, URL-reconciliation retry, rollback refusal, Active terminal behavior, and unchanged removal boundary.
- `docs/reference/php-runtime.md`: source-driven runtime selection lacked its atomic Laravel-classification evidence and exact retry comparison.

Reported:
- None.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Atomically record and compare the complete profile | Gateway migration, model, native provisioner | Gateway domain and API feature tests |
| 2. Refuse Laravel/plain/PHP/non-PHP drift at both checkpoints | Native provisioner | Gateway domain and API drift matrices |
| 3. Preserve unchanged retry and Active terminal behavior | Native provisioner | Gateway domain and API retry tests |
| 4. Preserve legacy rows, fail closed, and guard rollback | Gateway migration and provisioner | Migration and API feature tests |
| 5. Transport optional recovery intent and preserve omission | Gateway request/data, SDK request, CLI command | API, SDK unit, and CLI feature tests |
| 6. Revalidate identity and adopt only incomplete evidence | Create action and native provisioner | Gateway domain and API feature tests |
| 7. Reconcile recovered Laravel URL idempotently and preserve removal | Native provisioner and existing removal action | Gateway domain/API tests and Incus action |
| 8. Prove the filesystem and service boundary | Existing product paths | Incus `source-profile-checkpoint-drift` action |
| 9. Document behavior and refresh context | Maintained docs | `composer docs-build`; `composer docs-lint` |
| 10. Pass owning-project checks | Gateway, SDK, CLI | Each project `composer check` |
| 11. Pass repository suites | Monorepo | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Audit and update maintained AppInstance and PHP-runtime documentation.
2. Add nullable Laravel classification evidence with no forward backfill and a fail-closed rollback guard.
3. Thread optional recovery intent through Gateway, SDK, and CLI boundaries.
4. Make provisioning persist and compare the exact pair, and recover only legacy incomplete retained checkpoints.
5. Add focused migration, domain, API, SDK, and CLI regression evidence.
6. Run focused and project checks, then exercise the Incus scenario on discovery.
7. Coordinate current-main integration, root suite, and immutable proof with the root orchestrator.

## Must preserve

- Existing AppInstance retry identity, source ownership, stored Git branch and commit checks.
- Complete-profile drift refusal even when recovery is requested.
- Active creation terminal behavior and Active-only removal eligibility.
- AppInstance, source, placement, and Route identity.
- Existing idempotent Laravel URL reconciliation and normal durable retry semantics.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.

## Proof decisions

- Incus plan: `.loop/proof/ORB-168.json` with action `source-profile-checkpoint-drift`.
- Observed inputs: `true`. Setup runs the app-dev CLI and a Gateway CLI fixture, while acceptance runs the Gateway CLI fixture and the app-dev CLI through Gateway FPM. These actions exercise all required `app-dev:cli`, `gateway:cli`, and `gateway:fpm` surfaces, so PCOV can record complete PHP observations.
