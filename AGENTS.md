# Orbit Monorepo

This repository contains the Orbit CLI, Gateway, documentation tooling, PHP
SDK, and Incus E2E harness.

## Scope

- Read the nearest nested `AGENTS.md` before changing a project.
- Keep the CLI, Gateway, Docs, SDK, and E2E harness as separate Composer
  projects.
- Keep maintained documentation under root `docs/`; `apps/docs` owns only its
  console tooling, generators, rules, and tests.
- Use root commands only to coordinate project checks and repository tooling.
- Accepted repository ADRs own product architecture and durable technical
  boundaries. Linear issues own requested outcomes, scope, acceptance criteria,
  relationships, affected components, and proof requirements.

## Independent agent-role skills

The skills under `.agents/skills/` are standalone task guides. A contributor may
invoke any one directly; no private orchestration order is implied.

- `creating-issues` — refine a request into a current, verifiable Linear contract.
- `planning-features` — prepare or correct a concise Feature plan.
- `reviewing-feature-plans` — independently review a Feature plan.
- `developing-features` — implement and prove one issue.
- `reviewing-pull-requests` — independently review one pushed head and inspect
  its exact retained proof.
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
- Discovery remains the default development target while a separate fresh
  proof topology runs. Retain a failed proof for explicit unprivileged
  debugging and release it independently before the next proof.
- A proved topology is immutable evidence for one exact commit and issue. Never
  reuse proof resources across issues.
- Every proof action must exit `0`. Promotion requires the exact proved commit,
  exact proof plan, and complete zero-exit action evidence.
- Promotion releases both the successful proof topology and retained discovery
  topology after replacing the promoted topology snapshot.
- Production release is separate from development proof and never reuses a
  disposable proof topology.

## Verification

- Run `bin/test` for all full Pest suites without TIA.
- Run the nearest project's `composer check` for changed PHP code.
- Run `composer docs-lint` when maintained documentation changes.
- GitHub Actions runs each project as an independent matrix job.

## Durable knowledge

- Put architecture decisions in `docs/decisions`.
- Put stable operational or API reference in `docs/reference`.
- Put reusable implementation lessons in `docs/solutions`.
- Write human-facing documentation for people first. Use plain language, short
  paragraphs, and concrete examples. Keep delivery rules and internal workflow
  language in contributor guidance or ADRs.
- Do not add a document when the change has no durable project learning.
- Every implementation contract classifies documentation impact as `required`
  or `none` with a rationale. Reconcile required documentation in the same pull
  request as the behavior it describes.
