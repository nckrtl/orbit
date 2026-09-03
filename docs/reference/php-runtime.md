# PHP runtime defaults

Orbit provisions PHP-FPM from the pinned Sury apt source and fronts every site with Caddy over a unix socket. This page records the runtime defaults Orbit publishes for the `app-dev` and `app-prod` roles and why they differ. Every production deploy must end with `systemctl reload php<version>-fpm`; the [production deploy contract](#production-deploy-contract) below states why. [ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records why these defaults were chosen and the alternatives that were measured and rejected.

## Where each setting lives

OPcache shared memory belongs to the PHP-FPM master process, so sizing is a per-PHP-version setting. Timestamp validation is an ordinary INI directive, so it is a per-pool policy. Orbit therefore publishes two layers:

| Layer | Path | Owner | Scope |
| --- | --- | --- | --- |
| Runtime module | `/etc/php/<version>/mods-available/orbit-runtime.ini`, enabled for the fpm SAPI as `/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini` through `phpenmod` | `RemotePhpPackageManager` with `PhpFpmRuntimeIniRenderer` | One PHP-FPM service |
| Pool directives | `php_admin_value[opcache.*]` inside `/etc/php/<version>/fpm/pool.d/orbit-scopes.conf` (app-dev) and `orbit-prod-scopes.conf` (app-prod) | `AppDevPhpFpmConfigRenderer`, `AppProdPhpFpmConfigRenderer` | One site pool |

The runtime module is a normal Debian PHP module: it carries the `; priority=99` header and is enabled only for `fpm`. Every convergence renders the module and compares it with the installed file. It writes the file only when it differs and repairs a missing or wrong `conf.d` symlink. It then verifies through `php-fpm<version> -i` that every managed directive has its rendered value for the fpm SAPI. Orbit reloads a running service when the file, its enablement, or the FPM PCOV enablement changed. A failed publication restores the previous file and link before the command fails. The CLI SAPI keeps stock defaults (`opcache.enable_cli=0`).

## Runtime module

The module sets these directives for every pool of one PHP version, and the Reason column records why a value differs from the engine default.

| Directive | app-dev | app-prod | Reason |
| --- | --- | --- | --- |
| `opcache.enable` | On | On | Explicit; PHP 8.5 compiles OPcache in and Sury ships `php8.4-opcache` for 8.4. |
| `opcache.memory_consumption` | 512 | 256 | An app-dev node hosts many sites in one shared segment; a production node hosts a few instances. Pages are committed lazily. |
| `opcache.interned_strings_buffer` | 64 | 32 | Carved out of `memory_consumption`. |
| `opcache.max_accelerated_files` | 65407 | 65407 | A Laravel skeleton with vendor is about 6k files; the engine default of 10000 overflows with two apps. The hash table itself costs a few megabytes. |
| `opcache.jit` / `opcache.jit_buffer_size` | disable / 0 | disable / 0 | The tracing JIT gives a Laravel HTTP request no measurable gain and has known crash classes, so Orbit pins it off for both versions rather than relying on package defaults. |

A node that carries both roles receives the app-dev profile for every PHP version. `RemotePhpPackageManager` selects the app-dev package profile, with PCOV for the CLI, whenever the app-dev role is present, so production pools on such a node share the larger development sizing.

Nothing exotic is enabled: no `opcache.preload` (Laravel ships no preload file and a preload error stops FPM), no `file_cache`, no `huge_code_pages`.

## Pool policy

`app-dev` pools always serve the code that is on disk:

```ini
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 0
```

OPcache stays on because compiling a Laravel request from scratch costs far more than one `stat()` per file. Every request re-checks each cached script it includes. The stock `opcache.file_update_protection=2` is kept on purpose. File mtimes have one second resolution, so a file saved twice within the same second would otherwise be cached in its first state. With the two second window a freshly saved file is compiled without being cached until it is older than two seconds. That matches the editor save-and-refresh loop. Disabling OPcache is not the development strategy; revalidating on every request is.

`app-prod` pools never re-check cached files:

```ini
php_admin_value[opcache.validate_timestamps] = 0
```

Compiled code stays in shared memory until the service reloads.

## Production deploy contract

Because production pools do not validate timestamps, a code change on disk is invisible until PHP-FPM restarts its shared memory. Every production deploy must end with:

```sh
sudo systemctl reload php<version>-fpm
```

`reload` re-executes the FPM master, which recreates the OPcache segment; in-flight requests finish on the old workers. Orbit's own pool publication already uses `systemctl reload-or-restart`, so a convergence that changes a pool also flushes the cache. Editing files under a production checkout by hand and skipping the reload is unsupported.

Laravel's `php artisan optimize` (config, route, event, and view caches) remains an application deploy step and is not managed by Orbit.

## Process management

Both roles use `pm = ondemand` with a 10 second idle timeout and `pm.max_requests = 500`. OPcache lives in the master, so a worker spawned after an idle period reuses the warm cache. The measured cold-request cost is within run-to-run noise, while each idle worker would hold its own resident memory. Keep `ondemand` unless a node is dedicated to one busy site.

## Caddy

Production sites add an immutable cache header for Vite build output:

```caddyfile
@vite {
    path /build/assets/*
    file
}
header @vite Cache-Control "public, max-age=31536000, immutable"
```

Laravel's Vite plugin fingerprints every file under `public/build/assets`, so browsers can keep them for a year. The `file` matcher limits the header to assets that exist on disk. A request for a removed fingerprint falls through to Laravel's front controller without the header, so a 404 is never cached as immutable. Development sites do not set caching headers. `php_fastcgi`, `encode zstd gzip`, and `file_server` keep Caddy defaults; `try_files` already matches Laravel's front controller order.

## Verification

On a node, check the service-level values and the pool-level values separately:

```sh
/usr/sbin/php-fpm8.5 -i | grep -E '^opcache\.(enable|memory_consumption|max_accelerated_files|jit) '
grep opcache /etc/php/8.5/fpm/pool.d/orbit-*scopes.conf
```

Effective per-pool values are visible from inside a request with `ini_get('opcache.validate_timestamps')` or `opcache_get_configuration()['directives']`.
