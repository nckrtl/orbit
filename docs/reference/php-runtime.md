# PHP runtime defaults

Orbit provisions PHP-FPM from the pinned Sury apt source and fronts every site with Caddy over a unix socket. This page records the runtime defaults Orbit publishes for the `app-dev` and `app-prod` roles, why they differ, and the deploy contract that production code caching relies on.

## Where each setting lives

OPcache shared memory belongs to the PHP-FPM master process, so sizing is a per-PHP-version setting. Timestamp validation is an ordinary INI directive, so it is a per-pool policy. Orbit therefore publishes two layers:

| Layer | Path | Owner | Scope |
| --- | --- | --- | --- |
| Runtime module | `/etc/php/<version>/mods-available/orbit-runtime.ini`, enabled for the fpm SAPI as `/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini` through `phpenmod` | `RemotePhpPackageManager` with `PhpFpmRuntimeIniRenderer` | One PHP-FPM service |
| Pool directives | `php_admin_value[opcache.*]` inside `/etc/php/<version>/fpm/pool.d/orbit-scopes.conf` (app-dev) and `orbit-prod-scopes.conf` (app-prod) | `AppDevPhpFpmConfigRenderer`, `AppProdPhpFpmConfigRenderer` | One site pool |

The runtime module is a normal Debian PHP module: it carries the `; priority=99` header and is enabled only for `fpm`. Every convergence renders the module, compares it with the installed file, writes it only when it differs, repairs a missing or wrong `conf.d` symlink, and verifies through `php-fpm<version> -i` that every managed directive has its rendered value for the fpm SAPI. A running service is reloaded when the file, its enablement, or the FPM PCOV enablement changed; a failed publication restores the previous file and link before the command fails. The CLI SAPI keeps stock defaults (`opcache.enable_cli=0`).

## Runtime module

| Directive | app-dev | app-prod | Reason |
| --- | --- | --- | --- |
| `opcache.enable` | On | On | Explicit; PHP 8.5 compiles OPcache in and Sury ships `php8.4-opcache` for 8.4. |
| `opcache.memory_consumption` | 512 | 256 | An app-dev node hosts many sites in one shared segment; a production node hosts a few instances. Pages are committed lazily. |
| `opcache.interned_strings_buffer` | 64 | 32 | Carved out of `memory_consumption`. |
| `opcache.max_accelerated_files` | 65407 | 65407 | A Laravel skeleton with vendor is about 6k files; the engine default of 10000 overflows with two apps. The hash table itself costs a few megabytes. |
| `opcache.jit` / `opcache.jit_buffer_size` | disable / 0 | disable / 0 | Sury already ships `jit=off` for 8.4. The tracing JIT gives Laravel HTTP requests no measurable gain and has known crash classes, so Orbit pins it off for both versions instead of relying on package defaults. |

A node that carries both roles receives the app-dev profile for every PHP version, because `RemotePhpPackageManager` already selects the app-dev package profile (PCOV for the CLI) whenever the app-dev role is present; production pools on such a node therefore share the larger development sizing.

Nothing exotic is enabled: no `opcache.preload` (Laravel ships no preload file and a preload error stops FPM), no `file_cache`, no `huge_code_pages`.

## Pool policy

`app-dev` pools always serve the code that is on disk:

```ini
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 0
```

OPcache stays on because compiling a Laravel request from scratch costs far more than one `stat()` per file, but every request re-checks each cached script it includes. The stock `opcache.file_update_protection=2` is kept on purpose: file mtimes have one second resolution, so a file saved twice within the same second would otherwise be cached in its first state. With the two second window a freshly saved file is compiled without being cached until it is older than two seconds, which is exactly the editor save-and-refresh loop. Disabling OPcache is not the development strategy; revalidating on every request is.

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

Both roles use `pm = ondemand` with a 10 second idle timeout and `pm.max_requests = 500`. OPcache lives in the master, so a worker spawned after an idle period reuses the warm cache; the cold-request cost measured below is within run-to-run noise, while each idle worker would hold its own RSS. Keep `ondemand` unless a node is dedicated to one busy site.

## Caddy

Production sites add an immutable cache header for Vite build output:

```caddyfile
@vite {
    path /build/assets/*
    file
}
header @vite Cache-Control "public, max-age=31536000, immutable"
```

Laravel's Vite plugin fingerprints every file under `public/build/assets`, so browsers can keep them for a year. The `file` matcher limits the header to assets that exist on disk; a request for a removed fingerprint falls through to Laravel's front controller without the header, so a 404 is never cached as immutable. Development sites do not set caching headers. `php_fastcgi`, `encode zstd gzip`, and `file_server` keep Caddy defaults; `try_files` already matches Laravel's front controller order.

## Measurements (2026-08-30)

All numbers come from the registered two vCPU `app-dev` and `app-prod` nodes serving the `laravel/laravel` welcome page with `wrk` on the node itself, PHP 8.5.9 from Sury, four static workers, config/route/view caches warmed. Single-connection p50 isolates the PHP engine; the c=4 figures include SQLite session contention.

| Variant | p50 (c=1) | req/s (c=1) | req/s (c=4) |
| --- | --- | --- | --- |
| Sury, production policy (validate off, JIT off) | 7.1–7.7 ms | 125–135 | 191–198 |
| Sury, development policy (revalidate every request) | 8.4–9.1 ms | 105–115 | 175–186 |
| Sury, tracing JIT with 64 MB buffer | 7.3–7.5 ms | 122–133 | 171–192 |
| static-php.dev 8.5.9 `bulk` (musl) php-fpm, production policy | 8.1–9.0 ms | 105–117 | 177–183 |
| Sury, OPcache disabled | 90 ms | 11 | 21 |

Conclusions that shaped the defaults:

- Revalidating on every request costs about 1.5 ms per request on a warm cache; disabling OPcache costs about 80 ms. The development policy keeps OPcache on.
- The tracing JIT is throughput-neutral for a Laravel HTTP request, so it stays off.
- A static-php.dev build is not faster. The `gnu-bulk` (glibc) prebuilt crashed with `SIGILL` on the AMD EPYC VPS; the musl `bulk` build ran 10–15 % slower than Sury's package and lacks `pdo_sqlite`/`pdo_pgsql`. Building custom bundles would also mean owning PHP and library security updates. Orbit stays on Sury.
- Through Caddy over the public address the change is throughput-neutral (about 158 req/s at ten connections before and after). The gain is capacity and defined cache semantics: the engine default of 10000 accelerated files overflows with two Laravel checkouts, and production code is now cached until reload instead of re-checked every two seconds.
- `pm = ondemand` cold requests after the ten second idle timeout measured 45–54 ms against 42–53 ms warm, within run-to-run noise, because OPcache lives in the master.
- Caddy 2.11.4 from the official release archive and Ubuntu's 2.6.2 package served the same PHP-FPM socket at the same rate (about 180–190 req/s at four connections, about 14k req/s for a static file), so the Caddy package source is not a performance lever.

## Verification

On a node, check the service-level values and the pool-level values separately:

```sh
/usr/sbin/php-fpm8.5 -i | grep -E '^opcache\.(enable|memory_consumption|max_accelerated_files|jit) '
grep opcache /etc/php/8.5/fpm/pool.d/orbit-*scopes.conf
```

Effective per-pool values are visible from inside a request with `ini_get('opcache.validate_timestamps')` or `opcache_get_configuration()['directives']`.
