# AppInstance environment variables

This page tells an operator how the Gateway reads, stores, and safely replaces an AppInstance environment file. [ADR 0044](../decisions/0044-own-appinstance-environment-configuration-in-orbit.md) owns the environment-configuration boundary.

## Select an AppInstance

The import and update endpoints accept either a positive numeric AppInstance ID or an exact Route hostname in `{instance}`. A selector that matches no AppInstance returns HTTP 404. A Route hostname that has multiple AppInstance targets returns HTTP 409 with `env.target_ambiguous`.

The Gateway accepts an active AppInstance only after its recorded placement is complete and no source migration or Route hostname change is pending. It also enforces access from the active peer to the owning Node before it reads the environment file or stored configuration. Import requires the owning Node to be active. A stored update does not contact the Node and can succeed while that Node is unreachable.

## Use the PHP SDK

The PHP software development kit (SDK) provides typed import, update, and synchronization requests. Each request accepts a positive numeric AppInstance ID or an exact Route hostname and encodes the selector as one path segment. The update request also encodes its environment key as one path segment. The SDK forwards these inputs without looking up the AppInstance or resolving placeholders.

The SDK sends one JSON object for each operation and preserves every supplied value for Gateway validation.

| Operation | Method and path | Typed JSON body |
| --- | --- | --- |
| Import | `POST /api/v1/instances/{instance}/environment/import` | Optional boolean `replace`; omission and explicit `false` remain distinct |
| Update | `PUT /api/v1/instances/{instance}/environment/{key}` | Required string `value`, including empty text, multiline text, `false`, `0`, and placeholder expressions |
| Synchronize | `POST /api/v1/instances/{instance}/environment/sync` | Empty object `{}` |

A successful SDK result contains only a positive `app_instance_id`, the expected `operation` token, a boolean `changed`, a `key_count` from 0 through 1,024, and the correlated `request_id`. The SDK rejects missing, wrongly typed, contradictory, extra, or value-bearing result data through a bounded error. Environment values remain available only to the intended HTTP request-body serialization and do not appear in normal request, response, error, exception, or debugging state.

The SDK keeps a valid structured Gateway error code and request ID for an import conflict, unavailable target, failed preflight, unresolved reference, failed synchronization, or unconfirmed synchronization. It omits remote response content from that error boundary.

## Use the CLI

The Orbit command-line interface (CLI) exposes the three environment operations without reading a local file, choosing a target Node, or displaying an environment value. Select an AppInstance with a positive numeric ID or its exact Route hostname.

| Command | Required options | Optional options | Effect |
| --- | --- | --- | --- |
| `orbit env:import` | `--instance=SELECTOR` | `--replace`, `--json` | Import the workload `.env`. The Gateway refuses stored-key conflicts unless `--replace` is present. Replacement retains stored keys that the file omits. |
| `orbit env:update` | `--instance=SELECTOR`, `--key=KEY`, `--value=VALUE` | `--json` | Add or replace one stored value. The workload file stays unchanged. |
| `orbit env:sync` | `--instance=SELECTOR` | `--json` | Replace the workload `.env` from the complete stored configuration. |

Quote environment values for the shell so Orbit receives the intended string. An empty value needs an explicit empty quoted argument, `--value=''`; quote multiline values and values that contain spaces or shell metacharacters. Use single quotes for a reference expression such as `--value='https://{{app_instance.hostname}}'` so the Gateway stores the expression unchanged for destination-specific synchronization. The strings `false` and `0` remain strings.

Import or update changes stored configuration only. Run `orbit env:sync --instance=SELECTOR` explicitly to install it in the workload file. Synchronization does not refresh an application cache or restart a service or process, so run those application steps separately when existing application code must use the new configuration.

Human and JSON success output contains the selected AppInstance ID, the operation, whether the owned boundary changed, the total stored key count, and the request ID. Import and update output also states that the workload file is unchanged. Failures use a bounded error code and request ID and omit environment values.

## Import an environment file

Use the Gateway API to import the existing file from the recorded placement:

```http
POST /api/v1/instances/{instance}/environment/import
Content-Type: application/json

{}
```

