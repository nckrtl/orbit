# Orbit Monorepo

This repository contains the Orbit CLI, Gateway, PHP SDK, and Incus E2E
harness.

## Scope

- Read the nearest nested `AGENTS.md` before changing a project.
- Keep the CLI, Gateway, SDK, and E2E harness as separate Composer projects.
- Use root commands only to coordinate projects.
- Accepted repository ADRs own architectural decisions. Linear owns
  implementation outcomes, scope, acceptance criteria, relationships, and the
  proof venue. The worktree-local `.orbit/plan.md` is only an ephemeral
  post-claim implementation map and is never committed.

## Workflow

Every change follows the nine-step flow in
`docs/reference/development-workflow.md`: Linear issue → worktree and reviewed
preflight → fresh topology → get it right → codify → prove fresh → pull request
→ review → merge. The skills carry the exact commands:

- `.agents/skills/creating-issues` — write the Linear issue.
- `.agents/skills/planning-features` — prepare the worktree preflight.
- `.agents/skills/reviewing-feature-plans` — independently pass, fix, or block it.
- `.agents/skills/developing-features` — implement the passed plan and open the PR.
- `.agents/skills/reviewing-pull-requests` — re-prove and approve.
- `.agents/skills/merging-pull-requests` — merge, promote, clean up.

Rules that hold everywhere:

- `Backlog` records rough work that still needs refinement. `Todo` is the
  ordered queue of refined, proof-feasible issues. Tom selects only an eligible
  `Todo`, moves it to `In Progress`, verifies the transition, and only then
  starts or resumes its worktree, Herdr workspace, and preflight. A dependency
  that is not `Done` makes a Todo issue temporarily ineligible.
- Every governing ADR is accepted on `origin/main` before implementation
  issues are derived; a feature PR never introduces or changes an ADR.
- Before admitting any issue to `Todo`, refine its complete issue set against
  current `main`: inspect relevant product and harness code, close product and
  proof decisions, and make the dependency graph explicit and acyclic.
  Refined dependents may wait in `Todo`; Tom skips them until every prerequisite
  is `Done` and present on current `origin/main`.
- Implementation does not begin until `.orbit/plan.md` has `Verdict: PASS`.
  Every `FIX` starts a fresh correction planner and then a fresh independent
  reviewer. Repeat until `PASS` or `BLOCK`; only `BLOCK` stops preflight.
- `PASS` is expected for a correctly refined issue. `PASS` and `FIX` keep the
  active issue `In Progress`; `FIX` corrects the temporary implementation map.
  `BLOCK` parks the retained worktree and Herdr assets, moves the issue to
  `Blocked`, and requires a Linear comment with the blocker, evidence,
  resolution owner, retained assets, and restart condition. `Blocked` does not
  consume the three-slot execution limit. After resolution it returns to
  `Todo`; on re-selection Tom synchronizes current `origin/main` and starts a
  wholly fresh preflight before implementation resumes.
- One implementer owns the complete feature and its corrections. It may use
  bounded subagents, but there is no agent-per-increment requirement.
- Feature branches never modify the harness (`apps/e2e`, `bin/e2e-*`).
  Harness changes are their own issues.
- Proof plans live at `proofs/<ISSUE>.json`. Per-issue harness state lives
  in `<worktree>/.e2e/` and dies with the worktree; the promoted standby
  generation lives in `<primary>/.e2e/standby/` until the next promote.
- Review findings are defects against the issue, ADRs, existing invariants,
  tests, or repository rules. New requirements become separate Linear work.
- The repository owns agent behavior and the commands; orchestration of
  agents stays outside it.
- Production release is a separate process and never reuses a proof topology.

## Verification

- Run `bin/test` for all full Pest suites without TIA.
- Run the nearest project's `composer check` for changed PHP code.
- GitHub Actions runs each project as an independent matrix job.

## Durable knowledge

- Put architecture decisions in `docs/decisions`.
- Put stable operational or API reference in `docs/reference`.
- Put reusable implementation lessons in `docs/solutions`.
- Do not add a document when the change has no durable learning.
