---
title: "ADR 0075: Prune idle app-dev checkout dependencies"
sidebarTitle: "0075 Prune idle app-dev checkout dependencies"
description: "Accepted on 2026-09-14. Extends ADR 0074."
---

# ADR 0075: Prune idle app-dev checkout dependencies

In the context of hibernated development AppInstances that keep reconstructable vendor and node_modules trees on disk, facing wasted Node storage after long idle, we decided for a second idle tier that deletes those reconstructable checkout dependencies and restores them before Process start on the next HTTP wake, and against pruning while a keep-alive Process is desired running or splitting wake pages by tier, to reclaim disk without changing the HTTP wake contract, accepting that a cold first request waits for dependency restore.

## Status

Accepted on 2026-09-14. Extends [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes).

## Context

ADR 0074 stops idle app-dev AppInstance Processes and starts the desired-running group on the next HTTP request. Those checkouts still hold Composer and JavaScript dependency trees that lockfiles can rebuild. A keep-alive Process may still be running after Process halt, and that Process can require the trees that remain on disk. Caddy already intercepts a sleeping site with one progress page and one failed page.

## Decision

- The Gateway must delete reconstructable AppInstance checkout dependencies after a configured idle window that is longer than the Process halt window, and only while that AppInstance is already hibernated.
- The Gateway must not delete those dependencies when any keep-alive Process on that AppInstance is desired running.
- The Gateway must restore missing reconstructable dependencies before it starts Processes when it wakes a cold AppInstance.
- The Gateway must not write the awake marker until restore and Process readiness succeed.
- The Gateway must leave lockfiles in place when it deletes reconstructable dependency directories.
- The Gateway must keep the same wake and failed pages for Process halt and dependency restore.
- The Gateway must not apply dependency prune or restore to Node Processes, production AppInstances, PHP-FPM, or Schedules.
- The Gateway owns the durable cold marker; Caddy does not read it.

## Rejected alternatives

- Split wake or failed pages by idle tier: rejected because Caddy already owns one intercept contract and the operator sees the same progress or retry page.
- Prune while a keep-alive Process is desired running: rejected because that Process can still need the checkout dependency trees after Process halt.
- Treat cache or software-bill-of-materials inventory as reconstructable: rejected because those trees are not rebuilt from the lockfiles this record binds.
- Shorten or replace the Process halt window: rejected because ADR 0074 already owns that idle contract.

## Consequences

- A first HTTP request after a long idle waits for dependency restore before Processes start.
- A failed restore keeps the cold marker and shows the same failed page as a failed Process start.
- Operators who keep a Process running through idle halt also keep that AppInstance's reconstructable dependency trees.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes)
- Detail: [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation)
- Verify: `composer docs-lint`; Gateway hibernation prune-gate and cold-wake tests
