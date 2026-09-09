# Feature plan

Issue: ORB-167
Review verdict: IMPLEMENTATION AUTHORIZED

## Outcome

Persist each acquisition target in its initial lease and require exact, explicit recovery for ambiguous legacy lease-only records.

## Code boundaries

In:
- Attempt lease extension persistence and lease/topology validation.
- Exact ordinary release selection from current leases and compatible complete legacy records.
- Paired legacy recovery options for discovery, proof, and candidate-convergence attempts.
- Focused harness tests, maintained Incus topology reference, retained guest proof, and exact-candidate host rehearsal.

Out:
- `releaseExact`, captured-attempt cleanup, ownership metadata, and partial-cleanup policy changes.
- Proof-plan inference, new recipes, snapshot enlargement, host proof action types, and general workflow frameworks.

## Documentation

Audit scope: ORB-167; `docs/reference/incus-topologies.md` plus generated context selected by `apps/e2e`, `Node`, and `Proof topology` filters.

Fixed:
- `docs/reference/incus-topologies.md`: lease and release text omitted persisted extension and ambiguous legacy recovery -> documents target retention, complete-record compatibility, exact paired recovery, retry behavior, and the host-versus-guest evidence boundary.

Reported:
- None.

Verification: `composer docs-build` and `composer docs-lint` passed.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| New lease persistence and complete identity validation | `IssueState`, acquisition and proof runners | `IssueStateTest`, `TopologyAcquirerTest`, `TopologyProofRunnerTest` |
| Current lease-only release and partial retry | `TopologyReleaser` target selection | `TopologyReleaserTest` |
| Complete legacy compatibility and ambiguous refusal | `IssueState`, `TopologyReleaser` | `IssueStateTest`, `TopologyReleaserTest` |
| Paired explicit recovery options and purpose selection | `ReleaseCommand`, recovery value | `TopologyCommandsTest`, `TopologyReleaserTest` |
| Atomic, idempotent, conflict-safe recovery | `IssueState`, `TopologyReleaser` | `IssueStateTest`, `TopologyReleaserTest` |
| Guest boundary and real host deletion | Issue proof fixtures and host rehearsal | `lease-target-contract`, `orb-167-host-rehearsal.sh` |
| Maintained reference and context | Incus topology reference | `composer docs-lint` |
| Project and repository integrity | E2E and root suites | `composer check`, `bin/test` |

## Implementation order

1. Update and verify scoped maintained documentation.
2. Persist and validate lease extension identity before resource construction.
3. Select release targets from current leases or complete compatible records.
4. Add exact paired legacy recovery and its command surface.
5. Add focused regressions for acquisition, state, release, proof, and command behavior.
6. Commit a clean product/docs checkpoint and run project checks.
7. Exercise guest proof fixture and real Incus host rehearsal diagnostically.
8. Stop for the serialized current-main, root-suite, immutable-proof, and authoritative-host window.

## Must preserve

- Discovery remains the default release purpose.
- Exact resource ownership validation and unrelated attempts remain unchanged.
- `releaseExact` and captured-attempt cleanup semantics remain unchanged.
- Partial cleanup keeps the lease and its exact target for retry.
- Guest evidence never claims native Incus deletion; host rehearsal never replaces retained proof.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
