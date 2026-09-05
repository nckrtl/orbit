# PHP runtimes

Orbit provisions PHP-FPM from the pinned Sury apt source and fronts every site with Caddy over a unix socket. This page tells an operator or deployer which runtime values Orbit publishes for the `app-dev` and `app-prod` roles, where each value lives on a Node, and how to verify them. The deployer must end every production deploy with `systemctl reload php<version>-fpm`; the [production deploy contract](#production-deploy-contract) below states the obligation. [ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records why the values differ per role and which alternatives it rejected.

## Select an AppInstance runtime

For a development AppInstance, the Gateway reads source metadata after source preparation and before runtime or Domain Name System (DNS) publication. [ADR 0034](../decisions/0034-select-appinstance-php-from-composer-constraints.md) defines this source-driven selection boundary.

| Source | Runtime result |
| --- | --- |
| No `composer.json` file | Orbit classifies the source as non-PHP and prepares no PHP runtime. |
| Valid `composer.json` without a PHP platform constraint | Orbit selects PHP 8.5. |
| Valid `composer.json` with a supported PHP platform constraint | Orbit selects the highest Orbit-supported PHP version that satisfies the constraint. |
| Invalid or unsupported PHP platform constraint | The Gateway stops at PHP selection before runtime or DNS publication. |

AppInstance input, persisted AppInstance state, API responses, the PHP SDK, and the CLI do not expose a PHP-version field. The Node application role owns installation, configuration, and removal of every selected PHP runtime.

## Where each setting lives

Orbit publishes the runtime in two layers: one module per PHP-FPM service for sizing and one directive set per pool for timestamp validation.

| Layer | Path | Owner | Scope |
| --- | --- | --- | --- |
| Runtime module | `/etc/php/<version>/mods-available/orbit-runtime.ini`, enabled for the fpm SAPI as `/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini` through `phpenmod` | The Gateway's PHP package manager | One PHP-FPM service |
| Pool directives | `php_admin_value[opcache.*]` inside `/etc/php/<version>/fpm/pool.d/orbit-scopes.conf` (app-dev) and `orbit-prod-scopes.conf` (app-prod) | The Gateway's pool renderer for each role | One site pool |

The runtime module is a normal Debian PHP module: it carries the `; priority=99` header and is enabled only for `fpm`. On every convergence the Gateway renders the module, compares it with the installed file, writes the file only when it differs, and repairs a missing or wrong `conf.d` link. It then verifies through `php-fpm<version> -i` that every managed directive has its rendered value for the FPM Server Application Programming Interface (SAPI).

The Gateway reloads a running service when the file, its enablement, or the FPM PCOV enablement changed. When a publication fails, the Gateway restores the previous module file, or removes the file when none existed, removes the link when the publication created it, and reloads a running service before the command fails. The CLI SAPI keeps stock defaults (`opcache.enable_cli=0`).

## Runtime module

The module sets these directives for every pool of one PHP version; [ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records the reason for each value.

| Directive | app-dev | app-prod |
| --- | --- | --- |
| `opcache.enable` | On | On |
| `opcache.memory_consumption` | 512 | 256 |
| `opcache.interned_strings_buffer` | 64 | 32 |
| `opcache.max_accelerated_files` | 65407 | 65407 |
| `opcache.jit` / `opcache.jit_buffer_size` | disable / 0 | disable / 0 |

A Node that carries both roles receives the app-dev profile for every PHP version. The Gateway's PHP package manager selects the app-dev package profile, with PCOV enabled for the CLI SAPI, whenever the app-dev role is present, so production pools on such a Node share the app-dev sizing. `opcache.preload`, `file_cache`, and `huge_code_pages` stay off.

## Pool policy

An `app-dev` pool revalidates every cached file on every request and leaves `opcache.file_update_protection` at its stock value of 2, so a request serves the file on disk:

```ini
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 0
```

An `app-prod` pool never revalidates a cached file, so compiled code stays in shared memory until the service reloads:

```ini
php_admin_value[opcache.validate_timestamps] = 0
```

[ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records why each role gets its policy.

## Production deploy contract

A production pool serves compiled code from shared memory until PHP-FPM reloads, so a changed file on disk stays invisible until then. The deployer must end every production deploy with:

```sh
sudo systemctl reload php<version>-fpm
```

`reload` re-executes the FPM master, which recreates the OPcache segment; in-flight requests finish on the old workers. The Gateway's own pool publication runs `systemctl reload-or-restart`, so a convergence that changes a pool also flushes the cache. Editing files under a production checkout by hand without the reload is unsupported.

Laravel's `php artisan optimize` caches (config, route, event, and view) are an application deploy step outside Orbit.

## Process management

Both roles use `pm = ondemand` with `pm.process_idle_timeout = 10s` and `pm.max_requests = 500`. `pm.max_children` is 10 on an app-dev pool and 20 on an app-prod pool. [ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records why both roles use `ondemand`.

## Caddy

Production sites add an immutable cache header for Vite build output:

```caddyfile
@vite {
    path /build/assets/*
    file
}
header @vite Cache-Control "public, max-age=31536000, immutable"
```

Laravel's Vite plugin fingerprints every file under `public/build/assets`, so browsers can keep them for a year. The `file` matcher limits the header to assets that exist on disk. A request for a removed fingerprint falls through to Laravel's front controller without the header, so a 404 is never cached as immutable. Development sites set no caching header. `php_fastcgi`, `encode zstd gzip`, and `file_server` keep Caddy defaults; Orbit renders no `try_files`, and the `php_fastcgi` default tries `{path}`, then `{path}/index.php`, then `index.php`.

## Verification

On a Node, an operator checks the service-level values and the pool-level values separately:

```sh
/usr/sbin/php-fpm8.5 -i | grep -E '^opcache\.(enable|memory_consumption|max_accelerated_files|jit) '
grep opcache /etc/php/8.5/fpm/pool.d/orbit-*scopes.conf
```

Effective per-pool values are visible from inside a request with `ini_get('opcache.validate_timestamps')` or `opcache_get_configuration()['directives']`.
