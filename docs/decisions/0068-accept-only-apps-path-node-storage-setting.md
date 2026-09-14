---
title: "ADR 0068: Accept only apps.path as the node storage setting path"
sidebarTitle: "0068 Accept only apps.path as the node storage setting path"
description: "Accepted on 2026-09-13. Extends ADR 0008."
---

# ADR 0068: Accept only apps.path as the node storage setting path

In the context of closed node storage settings after one apps root replaced instance and worktree roots, facing operators who send `instance.path` or `worktree.path`, we decided for the single known setting path `apps.path` and against accepting those other keys, to keep one vocabulary with the shipped public NodeSettings shape, accepting that those other keys fail as unknown.

## Status

Accepted on 2026-09-13. Extends [ADR 0008](0008-typed-app-dev-node-storage-settings.md). Supersedes [ADR 0008](0008-typed-app-dev-node-storage-settings.md) for the known CLI setting paths `instance.path` and `worktree.path`. Extends [ADR 0009](0009-clustered-app-instance-routing.md) for the single apps-root setting.

## Context

Orbit stores one typed apps-root setting on a Node and derives new AppInstance checkouts from that root. [ADR 0008](0008-typed-app-dev-node-storage-settings.md) closed the settings key set and named `instance.path` and `worktree.path` as the CLI setting paths. [ADR 0009](0009-clustered-app-instance-routing.md) replaced those two roots with one apps root and left ADR 0008's text unchanged. Operators who send the named ADR 0008 paths receive `node.setting_unknown`, while `apps.path` is the path the CLI, Gateway, and PHP SDK accept.

## Decision

- The CLI must accept only `apps.path` as a node storage setting path.
- The CLI must reject `instance.path` and `worktree.path` as unknown setting paths.
- The Gateway must accept only the `apps` member in public NodeSettings requests.
- The PHP SDK must transport only the `apps` member in NodeSettings requests and responses.
- The Gateway may retain stored `instance` and `worktree` values that are not part of the public contract.

## Rejected alternatives

- Restore `instance.path` and `worktree.path` as accepted CLI keys: rejected because ADR 0009 closed the Node to one apps-root setting.
- Accept both vocabularies: rejected because a closed key set cannot expose two names for one member.
- Keep documenting `instance.path` and `worktree.path` as the known paths: rejected because operators who follow that text receive `node.setting_unknown`.

## Consequences

- Operators set the apps root with one setting path that matches the public JSON member.
- Stored instance and worktree values remain outside the public contract and have no CLI write path.
- A reader of ADR 0008 must apply this record for the known setting-path names.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0008](0008-typed-app-dev-node-storage-settings.md); supersedes [ADR 0008](0008-typed-app-dev-node-storage-settings.md) for the known CLI setting paths; extends [ADR 0009](0009-clustered-app-instance-routing.md)
- Detail: [Node settings](../reference/node-settings.md)
- Verify: `composer docs-lint`; CLI unknown-setting command tests
