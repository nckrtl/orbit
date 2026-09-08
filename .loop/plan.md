# Feature plan

Issue: ORB-179
Review verdict: direct implementation authorized by the worker contract

## Outcome

Persist immutable AppInstance removal operations, ordered member inventories, and ordered completion checkpoints. Permit an AppInstance to enter `removing` and lose its Route only when a complete recorded removal owns that member. Keep every public removal path and production multi-target behavior unchanged.

## Code boundaries

In:
- Add the `removing` AppInstance lifecycle value and bounded removal status and step enums.
- Add removal operation and member models with ordered relationships and casts.
- Add an additive Gateway migration for removal storage, inventory immutability, checkpoint ordering, recorded lifecycle admission, Route-cardinality preservation, and fail-closed rollback.
- Add focused database regression coverage and run the existing API contract suites unchanged.

Out:
- Coordinated removal actions, source finalization, Route projection, public responses, multi-target production admission, source adoption, and harness changes.

## Documentation

Audit scope: ORB-179 with `apps/gateway`, `AppInstance`, and `Route` context.

Fixed:
- None.

Reported:
- None. The maintained pages state the currently active public lifecycle and Route refusals. This issue adds unactivated internal persistence and therefore requires no maintained documentation change.

Verification: `composer docs-build` and `composer docs-lint` passed.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Populated upgrade preserves AppInstances, Routes, source identities, and lifecycle states | additive migration | `AppInstanceRemovalMigrationTest.php` populated upgrade case |
| Removal identity, force, ordered immutable inventory, bounded status, checkpoints, uniqueness, and ordering | migration triggers and removal models | `AppInstanceRemovalMigrationTest.php` persistence and invalid-transition cases |
| Active Route cardinality and recorded removing exception; no unrecorded lifecycle or production relaxation | AppInstance and Route triggers | `AppInstanceRouteConstraintTest.php` and `RouteMigrationTest.php` |
| Clean rollback and refusal with live, retained, or incompatible state | migration `down()` preflight | `AppInstanceRemovalMigrationTest.php` rollback cases |
| Existing public behavior remains unchanged | no action, request, controller, response, or projector edits | existing `AppInstancesTest.php` and `RoutesTest.php` |
| Gateway and repository gates pass | complete candidate | `composer check` and root `bin/test` |

## Implementation order

1. Add failing migration and model tests for upgrade preservation, persistence, constraints, Route behavior, and rollback.
2. Add enums, models, relationships, and the additive migration.
3. Run focused database and API tests, then documentation, Gateway, and repository checks.
4. Integrate current `origin/main`, commit the tracked `.loop` workspace with the issue changes, push, and verify the remote head.

## Must preserve

- Active AppInstances have exactly one Route.
- Existing pending multi-target storage does not become an active production Route.
- Existing API requests, response envelopes, source deletion eligibility, and Route refusals do not change.
- Existing AppInstance and Route rows survive upgrade byte-for-byte apart from the additive schema definition.
- Removal evidence is retained and blocks rollback.

## Open questions

- None.

## Proof decision

Automated tests only. ORB-179 has no `proof:incus` label and changes only SQLite persistence and Eloquent relationships.

## Deviations

- None.

## Review findings

- Resolved review `5142170216`: made initial-step and source-preparation admission comparisons NULL-safe and added a raw null-step regression.
