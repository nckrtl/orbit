# Production release layout

This page tells an operator how a production AppInstance separates replaceable code from persistent environment configuration and optional SQLite data, including how to convert an existing flat production home. [ADR 0046](../decisions/0046-own-production-release-deployment-in-orbit.md) owns the production release and serving-layout boundary.

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

## Convert an existing production home

Use explicit conversion for an existing production AppInstance that still serves code directly from its production home:

```text
orbit instance:prepare-deployment <instance-id>
```

The command sends `POST /api/v1/instances/{instance}/deployment-layout` through the Gateway. Use `--sqlite-source-path=PATH` only when one existing SQLite database must move to the persistent `database.sqlite` destination. The path is an explicit source selection; Orbit does not infer a database from application configuration.

The Gateway completes every preflight check before it moves a file, publishes a runtime, or changes serving state.

| Boundary | Required state |
| --- | --- |
| AppInstance | The record identifies one active production placement with complete source and runtime identity. |
| Source | The production home has a safe owned Git checkout that can move intact into one retained release. |
| Environment | The local `.env` has already been imported into Gateway-owned configuration, and its parsed values agree with the stored values. See [AppInstance environment variables](environment-variables.md#import-an-environment-file). |
| Destinations | `releases/`, `current`, `.env`, the optional `database.sqlite`, and conversion-owned temporary paths have no unsafe type, ownership, link, or content conflict. |
| PHP runtime | Existing local pool tuning can be represented in the dedicated runtime's `local.conf`, and the effective dedicated identity remains the recorded user, home, version, pool, service, socket, and document root. |
| Serving | The recorded Route, Caddy projection, PHP socket, and source path still identify this AppInstance. |
| SQLite | The selected file is safe, no owned application Process is active, no process has the file open, and no SQLite sidecar remains beside the source or destination. |

Every entry below the production home must belong to the production user and group. The document root must contain no symbolic link. For example, a Laravel operator must remove or relocate `public/storage` before conversion. A selected SQLite source must be outside the document root; move it outside the served tree and update the application configuration before conversion.

Conversion moves the existing checkout into one retained release and selects that same content through `current`. It does not fetch, reset, clean, or check out Git. It preserves tracked and ignored files, executable modes, and repository state. It keeps `.env` at the production-home path and links the retained release to it. When SQLite is selected, it moves those exact database bytes to `database.sqlite`; it does not change the schema or rewrite a stored environment value.

For PHP, conversion carries supported local pool tuning into the dedicated runtime's `local.conf`, validates the complete effective identity, and switches only this AppInstance's Caddy upstream to its dedicated socket. It does not restart, reload, or reset another production user's shared or dedicated PHP service.

The Gateway records each conversion boundary before it continues. A retry resumes file movement, persistent-state placement, runtime publication, or Route projection from the recorded state. It rechecks the retained content and serving association before each effect and reports completion only when `current`, the dedicated runtime, and the Route projection agree.

An unsupported tuning directive, unsafe destination, changed retained file, or changed serving association stops conversion with a bounded conflict. A refusal before the first recorded effect leaves the old workload unchanged. Repeating a completed request only validates and returns the completed layout; it does not replace the retained release or discard later local edits.

The operating agent must quiesce all application access and checkpoint or close SQLite before relocation so no `-wal`, `-shm`, or `-journal` sidecar remains. Orbit refuses active owned Processes, an observed open database, or one of those source or destination sidecars, but it does not infer maintenance mode, process shutdown, queue handling, schema migration, or another application command.

## Resolve the serving path

The effective web root is the AppInstance root override or its App root beneath `current`, and `current` must resolve to a release beneath the same production home. A root such as `public` therefore serves `<production-home>/current/public` while code is selected.

The Gateway refuses parent traversal, an escaped symbolic link, a selected target outside `releases/`, or an existing owned path with the wrong type or ownership before it publishes the serving projection. A missing `current` link remains a valid prepared layout without silently selecting staged source.

Caddy resolves the root symbolic link to the selected release before it passes a script path to PHP FastCGI Process Manager (PHP-FPM). When `current` selects different code, a request resolves its included PHP files from the newly selected release instead of retaining the previous release's path.

## Retain production content

AppInstance removal clears the owned `current` serving link and its Caddy, certificate, Route, and runtime projections. It retains `releases/`, `.env`, an existing `database.sqlite`, and `/etc/orbit/php-fpm/<production-user>/local.conf` for operator recovery.

Orbit does not remove old releases automatically. Orbit owns explicit release preparation, activation, conversion, and code rollback, while the operating agent owns configured application steps and recovery decisions. Converting an existing flat production home and executing a deployment remain separate operations.
