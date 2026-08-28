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
- Merge a required ADR to `main` before dependent issues become Ready.
- The implementation agent owns Work and Compound for its pull request.
- Review is a separate agent cycle. The same implementation agent addresses
  review comments.
- The merge agent verifies checks, approval, proof, and compound learning.
- The project-manager agent cleans any disposable Incus topology before it
  removes the worktree. It releases Incus first, then the worktree, and closes
  only after unchanged or promoted standby refresh.
- Keep project-manager orchestration outside this repository. The repository
  owns role behavior and handoff contracts only.
- Deployment and post-deploy verification are outside this repository cycle.

## Verification

- Run `bin/test` for all full Pest suites without TIA.
- Run the nearest project's `composer check` for changed PHP code.
- GitHub Actions runs each project as an independent matrix job.

## Durable knowledge

- Put architecture decisions in `docs/decisions`.
- Put stable operational or API reference in `docs/reference`.
- Put reusable implementation lessons in `docs/solutions`.
- Do not add a document when the change has no durable learning.
