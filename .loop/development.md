# ORB-352 development evidence

Flow: proof. Incus: required. Public family adoption remains unverified.

Candidate: fe66e4f515c6fc161353d4cb38622630c7580d17, containing main f4e7f46fe9eec413e4aa9f84e7b807ebdf11534b. Main integration was conflict-free and preserves the accepted multi-target Route options and SDK response fields.
The shared primitives cover separate input/output modes, scoped Laravel Prompts,
width-safe detail/table/property/error output, one-owner progress and nested waits,
checked writes, cancellation and explicit terminal cleanup. Existing product
schemas, explicit targeting, consent and transport contracts retain their owners.

## Corrections found in real terminal work

Discovery mount identity uses the harness marker plus a full committed-blob
manifest. The normal host .git pointer cannot be resolved inside the guest.
Detached isolated proof retains matching HEAD, clean status and runtime inventory.

Nested renderer restart yielded40-155ms glyph changes. Nested composition now
retains one renderer and acknowledges updates on its300ms monotonic clock. A final
active frame preserves the current glyph until the command explicitly settles its
product result. Business callbacks execute exactly once in the parent. Same-stream
private callback-entry markers prove the active frame appeared before each callback.

Verifier corrections retain original ANSI boundaries during emoji replay, compare
full padded state rows, normalize PTY CRLF for Bash syntax checks, and use the
completion client's actual nonempty input tokens. Six prose-contract tests fix
COLUMNS=120 and restore it; every original message/status/side-effect assertion
remains. Separate unit and PTY cases verify narrow output and complete wrapping.

## Development checks

- Final-phase CLI TIA with inherited COLUMNS=80:871passed/5720assertions,
  /tmp/orb352-tia-final-phase-80.log.
- CLI composer check passed: /tmp/orb352-cli-check-final-phase.log.
- Docs build/lint and Mintlify validate/broken-links passed during development;
  after main integration docs lint passed with no findings:
  /tmp/orb352-docs-lint-main-integration.log.
- Beast E2E composer test:fresh:1553passed/8645assertions at1b91c68a,
  /tmp/orb352-e2e-fresh-1b91c68a.log. Harness source unchanged afterward.
- Beast exact-candidate Builder gate at f202910c passed all 15 checks across five projects with no selection warnings. Receipt: .loop/checks/builder-f202910c.json (original review-vyn_ho1g on Beast).

## Discovery and proof

Discovery attempt7ed62b0e76ca24adf64d98820772116a, Solo process1740.
App-dev source /home/orbit/orbit, host worktree /fast/worktrees/orbit/orb-352.
A complete fresh six-action run at f202910c passed every action with exit 0. Static layout34cases,
state styles12cases, modes14cases, prompts16cases, liveness5cases,
lifecycle21cases, common contracts27cases. Existing earlier runs exposed and
verified the corrections above; they are not isolated acceptance evidence.

Manual Solo selection/retry/cancellation at031cc8a2 and failed-cadence regression
recordings are privately retained in evidence/orb352-discovery-031cc8a2 under the
root task evidence directory. All59files verified after10 bounded harness reads;
archive19436bytes, SHA256c44a8da311cab06ded9e0994223c4530c3003b800a6b5f632dcd7a86e293e46b.
Selection returned stable key record-beta; invalid text retried; cancellation
created no marker. Native stty restored and final cursor visible in all three.

The proof plan opts out of observed_inputs because these local CLI/PTTY fixtures
do not provide Gateway CLI and FPM observations in both required phases. Broad
static inputs are declared. mutates=true declares disposable recorder setup.
Full raw/chunks/frames/input events/summaries/native tty/process facts are retained.
A complete archive is emitted only after all six action receipts succeed, then
exported through recorded bounded reads with all sizes and hashes verified.

The f202 discovery archive contains 1,932 files, 1,687,436 bytes, SHA256 926aec7329ff1dd62ec3708dc6fac93b842cddedc25ba93d0862c54d2c506e2d. Guest archive: /home/orbit/.local/state/orbit-cli-ux/ORB-352/orbit-e2e-orb-352-7ed62b0e-app-dev/f202910cf8bdc9f5c6702e8278853d13967754d8/4a19b0b490fe4e9b93ec59b0f2a81f13.tar.xz. All six action receipts were read back in Solo. This is discovery evidence only.

After main f4e7 integration, CLI TIA passed 101 tests / 2,154 assertions (/tmp/orb352-tia-main-f4e7.log). Docs lint passed (/tmp/orb352-docs-main-f4e7.log). The fe66e4f5 Beast Builder gate passed all 15 checks with no selection warnings; exact receipt .loop/checks/builder-fe66e4f5.json, original /home/nckrtl/orbit/.git/orbit-checks/fe66e4f515c6fc161353d4cb38622630c7580d17/review-zipe4cbt/result.json. Local CLI composer check also passed (/tmp/orb352-cli-check-main-f4e7.log). Source manifest regenerated from clean fe66e4f5: 2,700 files, SHA256 87477813826b6e6039a87b36c498c9b120754d01ce2cb78f12dabffa528c5565.

Isolated proof, capture, exact-candidate independent review and closeout remain.

## Documentation

- docs/reference/cli-ux.md: shared standard/helper behavior, narrow layout and
  invocation boundaries; no claim of family compliance.
- docs/cli/overview.mdx: common surfaces, current format/target/consent boundaries.
- docs/generated/context.json: generated documentation index.

Remaining family-specific gaps belong to ORB-353–362 and integrated ORB-363.
The plan retains the documentation audit, owners and bounded implementation
clarifications. No legacy product vocabulary, policies, schemas or remote
execution mechanisms were reintroduced.
