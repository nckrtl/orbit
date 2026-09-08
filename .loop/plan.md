# Feature plan

Issue: ORB-182
Review verdict: PENDING

## Outcome

Operators can remove one development worktree without deleting its common repository or siblings. Forced checkout removal records every registered linked-worktree AppInstance in one immutable worktree-first set, rejects unregistered or changed sources, and resumes only unfinished members from durable evidence.

## Code boundaries

In:
- Extend the existing development source finalizer with an ephemeral linked-worktree inventory expectation.
- Discover and validate a complete registered source set under the Node source lock.
- Coordinate each accepted member through the existing five durable checkpoints.
- Preserve immutable member identity, original inventory, digest, journal, receipt, Route order, and bounded progress.
- Add focused Gateway API, coordinator, and real Git adapter regressions.

Out:
- New source-finalization mechanisms.
- Production removal, shared production Routes, or production projections.
- Public transport renaming or unrelated Route behavior.
- Source adoption, harness implementation changes, or branch deletion.

## Documentation

Audit scope: `docs/reference/appinstance-removal.md`, `docs/domains/applications.md`, `docs/reference/routes.md`, `docs/architecture.md`, and `docs/README.md`, selected from the issue component and Node, App, AppInstance, and Route context.

Fixed:
- `docs/reference/appinstance-removal.md`: replace the single-checkout-only boundary with worktree admission, forced fixed-set discovery, worktree-first ordering, immutable progress, branch preservation, and retry refusal rules.
- `docs/domains/applications.md`: route readers to the full worktree and cascade removal contract.
- `docs/reference/routes.md`: describe development removal as a per-member coordinated Route exception.
- `docs/architecture.md`: describe the removal owner as development source deletion and fixed-set retry.
- `docs/generated/context.json`: rebuild after the maintained pages change.

Reported:
- None.

Verification: `composer docs-build` and `composer docs-lint`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Normal and forced worktree removal | Source inspection and existing finalizer | Gateway API, real Git adapter, `worktree-removal-boundaries` |
| Checkout cascade admission | Fixed-set discovery under Node source lock | Gateway API/coordinator, `worktree-removal-boundaries` |
| Complete immutable preflight | Coordinator acceptance transaction and source expectation | Coordinator and real Git adapter |
| Worktree-first completion | Member ordering and five-step advancement | Coordinator, real Git adapter, `forced-cascade-removal` |
| Fixed-set retry refusal | Authenticated states and required/permitted live inventory | API/coordinator/adapter, `forced-cascade-retry` |
| Final completion and bounded output | Existing operation/member persistence and API/SDK/CLI DTOs | Gateway API plus existing SDK/CLI tests |
| Maintained docs | Removal reference and routing pages | `composer docs-lint` |
| Repository quality | Changed projects and root suites | project `composer check`, `PHPRC=/dev/null bin/test` |

## Implementation order

1. Document worktree and fixed-set cascade behavior.
2. Add the immutable revalidation expectation to the existing finalizer boundary.
3. Teach the real Git adapter to validate original evidence separately from the permitted current linked inventory.
4. Add fixed-set discovery, ordered members, and retry expectation derivation to the coordinator without enabling production removal.
5. Add focused coordinator, adapter, and API regressions.
6. Run focused product and documentation checks, then commit the product/docs checkpoint.
7. Reuse the approved ORB-181 proof helper as a starting point, replace it with the three ORB-182 actions, validate all actions on discovery, and prove the exact pushed candidate.

## Must preserve

- ORB-181 single-member behavior and exact default linked-inventory validation.
- Original member fields, original source digest, journal identity, and receipt identity.
- Immediate raw Git inventory race validation before destructive source mutation.
- Route-before-source ordering for every member and atomic final row/operation completion.
- Common repository, local worktree branches, and remote branches until checkout finalization; branch deletion never occurs.
- Production removal remains refused.

## Open questions

- None.

## Proof decision

- Use the standard three-Node topology.
- Use exactly `worktree-removal-boundaries`, `forced-cascade-removal`, and `forced-cascade-retry`.
- Set `mutates: true` and `observed_inputs: true`; the actions exercise Gateway, CLI, SDK, and real Node source/projection paths and support complete PHP observations.

## Deviations

- None.

## Review findings

- Pending independent review.
