---
title: "ADR 0131: Seed task workspace clones from the registered main cache store"
sidebarTitle: "0131 Seed task workspace clones from the registered store"
description: "Proposed. Every main cache publication registers its store for the repository's origin on that machine. An independent clone, such as a task workspace, seeds its test and quality caches from that store, and the review gate seeds before it checks."
---

# ADR 0131: Seed task workspace clones from the registered main cache store

A store that publishes main caches registers itself for its repository's origin in the user's state directory. An independent clone of the same origin on that machine, such as a managed task workspace, seeds its test graphs and its Pint and PHPStan caches from the registered store. The review gate seeds before it runs any check, so a task's baseline check starts warm.

## Status

Proposed.

This amends [ADR 0119](/decisions/0119-publish-main-caches-from-clean-bootstrap-runs), which accepted that independent task workspace clones do not share the main cache store and rejected clone configuration. It keeps the publication format, validation, and privacy rules of [ADR 0052](/decisions/0052-seed-worktrees-from-successful-main-test-baselines) and [ADR 0054](/decisions/0054-maintain-main-caches-asynchronously).

## Context

The Gateway provisions each task workspace as an independent clone. Before any agent starts, it runs the Project's setup steps and then root `composer check`, the review gate across all five Composer projects.

A clone has its own Git common directory, so `bin/tia-cache seed` looked in an empty store. The gate also never seeded Pint or PHPStan caches. Every baseline check therefore ran full static analysis and every test suite. On beast the first baseline for task group 58 took 12 minutes, while the primary checkout on the same machine held compatible publications from that morning.

`ORBIT_MAIN_CACHE_STORE` already lets a clone seed from another store, but nothing sets it for a clone that the Gateway creates. The Gateway runs any Project's checks and must not know one repository's cache layout.

## Decision

- Each successful graph or quality publication registers its store for the repository's origin. The registration is a symbolic link at `$XDG_STATE_HOME/orbit/main-cache-stores/{key}`, where `$XDG_STATE_HOME` defaults to `~/.local/state`. `{key}` is the SHA-256 of the origin's host and path, so HTTPS and SSH URLs of one repository share a key.
- The first live store with publications keeps the registration. Another store replaces it only when the registered store is gone, or when an operator runs `bin/tia-cache register` in the repository that is to own it.
- Seeding chooses a store in this order: `ORBIT_MAIN_CACHE_STORE`, the checkout's own store when it has publications, then the store registered for its origin. `bin/tia-cache seed` and `bin/worktree-cache` share this lookup. All existing compatibility checks still apply, and a clone's own writes stay private.
- The review gate (`bin/review-check`, root `composer check`) runs `bin/worktree-cache` and `bin/tia-cache seed` before its checks.
- When a clone seeds from a registered store whose publications lag the clone's fetched main, seeding queues a background refresh of that store. It does not queue a refresh for a project whose last refresh failed at that main commit or at a descendant of it, so a failure never becomes a retry loop.
- After each batch, the maintenance worker deletes run log directories that no recorded result or failure names.

## Rejected alternatives

- Set `ORBIT_MAIN_CACHE_STORE` in the Gateway's check environment or in the Project's setup steps: rejected because the Gateway runs checks for every Project, and a hard-coded path in Project data breaks when the primary checkout moves.
- Provision task workspaces as linked worktrees of the primary checkout: rejected because the Gateway provisions Instances over SSH and does not know about an operator's primary checkout.
- A global Git setting that names the store: rejected because it needs a manual step on every machine and silently goes stale when the checkout moves.
- Share Rector's cache the same way: rejected because Rector keys its cache on absolute file paths, so a copied cache never hits.

## Consequences

- A task workspace's baseline check runs only the tests affected since the published main and reanalyses only changed files.
- Seeding from a lagging store keeps the store moving forward as tasks start, without an orchestrator watching it. The refresh runs in the background at reduced priority and never delays the check.
- A machine with several primary checkouts of one origin shares the first one's publications until an operator registers another.
- Registration writes one symbolic link in the user's state directory. Removing the store's repository leaves a dangling link that the next publication replaces.
- Rector still runs cold in a new checkout.

## Affects

- Components: apps/e2e, apps/docs
- ADRs: amends [ADR 0119](/decisions/0119-publish-main-caches-from-clean-bootstrap-runs); keeps [ADR 0052](/decisions/0052-seed-worktrees-from-successful-main-test-baselines) and [ADR 0054](/decisions/0054-maintain-main-caches-asynchronously)
- Detail: [Feature delivery](/reference/implementation-loop#main-test-baselines)
- Verify: `apps/e2e/tests/Fixtures/tia-cache-tests.py` through `TiaCacheTest`; a baseline check on a new task workspace on beast
