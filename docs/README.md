# Orbit documentation

These pages explain what Orbit can do, how its main parts work together, and
how to use it.

If you are new to Orbit, start with the mission and architecture. Keep the
concepts page nearby for any Orbit terms you do not know yet.

## Start here

Choose a page based on what you want to learn:

- [Mission](/mission) explains why Orbit exists and what it is trying to
  make easier.
- [Architecture](/architecture) shows how the CLI, Gateway, and managed
  machines work together.
- [CLI command vocabulary](/reference/cli-command-vocabulary) lists the verbs
  every command uses and how ownership selects create and destroy or add and
  remove.
- [Tech stack](/tech-stack) lists the main tools and technologies used by
  Orbit.
- [Concepts](/concepts) gives short explanations of common Orbit terms.
- [Product areas](/domains/README) groups feature documentation as it grows.
- [Decisions](/decisions/README) keeps the history behind important design
  choices.

## Reference pages

These pages explain commands and managed services.

- [Private DNS](/reference/private-dns) describes managed resolver selection, repair, and recovery.
- [Local resolver overrides](/reference/local-resolver-overrides) describes caller-local macOS TLD and exact private Route DNS overrides.
- Application pages describe [Apps](/reference/apps), [cloning](/reference/appinstance-cloning), [transfer](/reference/appinstance-transfer), [environment variables](/reference/environment-variables), [process and Schedule definitions](/reference/app-processes-and-schedules), and [removal](/reference/appinstance-removal).
- Runtime reference pages describe the [production release layout](/reference/deployments), [PHP runtime defaults](/reference/php-runtime), [Routes](/reference/routes), [Schedules](/reference/schedules), and [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation).
- Infrastructure reference pages describe [Gateway trust](/reference/gateway-trust), [Herdr sessions](/reference/herdr-sessions), the [Database role](/reference/database-role), the [Metrics role](/reference/metrics), [Node provisioning](/reference/node-provisioning), [Node retarget](/reference/node-retarget), [Node settings](/reference/node-settings), [Tools](/reference/tools), and [WireGuard endpoints](/reference/wireguard-endpoints).
- [Database connections](/reference/database-connections) describes the Gateway-owned mysql, pgsql, and sqlite registry and how an operator adds a connection on an App instance.

## Contributor guides

These pages help contributors design, verify, and maintain Orbit.

- [CLI design standard](/reference/cli-ux) guides command authors and reviewers through input, output, consent, terminal behavior, and verification.
- Development reference pages describe the [Incus topology registry](/reference/incus-topologies), [Proof plans](/reference/proof-plans), and the [Topology snapshot](/reference/topology-snapshot).
- [Solutions](/solutions/README) collects useful fixes and lessons from past
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
