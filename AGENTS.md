# Orbit Monorepo

This repository contains the Orbit CLI, Gateway, PHP SDK, and Incus E2E
harness.

## Scope

- Read the nearest nested `AGENTS.md` before changing a project.
- Keep the CLI, Gateway, SDK, and E2E harness as separate Composer projects.
- Use root commands only to coordinate projects.
- Do not add a repository feature-plan document. Linear owns feature scope,
  acceptance criteria, the ADR decision, and the proof venue.

## Workflow

Every change follows the nine-step flow in
`docs/reference/development-workflow.md`: Linear issue → worktree → fresh
topology → get it right → codify → prove fresh → pull request → review →
merge. The skills carry the exact commands:

- `.agents/skills/creating-issues` — write the Linear issue.
- `.agents/skills/developing-features` — implement it and open the PR.
- `.agents/skills/reviewing-pull-requests` — re-prove and approve.
- `.agents/skills/merging-pull-requests` — merge, promote, clean up.

Rules that hold everywhere:

- Every governing ADR is on `main` before implementation starts; a feature
  PR never introduces or changes an ADR.
- Feature branches never modify the harness (`apps/e2e`, `bin/e2e-*`).
  Harness changes are their own issues.
- Proof plans live at `proofs/<ISSUE>.json`. Per-issue harness state lives
  in `<worktree>/.e2e/` and dies with the worktree; the promoted standby
  generation lives in `<primary>/.e2e/standby/` until the next promote.
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
