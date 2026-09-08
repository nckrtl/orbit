# Feature plan

Issue: ORB-178
Review verdict: implementation authorized by the issue worker contract

## Outcome

Make the existing checkout removal path collect reusable read-only source evidence, refuse every unsafe or linked checkout before mutation, and delete only when the same physical checkout and canonical repository identity still match immediately before the destructive command.

## Code boundaries

In:

- Split removal inspection and deletion from the creation-oriented `DevelopmentAppInstanceSourceLifecycle` contract.
- Add a stable source inventory with canonical repository identity, recorded placement, branch and commit, physical identity, and linked-worktree paths.
- Keep the existing `RemoveAppInstanceAction` lifecycle, migration, active-Route, layout, overlap, row-deletion, and response boundaries while replacing its source call with inspect-then-delete under the existing Node source lock.
- Use current remote branch and tag advertisements through a disposable repository outside the requested checkout for normal publication evidence.
- Recheck physical identity, canonical origin, ownership, canonical path, containment, branch, commit ancestry, and linked-worktree inventory immediately before checkout deletion.
- Cover the behavior in the existing Gateway infrastructure and API feature tests and in the two bounded Incus actions.

Out:

- Active-Route mutation, worktree deletion, cascade activation, durable removal operations, progress responses, and public force spelling.
- Source adoption, harness implementation, and local or remote branch deletion.
- Any change outside `apps/gateway`, `docs/`, and the issue's `.loop/` workspace.

## Documentation

Audit scope: ORB-178; `docs/domains/applications.md`, `docs/reference/apps.md`, `docs/concepts.md`, and `docs/architecture.md` from the filtered Gateway and AppInstance context.

Fixed:

- `docs/domains/applications.md`: replace the mutating exact-origin removal description with canonical origin identity, disposable current-remote publication evidence, linked-worktree refusal, and immediate physical and origin revalidation.

Reported:

- none.

Verification: `composer docs-build` and `composer docs-lint`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Normal clean and published acceptance; dirty or unpublished refusal leaves source and records unchanged. | Removal-only source inspector and current-remote publication query; existing action mutation order. | Gateway remote lifecycle test and `checkout-publication-boundary`. |
| Forced removal skips dirty and publication checks and remote publication I/O while retaining structural safety. | Force branch in the removal-only adapter; shared destructive revalidation. | Gateway remote lifecycle test and `checkout-removal-refusals`. |
| Every linked worktree refuses checkout deletion and preserves all refs and paths. | Reusable linked-worktree inventory plus the live action's intermediate-stage refusal. | Gateway remote lifecycle test and `checkout-removal-refusals`. |
| Replacement or canonical-origin change between inspection and deletion refuses normal and forced removal; equivalent SSH/HTTPS origins remain valid. | Physical source identity and canonical repository identity captured in inventory and checked in the destructive command. | Gateway remote lifecycle test and `checkout-removal-refusals`. |
| Migration, active-Route, and unsupported-layout refusals remain; inspection does not mutate source. | Existing action guards before source inspection; requested-repository optional-lock-free reads and disposable remote graph. | Gateway API test. |
| Maintained documentation states only the strengthened intermediate checkout boundary. | `docs/domains/applications.md` and generated context. | `composer docs-build` and `composer docs-lint`. |
| Gateway and repository suites pass. | Gateway project and root test runners. | `cd apps/gateway && composer check`; `bin/test`. |

## Implementation order

1. Audit and update maintained removal documentation.
2. Add the removal-only contract and inventory, then split removal from the creation lifecycle.
3. Add focused real-Git regression tests and update the API fake boundary.
4. Exercise both proof scenarios on discovery, commit and integrate current `origin/main`, then run all checks and exact-commit proof.

## Must preserve

- Existing public request and response shape, including the currently landed removal option spelling.
- Migration-required, active-Route, unsupported-layout, unsafe-path, and overlap refusals before remote inspection.
- Immutable recorded checkout placement and historical root validation from ADR 0008.
- One canonical App repository identity and supported SSH/HTTPS equivalence from ADR 0026.
- Checkout ownership and the force limits from ADR 0027.
- Requested checkout, index, refs, linked worktrees, branches, AppInstance, and Route state on every refusal.

## Open questions

- none.

## Deviations

- none.

## Review findings

-
