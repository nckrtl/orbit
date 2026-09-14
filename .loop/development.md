# ORB-351 development record

Flow: proof. Status: implemented, acceptance and merge blocked.
Candidate: f59e9a1bd83b2055fb7f422b5cac9e63380b50af.
Included main: d3121757a3d6ce45e12079b2af1b99e0fa62a2b7.

The foundation publishes one generic CLI standard, focused design and terminal
verification skills, a portable adoption record, current contributor routing,
and tested PTY capture/analysis. No product command is migrated by this issue.
The inventory has106 public commands and20 separately accounted framework
surfaces. Every command adoption verdict remains Unverified.

## Acceptance status

1. Standard and authority: documentation/semantic preparation review passed;
   docs-build, docs-lint and pinned Mintlify validation passed. Incus binding
   action prepared but not executed there.
2. Skills/routing: both skill validators pass; CLI guidance15 tests/304
   assertions and project/TIA checks pass. Generated guidance was refreshed.
3. Recorder:38 recorder/analyzer behavioral tests pass on macOS and Linux,
   including five cleanup paths, sentinel survival, partial input and deadlines.
   Real Solo1741 input,87x23 size, Unicode/dim cells,~307ms animation and restored
   cursor pass on tooling candidateb58b6077. Source blobs are unchanged in79a12944.
4. Verifier: negative state/cadence/input/identity cases pass. Independent review
   found input backpressure, the correction was committed, and the reviewer
   closed that finding against exactb58b6077. This is advisory review, not PR
   approval or isolated acceptance.
5. Registry: current79a12944 positive reconciliation and three negative cases
   pass, including stale Doctor help. Main's intentional removal of the
   Workspace family is recorded, without treating it as UX adoption.
6. Full files: local proof-fixture smoke generated an88,704-byte archive and
   reconstructed it through44 bounded reads. All28 manifest file sizes/hashes
   match; the largest response is2795bytes. Incus recorded export remains due.

See `cli-ux/foundation-checks.json`, `registry-fixture-checks.json`, the source
map, inventory, matrix, and proof runbook for retained paths and limitations.
The local full fixture run is tooling evidence, not a harness acceptance receipt.

## Checks and blockers

The exact-head Beast Builder gate failed only Gateway `composer test:affected`:
an unchanged legacy Instance fixture in RemoveNodeTest.php:953 expects offline
node removal to succeed. ORB-205 owns remaining legacy models/dead fixtures.
All five project validations/quality checks and CLI, Docs, E2E and SDK TIA pass.
Retain the failed receipt; never substitute this partial pass for a Builder gate.
See `cli-ux/main-check-incident.md`.

Supported discovery acquisition was attempted in Solo1740 and refused exit1:
no promoted topology snapshot generation. Snapshot status remains missing;
ORB-351 has no topology. ORB-91 owns snapshot recovery. No Incus acceptance,
capture, required proof review action, formal PR approval, merge or closeout has
occurred. No unrelated/shared guests were used or released.

## Documentation

Changed docs/reference/cli-ux.md (canonical standard), cli-command-vocabulary.md
(presentation routing/current surface), docs/README.md and docs/docs.json
(discovery), and docs/generated/context.json (generated context). Accepted ADRs
are unchanged. Product schema/domain migration stays with its existing owners.

## Continuation

Obtain the main repair and promoted snapshot; integrate current main, reconcile
any registry delta, and require a successful exact-candidate Builder gate. Then
follow `.loop/proof/ORB-351.json` and `cli-ux/proof-runbook.md` for discovery,
fresh acceptance, full export, Solo review, independent approval and closeout.
Only then proceed through ORB-352 and the approved command-family dependency
chain. The full restoration goal is incomplete.

## Route domain main integration

Merged ORB-256 main d3121757 into f59e9a1b with no conflict. Ten public
definitions changed, including --domain and the route:create domain argument.
The current inventory/fixture records all 106 public and 20 framework commands;
every UX verdict remains Unverified. Archived prior inventory under registry-history.
Exact registry and four altered-fixture rejection checks pass, including rejection
of the retired route:update --hostname option. All source and test paths exist.

Current guidance passes 15 tests/304 assertions; docs-lint passes. Beast's exact
f59e9a1b Builder receipt review-48gxn4_x is failed and unchanged: Gateway TIA
has the same legacy Instance failure (1 failed, 3396 passed, 20787 assertions,
1425 affected). Every other project validation, quality check and TIA passes.
The standard and both skill source trees are byte-identical to tooling candidate
b58b6077; old full local fixture exercises retain their original identity.
No Incus acceptance or formal review occurred.
