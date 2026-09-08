# Feature plan

Issue: ORB-183
Review verdict: PENDING

## Outcome

Remove one production AppInstance from an existing explicit Route. Preserve and republish ordered survivors, delete a final-target Route after complete projection cleanup, release its hostname, retain production content, and resume identical requests from durable progress.

## Code boundaries

In:
- Forward production target-set constraints and rollback refusal.
- One production removal member through the existing five-step coordinator.
- Shared and final Route projection cleanup, retained content evidence, and retry.
- Maintained removal and Route documentation.

Out:
- Development source deletion and worktree cascades.
- Public production pool creation or balancing-policy changes.
- Public Ingress, source adoption, content or branch deletion, and unrelated Route mutation.
- Harness implementation.

## Documentation

Audit scope: `docs/reference/appinstance-removal.md`, `docs/reference/routes.md`, `docs/domains/applications.md`, `docs/architecture.md`, and generated context.

Fixed:
- `docs/reference/appinstance-removal.md`: production removal was an explicit refusal; it now owns shared retention, final deletion, retained content, and retry.
- `docs/reference/routes.md`: removal was development-only; it now describes ordered production survivor publication and final production cleanup.
- `docs/domains/applications.md`: the removal guide named only development; it now routes production operators to the owning reference.
- `docs/architecture.md`: removal ownership omitted production content and Route cleanup; it now links those boundaries.

Reported:
- None.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Valid production target-set constraints | New forward Gateway migration | `AppInstanceRouteConstraintTest.php`, `RouteMigrationTest.php` |
| Shared production removal | Removal coordinator, complete-set Route projection | `NativeAppInstanceRemovalProjectorTest.php`, `shared-production-target-removal` |
| Final production removal | Final Route cleanup, retained production source boundary | `final-production-target-removal` |
| Idempotent retry | Coordinator checkpoints and Route projector | Coordinator/projector tests, `production-removal-retry` |
| Upgrade and rollback | Forward migration preflight and refusal | `RouteMigrationTest.php` |
| Maintained documentation | Four owning pages and generated context | `composer docs-build`, `composer docs-lint` |
| Repository verification | Gateway and root suites | `composer check`, `PHPRC=/dev/null bin/test` |

## Implementation order

1. Correct maintained documentation and record the audit.
2. Add the forward target-set migration and focused database regressions.
3. Add production retained-content admission/finalization through the existing coordinator.
4. Publish complete ordered Route target sets and implement shared/final production cleanup with retry tests.
5. Diagnose all three proof actions on discovery, then integrate current main and prove the exact pushed candidate.

## Must preserve

- Existing development singleton removal behavior and its exact unavailable response.
- One Route per active AppInstance and generated Route singleton targeting.
- Production content bytes, ownership, dedicated user, and all four registered topology Nodes.
- ORB-182 ownership of development source inventory and cascades.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
