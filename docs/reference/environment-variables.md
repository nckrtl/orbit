---
title: "Instance environment variables"
description: "How the Gateway imports, stores, updates, and synchronizes an Instance .env file without exposing its values."
covers:
  - apps/gateway/app/Domain/Instances/Environment/**
  - apps/gateway/app/Actions/*/{Import,Update,Synchronize}*EnvironmentAction.php
  - apps/gateway/app/Http/Controllers/Api/InstanceEnvironment*Controller.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteInstanceEnvironmentAccess,NativeInstanceEnvironmentOperationLock}.php
  - apps/gateway/app/Models/{Instance,InstanceEnvironmentValue}.php
  - apps/gateway/database/migrations/{2026_09_30_*,*rename_app_domain_to_project_and_instance}.php
  - apps/cli/app/Commands/Environment/**
  - packages/php-sdk/src/{Requests,Responses}/Environment/**
---

# Instance environment variables

The Gateway owns the environment configuration of every Instance. It stores each key and value encrypted, and it writes the Instance's `.env` file only when you synchronize. A value never appears in a response, an Activity entry, an error, or a log. [`env`](/cli/env) lists the commands.

The Gateway's own environment is separate from an Instance's stored configuration. Set `ORBIT_TASKS_IMPLEMENTER_EFFORT` and `ORBIT_TASKS_REVIEWER_EFFORT` in the Gateway's `.env`, not the task workspace's `.env`. See [Tasks configuration](/reference/tasks#configuration) for their defaults and when changes apply.

Reviewer trust is not an Instance environment setting. The Gateway operator sets `ORBIT_TASKS_GITHUB_REVIEWERS` in the Gateway's `.env`. A task workspace's `.env`, task definition, or branch cannot grant [GitHub feedback authority](/reference/tasks#trusted-github-feedback).

The Incus harness also reads its own environment. `ORBIT_E2E_INCUS_MEMORY` overrides the memory limit for every VM it creates or clones, for example `2GiB`. When unset, the harness uses the [per-Node defaults](/reference/incus-topologies#capacity). It does not read this setting from an Instance's `.env`.

## Operations

The import and update endpoints accept either a positive numeric Instance ID or an exact Route domain in `{instance}`. A selector that matches no Instance returns HTTP 404. A Route domain that has multiple Instance targets returns HTTP 409 with `env.target_ambiguous`. The Instance's recorded placement owns its environment: the owning Node is the Instance's `node_id`, not a Route. An Instance without a Route can still have its environment synchronized. Changing the Project's [task workspace routing setting](/reference/projects#task-workspace-routing) does not change an existing Instance's environment target.

| Operation | Request | Body | Effect |
| --- | --- | --- | --- |
| Import | `POST /api/v1/instances/{instance}/environment/import` | `{}` or `{"replace": true}` | Reads the Instance's `.env` into stored configuration. |
| Update | `PUT /api/v1/instances/{instance}/environment/{key}` | `{"value": "..."}` | Adds or replaces one stored value. |
| Synchronize | `POST /api/v1/instances/{instance}/environment/sync` | `{}` | Replaces the Instance's `.env` with the complete stored configuration. |

Import and update change stored configuration only. The `.env` file on the Node stays the same until you synchronize. The Gateway refuses unknown, duplicate, or wrongly typed members with 422 before it changes anything.

A success returns `instance_id`, `operation`, `changed`, and `key_count`, the total number of stored keys. `changed` is `false` when the stored value or the file already matched.

The Instance must be active and have complete placement. The Node must have exactly one active `app-dev` or `app-prod` role; without one, the Gateway returns `instance.placement_unavailable` (409).

Import, update, and synchronization use the Instance's recorded owning Node. A Route is not required for synchronization unless a stored value refers to `{{instance.domain}}`; then the Gateway needs an authoritative Route and returns `env.reference_unavailable` (409) if it cannot resolve one. A Route in an incomplete transition also blocks synchronization. The caller needs an access grant to the Instance's Node. Import and synchronize also need an active Node. Update does not contact the Node, so it works while the Node is unreachable.

## Where the file lives

The Gateway derives the file location and the user from the Instance's placement. The caller cannot choose the Node, the user, the directory, or the file name.

| Placement | File | User |
| --- | --- | --- |
| `app-dev` | `.env` in the Instance's application directory within the checkout | The Node's managed user |
| `app-prod` | `.env` in the production home | The Instance's production user |

For Laravel, the [application directory](/reference/projects#application-directory) is the effective web root without its trailing `/public`. With root `apps/site/public`, development reads and writes `<checkout>/apps/site/.env`, and `.env.testing` lives beside it. A development default uses the same paths in its stable checkout home and copies those files into each candidate's application directory.

On `app-prod`, every release links `.env` in its application directory to the production home's file. With root `apps/site/public`, `<home>/releases/<name>/apps/site/.env` links to `<home>/.env`; no release-root `.env` link is needed. See [Production release layout](/reference/deployments).

`ORBIT_TASKS_WORKER_USER` is a setting in the Gateway's own environment, not in an Instance's `.env`. It selects the worker account for [checkout ACLs](/reference/instance-setup#checkout-access), normally `orbit-worker`. An unset setting leaves checkout access unchanged. It does not change the Instance's placement, the user that imports or synchronizes its environment, or the mode `0600` used for a synchronized `.env` file. Setting an Instance key with that name does not configure the Gateway.

## Import

Import reads the Instance's `.env`. It never reads `.env.example`. Before it reads the contents, the Gateway checks SSH access, the user, the path, the file type, the owner, and the size. A missing, unsafe, or oversized file stops the import.

The importer accepts blank lines, comments, quoted and escaped values, multiline quoted values, and expansion of keys from the same file. It does not read the Gateway's own environment. A duplicate key, an unresolved expansion, invalid syntax, or a file over 1 MiB refuses the whole import. Orbit stores keys and values only, without comments or formatting.

Without `replace`, a file key that is already stored returns `env.import_conflict` (409), and nothing is stored. With `replace`, matching keys take the file value, new keys are added, and stored keys that the file lacks stay.

For a Laravel Instance, import stores `APP_URL` as `https://{{instance.domain}}`, so the URL follows the Route. It keeps `APP_KEY` and every other value as the file has it. When a Route's domain changes, Orbit updates APP_URL in that application's `.env` and Laravel cached configuration, not in an unrelated file at the repository root.

## Update

Update stores one string. `""`, `"false"`, and `"0"` are distinct strings. For a Laravel Instance, `APP_URL` accepts only `https://{{instance.domain}}`.

## Limits

The Gateway checks the complete result before it stores any part of an import or update. A failure returns `env.configuration_invalid` (422). The details name the key, the failed rule, and a leftover placeholder token, but never a value.

| Item | Limit |
| --- | --- |
| Key | 1 to 255 characters. It starts with `A-Z`, `a-z`, or `_`, and can then also hold `0-9`. |
| Value | Valid UTF-8, at most 65,536 bytes, without a NUL byte. |
| Keys | At most 1,024 for each Instance. |
| File | At most 1 MiB after rendering. |
| Placeholders | `{{instance.domain}}` and `{{instance.environment}}`, alone or inside a longer value. Any other `{{...}}` fails. |

## Synchronize

Synchronization takes one snapshot of the Instance, any authoritative Route, and the stored configuration. It resolves `{{instance.domain}}` to the Route's domain when one is present and resolves `{{instance.environment}}` to `development` on `app-dev` or `production` on `app-prod`. Only a stored domain placeholder requires a Route. A leftover `{{` or `}}` after rendering returns `env.reference_unavailable` (409) before the file changes.

Before it decrypts a value, the Gateway checks SSH access, the user, the path, the directory's write permission, the file type and owner, read-only storage, and free space. A failed check returns an error and leaves `.env` as it is.

The Gateway renders every stored key in sorted order, as a quoted value. It writes the result as a candidate file with mode `0600`, owned by the runtime user, and renames it over `.env`. A matching file with mode `0600` stays in place and returns `changed: false`. Stored configuration is the only input. Keys and edits that exist only in the file disappear, so import them first when they must stay.

When the Gateway cannot confirm the write, it returns `env.sync_unconfirmed` (the file may have changed). Repeat the request: it checks the file again and either accepts the matching file or writes it.

For an Instance that owns its `DB` database, synchronization also writes `.env.testing` the same way: the same values, with `APP_ENV=testing` and `DB_DATABASE` set to the [test database](/reference/database-connections#test-databases). The other `DB_*` keys stay the same. Orbit never deletes `.env.testing`. The file stays after the Instance stops owning its `DB` database.

Synchronization changes only `.env` and `.env.testing`. It does not run application code, clear a framework cache, or restart a service or Process. Run those steps yourself when running code must see the new values.

The Gateway takes one consistent snapshot of the Instance owner, any authoritative Route, and complete stored configuration. It resolves `{{instance.domain}}` from the Route when available and `{{instance.environment}}` to the default Laravel mode for the Instance's Node role (`development` on app-dev or `production` on app-prod). A Route is required only when a stored value uses the domain placeholder; a missing Route or unavailable reference then stops synchronization before replacement. An incomplete Route transition also stops synchronization. The generated dotenv file has stable key order and preserves literal whitespace, newlines, quotes, dollar signs, backslashes, empty strings, and stored application keys.

Import, update, synchronize, deploy, removal, and Route changes on one Instance share one operation lock. A competing request waits or returns `env.operation_busy`.

## Laravel mode

`APP_ENV` and `APP_DEBUG` are ordinary stored keys. The Node role, not these keys, decides the release layout, the Unix user, and the PHP-FPM pool. So a change to `APP_ENV` never moves an Instance between layouts. When `APP_ENV` is absent or is the environment placeholder, Orbit reads it as `development` on `app-dev` and `production` on `app-prod`.

A [clone](/reference/instance-cloning) onto `app-prod` copies the candidate's stored configuration and then sets `APP_ENV=production` and `APP_DEBUG=false`. You can change both afterwards. A transfer keeps every stored key.

## Other writers

Other operations also change stored keys, and never the file itself:

- [`instance:database:add` and `instance:database:remove`](/reference/database-connections#add-a-connection-on-an-instance) write or clear the keys of one database prefix.
- Creating an `agentation-mcp` Process stores `AGENTATION_URL` as `https://{{instance.domain}}/__orbit/agentation`. See [Agentation](/reference/agentation).
- Creating an `annotator` Process stores `ANNOTATOR_URL` as `https://{{instance.domain}}/__orbit/annotator/annotations`.

Removing the annotator Process deletes that stored key. Synchronization renders the current Route domain. Without a Route, the domain placeholder returns `env.reference_unavailable`.

The annotator also projects the concrete `ANNOTATOR_URL` and `ORBIT_ANNOTATOR_PORT` into every systemd Process of the Instance. These derived values override a stale `.env` value or a caller-supplied environment map. Process creation and removal rewrite the existing units of sibling Processes without changing their observed runtime state. Sleeping workers are not started, and cold dependencies are not restored. A running sibling reads the new values on its next start or restart. After removal, units unset both keys, even before the next environment synchronization. This runtime projection does not write `.env`; see [Annotator Process](/reference/agentation#annotator-process).

A production [deployment](/reference/deployments) synchronizes the stored configuration before it runs any deploy step. A development default keeps its configured environment files at the stable checkout home. Explicit synchronization writes there; its next development deployment copies `.env` and any `.env.testing` into the candidate without changing the live seed. Deploy the default by hand when these file changes need to take effect before the next push.

## Synchronize during a domain change

A development [Instance rename](/cli/instance#orbit-instancerename) updates Laravel `.env` and cached `app.url` through Route convergence, and records the stored APP_URL only after that operation succeeds. If a failed replacement remains, requesting the original domain is not a rollback or a no-op: it returns `route.domain_change_conflict` without another branch or environment mutation. Retry the pending destination to finish the replacement.

A domain change of a production Route rewrites the production `.env` inside the Route operation. It uses the same checks and writer as synchronization. It resolves `{{instance.domain}}` against the new Route, although the public operations refuse an Instance while its Route changes. Stored values stay the same, so a placeholder `APP_URL` changes and a literal `APP_KEY` does not.

When the change fails before the cutover, recovery renders the stored configuration against the old Route and restores `.env` before it removes the new Route. After the cutover, a retry checks the new file and does not revert it. See [Routes](/reference/routes).

## Project update boundary

A Project slug change updates the Laravel `APP_URL` that the Route domain owns. The Gateway resolves the new domain, stores the value, and then synchronizes `.env`. When a step fails before the new slug is published, the Gateway restores the stored configuration, the file, and the old Route. After publication, a retry continues forward. See [Applications](/domains/applications).

## Inspect the projection with Doctor

[Doctor](/cli/doctor) renders the stored configuration against the Instance's Route and placement and compares it with the `.env` file. It reports a missing, unsafe, or different production file as an `instance` finding. It shows no key or value, and it does not inspect a framework cache.

## Storage and recovery

The Gateway encrypts every stored value, placeholders included, with its own application key before it writes the row. Restoring stored configuration needs that key. These operations never create or delete an application's `APP_KEY`.

## Errors

Environment operations return these codes in the Orbit error envelope. None of them contains a value.

| Code | HTTP | Cause |
| --- | --- | --- |
| `env.target_ambiguous` | 409 | The domain reaches more than one Instance. |
| `env.owner_unavailable` | 409 | The Instance is not active or not fully placed, or it lacks exactly one healthy Route. |
| `instance.placement_unavailable` | 409 | The owning Node does not have exactly one active `app-dev` or `app-prod` role. |
| `env.import_conflict` | 409 | Import without `replace` found a key that is already stored. |
| `env.import_source_missing` | 404 | The recorded source `.env` file does not exist; fix or restore the file before importing. |
| `env.configuration_invalid` | 422 | A key, value, placeholder, count, size, or Laravel `APP_URL` rule failed. |
| `env.reference_unavailable` | 409 | A placeholder is left over after rendering. |
| `env.operation_busy` | 409 | Another operation holds the Instance's lock. |
| `env.sync_unconfirmed` | 409 | The Gateway cannot confirm the write. Retry the request. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The Gateway holds the configuration

A `.env` file on a Node holds values for one placement, such as its domain and paths. Another placement cannot rebuild it from the Gateway. So the Gateway stores the configuration, and synchronization writes it for the destination. Treating the Node's file as the source is a rejected alternative.

### Every value is encrypted

Orbit does not classify values as secret or not. One missed classification would leave a credential in plain text. So the Gateway encrypts every value.

### Synchronization never merges local edits

A write that pulls edits from the Node would replace stored intent with changes that nobody requested. So synchronization only writes, and import is the explicit way to read a file.

### Synchronization runs no framework

Synchronization must work before dependencies are installed and before the application runs. So it writes the file and nothing else. Deploy steps own caches and restarts.

### The Node role decides isolation

An operator may set `APP_ENV` to `local` or `staging` on a production Instance. If `APP_ENV` decided the layout or the Unix user, that edit would turn off release layout or user isolation. So the Node role decides them, and `APP_ENV` stays an application setting.

The Instance environment comes from its Node role.
