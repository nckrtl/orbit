# Production release layout

This page tells an operator how a production AppInstance separates replaceable code from persistent environment configuration and optional SQLite data. [ADR 0046](../decisions/0046-own-production-release-deployment-in-orbit.md) owns the production release and serving-layout boundary.

## Read the production home

The Gateway prepares each new production home with these paths before it publishes serving state.

| Path | Purpose |
| --- | --- |
| `releases/` | Contains retained, replaceable code releases owned by the production AppInstance. |
| `.env` | Holds the persistent environment file outside every release. |
| `database.sqlite` | Holds the optional persistent SQLite database when the operating agent configures or supplies one. Layout preparation does not create this file. |
| `current` | Selects one release through a symbolic link. It is absent until Orbit selects code. |

Each prepared release contains a `.env` symbolic link that resolves to the production home's `.env` file. Production creation stages initial source under `releases/` and leaves `current` absent. The explicit first deployment selects code later. Staged source does not become serving state merely because it exists.

Orbit keeps `database.sqlite` at the production-home path. The operating agent must configure the application to use that path. Orbit provides no database-path environment placeholder and does not infer or rewrite a stored literal database value. The [AppInstance cloning reference](appinstance-cloning.md) describes optional SQLite seeding into this path.

## Configure deployments

The Gateway stores one complete deployment configuration for each production AppInstance. Reading or replacing this configuration does not start a deployment.

| Request | Result |
| --- | --- |
| `GET /api/v1/instances/{instance}/deployment-config` | Returns the configured `branch` and ordered `steps`. An existing production AppInstance with no stored steps returns an empty array and keeps its recorded branch. |
| `PUT /api/v1/instances/{instance}/deployment-config` | Atomically replaces the complete `branch` and `steps` configuration. The request does not fetch source, run a command, or change `current`. |

The complete request and response use these fields.

| Field | Type | Contract |
| --- | --- | --- |
| `branch` | string | Required Git branch name accepted by Orbit's branch validator. A later App default change does not replace this instance-owned value. |
| `steps` | array | Required ordered list with at most 32 entries. An empty list is valid. |
| `steps[].name` | string | Required unique name of 1 through 63 lowercase letters, digits, or hyphens. A name starts and ends with a letter or digit. |
| `steps[].phase` | string | Required `before_activation` or `after_activation`. |
| `steps[].command` | string | Required nonempty UTF-8 command of at most 16 KiB with no NUL byte. The operating agent owns this command. |
| `steps[].timeout_seconds` | integer | Optional timeout from 1 through 900 seconds. The default is 300 seconds. |

The array preserves the order of steps within each phase. The sum of configured timeouts, including defaulted values, cannot exceed 3,600 seconds.

The Gateway rejects malformed JSON, duplicate or unknown members, duplicate step names, wrong types, and values outside these limits before it changes either field. Both endpoints use the AppInstance's current Node-access authorization and refuse a non-production AppInstance. Authorized reads return commands, but the Gateway keeps command text out of Activity records, validation errors, and generic diagnostics.

## Resolve the serving path

The effective web root is the AppInstance root override or its App root beneath `current`, and `current` must resolve to a release beneath the same production home. A root such as `public` therefore serves `<production-home>/current/public` while code is selected.

The Gateway refuses parent traversal, an escaped symbolic link, a selected target outside `releases/`, or an existing owned path with the wrong type or ownership before it publishes the serving projection. A missing `current` link remains a valid prepared layout without silently selecting staged source.

Caddy resolves the root symbolic link to the selected release before it passes a script path to PHP FastCGI Process Manager (PHP-FPM). When `current` selects different code, a request resolves its included PHP files from the newly selected release instead of retaining the previous release's path.

## Retain production content

AppInstance removal clears the owned `current` serving link and its Caddy, certificate, Route, and runtime projections. It retains `releases/`, `.env`, an existing `database.sqlite`, and `/etc/orbit/php-fpm/<production-user>/local.conf` for operator recovery.

Orbit does not remove old releases automatically. Orbit owns explicit release preparation, activation, and code rollback, while the operating agent owns configured application steps and recovery decisions. Converting an existing flat production home and executing a deployment remain separate operations.
