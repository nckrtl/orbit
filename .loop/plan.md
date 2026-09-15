# Feature plan

Plan format: 1
Issue: ORB-351
Flow: proof
Review verdict: PASS

## Outcome

Command authors and reviewers use one generic CLI design standard and two reusable skills, with a tested recorder and inspected terminal evidence.

## Code boundaries

In:
- Root `.agents/skills/designing-cli-commands/` and `.agents/skills/verifying-cli-output/`: focused guidance, an adoption-record template, Python recorder/verifier, pinned requirements and behavioral tests.
- Root `AGENTS.md` and the planning, development, plan-review and PR-review skills: route CLI interaction and rendering work to the two skills without changing role or delivery authority.
- `apps/cli/.ai/skills/command-designer/SKILL.md`, its generated `.agents` output, `apps/cli/.ai/rules/app.md`, `commands.md`, `.ai/skills/orbit-cli-development/SKILL.md`, `.ai/guidelines/orbit.md`, CLI `AGENTS.md` and `README.md`: replace contradictory generic consent/output instructions with canonical routing while retaining current product boundaries. Use Boost generation for generated output.
- `apps/cli/tests/Feature/BoostGuidanceTest.php`: verify that repository-owned and generated CLI guidance routes to readable root skills and the canonical standard.
- `.loop/cli-ux/`: candidate-bound current inventory, source provenance, contract/exception map and unverified adoption matrix. These are delivery artifacts, not a second maintained documentation corpus.

Out:
- Product command implementations, signatures, targeting, consent behavior and result schemas remain unchanged in this foundation issue; ORB-352 and the command-family issues own adoption.
- Gateway and SDK code, transport, permissions, data models and API schemas remain unchanged.
- `apps/e2e` and `bin/e2e-*` harness code remain unchanged. Issue-owned fixtures use the supported proof plan interface.
- Accepted ADRs remain unchanged; old product entities, aliases, envelopes, Doctor repair and deployment machinery are not imported.

## Documentation

- `docs/reference/cli-ux.md`: owns generic input modes, prompt selection, explicit consent, result primitives, terminal state/liveness and evidence rules. Its introduction states that adoption requires inspected evidence and names `apps/cli` for context routing.
- `docs/README.md`: links authors and reviewers to the standard and groups reference and contributor links under clear navigation headings.
- `docs/docs.json`: adds the standard and command vocabulary to the Contributors navigation group.
- `docs/reference/cli-command-vocabulary.md`: removes two retired deployment commands, links named deploy steps to the deployment reference, and routes presentation to the standard.
- `docs/generated/context.json`: regenerated from the maintained corpus.
- Scope audit: filtered `composer docs-context -- --component=apps/cli` plus the foundation page. The old CLI command-designer's universal force and fixed success/error envelope rules are corrected in contributor guidance during implementation. Product domain/schema drift remains owned by ORB-256; native registration/trust proof belongs to ORB-362. The legacy Doctor gap belongs to ORB-88 and later UX to ORB-361; Cluster-first precedence ownership remains ORB-354. The CLI guidance generation also corrects its stale reviewer-owned gate sentence to the current Builder-owned policy. Detailed audit findings and owners are retained with the plan artifacts.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Canonical generic standard preserves current authority | docs/reference/cli-ux.md and its navigation | `composer docs-build`, `composer docs-lint`, Mintlify validation/link checks, and independent semantic review against the source map and attached ADRs; proof action `standard-binding` verifies the candidate's reviewed document and skill inputs. |
| 2. Discoverable skills have executable, current instructions | root skills and contributor/CLI routing | Skill frontmatter validation, CLI BoostGuidanceTest.php through TIA/guidance checks, and proof action `skill-forward-check` exercising documented tools on disposable fixtures with no old checkout available. |
| 3. Recorder preserves interaction, bytes, status and cleanup | verifying-cli-output/scripts/capture.py and tests/test_capture.py | Proof action `recorder-contracts` runs behavioral tests and a real PTY fixture on app-dev; subsequent required Solo interaction checks manual keyboard input, dimensions, Unicode, dim cells and trailing output. For normal completion, child failure, interruption, timeout and collector failure, tests and the disposable PTY fixture assert identical terminal settings before/after, visible cursor, separate child/capture status, termination and reaping of owned children/descendants, and survival of an unrelated sentinel process. |
| 4. Verifier rejects incomplete or false evidence | verifying-cli-output/scripts/verify.py and tests/test_verify.py | Proof action `verifier-contracts` accepts correct fixtures and rejects wrong identity/status/output, missing actions, backward states, frozen active tails and active final frames. Expected child failures are asserted by the fixture wrapper; every proof action exits zero only after its assertions pass. |
| 5. Complete current inventory and adoption format | designing-cli-commands template and .loop/cli-ux artifacts | Proof action `registry-reconciliation` enumerates the candidate registry with an isolated Orbit home and enabled Herdr, compares supported product commands and separately accounts for internal/framework commands; CLI CommandSurfaceTest.php through TIA. Every adoption row starts Unverified with empty evidence. |
| 6. Complete recordings survive export | verifying-cli-output evidence instructions and proof fixture bundle | Proof action `full-artifact-manifest` creates a recording larger than 4096 bytes, emits a bounded file/size/SHA-256 manifest, and retains full files on app-dev. Supported recorded reads export bounded chunks before release; local reconstruction checks every size/hash. Independent review inspects the actual frames and the exported bytes. |

