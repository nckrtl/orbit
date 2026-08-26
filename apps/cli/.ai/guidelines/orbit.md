# Orbit CLI Guidelines

## Context

- This repository is a Laravel Zero 13 client, not a web application.
- Confirm installed package versions before using version-specific APIs.
- Follow existing file structure and sibling conventions. Do not add a dependency or a new top-level directory without approval.

## Skills

- Activate the relevant skill from `.agents/skills` before work in that domain.
- Every named skill must have a readable `SKILL.md`. Treat a missing skill as an incomplete Boost bootstrap and use the Required Guidance Bootstrap recovery steps.

## Verification

- Use focused Pest 5 tests during development and Test Impact Analysis through `composer test`.
- Run `composer check` and `composer test:full` before delivery. Mago and Rector are the configured PHP quality tools.
