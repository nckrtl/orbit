# Feature plan

Issue: ORB-180
Review verdict: UNREVIEWED (prepared during implementation)

## Outcome

Finalize one removal member from its persisted source inventory. Delete only
the recorded checkout or worktree. Resume an interrupted quarantine operation
from exact journal, receipt, filesystem identity, and worktree administration
evidence.

## Code boundaries

In:
- An inactive recorded-member finalizer contract and Gateway adapter.
- Per-Node preparation, revalidation, quarantine, receipt, and cleanup.
- Checkout and linked-worktree handling that preserves shared Git state.
- Focused Gateway tests and three-node Incus adapter proof.

Out:
- Public removal activation and multi-member orchestration.
- Route, runtime, AppInstance lifecycle, API, SDK, and CLI changes.
- Remote or retained local branch deletion, source adoption, and harness changes.

## Documentation

Audit result: unchanged. The adapter is an inactive internal boundary for the
next removal orchestration issue. Maintained user and operator pages do not yet
describe an invocable behavior, and changing them would imply public
activation that is outside this issue.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Checkout and worktree finalization preserve every unowned Git resource | `DevelopmentAppInstanceSourceFinalizer::finalize` and the remote finalization script | Source lifecycle feature tests; Incus `recorded-source-finalization` |
| Interrupted operations resume only from matching durable evidence | Preparation journal, quarantine identity, worktree recovery manifest, receipt, and revalidation state | Source lifecycle feature tests; Incus `source-finalization-recovery` |
| Retry refuses all recorded source drift before further deletion | Recorded inspection plus immediate shell revalidation under the source-operation lock | Source lifecycle feature tests; Incus `source-finalization-revalidation` |
| Missing, ambiguous, or mismatched recovery state is refused | Revalidation state script and receipt cleanup boundary | Source lifecycle feature tests; Incus `source-finalization-recovery` |
| Adapter remains inactive and every call holds the per-Node lock | Container alias to the existing removal service; no action/controller caller | Source lifecycle and AppInstances API feature tests |
| Project checks pass | Gateway and repository verification | `composer check`; `PHPRC=/dev/null bin/test` |

## Implementation order

1. Extend the existing source inventory with transient raw origin and worktree
   evidence without changing its persisted digest.
2. Add the inactive recorded-member finalizer and alias it to the existing
   source removal singleton.
3. Revalidate persisted App, checkout, Git, ownership, ancestry, and inventory
   evidence under the per-Node source-operation lock.
4. Quarantine the exact source, write a durable receipt, and recover incomplete
   cleanup through identity-bound state.
5. Cover success, retry, recovery, drift, ambiguity, receipt, shared Git, and
   lock behavior in focused tests and disposable three-node proof.

## Must preserve

- Existing `inspect` and `remove` behavior and their public callers.
- ORB-179 persisted field meaning, including the effective web root in
  `removal_members.root`.
- Checkout finalization refuses while any linked worktree still depends on its
  common repository.
- Worktree finalization keeps the common repository, sibling worktrees, local
  branch, and remote branches.
- Absence succeeds only with the exact durable completion receipt.
- No Route, runtime, lifecycle, or public contract mutation.

## Open questions

- None.

## Deviations

- The implementation adds an identity-bound worktree recovery manifest beside
  the journal and receipt. It records the exact administration, common Git, and
  worktree-directory filesystem identities before the receipt so acknowledged
  partial cleanup can distinguish removed metadata from replacement metadata.

## Review findings

- Independent source preflight P1: refuse checkout finalization with linked
  worktrees. Resolved with a recorded inventory guard and preservation test.
- Independent source preflight P1: resume acknowledged cleanup after partial
  `.git` or worktree-administration deletion. Resolved with structural probing,
  the recovery manifest, exact-entry cleanup, immediate identity checks, and
  focused interruption tests.
