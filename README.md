# Orbit

Orbit is one Git repository with five independently installable PHP projects.
The repository keeps their release boundaries and Composer lock files separate.

```text
apps/cli            Laravel Zero client
apps/docs           Documentation linter and context generator
apps/e2e            Incus proof harness
apps/gateway        Laravel control plane
packages/php-sdk    Framework-neutral PHP SDK
```

## Bootstrap

Use PHP 8.5 and Composer 2, then install all projects in parallel:

```bash
bin/bootstrap
```

The equivalent Composer command is `composer bootstrap`.

Run focused Pest tests and the changed project's `composer check` locally. CI
runs all full suites. For an explicit full local run:

```bash
bin/test
```

The equivalent Composer command is `composer test`.

Each project keeps its own `AGENTS.md`, quality commands, and release contract.
Read the nearest guidance file before changing a project.

The maintained documentation corpus stays under root `docs/`. Run
`composer docs-lint` to verify it, `composer docs-build` to update its committed
context index, and `composer docs-context` to select an ordered reading set.

The former standalone repositories are history snapshots. This repository is
the source of truth. Add an explicit split or artifact workflow before the next
standalone package publication; do not maintain the old repositories by hand.

## Feature work

Feature branches use one repository worktree. A worktree contains the CLI,
Gateway, SDK, and E2E harness at one commit, so cross-project changes stay
atomic.

```bash
bin/worktree-create NCK-123 concise-feature-name --flow=discovery
```

The command creates `.worktrees/nck-123-concise-feature-name` on branch
`nck-123-concise-feature-name`, initializes an ignored `.loop/plan.md` beside
`.loop/proof/`, and bootstraps all projects. Publish the workspace with
`bin/loop-artifacts publish NCK-123` after committing the candidate. Review and
merge that same candidate. See [the implementation loop](docs/reference/implementation-loop.md).

Repository-owned skills under `.agents/skills/` are independently invokable
helpers for decision records, issue creation, documentation writing and auditing, planning, implementation, review, and merge
operations. They are optional task guides for contributors and coding agents.
