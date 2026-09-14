---
title: "Orbit documentation"
description: "A map of the maintained documentation corpus and the commands that keep it consistent."
---

# Orbit documentation

These pages explain what Orbit can do, how its main parts work together, and
how to use it.

The same pages publish as the Orbit documentation site through Mintlify. `docs.json` holds the site navigation and theme, `style.css` holds the site styling, and the pages under `cli/` and `api/` exist for the site only. `openapi.json` describes the Gateway API for the site's API tab; `composer docs-openapi` regenerates it from the Gateway routes, form requests, data classes, and the PHP SDK.

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
- [Using the CLI](cli/overview.mdx) and the pages beside it document every `orbit` command family with each argument and option.
- [Product areas](domains/README.md) groups feature documentation as it grows.
- [Decisions](decisions/README.md) keeps the history behind important design
  choices.
- [Private DNS](reference/private-dns.md) describes managed resolver selection, repair, and recovery.
- Application reference pages describe [Apps](reference/apps.md), [AppInstance cloning](reference/appinstance-cloning.md), [AppInstance environment variables](reference/environment-variables.md), [App process and Schedule definitions and copies](reference/app-processes-and-schedules.md), and [AppInstance removal](reference/appinstance-removal.md).
- Runtime reference pages describe the [production release layout](reference/deployments.md), [PHP runtime defaults](reference/php-runtime.md), [Routes](reference/routes.md), [Schedules](reference/schedules.md), and [App-dev runtime hibernation](reference/app-dev-runtime-hibernation.md).
- Infrastructure reference pages describe [Gateway trust](reference/gateway-trust.md), [Herdr sessions](reference/herdr-sessions.md), the [Database role](reference/database-role.md), the [Metrics role](reference/metrics.md), [Node provisioning](reference/node-provisioning.md), [Node retarget](reference/node-retarget.md), [Node settings](reference/node-settings.md), [Tools](reference/tools.md), and [WireGuard endpoints](reference/wireguard-endpoints.md).
- [Database connections](reference/database-connections.md) describes the Gateway-owned mysql, pgsql, and sqlite registry and how an operator adds a connection on an AppInstance.
- Development reference pages describe the [Incus topology registry](reference/incus-topologies.md), [Proof plans](reference/proof-plans.md), and the [Topology snapshot](reference/topology-snapshot.md).
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
