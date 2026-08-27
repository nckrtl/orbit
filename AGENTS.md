# Orbit Monorepo

This repository contains the Orbit CLI, Gateway, and PHP SDK.

## Scope

- Read the nearest nested `AGENTS.md` before changing a project.
- Keep the CLI, Gateway, and SDK as separate Composer projects.
- Use root commands only to coordinate projects.
- Do not add a repository feature-plan document. Linear owns feature scope,
  acceptance criteria, the ADR decision, and the proof venue.

## Workflow

- Start feature work in a worktree created by `bin/worktree-create`.
- Use `.agents/skills/creating-orbit-issues` to prepare Linear issues.
- Merge a required ADR to `main` before dependent issues become Ready.
- The implementation agent owns Work and Compound for its pull request.
- Review is a separate agent cycle. The same implementation agent addresses
  review comments.
- The merge agent verifies checks, approval, proof, and compound learning.
- The project-manager agent cleans any disposable Incus topology before it
  removes the worktree.
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
