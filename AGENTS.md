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
- Use `.agents/skills/developing-orbit-features` for Work and Compound. It
  encodes the 14-step topology-led flow from
  `docs/reference/development-workflow.md`.
- Use `.agents/skills/reviewing-orbit-pull-requests` for independent review.
- Use `.agents/skills/merging-orbit-pull-requests` for the final merge gate.
- Merge every governing ADR to `main` before implementation or a dependent
  workflow-contract change starts. A feature pull request must not introduce,
  modify, or rely on an unmerged governing ADR.
- The implementation agent owns Work and Compound for its pull request.
- Incus proof follows ADR 0006: discovery on one disposable topology, verified
  release, then one-shot proof of the exact candidate on a fresh topology. The
  agent opens a normal pull request only after proof succeeds.
- Review is a separate agent cycle. CI and review start when the pull request
  opens and run in parallel. The same implementation agent addresses review
  comments and proves each corrected candidate again.
- The merge agent verifies passing current-head CI, approval for the current
  head, the active proved attempt for that head, acceptance results, Compound,
  and post-deployment actions without mutating any topology.
- After merge, the external project manager releases the proof topology and
  verifies exact absence, runs the standby refresh only when the prepared-state
  fingerprint changed, removes the worktree, and closes the issue. A failed
  refresh keeps the worktree and issue open and does not revert merged code.
- Keep project-manager orchestration outside this repository. The repository
  owns role behavior and handoff contracts only.
- Production release and `post_deployment_actions` remain a separate
  operations process. Production never reuses a proof topology.

## Verification

- Run `bin/test` for all full Pest suites without TIA.
- Run the nearest project's `composer check` for changed PHP code.
- GitHub Actions runs each project as an independent matrix job.

## Durable knowledge

- Put architecture decisions in `docs/decisions`.
- Put stable operational or API reference in `docs/reference`.
- Put reusable implementation lessons in `docs/solutions`.
- Do not add a document when the change has no durable learning.
