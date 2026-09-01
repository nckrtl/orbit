# Orbit Documentation

Welcome to the Orbit documentation. These pages explain what Orbit does, how
its main parts fit together, and how to work with it.

If you are new to Orbit, start with the mission and architecture. You can then
use the concepts page whenever you meet an unfamiliar Orbit term.

## Start here

- [Mission](mission.md) explains why Orbit exists and what it is trying to
  make easier.
- [Architecture](architecture.md) shows how the CLI, Gateway, and managed
  machines work together.
- [Tech stack](tech-stack.md) lists the main tools and technologies used by
  Orbit.
- [Concepts](concepts.md) gives short explanations of common Orbit terms.
- [Product areas](domains/README.md) groups feature documentation as it grows.
- [Decisions](decisions/README.md) keeps the history behind important design
  choices.
- [Reference](reference/) contains detailed operational information.
- [Solutions](solutions/README.md) collects useful fixes and lessons from past
  work.

## Keeping the docs up to date

Orbit includes a small documentation tool in `apps/docs`. It checks the docs
and builds an index that helps contributors find the right pages for a change.

Check the documentation from the repository root:

```bash
composer docs-lint
```

Rebuild the index after adding a page or changing what a page covers:

```bash
composer docs-build
```

Find documentation for a part of Orbit or a product concept:

```bash
composer docs-context -- --component=apps/gateway --concept=Cluster
```

The generated index lives at `docs/generated/context.json`. Do not edit it by
hand.