## Incus observations

Incus: required. Flow: proof. Use issue-owned discovery after independent preflight, then a separate proof topology on Beast. Snapshot recovery through ORB-91 is complete. The ORB-364 repeatable hibernator installer repair is accepted on main. Use the refreshed snapshot after its closeout; do not reuse another issue's live resources as this issue's proof.

Set `observed_inputs: false`: acceptance primarily executes Python and terminal fixtures, so PHP PCOV cannot supply complete app-dev CLI, Gateway CLI and Gateway FPM observations. Declare the root skill directories, their tests/templates, the standard and the inventory fixture as proof inputs. Do not infer equivalence from incomplete PHP observations.

The implementer writes `.loop/proof/ORB-351.json` with setup and the six named acceptance actions, using app-dev's candidate checkout at `/home/orbit/orbit`. Flat fixtures under `.loop/proof/` bind the scenarios and assertions to the artifact commit. Setup creates an isolated Python environment with the pinned pyte/wcwidth requirements; record package versions and hashes. No privileged host mutation is part of acceptance.

Fixtures record the actual candidate SHA/tree and launcher path, validate them against the proof binding, and write to a private issue/attempt/case directory. Record raw bytes, timestamped chunks, styled frames, input events, child/capture/verifier exits, dimensions, first-output delay and cadence. Output-tail pointers are not artifact retention. Keep immutable acceptance recordings separate from later required Solo review actions; export both with hashes before topology release. A fix to recording or verification behavior requires fresh applicable proof.

## Implementation order

1. Commit the standard, navigation and generated context; save this plan and obtain independent preflight review.
2. Install the reviewed skills/tools and portable adoption template. Route contributor and generated CLI guidance to the standard, then regenerate Boost output.
3. Materialize the source map/current inventory as issue artifacts, reconcile the live registry in an isolated configuration and add routing checks.
4. Extend recorder lifecycle tests and proof fixtures with the five cleanup paths named in acceptance 3. Allocate tracked child/descendant PIDs and an unrelated sentinel; compare terminal attributes and cursor state after each path, assert no owned live process remains, and clean up fixture resources even on a failed assertion. Then run recorder/verifier behavioral tests, skill validation, affected CLI TIA tests, CLI project checks and documentation checks. Use TMPDIR=/tmp for local Pest subprocess fixtures because the default macOS path contains a numeric namespace segment; this does not change repository code.
5. Acquire discovery, forward-test in a Solo terminal, inspect artifacts and fix findings. Build a clean committed candidate, run the root Builder gate with TIA, and publish candidate-bound artifacts.
6. Run fresh isolated acceptance proof, verify full artifact export, release idle discovery, and request independent exact-candidate review including required Solo interactions. Complete authorized merge and snapshot closeout only after those checks pass.

## Must preserve

- ADR 0014: one maintained root documentation corpus; ADR/issue/code/evidence authority stays distinct; historical sources are reconciled rather than imported wholesale; generated context stays deterministic; valid and invalid cases protect reused rules.
- ADR 0036: only the supported App instance model and current public surface are inventoried; no retired Workspace/Instance aliases or migration tools return.
- ADR 0058: the Incus requirement remains separate from explicitly selected proof flow; discovery follows preflight and acceptance uses a separate topology.
- ADR 0071: existing vocabulary, ownership-selected verbs and absence of retired aliases remain intact; foundation adds no product command or SDK surface.
- Current command-specific JSON/NDJSON contracts, request IDs, secret handling, explicit-only inputs and existing consent/override meanings are preserved. The approved standard's new consent behavior is implemented only by its command-family owners.
- Existing independently reviewed recorder corrections remain: incremental UTF-8, owned process-group cleanup, dim SGR2/22 handling, final active-tail bounds and terminal-state evidence. Tool tests do not establish Orbit command compliance.

## Open questions

none: the promoted snapshot was restored and the subsequent hibernator convergence repair is merged. Recheck live availability before proof; never substitute another issue's resources.

## Deviations

Discovery showed that the restored Ubuntu snapshot has Python but lacks its
versioned venv package. Setup installs that package only when ensurepip is
missing, then creates the isolated environment as planned. The proof declares
`mutates: true` because this changes disposable guest packages; closeout must
refresh from merged main. No shared host package or product state is changed.

## Review findings
