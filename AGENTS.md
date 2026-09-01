# Orbit Monorepo

This repository contains the Orbit CLI, Gateway, PHP SDK, and Incus E2E
harness.

## Scope

- Read the nearest nested `AGENTS.md` before changing a project.
- Keep the CLI, Gateway, SDK, and E2E harness as separate Composer projects.
- Use root commands only to coordinate project checks and repository tooling.
- Accepted repository ADRs own product architecture and durable technical
  boundaries. Linear issues own requested outcomes, scope, acceptance criteria,
  relationships, affected components, and proof requirements.

## Independent agent-role skills

The skills under `.agents/skills/` are standalone task guides. A contributor may
invoke any one directly; no private orchestration order is implied.

- `creating-issues` — refine a request into a current, verifiable Linear contract.
- `planning-features` — let a Builder prepare or correct a concise Feature plan.
- `reviewing-feature-plans` — independently review a Feature plan.
- `developing-features` — implement and prove one issue, optionally continuing
  in the same Builder context after plan approval.
- `reviewing-pull-requests` — independently review and re-prove one pushed head.
- `merging-pull-requests` — deterministic merge, promotion, and cleanup steps.

## Repository rules

- Every governing product ADR must already be accepted on `origin/main`; a
  feature pull request never introduces or changes an ADR.
- Issue contracts use observable acceptance criteria and name the smallest
  affected components. New requirements become separate Linear work.
- Product feature branches never modify the harness under `apps/e2e` or
  `bin/e2e-*`. Harness changes are dedicated `apps/e2e` issues.
- `Proof: incus` uses the repository's disposable topology and proof commands.
  Automated-only changes use project checks and CI.
- Proof plans live in `proofs/<ISSUE>.json`; optional fixtures live in
  `proofs/<ISSUE>/`. Per-worktree harness state lives in `<worktree>/.e2e/`.
- A proved topology is immutable evidence for one exact commit and issue. Never
  reuse proof resources across issues.
- Production release is separate from development proof and never reuses a
  disposable proof topology.

## Verification

- Run `bin/test` for all full Pest suites without TIA.
- Run the nearest project's `composer check` for changed PHP code.
- GitHub Actions runs each project as an independent matrix job.

## Durable knowledge

- Put architecture decisions in `docs/decisions`.
- Put stable operational or API reference in `docs/reference`.
- Put reusable implementation lessons in `docs/solutions`.
- Do not add a document when the change has no durable project learning.
