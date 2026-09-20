---
title: "ADR 0025: Stabilize the default AppInstance identity"
sidebarTitle: "0025 Stabilize the default AppInstance identity"
description: "Accepted on 2026-09-05. Extends ADR 0009, ADR 0016, and ADR 0024."
---

# ADR 0025: Stabilize the default AppInstance identity

In the context of AppInstance identity derived from a mutable Git branch name, facing directory and Route churn when that branch is renamed, we decided for a stable `default` AppInstance backed by an App `default_branch` setting and against branch-named default placement, to preserve placement and routing identity across branch changes, accepting coordinated migration and source reconciliation.

## Status

Accepted on 2026-09-05. Extends [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0016](/decisions/0016-reconcile-app-identity-and-source-default-updates), and [ADR 0024](/decisions/0024-follow-generated-route-targets). Supersedes ADR 0009 for default AppInstance naming and generated hostname shape, and ADR 0016 for App branch terminology and prospective-only default-branch changes. Amended by [ADR 0105](/decisions/0105-name-applications-as-project-and-instance): the reserved identity remains `default`, and that Instance tracks the Project `default_branch`.

## Context

ADR 0009 uses the App's configured main branch as both a Git source default and the special AppInstance name that receives an unprefixed generated Route. ADR 0016 lets that branch setting change but leaves existing AppInstances unchanged. A repository can rename its default branch without changing the stable placement that represents the App's default development source.

## Decision

- A Project owns one `default_branch` source setting.
- Orbit must name the Instance that represents a Project's default development source `default`.
- Orbit must treat `default` as the reserved identity for that Instance role. The Instance tracks `project.default_branch` when it inherits that setting.
- Orbit must generate an unprefixed development Route hostname only for the `default` AppInstance.
- Orbit must use every other AppInstance name as the generated development Route hostname prefix.
- Orbit must keep the `default` AppInstance name, placement, and Route identity stable when `default_branch` changes.
- Orbit must reconcile an existing `default` checkout or worktree to a changed `default_branch` before the new setting becomes authoritative.
- Orbit must refuse a `default_branch` change when the affected source cannot switch coherently.
- Orbit must use `default_branch` as the fallback source when it creates a non-default AppInstance without a matching remote branch.
- Orbit must migrate the AppInstance identified as default by the earlier branch-name rule to the `default` identity without changing its Route identity.
- Orbit must not overwrite an existing `default` identity or placement during migration.

## Rejected alternatives

- Keep the default AppInstance named after the configured branch: rejected because an ordinary default-branch rename would continue to change durable placement identity.
- Keep existing default source on its old branch after `default_branch` changes: rejected because the `default` AppInstance would no longer represent the App's configured default source.
- Preserve `main_branch` as an alias: rejected because two public names for one source setting would make the replacement contract ambiguous.

## Consequences

- The default AppInstance keeps one name, path identity, and generated Route while its Git branch can change.
- `default` cannot be used as an ordinary non-default AppInstance name.
- Existing App, AppInstance, Route, API, SDK, CLI, and documentation state needs a coordinated migration.
- A dirty, unpublished, missing, or conflicting default source can prevent a default-branch update or migration.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0016](/decisions/0016-reconcile-app-identity-and-source-default-updates), and [ADR 0024](/decisions/0024-follow-generated-route-targets); supersedes ADR 0009 for default AppInstance naming and generated hostname shape, and ADR 0016 for App branch terminology and prospective-only default-branch changes
- Detail: docs/domains/applications.md
- Verify: `bin/test`
