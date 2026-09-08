# Feature plan

Issue: ORB-181
Review verdict: no separate plan review required by the prepared no-plan implementation path

## Outcome

Remove one independent development checkout through coordinated Route cleanup, bounded five-step progress, and identical retry. Preserve pre-mutation refusals for linked-worktree cascades, worktree AppInstances, and every production AppInstance.

## Code boundaries

In:
- Gateway admission, lifecycle state machine, final-target development Route cleanup, source-finalizer composition, runtime cleanup, atomic final completion, progress projection, and retry guards.
- PHP SDK response decoding, CLI human and JSON rendering, activity details, and scoped maintained documentation.
- Three Incus proof actions on the standard topology.

Out:
- Worktree or linked-worktree cascade activation, production removal, shared production Routes, source-finalizer changes, unrelated Route mutation, and harness changes.

## Documentation

Audit scope: `docs/architecture.md`, `docs/domains/applications.md`, `docs/reference/routes.md`, `docs/README.md`, and the required `docs/reference/appinstance-removal.md` page selected from the issue contract and filtered documentation context.

Fixed:
- `docs/reference/appinstance-removal.md`: add the single-checkout normal/forced contract, ordering, transient unavailable response, bounded progress, refusals, retained branches, and retry.
- `docs/domains/applications.md`: replace the temporary coordinated-removal refusal with the shipped command and link to the owning reference.
- `docs/reference/routes.md`: document the accepted AppInstance-removal exception and final-target development Route deletion.
- `docs/architecture.md` and `docs/README.md`: route readers to the owning removal reference.
- `docs/generated/context.json`: rebuild after the new page and links.

Reported:
- None.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Safe normal/force admission under one Node lock | Gateway coordinator and source inspector | Gateway API tests; `single-development-removal` |
| One immutable member and explicit worktree/cascade/production refusals | Gateway coordinator and removal models | Gateway API and coordinator tests |
| Route-before-source order and atomic completion | Coordinator and Route projector | Coordinator tests; `single-development-removal` |
| Exact transient 503 without former-target contact | Development Caddy/DNS projection and Route projector | `development-removal-traffic-cutoff` |
| Checkpointed identical retry, including late DNS and final DB failure | Coordinator, projector, and source finalizer | API/projector tests; `development-removal-retry` |
| Bounded progress on API/activity/SDK/CLI | Data object, middleware, SDK DTO, CLI renderer | Gateway, SDK, and CLI tests |
| Maintained documentation and generated context | Scoped pages above | `composer docs-build`; `composer docs-lint` |
| Component and repository quality gates | Gateway, SDK, CLI, root | Component `composer check`; `PHPRC=/dev/null bin/test` |

## Implementation order

1. Audit and write scoped documentation.
2. Port the preserved single-member coordinator and Route projector onto the ORB-180 recorded-source finalizer.
3. Add progress transport and presentation across Gateway, SDK, CLI, and activity.
4. Add focused regression coverage and the three exact proof fixtures.
5. Exercise all three scenarios on discovery, freeze the candidate, run checks, and prove the exact commit on a fresh topology.

## Must preserve

- ORB-180 exact single-checkout inventory equality and per-Node reentrant lock.
- Specific pre-mutation refusals for worktrees, linked-worktree cascades, and production.
- No source finalization before final-target Route deletion and hostname release.
- Late-DNS retry skips unavailable TLS republication after certificate deletion, while first-publication failure retries the unavailable projection before any later cleanup.
- Final AppInstance deletion, member checkpoint, and operation completion commit atomically.
- Safe-token-or-null error decoding through the SDK shared validator.
- Local and remote branches remain available.

## Open questions

- None. ORB-182 owns the cascade inventory expectation seam described in `orb182-integration-advisory.md`.

## Deviations

- None.

## Review findings

- None yet.
