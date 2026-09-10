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

When an external orchestrator assigns a role, finish that role and return its
handoff. Helpers may work within the assigned role's scope, but they cannot
replace its independent review or authorize the next delivery phase. The
orchestrator assigns the formal reviewers and coordinates phase transitions.

- `grilling` — interview the user until every material design branch is settled, without changing project state.
- `domain-modeling` — sharpen Orbit terms, relationships, and decision boundaries against current evidence.
- `grill-with-docs` — explicitly run both shaping disciplines and produce a confirmed handoff before issue creation.
- `resolve-pipeline-issues` — make `Blocked` and `Backlog` Linear work ready for `Todo`, or return a read-only resolution proposal for one exact issue.
- `maintaining-monorepo` — diagnose and repair main-check or cache-maintenance
  failures as one owner across all five projects, then return verified recovery
  or an independent-review handoff to the orchestrator.
- `recording-decisions` — draft, lint, and accept one architecture decision record.
- `writing-documentation` — write or change one maintained page under `docs/`.
- `auditing-documentation` — find and fix documentation drift for one issue, or for the whole corpus on request.
- `creating-issues` — synthesize confirmed shaping into current, verifiable Linear contracts, or return a blocked issue to `Backlog`.
- `planning-features` — audit and write the issue's documentation, then prepare or correct the plan for one issue.
- `reviewing-feature-plans` — independently review one issue's plan and documentation commits.
- `developing-features` — implement and prove one issue.
- `reviewing-pull-requests` — independently review one pushed head and inspect
  its exact retained proof.
- `merging-pull-requests` — deterministic merge, promotion, and cleanup steps.

## Repository rules

- Resolve the worktree's delivery flow with `bin/loop-flow status`. Read
  [implementation loop](docs/reference/implementation-loop.md) before planning,
  developing, reviewing, or closing out. Requirements below for isolated proof and snapshot closeout apply only to
  the `proof` flow. ADR 0051 governs the `discovery` alternative: preflight and
  preflight review, discovery as a development tool, code review, local quality checks, and merge;
  no proof, main-freshness, or snapshot-closeout gate. Each handoff names its flow.
- An explicit user instruction for the current task overrides any conflicting
  rule in this repository, including an agent-role skill or workflow boundary.
  It does not override system or platform safety requirements.
- Every governing product ADR must already be accepted on `origin/main`; a
  feature pull request never introduces or changes an ADR.
- Issue contracts use observable acceptance criteria and name the smallest
  affected components. New requirements become separate Linear work.
- Product feature branches never modify the harness under `apps/e2e` or
  `bin/e2e-*`; the tests under `apps/e2e/tests/Feature/**` and
  `apps/e2e/tests/Unit/**` are not harness code. Harness changes require a dedicated issue with
  repository-owner-approved behavior and issue-specific proof.
- The `incus` label requires real-machine verification; it does not select a
  delivery flow. Use discovery for labeled issues, adding a separate proof
  topology only when `proof` is explicitly selected. Automated-only issues
  without the label use local checks without a required topology. ADR 0058
  governs this distinction. Planning and review check the label against acceptance.
- New or revised feature plans use the planning template and `bin/plan-lint`.
  A completed plan handoff includes a receipt verified against its saved artifact;
  structural validation does not replace independent plan review.
- Proof plans and fixtures live locally under ignored `.loop/proof/` and are
  published with `bin/loop-artifacts` on immutable candidate-bound refs. Per-worktree harness state lives in `<worktree>/.e2e/`.
- Discovery remains the default development target while a separate fresh
  proof topology runs. Retain a failed proof for explicit unprivileged
  debugging and release it independently before the next proof.
- Proof evidence is immutable for one exact commit and issue. Never reuse proof
  resources across issues. Capture successful evidence before interactive review.
- Every proof action must exit `0`. Promotion requires the exact proved commit,
  exact proof plan, and complete zero-exit action evidence.
- In proof flow, release idle discovery before review but retain every captured
  successful proof Node. Review actions and findings stay separate from immutable
  proof evidence, and a code or configuration fix requires fresh proof.
- After the verified merge, closeout refreshes the snapshot from merged main and
  then releases the exact retained successful proof topology. A failed refresh
  keeps that topology and its evidence for retry.
- Production release is separate from development proof and never reuses a
  disposable proof topology.

## Verification

- Developers run focused Pest tests locally. Independent reviewers run root
  `composer check` across all five projects with TIA, as ADR 0053 requires.
- Use `bin/test` only for an explicit full local run or failure diagnosis.
- Run the nearest project's `composer check` for changed PHP code.
- Run `composer docs-lint` when maintained documentation changes.
- GitHub CI is disabled and is not a merge gate. Retain the reviewer's exact-head local check receipt.
- Before creating a feature worktree, fetch and fast-forward clean primary main,
  then bootstrap and seed the new worktree with `bin/worktree-create`. It queues
  cache maintenance in the background and uses compatible successful publications
  immediately. Preserve unrelated edits before advancing main.
- One maintenance owner covers all five projects. Routine warming uses repository
  scripts; `maintaining-monorepo` handles failures. Cache availability does not
  gate delivery. A failed correctness check on main holds unrelated feature
  merges until a reviewed repair or revert is verified on main.

## Durable knowledge

- Put architecture decisions in `docs/decisions`.
- Put stable operational or API reference in `docs/reference`.
- Put reusable implementation lessons in `docs/solutions`.
- Write human-facing documentation for people first. Use plain language, short
  paragraphs, and concrete examples. Keep delivery rules and internal workflow
  language in contributor guidance or ADRs.
- Do not add a document when the change has no durable project learning.
- Every issue states documentation impact with the `docs` label. The planner
  (`planning-features`, the preflight step), or the implementer when no plan
  exists, audits and writes the issue's documentation; the pull request that
  changes behavior carries the pages that describe it and lists every
  documentation change.
