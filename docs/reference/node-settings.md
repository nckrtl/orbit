# Node settings

This reference is for operators who set the apps-root storage path on a Node and need the accepted setting path, inputs, outputs, and failure codes.

A Node owns one typed apps-root setting. [ADR 0008](/decisions/0008-typed-app-dev-node-storage-settings) closes the settings contract, [ADR 0009](/decisions/0009-clustered-app-instance-routing) defines the single apps root, and [ADR 0068](/decisions/0068-accept-only-apps-path-node-storage-setting) names the public setting path.

## Set the apps root

`node:add` and `node:settings` accept repeatable `--setting=<setting-path>:<value>` options. The only known setting path is `apps.path`.

```bash
orbit node:add app-dev app-dev.example --role=app-dev --setting=apps.path:/srv/orbit/apps
orbit node:settings app-dev --setting=apps.path:/mnt/apps
```

The CLI splits each option at its first colon, so a value may contain additional colons. An empty value is the unset form: `--setting=apps.path:` sends a null apps path. The CLI does not trim or otherwise reinterpret a non-empty path.

`node:settings` requires at least one `--setting` option. `node:add` may omit `--setting` and then provisions the Node without a storage override.

The known setting path has this result.

| Setting path | Result |
| --- | --- |
| `apps.path` | Sets or unsets the Node apps root |

The Gateway and PHP SDK use the same public JSON shape.

```json
{
  "settings": {
    "apps": {"path": "/srv/orbit/apps"}
  }
}
```

`POST /api/v1/nodes` accepts an optional `settings` member with that complete nullable shape. An absent or null member provisions the Node without a storage override. `PATCH /api/v1/nodes/{node}/settings` accepts a partial object that must contain the `apps` member. A nested object with `path: null`, or an explicit null `apps` member, removes the override. An empty string `path` is invalid.

Node responses return the raw apps override through the same shape. They return `settings: null` when no apps override exists and never replace a null with an effective default. `orbit node:show <id> --json` exposes that raw member.

## Derive the effective root

When a checkout is created, the Gateway resolves the effective apps root as `settings.apps.path`, or a stored instance path when that override is absent, or `<managed-user-home>/apps` when neither exists.

A new App instance checkout is `<apps-root>/<app-slug>/<instance-name>`. [Applications](/domains/applications) owns placement and identity. The Gateway records that path on the App instance and does not move, rewrite, or delete an existing checkout when the Node setting changes.

## Validate before the root becomes stored

The Gateway validates every configured path at the API boundary and again on the target Node before it persists a provisioning or settings mutation. [ADR 0008](/decisions/0008-typed-app-dev-node-storage-settings) owns the path, overlap, protected-path, and preparation rules. A failed mutation leaves stored settings unchanged.

## Failure codes

The CLI rejects unknown, duplicate, and malformed `--setting` options before it sends a request.

| Code | Condition |
| --- | --- |
| `node.setting_unknown` | The setting path is not `apps.path` |
| `node.setting_duplicate` | The same setting path appears more than once |
| `node.setting_invalid` | An option is missing a colon or has an empty key |
| `node.setting_required` | `node:settings` ran with no `--setting` option |

The Gateway rejects an invalid public settings object or an unsafe root without changing stored intent.

| Code | Condition |
| --- | --- |
| `node.settings_invalid` | The object has an unknown member, is not an object, or a patch omits `apps` |
| `node.settings_path_invalid` | The apps path is empty or is not a normalized absolute path |
| `node.settings_path_protected` | The apps path is a protected or operating-system path |
| `node.settings_path_managed` | The apps path overlaps a managed checkout |
| `node.settings_roots_overlap` | The apps root overlaps a stored worktree root |
| `node.settings_root_failed` | Directory, ownership, or access-control preparation failed |
