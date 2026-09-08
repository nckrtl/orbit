# Feature plan

Issue: ORB-153
Review verdict: PENDING

## Outcome

Promotion cleanup releases only the exact issue attempts captured while the
promotion issue lock is held. A later replacement attempt survives and causes
an explicit cleanup conflict before Incus, Git, or lease mutation.

## Code boundaries

In:
- `apps/e2e/app/E2E/TopologySnapshotPromoter.php`
- `apps/e2e/app/E2E/TopologyReleaser.php`
- focused promoter and releaser unit tests
- `docs/reference/topology-snapshot.md`
- `.loop/proof/ORB-153.json` and its executable fixture

Out:
- CLI current-purpose release semantics
- topology lifecycle redesign or unrelated harness behavior
- production or shared topology mutation during issue proof

## Documentation

Audit scope: `docs/reference/topology-snapshot.md`,
`docs/reference/incus-topologies.md`, and `docs/reference/proof-plans.md` from
`composer docs-context -- --component=apps/e2e`.

Fixed:
- `docs/reference/topology-snapshot.md`: promotion cleanup was described as
  releasing the issue's current topologies after installation; it now binds
  cleanup to captured attempt identities, states the refusal boundary, and
  gives the installed-snapshot recovery context.

Reported:
- none

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Replacement conflicts before cleanup mutation | `TopologyReleaser::releaseExact` and promoter capture | focused releaser and promoter tests |
| Later discovery remains untouched | promoter captured cleanup set | focused promoter and releaser tests |
| Order, deduplication, absent identity, and partial failure outcomes | promoter cleanup orchestration and exact releaser | focused promoter and releaser tests |
| Isolated operating-system boundary | real lock/state/Git ref with an Incus sentinel | `promotion-exact-attempt-cleanup` |
| Maintained reference is current | topology snapshot reference | `composer docs-build`; `composer docs-lint` |
| E2E project checks pass | `apps/e2e` | `PHPRC=/dev/null composer check` |
| Repository suites pass | monorepo | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Document the captured-identity cleanup and recovery contract.
2. Add an exact ordered cleanup interface that validates every captured
   identity under the issue lock before mutation.
3. Capture the ordered, deduplicated promotion cleanup set under the existing
   issue lock and wrap later cleanup failures with installed-generation
   context.
4. Add focused regression tests for replaced, later-created, unchanged,
   absent, and partial-failure paths.
5. Validate the executable proof fixture on discovery, integrate current main,
   run all checks, and prove the exact commit.

## Must preserve

- `release ISSUE [--proof|--candidate]` continues to select the current attempt
  for that purpose.
- Exact resource ownership, reverse VM cleanup, partial retry, proof Git pin
  removal, lease deletion, and orphan network sweeping remain unchanged.
- Missing lease state never proves that old resources are absent.
- A successful proof topology and `.loop/` stay retained through first review.

## Open questions

- none

## Proof decision

`observed_inputs` is false. The acceptance action runs the host-side E2E PHP
boundary directly on the isolated gateway with real temporary filesystem,
OperationLock, AtomicJsonStore, and Git-ref state. A PATH-prepended Incus
sentinel fails and records any unexpected command. PHP file observations would
not add useful completeness for this non-product, deliberately pre-Incus
refusal path.

## Deviations

- none

## Review findings

- none
