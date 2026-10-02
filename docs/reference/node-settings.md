---
title: "Node settings"
description: "The apps.path setting on a Node: input, the effective apps root, validation, preparation, and failure codes."
covers:
  - apps/gateway/app/Domain/Nodes/Storage/**
  - apps/gateway/app/Actions/Nodes/UpdateNodeSettingsAction.php
  - apps/gateway/app/Infrastructure/Nodes/RemoteNodeStorageRootPreparer.php
  - apps/gateway/app/Data/Nodes/{NodeSettingsData,NodeStorageAppsData}.php
  - apps/gateway/app/Http/Requests/Nodes/UpdateNodeSettingsRequest.php
  - apps/cli/app/{Support/NodeSettingOptions,Commands/Nodes/UpdateNodeSettingsCommand}.php
  - packages/php-sdk/src/{Responses/Nodes/NodeSettings,Requests/Nodes/UpdateNodeSettingsRequest}.php
---

# Node settings

A Node has one setting: `apps.path`, the apps root under which the Gateway creates development Instance checkouts. Set it with `node:add` or `node:settings`. [`node`](/cli/node#orbit-nodesettings) lists the commands.

## Set the apps root

Both commands take repeatable `--setting=<setting-path>:<value>` options. `apps.path` is the only setting path.

```bash
orbit node:add app-dev app-dev.example --role=app-dev --setting=apps.path:/srv/orbit/apps
orbit node:settings app-dev --setting=apps.path:/mnt/apps
```

The CLI splits each option at its first colon, so a value can contain more colons. An empty value unsets the path: `--setting=apps.path:` sends null. The CLI does not trim a value. `node:settings` needs at least one `--setting` option.

`POST /api/v1/nodes` accepts an optional `settings` member:

```json
{ "settings": { "apps": { "path": "/srv/orbit/apps" } } }
```

`PATCH /api/v1/nodes/{node}/settings` takes the settings object directly, without a `settings` wrapper:

```json
{ "apps": { "path": "/mnt/apps" } }
```

The [API reference](/api/overview), [MCP](/reference/mcp), and generated web types publish `apps` as an object or null, with `path` as a string or null. Both the settings object and the nested `apps` object reject unknown fields. A patch needs the `apps` member; its `path` member is optional. `"path": null`, `"apps": null`, or `"apps": {}` removes the path. Node responses return the stored value only. They return `settings: null` when no path is set, never the default. `orbit node:show` shows the path, or an em dash when none is set.

## Derive the effective root

The apps root is `apps.path`, or `<managed-user-home>/apps` when the path is not set. The Gateway computes the default each time and does not store it.

A new development Instance checkout is `<apps-root>/<project-slug>/<instance-name>`. The Gateway stores that path on the Instance. A later change to `apps.path` never moves, rewrites, or deletes an existing checkout. [Doctor](/cli/doctor#what-each-family-checks) accepts a development checkout under the apps root or under the managed user's home.

## Validation

The Gateway checks the path before it stores it. A path must:

- be absolute and normalized: not `/`, with no trailing or repeated `/`, no `.` or `..` part, and no control character;
- not be the managed user's home or an ancestor of it;
- not overlap `/boot`, `/dev`, `/etc`, `/proc`, `/run`, `/sys`, `/usr`, `/opt/orbit`, `/var/lib/orbit`, `/var/www`, or the Gateway checkout;
- not be inside a hidden directory of the managed user's home; and
- not equal, or lie inside, an existing Instance checkout on the Node.

The Gateway then checks the path on the Node. When the Node has an active `app-dev` role, it prepares the path: it creates each missing directory, owned by the managed user and group with mode `0755`. Otherwise the path must already exist. `app-dev` convergence prepares the apps root in the same way.

An existing directory must be a real directory, not a symlink, owned by the managed user and group. The owner must have read, write, and execute access, and group and others must not have write access. The Gateway never changes the owner or mode of an existing directory. When a check fails, the stored setting stays unchanged. A directory that the Gateway created before the failure can remain.

### Caddy access

Caddy runs as its own user and must reach each development site's document root, also when the apps root lies outside the managed user's home. When a development site publishes, the Gateway adds execute access for the `caddy` user on each ancestor directory of the document root that Caddy cannot enter yet. It adds no read access to those directories, so Caddy can pass through them but cannot list them. Inside the checkout, Caddy can read only the document root and the public storage target.

## Failure codes

The CLI refuses a bad option before it sends a request.

| Code | Condition |
| --- | --- |
| `node.setting_unknown` | The setting path is not `apps.path`. |
| `node.setting_duplicate` | The same setting path appears twice. |
| `node.setting_invalid` | An option has no colon or an empty setting path. |
| `node.setting_required` | `node:settings` got no `--setting` option. |

The Gateway refuses an invalid value without changing the stored setting.

| Code | Condition |
| --- | --- |
| `node.settings_invalid` | The object has an unknown member, is not an object, or a patch has no `apps` member. |
| `node.settings_path_invalid` | The path is empty or not absolute and normalized. |
| `node.settings_path_protected` | The path overlaps a protected path. |
| `node.settings_path_managed` | The path equals or lies inside an Instance checkout. |
| `node.settings_root_failed` | The path failed the check or the preparation on the Node. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One typed setting

A generic settings map would make any key part of the public contract. A column for each setting would make each new setting a schema change. So `settings` is one closed, typed value that grows only through reviewed code. `instance.path` and `worktree.path` are unknown paths.

### Only intent is stored

Storing the computed default would go stale when the managed user's home changes. So the Gateway stores only the path you set and derives the default when it needs it.

### Settings never move checkouts

Moving source on a settings change would be a hidden, risky migration. So each checkout keeps the path it got at creation. Move or remove old checkouts with explicit commands.

### No ownership changes to existing directories

The path can be outside the managed user's home, so a broad `chown` could damage unrelated data. The Gateway only creates missing directories and refuses an existing one that does not already fit.
