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

- Start feature work in a worktree created by `bin/worktree-create`.
- Use `.agents/skills/creating-orbit-issues` to prepare Linear issues.
- Use `.agents/skills/developing-orbit-features` for Work and Compound.
- Use `.agents/skills/reviewing-orbit-pull-requests` for independent review.
- Use `.agents/skills/merging-orbit-pull-requests` for the final merge gate.
- Merge every governing ADR to `main` before implementation or a dependent
  workflow-contract change starts. A feature pull request must not introduce,
  modify, or rely on an unmerged governing ADR.
- The implementation agent owns Work and Compound for its pull request.
- Review is a separate agent cycle. For live proof, review the exact candidate
  after full gates and current-head CI but before rollout, then review it again
  after proof and task-resource cleanup. The same implementation agent addresses
  review comments.
- The merge agent verifies checks, post-proof approval, proof, task-resource
  absence, live-state drift, and compound learning without mutating live state.
- For live proof, inspect the registered topology read-only before changing
  code. Immediately before every rollout or other live mutation, run and inspect
  `orbit node:list --json`; a prior listing cannot authorize a later mutation.
- Shared live nodes and pre-existing resources are never removed or adopted by
  feature cleanup. The feature worker removes only recorded task-owned proof
  resources before final review. After merge, the external orchestrator verifies
  absence again before it removes the worktree.
- Incus is optional diagnostic tooling. It never gates readiness, proof, review,
  or merge.
- After merge, the external orchestrator fingerprints merged `main` and refreshes
  the stopped Incus standby only when prepared state changed. It removes the
  worktree and closes the issue only after an `unchanged` or `promoted` result.
  A `failed` refresh leaves both pending.
- Keep project-manager orchestration outside this repository. The repository
  owns role behavior and handoff contracts only.
- Candidate deployment and rollout to the registered live topology are part of
  feature development. Production release and post-deploy operations remain
  separate.

## Verification

- Run `bin/test` for all full Pest suites without TIA.
- Run the nearest project's `composer check` for changed PHP code.
- GitHub Actions runs each project as an independent matrix job.

## Durable knowledge

- Put architecture decisions in `docs/decisions`.
- Put stable operational or API reference in `docs/reference`.
- Put reusable implementation lessons in `docs/solutions`.
- Do not add a document when the change has no durable learning.
