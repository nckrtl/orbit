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

Orbit keeps `database.sqlite` at the production-home path. The operating agent must configure the application to use that path. Orbit provides no database-path environment placeholder and does not infer or rewrite a stored literal database value.

## Resolve the serving path

The effective web root is the AppInstance root override or its App root beneath `current`, and `current` must resolve to a release beneath the same production home. A root such as `public` therefore serves `<production-home>/current/public` while code is selected.

The Gateway refuses parent traversal, an escaped symbolic link, a selected target outside `releases/`, or an existing owned path with the wrong type or ownership before it publishes the serving projection. A missing `current` link remains a valid prepared layout without silently selecting staged source.

Caddy resolves the root symbolic link to the selected release before it passes a script path to PHP FastCGI Process Manager (PHP-FPM). When `current` selects different code, a request resolves its included PHP files from the newly selected release instead of retaining the previous release's path.

## Retain production content

AppInstance removal clears the owned `current` serving link and its Caddy, certificate, Route, and runtime projections. It retains `releases/`, `.env`, an existing `database.sqlite`, and `/etc/orbit/php-fpm/<production-user>/local.conf` for operator recovery.

Orbit does not remove old releases automatically. Orbit owns explicit release preparation, activation, and code rollback, while the operating agent owns configured application steps and recovery decisions. Converting an existing flat production home and executing a deployment remain separate operations.