The request accepts one optional boolean field. Unknown, duplicate, malformed, or wrongly typed fields return HTTP 422 before a change. Without replacement, a file key that is already stored returns HTTP 409 with `env.import_conflict` and stores nothing. Replacement updates matching keys, adds new keys, and retains stored keys that the file omits.

| Field | Default | Effect |
| --- | --- | --- |
| `replace` | `false` | Select whether the import can replace stored keys. |

For development, the Gateway reads `.env` from the recorded checkout. For production, it reads `.env` from the recorded application-user home. The Gateway never falls back to `.env.example`. It refuses an absent or oversized file, unsafe path, non-regular file, wrong owner, unusable recorded execution identity, unreadable file, or failed trusted Secure Shell (SSH) connection before it reads file contents. The production check runs as the recorded application user and does not require an interactive login shell.

The importer accepts blank lines, comments, quoted values, escaped values, multiline quoted values, and variable expansion from keys in the same file. It does not read variables from the Gateway process environment. Duplicate keys, unresolved expansion, invalid dotenv syntax, or a file larger than 1 MiB reject the complete import. Orbit stores parsed keys and values, without comments or original formatting.

For an AppInstance recorded as Laravel, import replaces an encountered `APP_URL` with `https://{{app_instance.hostname}}` or adds it when absent. It keeps `APP_KEY` and every other imported literal unchanged. Other application types receive no Laravel-specific key.

## Update one stored value

Use the Gateway API to add or replace one key:

```http
PUT /api/v1/instances/{instance}/environment/{key}
Content-Type: application/json

{"value":"example"}
```

The body contains exactly one string `value`. Empty text, `false`, and `0` are distinct string values when sent as `""`, `"false"`, and `"0"`. An identical stored value succeeds with `changed: false`. For Laravel, an `APP_URL` update accepts only `https://{{app_instance.hostname}}`.

## Validate stored configuration

The Gateway validates the complete result before it stores any part of an import or update.

| Limit | Contract |
| --- | --- |
| Key | 1 to 255 ASCII characters; starts with `A-Z`, `a-z`, or `_`; remaining characters can also contain `0-9` |
| Value | Valid UTF-8 string of at most 65,536 bytes without a null byte |
| Keys per AppInstance | At most 1,024 |
| Generated file | At most 1 MiB after conservative escaping and maximum placeholder expansion |
| Placeholders | Only `{{app_instance.hostname}}` and `{{app_instance.environment}}`, including as part of a longer value |

Malformed or unknown placeholder expressions fail validation. Database-path placeholders, `{{instance.hostname}}`, and `{{app_instance.url}}` are not supported.

## Synchronize stored configuration

Use the Gateway API to replace the workload file from the complete stored configuration:

```http
POST /api/v1/instances/{instance}/environment/sync
Content-Type: application/json

{}
```

The request body must be an empty JSON object. Synchronization accepts the same positive AppInstance ID or exact Route hostname selectors as import and update. It requires an active, complete AppInstance owner, an active owning Node, and access from the active peer to that Node.

The Gateway takes one consistent snapshot of the AppInstance owner, sole Route, and complete stored configuration. It resolves `{{app_instance.hostname}}` from that Route and `{{app_instance.environment}}` to the recorded `development` or `production` value. A missing Route, a Route transition, or an unavailable reference stops synchronization before replacement. The generated dotenv file has stable key order and preserves literal whitespace, newlines, quotes, dollar signs, backslashes, empty strings, and stored application keys.

For development, synchronization selects `.env` in the recorded checkout. For production, it selects `.env` in the recorded application-user home and runs as that user even when the account has a disabled login shell. Every prepared production release links its local `.env` to this home file. An App or AppInstance web root such as `public` does not change this location. Callers cannot override the Node, runtime user, directory, or filename. The [production release-layout reference](deployments.md) describes the complete home boundary.

Before it decrypts any stored value, the Gateway checks trusted SSH access, the recorded execution identity, path containment, directory write access, destination type and ownership, replacement permission, read-only storage, and a conservative capacity bound derived without decrypting values. A missing parent, unsafe symlink, special file, wrong owner, failed or malformed observation, or insufficient capacity stops the operation without changing `.env`.

