# Orbit documentation

These pages explain what Orbit can do, how its main parts work together, and
how to use it.

If you are new to Orbit, start with the mission and architecture. Keep the
concepts page nearby for any Orbit terms you do not know yet.

## Start here

Choose a page based on what you want to learn:

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

The `apps/docs` tool checks these pages and builds an index that helps
contributors find the right information for a change.

Check the documentation from the repository root:

```bash
composer docs-lint
```

After adding a page or changing what it covers, rebuild the index:

```bash
composer docs-build
```

To find pages for a part of Orbit or an Orbit term, run:

```bash
composer docs-context -- --component=apps/gateway --concept=Cluster
```

The generated index lives at `docs/generated/context.json`. Do not edit it by
hand.
