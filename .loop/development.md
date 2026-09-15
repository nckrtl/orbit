# ORB-352 development evidence

Flow: proof. Incus: required. This record describes the candidate at artifact publication. Subsequent proof, export, manual inspection and independent review are retained separately by the harness and the root delivery record. Public command-family adoption remains unverified.

Candidate: af717b848c956f1f9ac017c1a3def66474b290ae.
Included main: 3092c3a83ebea75e203fd139bfb5932ff3530b1d.
Worktrees: /Users/nckrtl/orbit-worktrees/orb-352 and /fast/worktrees/orbit/orb-352.
The main integration is conflict-free. Its Tool Doctor inventory change is unchanged upstream work. The CLI tree is identical to corrected candidate 05f5bd9e: e75826b8736a7cf6ac48daf34aaf6209de9ffa3a.

## Implementation and boundaries

Shared helpers separate input admission, machine output, decoration, repainting and selected-output width. Scoped Laravel Prompts v0.3.24 supplies validated prompts and stable-key datatables. HumanRenderer preserves complete text, literal markup, null/false/zero, table columns, detail/property/error structure and narrow-width fallback. ProgressDisplay and SpinnerDisplay retain one output owner through nesting, acknowledge paint before parent callbacks, and maintain a 300 ms monotonic animation clock. Checked writes and explicit cleanup preserve terminal settings, cursor state and owned processes across success, failure and interruption.

GatewayCommand, GatewayFailureRenderer and Kernel integrate common boundaries. Command families still own targets, admitted prompts, consent, override meanings, transport, numeric statuses and machine schemas. No public command names/options, API/SDK fields or harness code change. Business callbacks execute exactly once in the parent.

## Current checks

Builder gate: passed all 15 checks on clean af717b84 across all five projects. Receipt: .loop/checks/builder-af717b84.json; original /home/nckrtl/orbit/.git/orbit-checks/af717b848c956f1f9ac017c1a3def66474b290ae/review-sronqypg/result.json.

The receipt has one CLI TIA no-selection warning. The CLI tree is byte-identical to 05f5bd9e, whose immediately preceding Beast gate passed without warnings (.loop/checks/builder-05f5bd9e.json). The correction's local CLI TIA executed 779 tests / 5297 assertions, and CLI composer check passed. Logs: /tmp/orb352-tia-native-width.log and /tmp/orb352-cli-check-native-width.log. The merge only changes Gateway files. This explains the CLI skip; the current root gate is not claimed as a new execution of those CLI tests.

Docs lint passed after the native-width correction: /tmp/orb352-docs-native-width.log. Documentation is unchanged by the main merge. Docs build, Mintlify validation and broken-links checks passed during development. Beast E2E test:fresh passed 1553 tests / 8645 assertions at 1b91c68a (/tmp/orb352-e2e-fresh-1b91c68a.log); harness source is unchanged afterward. Historical check receipts remain named by their actual candidates.

## Terminal findings and correction

Earlier discovery recordings exposed 40–155 ms glyph changes caused by nested renderer restarts. The renderer now preserves its clock and final active glyph. Private callback-entry markers in the same PTY byte stream prove paint precedes work; callback execution stays in the parent.

Native Solo inspection of fe66e4f5 found misaligned Unicode table separators despite passing automated checks. Solo cursor queries measured joined emoji and modifier sequences as four cells and the keycap as one; mode 2027 was unsupported. Both the renderer and its original oracle incorrectly assumed a two-cell cluster. TerminalText now measures ordinary base-character cells while keeping every grapheme intact. The corrected independent scalar-cell oracle rejects all eight earlier overflowing bordered tables; the root reproduced rejection of an 80-column row as 82 cells.

Native discovery recordings of corrected 05f5bd9e were inspected in Solo at 60, 80 and 120 columns. Borders and Unicode rows align. The five-column table preserves complete graphemes at its 25-column minimum and switches to complete labeled records at 24. Raw recordings: /home/orbit/.local/state/orbit-cli-ux/ORB-352/manual-discovery-05f5 on discovery app-dev. These are development observations, not isolated acceptance for af717b84.

A subsequent discovery layout run was invalidated when the root advanced the mounted checkout during verification. Its source guard refused the changed Gateway file. The run remains failed; a new session after supported sync passed all 34 layout and 12 state-style cases at af717b84 with action exit 0. Its guest output root is /home/orbit/.local/state/orbit-cli-ux/ORB-352/orbit-e2e-orb-352-7ed62b0e-app-dev/af717b848c956f1f9ac017c1a3def66474b290ae/acc67ba4f6d54080863b08fc2057726e. Log: /tmp/orb352-discovery-af717b84-layout.log.

## Proof and retention

The six declared actions cover layout (34 cases plus 12 state-style cases), modes (14), prompts (16), liveness (5), lifecycle (21) and common contracts (27). Cases retain exact invocations, descriptor facts, timestamped bytes, frames, input events, shell/process facts and expected negative outcomes. Each action must exit zero after asserting its child results.

Observed inputs: false. Local CLI/PTTY fixtures cannot supply Gateway CLI and FPM observations in both required phases. Broad static inputs are declared. mutates=true covers disposable recorder setup. Source manifest: 2704 committed files, SHA256 288a1b1b04b846e6916f0ac01cf073355c271d357b26d4141a00ecb8f8e87c79. Mounted discovery uses the harness marker and full blob manifest; isolated proof uses clean Git identity.

Complete proof recordings must be captured, exported through supported recorded bounded reads, verified by size/hash, and inspected before topology release. Manual Solo actions belong to the separate review record. Fresh isolated proof and independent approval are required for this candidate; publication alone claims neither.

Historical fe66 proof attempts 99235e689d27ab46984f74e8a0ed7364 and dce9c6e7b5e906c96c02deffd9711149 were fully exported before supported replacement release. The first has a root metadata-query failure; the second has the real native width finding. Their complete evidence remains under /Users/nckrtl/.codex/work/cli-ux-restoration/evidence/orb352-proof-99235e68 and orb352-proof-dce9c6e7. They are not final acceptance.

## Documentation and follow-up

- docs/reference/cli-ux.md: shared helper behavior, narrow layouts, ordinary terminal cells and invocation boundaries.
- docs/cli/overview.mdx: common surfaces and current format, target and consent boundaries.
- docs/generated/context.json: generated documentation index.

The plan retains the documentation audit, reported findings and their owners. Its clarifications concern plain waiting lines, invocation scoping, minimum datatable height, nested cadence and native width; none changes product contracts. ORB-353–362 own command families, and ORB-363 owns integrated adoption. All 108 public command verdicts remain Unverified.