Synchronization renders every stored key and installs one complete file. Stored configuration is the only input. The operation preserves stored `APP_KEY` and database values, while local-only keys and local edits disappear. It never changes a stored literal database path to the production home's optional `database.sqlite`, and no database-path placeholder is supported. Import local edits first, or update the stored values, if those edits must remain.

Concurrent import, update, synchronization, removal, and Route transitions share one bounded AppInstance operation owner. A competitor waits or returns `env.operation_busy` before effects. An update that runs after synchronization changes stored configuration only. Run synchronization again to install that pending value.

A successful response contains only the AppInstance ID, `operation: sync`, whether the file changed, the total stored key count, and request-ID metadata. An identical protected file returns `changed: false` without replacement. Changed content or protection returns `changed: true` after complete replacement. A retry always rechecks current placement and stored configuration.

Preflight, decryption, rendering, and confirmed writer failures leave the previous file intact. An unconfirmed writer result returns `env.sync_unconfirmed`; the replacement might have completed, so the response does not claim that the previous file remains. Retry the same request to recheck the current file and either accept the matching protected file or install the complete current result.

## Inspect the projection with Doctor

Doctor renders the current stored configuration against the AppInstance's recorded Route and environment, then compares that intent with the workload `.env`. It reports a bounded instance-family finding when the persistent production file is missing, unsafe, or different. It does not expose a key or value in the report, Activity record, error, or diagnostic output.

This comparison checks only Orbit-owned file projection. It does not inspect a framework configuration cache, restart a process, synchronize a pending stored change, or modify the file. A stale application cache is not environment projection drift.

## Remote replacement boundary

Synchronization installs the rendered file through a protected internal operation. The reusable writer also remains available to other Gateway operations that already hold the AppInstance ownership and validation boundary.

Before a write, the Gateway checks trusted SSH access, the recorded execution identity, path containment, directory write access, destination type and ownership, replacement permission, read-only storage, and conservative required capacity. A missing parent, unsafe symlink, special file, wrong owner, failed or malformed observation, or insufficient capacity stops the operation before it changes `.env`. This preflight reads no environment values and does not parse or decrypt configuration. Import uses the same boundary with read checks and needs no write permission or replacement capacity.

The writer checks the recorded boundary again, writes the supplied bytes through protected input to a mode-`0600` candidate owned by the runtime user, and atomically replaces `.env`. It does not parse or import the old file. A missing `.env` is created. A matching protected file remains the same file and reports `changed: false`; different bytes or a different mode produce a complete protected replacement and report `changed: true`.

A confirmed candidate-write, protection, or rename failure leaves the previous `.env` unchanged and removes only the failed attempt's candidate. When the Gateway cannot confirm completion after replacement, it reports an unconfirmed result because the replacement might have completed. A retry repeats placement and boundary checks, then accepts an already matching protected file or installs the supplied complete file.

The remote operation does not expose supplied bytes or raw remote output in results, errors, exception chains, logs, or normal debugging. It changes no source file outside `.env`, Git metadata, database file, framework cache, service, or application process. It runs without an installed framework, application dependencies, or an application database.

## Read results and recover encrypted values

A successful import, update, or synchronization returns only the AppInstance ID, operation, whether its owned boundary changed, the total stored key count, and request-ID metadata. Responses, activity, logs, diagnostics, and model serialization omit environment values.

The Gateway encrypts every literal and placeholder expression with its application encryption key before database storage. Recovery of stored configuration depends on retaining that Gateway key material. Orbit does not generate or delete an application key through these endpoints, and it never displays plaintext stored values.

Import and update change only stored Gateway configuration. They do not write the workload `.env`, run application code, refresh framework caches, restart services, or require an application database or installed framework dependencies.

Synchronization changes only the workload `.env`. It does not run application code, refresh framework caches, restart services or application processes, change Git metadata or source, or touch application database files. Stale framework caches, missing dependencies, and an absent application database do not block it. Run the application's separate cache refresh or process restart step when the new file must become effective in already running application code.
