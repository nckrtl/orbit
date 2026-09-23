---
title: "ADR 0119: Share caches after bootstrap"
sidebarTitle: "0119 Share bootstrap caches"
description: "Proposed. Save successful bootstrap caches for subsequent local worktrees without updating the running checkout."
---

# ADR 0119: Share caches after bootstrap

## Status

Proposed. Amends [ADR 0052](/decisions/0052-seed-worktrees-from-successful-main-test-baselines) and [ADR 0054](/decisions/0054-maintain-main-caches-asynchronously).

## Context

Bootstrap already seeds caches and runs checks. Subsequent worktrees repeat work when bootstrap keeps its refreshed caches private. Updating the running checkout to warm caches requires deployment coordination.

## Decision

Extend the existing bootstrap and cache scripts. After all checks pass, bootstrap saves its test and quality caches to the existing Git common-directory store when the checkout remains clean at the same fetched main commit. Feature changes, failed checks, custom cache directories, and `--skip-checks` do not publish.

Reuse the existing cache format, validation, maintenance lock, and atomic writes. Convert the task branch's Pest results into a main baseline using Pest's fallback rules. Preserve the graph's recorded commit. Refuse older results that would replace newer shared caches. Cache errors are reported and leave application checks successful.

The primary checkout does not need to be clean or on main. Worktree creation fetches main for the new task without advancing the primary checkout or starting duplicate background checks. Deployment owns updates to the running checkout. Background maintenance remains available separately.

## Rejected alternatives

- Update the primary checkout: this requires deployment and migration coordination.
- Publish feature results: these results do not establish a main baseline.
- Add a separate cache service or clone configuration: linked worktrees already share a local cache store.

## Consequences

Subsequent local worktrees reuse successful bootstrap results. Caches remain private during feature development. This change relies on the existing Pest graph validation and does not add a second test-report validation system. Pest must preserve binary dataset values in both worker results and graphs; Orbit pins the merged runner fix until a release includes it. Independent clones created by task workspace provisioning do not share this store or invoke bootstrap automatically.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: amends ADR 0052 and ADR 0054
- Detail: [Feature delivery reference](/reference/implementation-loop)
- Verify: cache publication and worktree creation regressions, `composer test:affected`, `composer check`
