# Orbit

Orbit is one Git repository with three independently installable PHP projects.
The repository keeps their release boundaries and Composer lock files separate.

```text
apps/cli            Laravel Zero client
apps/gateway        Laravel control plane
packages/php-sdk    Framework-neutral PHP SDK
```

## Bootstrap

Use PHP 8.5 and Composer 2, then install all projects in parallel:

```bash
bin/bootstrap
```

The equivalent Composer command is `composer bootstrap`.

Run every full test suite in parallel:

```bash
bin/test
```

The equivalent Composer command is `composer test`.

Each project keeps its own `AGENTS.md`, quality commands, and release contract.
Read the nearest guidance file before changing a project.

The former standalone repositories are history snapshots. This repository is
the source of truth. Add an explicit split or artifact workflow before the next
standalone package publication; do not maintain the old repositories by hand.

## Feature work

Feature branches use one repository worktree. A worktree contains the CLI,
Gateway, SDK, and E2E harness at one commit, so cross-project changes stay
atomic.

```bash
bin/worktree-create NCK-123 concise-feature-name
```

The command creates `.worktrees/nck-123-concise-feature-name` on branch
`nck-123-concise-feature-name`, initializes a gitignored `.orbit/plan.md`, and
bootstraps all projects.

Repository-owned skills under `.agents/skills/` are independently invokable
helpers for issue creation, planning, implementation, review, and merge
operations. They are optional task guides for contributors and coding agents.
