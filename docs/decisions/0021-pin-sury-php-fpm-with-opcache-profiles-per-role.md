# ADR 0021: Pin Sury PHP-FPM with OPcache profiles per role

In the context of PHP applications served by Caddy on `app-dev` and `app-prod` Nodes, facing engine OPcache defaults that overflow with two Laravel checkouts and re-check every file every two seconds, we decided for the Sury packages with one PHP-FPM service per version and an OPcache policy per role, and against static-php builds, the tracing JIT, and building Caddy from source, to achieve defined cache semantics and enough capacity for many sites on one Node, accepting that production code is cached until the service reloads and every production deploy must end with that reload.

## Status

Accepted on 2026-09-03.

## Context

The engine default of 10000 accelerated files overflows once two Laravel checkouts share a Node, and the default two-second revalidation gives production neither a cached-until-reload guarantee nor development an always-current one. OPcache shared memory belongs to the PHP-FPM master, so sizing is a per-version setting, while timestamp validation is an ordinary INI directive, so it is a per-pool policy. Measurements on the registered two-vCPU Nodes serving the Laravel welcome page showed that revalidating every request costs about 1.5 ms on a warm cache, disabling OPcache costs about 80 ms, the tracing JIT is throughput-neutral, a static-php build is 10 to 15 percent slower and lacks `pdo_sqlite` and `pdo_pgsql`, and Caddy from the release archive serves the same socket at the same rate as the Ubuntu package.

## Decision

- Orbit installs PHP from the pinned Sury apt source and runs one PHP-FPM service per version. Orbit must not build, bundle, or vendor PHP.
- Orbit publishes one runtime module per PHP version for the fpm SAPI, with OPcache on, `max_accelerated_files` at 65407, and memory sized by role: 512 MB with a 64 MB interned-strings buffer for `app-dev`, 256 MB with 32 MB for `app-prod`. A Node that carries both roles receives the `app-dev` sizing for every version.
- The tracing JIT stays off for every version and role.
- An `app-dev` pool validates timestamps on every request with the stock two-second `file_update_protection`, so it serves the code on disk while keeping OPcache on.
- An `app-prod` pool never validates timestamps. Compiled code stays in shared memory until the service reloads.
- Every production deploy must end with `systemctl reload php<version>-fpm`. Orbit's own pool publication reloads the service, so a convergence that changes a pool flushes the cache; editing a production checkout by hand without the reload is unsupported.
- Both roles use `pm = ondemand` with a ten-second idle timeout, because OPcache lives in the master and a worker spawned after idle reuses the warm cache.
- The CLI SAPI keeps stock defaults. `opcache.preload`, `file_cache`, and `huge_code_pages` stay off.

## Rejected alternatives

- A static-php.dev build: rejected because the glibc build crashed with `SIGILL` on the AMD EPYC VPS, the musl build ran 10 to 15 percent slower than Sury's package and lacks `pdo_sqlite` and `pdo_pgsql`, and a custom bundle would make Orbit own PHP and library security updates.
- The tracing JIT with a 64 MB buffer: rejected because it gives a Laravel HTTP request no measurable gain and has known crash classes.
- Disabling OPcache for development: rejected because it costs about 80 ms per request while revalidating every request costs about 1.5 ms.
- Building Caddy from source or the release archive for performance: rejected because it served the same PHP-FPM socket at the same rate as the Ubuntu package.

## Consequences

- Development sites reflect an editor save on the next request, and production sites serve compiled code until a reload, which gives each role the semantics it needs from one runtime.
- Two Laravel checkouts on one Node no longer overflow the accelerated-files table.
- A production deploy that skips the reload serves stale code; the deploy contract in the reference page is a hard requirement, not advice.
- Laravel's own `artisan optimize` caches remain an application deploy step outside Orbit.
- Every runtime measurement that justified these values is bound to the two-vCPU Nodes and PHP 8.5.9 it was taken on; a new platform needs a new measurement before the values change.

## Affects

- Components: apps/gateway
- ADRs: none
- Detail: docs/reference/php-runtime.md
- Verify: `apps/gateway` Pest suite covers the runtime module and pool renderers; on a Node, `php-fpm<version> -i` shows the managed directives and `grep opcache /etc/php/<version>/fpm/pool.d/orbit-*scopes.conf` shows the per-pool policy
