# Orbit Monorepo

This repository contains the Orbit CLI, Gateway, PHP SDK, and Incus E2E
harness.

## Scope

- Read the nearest nested `AGENTS.md` before changing a project.
- Keep the CLI, Gateway, SDK, and E2E harness as separate Composer projects.
- Use root commands only to coordinate projects.
- Linear owns feature scope, acceptance criteria, the ADR decision, and the
  proof venue. The worktree-local `.orbit/plan.md` is only an ephemeral
  implementation map and is never committed.

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

- Every governing ADR is on `main` before implementation starts; a feature
  PR never introduces or changes an ADR.
- Implementation does not begin until `.orbit/plan.md` has `Verdict: PASS`.
  Tom may run one fresh planner correction and one fresh reviewer pass after a
  `FIX`; another `FIX` or any `BLOCK` returns the issue for clarification.
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
