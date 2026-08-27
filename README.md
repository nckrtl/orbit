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
Gateway, and SDK at one commit, so cross-project changes stay atomic.

```bash
bin/worktree-create ORB-123 concise-feature-name
```

The command creates `.worktrees/orb-123-concise-feature-name` on branch
`orb-123-concise-feature-name` and bootstraps all projects.

See [the development workflow](docs/reference/development-workflow.md) for the
Linear, Slack, pull request, review, compound, and cleanup contracts.
