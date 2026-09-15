# ORB-351 formal review: main freshness blocks approval

Verdict: approval withheld solely because current main is not included. The independent acceptance and runtime assessment is complete for the reviewed candidate. No substantive implementation or acceptance finding remains on that candidate.

## Exact binding

- PR: https://github.com/nckrtl/orbit/pull/430, open submitted head `c652f0b4af5645c3718461771bd48e830d53a8fd`.
- Reviewed candidate R: `c652f0b4af5645c3718461771bd48e830d53a8fd`.
- Tree: `b22bdd67829d8025e2a456ffcd697d370e5d4be9`.
- Included main B: `6eeb4d6b2e5d4150ffe5d8abe93e4208e548b77d`.
- Current remote main M: `e89af206eb2066d7ac9709dbc08ada5da522ecbb`.
- Artifact: `794bb418b367e0d7ef970265dc42a73051dd7046` at `refs/tags/loop/orb-351/c652f0b4af5645c3718461771bd48e830d53a8fd`.
- Flow: `proof`; Incus required. Local flow and published selection agree.
- Checkout: `/Users/nckrtl/orbit-worktrees/orb-351`, clean at R. The supported artifact fetch/validation succeeded with both exact SHAs.

The final live `git ls-remote` returned R for `refs/pull/430/head` and M for `refs/heads/main`. After fetching main, `git merge-base --is-ancestor origin/main HEAD` exited 1. The reviewing-pull-requests skill's proof-flow freshness rule prevents approval. The new main includes PR 424; its integration and affected registry/contracts need the next candidate's assessment. This report does not assess or approve that future integration.

No code, product configuration, PR, merge, resource release or snapshot mutation was performed. The reviewer added only a supported required guest review action, evaluated its result, fetched read-only review refs and wrote this external report.

## Scope and authority

The live ORB-351 scope, six acceptance items, `docs`, `incus` and `apps/cli` labels and ADR attachments are unchanged. ADRs 0014, 0036, 0058 and 0071 and the confirmed consent decision govern the assessment. The complete source assessment is retained in `/Users/nckrtl/.codex/work/cli-ux-restoration/orb351-semantic-c652f0b4.md`; source and artifact are unchanged since that assessment. Its pending runtime items are resolved below for R.

The feature changes the shared standard, design and verification skills, recorder/verifier and behavioral tests, root/CLI routing and documentation. It does not change product command behavior, API/SDK schemas or the topology harness. Current targeting, typed HTTP transport, machine schemas, request IDs, redaction and errors remain authoritative. Default-No destructive/ownership-transfer consent and independent automation consent are approved future adoption work; force overrides retain their distinct meanings. Every public command remains Unverified.

## Complete acceptance assessment

| Item | Result and evidence |
| --- | --- |
| 1. Generic standard | Pass for R. Independent semantic review confirms modes, primitives, consent, errors, clean machine output, truthful progress, cadence and evidence, with current ADR/command authority. Maintained standard and routing were inspected. Submitted docs build/lint and Mintlify validation/broken-links checks pass. Fresh `standard-binding` exits 0. |
| 2. Discoverable executable skills | Pass for R. Both root skills route to the maintained standard and current flow without transferring delivery authority. CLI source/generated guidance and routing test agree. Fresh `skill-forward-check` exits 0; its exact recording demonstrates prompt-triggered input, state progression and final output. |
| 3. Recorder | Pass for R. Fresh retained `recorder-contracts.stderr` records all 26 behavioral tests passing in 12.387 seconds, including real PTYs, split Unicode, colors/dim, actual partial input writes and backpressure, trailing output, child failure, wall/idle timeout, signals, collector faults, terminal restoration, owned descendants and unrelated sentinel protection. Solo manual fixture forwards y+Enter, records 2 delivered bytes and none pending, and exits 0 for child and capture. Terminal restoration comparison exits 0. |
| 4. Verifier | Pass for R. All 12 analyzer tests pass. Inspected paired cases cover final content, candidate identity, missing or incomplete input, state presence/order, backward transitions, genuine glyph changes and frozen/incomplete animation tails. Forward and manual verdicts pass with no failures. |
| 5. Inventory/adoption | Pass for R. The 107 public command rows exactly match the extension-enabled expected registry; 20 framework/internal surfaces and 127 registration keys are separate. All row references, source/test paths and source hashes resolve. All 175 runtime/source/lock hashes match. `app:update` is included with current explicit-input and safety boundaries. Local exact comparison and nine altered fixtures have expected outcomes; fresh guest `registry-reconciliation` exits 0. All 107 public verdicts are Unverified with empty evidence pointers. The adoption format contains modes, applicable rules, exceptions/gaps, tests, terminal cases, identity, artifacts and verdict. |
| 6. Complete export | Pass for R. Independently reconstructed acceptance archive from 26 recorded chunks and manual archive from 3. Every read has the expected offset and length, at most 2048 bytes. Archive byte counts and SHA256 match. All 28 acceptance and 10 manual manifest files match archive entries and local files in both bytes and hashes. The large raw recording is 12,923 bytes and retains FINAL-MARKER in the final frame. A required independent guest check repeats archive and every-file verification as UID 1000, exit 0. |

## Builder gate

