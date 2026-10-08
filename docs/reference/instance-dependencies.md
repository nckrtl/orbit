---
title: "Instance dependencies"
description: "Record the resolved Composer and JavaScript dependencies of each Instance, and update a development Instance within its declared constraints."
covers:
  - apps/gateway/app/Actions/Instances/Dependencies/{ScanInstanceDependenciesAction,UpdateInstanceDependenciesAction,AccessInstanceDependenciesAction}.php
  - apps/gateway/app/Http/Controllers/Api/{InstanceDependenciesController,ResolveDependencyInstanceController,ResolveDirectoryInstanceController}.php
  - apps/cli/app/Commands/Dependencies/**
  - apps/cli/app/Services/DependencyInstanceSelector.php
---

# Instance dependencies

The Gateway keeps an inventory of the resolved dependencies of each Instance. It reads the manifests and lockfiles in the Instance's dependency directory, so the inventory works while dependencies are [pruned](/reference/app-dev-runtime-hibernation#dependency-prune). On a development Instance, Orbit can also update dependencies within their declared version constraints. [Dependency contracts](/reference/instance-dependency-contracts) holds the reader rules, stored tables, and response shapes.

## What the inventory holds

The inventory belongs to one Instance/app pair. Two Instances of a Project, or two apps in an Instance, can resolve different versions.

For every type, the dependency directory is the selected app's effective [application directory](/reference/projects#application-directory), not its web root. With app path `apps/site`, a development scan reads `<checkout>/apps/site`, and a supported single-app production scan reads `<home>/releases/<selected-release>/apps/site`. App path `.` selects the checkout or release root.

A scan reads only that directory; it does not combine manifests from the repository root or sibling apps. The response's `source.project_root` names the directory actually read. When the CLI selects by directory, it identifies the Instance, not an arbitrary dependency tree within it. Orbit reads only these files:

| Ecosystem | Files | Supported format |
| --- | --- | --- |
| Composer | `composer.json`, `composer.lock` | Composer 1 and 2 locks. |
| npm | `package.json`, `package-lock.json` or `npm-shrinkwrap.json` | `lockfileVersion` 2 and 3. Shrinkwrap wins. |
| pnpm | `package.json`, `pnpm-lock.yaml` | `lockfileVersion` 9.0 with one root importer. |
| Bun | `package.json`, `bun.lock` | Text lock, `lockfileVersion` 1 or 2. |

npm, pnpm, and Bun all record packages in the `npm` ecosystem. Yarn, binary `bun.lockb`, workspace or multi-importer layouts, and local `file:` or `link:` packages are unsupported and fail the scan.

For each package, the inventory records every resolved version and how the root reaches it: direct or transitive, regular or development, and peer requirements. It records what the lockfile says. It does not prove that packages are installed or safe.

A scan reads files only. It runs no package manager, project script, or registry request.

## Scan

Select one Instance by the current directory or by its full Route domain, or scan every Instance you can reach.

```bash
orbit instance:dependencies:scan
orbit instance:dependencies:scan --project=commander.test
orbit instance:dependencies:scan --all
```

| Selector | Target |
| --- | --- |
| none | The registered Instance whose checkout or production home holds the current directory, on the caller's Node. |
| `--project=DOMAIN` | The one Instance behind that Route domain. It takes precedence over the directory. |
| `--app=NAME` | Select one exact app within the resolved Instance. Required for directory selection on a multi-app Project; a Route domain supplies its own app. |
| `--all` | Every accessible Instance, in list order, and every effective app within it in name order, including non-serving packages and unrouted workspaces. |

No match or more than one match fails before any work, with `dependencies.target_not_found` or `dependencies.target_ambiguous`. `--all` with `--project` or `--app` returns `dependencies.target_conflict` before any request. An `--all` scan continues after a failed app, including sibling apps. Ctrl-C marks all remaining app entries as skipped. Single-target omission selects the sole app or returns `app.required`; unknown app names return `app.not_found`, and disagreement with a Route domain returns `app.selector_conflict`.

Human output labels each Instance and app before its ecosystems, counts and times. An unknown count shows as an em dash, and verified absence shows zero. Single-target `--json` returns the [typed app inventory](/reference/instance-dependency-contracts#responses). `--all` returns exactly `succeeded`, `summary`, `instances`, and `request_id`, as shown below. Each `inventory` is that typed inventory or null if the request failed or was skipped; request failures carry the stable `error_code`. A successfully returned inventory with failed ecosystems has status `failed`, its inventory intact, and null aggregate error code.

```json
{
  "succeeded": false,
  "summary": {"instances":1,"apps":2,"scanned":0,"failed":1,"skipped":1},
  "instances": [{
    "instance_id":12,
    "apps":[
      {"app":"admin","status":"failed","error_code":"dependencies.source_unavailable","inventory":null},
      {"app":"web","status":"skipped","error_code":null,"inventory":null}
    ]
  }],
  "request_id":"request-correlation-id"
}
```

`summary.instances` counts selected Instances; `apps` counts selected Instance/app pairs. `scanned` counts pairs whose ecosystems succeeded; `failed` counts pairs with a failed request or ecosystem; `skipped` counts cancelled pairs. These last three counts sum to `apps`. An empty selection succeeds with zero counts. `succeeded` and exit status zero require every selected app/ecosystem to succeed and no skips. API and MCP return an unaggregated response for each app; this aggregate is the CLI `--all` result, whose SDK inventories preserve their `app` identity.

## Refresh rules

Each Instance/app/ecosystem keeps its last successful observation and latest attempt separately. Migration assigns `web` to existing observations and attempt histories.

| Condition | Result |
| --- | --- |
| Manifest and lockfile are valid | Replace the observation. |
| Both files are missing | Record that the ecosystem is absent. |
| One of the two files is missing | Fail with `dependencies.incomplete_source`. |
| The lockfile root differs from `package.json` | Fail with `dependencies.stale_npm_lockfile`, `dependencies.stale_pnpm_lockfile`, or `dependencies.stale_bun_lockfile`. Human output adds a fix, such as `Run bun install in the dependency directory and commit bun.lock.` |
| The format or layout is unsupported, or a file is invalid | Fail with the matching code. |
| A production Instance has no selected release | Fail with `dependencies.source_unavailable`. |
| The source changes during the scan | Fail both ecosystems with `dependencies.source_changed`. Retry. |

A failure keeps the last observation and marks it stale. An Instance that was never scanned has unknown inventory. One ecosystem can succeed while the other fails.

Scans share the Instance's operation lock with updates, deployments, rollbacks, environment changes, and removal. A busy Instance returns `dependencies.operation_busy`. Removing an Instance removes its inventory.

## Update a development Instance

Update one development Instance with the same selectors, except `--all`:

```bash
orbit instance:dependencies:update
orbit instance:dependencies:update --project=commander.test
```

The Gateway runs these steps as the Node's user in the same dependency directory used by scans. For app path `apps/site`, that is `<checkout>/apps/site`; presence checks, Composer, Vite+, and the final scan all use that directory. These commands do not update repository-root or sibling manifests. Unlike dependency updates, repository-owned setup, deploy, and task-check commands keep their [repository-root scope](/reference/projects#application-directory):

1. It inspects both ecosystems. A refusal stops the whole update before any package command. See the refusals below.
2. It runs `composer update --no-interaction --no-ansi --no-progress --no-audit` for a Composer project. Regular and development packages move within their constraints.
3. It runs `vp update --no-save` for a JavaScript project. Vite+ picks npm, pnpm, or Bun. For Bun, Orbit adds `-- --lockfile-only --save-text-lockfile`.
4. It scans the result, also after a failed or cancelled step.

The inspection refuses a Yarn project, an unsupported layout, two package managers, and an unverified Vite+.

Each package step has a 600-second deadline and owns its process group. A failed step stops the steps after it. Orbit claims no rollback. The update succeeds only when every package step and the final scan succeed.

Orbit uses the first Vite+ it finds at `/opt/orbit/vite-plus/bin/vp`, `~/.vite-plus/bin/vp`, `~/.vite-plus/current/bin/vp`, `~/.local/share/vite-plus/bin/vp`, or `~/.local/share/vite-plus/current/bin/vp`. It also accepts the launcher `/usr/local/bin/vp`. Only Vite+ 0.3.0 is verified. Another version fails with `dependencies.unsupported_delegation`.

A production Instance returns `dependencies.production_update_forbidden` before any command. `--all` and `--latest` are refused before any request. Declared constraints never change: an update that needs a new constraint is an upgrade, which Orbit does not do.

To bring an update to production, test it, commit the manifests and lockfiles, and push them to the production deployment branch. Then [deploy](/reference/deployments) with a step that installs from the lockfiles.

## API

The CLI uses these Gateway routes. Each needs an [access grant](/cli/node) to the Instance's Node.

| Route | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/dependencies` | The stored inventory, without SSH. |
| `POST /api/v1/instances/{instance}/dependencies/scan` | Scan both ecosystems. |
| `POST /api/v1/instances/{instance}/dependencies/update` | Update a development Instance. |
| `GET /api/v1/instances/resolve?domain=DOMAIN` | Find the Instance behind a Route domain. |
| `GET /api/v1/instances/resolve-directory?directory=PATH` | Find the Instance that holds a directory on the caller's Node. |

Reads accept `?app=NAME`; `POST` takes `{"app":"web"}` or an empty object for a single-app Project. The request targets only that app. SDK requests use `$app`, MCP uses `app`, and CLI uses `--app=NAME`. HTTP 200 means the operation ran, not that it succeeded. Check `succeeded`, each step's `status`, and each ecosystem's state.

## Nightly scan

Create one Node [Schedule](/reference/schedules) on the Gateway host. Each run finds the current set of Instances, so you need no Schedule per Instance.

```bash
orbit schedule:create dependency-nightly-scan \
  --node=GATEWAY_NODE_ID \
  --calendar='*-*-* 03:15:00 Europe/Amsterdam' \
  --command='orbit instance:dependencies:scan --all --json --no-interaction' \
  --timeout=7200
```

Put the timezone in the calendar expression. Size the timeout for the fleet. A failed Instance makes the run fail, and the Schedule logs keep the JSON result. A nightly scan checks no advisories and updates no packages.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Inventory per Instance and app

Instances of one Project can run different source, and apps have independent manifests. One version per Project or per package identity was rejected. Shared package identities still allow fleet-wide queries.

### Lockfiles, not installed files

Hibernation deletes `vendor` and `node_modules` on idle Instances. Lockfiles stay, so the inventory reads them.

### No updates in production

Resolving versions in production skips the tested source and the release flow. So updates run only in development, and production installs the committed lockfiles through a deployment.

### No Yarn

Orbit supports npm, pnpm, and Bun, the managers that Vite+ drives with a verified constrained update. Yarn has no such update, so a Yarn project fails clearly. It never becomes an empty inventory or another manager's result.

### Nightly scans as well as updates

Dependencies also change outside Orbit. A scan after each Orbit update would miss those changes, so one Schedule scans the fleet every night.