Canonical receipt read live on Beast:
`/home/nckrtl/orbit/.git/orbit-checks/c652f0b4af5645c3718461771bd48e830d53a8fd/review-h4kezf6i/result.json`.

Schema 1, role builder, exact R/tree/B, passed true, unchanged true, warnings empty. The artifact copy has the same binding and outcomes. All fifteen checks exit 0:

| Project | Composer validate --strict | Composer check | Composer test:affected |
| --- | --- | --- | --- |
| apps/cli | 0 | 0 | 0 |
| apps/docs | 0 | 0 | 0 |
| apps/gateway | 0 | 0 | 0 |
| apps/e2e | 0 | 0 | 0 |
| packages/php-sdk | 0 | 0 | 0 |

No root gate was repeated. The additional E2E fresh result of 1,551 tests / 8,642 assertions is reported by the implementation handoff; the Builder receipt and specific acceptance evidence above carry the review conclusions.

## Proof and independent runtime review

- Retained Beast worktree: `/fast/worktrees/orbit/orb-351`.
- Attempt: `6568fe4098be0b40c503082b04038130`.
- Captured candidate: R, exact.
- Capture fingerprint: `49673eb602ef09af8d476129ec66961981a3c874cba92e67ecf513099ec11242`.
- Proof-plan fingerprint: `f25b02c07e0dc3ef9ead0dfa4d9673995a655d16034b7e8148d57dee815dea18`.
- Input manifest: `a829bae7a1e6685a6bd1c5d49ca46ac9ed08e04f6ebd6aa583ee898ab5dfc39a`.
- Setup and all six acceptance actions exit 0; all 21 general probes pass.

Supported live status reports proved, captured and retained. The complete standard inventory remains: gateway, app-dev and app-prod for this exact attempt. Idle discovery is absent. All 1,608 static manifest entries independently match candidate/artifact Git modes and blobs, with no mismatches. Completeness flags pass. `observed_inputs: false` is explicitly disclosed for Python/terminal fixtures, and the broad static policy applies; no observed-input equivalence shortcut is inferred.

Supported review records contain all 29 bounded export reads and the passed required Solo shell. The independent reviewer added `independent-archive-integrity` using `bin/e2e-topology exec ORB-351 app-dev --proof --review-action=independent-archive-integrity --required --json`, running Python against both retained archives as ordinary UID 1000. It checked archive hashes and every manifest member's bytes/hash and exited 0. The following supported review evaluation reports ready, with no required incomplete, required failed or exploratory failed actions.

Acceptance archive: 52,442 bytes, SHA256 `980d120087a3e5f986c633fb61062c1f017b8f5a5ff0f2fecc49507fbf6e43e9`.
Manual archive: 5,783 bytes, SHA256 `486462da9fc66ad00b3a7a56ac1116ec81823730ecb12dacc6d66bacaf0d0cf4`.
Full evidence: `/Users/nckrtl/.codex/work/cli-ux-restoration/evidence/proof-c652f0b4/`.

All full reconstructed frame sequences were inspected: 10 forward frames, 12 large-output frames and 10 Solo manual frames. They preserve 87x23 geometry, café, wide 界 and border glyphs, dim Unicode text, cyan active glyphs, green non-dim completion and a visible final cursor. Work advances queued to running to complete, with approximately 300ms active changes and a fully drained final marker. The manual initial 14.26-second wait occurs before keyboard input, not while work is active. Manual child/capture/verifier exit 0 and the before/after stty comparison exits 0. The fresh lifecycle tests separately prove restoration under failures, interruptions, timeouts and collector errors.

The reviewer inspected retained Solo evidence and full PTY frame data; the implementation lead performed the live Solo session. Native screenshot/pixel inspection is not claimed because the window API returned cgWindowNotFound. The braille fixture is terminal-tooling stimulus, not a compliant Orbit product renderer. These limits do not leave an ORB-351 acceptance item unassessed.

## Publication, documentation and corrections

The submitted PR body and byte-readback were read at `/Users/nckrtl/.codex/work/cli-ux-restoration/orb351-pr-body.md` and `orb351-pr-readback.json`. They contain exact identity, per-item results, receipt, documentation list, proof/export locations and limits. Independent live PR-head checks used Git because the GitHub connector is disconnected; no GitHub mutation or gh command was used. Body publication relies on the orchestrator's supplied readback.

Checked documentation: `docs/reference/cli-ux.md`, vocabulary link, docs README/navigation, generated context, CLI README and source/generated contributor/skill routing. Family-owned adoption gaps remain explicit. The nonblocking `.loop/cli-ux/registry-fixture.md:8` guest-path defect is corrected in the PR body and inspection report: retained fixtures use `/var/lib/orbit-e2e/proof/`. The actual wrapper already uses those paths, and immutable artifacts were preserved.

## Handoff

Only blocker: proof-flow main freshness. No approval is issued. The acceptance assessment for R with included main B is complete and can support the skill's next-pass reuse rules, subject to their exact merge shape, unchanged contract and new-candidate evidence requirements. Reassess incoming command/registry interactions, validate the new Builder receipt/artifact and required proof evaluation, and recheck final remote head/main. Neither this assessment nor the successful R proof approves a later candidate. Resources remain retained for the orchestrator.
